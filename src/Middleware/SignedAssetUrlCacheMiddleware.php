<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Middleware;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;

/**
 * Middleware to automatically adjust Cache-Control headers based on signed URLs generated during the request.
 *
 * When signed URLs are generated, this middleware ensures the page response
 * won't be cached longer than the shortest-lived signed URL it contains.
 */
class SignedAssetUrlCacheMiddleware implements HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate): HTTPResponse
    {
        // Reset tracker at start of request
        AssetUrlSigningService::resetExpiryTracker();

        /** @var HTTPResponse $response */
        $response = $delegate($request);

        // Check if any signed URLs were generated during this request
        $earliestExpiry = AssetUrlSigningService::getEarliestExpiry();

        if ($earliestExpiry !== null && $response) {
            $this->adjustCacheHeaders($response, $earliestExpiry);
        }

        # A session-bound URL belongs to one visitor, and generating it may have started that
        # visitor's session (Set-Cookie). A shared cache must never store such a page, whatever the
        # page itself asked for (issue #6).
        if ($response && AssetUrlSigningService::sessionBoundUrlIssued()) {
            $this->forcePrivate($response);
        }

        return $response;
    }

    /**
     * Make the response uncacheable by shared caches (CDNs, proxies): "private" instead of "public".
     */
    protected function forcePrivate(HTTPResponse $response): void
    {
        $header = (string) $response->getHeader('Cache-Control');
        if ($header === '') {
            # No header yet: HTTPCacheControlMiddleware (outside this one) writes it from its state
            # after we return. Steer that state instead of writing a bare "private", which would
            # replace a stricter "no-cache, no-store" the page or core asked for. Forced, so a
            # forced publicCache() cannot win; a forced disableCache() still does (higher level),
            # and a page already in the disabled state is left disabled.
            $cacheControl = HTTPCacheControlMiddleware::singleton();
            if ($cacheControl->getState() !== HTTPCacheControlMiddleware::STATE_DISABLED) {
                $cacheControl->privateCache(true);
            }
            return;
        }

        $directives = array_filter(array_map('trim', explode(',', $header)), function (string $directive) {
            # "public" and "s-maxage" are the shared-cache permissions; drop both.
            $name = strtolower(strtok($directive, '='));
            return $directive !== '' && $name !== 'public' && $name !== 's-maxage';
        });
        $hasPrivate = (bool) array_filter($directives, fn (string $d) => strtolower($d) === 'private');
        if (!$hasPrivate) {
            array_unshift($directives, 'private');
        }
        $response->addHeader('Cache-Control', implode(', ', $directives));
    }

    /**
     * Adjust response cache headers to not exceed signed URL expiry
     */
    protected function adjustCacheHeaders(HTTPResponse $response, int $earliestExpiry): void
    {
        $maxAge = max(0, $earliestExpiry - time());

        // Get existing Cache-Control header
        $existingHeader = $response->getHeader('Cache-Control');

        if ($existingHeader) {
            // Parse existing max-age if present
            if (preg_match('/max-age=(\d+)/', $existingHeader, $matches)) {
                $existingMaxAge = (int) $matches[1];
                // Only reduce, never increase
                if ($existingMaxAge > $maxAge) {
                    $newHeader = preg_replace('/max-age=\d+/', "max-age={$maxAge}", $existingHeader);
                    $response->addHeader('Cache-Control', $newHeader);
                }
            } else {
                // No max-age in existing header, append it
                $response->addHeader('Cache-Control', $existingHeader . ", max-age={$maxAge}");
            }
        } else {
            // No existing Cache-Control, set a sensible default
            $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
        }

        // Also set Expires header for older caches
        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', $earliestExpiry) . ' GMT');
    }
}
