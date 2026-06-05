<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * WS-2 / WS-3 — `WSRouter::bootChecks()` boot-time advisories (mirror of
 * `App::redisBootChecks()`).
 *
 * WS-2: when a WS rate limit is configured but the Counter backend is the
 * default per-worker Atomic, the limit is per-worker (× worker_count) and NOT
 * cross-node — the advisory surfaces that silent under-protection.
 *
 * WS-3: when the router is Redis-backed (cross-node pub/sub) but no channel
 * HMAC secret is set, routed ws:server:* / ws:room:* messages are
 * unauthenticated — the advisory surfaces the forge-a-message risk.
 *
 * Pure + side-effect-free, so testable without booting a server. Both Redis
 * backend objects construct lazily (no connection at default-backend time),
 * so these run without a live Redis.
 */
final class WsRouterBootChecksTest extends TestCase
{
    protected function setUp(): void
    {
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        WSRouter::setRoomRateLimit(0);
        WSRouter::setClientRateLimit(0);
        WSRouter::setChannelHmacSecret(null);
    }

    protected function tearDown(): void
    {
        WSRouter::setRoomRateLimit(0);
        WSRouter::setClientRateLimit(0);
        WSRouter::setChannelHmacSecret(null);
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        WSRouter::reset();
    }

    /** @param list<string> $warnings */
    private static function hasWarning(array $warnings, string $needle): bool
    {
        foreach ($warnings as $w) {
            if (str_contains($w, $needle)) { return true; }
        }
        return false;
    }

    // ── WS-2: rate-limit-on-Atomic advisory ──────────────────────────────

    public function testNoRateLimitAdvisoryWhenLimitsDisabled(): void
    {
        // Limits off → no WS-2 advisory regardless of backend.
        self::assertFalse(self::hasWarning(WSRouter::bootChecks(), 'WS-2'));
    }

    public function testRoomRateLimitOnAtomicEmitsAdvisory(): void
    {
        WSRouter::setRoomRateLimit(100, 60);
        $warnings = WSRouter::bootChecks();
        self::assertTrue(self::hasWarning($warnings, 'WS-2'));
        self::assertTrue(self::hasWarning($warnings, 'PER-WORKER'),
            'advisory states the limit is per-worker');
    }

    public function testClientRateLimitOnAtomicEmitsAdvisory(): void
    {
        WSRouter::setClientRateLimit(50, 10);
        self::assertTrue(self::hasWarning(WSRouter::bootChecks(), 'WS-2'));
    }

    public function testRateLimitOnSharedCounterBackendSuppressesAdvisory(): void
    {
        // Shared (Redis) Counter backend → limits ARE cross-node → no advisory.
        Counter::defaultBackend(Counter::BACKEND_REDIS, 'redis://127.0.0.1:59999/0');
        WSRouter::setRoomRateLimit(100, 60);
        self::assertFalse(self::hasWarning(WSRouter::bootChecks(), 'WS-2'),
            'no per-worker advisory when the Counter backend is shared');
    }

    public function testCounterBackendIsSharedReflectsBackend(): void
    {
        self::assertFalse(WSRouter::counterBackendIsShared(), 'atomic is per-worker');
        Counter::defaultBackend(Counter::BACKEND_REDIS, 'redis://127.0.0.1:59999/0');
        self::assertTrue(WSRouter::counterBackendIsShared(), 'redis is shared');
    }

    // ── WS-3: Redis-backed-without-HMAC advisory ──────────────────────────

    public function testNoHmacAdvisoryOnTableBackend(): void
    {
        // Table backend has no cross-node pub/sub → no WS-3 advisory even
        // without an HMAC secret.
        self::assertFalse(self::hasWarning(WSRouter::bootChecks(), 'WS-3'));
    }

    public function testRedisBackedWithoutHmacEmitsAdvisory(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:59999/0');
        $warnings = WSRouter::bootChecks();
        self::assertTrue(self::hasWarning($warnings, 'WS-3'));
        self::assertTrue(self::hasWarning($warnings, 'UNAUTHENTICATED'),
            'advisory states messages are unauthenticated');
    }

    public function testRedisBackedWithHmacSuppressesAdvisory(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:59999/0');
        WSRouter::setChannelHmacSecret('shared-cluster-secret');
        self::assertFalse(self::hasWarning(WSRouter::bootChecks(), 'WS-3'),
            'a configured HMAC secret suppresses the WS-3 advisory');
    }

    public function testHmacConfiguredReflectsSecretState(): void
    {
        self::assertFalse(WSRouter::hmacConfigured());
        WSRouter::setChannelHmacSecret('s');
        self::assertTrue(WSRouter::hmacConfigured());
        WSRouter::setChannelHmacSecret('');   // empty == disabled
        self::assertFalse(WSRouter::hmacConfigured());
    }

    public function testBothAdvisoriesCanFireTogether(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:59999/0');
        WSRouter::setRoomRateLimit(10, 60);   // on Atomic Counter → WS-2
        // No HMAC secret + Redis Store → WS-3
        $warnings = WSRouter::bootChecks();
        self::assertTrue(self::hasWarning($warnings, 'WS-2'));
        self::assertTrue(self::hasWarning($warnings, 'WS-3'));
        self::assertCount(2, $warnings);
    }
}
