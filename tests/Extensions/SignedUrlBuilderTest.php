<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Extensions;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Coverage for the URL-builder side (SignedUrlDBFileExtension / FileExtension):
 * public-vs-protected decision, signed-vs-normal URLs, named policies, and the
 * masked-URL builder (incl. the small-image "masked original" shape the
 * controller must reverse, and the large-image masked-variant shape).
 *
 * NB: in this project files have no Draft/Live staging (always "published"), so
 * protection is driven by CanViewType (hasRestrictedAccess), not publish state.
 */
class SignedUrlBuilderTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsBuilderTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /** $canView 'LoggedInUsers' = protected (signed URLs); 'Anyone' = public. */
    private function makeImage(string $filename, int $w, int $h, string $canView = 'LoggedInUsers'): Image
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 200));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        $img = Image::create();
        $img->setFromString($bytes, $filename);
        $img->CanViewType = $canView;
        $img->write();
        return $img;
    }

    private function signedPath(string $url): string
    {
        $this->assertStringStartsWith('/signed-asset/', $url, "expected a signed URL, got: {$url}");
        return rawurldecode(preg_replace('#^/signed-asset/([^?]+).*$#', '$1', $url));
    }

    private function expiryOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $p);
        return (int) ($p['e'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Public vs protected
    // -------------------------------------------------------------------------
    public function testPublicImageReturnsNormalUrl(): void
    {
        $img = $this->makeImage('builder/public.png', 200, 100, 'Anyone');
        $this->assertFalse($img->requiresSignedURL(), 'public file is not protected');
        $this->assertStringNotContainsString('/signed-asset/', (string) $img->AutoURL('m'), 'public -> normal URL');
    }

    public function testRestrictedImageRequiresSignedUrl(): void
    {
        $img = $this->makeImage('builder/restricted.png', 200, 100, 'LoggedInUsers');
        $this->assertTrue($img->requiresSignedURL(), 'restricted file is protected');
        $this->assertStringStartsWith('/signed-asset/', (string) $img->AutoURL('m'), 'protected -> signed URL');
    }

    // -------------------------------------------------------------------------
    // Named policies (ttl + session flag) end-to-end through AutoURL
    // -------------------------------------------------------------------------
    public function testPolicyShortSessionBound(): void
    {
        $img = $this->makeImage('builder/policy-ss.png', 200, 100);
        $url = (string) $img->AutoURL('ss');
        $this->assertEqualsWithDelta(time() + 30, $this->expiryOf($url), 5, "'ss' = 30s TTL");
        $this->assertStringContainsString('ss=1', $url, "'ss' is session-bound");
    }

    public function testPolicyMediumNotSessionBound(): void
    {
        $img = $this->makeImage('builder/policy-m.png', 200, 100);
        $url = (string) $img->AutoURL('m');
        $this->assertEqualsWithDelta(time() + 3600, $this->expiryOf($url), 5, "'m' = 1h TTL");
        $this->assertStringNotContainsString('ss=1', $url, "'m' is not session-bound");
    }

    // -------------------------------------------------------------------------
    // Masked URL: small image -> masked ORIGINAL; large -> masked VARIANT
    // -------------------------------------------------------------------------
    public function testMaskedScaleWidthPublicFileProducesMaskedOriginal(): void
    {
        // A PUBLIC file is the masked-ORIGINAL trigger (the prod bug shape): a
        // variant IS generated, but buildAssetPath can't extract a hash-prefixed
        // path from the public source URL, so it falls back to the original
        // filename -> masked path "{folder}/x{id}.{ext}" with no variant token.
        // The controller must reverse this (findFileByMaskedOriginalPath).
        $img = $this->makeImage('builder/public-opt.png', 400, 240, 'Anyone');
        $path = $this->signedPath((string) $img->MaskedScaleWidthURL(180, 'ss'));
        $this->assertStringNotContainsString('__', $path, 'public file -> no variant token (masked original)');
        $this->assertMatchesRegularExpression('#/x[0-9a-f]{8}\.png$#', $path, 'masked original form {folder}/x{id}.png');
    }

    public function testMaskedScaleWidthLargeImageProducesMaskedVariant(): void
    {
        $img = $this->makeImage('builder/large.png', 400, 240);
        $path = $this->signedPath((string) $img->MaskedScaleWidthURL(180, 'ss'));
        $this->assertStringContainsString('__', $path, 'variant token present');
        $this->assertMatchesRegularExpression('#/x[0-9a-f]{8}__#', $path, 'masked variant form .../x{id}__{variant}');
    }

    public function testMaskedScaleWidthOnNonExistentReturnsNull(): void
    {
        $this->assertNull(Image::create()->MaskedScaleWidthURL(180, 'ss'));
    }

    public function testMaskedUrlNullCases(): void
    {
        $this->assertNull(File::create()->File->MaskedURL(5), 'empty filename -> null');
        $img = $this->makeImage('builder/idcheck.png', 100, 60);
        $this->assertNull($img->File->MaskedURL(0), 'id <= 0 -> null');
    }
}
