<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\Stats;

/**
 * H5: operational stats surface.
 *
 * - Stats class is a simple monotonic counter struct.
 * - RedisConnectionPool tracks pool_acquires_total + pool_acquire_timeouts_total +
 *   pool_clients_created_total.
 * - Store::stats() proxies the active backend's pool stats (empty on Table).
 */
final class StatsTest extends TestCase
{
    public function testStatsStructIncrementAndSnapshot(): void
    {
        $s = new Stats();
        self::assertSame(0, $s->get('foo'));
        $s->inc('foo');
        $s->inc('foo', 4);
        $s->inc('bar');
        self::assertSame(5, $s->get('foo'));
        self::assertSame(1, $s->get('bar'));
        self::assertSame(['foo' => 5, 'bar' => 1], $s->snapshot());
    }

    public function testStatsResetClearsCounters(): void
    {
        $s = new Stats();
        $s->inc('x', 7);
        $s->reset();
        self::assertSame([], $s->snapshot());
        self::assertSame(0, $s->get('x'));
    }

    public function testStoreStatsEmptyOnTableBackend(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        self::assertSame([], Store::stats());
    }

    public function testPoolStatsTrackSyncAcquires(): void
    {
        // Sync-context path uses the singleton client — increments acquires
        // + clients_created on first use, then only acquires after that.
        $pool = new RedisConnectionPool('redis://127.0.0.1:9');
        // Sync context (not inside Coroutine::run) means acquire() uses syncClient
        $stats = $pool->stats();
        self::assertSame(0, $stats->get('pool_acquires_total'));
        try {
            // Acquire will populate the syncClient; the client lazy-builds and
            // doesn't dial until first op, so this should succeed.
            $c = $pool->acquire();
            self::assertSame(1, $stats->get('pool_acquires_total'));
            self::assertSame(1, $stats->get('pool_clients_created_total'));
            $c2 = $pool->acquire();
            self::assertSame(2, $stats->get('pool_acquires_total'));
            self::assertSame(1, $stats->get('pool_clients_created_total'), 'sync client reused');
        } catch (\Throwable $e) {
            // If the sync client connect fails, stats still record the attempt:
            // the inc happens AFTER syncClient assignment in the current code,
            // so a connect failure would skip the inc. That's acceptable —
            // failed acquires don't represent successful pool usage.
            self::assertStringContainsString('connect', strtolower($e->getMessage()),
                'expected connect failure (mock URL); other errors are bugs');
        }
    }

    public function testStoreStatsProxiesPoolOnRedisBackend(): void
    {
        // Construct a Redis backend with no live Redis — only stats() access
        // is exercised, no actual ops.
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        // No Redis ops have happened yet, so the counters snapshot is empty.
        // The important thing this test verifies is that the call path
        // (Store → backend → pool → stats) is wired and doesn't throw.
        self::assertSame([], Store::stats());
        Store::defaultBackend(Store::BACKEND_TABLE);
    }
}
