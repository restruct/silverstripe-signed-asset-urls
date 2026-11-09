<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Control;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * Converted variants (core's Convert(), an ExtRewrite sub-variant that changes the extension):
 * issue #17 (a signed .avif was served as application/octet-stream) and issue #18 (MaskedURL on a
 * converted variant was served with the original's Content-Type).
 */
class SignedAssetUrlConvertedVariantTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsConvertedTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

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
     * $file's variant with an ExtRewrite (png -> $ext) sub-variant, written into the store as
     * core's Convert() does. Independent of the image backend's AVIF support, which CI's GD may
     * lack.
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

    private function decodedPath(string $url): string
    {
        return rawurldecode(preg_replace('#^/signed-asset/([^?]+).*$#', '$1', $url));
    }

    // -------------------------------------------------------------------------
    // Issue #17: AVIF content type
    // -------------------------------------------------------------------------
    public function testSignedAvifVariantServedAsImageAvif(): void
    {
        $img = $this->makeImage('converted/photo.png', 400, 240);
        $avif = $this->fakeConverted($img->ScaleWidth(200), 'avif', 'fake avif bytes');

        $url = (string) $avif->AutoURL('m');
        $this->assertStringEndsWith('.avif', $this->decodedPath($url), 'precondition: the avif variant is signed');
        $resp = $this->get($url);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('image/avif', (string) $resp->getHeader('Content-Type'));
        $this->assertStringContainsString('inline', (string) $resp->getHeader('Content-Disposition'), 'an image renders inline, not as a download');
    }

    public function testMimeTypeFallsBackToCoreMimeTypes(): void
    {
        $c = new TestableSignedAssetUrlController();
        $this->assertSame('image/avif', $c->pub_getMimeTypeFromExtension('AVIF'), 'avif, any case');
        # not in the controller's own map, in core's HTTP.MimeTypes
        $this->assertSame('image/tiff', $c->pub_getMimeTypeFromExtension('tiff'));
        $this->assertSame('application/octet-stream', $c->pub_getMimeTypeFromExtension('nosuchext'), 'unknown stays octet-stream');
    }

    // -------------------------------------------------------------------------
    // Issue #18: MaskedURL on a converted variant
    // -------------------------------------------------------------------------
    public function testMaskedUrlOnConvertedWebpVariantServedAsImageWebp(): void
    {
        $this->assertTrue(function_exists('imagewebp'), 'precondition: GD with WebP support');
        $img = $this->makeImage('converted/masked.png', 400, 240);
        $webp = $img->ScaleWidth(200)->Convert('webp');
        $this->assertInstanceOf(DBFile::class, $webp, 'precondition: core converted the variant to webp');

        $url = (string) $webp->MaskedURL((int) $img->ID, 'm');
        $this->assertStringEndsWith('.webp', $this->decodedPath($url), 'precondition: masked path keeps .webp');
        $resp = $this->get($url);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('image/webp', (string) $resp->getHeader('Content-Type'));
        $this->assertSame('WEBP', substr((string) $resp->getBody(), 8, 4), 'the WebP bytes are served');
    }

    public function testReconstructedPathOfConvertedVariantMatchesTheStoredName(): void
    {
        # a multi-dot name: core puts the variant after the FIRST dot and rewrites the LAST
        # extension, "shot-17.28.11.png" -> "shot-17__{variant}.28.11.webp"
        $img = $this->makeImage('converted/shot-17.28.11.png', 400, 240);
        $webp = $this->fakeConverted($img->ScaleWidth(200), 'webp', 'bytes');
        $realPath = substr($img->getHash(), 0, 10) . '/shot-17__' . $webp->getVariant() . '.28.11.webp';

        $masked = substr($img->getHash(), 0, 10) . '/x' . sprintf('%08x', $img->ID) . '__' . $webp->getVariant() . '.webp';
        $c = new TestableSignedAssetUrlController();
        $this->assertSame($realPath, $c->pub_reconstructRealVariantPath($img, $masked));

        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $this->assertTrue($store->exists($img->getFilename(), $img->getHash(), $webp->getVariant()), 'precondition: the variant is stored');
        $this->assertStringEndsWith($realPath, (string) $webp->getSourceURL(false), 'that is the name core stored it under');
    }

    public function testReconstructedPathOfPlainVariantUnchanged(): void
    {
        $img = $this->makeImage('converted/plain.png', 400, 240);
        $variant = $img->ScaleWidth(200);
        $masked = substr($img->getHash(), 0, 10) . '/x' . sprintf('%08x', $img->ID) . '__' . $variant->getVariant() . '.png';
        $c = new TestableSignedAssetUrlController();
        $this->assertSame(
            substr($img->getHash(), 0, 10) . '/plain__' . $variant->getVariant() . '.png',
            $c->pub_reconstructRealVariantPath($img, $masked)
        );
    }
}
