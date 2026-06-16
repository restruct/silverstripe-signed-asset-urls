<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Extensions;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;

/**
 * Thin wrapper extension for File that delegates to DBFile's signed URL methods.
 *
 * The actual implementation is on DBFile (SignedUrlDBFileExtension).
 * This extension just provides the same methods on File for convenience.
 */
class SignedUrlFileExtension extends Extension
{
    /**
     * @param int|null $ttl
     * @param bool|null $bindToSession
     * @return string|null
     */
    public function SignedURL(?int $ttl = null, ?bool $bindToSession = null): ?string
    {
        /** @var File $file */
        $file = $this->getOwner();
        return $file->File->SignedURL($ttl, $bindToSession);
    }

    /**
     * @return bool
     */
    public function requiresSignedURL(): bool
    {
        /** @var File $file */
        $file = $this->getOwner();
        return $file->File->requiresSignedURL();
    }

    /**
     * @param string|int|null $policyOrTtl
     * @param bool|null $bindToSession
     * @return string|null
     */
    public function AutoURL(string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        /** @var File $file */
        $file = $this->getOwner();
        return $file->File->AutoURL($policyOrTtl, $bindToSession);
    }

    /**
     * Scale-to-width variant with a filename-masked signed URL — the URL
     * carries the File id (hex) instead of the (potentially answer-revealing)
     * filename. For question option-images.
     *
     * Convenience that lives on File because it has the id ($this->ID) that a
     * bare DBFile variant doesn't; it does the ScaleWidth() manipulation here
     * so callers get a single template-friendly call:
     *   $OptionImage.MaskedScaleWidthURL(180, 'ss')
     *
     * @param int $width Target width in px (ScaleWidth)
     */
    public function MaskedScaleWidthURL(int $width, string|int|null $policyOrTtl = null, ?bool $bindToSession = null): ?string
    {
        /** @var File $file */
        $file = $this->getOwner();
        if (!$file || !$file->exists() || !$file->ID) {
            return null;
        }
        $variant = $file->ScaleWidth($width); // DBFile
        return $variant->MaskedURL((int) $file->ID, $policyOrTtl, $bindToSession);
    }
}
