<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\StoreException;

/**
 * Covers `Store::defaultBackend()` and `Counter::defaultBackend()`
 * array-shape config branches — `redis`, `tiered`, `memcached` —
 * including `DriverPreference` enum + string coercion and the
 * silent fallback when an unknown preference string is passed.
 *
 * Connection is deferred until first use, so config-time tests can
 * point at an intentionally-unreachable Redis port without flaking.
 */
final class DefaultBackendConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    // ── Store::defaultBackend('memcached', …) ───────────────────────

    public function testStoreMemcachedReadsEnvServers(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Store::defaultBackend('memcached');
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    public function testStoreMemcachedAcceptsArrayConfig(): void
    {
        try {
            Store::defaultBackend('memcached', ['servers' => '127.0.0.1:1', 'prefix' => 'zptest']);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testStoreMemcachedArrayWithoutServersFallsBackToEnv(): void
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

    // ── Store::defaultBackend('redis', […]) ─────────────────────────

    public function testStoreRedisArrayWithPreferString(): void
    {
        Store::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreRedisArrayWithPreferEnum(): void
    {
        Store::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => DriverPreference::Auto,
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreRedisArrayWithInvalidPreferFallsBack(): void
    {
        // L138 — InvalidArgumentException from DriverPreference::coerce
        // is silently caught so an unknown 'prefer' string degrades to
        // the env default rather than crashing boot.
        Store::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'nonsense-driver',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── Store::defaultBackend('tiered', […]) ────────────────────────

    public function testStoreTieredAcceptsFullArrayConfig(): void
    {
        Store::defaultBackend('tiered', [
            'url'                 => 'redis://127.0.0.1:65000/0',
            'pool_size'           => 4,
            'prefix'              => 'zptest',
            'l1_ttl'              => 10,
            'invalidation_secret' => 'shared-secret-xyz',
            'prefer'              => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testStoreTieredWithInvalidPreferFallsBack(): void
    {
        Store::defaultBackend('tiered', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'unknown-driver',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── Counter::defaultBackend(…) array variants ───────────────────

    public function testCounterMemcachedReadsEnvServers(): void
    {
        putenv('ZEALPHP_MEMCACHED_SERVERS=127.0.0.1:1');
        try {
            try {
                Counter::defaultBackend('memcached');
                $this->addToAssertionCount(1);
            } catch (StoreException $e) {
                $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
            }
        } finally {
            putenv('ZEALPHP_MEMCACHED_SERVERS');
        }
    }

    public function testCounterMemcachedAcceptsArrayConfig(): void
    {
        try {
            Counter::defaultBackend('memcached', ['servers' => '127.0.0.1:1', 'prefix' => 'zptest']);
            $this->addToAssertionCount(1);
        } catch (StoreException $e) {
            $this->assertMatchesRegularExpression('/memcached/i', $e->getMessage());
        }
    }

    public function testCounterRedisArrayShape(): void
    {
        Counter::defaultBackend('redis', [
            'url'       => 'redis://127.0.0.1:65000/0',
            'pool_size' => 4,
            'prefix'    => 'zptest',
            'prefer'    => 'predis',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testCounterRedisInvalidPreferFallsBack(): void
    {
        Counter::defaultBackend('redis', [
            'url'    => 'redis://127.0.0.1:65000/0',
            'prefer' => 'unknown-driver',
        ]);
        $this->addToAssertionCount(1);
    }

    // ── DriverPreference::coerce — enum / string / case / unknown ──

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
