<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Services;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Session;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Service for generating and validating signed asset URLs.
 *
 * Environment variables:
 * - ASSET_SIGNING_SECRET: Required. Secret key for HMAC signing.
 *
 * Config:
 * - default_ttl: Default URL lifetime in seconds (default: 3600)
 * - bypass_permissions: Permissions that bypass signing requirement
 * - bind_to_session: If true, URLs are only valid for the session that created them
 * - check_published_status: If true, verify files are published before serving (when using Versioned with staging)
 */
class AssetUrlSigningService
{
    use Injectable;
    use Configurable;

    private static $default_ttl = 3600;

    /**
     * Permissions that bypass signing. Defaults to Versioned::$non_live_permissions.
     * Set to null to use Versioned's config, or provide custom array.
     */
    private static $bypass_permissions = null;

    private static $bind_to_session = false;

    /**
     * Whether to automatically adjust page cache headers based on signed URL TTLs
     */
    private static $auto_cache_headers = true;

    /**
     * Whether to check if files are published before serving (respects Versioned staging)
     */
    private static $check_published_status = true;

    /**
     * Track the earliest expiry time of signed URLs generated during this request
     */
    protected static $earliestExpiry = null;

    /**
     * Generate a signed URL for an asset
     *
     * @param string $path Relative path within protected folder
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @param bool|null $bindToSession Override session binding (null = use config)
     * @return string Signed URL
     */
    public function generateSignedURL(string $path, ?int $ttl = null, ?bool $bindToSession = null): string
    {
        $ttl = $ttl ?? $this->config()->get('default_ttl');
        $expires = time() + $ttl;
        $bindToSession = $bindToSession ?? $this->config()->get('bind_to_session');

        $sessionToken = $bindToSession ? $this->getSessionToken() : '';
        $hash = $this->generateHash($path, $expires, $sessionToken);

        // URL encode the path but preserve slashes for readability
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        // Track earliest expiry for cache header management
        if ($this->config()->get('auto_cache_headers')) {
            $this->trackExpiry($expires);
        }

        // Build query string with signature params (S3-style)
        $params = [
            's' => $hash,
            'e' => $expires,
        ];
        if ($bindToSession) {
            $params['ss'] = '1';
        }

        return "/signed-asset/{$encodedPath}?" . http_build_query($params);
    }

    /**
     * Validate a signed URL
     *
     * @param string $hash The hash from URL
     * @param int $expires The expiration timestamp from URL
     * @param string $path The file path from URL
     * @param bool $sessionBound Whether URL is bound to session
     * @return bool|string True if valid, or error message string
     */
    public function validateSignature(string $hash, int $expires, string $path, bool $sessionBound = false)
    {
        // Check expiry first
        if ($expires < time()) {
            return 'expired';
        }

        // Get session token if URL is session-bound
        $sessionToken = $sessionBound ? $this->getSessionToken() : '';

        // Validate hash
        $expectedHash = $this->generateHash($path, $expires, $sessionToken);

        if (!hash_equals($expectedHash, $hash)) {
            return 'invalid_signature';
        }

        return true;
    }

    /**
     * Check if current user can bypass signing (admin/CMS access)
     *
     * Uses Versioned::$non_live_permissions by default for consistency
     * with SilverStripe's draft content viewing permissions.
     *
     * @return bool
     */
    public function canBypassSigning(): bool
    {
        $member = Security::getCurrentUser();
        if (!$member) {
            return false;
        }

        $bypassPermissions = $this->config()->get('bypass_permissions');

        // Fall back to Versioned's non_live_permissions if not configured
        if ($bypassPermissions === null) {
            $bypassPermissions = Config::inst()->get(Versioned::class, 'non_live_permissions');
        }

        if (!is_array($bypassPermissions)) {
            $bypassPermissions = [$bypassPermissions];
        }

        return Permission::checkMember($member, $bypassPermissions);
    }

    /**
     * Generate HMAC hash for path, expiry, and optional session token
     *
     * @param string $path
     * @param int $expires
     * @param string $sessionToken Optional session token for session binding
     * @return string 16-character hash
     */
    protected function generateHash(string $path, int $expires, string $sessionToken = ''): string
    {
        $secret = $this->getSigningSecret();
        $data = "{$expires}/{$path}";

        if ($sessionToken) {
            $data .= "/{$sessionToken}";
        }

        return substr(hash_hmac('sha256', $data, $secret), 0, 16);
    }

    /**
     * Get a token representing the current session
     *
     * Uses a hash of the session ID rather than the raw ID for security
     *
     * @return string
     */
    protected function getSessionToken(): string
    {
        $sessionId = session_id();

        if (empty($sessionId)) {
            // Try to get from SilverStripe's session
            try {
                $request = Controller::curr()->getRequest();
                $session = $request->getSession();
                $sessionId = $session->getAll()['SecurityID'] ?? session_id();
            } catch (\Exception $e) {
                $sessionId = '';
            }
        }

        if (empty($sessionId)) {
            return '';
        }

        // Hash the session ID so we don't expose it
        $secret = $this->getSigningSecret();
        return substr(hash_hmac('sha256', $sessionId, $secret), 0, 8);
    }

    /**
     * Get the signing secret from environment
     *
     * @return string
     * @throws \RuntimeException if secret not configured
     */
    protected function getSigningSecret(): string
    {
        $secret = Environment::getEnv('ASSET_SIGNING_SECRET');

        if (empty($secret)) {
            throw new \RuntimeException(
                'ASSET_SIGNING_SECRET environment variable must be set for signed asset URLs'
            );
        }

        return $secret;
    }

    /**
     * Get the full filesystem path to protected assets folder
     *
     * Uses same logic as SilverStripe's ProtectedAssetAdapter:
     * 1. SS_PROTECTED_ASSETS_PATH environment variable (relative to PUBLIC_PATH)
     * 2. ProtectedAssetAdapter::$secure_folder config (under ASSETS_PATH)
     *
     * Note: This module is designed for local filesystem storage with web server handoff.
     * For remote storage (S3, etc.), assets should be served via pre-signed URLs from
     * the storage provider, not through this module.
     *
     * @return string Resolved absolute path
     */
    public function getProtectedFolderPath(): string
    {
        // First check SS_PROTECTED_ASSETS_PATH (same as SilverStripe's ProtectedAssetAdapter)
        // This path is relative to PUBLIC_PATH (webroot)
        $path = Environment::getEnv('SS_PROTECTED_ASSETS_PATH');

        if ($path) {
            // Resolve relative to PUBLIC_PATH (webroot) for consistency with SS
            return $this->resolveRelativePath($path, PUBLIC_PATH);
        }

        // Fall back to SS's ProtectedAssetAdapter config: ASSETS_PATH / secure_folder
        $secureFolder = Config::inst()->get(ProtectedAssetAdapter::class, 'secure_folder');
        return ASSETS_PATH . '/' . $secureFolder;
    }

    /**
     * Resolve a relative path from a base directory
     *
     * @param string $path Path to resolve (can be absolute or relative)
     * @param string $basePath Base directory for relative paths
     * @return string Resolved absolute path
     */
    protected function resolveRelativePath(string $path, string $basePath): string
    {
        // Absolute path - use as-is
        if (strpos($path, '/') === 0) {
            return $path;
        }

        // Resolve relative path from basePath
        $fullPath = $basePath . '/' . $path;

        // Use realpath to normalize the path (handles ../ etc)
        $resolved = realpath($fullPath);

        return $resolved ?: $fullPath;
    }

    /**
     * Check if published status should be verified before serving files
     *
     * @return bool
     */
    public function shouldCheckPublishedStatus(): bool
    {
        return (bool) $this->config()->get('check_published_status');
    }

    /**
     * Track an expiry time for cache header management
     *
     * @param int $expires Unix timestamp
     */
    protected function trackExpiry(int $expires): void
    {
        if (self::$earliestExpiry === null || $expires < self::$earliestExpiry) {
            self::$earliestExpiry = $expires;
        }
    }

    /**
     * Get the earliest expiry time of signed URLs generated during this request
     *
     * @return int|null Unix timestamp or null if no signed URLs were generated
     */
    public static function getEarliestExpiry(): ?int
    {
        return self::$earliestExpiry;
    }

    /**
     * Reset the expiry tracker (called at start of each request by middleware)
     */
    public static function resetExpiryTracker(): void
    {
        self::$earliestExpiry = null;
    }
}
