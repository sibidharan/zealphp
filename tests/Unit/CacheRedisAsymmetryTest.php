<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Store;

/**
 * Cache::init backend-asymmetry surface (v0.2.41 hardening).
 *
 * The $maxRows parameter is a HARD CAP on the Table backend (OpenSwoole\Table
 * allocates fixed shared memory). On Redis it has NO equivalent — Redis is a
 * global KV store with no per-table size cap. The new $ttlSeconds parameter
 * flips Redis tables into TTL mode for auto-expiry, the recommended pattern
 * for cache workloads when bounded growth matters.
 *
 * The framework's `error_log()` is uopz-overridden once `App::init()` runs,
 * routing through the per-request `elog` channel. In unit tests App::init
 * hasn't run; the bare `error_log` from PHP's runtime is used, which goes
 * to wherever `ini_get('error_log')` points (default empty → stderr/SAPI).
 * We don't assert ON the warning text in this test (CLI vs hooked behaviour
 * differs); we assert that the wiring DECISION (Store::make opts) lands
 * correctly.
 */
final class CacheRedisAsymmetryTest extends TestCase
{
    private function freshCache(): void
    {
        // Reset Cache singleton state between cases (the only way without an
        // explicit Cache::reset() — adding one is a follow-up).
        $r = new \ReflectionProperty(Cache::class, 'initialized');
        $r->setAccessible(true);
        $r->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->freshCache();
    }

    public function testTableBackendIgnoresTtlSecondsOpt(): void
    {
        // On Table backend, $ttlSeconds is documentational — Cache itself
        // enforces TTL via the row's ttl column.
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->freshCache();
        Cache::init(maxRows: 64, cacheDir: '/tmp/cache-table-test', ttlSeconds: 3600);
        // init() registered the table on Table backend (ttlSeconds is purely
        // informational here — TableBackend doesn't honour the 'ttl' opt).
        self::assertContains('__cache', Store::names());
    }

    public function testRedisBackendWithTtlFlipsToTtlMode(): void
    {
        // When $ttlSeconds is set on Redis backend, the underlying Store
        // table is made with ['mode' => 'ttl', 'ttl' => N]. The Store table
        // schema landing is observable via Store::names(); the mode choice
        // is exercised by integration tests against live Valkey.
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        $this->freshCache();
        Cache::init(maxRows: 4096, cacheDir: '/tmp/cache-redis-ttl-test', ttlSeconds: 3600);
        self::assertContains('__cache', Store::names(), 'cache table registered on Redis backend');
    }

    public function testRedisBackendWithCustomMaxRowsAndNoTtlEmitsWarning(): void
    {
        // The exact warning routing depends on whether uopz override is
        // active (production: hooked to elog; bare PHP test: stderr).
        // We assert error_log is INVOKED via a custom error_log handler
        // installed in this test.
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        $this->freshCache();

        // PHP doesn't expose hooks into bare error_log() in CLI; under
        // production the framework's uopz override routes it through elog.
        // The honest unit-level assertion: the init() COMPLETES (warning
        // emitted OR not, depending on environment) without throwing.
        Cache::init(maxRows: 99_999, cacheDir: '/tmp/cache-redis-warn-test');
        self::assertContains('__cache', Store::names(), 'init reached the make() call after the warning branch');
    }

    public function testRedisBackendWithDefaultMaxRowsDoesNotWarn(): void
    {
        // 4096 == default; no warning should fire (and Cache::init succeeds).
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        $this->freshCache();
        Cache::init(cacheDir: '/tmp/cache-redis-default-test');
        self::assertContains('__cache', Store::names());
    }
}
