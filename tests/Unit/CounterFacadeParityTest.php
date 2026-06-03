<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store\StoreException;

/**
 * Drives the Counter facade through both backends — the same scenarios
 * the historical CounterTest pins for the atomic path, replayed against
 * the redis backend via Counter::defaultBackend('redis').
 *
 * Companion to StoreFacadeParityTest. Covers the redis-mode lines in
 * src/Counter.php that the atomic-only CounterTest can't reach.
 */
final class CounterFacadeParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Counter::defaultBackend('atomic');
    }

    public function testDefaultBackendIsAtomic(): void
    {
        $b = Counter::defaultBackend();
        $this->assertInstanceOf(\ZealPHP\Counter\AtomicBackend::class, $b);
    }

    public function testAtomicBackendExposesRaw(): void
    {
        Counter::defaultBackend('atomic');
        $c = new Counter(7);
        // raw() returns the 64-bit OpenSwoole\Atomic\Long (was OpenSwoole\Atomic
        // pre-overflow-fix) so counters can't silently wrap at 2^32.
        $this->assertInstanceOf(\OpenSwoole\Atomic\Long::class, $c->raw());
        $this->assertSame(7, $c->raw()->get());
    }

    public function testUnknownKindRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Counter::defaultBackend('nonsense');
    }

    public function testRedisRawThrowsStoreException(): void
    {
        $this->skipIfNoRedis();
        Counter::defaultBackend('redis', (string) getenv('ZEALPHP_REDIS_URL'));
        $c = new Counter(0, 'parity_raw');
        $this->expectException(StoreException::class);
        $c->raw();
    }

    public function testRedisBackendStringUrlConfig(): void
    {
        $this->skipIfNoRedis();
        \OpenSwoole\Coroutine::run(function (): void {
            Counter::defaultBackend('redis', (string) getenv('ZEALPHP_REDIS_URL'));
            $c = new Counter(0, 'parity_string_url');
            $c->set(0);
            $this->assertSame(0, $c->get());
            $this->assertSame(5, $c->increment(5));
            $this->assertSame(2, $c->decrement(3));
        });
    }

    public function testRedisBackendArrayConfigWithPoolSize(): void
    {
        $this->skipIfNoRedis();
        \OpenSwoole\Coroutine::run(function (): void {
            Counter::defaultBackend('redis', [
                'url'       => (string) getenv('ZEALPHP_REDIS_URL'),
                'pool_size' => 4,
                'prefix'    => 'zptest-counter-array',
            ]);
            $c = new Counter(0, 'parity_array');
            $c->set(100);
            $this->assertSame(100, $c->get());
            $this->assertTrue($c->compareAndSet(100, 200));
            $this->assertSame(200, $c->get());
            $this->assertFalse($c->compareAndSet(999, 0));
            $this->assertSame(200, $c->get());
            $c->reset();
            $this->assertSame(0, $c->get());
        });
    }

    public function testRedisBackendEmptyArrayConfigFallsBackToEnv(): void
    {
        $this->skipIfNoRedis();
        \OpenSwoole\Coroutine::run(function (): void {
            // Empty conn array → buildBackend falls back to the env URL.
            Counter::defaultBackend('redis', []);
            $c = new Counter(0, 'parity_env_default');
            $this->assertSame(0, $c->get());
            $this->assertSame(1, $c->increment());
        });
    }

    public function testRedisUrlFromEnvFallbackWhenEnvUnset(): void
    {
        // Simulate a missing env var; buildBackend should fall back to the
        // built-in default redis://127.0.0.1:6379 (no connect attempt yet —
        // backend is built lazily, pool connects on first acquire).
        $prev = getenv('ZEALPHP_REDIS_URL');
        putenv('ZEALPHP_REDIS_URL');
        try {
            Counter::defaultBackend('redis');
            $b = Counter::defaultBackend();
            $this->assertInstanceOf(\ZealPHP\Counter\RedisCounterBackend::class, $b);
        } finally {
            if ($prev !== false) { putenv("ZEALPHP_REDIS_URL=$prev"); }
        }
    }

    public function testDefaultBackendReadCurrentWithoutMutating(): void
    {
        Counter::defaultBackend('atomic');
        $a = Counter::defaultBackend();
        $b = Counter::defaultBackend();
        $this->assertSame($a, $b, 'no-arg call returns cached singleton');
    }

    private function skipIfNoRedis(): void
    {
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        if ($url === '') { $url = 'redis://127.0.0.1:16379/0'; }
        try {
            $c = new \Predis\Client($url);
            $c->ping();
            $c->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis/Valkey not available at ' . $url);
        }
    }
}
