<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Tasks\SignedAssetUrlVerifyTaskExitCodeTest;

use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Dev\TestOnly;

/**
 * The real signing service with exactly one of the verify task's checks made to fail.
 *
 * Registered in place of AssetUrlSigningService by SignedAssetUrlVerifyTaskExitCodeTest, so each
 * of the task's failure paths (not only the missing secret) is shown to reach its result.
 */
class BreakableSigningService extends AssetUrlSigningService implements TestOnly
{
    public const BREAK_VALID_SIGNATURE = 'valid_signature';
    public const BREAK_WRONG_HASH = 'wrong_hash';
    public const BREAK_EXPIRED = 'expired';
    public const BREAK_SESSION_FLAG = 'session_flag';

    /**
     * Which check to break (one of the BREAK_* constants); '' breaks nothing.
     */
    public string $break = '';

    public function validateSignature(string $hash, int $expires, string $path, bool $sessionBound = false)
    {
        $result = parent::validateSignature($hash, $expires, $path, $sessionBound);

        // A correct signature is rejected: the task's "Valid signature" check fails.
        if ($this->break === self::BREAK_VALID_SIGNATURE && $result === true) {
            return 'invalid_signature';
        }
        // A forged hash is accepted: the task's "Wrong hash" check fails.
        if ($this->break === self::BREAK_WRONG_HASH && $result === 'invalid_signature') {
            return true;
        }
        // An expired URL is accepted: the task's "Expired URL" check fails.
        if ($this->break === self::BREAK_EXPIRED && $result === 'expired') {
            return true;
        }

        return $result;
    }

    public function generateSignedURL(string $path, ?int $ttl = null, ?bool $bindToSession = null): string
    {
        $url = parent::generateSignedURL($path, $ttl, $bindToSession);

        // A session-bound URL comes back without its ss=1 flag: the task's session check fails.
        if ($this->break === self::BREAK_SESSION_FLAG) {
            $url = str_replace('&ss=1', '', $url);
        }

        return $url;
    }
}
