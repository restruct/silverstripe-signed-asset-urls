<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Services;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Restruct\SilverStripe\SignedAssetUrls\Middleware\SignedAssetUrlCacheMiddleware;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPCacheControlMiddleware;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;

/**
 * Regression tests for issue #6: "Session-bound signed URLs are not bound for visitors without a
 * PHP session".
 *
 * Without a session the session token was '' when the URL was signed, and '' again when any other
 * browser without a session requested it, so the "bound" URL validated for anyone. A session-bound
 * URL must now never validate without a session, the generator must start a session for the
 * visitor where it can, say so where it cannot, and the page carrying such a URL must not be
 * cacheable by a shared cache.
 */
class SessionBoundUrlWithoutSessionTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const SECRET = 'test-signing-secret-0123456789';

    /** @var array<int, array{0: string, 1: string}> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        AssetUrlSigningService::resetExpiryTracker();

        $this->records = [];
        $records = &$this->records;
        $logger = new class ($records) extends AbstractLogger {
            private array $records;

            public function __construct(array &$records)
            {
                $this->records = &$records;
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };
        Injector::inst()->registerService($logger, LoggerInterface::class);
    }

    protected function tearDown(): void
    {
        # The service keeps per-request state in statics; never let it leak into the next test.
        AssetUrlSigningService::resetExpiryTracker();
        if (Injector::inst()->has(HTTPRequest::class)) {
            Injector::inst()->unregisterNamedObject(HTTPRequest::class);
        }
        parent::tearDown();
    }

    /** @return string[] messages logged at warning level */
    private function warnings(): array
    {
        return array_values(array_map(
            fn (array $record) => $record[1],
            array_filter($this->records, fn (array $record) => $record[0] === 'warning')
        ));
    }

    /** Parse the s/e/ss params and the (decoded) path out of a generated URL. */
    private function parse(string $url): array
    {
        $qpos = strpos($url, '?');
        parse_str(substr($url, $qpos + 1), $params);
        $path = rawurldecode(preg_replace('#^/signed-asset/#', '', substr($url, 0, $qpos)));
        return [$path, $params];
    }

    /** A service with no session at all: fakeSessionToken '' stands in for session_id() === ''. */
    private function sessionless(): NoSessionSigningService
    {
        $svc = new NoSessionSigningService();
        $svc->fakeSessionToken = '';
        return $svc;
    }

    public function testSessionBoundUrlIssuedWithoutSessionIsRefusedToAnotherVisitorWithoutSession(): void
    {
        # The exact case of issue #6: signed without a session, requested by someone else who also
        # has none. Both sides compute the same empty token, which used to validate.
        $url = $this->sessionless()->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);
        $this->assertSame('1', $p['ss'] ?? null, 'the URL still says it is session-bound');

        $this->assertSame(
            'invalid_signature',
            $this->sessionless()->validateSignature($p['s'], (int) $p['e'], $path, true),
            'a session-bound URL must never validate for a request without a session'
        );
    }

    public function testSessionBoundUrlIssuedWithoutSessionIsRefusedToAnySession(): void
    {
        $url = $this->sessionless()->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);

        $other = new NoSessionSigningService();
        $other->fakeSessionToken = 'session-B';
        $this->assertSame('invalid_signature', $other->validateSignature($p['s'], (int) $p['e'], $path, true));
    }

    public function testSessionBoundUrlFromARealSessionIsRefusedWithoutSession(): void
    {
        $issuer = new NoSessionSigningService();
        $issuer->fakeSessionToken = 'session-A';
        $url = $issuer->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);

        $this->assertSame('invalid_signature', $this->sessionless()->validateSignature($p['s'], (int) $p['e'], $path, true));
        # Control: it does validate in the session that got it, so the refusal above is the binding.
        $this->assertTrue($issuer->validateSignature($p['s'], (int) $p['e'], $path, true));
    }

    public function testUnboundUrlStillValidatesWithoutSession(): void
    {
        # Only session-bound URLs need a session; a plain signed URL is unaffected.
        $url = $this->sessionless()->generateSignedURL('Uploads/a/doc.pdf', 300, false);
        [$path, $p] = $this->parse($url);
        $this->assertTrue($this->sessionless()->validateSignature($p['s'], (int) $p['e'], $path, false));
        $this->assertSame([], $this->warnings(), 'no warning for a URL that is not session-bound');
    }

    public function testWarnsOncePerRequestWhenNoSessionCanBeStarted(): void
    {
        # No request to start a session on (CLI, queued job): the URL cannot be bound, so it is
        # issued dead and the reason is logged - once, not once per URL on the page.
        $svc = $this->sessionless();
        $svc->generateSignedURL('Uploads/a/one.pdf', 300, true);
        $svc->generateSignedURL('Uploads/a/two.pdf', 300, true);

        $warnings = $this->warnings();
        $this->assertCount(1, $warnings, 'one warning per request');
        $this->assertStringContainsString('session-bound', $warnings[0]);
    }

    public function testUnbindableUrlStaysDeadWithItsSessionFlagStripped(): void
    {
        # Signed where no session exists (CLI, queued job). With an empty token its hash equalled
        # the unbound hash, so dropping &ss=1 made it a URL that works for everyone.
        $url = $this->sessionless()->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);

        $withSession = new NoSessionSigningService();
        $withSession->fakeSessionToken = 'session-B';
        foreach (['no session' => $this->sessionless(), 'a session' => $withSession] as $who => $svc) {
            $this->assertSame(
                'invalid_signature',
                $svc->validateSignature($p['s'], (int) $p['e'], $path, false),
                "ss flag stripped, requested with $who"
            );
            $this->assertSame(
                'invalid_signature',
                $svc->validateSignature($p['s'], (int) $p['e'], $path, true),
                "ss flag kept, requested with $who"
            );
        }
    }

    public function testStartsTheVisitorsSessionWhenItCan(): void
    {
        # A web request whose visitor has no session yet: generating a session-bound URL starts the
        # session and stores a value in it, so Silverstripe keeps the session (and its cookie) and
        # treats the response as private.
        $request = new HTTPRequest('GET', '/page');
        $session = new Session(null);
        $request->setSession($session);
        Injector::inst()->registerService($request, HTTPRequest::class);
        $this->assertFalse($session->isStarted(), 'precondition: no session yet');

        $svc = $this->sessionless();
        $svc->allowSessionStart = true;
        $svc->generateSignedURL('Uploads/a/doc.pdf', 300, true);

        $this->assertTrue($session->isStarted(), 'the session is started for the visitor');
        $this->assertNotEmpty($session->getAll(), 'and holds a value, so it is kept and the page is private');
    }

    public function testDoesNotStartASessionForAnUnboundUrl(): void
    {
        $request = new HTTPRequest('GET', '/page');
        $session = new Session(null);
        $request->setSession($session);
        Injector::inst()->registerService($request, HTTPRequest::class);

        $svc = $this->sessionless();
        $svc->allowSessionStart = true;
        $svc->generateSignedURL('Uploads/a/doc.pdf', 300, false);

        $this->assertFalse($session->isStarted(), 'a plain signed URL costs no session');
    }

    public function testPageWithSessionBoundUrlIsNeverPubliclyCacheable(): void
    {
        # A shared cache that stores a page with a session-bound URL hands one visitor's URL (and,
        # once the session is started here, possibly their session cookie) to the next visitor.
        $svc = new NoSessionSigningService();
        $svc->fakeSessionToken = 'session-A';
        $response = (new SignedAssetUrlCacheMiddleware())->process(
            new HTTPRequest('GET', '/'),
            function () use ($svc) {
                $svc->generateSignedURL('a/b.png', 3600, true);
                return HTTPResponse::create('body')->addHeader('Cache-Control', 'public, max-age=86400');
            }
        );
        $header = (string) $response->getHeader('Cache-Control');
        $this->assertStringNotContainsString('public', $header);
        $this->assertStringContainsString('private', $header);
    }

    public function testPageWithOnlyUnboundUrlsKeepsItsPublicCaching(): void
    {
        # Control for the test above: plain signed URLs keep the existing behaviour (max-age capped,
        # cacheability left to the page).
        $svc = new NoSessionSigningService();
        $response = (new SignedAssetUrlCacheMiddleware())->process(
            new HTTPRequest('GET', '/'),
            function () use ($svc) {
                $svc->generateSignedURL('a/b.png', 3600, false);
                return HTTPResponse::create('body')->addHeader('Cache-Control', 'public, max-age=86400');
            }
        );
        $this->assertStringContainsString('public', (string) $response->getHeader('Cache-Control'));
    }

    /**
     * Run the module middleware inside Silverstripe's own cache middleware state, the way they nest
     * in a real request, and return the final Cache-Control.
     *
     * @param callable $setCoreState Receives a fresh HTTPCacheControlMiddleware to put in a state
     */
    private function finalCacheControl(bool $autoCacheHeaders, callable $setCoreState): string
    {
        Config::modify()->set(AssetUrlSigningService::class, 'auto_cache_headers', $autoCacheHeaders);
        # Live defaults: the dev environment (the test host) forces the disabled state at level 3,
        # which would hide every case below behind no-store.
        Config::modify()->set(HTTPCacheControlMiddleware::class, 'defaultState', HTTPCacheControlMiddleware::STATE_ENABLED);
        Config::modify()->set(HTTPCacheControlMiddleware::class, 'defaultForcingLevel', 0);
        $core = new HTTPCacheControlMiddleware();
        Injector::inst()->registerService($core, HTTPCacheControlMiddleware::class);
        $setCoreState($core);

        $svc = new NoSessionSigningService();
        $svc->fakeSessionToken = 'session-A';
        $response = (new SignedAssetUrlCacheMiddleware())->process(
            new HTTPRequest('GET', '/'),
            function () use ($svc) {
                $svc->generateSignedURL('a/b.png', 3600, true);
                return HTTPResponse::create('body');
            }
        );
        # What HTTPCacheControlMiddleware does after the inner middlewares return.
        $core->applyToResponse($response);
        return (string) $response->getHeader('Cache-Control');
    }

    public function testCacheMatrixWithoutAutoCacheHeadersEnabledCacheBecomesPrivate(): void
    {
        $header = $this->finalCacheControl(false, function (HTTPCacheControlMiddleware $core) {
            $core->enableCache(false, 600);
        });
        $this->assertStringContainsString('private', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function testCacheMatrixWithoutAutoCacheHeadersDefaultStateBecomesPrivate(): void
    {
        $header = $this->finalCacheControl(false, function (HTTPCacheControlMiddleware $core) {
        });
        $this->assertStringContainsString('private', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function testCacheMatrixWithoutAutoCacheHeadersForcedPublicBecomesPrivate(): void
    {
        $header = $this->finalCacheControl(false, function (HTTPCacheControlMiddleware $core) {
            $core->publicCache(true, 600);
        });
        $this->assertStringContainsString('private', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function testCacheMatrixWithoutAutoCacheHeadersKeepsDisabledCache(): void
    {
        # A bare "private" written by the module used to replace core's stricter no-store.
        $header = $this->finalCacheControl(false, function (HTTPCacheControlMiddleware $core) {
            $core->disableCache();
        });
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function testCacheMatrixWithoutAutoCacheHeadersKeepsForcedDisabledCache(): void
    {
        $header = $this->finalCacheControl(false, function (HTTPCacheControlMiddleware $core) {
            $core->disableCache(true);
        });
        $this->assertStringContainsString('no-store', $header);
        $this->assertStringNotContainsString('public', $header);
    }

    public function testCacheMatrixWithAutoCacheHeadersIsPrivate(): void
    {
        foreach ([
            'default' => function (HTTPCacheControlMiddleware $core) {
            },
            'forced public' => function (HTTPCacheControlMiddleware $core) {
                $core->publicCache(true, 600);
            },
            'disabled' => function (HTTPCacheControlMiddleware $core) {
                $core->disableCache();
            },
        ] as $state => $setCoreState) {
            AssetUrlSigningService::resetExpiryTracker();
            $header = $this->finalCacheControl(true, $setCoreState);
            $this->assertStringContainsString('private', $header, $state);
            $this->assertStringNotContainsString('public', $header, $state);
        }
    }
}

/**
 * Lets a test decide the session token and whether a session may be started (the real service
 * never starts one on the CLI, which is where the tests run).
 */
class NoSessionSigningService extends AssetUrlSigningService implements TestOnly
{
    public ?string $fakeSessionToken = null;

    public bool $allowSessionStart = false;

    protected function getSessionToken(): string
    {
        return $this->fakeSessionToken !== null ? $this->fakeSessionToken : parent::getSessionToken();
    }

    protected function canStartSession(): bool
    {
        return $this->allowSessionStart;
    }
}
