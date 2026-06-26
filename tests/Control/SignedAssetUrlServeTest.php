<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Control;

use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * End-to-end coverage of SignedAssetUrlController::serve() — the request flow
 * that would have caught the masked-original 404. Exercises valid serving
 * (original / masked variant / masked original), signature rejection, expiry,
 * unknown paths, traversal, and content disposition.
 *
 * Uses the non-session 'm' policy so validation doesn't depend on a session
 * token (which is empty under the test runner).
 */
class SignedAssetUrlServeTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsServeTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function svc(): AssetUrlSigningService
    {
        return Injector::inst()->get(AssetUrlSigningService::class);
    }

    private function makeImage(string $filename, int $w, int $h, string $canView): Image
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

    private function makePdf(string $filename): File
    {
        $file = File::create();
        $file->setFromString("%PDF-1.4\n% fake pdf for tests\n", $filename);
        $file->CanViewType = 'LoggedInUsers';
        $file->write();
        return $file;
    }

    private function decodedPath(string $url): string
    {
        return rawurldecode(preg_replace('#^/signed-asset/([^?]+).*$#', '$1', $url));
    }

    // -------------------------------------------------------------------------
    // Valid serving
    // -------------------------------------------------------------------------
    public function testValidProtectedOriginalServes200(): void
    {
        $img = $this->makeImage('serve/protected.png', 200, 100, 'LoggedInUsers');
        $resp = $this->get((string) $img->AutoURL('m'));
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('image/png', (string) $resp->getHeader('Content-Type'));
    }

    public function testValidMaskedVariantServes200(): void
    {
        $img = $this->makeImage('serve/variant.png', 400, 240, 'LoggedInUsers');
        $url = (string) $img->MaskedScaleWidthURL(180, 'm');
        $this->assertStringContainsString('__', $this->decodedPath($url), 'precondition: masked variant');
        $this->assertSame(200, $this->get($url)->getStatusCode());
    }

    public function testValidMaskedOriginalServes200(): void
    {
        // The exact prod bug shape: a public file -> masked ORIGINAL path.
        $img = $this->makeImage('serve/masked-original.png', 400, 240, 'Anyone');
        $url = (string) $img->MaskedScaleWidthURL(180, 'm');
        $this->assertStringNotContainsString('__', $this->decodedPath($url), 'precondition: masked original');
        $this->assertSame(200, $this->get($url)->getStatusCode(), 'masked original must serve (the fix)');
    }

    // -------------------------------------------------------------------------
    // Rejections
    // -------------------------------------------------------------------------
    public function testInvalidSignatureReturns403(): void
    {
        $img = $this->makeImage('serve/badsig.png', 200, 100, 'LoggedInUsers');
        $tampered = preg_replace('/([?&]s=)[^&]+/', '${1}0000000000000000', (string) $img->AutoURL('m'));
        $this->assertSame(403, $this->get($tampered)->getStatusCode());
    }

    public function testExpiredReturns410(): void
    {
        $img = $this->makeImage('serve/expired.png', 200, 100, 'LoggedInUsers');
        $path = $this->decodedPath((string) $img->AutoURL('m'));
        $expiredUrl = $this->svc()->generateSignedURL($path, -10); // valid hash, past expiry
        $this->assertSame(410, $this->get($expiredUrl)->getStatusCode());
    }

    public function testUnknownPathReturns404(): void
    {
        $url = $this->svc()->generateSignedURL('Uploads/does-not-exist/ghost.png', 300);
        $this->assertSame(404, $this->get($url)->getStatusCode());
    }

    public function testTraversalPathReturns404(): void
    {
        // Validly signed (we signed it) but resolves to no File record.
        $url = $this->svc()->generateSignedURL('../../etc/passwd', 300);
        $this->assertSame(404, $this->get($url)->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Content disposition
    // -------------------------------------------------------------------------
    public function testPdfServesInlineByDefault(): void
    {
        $pdf = $this->makePdf('serve/doc.pdf');
        $resp = $this->get((string) $pdf->AutoURL('m'));
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('inline', (string) $resp->getHeader('Content-Disposition'));
    }

    public function testDownloadParamForcesAttachment(): void
    {
        $pdf = $this->makePdf('serve/doc-att.pdf');
        $url = (string) $pdf->AutoURL('m');
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'd=att';
        $resp = $this->get($url);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString('attachment', (string) $resp->getHeader('Content-Disposition'));
    }

    // -------------------------------------------------------------------------
    // No-op variant fallback (DDoS-scoped): a no-op Scale streams the original;
    // anything outside the window returns null (-> 404). Security-relevant.
    // -------------------------------------------------------------------------
    private function variantId(string $method, array $args): string
    {
        return $method . rtrim(strtr(base64_encode(json_encode($args)), '+/', '-_'), '=');
    }

    public function testNoOpScaleStreamsOriginal(): void
    {
        $img = $this->makeImage('serve/noop.png', 100, 60, 'LoggedInUsers');
        $ctrl = new TestableSignedAssetUrlController();
        // ScaleWidth target 200 >= source 100 -> no-op -> stream the original.
        $stream = $ctrl->pub_tryServeOriginalForNoOpVariant($img, $this->variantId('ScaleWidth', [200]));
        $this->assertNotNull($stream, 'a no-op upscale must stream the original');
    }

    public function testRealResizeReturnsNull(): void
    {
        $img = $this->makeImage('serve/resize.png', 100, 60, 'LoggedInUsers');
        $ctrl = new TestableSignedAssetUrlController();
        // ScaleWidth target 50 < source 100 -> a real resize is needed -> null.
        $this->assertNull($ctrl->pub_tryServeOriginalForNoOpVariant($img, $this->variantId('ScaleWidth', [50])));
    }

    public function testFillVariantNeverServedAsOriginal(): void
    {
        $img = $this->makeImage('serve/fill.png', 100, 60, 'LoggedInUsers');
        $ctrl = new TestableSignedAssetUrlController();
        // Fill is not in the whitelist (crops/letterboxes) -> never served as original.
        $this->assertNull($ctrl->pub_tryServeOriginalForNoOpVariant($img, $this->variantId('Fill', [200, 200])));
    }

    public function testOversizedTargetReturnsNull(): void
    {
        $img = $this->makeImage('serve/big.png', 100, 60, 'LoggedInUsers');
        $ctrl = new TestableSignedAssetUrlController();
        $this->assertNull($ctrl->pub_tryServeOriginalForNoOpVariant($img, $this->variantId('ScaleWidth', [99999])));
    }
}
