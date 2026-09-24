<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Extensions;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * An unknown policy name in AutoURL()/MaskedURL() falls back to the default TTL WITHOUT session
 * binding. The README once used the nonexistent 'md'/'md_sess', so a copied template handed out
 * shareable links where session-bound ones were meant, and nothing said so. The fallback is kept
 * in 1.x; it now logs a warning.
 */
class SignedUrlUnknownPolicyTest extends SapphireTest
{
    protected $usesDatabase = true; // File::write()

    /**
     * Captured log records: [level, message].
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
        TestAssetStore::activate('SignedUrlUnknownPolicyTest');

        // Replace the logger service for this test only (SapphireTest nests the Injector per test).
        $records = &$this->records;
        $logger = new class ($records) extends AbstractLogger {
            private array $records;

            public function __construct(array &$records)
            {
                $this->records = &$records;
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        Injector::inst()->registerService($logger, LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function protectedFile(): File
    {
        $file = File::create();
        $file->setFromString("%PDF-1.4\n% fake pdf for tests\n", 'policy/doc.pdf');
        $file->CanViewType = 'LoggedInUsers';
        $file->write();

        return $file;
    }

    /**
     * @return string[] messages logged at warning level
     */
    private function warnings(): array
    {
        return array_values(array_map(
            fn (array $record) => $record[1],
            array_filter($this->records, fn (array $record) => $record[0] === 'warning')
        ));
    }

    public function testUnknownPolicyLogsAWarningAndKeepsTheFallback(): void
    {
        $url = (string) $this->protectedFile()->AutoURL('md_sess');

        // The 1.x fallback is unchanged: a signed URL, default TTL, not session-bound.
        $this->assertStringStartsWith('/signed-asset/', $url);
        $this->assertStringNotContainsString('ss=1', $url);

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings, 'an unknown policy name is reported once');
        $this->assertStringContainsString('"md_sess"', $warnings[0]);
        $this->assertStringContainsString('ms', $warnings[0], 'the known policies are listed');
    }

    public function testKnownPolicyAndTtlLogNothing(): void
    {
        $file = $this->protectedFile();
        $this->assertStringContainsString('ss=1', (string) $file->AutoURL('ms'));
        $file->AutoURL(120);
        $file->AutoURL();

        $this->assertSame([], $this->warnings());
    }
}
