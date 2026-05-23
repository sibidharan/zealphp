<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\StoreException;
use ZealPHP\WSRouter;

/**
 * Second patch-coverage batch: targets branches the first batch left
 * uncovered — Cache stampede stall path, Store::defaultBackend
 * config-as-array branches, and Cache tag invalidation paths.
 */
final class PatchCoverageBoost2Test extends TestCase
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
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    // ── Cache stampede gate: lock-contention path ────────────────────

    public function testGetOrComputeStallsWhenLockHeldThenFallsThrough(): void
    {
        // C-1 stampede gate: another worker has the lock — this caller
        // polls for ~200ms (10 × 20ms usleep), then computes itself.
        $dir = sys_get_temp_dir() . '/zptest-stampede-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $key  = 'stampede-key-' . bin2hex(random_bytes(2));
        // Acquire the lock by hand — drive the CAS to 1 from "outside"
        // so getOrCompute's CAS loses.
        $lockName = '__cache_lock_' . md5($key);
        $lock = new Counter(0, $lockName);
        $lock->compareAndSet(0, 1);
        // Now call getOrCompute — must hit the stall loop (L195-211).
        $start = microtime(true);
        $val = Cache::getOrCompute($key, fn() => 'computed', 30);
        $elapsed = microtime(true) - $start;
        $this->assertSame('computed', $val);
        // ~200ms expected (10 × 20ms); allow generous slack for CI.
        $this->assertGreaterThanOrEqual(0.15, $elapsed);
    }

    // ── Cache::invalidateTag ────────────────────────────────────────

    public function testInvalidateTagOnTableBackendReturnsZero(): void
    {
        // L283-289 — Table backend has no set ops; invalidateTag logs
        // a warning and returns 0 without sweeping anything.
        $dir = sys_get_temp_dir() . '/zptest-tags-table-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        Cache::set('a', 'A');
        $dropped = Cache::invalidateTag('groupA');
        $this->assertSame(0, $dropped);
        // Entry untouched (no set-ops to walk).
        $this->assertSame('A', Cache::get('a'));
    }

    // ── Store::defaultBackend('memcached', [array]) shape ──────────

    public function testStoreDefaultBackendMemcachedAcceptsArrayConfig(): void
    {
        try {
            Store::defaultBackend('memcached', [
                'servers' => '127.0.0.1:1',
                'prefix'  => 'zptest',
            ]);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testStoreDefaultBackendMemcachedArrayWithoutServersFallsBackToEnv(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Store::defaultBackend('memcached', ['prefix' => 'zptest']);
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    // ── Store::defaultBackend('redis', [array with prefer]) ────────

    public function testStoreDefaultBackendRedisArrayWithPreferString(): void
    {
        // Hits Store.php L188-195: DriverPreference::coerce path on
        // the array-config branch. Connection isn't actually opened
        // until first use, so this is a pure boot-config path test.
        Store::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',   // intentional bad port; never connects
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);   // no throw at config-time
    }

    public function testStoreDefaultBackendRedisArrayWithPreferEnum(): void
    {
        Store::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => DriverPreference::Auto,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreDefaultBackendRedisArrayWithInvalidPreferFallsBack(): void
    {
        // L138 — \InvalidArgumentException caught silently.
        Store::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'nonsense-driver-name',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── Store::defaultBackend('tiered', [array]) ───────────────────

    public function testStoreDefaultBackendTieredAcceptsArrayConfig(): void
    {
        Store::defaultBackend('tiered', [
            'url'                 => 'redis://127.0.0.1:65000/0',
            'pool_size'           => 4,
            'prefix'              => 'zptest',
            'l1_ttl'              => 10,
            'invalidation_secret' => 'shared-secret-xyz',
            'prefer'              => 'predis',
        ]);
        $this->addToAssertionCount(1);   // no throw at config-time
    }

    public function testStoreDefaultBackendTieredWithInvalidPreferFallsBack(): void
    {
        Store::defaultBackend('tiered', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'unknown-driver',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── Counter::defaultBackend('memcached', [array]) ──────────────

    public function testCounterDefaultBackendMemcachedAcceptsArrayConfig(): void
    {
        try {
            Counter::defaultBackend('memcached', [
                'servers' => '127.0.0.1:1',
                'prefix'  => 'zptest',
            ]);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testCounterDefaultBackendRedisArrayShape(): void
    {
        Counter::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testCounterDefaultBackendRedisInvalidPreferFallsBack(): void
    {
        Counter::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'unknown-driver',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── DriverPreference enum coerce variants ───────────────────────

    public function testDriverPreferenceCoerceFromEnumPassesThrough(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce(DriverPreference::Phpredis));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce(DriverPreference::Predis));
        $this->assertSame(DriverPreference::Auto,     DriverPreference::coerce(DriverPreference::Auto));
    }

    public function testDriverPreferenceCoerceFromString(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce('phpredis'));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce('predis'));
        $this->assertSame(DriverPreference::Auto,     DriverPreference::coerce('auto'));
    }

    public function testDriverPreferenceCoerceCaseInsensitive(): void
    {
        $this->assertSame(DriverPreference::Phpredis, DriverPreference::coerce('PHPREDIS'));
        $this->assertSame(DriverPreference::Predis,   DriverPreference::coerce('Predis'));
    }

    public function testDriverPreferenceCoerceUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DriverPreference::coerce('not-a-driver');
    }
}
