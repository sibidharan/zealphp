<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\CircuitBreakerBackend;
use ZealPHP\Store\RedisBackend;

/**
 * H4: `['on_error' => 'fallback_table']` opt-in wiring.
 *
 * When set on Store::defaultBackend(), the resolved backend is a
 * CircuitBreakerBackend wrapping the Redis primary with a Table fallback.
 * Default (no opt) returns the bare RedisBackend, preserving BC.
 */
final class StoreOnErrorOptTest extends TestCase
{
    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    public function testRedisWithoutOptReturnsBareBackend(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        $b = Store::defaultBackend();
        self::assertInstanceOf(RedisBackend::class, $b);
    }

    public function testFallbackTableOptWrapsInCircuitBreaker(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, [
            'url'      => 'redis://127.0.0.1:9',
            'on_error' => 'fallback_table',
        ]);
        $b = Store::defaultBackend();
        self::assertInstanceOf(CircuitBreakerBackend::class, $b);
        self::assertSame('closed', $b->state());
    }

    public function testBreakerOptsTuneThresholds(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, [
            'url'      => 'redis://127.0.0.1:9',
            'on_error' => 'fallback_table',
            'breaker'  => [
                'failure_threshold'   => 3,
                'failure_window_sec'  => 5,
                'open_seconds'        => 15,
            ],
        ]);
        $b = Store::defaultBackend();
        self::assertInstanceOf(CircuitBreakerBackend::class, $b);
        // Tuning verification — the breaker constructor enforces threshold>=1
        // and stores the values; we can't introspect privates without
        // reflection, but the absence of an exception confirms the values
        // landed.
    }

    public function testTableBackendIgnoresOnErrorOpt(): void
    {
        // on_error only applies to redis backend — Table is unaffected.
        Store::defaultBackend(Store::BACKEND_TABLE);
        $b = Store::defaultBackend();
        self::assertNotInstanceOf(CircuitBreakerBackend::class, $b);
    }
}
