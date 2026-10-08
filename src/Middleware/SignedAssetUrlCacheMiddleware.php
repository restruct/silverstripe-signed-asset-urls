<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Middleware;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injector;
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
            # No header yet: HTTPCacheControlMiddleware runs outside this one here (normally it
            # runs inside and has already written the header, handled below), so it writes the
            # header from its state after we return. Steer that state instead of writing a bare
            # "private", which would replace a stricter "no-cache, no-store" the page or core asked
            # for. Forced, so a forced publicCache() cannot win; a forced disableCache() still
            # does (higher level), and a page already in the disabled state is left disabled.
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
            # Issue #8: this middleware is registered after framework's, so it runs OUTSIDE
            # HTTPCacheControlMiddleware, which has already written the header from its state by
            # now. A no-store response (disableCache(), forms with a security token, the CMS, the
            # dev environment's default) is never stored, so there is nothing to cap; appending a
            # max-age (and a future Expires) to it only contradicted it.
            $directives = array_map(
                fn (string $directive) => strtolower(trim(explode('=', $directive, 2)[0])),
                explode(',', (string) $existingHeader)
            );
            if (in_array('no-store', $directives, true)) {
                return;
            }

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
            // $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
            # Without HTTPCacheControlMiddleware in Director's stack nobody else writes the header,
            # so the 1.2.1 default stays: there is no core state to respect.
            if (!$this->coreCacheControlInStack()) {
                $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
                $response->addHeader('Expires', gmdate('D, d M Y H:i:s', $earliestExpiry) . ' GMT');
                return;
            }
            # Issue #8: core is in the stack but has not written the header yet, ie it runs outside
            # this one (a project that reordered Director.Middlewares) and writes the header from
            # its state after we return - it only fills empty headers. Writing the header here
            # replaced that: a page core sends as "no-cache, no-store, must-revalidate"
            # (disableCache(), forms with a security token, the CMS, the dev environment's default)
            # became cacheable by the browser for $maxAge seconds. Steer core's state instead, as
            # forcePrivate() does, and leave a disabled state alone: a stricter state wins.
            $cacheControl = HTTPCacheControlMiddleware::singleton();
            if ($cacheControl->getState() !== HTTPCacheControlMiddleware::STATE_DISABLED) {
                # Read the max-age before changing state: a page that set a shorter one keeps it.
                $existingMaxAge = $cacheControl->getDirective('max-age');
                # Not forced, as the header used to say "private" without forcing anything: a
                # forced publicCache() or enableCache() keeps its state, with the max-age capped.
                $cacheControl->privateCache();
                if ($existingMaxAge === false || $existingMaxAge === null || (int) $existingMaxAge > $maxAge) {
                    $cacheControl->setMaxAge($maxAge);
                }
            }
            # Core writes Expires itself from the max-age it ends up with; one written here would
            # contradict a disabled state's no-store.
            return;
        }

        // Also set Expires header for older caches
        $response->addHeader('Expires', gmdate('D, d M Y H:i:s', $earliestExpiry) . ' GMT');
    }

    /**
     * Whether Director's middleware stack has core's HTTPCacheControlMiddleware, which writes the
     * Cache-Control header from its state.
     */
    protected function coreCacheControlInStack(): bool
    {
        foreach (Injector::inst()->get(Director::class)->getMiddlewares() as $middleware) {
            if ($middleware instanceof HTTPCacheControlMiddleware) {
                return true;
            }
        }
        return false;
    }
}
