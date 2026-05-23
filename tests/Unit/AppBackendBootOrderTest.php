<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\TableBackend;

/**
 * Boot-order contract: ZEALPHP_STORE_BACKEND env var must resolve at
 * App::init() — BEFORE app.php's subsequent Store::make() calls — so the
 * tables land on the resolved backend the first time.
 *
 * Previously the env-var flip ran inside App::run(), AFTER app.php had
 * already registered schemas on the default Table backend. The flip then
 * silently replaced the backend instance, throwing away the schemas.
 * Symptom: /store and /pubsub returned 500 with "table not registered:
 * github_stars" the first time a handler ran on the Redis backend.
 *
 * These tests are isolated (they don't actually call App::init() because
 * that allocates a real Swoole server) — they verify the SAME env-read
 * logic separately on Store + Counter, mirroring what App::init() now does
 * at boot.
 */
final class AppBackendBootOrderTest extends TestCase
{
    private string $prevEnv = '';

    protected function setUp(): void
    {
        $prev = getenv('ZEALPHP_STORE_BACKEND');
        $this->prevEnv = is_string($prev) ? $prev : '';
        putenv('ZEALPHP_STORE_BACKEND');
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        if ($this->prevEnv !== '') {
            putenv('ZEALPHP_STORE_BACKEND=' . $this->prevEnv);
        } else {
            putenv('ZEALPHP_STORE_BACKEND');
        }
    }

    public function testEnvVarFlipsBackendToRedis(): void
    {
        putenv('ZEALPHP_STORE_BACKEND=redis');
        // Mirror App::init()'s env-read logic. The real init() also creates
        // the App singleton + binds Swoole — we exercise only the
        // backend-flip portion here.
        $envKind = getenv('ZEALPHP_STORE_BACKEND');
        if (is_string($envKind) && $envKind !== '') {
            Store::defaultBackend($envKind);
            Counter::defaultBackend($envKind === 'redis' ? 'redis' : 'atomic');
        }
        self::assertInstanceOf(RedisBackend::class, Store::defaultBackend());
    }

    public function testTablesRegisteredAfterEnvFlipLandOnRedis(): void
    {
        // This is the SCENARIO the fix exists for: app.php boot order is
        //   1. env var set externally (Docker/systemd/CLI)
        //   2. App::init() reads it and flips Store::defaultBackend
        //   3. app.php calls Store::make('users', ...) — must land on Redis
        putenv('ZEALPHP_STORE_BACKEND=redis');
        $envKind = getenv('ZEALPHP_STORE_BACKEND');
        if (is_string($envKind) && $envKind !== '') {
            Store::defaultBackend($envKind, 'redis://127.0.0.1:9');  // mock URL
        }
        // Simulating app.php's later make() — schema lands on Redis.
        Store::make('app_table_x', 16, ['v' => [Store::TYPE_STRING, 8]]);
        self::assertContains('app_table_x', Store::names());
        self::assertInstanceOf(RedisBackend::class, Store::defaultBackend());
    }

    public function testEmptyEnvLeavesTableBackend(): void
    {
        putenv('ZEALPHP_STORE_BACKEND');
        $envKind = getenv('ZEALPHP_STORE_BACKEND');
        if (is_string($envKind) && $envKind !== '') {
            Store::defaultBackend($envKind);
        }
        self::assertInstanceOf(TableBackend::class, Store::defaultBackend());
    }
}
