<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Stub;

use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Test-only implementation of core's FlysystemAssetStore `updateResponse` hook: adds a marker
 * header to every response and records the arguments it was called with, so a test can see that a
 * signed response went through the hook (issue #14).
 *
 * @extends Extension<FlysystemAssetStore>
 */
class AssetStoreResponseHeaderExtension extends Extension implements TestOnly
{
    public const HEADER = 'X-Test-Update-Response';

    /**
     * Arguments of each call since the last reset: [asset, context]
     *
     * @var array<int, array{0: string, 1: array}>
     */
    public static array $calls = [];

    /**
     * @param HTTPResponse $response
     * @param string $asset
     * @param array $context
     */
    public function updateResponse($response, $asset, $context = []): void
    {
        static::$calls[] = [(string) $asset, (array) $context];
        $response->addHeader(static::HEADER, 'applied');
    }
}
