<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Tasks;

use ReflectionProperty;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use Restruct\SilverStripe\SignedAssetUrls\Tasks\SignedAssetUrlVerifyTask;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * SignedAssetUrlVerifyTask on both Silverstripe majors: it is listed, its title and description
 * are served (both were properties SS6 made typed/static), the version-specific entry point from
 * SignedAssetUrlVerifyTaskEntryPoint actually runs the checks, and the checks report what the
 * signing service really does.
 */
class SignedAssetUrlVerifyTaskTest extends FunctionalTest
{
    protected $usesDatabase = true; // logInWithPermission() for the dev/tasks listing

    private const SECRET = 'test-signing-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        Environment::setEnv('ASSET_FILE_SERVER', 'php');
    }

    /**
     * Run the version-neutral body and collect its output as plain text.
     */
    private function verifyOutput(): string
    {
        $out = '';
        SignedAssetUrlVerifyTask::create()->verify(function (string $message, bool $newline = true) use (&$out): void {
            $out .= $message . ($newline ? "\n" : '');
        });

        return $out;
    }

    public function testTitleAndDescription(): void
    {
        $task = SignedAssetUrlVerifyTask::create();

        $this->assertSame('Verify Signed Asset URLs Configuration', $task->getTitle());
        $this->assertSame(SignedAssetUrlVerifyTask::DESCRIPTION, $task->getDescription());
        $this->assertStringContainsString('signed URL generation', $task->getDescription());
        $this->assertTrue($task->isEnabled());
    }

    public function testIsListedOnDevTasks(): void
    {
        // The dev/tasks listing reflects on every BuildTask subclass; a task class that fails to
        // declare on this major breaks the whole list, not just itself.
        $this->logInWithPermission('ADMIN');
        $response = $this->get('dev/tasks');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SignedAssetUrlVerifyTask', $response->getBody());
        $this->assertStringContainsString('Verify Signed Asset URLs Configuration', $response->getBody());
    }

    public function testChecksPassWithAConfiguredSecret(): void
    {
        $out = $this->verifyOutput();

        $this->assertStringContainsString('ASSET_SIGNING_SECRET... OK', $out);
        $this->assertStringContainsString('Valid signature: PASS', $out);
        $this->assertStringContainsString('Wrong hash (expect invalid): PASS', $out);
        $this->assertStringContainsString('Expired URL (expect expired): PASS', $out);
        $this->assertStringContainsString('Contains session flag (ss=1): PASS', $out);
        $this->assertStringNotContainsString('FAIL', $out);
        $this->assertStringContainsString('=== Verification Complete ===', $out);
    }

    public function testMissingSecretIsReportedAndStops(): void
    {
        Environment::setEnv('ASSET_SIGNING_SECRET', '');
        try {
            $out = $this->verifyOutput();
        } finally {
            Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        }

        $this->assertStringContainsString('ASSET_SIGNING_SECRET... FAILED', $out);
        $this->assertStringContainsString('Add ASSET_SIGNING_SECRET to your .env file', $out);
        $this->assertStringNotContainsString('Verification Complete', $out, 'the task stops at a missing secret');
    }

    public function testReportsConfiguredValues(): void
    {
        Config::modify()->set(AssetUrlSigningService::class, 'default_ttl', 123);
        Config::modify()->set(AssetUrlSigningService::class, 'bind_to_session', true);

        $out = $this->verifyOutput();

        $this->assertStringContainsString('default_ttl: 123 seconds', $out);
        $this->assertStringContainsString('bind_to_session: true', $out);
    }

    public function testNginxFileServerPrintsTheLocationBlock(): void
    {
        Environment::setEnv('ASSET_FILE_SERVER', 'nginx');
        try {
            $out = $this->verifyOutput();
        } finally {
            Environment::setEnv('ASSET_FILE_SERVER', 'php');
        }

        $this->assertStringContainsString('ASSET_FILE_SERVER: nginx', $out);
        $this->assertStringContainsString('internal;', $out);
    }

    public function testEntryPointForThisMajorRunsTheChecks(): void
    {
        // Drives the real entry point (run() on SS5, execute() via run() on SS6), so a trait that
        // is wired to the wrong major, or not wired at all, fails here.
        $task = SignedAssetUrlVerifyTask::create();

        if (class_exists(PolyOutput::class)) {
            $buffer = new BufferedOutput();
            $output = new PolyOutput(PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
            $exitCode = $task->run(new ArrayInput([]), $output);
            $out = $buffer->fetch();
            $this->assertSame(Command::SUCCESS, $exitCode);
        } else {
            ob_start();
            try {
                $task->run(new HTTPRequest('GET', 'dev/tasks/SignedAssetUrlVerifyTask'));
            } finally {
                $out = (string) ob_get_clean();
            }
        }

        $this->assertStringContainsString('Valid signature: PASS', $out);
        $this->assertStringContainsString('=== Verification Complete ===', $out);
    }

    /**
     * Run this major's real entry point with ASSET_FILE_SERVER=apache, rendering for a browser
     * (HTML) or a terminal, and return what it wrote.
     */
    private function apacheHintOutput(bool $html): string
    {
        Environment::setEnv('ASSET_FILE_SERVER', 'apache');
        $task = SignedAssetUrlVerifyTask::create();
        try {
            if (class_exists(PolyOutput::class)) {
                $buffer = new BufferedOutput();
                $output = new PolyOutput($html ? PolyOutput::FORMAT_HTML : PolyOutput::FORMAT_ANSI, wrappedOutput: $buffer);
                $task->run(new ArrayInput([]), $output);
                return $buffer->fetch();
            }

            # Silverstripe 5 decides by Director::is_cli(), which is always true under PHPUnit:
            # set framework's own (internal) override for the duration of the run.
            $isCli = new ReflectionProperty(Environment::class, 'isCliOverride');
            $previous = $isCli->getValue();
            $isCli->setValue(null, !$html);
            ob_start();
            try {
                $task->run(new HTTPRequest('GET', 'dev/tasks/SignedAssetUrlVerifyTask'));
            } finally {
                $out = (string) ob_get_clean();
                $isCli->setValue(null, $previous);
            }
            return $out;
        } finally {
            Environment::setEnv('ASSET_FILE_SERVER', 'php');
        }
    }

    /**
     * Issue #3: in a browser the Apache hint's "<IfModule ...>" lines were written raw and parsed
     * as tags, so they were not visible. HTML output is escaped; terminal output stays plain.
     */
    public function testApacheHintIsVisibleInABrowser(): void
    {
        $html = $this->apacheHintOutput(true);
        $this->assertStringContainsString('&lt;IfModule mod_xsendfile.c&gt;', $html);
        $this->assertStringContainsString('&lt;/IfModule&gt;', $html);
        $this->assertStringNotContainsString('<IfModule', $html, 'no raw tag left for the browser to swallow');
        $this->assertStringContainsString('<br>', $html, 'line breaks are still HTML');
    }

    public function testApacheHintIsPlainTextInATerminal(): void
    {
        $cli = $this->apacheHintOutput(false);
        $this->assertStringContainsString('<IfModule mod_xsendfile.c>', $cli);
        $this->assertStringNotContainsString('&lt;', $cli);
    }
}
