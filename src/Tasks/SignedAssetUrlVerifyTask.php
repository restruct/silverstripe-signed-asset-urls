<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tasks;

use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

/**
 * Verify signed assets configuration and test URL generation/validation.
 *
 * Run via: vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask
 */
class SignedAssetUrlVerifyTask extends BuildTask
{
    private static $segment = 'SignedAssetUrlVerifyTask';

    protected $title = 'Verify Signed Asset URLs Configuration';

    protected $description = 'Tests signed URL generation and validation to verify correct installation and configuration.';

    public function run($request): void
    {
        /** @var AssetUrlSigningService $service */
        $service = Injector::inst()->get(AssetUrlSigningService::class);

        $this->output("=== Signed Asset URLs Configuration Verification ===");
        $this->output("");

        // 1. Check environment variable
        $this->output("1. Environment variable ASSET_SIGNING_SECRET... ", false);
        try {
            // This will throw if secret is not set
            $service->generateSignedURL('test.txt');
            $this->output("OK");
        } catch (\RuntimeException $e) {
            $this->output("FAILED");
            $this->output("   Error: " . $e->getMessage());
            $this->output("   Add ASSET_SIGNING_SECRET to your .env file");
            $this->output("");
            return;
        }

        // 2. Show protected folder path
        $protectedPath = $service->getProtectedFolderPath();
        $protectedExists = is_dir($protectedPath);
        $this->output("2. Protected folder path: " . $protectedPath . "... " . ($protectedExists ? "EXISTS" : "NOT FOUND"));

        // 3. Show config values
        $config = $service->config();
        $this->output("");
        $this->output("=== Configuration ===");
        $this->output("default_ttl: " . $config->get('default_ttl') . " seconds");
        $this->output("bind_to_session: " . ($config->get('bind_to_session') ? 'true' : 'false'));
        $this->output("auto_cache_headers: " . ($config->get('auto_cache_headers') ? 'true' : 'false'));
        $this->output("check_published_status: " . ($config->get('check_published_status') ? 'true' : 'false'));

        // 4. Test URL generation
        $this->output("");
        $this->output("=== URL Generation Test ===");
        $testPath = 'test.txt';
        $signedUrl = $service->generateSignedURL($testPath, 3600);
        $this->output("Test path: " . $testPath);
        $this->output("Signed URL: " . $signedUrl);

        // Parse URL components (S3-style: /signed-asset/{path}?s={hash}&e={expires}&ss={session})
        $urlParts = parse_url($signedUrl);
        $urlPath = preg_replace('#^/signed-asset/#', '', $urlParts['path'] ?? '');
        parse_str($urlParts['query'] ?? '', $queryParams);

        $hash = $queryParams['s'] ?? '';
        $expires = (int) ($queryParams['e'] ?? 0);
        $sessionBound = isset($queryParams['ss']);

        if ($hash && $expires && $urlPath) {
            $this->output("Hash: " . $hash . " (" . strlen($hash) . " chars)");
            $this->output("Expires: " . $expires . " (" . date('Y-m-d H:i:s', $expires) . ")");
            $this->output("Session bound: " . ($sessionBound ? 'yes' : 'no'));
            $this->output("Path: " . rawurldecode($urlPath));

            // 5. Test validation
            $this->output("");
            $this->output("=== Validation Tests ===");

            // Valid signature
            $result = $service->validateSignature($hash, $expires, rawurldecode($urlPath), $sessionBound);
            $this->output("Valid signature: " . ($result === true ? "PASS" : "FAIL (" . $result . ")"));

            // Wrong hash
            $badResult = $service->validateSignature('wronghash1234567', $expires, rawurldecode($urlPath), $sessionBound);
            $this->output("Wrong hash (expect invalid): " . ($badResult === 'invalid_signature' ? "PASS" : "FAIL"));

            // Expired URL
            $expiredResult = $service->validateSignature($hash, time() - 1, rawurldecode($urlPath), $sessionBound);
            $this->output("Expired URL (expect expired): " . ($expiredResult === 'expired' ? "PASS" : "FAIL"));
        } else {
            $this->output("ERROR: Could not parse signed URL format");
        }

        // 6. Test session-bound URL
        $this->output("");
        $this->output("=== Session-Bound URL Test ===");
        $sessionUrl = $service->generateSignedURL($testPath, 3600, true);
        $this->output("Session-bound URL: " . $sessionUrl);
        $hasSessionFlag = str_contains($sessionUrl, 'ss=1');
        $this->output("Contains session flag (ss=1): " . ($hasSessionFlag ? "PASS" : "FAIL"));

        $this->output("");
        $this->output("=== Verification Complete ===");
        $this->output("");
        $this->output("Note: Files are served via PHP streaming using SilverStripe's AssetStore.");
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
