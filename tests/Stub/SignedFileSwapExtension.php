<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Stub;

use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test-only implementation of the updateSignedURLFile hook: hands back whatever the test put in
 * $swap (a callable that receives the current DBFile and returns the replacement).
 *
 * @extends Extension<DBFile>
 */
class SignedFileSwapExtension extends Extension implements TestOnly
{
    /**
     * Set per test, reset in tearDown(): a static that outlives its test would swap files in
     * every later test of the run.
     *
     * @var callable|null
     */
    public static $swap = null;

    /**
     * Number of times the hook ran since the last reset
     */
    public static int $calls = 0;

    /**
     * @param DBFile $file
     */
    public function updateSignedURLFile(&$file): void
    {
        static::$calls++;
        if (is_callable(static::$swap)) {
            $file = call_user_func(static::$swap, $file);
        }
    }
}
