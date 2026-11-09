<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Extensions;

use Restruct\SilverStripe\SignedAssetUrls\Tests\Stub\SignedFileSwapExtension;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * The updateSignedURLFile hook (1.4): an extension may swap the DBFile that SignedURL() /
 * AutoURL() / MaskedURL() build their URL for, eg for its WebP variant. The URL, the protection
 * decision and the served file then follow the swapped file, and a swap to another stored file is
 * ignored.
 *
 * The WebP "conversion" is a variant written straight into the asset store under core's
 * ExtRewrite variant name, so the test does not depend on the image backend's WebP support.
 */
class SignedUrlFileHookTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        DBFile::class => [SignedFileSwapExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsHookTest');
        SignedFileSwapExtension::$swap = null;
        SignedFileSwapExtension::$calls = 0;
    }

    protected function tearDown(): void
    {
        SignedFileSwapExtension::$swap = null;
        SignedFileSwapExtension::$calls = 0;
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

    /**
     * A stand-in for a converted variant: $file's variant with an ExtRewrite (png -> $ext)
     * sub-variant, its bytes written into the store next to the image, as core's Convert() does.
     */
    private function fakeConverted(DBFile $file, string $ext, string $bytes): DBFile
    {
        $source = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
        $rewrite = 'ExtRewrite' . Convert::base64url_encode([$source, $ext]);
        $variant = $file->getVariant() ? $file->getVariant() . '_' . $rewrite : $rewrite;

        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $store->setFromString($bytes, $file->getFilename(), $file->getHash(), $variant, [
            'visibility' => $store->getVisibility($file->getFilename(), $file->getHash()),
        ]);

        $converted = DBFile::create();
        $converted->setValue([
            'Filename' => $file->getFilename(),
            'Hash' => $file->getHash(),
            'Variant' => $variant,
        ]);
        return $converted;
    }

    private function signedPath(string $url): string
    {
        $this->assertStringStartsWith('/signed-asset/', $url, "expected a signed URL, got: {$url}");
        return rawurldecode(preg_replace('#^/signed-asset/([^?]+).*$#', '$1', $url));
    }

    public function testHookRunsWithoutChangingAnythingWhenNotImplemented(): void
    {
        $img = $this->makeImage('hook/plain.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        $path = $this->signedPath((string) $variant->AutoURL('m'));
        $this->assertSame(1, SignedFileSwapExtension::$calls, 'the hook runs once per AutoURL()');
        $this->assertStringEndsWith('.png', $path, 'no swap: the variant itself is signed');
        $this->assertStringNotContainsString('ExtRewrite', $path);
    }

    public function testAutoUrlSignsTheSwappedVariant(): void
    {
        $img = $this->makeImage('hook/swap.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        $webp = $this->fakeConverted($variant, 'webp', 'RIFF....WEBPVP8 fake');
        SignedFileSwapExtension::$swap = fn (DBFile $file) => $file->getVariant() === $variant->getVariant() ? $webp : $file;

        $url = (string) $variant->AutoURL('m');
        $path = $this->signedPath($url);
        $this->assertStringEndsWith('.webp', $path, 'the swapped (webp) variant is signed');
        $this->assertStringContainsString($webp->getVariant(), $path);

        $resp = $this->get($url);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('image/webp', (string) $resp->getHeader('Content-Type'));
        $this->assertSame('RIFF....WEBPVP8 fake', $resp->getBody(), 'the swapped file is served');
    }

    public function testSignedUrlSignsTheSwappedVariant(): void
    {
        $img = $this->makeImage('hook/swap-signed.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        $webp = $this->fakeConverted($variant, 'webp', 'webp bytes');
        SignedFileSwapExtension::$swap = fn (DBFile $file) => $webp;

        $this->assertStringEndsWith('.webp', $this->signedPath((string) $variant->SignedURL(300, false)));
    }

    public function testMaskedUrlUsesTheSwappedVariant(): void
    {
        $img = $this->makeImage('hook/swap-masked.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        $webp = $this->fakeConverted($variant, 'webp', 'masked webp bytes');
        SignedFileSwapExtension::$swap = fn (DBFile $file) => $webp;

        $url = (string) $variant->MaskedURL((int) $img->ID, 'm');
        $path = $this->signedPath($url);
        $this->assertMatchesRegularExpression('#/x[0-9a-f]{8}__[^/]*ExtRewrite[^/.]*\.webp$#', $path, 'masked path keeps the swapped variant token and extension');

        $resp = $this->get($url);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('image/webp', (string) $resp->getHeader('Content-Type'));
        $this->assertSame('masked webp bytes', $resp->getBody());
    }

    public function testPublicFileReturnsTheSwappedFilesUrl(): void
    {
        $img = $this->makeImage('hook/public.png', 400, 240, 'Anyone');
        $variant = $img->ScaleWidth(200);
        $webp = $this->fakeConverted($variant, 'webp', 'public webp bytes');
        SignedFileSwapExtension::$swap = fn (DBFile $file) => $webp;

        $url = (string) $variant->AutoURL('m');
        $this->assertStringNotContainsString('/signed-asset/', $url, 'public -> normal URL');
        $this->assertSame($webp->getURL(), $url, 'the swapped file\'s own URL');
    }

    public function testSwapToAnotherStoredFileIsIgnored(): void
    {
        $img = $this->makeImage('hook/mine.png', 400, 240);
        $other = $this->makeImage('hook/other.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        SignedFileSwapExtension::$swap = fn (DBFile $file) => $other->ScaleWidth(200);

        $path = $this->signedPath((string) $variant->AutoURL('m'));
        $this->assertStringContainsString('mine__', $path, 'a file of another record is never signed in its place');
        $this->assertStringNotContainsString('other', $path);
    }

    public function testSwapToANonFileIsIgnored(): void
    {
        $img = $this->makeImage('hook/nonfile.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        SignedFileSwapExtension::$swap = fn (DBFile $file) => null;

        $this->assertStringContainsString('nonfile__', $this->signedPath((string) $variant->AutoURL('m')));
    }
}
