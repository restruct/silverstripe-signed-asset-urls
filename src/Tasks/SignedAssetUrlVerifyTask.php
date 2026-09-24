<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tasks;

use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

/**
 * Verify signed assets configuration and test URL generation/validation.
 *
 * Run via:
 * - Silverstripe 6: vendor/bin/sake tasks:SignedAssetUrlVerifyTask
 * - Silverstripe 5: vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask
 * - either, in a browser as an admin: /dev/tasks/SignedAssetUrlVerifyTask
 *
 * The entry point (run() on SS5, execute() on SS6) and getDescription() come from
 * SignedAssetUrlVerifyTaskEntryPoint, because BuildTask's shape differs between the two majors
 * in ways one class body cannot satisfy - see that file for why it is done this way.
 */
class SignedAssetUrlVerifyTask extends BuildTask
{
    use SignedAssetUrlVerifyTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/SignedAssetUrlVerifyTask). Inert config on
     * Silverstripe 6, where $commandName names the task instead.
     *
     * @config
     */
    private static $segment = 'SignedAssetUrlVerifyTask';

    /**
     * Silverstripe 6 command name (sake tasks:SignedAssetUrlVerifyTask). Declared here, not in the
     * trait: SS5's BuildTask has no such property, so this is a new static there, and on SS6 it is
     * a compatible redeclaration of PolyCommand::$commandName (same type, same staticness).
     */
    protected static string $commandName = 'SignedAssetUrlVerifyTask';

    // Were declared as properties. Silverstripe 6 types BuildTask::$title as `string` and makes
    // $description a typed static, so either redeclaration is a fatal "must be compatible" error
    // there, on class load - and the class manifest loads every class on a flush.
    // The title is now assigned in the constructor, the description served by getDescription().
//    protected $title = 'Verify Signed Asset URLs Configuration';
//
//    protected $description = 'Tests signed URL generation and validation to verify correct installation and configuration.';

    /**
     * Shared by both majors' getDescription() (see the entry-point trait).
     */
    public const DESCRIPTION = 'Tests signed URL generation and validation to verify correct installation and configuration.';

    public function __construct()
    {
        parent::__construct();
        // Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6,
        // and no single redeclaration is compatible with both.
        $this->title = 'Verify Signed Asset URLs Configuration';
    }

    /**
     * Version-neutral body of the task: every check the task reports, written through $write.
     *
     * The SS5 entry point writes with output() below (echo, <br> in a browser); the SS6 entry
     * point writes to PolyOutput, which renders for the terminal or the browser itself.
     *
     * @param callable $write function (string $message, bool $newline = true): void
     */
    public function verify(callable $write): void
    {
        // Keeps the body below unchanged: it was written against $output($message, $newline).
        $output = function (string $message, bool $newline = true) use ($write): void {
            $write($message, $newline);
        };

        /** @var AssetUrlSigningService $service */
        $service = Injector::inst()->get(AssetUrlSigningService::class);

        $output("=== Signed Asset URLs Configuration Verification ===");
        $output("");

        // 1. Check environment variable
        $output("1. Environment variable ASSET_SIGNING_SECRET... ", false);
        try {
            // This will throw if secret is not set
            $service->generateSignedURL('test.txt');
            $output("OK");
        } catch (\RuntimeException $e) {
            $output("FAILED");
            $output("   Error: " . $e->getMessage());
            $output("   Add ASSET_SIGNING_SECRET to your .env file");
            $output("");
            return;
        }

        // 2. Show protected folder path
        $protectedPath = $service->getProtectedFolderPath();
        $protectedExists = is_dir($protectedPath);
        $output("2. Protected folder path: " . $protectedPath . "... " . ($protectedExists ? "EXISTS" : "NOT FOUND"));

        // 3. Show config values
        $config = $service->config();
        $output("");
        $output("=== Configuration ===");
        $output("default_ttl: " . $config->get('default_ttl') . " seconds");
        $output("bind_to_session: " . ($config->get('bind_to_session') ? 'true' : 'false'));
        $output("auto_cache_headers: " . ($config->get('auto_cache_headers') ? 'true' : 'false'));
        $output("check_published_status: " . ($config->get('check_published_status') ? 'true' : 'false'));

        // 4. Test URL generation
        $output("");
        $output("=== URL Generation Test ===");
        $testPath = 'test.txt';
        $signedUrl = $service->generateSignedURL($testPath, 3600);
        $output("Test path: " . $testPath);
        $output("Signed URL: " . $signedUrl);

        // Parse URL components (S3-style: /signed-asset/{path}?s={hash}&e={expires}&ss={session})
        $urlParts = parse_url($signedUrl);
        $urlPath = preg_replace('#^/signed-asset/#', '', $urlParts['path'] ?? '');
        parse_str($urlParts['query'] ?? '', $queryParams);

        $hash = $queryParams['s'] ?? '';
        $expires = (int) ($queryParams['e'] ?? 0);
        $sessionBound = isset($queryParams['ss']);

        if ($hash && $expires && $urlPath) {
            $output("Hash: " . $hash . " (" . strlen($hash) . " chars)");
            $output("Expires: " . $expires . " (" . date('Y-m-d H:i:s', $expires) . ")");
            $output("Session bound: " . ($sessionBound ? 'yes' : 'no'));
            $output("Path: " . rawurldecode($urlPath));

            // 5. Test validation
            $output("");
            $output("=== Validation Tests ===");

            // Valid signature
            $result = $service->validateSignature($hash, $expires, rawurldecode($urlPath), $sessionBound);
            $output("Valid signature: " . ($result === true ? "PASS" : "FAIL (" . $result . ")"));

            // Wrong hash
            $badResult = $service->validateSignature('wronghash1234567', $expires, rawurldecode($urlPath), $sessionBound);
            $output("Wrong hash (expect invalid): " . ($badResult === 'invalid_signature' ? "PASS" : "FAIL"));

            // Expired URL
            $expiredResult = $service->validateSignature($hash, time() - 1, rawurldecode($urlPath), $sessionBound);
            $output("Expired URL (expect expired): " . ($expiredResult === 'expired' ? "PASS" : "FAIL"));
        } else {
            $output("ERROR: Could not parse signed URL format");
        }

        // 6. Test session-bound URL
        $output("");
        $output("=== Session-Bound URL Test ===");
        $sessionUrl = $service->generateSignedURL($testPath, 3600, true);
        $output("Session-bound URL: " . $sessionUrl);
        $hasSessionFlag = str_contains($sessionUrl, 'ss=1');
        $output("Contains session flag (ss=1): " . ($hasSessionFlag ? "PASS" : "FAIL"));

        // 7. Show file server configuration
        $output("");
        $output("=== File Server Configuration ===");
        $fileServer = $service->getFileServer();
        $output("ASSET_FILE_SERVER: " . $fileServer);

        if ($fileServer === 'nginx') {
            $output("");
            $output("Nginx X-Accel-Redirect is enabled.");
            $output("Add this to your nginx config:");
            $output("");
            foreach (explode("\n", $service->getNginxConfigHint()) as $line) {
                $output("    " . $line);
            }
        } elseif ($fileServer === 'apache') {
            $output("");
            $output("Apache X-Sendfile is enabled.");
            $output("Add this to your Apache config or .htaccess:");
            $output("");
            foreach (explode("\n", $service->getApacheConfigHint()) as $line) {
                $output("    " . $line);
            }
        } else {
            $output("Files are served via PHP streaming (default).");
            $output("");
            $output("For better performance, consider enabling web server file handoff:");
            $output("  - Set ASSET_FILE_SERVER=nginx or ASSET_FILE_SERVER=apache in .env");
            $output("  - Re-run this task to see required web server configuration");
        }

        $output("");
        $output("=== Verification Complete ===");
    }

    /**
     * Output a line with appropriate line ending for CLI or web
     *
     * @param string $message The message to output
     * @param bool $newline Whether to add a newline (default: true)
     */
    protected function output(string $message, bool $newline = true): void
    {
        echo $message;
        if ($newline) {
            echo Director::is_cli() ? "\n" : "<br>\n";
        }
    }
}
