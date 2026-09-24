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
 * Regression: serving a signed URL called File::isPublished() unconditionally, and that method
 * only exists when File carries the Versioned extension. silverstripe/versioned is not a
 * dependency of silverstripe/assets or recipe-core, so on a project without it every signed URL
 * failed under the default config (check_published_status: true).
 *
 * Versioned is removed from File for this test class only, and the temp database is rebuilt
 * without the _Live/_Versions tables - the shape of a project that never installed
 * silverstripe/versioned.
 */
class SignedAssetUrlUnversionedFileTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /**
     * File's own (uninherited) extensions config before this class removed Versioned.
     */
    private static ?array $originalFileExtensions = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Not via $illegal_extensions: that path only removes an extension configured as the
        // plain class name (ExtensionTestState checks class_exists() on the configured string,
        // and remove_extension() matches it exactly or as "Class(args)"). A project that applies
        // Versioned as an Injector service - "Versioned.versioned", the versioning-only form -
        // would silently keep it. So match on the class name and remove the configured string.
        $configured = Config::inst()->get(File::class, 'extensions', Config::EXCLUDE_EXTRA_SOURCES | Config::UNINHERITED) ?: [];
        self::$originalFileExtensions = $configured;
        foreach ($configured as $extension) {
            if (Extension::get_classname_without_arguments((string) $extension) === Versioned::class) {
                File::remove_extension($extension);
            }
        }

        // Rebuild the schema without the versioned tables (as ExtensionTestState does).
        Injector::inst()->unregisterObjects([DataObject::class, Extension::class]);
        DataObject::reset();
        static::resetDBSchema(true, true);
    }

    public static function tearDownAfterClass(): void
    {
        // Put File's extensions back as they were. Not via add_extension(): it rejects the
        // Injector-service form ("Can't find extension class for ...Versioned.versioned").
        if (self::$originalFileExtensions !== null) {
            Config::modify()->set(File::class, 'extensions', self::$originalFileExtensions);
            self::$originalFileExtensions = null;
            // Drop the per-class method cache that add/remove_extension() would drop, so File and
            // its subclasses pick Versioned's methods up again (it is rebuilt on demand).
            $cache = new ReflectionProperty(File::class, 'extra_methods');
            $methods = $cache->getValue();
            foreach (array_merge([File::class], ClassInfo::subclassesFor(File::class)) as $class) {
                unset($methods[strtolower($class)]);
            }
            $cache->setValue(null, $methods);
        }

        // Later test classes in this run expect File's versioned tables again.
        Injector::inst()->unregisterObjects([DataObject::class, Extension::class]);
        DataObject::reset();
        static::resetDBSchema(true, true);

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedAssetUrlsUnversionedTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testPreconditionFileIsNotVersioned(): void
    {
        $this->assertFalse(
            File::singleton()->hasExtension(Versioned::class),
            'precondition: this class runs with Versioned removed from File'
        );
    }

    public function testServesProtectedFileWhenFileHasNoVersionedExtension(): void
    {
        $this->assertTrue(
            (bool) Config::inst()->get(AssetUrlSigningService::class, 'check_published_status'),
            'precondition: the default config checks published status'
        );

        $file = File::create();
        $file->setFromString("%PDF-1.4\n% fake pdf for tests\n", 'unversioned/doc.pdf');
        $file->CanViewType = 'LoggedInUsers';
        $file->write();

        $url = (string) $file->AutoURL('m');
        $this->assertStringStartsWith('/signed-asset/', $url, 'a restricted file gets a signed URL');

        $this->logOut();
        $response = $this->get($url);
        $this->assertSame(200, $response->getStatusCode(), 'no Versioned means no draft state: serve it');
        $this->assertStringContainsString('application/pdf', (string) $response->getHeader('Content-Type'));
    }
}
