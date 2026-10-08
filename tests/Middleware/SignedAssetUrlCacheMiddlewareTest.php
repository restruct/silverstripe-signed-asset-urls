<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Middleware;

use Restruct\SilverStripe\SignedAssetUrls\Middleware\SignedAssetUrlCacheMiddleware;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The middleware must cap a page's Cache-Control to the shortest-lived signed
 * URL it generated, so a cached page never outlives (and keeps serving) an
 * already-expired signed URL.
 */
class SignedAssetUrlCacheMiddlewareTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', 'test-signing-secret-0123456789');
    }

    private function runMw(callable $delegate): HTTPResponse
    {
        return (new SignedAssetUrlCacheMiddleware())->process(new HTTPRequest('GET', '/'), $delegate);
    }

    private function maxAge(HTTPResponse $r): ?int
    {
        if (preg_match('/max-age=(\d+)/', (string) $r->getHeader('Cache-Control'), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function svc(): AssetUrlSigningService
    {
        return Injector::inst()->get(AssetUrlSigningService::class);
    }

    public function testCapsMaxAgeToSignedUrlTtl(): void
    {
        $resp = $this->runMw(function () {
            $this->svc()->generateSignedURL('a/b.png', 60); // tracks expiry now+60
            $r = HTTPResponse::create('body');
            $r->addHeader('Cache-Control', 'public, max-age=86400');
            return $r;
        });
        $this->assertLessThanOrEqual(60, $this->maxAge($resp), 'long max-age capped to the 60s signed URL');
    }

    public function testNeverIncreasesAShorterMaxAge(): void
    {
        $resp = $this->runMw(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            $r = HTTPResponse::create('body');
            $r->addHeader('Cache-Control', 'public, max-age=10');
            return $r;
        });
        $this->assertSame(10, $this->maxAge($resp), 'a shorter existing max-age is left alone');
    }

    public function testNoSignedUrlLeavesResponseUntouched(): void
    {
        $resp = $this->runMw(function () {
            $r = HTTPResponse::create('body');
            $r->addHeader('Cache-Control', 'public, max-age=86400');
            return $r;
        });
        $this->assertSame(86400, $this->maxAge($resp), 'no signed URL generated -> headers unchanged');
    }

    public function testSetsDefaultWhenNoCacheControl(): void
    {
        $resp = $this->runMw(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            return HTTPResponse::create('body');
        });
        $this->assertNotNull($this->maxAge($resp), 'a default Cache-Control is set');
        $this->assertLessThanOrEqual(60, $this->maxAge($resp));
    }

    /**
     * Issue #8, as a real request meets it: this middleware is registered after framework's, so it
     * runs outside HTTPCacheControlMiddleware and finds the header core already wrote.
     */
    public function testLeavesANoStoreHeaderAlone(): void
    {
        $resp = $this->runMw(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            $r = HTTPResponse::create('body');
            $r->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            return $r;
        });
        $this->assertSame('no-cache, no-store, must-revalidate', $resp->getHeader('Cache-Control'));
        $this->assertNull($resp->getHeader('Expires'), 'no Expires in the future on a no-store page');
    }

    /**
     * Issue #8, when HTTPCacheControlMiddleware runs outside this middleware (a project that
     * reordered Director.Middlewares): core then only fills a Cache-Control header that is still
     * empty. Run this middleware, then apply core's state the way core does after it, with core in
     * the given default state (a fresh singleton per call).
     */
    private function runWithCore(callable $delegate, string $defaultState, int $defaultForcingLevel = 0): HTTPResponse
    {
        Config::modify()->set(HTTPCacheControlMiddleware::class, 'defaultState', $defaultState);
        Config::modify()->set(HTTPCacheControlMiddleware::class, 'defaultForcingLevel', $defaultForcingLevel);
        HTTPCacheControlMiddleware::reset();

        $response = $this->runMw($delegate);
        HTTPCacheControlMiddleware::singleton()->applyToResponse($response);

        return $response;
    }

    public function testKeepsNoStoreOfAPageThatDisabledCaching(): void
    {
        # Live defaults (state enabled, no forcing); the page calls disableCache() itself, as a
        # form with a security token does.
        $resp = $this->runWithCore(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            HTTPCacheControlMiddleware::singleton()->disableCache();
            return HTTPResponse::create('body');
        }, HTTPCacheControlMiddleware::STATE_ENABLED);

        $header = (string) $resp->getHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $header, 'a page that asked for no-store keeps it');
        $this->assertNull($this->maxAge($resp), 'and is not made cacheable for the signed URL\'s lifetime');
        $this->assertNull($resp->getHeader('Expires'), 'nor given an Expires in the future');
    }

    public function testKeepsNoStoreOfTheDevEnvironmentDefault(): void
    {
        # Framework's dev-environment config: disabled, at forcing level 3.
        $resp = $this->runWithCore(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            return HTTPResponse::create('body');
        }, HTTPCacheControlMiddleware::STATE_DISABLED, 3);

        $this->assertStringContainsString('no-store', (string) $resp->getHeader('Cache-Control'));
        $this->assertNull($this->maxAge($resp));
    }

    public function testCapsTheMaxAgeOfACacheablePageThroughCoresState(): void
    {
        # A page that asked to be public for a day: private now (as before #8), and no longer
        # than the signed URL lives.
        $resp = $this->runWithCore(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            HTTPCacheControlMiddleware::singleton()->publicCache(false, 86400);
            return HTTPResponse::create('body');
        }, HTTPCacheControlMiddleware::STATE_ENABLED);

        $header = (string) $resp->getHeader('Cache-Control');
        $this->assertStringContainsString('private', $header);
        $this->assertStringNotContainsString('public', $header);
        $this->assertLessThanOrEqual(60, $this->maxAge($resp));
        $this->assertGreaterThanOrEqual(55, $this->maxAge($resp));
        $this->assertNotNull($resp->getHeader('Expires'), 'core writes Expires from the capped max-age');
    }

    public function testNeverIncreasesAShorterMaxAgeInCoresState(): void
    {
        $resp = $this->runWithCore(function () {
            $this->svc()->generateSignedURL('a/b.png', 60);
            HTTPCacheControlMiddleware::singleton()->publicCache(false, 10);
            return HTTPResponse::create('body');
        }, HTTPCacheControlMiddleware::STATE_ENABLED);

        $this->assertSame(10, $this->maxAge($resp), 'a shorter max-age in core\'s state is left alone');
    }
}
