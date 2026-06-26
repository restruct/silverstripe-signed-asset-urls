<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Middleware;

use Restruct\SilverStripe\SignedAssetUrls\Middleware\SignedAssetUrlCacheMiddleware;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
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
}
