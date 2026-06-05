<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * WS-7 — WS rate-limit counter naming no longer grows unboundedly.
 *
 * PRE-WS-7 the room + client rate limiters baked the time-bucket into the
 * Counter NAME (`..._{hash}_{bucket}`), so on the default Atomic Counter
 * backend every elapsed window left a dead named Atomic forever — a slow
 * unbounded per-worker memory leak. WS-7 keeps the counter name STABLE per
 * subject and resets the SAME counter on window rotation, so the live
 * named-Atomic count is bounded by the number of DISTINCT rate-limited
 * subjects, not subjects × elapsed-windows.
 *
 * Runs on the default Atomic Counter backend — no Redis needed. The
 * `$rateLimitWindows` per-worker map is the observable proxy for how many
 * distinct stable counter names are live.
 */
final class WsRouterRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        WSRouter::reset();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        WSRouter::setRoomRateLimit(0);
        WSRouter::setClientRateLimit(0);
    }

    public function testSameSubjectAllocatesExactlyOneWindowEntryAcrossManyCalls(): void
    {
        // Many calls for the SAME stable name — the window map must hold a
        // single entry no matter how many times we hit it (no per-window
        // accumulation). 5000 calls within one wall-clock window.
        for ($i = 0; $i < 5000; $i++) {
            WSRouter::rateLimitAllow('_wsrouter_rl_fixed', 1_000_000, 60);
        }
        self::assertSame(1, WSRouter::rateLimitWindowCount(),
            'a single subject occupies exactly one window-map slot regardless of call count');
    }

    public function testWindowMapBoundedByDistinctSubjectsNotWindows(): void
    {
        // Simulate 100 distinct rooms/clients, each hammered many times. The
        // map grows to 100 (one per subject) — NOT 100 × number-of-windows.
        for ($subject = 0; $subject < 100; $subject++) {
            $name = '_wsrouter_rl_' . substr(sha1("room-$subject"), 0, 16);
            for ($i = 0; $i < 50; $i++) {
                WSRouter::rateLimitAllow($name, 1_000_000, 60);
            }
        }
        self::assertSame(100, WSRouter::rateLimitWindowCount(),
            'window map size == distinct subjects, bounded regardless of call volume');
    }

    public function testWindowRotationReusesTheSameSlotNotAFreshAllocation(): void
    {
        // window=1s: the first call records window W; after the wall clock rolls
        // into W+1, the next call for the SAME name reuses the same map slot
        // (updates the stored window id in place) — the count stays 1.
        $name = '_wsrouter_rl_rotate';
        WSRouter::rateLimitAllow($name, 1_000_000, 1);
        self::assertSame(1, WSRouter::rateLimitWindowCount());

        // Cross a 1s window boundary deterministically.
        usleep(1_100_000);
        WSRouter::rateLimitAllow($name, 1_000_000, 1);

        self::assertSame(1, WSRouter::rateLimitWindowCount(),
            'crossing a window boundary reuses the same slot — no new allocation');
    }

    public function testLimitEnforcedWithinAWindow(): void
    {
        // Allow exactly 3 ops in a 60s window for a fresh subject.
        $name = '_wsrouter_rl_enforce_' . bin2hex(random_bytes(3));
        self::assertTrue(WSRouter::rateLimitAllow($name, 3, 60));   // 1
        self::assertTrue(WSRouter::rateLimitAllow($name, 3, 60));   // 2
        self::assertTrue(WSRouter::rateLimitAllow($name, 3, 60));   // 3
        self::assertFalse(WSRouter::rateLimitAllow($name, 3, 60));  // 4 — over
        self::assertFalse(WSRouter::rateLimitAllow($name, 3, 60));  // 5 — still over
    }

    public function testWindowResetReopensTheBudget(): void
    {
        // After a 1s window elapses the SAME counter is reset → the budget
        // reopens, proving the stable-name reuse still behaves as a window.
        $name = '_wsrouter_rl_reopen_' . bin2hex(random_bytes(3));
        self::assertTrue(WSRouter::rateLimitAllow($name, 1, 1));    // 1 — ok
        self::assertFalse(WSRouter::rateLimitAllow($name, 1, 1));   // 2 — over (same window)

        usleep(1_100_000);                                          // cross boundary
        self::assertTrue(WSRouter::rateLimitAllow($name, 1, 1),
            'a new window resets the counter and reopens the budget');
    }

    public function testDisabledLimitsShortCircuit(): void
    {
        // n <= 0 → always allowed, never touches the window map.
        self::assertTrue(WSRouter::rateLimitAllow('_x', 0, 60));
        self::assertTrue(WSRouter::rateLimitAllow('_x', -5, 60));
        self::assertSame(0, WSRouter::rateLimitWindowCount(),
            'disabled limits allocate nothing');
    }

    public function testCheckClientRateBumpsDropStatOnBreach(): void
    {
        WSRouter::stats()->reset();
        WSRouter::setClientRateLimit(2, 60);
        self::assertTrue(WSRouter::checkClientRate('noisy'));   // 1
        self::assertTrue(WSRouter::checkClientRate('noisy'));   // 2
        self::assertFalse(WSRouter::checkClientRate('noisy'));  // 3 — dropped
        self::assertSame(1, WSRouter::stats()->get('client_rate_limit_drops_total'));
    }
}
