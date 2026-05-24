<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * Patch-coverage for the v0.2.40 WSRouter additions:
 *   - HMAC sign/verify (WS-3)
 *   - Per-client rate limit (WS-4)
 *   - WebSocket close-code constants (WS-5)
 *   - Inline init() options (no separate initOptions step)
 *   - Backend-detection helper (`hasRedisBackend`)
 *
 * All paths exercise the Table backend — no Redis dependency. The pub/sub
 * federation paths are covered by the integration smoke at
 * scripts/smoke-v0.2.40.php and the existing WSRouterTest.
 */
final class WSRouterV040Test extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        WSRouter::reset();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        WSRouter::setChannelHmacSecret(null);
        WSRouter::setClientRateLimit(0);
    }

    // ── WS-3: HMAC sign/verify ──────────────────────────────────────────

    public function testSignPayloadPassthroughWhenSecretUnset(): void
    {
        WSRouter::setChannelHmacSecret(null);
        $this->assertSame('hello', WSRouter::signPayload('hello'));
    }

    public function testVerifyPayloadPassthroughWhenSecretUnset(): void
    {
        WSRouter::setChannelHmacSecret(null);
        $this->assertSame('hello', WSRouter::verifyPayload('hello'));
    }

    public function testSignVerifyRoundTripWithSecret(): void
    {
        WSRouter::setChannelHmacSecret('secret-1234');
        $signed   = WSRouter::signPayload('the payload');
        $this->assertNotSame('the payload', $signed, 'Signed envelope is JSON-wrapped, not raw');
        $this->assertSame('the payload', WSRouter::verifyPayload($signed));
    }

    public function testVerifyPayloadRejectsBadHmac(): void
    {
        WSRouter::setChannelHmacSecret('secret-1234');
        $bad = json_encode(['v' => 1, 'hmac' => 'deadbeef', 'payload' => 'tampered']);
        $this->assertIsString($bad);
        $this->assertNull(WSRouter::verifyPayload($bad));
    }

    public function testVerifyPayloadRejectsMissingFields(): void
    {
        WSRouter::setChannelHmacSecret('secret-1234');
        $this->assertNull(WSRouter::verifyPayload('not-json'));
        $this->assertNull(WSRouter::verifyPayload(json_encode(['v' => 1])));   // no hmac/payload
        $this->assertNull(WSRouter::verifyPayload(json_encode(['v' => 2, 'hmac' => 'x', 'payload' => 'y'])));
    }

    public function testSecretEmptyStringIsTreatedAsUnset(): void
    {
        WSRouter::setChannelHmacSecret('');
        // Empty string == disabled → passthrough behavior.
        $this->assertSame('hello', WSRouter::signPayload('hello'));
        $this->assertSame('hello', WSRouter::verifyPayload('hello'));
    }

    // ── WS-4: per-client rate limit ─────────────────────────────────────

    public function testCheckClientRateAllowsAllWhenDisabled(): void
    {
        WSRouter::setClientRateLimit(0);
        $this->assertTrue(WSRouter::checkClientRate('any-client'));
        $this->assertTrue(WSRouter::checkClientRate(''));
    }

    public function testCheckClientRateCapsThenDrops(): void
    {
        WSRouter::setClientRateLimit(3, 60);
        $cid = 'rl-test-' . bin2hex(random_bytes(3));
        $this->assertTrue(WSRouter::checkClientRate($cid));   // 1
        $this->assertTrue(WSRouter::checkClientRate($cid));   // 2
        $this->assertTrue(WSRouter::checkClientRate($cid));   // 3
        $this->assertFalse(WSRouter::checkClientRate($cid));  // 4 — over
        $this->assertFalse(WSRouter::checkClientRate($cid));  // 5 — still over
    }

    public function testCheckClientRateAcceptsEmptyClientId(): void
    {
        // Empty client id is never rate-limited (it's not attributed to anyone).
        WSRouter::setClientRateLimit(1, 60);
        $this->assertTrue(WSRouter::checkClientRate(''));
        $this->assertTrue(WSRouter::checkClientRate(''));   // still true
    }

    public function testClientRateLimitNGetterReportsCurrentN(): void
    {
        WSRouter::setClientRateLimit(42, 60);
        $this->assertSame(42, WSRouter::clientRateLimitN());
        WSRouter::setClientRateLimit(0);
        $this->assertSame(0, WSRouter::clientRateLimitN());
    }

    public function testSetClientRateLimitRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::setClientRateLimit(-1, 60);
    }

    public function testSetClientRateLimitRejectsZeroWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::setClientRateLimit(10, 0);
    }

    // ── WS-5: close-code constants ──────────────────────────────────────

    public function testCloseCodeConstants(): void
    {
        // Standard 1000-1099 (RFC 6455)
        $this->assertSame(1000, WSRouter::CLOSE_NORMAL);
        $this->assertSame(1001, WSRouter::CLOSE_GOING_AWAY);
        $this->assertSame(1002, WSRouter::CLOSE_PROTOCOL_ERROR);
        $this->assertSame(1003, WSRouter::CLOSE_UNSUPPORTED);
        $this->assertSame(1008, WSRouter::CLOSE_POLICY_VIOLATION);
        $this->assertSame(1009, WSRouter::CLOSE_MESSAGE_TOO_BIG);
        $this->assertSame(1011, WSRouter::CLOSE_INTERNAL_ERROR);
        $this->assertSame(1013, WSRouter::CLOSE_TRY_AGAIN_LATER);
        // App range 4000-4999
        $this->assertSame(4001, WSRouter::CLOSE_AUTH_REQUIRED);
        $this->assertSame(4002, WSRouter::CLOSE_AUTH_INVALID);
        $this->assertSame(4003, WSRouter::CLOSE_FORBIDDEN);
        $this->assertSame(4013, WSRouter::CLOSE_CAPACITY);
        $this->assertSame(4029, WSRouter::CLOSE_RATE_LIMITED);
        $this->assertSame(4040, WSRouter::CLOSE_IDLE);
    }

    // ── inline init() options ──────────────────────────────────────────

    public function testInitAcceptsInlineCapacityOptions(): void
    {
        WSRouter::init(
            ownerCapacity:       12_345,
            roomMembersCapacity: 67_890,
            slowConsumerBytes:   2 * 1024 * 1024,
        );
        // Reflect the static properties to confirm the inline options landed.
        $r = new \ReflectionClass(WSRouter::class);
        $this->assertSame(12_345,    $r->getProperty('ownerCapacity')->getValue());
        $this->assertSame(67_890,    $r->getProperty('roomMembersCapacity')->getValue());
        $this->assertSame(2 * 1024 * 1024, $r->getProperty('slowConsumerBytes')->getValue());
    }

    // ── backend-detection helper ────────────────────────────────────────

    public function testHasRedisBackendFalseOnTable(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->assertFalse(WSRouter::hasRedisBackend());
    }
}
