<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;

/**
 * Extension for File/Image to add signed URL generation.
 *
 * Adds getSignedURL() method that generates time-limited signed URLs
 * for protected assets. Falls back to normal URL for public files.
 *
 * Supports named policies for convenient template usage:
 *   $Image.AutoURL('ss')  ->  30 seconds, session-bound
 *   $Image.AutoURL('m')       ->  1 hour, not session-bound
 */
class SignedAssetUrlExtension extends Extension
{
    /**
     * Named policies for signed URL generation.
     *
     * Each policy defines TTL (in seconds) and session binding.
     * Use in templates: $Image.AutoURL('policy_name')
     *
     * @config
     * @var array<string, array{ttl: int, session: bool}>
     */
    private static array $policies = [
        'ss' => ['ttl' => 30, 'session' => true],      // Short, session-bound (30s)
        's' => ['ttl' => 30, 'session' => false],      // Short, not session-bound
        'ms' => ['ttl' => 3600, 'session' => true],    // Medium, session-bound (1h)
        'm' => ['ttl' => 3600, 'session' => false],    // Medium, not session-bound
        'ls' => ['ttl' => 86400, 'session' => true],   // Long, session-bound (24h)
        'l' => ['ttl' => 86400, 'session' => false],   // Long, not session-bound
    ];

    /**
     * Get a signed URL for this asset
     *
     * For protected files: returns a signed URL with expiration
     * For public files: falls back to normal getURL()
     *
     * @param int|null $ttl Time-to-live in seconds (null = use default from config)
     * @param bool|null $bindToSession Bind to current session (null = use config default)
     * @return string|null Signed URL, normal URL for public files, or null if file doesn't exist
     */
    public function getSignedURL(?int $ttl = null, ?bool $bindToSession = null): ?string
    {
        /** @var File $file */
        $file = $this->getOwner();

        // File must have a filename
        $filename = $file->getFilename();

        if (empty($filename)) {
            return null;
        }

        // Check if file is protected (CanViewType restrictions or unpublished)
        if (!$this->requiresSignedURL()) {
            // Public file - return normal URL
            return $file->getURL();
        }

        // Protected file - generate signed URL
        /** @var AssetUrlSigningService $signingService */
        $signingService = Injector::inst()->get(AssetUrlSigningService::class);

        return $signingService->generateSignedURL($filename, $ttl, $bindToSession);
    }

    /**
     * Check if this asset requires signed URLs (is protected).
     *
     * A file is protected if:
     * 1. It has restricted CanViewType (LoggedInUsers, OnlyTheseUsers, or inherited)
     * 2. It's unpublished when using Versioned with staging
     *
     * Uses SilverStripe's built-in methods to avoid duplicating framework logic.
     *
     * @return bool True if file requires protection/signing
     */
    public function requiresSignedURL(): bool
    {
        /** @var File $file */
        $file = $this->getOwner();

        if (empty($file->getFilename())) {
            return false;
        }

        // 1. Check CanViewType restrictions (includes parent inheritance)
        if ($file->hasRestrictedAccess()) {
            return true;
        }

        // 2. Check Versioned staging - unpublished files are protected
        if ($file->hasExtension(Versioned::class) && !$file->isPublished()) {
            return true;
        }

        return false;
    }

    /**
     * Get URL using a named policy or explicit parameters.
     *
     * Template usage:
     *   $Image.AutoURL           ->  Uses default TTL and session binding
     *   $Image.AutoURL('ss') ->  30 seconds, session-bound
     *   $Image.AutoURL('m')      ->  1 hour, not session-bound
     *
     * @param string|int|null $policyOrTtl Policy name (string) or TTL in seconds (int)
     * @param bool|null $bindToSession Session binding (only used if $policyOrTtl is int/null)
     * @return string|null
     */
    public function getAutoURL(string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        $ttl = null;

        // If a policy name is provided, look it up
        if (is_string($policyOrTtl)) {
            $policies = $this->getOwner()->config()->get('policies');
            if (isset($policies[$policyOrTtl])) {
                $policy = $policies[$policyOrTtl];
                $ttl = $policy['ttl'] ?? null;
                $bindToSession = $policy['session'] ?? null;
            }
            // If policy not found, fall through with null ttl (uses defaults)
        } elseif (is_int($policyOrTtl)) {
            // Direct TTL value provided
            $ttl = $policyOrTtl;
        }

        // getSignedURL handles public/protected logic
        return $this->getSignedURL($ttl, $bindToSession);
    }
}
