<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Tests\Services;

use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * The signing service is the security boundary: if a URL can be forged,
 * tampered, or replayed past expiry, protected files leak. These tests lock
 * down the generate/validate round-trip, tamper rejection, expiry, session
 * binding, the secret requirement, and admin bypass.
 */
class AssetUrlSigningServiceTest extends SapphireTest
{
    protected $usesDatabase = true; // for the canBypassSigning() member checks

    private const SECRET = 'test-signing-secret-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
    }

    private function svc(): TestableSigningService
    {
        return new TestableSigningService();
    }

    /** Parse the s/e/ss params and the (decoded) path out of a generated URL. */
    private function parse(string $url): array
    {
        $qpos = strpos($url, '?');
        $pathPart = substr($url, 0, $qpos);
        parse_str(substr($url, $qpos + 1), $params);
        // Strip "/signed-asset/" prefix and decode, mirroring the controller.
        $encoded = preg_replace('#^/signed-asset/#', '', $pathPart);
        $path = rawurldecode($encoded);
        return [$path, $params];
    }

    // -------------------------------------------------------------------------
    // Round-trip
    // -------------------------------------------------------------------------
    public function testRoundTripValidates(): void
    {
        $svc = $this->svc();
        $url = $svc->generateSignedURL('Uploads/abc123/doc.pdf', 300);
        [$path, $p] = $this->parse($url);
        $this->assertSame('Uploads/abc123/doc.pdf', $path);
        $this->assertArrayHasKey('s', $p);
        $this->assertArrayHasKey('e', $p);
        $this->assertSame(true, $svc->validateSignature($p['s'], (int) $p['e'], $path, false));
    }

    public function testSpecialCharPathRoundTrips(): void
    {
        $svc = $this->svc();
        $raw = 'Uploads/space dir/náme (1)+x.png';
        $url = $svc->generateSignedURL($raw, 300);
        [$path, $p] = $this->parse($url);
        $this->assertSame($raw, $path, 'encode -> decode must be lossless');
        $this->assertSame(true, $svc->validateSignature($p['s'], (int) $p['e'], $path, false));
    }

    // -------------------------------------------------------------------------
    // Tamper rejection (the core security property)
    // -------------------------------------------------------------------------
    public function testTamperedPathRejected(): void
    {
        $svc = $this->svc();
        $url = $svc->generateSignedURL('Uploads/a/secret.pdf', 300);
        [, $p] = $this->parse($url);
        $this->assertSame('invalid_signature', $svc->validateSignature($p['s'], (int) $p['e'], 'Uploads/a/OTHER.pdf', false));
    }

    public function testTamperedExpiryRejected(): void
    {
        // Extending the expiry must invalidate the signature (hash covers e),
        // so a captured link can never be lengthened.
        $svc = $this->svc();
        $url = $svc->generateSignedURL('Uploads/a/doc.pdf', 300);
        [$path, $p] = $this->parse($url);
        $extended = (int) $p['e'] + 100000;
        $this->assertSame('invalid_signature', $svc->validateSignature($p['s'], $extended, $path, false));
    }

    public function testTamperedHashRejected(): void
    {
        $svc = $this->svc();
        $url = $svc->generateSignedURL('Uploads/a/doc.pdf', 300);
        [$path, $p] = $this->parse($url);
        $this->assertSame('invalid_signature', $svc->validateSignature('0000000000000000', (int) $p['e'], $path, false));
    }

    public function testChangingMaskedIdInPathInvalidates(): void
    {
        // A masked path carries the File id; swapping it must break the signature
        // (can't pivot a valid link to another file).
        $svc = $this->svc();
        $url = $svc->generateSignedURL('test-thumbnails/x0000093f.png', 300);
        [, $p] = $this->parse($url);
        $this->assertSame('invalid_signature', $svc->validateSignature($p['s'], (int) $p['e'], 'test-thumbnails/x000000ff.png', false));
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------
    public function testExpiredRejectedBeforeSignatureCheck(): void
    {
        $svc = $this->svc();
        // Even with a correct hash, a past expiry is rejected as 'expired'.
        $past = time() - 10;
        $hash = $svc->pub_generateHash('Uploads/a/doc.pdf', $past, '');
        $this->assertSame('expired', $svc->validateSignature($hash, $past, 'Uploads/a/doc.pdf', false));
    }

    public function testFutureExpiryNotExpired(): void
    {
        $svc = $this->svc();
        $future = time() + 60;
        $hash = $svc->pub_generateHash('Uploads/a/doc.pdf', $future, '');
        $this->assertSame(true, $svc->validateSignature($hash, $future, 'Uploads/a/doc.pdf', false));
    }

    // -------------------------------------------------------------------------
    // Session binding
    // -------------------------------------------------------------------------
    public function testSessionBindingChangesHash(): void
    {
        $svc = $this->svc();
        $unbound = $svc->pub_generateHash('Uploads/a/doc.pdf', 1000, '');
        $bound = $svc->pub_generateHash('Uploads/a/doc.pdf', 1000, 'session-token-A');
        $this->assertNotSame($unbound, $bound, 'session token must affect the signature');
    }

    public function testSessionBoundUrlRejectedForDifferentSession(): void
    {
        $svc = $this->svc();
        $svc->fakeSessionToken = 'session-A';
        $url = $svc->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);
        $this->assertSame('1', $p['ss'] ?? null, 'session-bound url carries ss=1');

        // Same session validates.
        $this->assertSame(true, $svc->validateSignature($p['s'], (int) $p['e'], $path, true));

        // A different session does not.
        $other = $this->svc();
        $other->fakeSessionToken = 'session-B';
        $this->assertSame('invalid_signature', $other->validateSignature($p['s'], (int) $p['e'], $path, true));
    }

    public function testSessionBoundUrlRejectedWhenValidatedUnbound(): void
    {
        $svc = $this->svc();
        $svc->fakeSessionToken = 'session-A';
        $url = $svc->generateSignedURL('Uploads/a/doc.pdf', 300, true);
        [$path, $p] = $this->parse($url);
        // Dropping the ss flag (sessionBound=false) omits the token -> mismatch.
        $this->assertSame('invalid_signature', $svc->validateSignature($p['s'], (int) $p['e'], $path, false));
    }

    // -------------------------------------------------------------------------
    // Secret
    // -------------------------------------------------------------------------
    public function testMissingSecretThrows(): void
    {
        Environment::setEnv('ASSET_SIGNING_SECRET', '');
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc()->generateSignedURL('Uploads/a/doc.pdf', 300);
        } finally {
            Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        }
    }

    public function testDifferentSecretProducesDifferentHash(): void
    {
        $svc = $this->svc();
        $h1 = $svc->pub_generateHash('Uploads/a/doc.pdf', 1000, '');
        Environment::setEnv('ASSET_SIGNING_SECRET', 'a-completely-different-secret');
        $h2 = $svc->pub_generateHash('Uploads/a/doc.pdf', 1000, '');
        Environment::setEnv('ASSET_SIGNING_SECRET', self::SECRET);
        $this->assertNotSame($h1, $h2, 'signature must depend on the secret');
    }

    // -------------------------------------------------------------------------
    // Bypass (admin/CMS) + policy table
    // -------------------------------------------------------------------------
    public function testAnonymousCannotBypass(): void
    {
        $this->logOut();
        $this->assertFalse($this->svc()->canBypassSigning());
    }

    public function testAdminCanBypass(): void
    {
        $this->logInWithPermission('ADMIN');
        $this->assertTrue($this->svc()->canBypassSigning());
    }

    public function testPlainMemberCannotBypass(): void
    {
        $this->logInWithPermission('SOME_UNRELATED_PERMISSION');
        $this->assertFalse($this->svc()->canBypassSigning());
    }

    public function testPolicyTableMatchesDocumentedValues(): void
    {
        // Guards against accidental edits to the named TTL/session policies.
        $policies = AssetUrlSigningService::config()->get('policies');
        $this->assertSame(['ttl' => 30, 'session' => true], $policies['ss']);
        $this->assertSame(['ttl' => 30, 'session' => false], $policies['s']);
        $this->assertSame(['ttl' => 3600, 'session' => true], $policies['ms']);
        $this->assertSame(['ttl' => 3600, 'session' => false], $policies['m']);
        $this->assertSame(['ttl' => 86400, 'session' => true], $policies['ls']);
        $this->assertSame(['ttl' => 86400, 'session' => false], $policies['l']);
    }
}

/**
 * Exposes generateHash() and lets tests inject a deterministic session token
 * (real getSessionToken() returns '' under CLI, so binding can't be exercised
 * otherwise).
 */
class TestableSigningService extends AssetUrlSigningService
{
    public ?string $fakeSessionToken = null;

    public function pub_generateHash(string $path, int $expires, string $token = ''): string
    {
        return $this->generateHash($path, $expires, $token);
    }

    protected function getSessionToken(): string
    {
        return $this->fakeSessionToken !== null ? $this->fakeSessionToken : parent::getSessionToken();
    }
}
