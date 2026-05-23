<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Store;

/**
 * H6 + H7 boot-time self-checks.
 *
 * - H6: ping the Redis backend at boot; warn on failure.
 * - H7: warn when phpredis + HOOK_ALL=0 + registered pubsub handlers.
 *
 * The checks run from `App::run()` once the backend has resolved. The
 * standalone `App::redisBootChecks()` is the testing surface.
 */
final class AppRedisBootChecksTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        // Reset pubsub registry so tests don't leak across cases.
        $r = new \ReflectionProperty(App::class, 'pubsubRegistry');
        $r->setAccessible(true);
        $r->setValue(null, []);
        $r2 = new \ReflectionProperty(App::class, 'reliableRegistry');
        $r2->setAccessible(true);
        $r2->setValue(null, []);
        putenv('ZEALPHP_REDIS_PREFER');
    }

    public function testTableBackendProducesNoWarnings(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        self::assertSame([], App::redisBootChecks());
    }

    public function testUnreachableRedisProducesH6Warning(): void
    {
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:9');
        $w = App::redisBootChecks();
        self::assertNotEmpty($w);
        self::assertStringContainsString('H6', $w[0]);
        self::assertStringContainsString('FAILED', $w[0]);
    }

    public function testHookAllZeroPlusPhpredisPlusSubscribersTriggersH7(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('phpredis ext-redis not loaded — H7 condition cannot fire');
        }
        // Force HOOK_ALL=0 so the H7 condition fires.
        App::hookAll(false);
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:16379/0');

        // Register a pubsub handler so the "hasSubscribers" branch is true.
        App::onPubSub('unit-test:h7', function (): void {});

        $w = App::redisBootChecks();
        $h7 = array_filter($w, fn(string $m): bool => str_contains($m, 'H7'));
        self::assertNotEmpty($h7, 'H7 must fire when phpredis + HOOK_ALL=0 + subscriber present');

        // Reset hookAll for subsequent tests
        App::hookAll(null);
    }

    public function testForcedPredisSilencesH7Warning(): void
    {
        App::hookAll(false);
        putenv('ZEALPHP_REDIS_PREFER=predis');
        Store::defaultBackend(Store::BACKEND_REDIS, 'redis://127.0.0.1:16379/0');
        App::onPubSub('unit-test:h7-silenced', function (): void {});

        $w = App::redisBootChecks();
        $h7 = array_filter($w, fn(string $m): bool => str_contains($m, 'H7'));
        self::assertSame([], $h7, 'predis forced → no H7 warning');

        App::hookAll(null);
    }
}
