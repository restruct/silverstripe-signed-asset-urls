<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Control;

use Restruct\SilverStripe\SignedAssetUrls\Tests\Stub\AssetStoreResponseHeaderExtension;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;

/**
 * Issue #14: a file served through a signed URL runs the asset store's `updateResponse` extension
 * hook, as core's own asset responses do, so headers other modules add (eg the SVG CSP sandbox of
 * restruct/silverstripe-svg-images) are also on a signed response. Covers both response builders:
 * PHP streaming and the web server handoff (X-Sendfile).
 *
 * Uses the non-session 'm' policy so validation doesn't depend on a session token.
 */
class SignedAssetUrlResponseHookTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $required_extensions = [
        FlysystemAssetStore::class => [AssetStoreResponseHeaderExtension::class],
    ];

    /**
     * ASSET_FILE_SERVER before the test, restored in tearDown()
     */
    private $fileServerBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        $this->fileServerBefore = Environment::getEnv('ASSET_FILE_SERVER');
        TestAssetStore::activate('SignedAssetUrlsResponseHookTest');
        AssetStoreResponseHeaderExtension::$calls = [];
    }

    protected function tearDown(): void
    {
        Environment::setEnv('ASSET_FILE_SERVER', $this->fileServerBefore === false ? '' : (string) $this->fileServerBefore);
        AssetStoreResponseHeaderExtension::$calls = [];
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function makeImage(string $filename, int $w, int $h): Image
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 90, 140, 200));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        $img = Image::create();
        $img->setFromString($bytes, $filename);
        # Protected: served through a signed URL
        $img->CanViewType = 'LoggedInUsers';
        $img->write();
        return $img;
    }

    public function testStreamedOriginalRunsUpdateResponse(): void
    {
        Environment::setEnv('ASSET_FILE_SERVER', 'php');
        $img = $this->makeImage('hook/original.png', 200, 100);

        $resp = $this->get((string) $img->AutoURL('m'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('applied', $resp->getHeader(AssetStoreResponseHeaderExtension::HEADER));
        $this->assertCount(1, AssetStoreResponseHeaderExtension::$calls, 'hook runs once per response');

        [$asset, $context] = AssetStoreResponseHeaderExtension::$calls[0];
        $this->assertStringEndsWith('original.png', $asset, 'asset is the file ID in the store');
        $this->assertSame(FlysystemAssetStore::VISIBILITY_PROTECTED, $context['visibility'] ?? null);
        $this->assertInstanceOf(ParsedFileID::class, $context['parsedFileID'] ?? null);
        $this->assertSame('hook/original.png', $context['parsedFileID']->getFilename());
        $this->assertTrue($context['signedUrl'] ?? false);
    }

    public function testStreamedVariantRunsUpdateResponse(): void
    {
        Environment::setEnv('ASSET_FILE_SERVER', 'php');
        $img = $this->makeImage('hook/variant.png', 400, 240);

        $resp = $this->get((string) $img->MaskedScaleWidthURL(180, 'm'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('applied', $resp->getHeader(AssetStoreResponseHeaderExtension::HEADER));
        [$asset, $context] = AssetStoreResponseHeaderExtension::$calls[0];
        $this->assertStringContainsString('__ScaleWidth', $asset, 'asset is the variant file ID');
        $this->assertStringStartsWith('ScaleWidth', $context['parsedFileID']->getVariant());
    }

    public function testWebServerHandoffRunsUpdateResponse(): void
    {
        Environment::setEnv('ASSET_FILE_SERVER', 'apache');
        $img = $this->makeImage('hook/sendfile.png', 200, 100);

        $resp = $this->get((string) $img->AutoURL('m'));

        $this->assertSame(200, $resp->getStatusCode());
        $this->assertNotEmpty($resp->getHeader('X-Sendfile'), 'precondition: web server handoff');
        $this->assertSame('applied', $resp->getHeader(AssetStoreResponseHeaderExtension::HEADER));
    }
}
