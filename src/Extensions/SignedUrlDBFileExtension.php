<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;

/**
 * Extension for DBFile to add signed URL generation.
 *
 * Applied to DBFile, this works for:
 * - File objects (File::getURL() delegates to $this->File->getURL())
 * - Image variants from Fill(), ScaleWidth(), etc. (return DBFile directly)
 *
 * Template usage:
 *   $Image.AutoURL              ->  Signed URL for original
 *   $Image.AutoURL('ss')        ->  30 seconds, session-bound
 *   $Image.Fill(400,225).AutoURL('ss')  ->  Signed URL for variant
 */
class SignedUrlDBFileExtension extends Extension
{
    /**
     * Named policies for signed URL generation.
     *
     * @config
     * @var array<string, array{ttl: int, session: bool}>
     */
    private static array $policies = [
        'ss' => ['ttl' => 30, 'session' => true],       // Short, session-bound (30s)
        's' => ['ttl' => 30, 'session' => false],       // Short, not session-bound
        'ms' => ['ttl' => 3600, 'session' => true],     // Medium, session-bound (1h)
        'm' => ['ttl' => 3600, 'session' => false],     // Medium, not session-bound
        'ls' => ['ttl' => 86400, 'session' => true],    // Long, session-bound (24h)
        'l' => ['ttl' => 86400, 'session' => false],    // Long, not session-bound
    ];

    /**
     * Get a signed URL for this asset (original or variant)
     *
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @param bool|null $bindToSession Bind to current session (null = use default)
     * @return string|null Signed URL, normal URL for public files, or null if not found
     */
    public function getSignedURL(?int $ttl = null, ?bool $bindToSession = null): ?string
    {
        /** @var DBFile $dbFile */
        $dbFile = $this->getOwner();

        if (!$dbFile->exists()) {
            return null;
        }

        // Check if file requires signed URLs (is protected)
        if (!$this->requiresSignedURL()) {
            // Public file - return normal URL
            return $dbFile->getURL();
        }

        // Build the path for signing (handles both originals and variants)
        $path = $this->buildAssetPath();
        if (!$path) {
            return null;
        }

        /** @var AssetUrlSigningService $signingService */
        $signingService = Injector::inst()->get(AssetUrlSigningService::class);

        return $signingService->generateSignedURL($path, $ttl, $bindToSession);
    }

    /**
     * Check if this asset requires signed URLs (is protected).
     *
     * Looks up the File record and checks its protection status.
     *
     * @return bool True if file requires signed URL
     */
    public function requiresSignedURL(): bool
    {
        /** @var DBFile $dbFile */
        $dbFile = $this->getOwner();

        $filename = $dbFile->getFilename();
        if (empty($filename)) {
            return false;
        }

        // Find the File record
        $file = File::get()->filter('FileFilename', $filename)->first();

        if (!$file) {
            // No File record - assume protected to be safe
            return true;
        }

        // Check CanViewType restrictions (includes parent folder inheritance)
        if ($file->hasRestrictedAccess()) {
            return true;
        }

        // Check Versioned staging - unpublished files are protected
        if ($file->hasExtension(Versioned::class) && !$file->isPublished()) {
            return true;
        }

        return false;
    }

    /**
     * Get URL using a named policy or explicit parameters.
     *
     * Template usage:
     *   $Image.AutoURL              ->  Uses default TTL
     *   $Image.AutoURL('ss')        ->  30 seconds, session-bound
     *   $Image.AutoURL('m')         ->  1 hour, not session-bound
     *   $Image.Fill(400,225).AutoURL('ss')  ->  Variant with policy
     *
     * @param string|int|null $policyOrTtl Policy name (string) or TTL in seconds (int)
     * @param bool|null $bindToSession Session binding (only used if $policyOrTtl is int/null)
     * @return string|null
     */
    public function getAutoURL(string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        $ttl = null;

        if (is_string($policyOrTtl)) {
            $policies = $this->getOwner()->config()->get('policies');
            if (isset($policies[$policyOrTtl])) {
                $policy = $policies[$policyOrTtl];
                $ttl = $policy['ttl'] ?? null;
                $bindToSession = $policy['session'] ?? null;
            }
        } elseif (is_int($policyOrTtl)) {
            $ttl = $policyOrTtl;
        }

        return $this->getSignedURL($ttl, $bindToSession);
    }

    /**
     * Build the asset path for signing.
     *
     * For variants: extracts hash/filename__variant.ext from getSourceURL()
     * For originals: returns FileFilename
     *
     * @return string|null The path to sign, or null if cannot be built
     */
    protected function buildAssetPath(): ?string
    {
        /** @var DBFile $dbFile */
        $dbFile = $this->getOwner();

        $filename = $dbFile->getFilename();
        $hash = $dbFile->getHash();
        $variant = $dbFile->getVariant();

        if (empty($filename)) {
            return null;
        }

        // For variants: let framework build URL, extract from hash prefix onwards
        if ($variant && $hash) {
            $sourceUrl = $dbFile->getSourceURL(false);
            if ($sourceUrl) {
                $hashPrefix = substr($hash, 0, 10);
                $pos = strpos($sourceUrl, '/' . $hashPrefix . '/');
                if ($pos !== false) {
                    return substr($sourceUrl, $pos + 1); // +1 to skip leading /
                }
            }
        }

        // For originals: use FileFilename
        return $filename;
    }
}
