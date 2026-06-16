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
     * Get a signed URL for this asset (original or variant)
     *
     * @param int|null $ttl Time-to-live in seconds (null = use default)
     * @param bool|null $bindToSession Bind to current session (null = use default)
     * @return string|null Signed URL, normal URL for public files, or null if not found
     */
    public function SignedURL(?int $ttl = null, ?bool $bindToSession = null): ?string
    {
        /** @var DBFile $dbFile */
        $dbFile = $this->getOwner();

        if (!$dbFile->exists()) {
            // Fallback: check if we have filename and hash even if exists() failed
            // (exists() can return false for protected files that actually exist on disk)
            if (empty($dbFile->getFilename()) || empty($dbFile->getHash())) {
                return null;
            }
            // File data exists, continue even though exists() returned false
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
    public function AutoURL(string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        [$ttl, $bindToSession] = $this->resolvePolicy($policyOrTtl, $bindToSession);
        return $this->SignedURL($ttl, $bindToSession);
    }

    /**
     * Resolve a policy name (or raw TTL) to [ttl, bindToSession].
     */
    protected function resolvePolicy(string|int|null $policyOrTtl, ?bool $bindToSession): array
    {
        $ttl = null;
        if (is_string($policyOrTtl)) {
            $policies = AssetUrlSigningService::config()->get('policies');
            if (isset($policies[$policyOrTtl])) {
                $ttl = $policies[$policyOrTtl]['ttl'] ?? null;
                $bindToSession = $policies[$policyOrTtl]['session'] ?? null;
            }
        } elseif (is_int($policyOrTtl)) {
            $ttl = $policyOrTtl;
        }
        return [$ttl, $bindToSession];
    }

    /**
     * Signed URL with the filename masked as the File's id (hex).
     *
     * Identical to AutoURL except the filename stem in the URL path is
     * replaced with "x{zero-padded-hex-id}" (e.g. "x0000002a") — the
     * content-hash prefix and variant token are kept, so the correct file and
     * size still resolve, but a (potentially answer-revealing) filename is no
     * longer exposed. Used for question option-images.
     *
     * Always produces a signed (controller-served) URL, since masking requires
     * routing through SignedAssetUrlController which decodes the id.
     *
     * @param int $fileID The File record id to encode (the caller knows it;
     *                     a DBFile variant doesn't carry the id itself).
     */
    public function MaskedURL(int $fileID, string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        /** @var DBFile $dbFile */
        $dbFile = $this->getOwner();
        if (empty($dbFile->getFilename()) || empty($dbFile->getHash()) || $fileID <= 0) {
            return null;
        }

        [$ttl, $bindToSession] = $this->resolvePolicy($policyOrTtl, $bindToSession);

        $path = $this->buildAssetPath();
        if (!$path) {
            return null;
        }

        // Rebuild the last segment as a canonical "x{idhex}__{variant}.{ext}"
        // dropping ALL of the original filename (incl. multi-dot fragments like
        // a "...17.28.11.png" timestamp). SS injects the variant after the
        // FIRST dot of the name, so the real segment looks like
        // "stem__Variant.suffix.ext"; we keep only the variant token + the
        // final extension. The controller reconstructs the real on-disk name
        // from the File record (see reconstructRealVariantPath).
        $segments = explode('/', $path);
        $last = array_pop($segments);
        $token = 'x' . sprintf('%08x', $fileID);
        $ext = pathinfo($last, PATHINFO_EXTENSION);
        $sep = strpos($last, '__');
        if ($sep !== false) {
            $afterSep = substr($last, $sep + 2);            // "Variant.suffix.ext"
            $dot = strpos($afterSep, '.');
            $variantToken = $dot !== false ? substr($afterSep, 0, $dot) : $afterSep;
            $last = $token . '__' . $variantToken . ($ext !== '' ? '.' . $ext : '');
        } else {
            $last = $token . ($ext !== '' ? '.' . $ext : '');
        }
        $segments[] = $last;
        $maskedPath = implode('/', $segments);

        /** @var AssetUrlSigningService $signingService */
        $signingService = Injector::inst()->get(AssetUrlSigningService::class);
        return $signingService->generateSignedURL($maskedPath, $ttl, $bindToSession);
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
