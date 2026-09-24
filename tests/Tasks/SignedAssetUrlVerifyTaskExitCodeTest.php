<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Tasks;

use Restruct\SilverStripe\SignedAssetUrls\Tasks\SignedAssetUrlVerifyTask;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Regression: on Silverstripe 6 the verify task printed FAILED for a missing secret and still
 * exited 0 ("completed successfully"), so a deploy hook or cron running
 * `sake tasks:SignedAssetUrlVerifyTask || alert` never alerted.
 *
 * verify() now reports whether every check passed, and the SS6 entry point maps a failure to
 * Command::FAILURE. SS5's BuildTask::run() has no exit-code channel, so on SS5 only verify()'s
 * result is asserted.
 */
class SignedAssetUrlVerifyTaskExitCodeTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const SECRET = 'test-signing-secret-0123456789';

    /**
     * The secret as it was before this class changed it (the host's .env may set one).
     */
    private $originalSecret = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSecret = Environment::getEnv('ASSET_SIGNING_SECRET');
        Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        Environment::setEnv('ASSET_FILE_SERVER', 'php');
    }

    protected function tearDown(): void
    {
        Environment::setEnv('ASSET_SIGNING_SECRET', $this->originalSecret === false ? '' : (string) $this->originalSecret);
        parent::tearDown();
    }

    /**
     * Run verify() with its output discarded, and return its result.
     */
    private function verifyResult(): bool
    {
        return SignedAssetUrlVerifyTask::create()->verify(function (string $message, bool $newline = true): void {
        });
    }

    public function testVerifyReportsSuccessWhenEveryCheckPasses(): void
    {
        $this->assertTrue($this->verifyResult());
    }

    public function testMissingSecretIsAFailure(): void
    {
        Environment::setEnv('ASSET_SIGNING_SECRET', '');

        $this->assertFalse($this->verifyResult(), 'verify() reports the missing secret as a failure');

        if (class_exists(PolyOutput::class)) {
            // Silverstripe 6: the exit code of the real entry point (BuildTask::run() returns what
            // execute() returned).
            $buffer = new BufferedOutput();
            $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
            $exitCode = SignedAssetUrlVerifyTask::create()->run(new ArrayInput([]), $output);

            $this->assertStringContainsString('FAILED', $buffer->fetch());
            $this->assertSame(Command::FAILURE, $exitCode, 'a failed check must not exit 0');
        }
    }
}
