<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CgiMode;
use ZealPHP\Counter;
use ZealPHP\Counter\CounterBackendKind;
use ZealPHP\Store;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\StoreBackendKind;

/**
 * Pins the v0.2.40 enum API: Store::defaultBackend, Counter::defaultBackend,
 * App::cgiMode all accept the enum form AND the legacy bare string form.
 */
final class EnumApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Store::defaultBackend('table');
        Counter::defaultBackend('atomic');
        App::cgiMode('proc');
    }

    public function testStoreBackendKindEnumCases(): void
    {
        $this->assertSame('table', StoreBackendKind::Table->value);
        $this->assertSame('redis', StoreBackendKind::Redis->value);
        $this->assertSame(StoreBackendKind::Table, StoreBackendKind::coerce('table'));
        $this->assertSame(StoreBackendKind::Redis, StoreBackendKind::coerce('REDIS'));   // case-insensitive
        $this->assertSame(StoreBackendKind::Redis, StoreBackendKind::coerce(StoreBackendKind::Redis));
    }

    public function testStoreBackendKindCoerceRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StoreBackendKind::coerce('nonsense');
    }

    public function testStoreDefaultBackendAcceptsEnum(): void
    {
        Store::defaultBackend(StoreBackendKind::Table);
        $this->assertInstanceOf(\ZealPHP\Store\TableBackend::class, Store::defaultBackend());
    }

    public function testStoreDefaultBackendStillAcceptsLegacyString(): void
    {
        Store::defaultBackend('table');
        $this->assertInstanceOf(\ZealPHP\Store\TableBackend::class, Store::defaultBackend());
    }

    public function testCounterBackendKindEnumCases(): void
    {
        $this->assertSame('atomic', CounterBackendKind::Atomic->value);
        $this->assertSame('redis',  CounterBackendKind::Redis->value);
        $this->assertSame(CounterBackendKind::Atomic, CounterBackendKind::coerce('atomic'));
    }

    public function testCounterDefaultBackendAcceptsEnum(): void
    {
        Counter::defaultBackend(CounterBackendKind::Atomic);
        $this->assertInstanceOf(\ZealPHP\Counter\AtomicBackend::class, Counter::defaultBackend());
    }

    public function testDriverPreferenceEnumCases(): void
    {
        $this->assertSame('auto',     DriverPreference::Auto->value);
        $this->assertSame('phpredis', DriverPreference::Phpredis->value);
        $this->assertSame('predis',   DriverPreference::Predis->value);
    }

    public function testStorePreferAcceptsEnumViaConnOpts(): void
    {
        // Backend is built lazily — the prefer must round-trip into the pool opts.
        Store::defaultBackend(StoreBackendKind::Redis, [
            'url'    => 'redis://127.0.0.1:16379/0',
            'prefer' => DriverPreference::Predis,
        ]);
        $b = Store::defaultBackend();
        $this->assertInstanceOf(\ZealPHP\Store\RedisBackend::class, $b);
    }

    public function testCgiModeEnumCases(): void
    {
        $this->assertSame('pool', CgiMode::Pool->value);
        $this->assertSame('proc', CgiMode::Proc->value);
        $this->assertSame('fcgi', CgiMode::Fcgi->value);
        $this->assertSame(CgiMode::Proc, CgiMode::coerce('proc'));
        $this->assertSame(CgiMode::Fcgi, CgiMode::coerce('FCGI'));
    }

    public function testCgiModeAcceptsEnum(): void
    {
        App::cgiMode(CgiMode::Pool);
        $this->assertSame('pool', App::cgiMode());
        App::cgiMode(CgiMode::Fcgi);
        $this->assertSame('fcgi', App::cgiMode());
    }

    public function testCgiModeAcceptsLegacyString(): void
    {
        App::cgiMode('pool');
        $this->assertSame('pool', App::cgiMode());
    }

    public function testCgiModeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::cgiMode('nonsense');
    }
}
