<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Control;

use ReflectionProperty;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * The published-status check in SignedAssetUrlController::serve() against STAGED File (draft and
 * live, the stock silverstripe/versioned setup): a valid signature must not hand out a file that
 * was never published.
 *
 * Most of the suite runs in a host whose File is versioned but not staged (see the CI workflow),
 * where every written file counts as published - so without this class no test reaches the
 * "unpublished -> 403" branch, and dropping the check left every leg green. Mirror of
 * SignedAssetUrlUnversionedFileTest: here Versioned is (re)applied to File in its staged form for
 * this class only, and the temp database is rebuilt with File_Live.
 */
class SignedAssetUrlStagedFileTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /**
     * File's own (uninherited) extensions config before this class changed it.
     */
    private static ?array $originalFileExtensions = null;

    /**
     * Versioned's default reading mode before a test changed it.
     */
    private ?string $originalDefaultReadingMode = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Replace whatever form of Versioned the host applies to File (eg the versioning-only
        // Injector service "Versioned.versioned") with the plain, staged extension - the config
        // silverstripe/versioned itself ships (versionedfiles.yml, key 'Versioned').
        $configured = Config::inst()->get(File::class, 'extensions', Config::EXCLUDE_EXTRA_SOURCES | Config::UNINHERITED) ?: [];
        self::$originalFileExtensions = $configured;
        $staged = [];
        foreach ($configured as $key => $extension) {
            if (Extension::get_classname_without_arguments((string) $extension) === Versioned::class) {
                $extension = Versioned::class;
            }
            $staged[$key] = $extension;
        }
        if (!in_array(Versioned::class, $staged, true)) {
            $staged['Versioned'] = Versioned::class;
        }
        Config::modify()->set(File::class, 'extensions', $staged);
        self::dropExtraMethodsCache();

        // Rebuild the schema with the staged (_Live) tables (as ExtensionTestState does).
        Injector::inst()->unregisterObjects([DataObject::class, Extension::class]);
        DataObject::reset();
        static::resetDBSchema(true, true);
    }

    public static function tearDownAfterClass(): void
    {
        // Put File's extensions back as they were (see SignedAssetUrlUnversionedFileTest for why
        // not via add/remove_extension()).
        if (self::$originalFileExtensions !== null) {
            Config::modify()->set(File::class, 'extensions', self::$originalFileExtensions);
            self::$originalFileExtensions = null;
            self::dropExtraMethodsCache();
        }

        // Later test classes in this run expect the host's File tables again.
        Injector::inst()->unregisterObjects([DataObject::class, Extension::class]);
        DataObject::reset();
        static::resetDBSchema(true, true);

        parent::tearDownAfterClass();
    }

    /**
     * Drop the per-class method cache that add/remove_extension() would drop, so File and its
     * subclasses pick up the changed extension's methods (it is rebuilt on demand).
     */
    private static function dropExtraMethodsCache(): void
    {
        $cache = new ReflectionProperty(File::class, 'extra_methods');
        $methods = $cache->getValue();
        foreach (array_merge([File::class], ClassInfo::subclassesFor(File::class)) as $class) {
            unset($methods[strtolower($class)]);
        }
        $cache->setValue(null, $methods);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsStagedTest');
        $this->originalDefaultReadingMode = Versioned::get_default_reading_mode();
    }

    protected function tearDown(): void
    {
        Versioned::set_default_reading_mode($this->originalDefaultReadingMode);
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * A draft-only protected PDF and its signed URL. Nobody is logged in when the URL is fetched:
     * the request comes from an anonymous URL holder, who cannot bypass signing.
     *
     * @return array{0: File, 1: string}
     */
    private function draftFileWithSignedUrl(string $filename): array
    {
        $file = File::create();
        $file->setFromString("%PDF-1.4\n% fake pdf for tests\n", $filename);
        $file->CanViewType = 'LoggedInUsers';
        $file->write();

        $url = (string) $file->AutoURL('m');
        $this->assertStringStartsWith('/signed-asset/', $url, 'a draft file gets a signed URL');
        $this->logOut();

        return [$file, $url];
    }

    public function testPreconditionFileIsStaged(): void
    {
        $this->assertTrue(File::singleton()->hasExtension(Versioned::class));
        $this->assertTrue(File::singleton()->hasStages(), 'precondition: this class runs with staged File');
        $this->assertTrue(
            (bool) Config::inst()->get(AssetUrlSigningService::class, 'check_published_status'),
            'precondition: the default config checks published status'
        );
    }

    public function testUnpublishedFileIsRefusedWhenTheDraftIsReadable(): void
    {
        // A site whose front end reads the draft stage (default reading mode, or a session left in
        // Stage) finds the draft File record; the published-status check is then all that stands
        // between a valid signature and an unpublished file.
        Versioned::set_default_reading_mode('Stage.' . Versioned::DRAFT);
        [$file, $url] = $this->draftFileWithSignedUrl('staged/draft.pdf');

        $this->assertFalse($file->isPublished(), 'precondition: the file is draft-only');
        $response = $this->get($url);
        $this->assertSame(403, $response->getStatusCode(), 'an unpublished file is not served');
    }

    public function testUnpublishedFileIsNotServedFromTheLiveStage(): void
    {
        // The stock reading mode is live: the draft record is not found at all.
        [, $url] = $this->draftFileWithSignedUrl('staged/draft-live.pdf');

        $response = $this->get($url);
        $this->assertNotSame(200, $response->getStatusCode(), 'an unpublished file is not served');
    }

    public function testPublishedFileIsServed(): void
    {
        Versioned::set_default_reading_mode('Stage.' . Versioned::DRAFT);
        [$file, $url] = $this->draftFileWithSignedUrl('staged/published.pdf');
        $file->publishSingle();

        $this->assertTrue($file->isPublished(), 'precondition: the file is published');
        $response = $this->get($url);
        $this->assertSame(200, $response->getStatusCode(), 'a published file with a valid signature is served');
        $this->assertStringContainsString('application/pdf', (string) $response->getHeader('Content-Type'));
    }
}
