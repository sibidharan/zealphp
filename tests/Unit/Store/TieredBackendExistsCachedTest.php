<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;

/**
 * H8: TieredBackend::existsCached() — stale-OK opt-in fast-path.
 *
 * The strict exists() always hits L2 (consistency). existsCached() returns
 * true if L1 has a fresh entry within $l1Ttl; otherwise defers to L2.
 *
 * The L1-fresh fast-path needs no L2 traffic — that's the whole point of
 * the optimization. We exercise it by configuring a TieredBackend whose
 * L2 has a lazy connection pool that would explode on a real call (no
 * Redis is required for these tests; we never actually USE the L2 pool).
 *
 * The L1-miss fallback path needs a live L2; covered in
 * tests/Integration/StoreBackendIntegrationTest.php instead.
 */
final class TieredBackendExistsCachedTest extends TestCase
{
    private TieredBackend $tiered;

    protected function setUp(): void
    {
        // RedisBackend constructor is lazy — the pool isn't dialed until
        // the first op runs. Safe to instantiate without a live Redis.
        $l2 = new RedisBackend(new RedisConnectionPool('redis://127.0.0.1:9'));
        $this->tiered = new TieredBackend(new TableBackend(), $l2, l1Ttl: 60);
        $this->tiered->make('t', 16, [
            'name' => [Table::TYPE_STRING, 32],
            'hits' => [Table::TYPE_INT],
        ]);
    }

    public function testL1FreshHitShortCircuitsBeforeL2(): void
    {
        // set() writes through to L2 (would throw if L2 were called), so
        // we populate L1 directly instead to exercise the read fast-path.
        $this->tiered->l1()->set('t', 'alice', [
            'name' => 'a', 'hits' => 1, '__cached_at' => time(),
        ]);
        // L1 fresh → existsCached returns true without touching L2.
        self::assertTrue($this->tiered->existsCached('t', 'alice'));
    }

    public function testL1StaleEntryFallsThroughToL2(): void
    {
        // Stale L1 (cached_at older than $l1Ttl=60) should fall through to
        // L2.exists(); without a live Redis on :9 that will throw. We
        // assert the exception comes from the L2 path — proves existsCached
        // didn't short-circuit on the stale row.
        $this->tiered->l1()->set('t', 'bob', [
            'name' => 'b', 'hits' => 1, '__cached_at' => time() - 3600,
        ]);
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $this->tiered->existsCached('t', 'bob');
    }

    public function testL1EmptyFallsThroughToL2(): void
    {
        // No L1 entry at all → fall through to L2.
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $this->tiered->existsCached('t', 'never-cached');
    }

    public function testExistsAlwaysHitsL2Regardless(): void
    {
        // The strict exists() must NEVER use L1 — even a fresh L1 row
        // doesn't change the behaviour.
        $this->tiered->l1()->set('t', 'carol', [
            'name' => 'c', 'hits' => 1, '__cached_at' => time(),
        ]);
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $this->tiered->exists('t', 'carol');
    }
}
