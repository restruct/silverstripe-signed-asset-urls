<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Control;

use Restruct\SilverStripe\SignedAssetUrls\Control\SignedAssetUrlController;
use SilverStripe\Assets\File;
use SilverStripe\Dev\SapphireTest;

/**
 * Unit coverage for the path -> File resolution logic in
 * SignedAssetUrlController. This is the edge-case-prone area (masked variants,
 * masked originals, multi-dot names, folder/extension mismatches), so it gets
 * focused tests we can keep extending as new cases surface.
 */
class SignedAssetUrlResolutionTest extends SapphireTest
{
    protected static $fixture_file = 'SignedAssetUrlResolutionTest.yml';

    private function ctrl(): TestableSignedAssetUrlController
    {
        return new TestableSignedAssetUrlController();
    }

    private function masked(int $id): string
    {
        return 'x' . sprintf('%08x', $id);
    }

    // -------------------------------------------------------------------------
    // Path classification (pure string)
    // -------------------------------------------------------------------------
    public function testIsVariantPathDetectsHashPrefixOnly(): void
    {
        $c = $this->ctrl();
        $this->assertTrue($c->pub_isVariantPath('abcdef0123/img__FillWzQwMF0.jpg'), 'hash-prefixed = variant');
        $this->assertFalse($c->pub_isVariantPath('test-thumbnails/x0000093f.png'), 'folder-prefixed masked original is NOT a variant');
        $this->assertFalse($c->pub_isVariantPath('x0000093f.png'), 'single segment is not a variant');
    }

    public function testIsMaskedVariantPath(): void
    {
        $c = $this->ctrl();
        $this->assertTrue($c->pub_isMaskedVariantPath('abcdef0123/x0000002a__FillWzQwMF0.jpg'));
        $this->assertFalse($c->pub_isMaskedVariantPath('abcdef0123/realname__FillWzQwMF0.jpg'), 'non-masked stem');
    }

    // -------------------------------------------------------------------------
    // Masked ORIGINAL resolution (the fix): "{folder}/x{idhex}.{ext}"
    // -------------------------------------------------------------------------
    public function testMaskedOriginalResolvesByEmbeddedId(): void
    {
        $file = $this->objFromFixture(File::class, 'skylift'); // test-thumbnails/skylift.png
        $path = 'test-thumbnails/' . $this->masked((int) $file->ID) . '.png';
        $resolved = $this->ctrl()->pub_findFileByMaskedOriginalPath($path);
        $this->assertNotNull($resolved, 'masked original must resolve to its File');
        $this->assertSame((int) $file->ID, (int) $resolved->ID);
    }

    public function testMaskedOriginalRejectsFolderMismatch(): void
    {
        $file = $this->objFromFixture(File::class, 'skylift');
        $path = 'somewhere-else/' . $this->masked((int) $file->ID) . '.png';
        $this->assertNull($this->ctrl()->pub_findFileByMaskedOriginalPath($path), 'wrong folder must not resolve');
    }

    public function testMaskedOriginalRejectsExtensionMismatch(): void
    {
        $file = $this->objFromFixture(File::class, 'skylift');
        $path = 'test-thumbnails/' . $this->masked((int) $file->ID) . '.jpg';
        $this->assertNull($this->ctrl()->pub_findFileByMaskedOriginalPath($path), 'wrong extension must not resolve');
    }

    public function testNonMaskedOriginalReturnsNull(): void
    {
        // A real (unmasked) filename must fall through to the normal lookup.
        $this->assertNull($this->ctrl()->pub_findFileByMaskedOriginalPath('test-thumbnails/skylift.png'));
    }

    public function testMaskedOriginalUnknownIdReturnsNull(): void
    {
        $path = 'test-thumbnails/' . $this->masked(999999) . '.png';
        $this->assertNull($this->ctrl()->pub_findFileByMaskedOriginalPath($path));
    }

    public function testMaskedOriginalAtRootFolderResolves(): void
    {
        $file = $this->objFromFixture(File::class, 'rootfile'); // root.png (no folder)
        $path = $this->masked((int) $file->ID) . '.png';
        $resolved = $this->ctrl()->pub_findFileByMaskedOriginalPath($path);
        $this->assertNotNull($resolved);
        $this->assertSame((int) $file->ID, (int) $resolved->ID);
    }

    // -------------------------------------------------------------------------
    // Masked VARIANT reconstruction with a multi-dot name (regression)
    // -------------------------------------------------------------------------
    public function testReconstructMultiDotVariantPath(): void
    {
        $file = $this->objFromFixture(File::class, 'multidot'); // Name "Shot-17.28.11.png"
        $hash10 = substr((string) $file->getHash(), 0, 10);
        $masked = $hash10 . '/' . $this->masked((int) $file->ID) . '__ScaleWidthWzE4MF0.png';
        $real = $this->ctrl()->pub_reconstructRealVariantPath($file, $masked);
        // SS injects the variant after the FIRST dot, keeping the rest as suffix.
        $this->assertSame($hash10 . '/Shot-17__ScaleWidthWzE4MF0.28.11.png', $real);
    }

    // -------------------------------------------------------------------------
    // findFileByVariantPath edge cases
    // -------------------------------------------------------------------------
    public function testVariantPathResolvesByHashAndName(): void
    {
        $file = $this->objFromFixture(File::class, 'dup1'); // hash ab12cd34ef…, Name photo.jpg
        $resolved = $this->ctrl()->pub_findFileByVariantPath('ab12cd34ef/photo__FillWzEwMF0.jpg');
        $this->assertNotNull($resolved);
        $this->assertSame((int) $file->ID, (int) $resolved->ID, 'resolves the record matching Name, not the duplicate-hash sibling');
    }

    public function testDuplicateHashDisambiguatesByName(): void
    {
        $dup2 = $this->objFromFixture(File::class, 'dup2'); // same hash, Name photo-copy.jpg
        $resolved = $this->ctrl()->pub_findFileByVariantPath('ab12cd34ef/photo-copy__FillWzEwMF0.jpg');
        $this->assertSame((int) $dup2->ID, (int) $resolved->ID, 'same hash, different name -> correct record');
    }

    public function testChainedManipulationVariantResolves(): void
    {
        // Variant token itself contains underscores (chained manipulations).
        $file = $this->objFromFixture(File::class, 'chained'); // banner.jpg
        $resolved = $this->ctrl()->pub_findFileByVariantPath('cc11cc11cc/banner__FillWzEwMF0_ScaleWidthWzUwXQ.jpg');
        $this->assertNotNull($resolved, 'chained variant token must still recover the original name');
        $this->assertSame((int) $file->ID, (int) $resolved->ID);
    }

    public function testExtRewriteVariantResolvesViaBasenameFallback(): void
    {
        // PDF whose variant is a PNG preview: recovered name "report.png" != "report.pdf",
        // so resolution falls back to a basename (any-extension) match.
        $file = $this->objFromFixture(File::class, 'extrewrite'); // report.pdf
        $resolved = $this->ctrl()->pub_findFileByVariantPath('ee22ee22ee/report__ExtRewriteWyJwZGYiLCJwbmciXQ_FillWzMyMF0.png');
        $this->assertNotNull($resolved, 'ExtRewrite variant must resolve via basename fallback');
        $this->assertSame((int) $file->ID, (int) $resolved->ID);
    }

    // -------------------------------------------------------------------------
    // MIME + disposition
    // -------------------------------------------------------------------------
    public function testMimeTypeFromExtension(): void
    {
        $c = $this->ctrl();
        $this->assertSame('image/png', $c->pub_getMimeTypeFromExtension('png'));
        $this->assertSame('application/pdf', $c->pub_getMimeTypeFromExtension('pdf'));
        $this->assertSame('image/jpeg', $c->pub_getMimeTypeFromExtension('jpg'));
        $this->assertSame('application/octet-stream', $c->pub_getMimeTypeFromExtension('xyzunknown'));
    }

    public function testDisposition(): void
    {
        $c = $this->ctrl();
        $this->assertStringContainsString('inline', $c->pub_resolveDisposition('application/pdf', false), 'pdf inline by default');
        $this->assertStringContainsString('inline', $c->pub_resolveDisposition('image/png', false), 'image inline by default');
        $this->assertStringContainsString('attachment', $c->pub_resolveDisposition('application/pdf', true), 'forced attachment');
        $this->assertStringContainsString('attachment', $c->pub_resolveDisposition('application/zip', false), 'non-viewable -> attachment');
    }
}

/**
 * Exposes the controller's protected resolution helpers for unit testing.
 */
class TestableSignedAssetUrlController extends SignedAssetUrlController
{
    public function pub_isVariantPath(string $p): bool
    {
        return $this->isVariantPath($p);
    }

    public function pub_isMaskedVariantPath(string $p): bool
    {
        return $this->isMaskedVariantPath($p);
    }

    public function pub_findFileByMaskedOriginalPath(string $p): ?File
    {
        return $this->findFileByMaskedOriginalPath($p);
    }

    public function pub_reconstructRealVariantPath(File $f, string $p): string
    {
        return $this->reconstructRealVariantPath($f, $p);
    }

    public function pub_findFileByVariantPath(string $p): ?File
    {
        return $this->findFileByVariantPath($p);
    }

    public function pub_getMimeTypeFromExtension(string $ext): string
    {
        return $this->getMimeTypeFromExtension($ext);
    }

    public function pub_resolveDisposition(string $mime, bool $forceAttachment): string
    {
        return $this->resolveDisposition($mime, $forceAttachment);
    }

    public function pub_tryServeOriginalForNoOpVariant(File $file, string $variantId)
    {
        return $this->tryServeOriginalForNoOpVariant($file, $variantId);
    }
}
