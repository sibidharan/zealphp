<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\StoreBackendKind;
use ZealPHP\Store\TieredBackend;

/**
 * Store::defaultBackend('tiered', …) facade wiring.
 *
 * The tiered backend pairs an L1 TableBackend with an L2 RedisBackend.
 * Construction is lazy — no Redis connection at instance build time, so
 * these tests work without a live Redis. Conn opts mirror the 'redis'
 * dialect (url/pool_size/prefix/prefer) plus 'l1_ttl' and
 * 'invalidation_secret' for the tiered-specific knobs.
 */
final class StoreTieredFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    public function testStringKindBuildsTiered(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, 'redis://127.0.0.1:9');
        self::assertInstanceOf(TieredBackend::class, Store::defaultBackend());
    }

    public function testEnumKindBuildsTiered(): void
    {
        Store::defaultBackend(StoreBackendKind::Tiered, 'redis://127.0.0.1:9');
        self::assertInstanceOf(TieredBackend::class, Store::defaultBackend());
    }

    public function testL1TtlOptIsHonored(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, [
            'url' => 'redis://127.0.0.1:9',
            'l1_ttl' => 30,
        ]);
        $b = Store::defaultBackend();
        self::assertInstanceOf(TieredBackend::class, $b);
        self::assertSame(30, $b->l1Ttl());
    }

    public function testInvalidationSecretOptEnablesAuth(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, [
            'url' => 'redis://127.0.0.1:9',
            'invalidation_secret' => 'cluster-secret',
        ]);
        $b = Store::defaultBackend();
        self::assertInstanceOf(TieredBackend::class, $b);
        self::assertTrue($b->isInvalidationAuthenticated());
    }

    public function testDefaultL1TtlIs5Seconds(): void
    {
        Store::defaultBackend(Store::BACKEND_TIERED, 'redis://127.0.0.1:9');
        $b = Store::defaultBackend();
        self::assertInstanceOf(TieredBackend::class, $b);
        self::assertSame(5, $b->l1Ttl());
    }
}
