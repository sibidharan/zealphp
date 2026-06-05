<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * #256(B) — the Store facade must UNWRAP a Tiered backend to its working L2
 * (Redis) for publish/publishReliable/stats, mirroring redisOrThrow()/
 * hasSetOps(). Previously these rejected Tiered (throw / return []) despite a
 * fully functional cross-node L2.
 *
 * These cases use a dead-port Tiered backend (lazy construction = no
 * connection at build time), so they run WITHOUT a live Redis: the proof is
 * that the facade gets PAST the `instanceof RedisBackend` guard — publish now
 * fails with a downstream CONNECT error, not the "requires the redis backend"
 * rejection; stats returns the L2 pool snapshot array, not the rejected [].
 */
final class TieredBackendPublishGateTest extends TestCase
{
    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    public function testPublishUnwrapsTieredAndReachesL2(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, 'redis://127.0.0.1:9');
        try {
            Store::publish('ch', 'payload');
            $this->fail('expected a downstream connect failure (dead port)');
        } catch (StoreException $e) {
            // It got PAST the instanceof guard — the error is a connect failure,
            // NOT the "requires the redis backend" rejection.
            self::assertStringNotContainsString('requires the redis backend', $e->getMessage());
        }
    }

    public function testPublishReliableUnwrapsTieredAndReachesL2(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, 'redis://127.0.0.1:9');
        try {
            Store::publishReliable('stream', 'payload');
            $this->fail('expected a downstream connect failure (dead port)');
        } catch (StoreException $e) {
            self::assertStringNotContainsString('requires the redis backend', $e->getMessage());
        }
    }

    public function testStatsUnwrapsTieredToL2PoolSnapshot(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, 'redis://127.0.0.1:9');
        $b = Store::defaultBackend();
        self::assertInstanceOf(TieredBackend::class, $b);
        // Previously Store::stats() returned [] because Tiered was rejected.
        // Now it unwraps to the L2 pool — prove DELEGATION by bumping a counter
        // on the L2 pool's Stats and observing it through the facade.
        $b->l2()->pool()->stats()->inc('pool_acquires_total', 3);
        self::assertSame(3, Store::stats()['pool_acquires_total'] ?? null);
    }

    // ── #256(A): publish-when-disabled gate (live Redis) ────────────────

    private function instance(string $prefix, string $originSuffix): TieredBackend
    {
        $url = getenv('ZEALPHP_REDIS_URL');
        $url = is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        $l1 = new TableBackend();
        $l2 = new RedisBackend(new RedisConnectionPool($url, 4), $prefix);
        return new TieredBackend($l1, $l2, 60, 'origin-' . $originSuffix);
    }

    public function testNoInvalidationPublishWhenDisabled(): void
    {
        if (extension_loaded('redis')) {
            $this->markTestSkipped('uses the predis SUBSCRIBE path to observe publishes');
        }
        $url = getenv('ZEALPHP_REDIS_URL');
        $url = is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        try {
            $probe = new \Predis\Client($url);
            $probe->ping();
            $probe->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: ' . $e->getMessage());
        }

        \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        try {
            Coroutine::run(function (): void {
                $prefix = 'zptest-tier-gate-' . bin2hex(random_bytes(4));
                $writer = $this->instance($prefix, 'W'); // never enableInvalidation()
                $peer   = $this->instance($prefix, 'P');

                $writer->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
                $peer->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);

                // ONLY the peer enables invalidation (so it has a subscriber that
                // WOULD evict its L1 if a publish arrived).
                $peer->enableInvalidation();
                (new Channel(1))->pop(0.15);

                // Warm the peer's L1.
                $writer->set('t', 'k', ['v' => 'v1']);
                self::assertSame(['v' => 'v1'], $peer->get('t', 'k'));
                self::assertNotNull($peer->l1()->get('t', 'k'), 'peer L1 warmed');

                // Writer (invalidation DISABLED) writes again. With the #256(A)
                // gate, it must NOT publish — so the peer's L1 stays intact.
                $writer->set('t', 'k', ['v' => 'v2']);
                (new Channel(1))->pop(0.2);

                self::assertNotNull(
                    $peer->l1()->get('t', 'k'),
                    'peer L1 must NOT be evicted — a disabled writer publishes nothing (#256A)'
                );

                $peer->stopInvalidation();
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine(false);
        }
    }

    public function testInvalidationPublishesWhenEnabled(): void
    {
        if (extension_loaded('redis')) {
            $this->markTestSkipped('uses the predis SUBSCRIBE path to observe publishes');
        }
        $url = getenv('ZEALPHP_REDIS_URL');
        $url = is_string($url) && $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        try {
            $probe = new \Predis\Client($url);
            $probe->ping();
            $probe->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not available: ' . $e->getMessage());
        }

        \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_ALL);
        try {
            Coroutine::run(function (): void {
                $prefix = 'zptest-tier-gate-on-' . bin2hex(random_bytes(4));
                $writer = $this->instance($prefix, 'W');
                $peer   = $this->instance($prefix, 'P');

                $writer->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
                $peer->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);

                // BOTH enable invalidation now — the writer's publish must reach
                // the peer and evict its L1.
                $writer->enableInvalidation();
                $peer->enableInvalidation();
                (new Channel(1))->pop(0.15);

                $writer->set('t', 'k', ['v' => 'v1']);
                self::assertSame(['v' => 'v1'], $peer->get('t', 'k'));
                self::assertNotNull($peer->l1()->get('t', 'k'));

                $writer->set('t', 'k', ['v' => 'v2']);
                (new Channel(1))->pop(0.2);

                self::assertNull(
                    $peer->l1()->get('t', 'k'),
                    'peer L1 IS evicted when the writer has invalidation enabled (#256A, enabled path)'
                );

                $writer->stopInvalidation();
                $peer->stopInvalidation();
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine(false);
        }
    }
}
