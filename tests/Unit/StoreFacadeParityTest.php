<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Drives the same public-API scenarios through `Store::defaultBackend('table')`
 * and `Store::defaultBackend('redis')` — the parity guarantee that flipping
 * one boot-line preserves every existing call shape.
 */
final class StoreFacadeParityTest extends TestCase
{
    protected function tearDown(): void
    {
        // Always restore the default so other tests aren't affected.
        Store::defaultBackend('table');
    }

    public function testTypeConstantsMatchOpenSwooleTable(): void
    {
        $this->assertSame(\OpenSwoole\Table::TYPE_INT,    Store::TYPE_INT);
        $this->assertSame(\OpenSwoole\Table::TYPE_FLOAT,  Store::TYPE_FLOAT);
        $this->assertSame(\OpenSwoole\Table::TYPE_STRING, Store::TYPE_STRING);
    }

    public function testTableBackendIsDefaultAndExposesTable(): void
    {
        Store::defaultBackend('table');
        $name = 't_' . uniqid();
        $t = Store::make($name, 16, ['v' => [Store::TYPE_STRING, 16]]);
        $this->assertInstanceOf(\OpenSwoole\Table::class, $t);
        $this->assertSame($t, Store::table($name));
    }

    public function testTableBackendGetMissingReturnsFalseForBc(): void
    {
        Store::defaultBackend('table');
        $name = 't_' . uniqid();
        Store::make($name, 16, ['v' => [Store::TYPE_STRING, 16]]);
        // BC contract: Store::get returns false (not null) for missing.
        $this->assertFalse(Store::get($name, 'absent'));
    }

    public function testRedisBackendThrowsOnTableAccessor(): void
    {
        $this->skipIfNoRedis();
        Store::defaultBackend('redis', (string) getenv('ZEALPHP_REDIS_URL'));
        $this->expectException(StoreException::class);
        Store::table('whatever');
    }

    public function testRedisBackendRunsThroughTheFacade(): void
    {
        $this->skipIfNoRedis();
        \OpenSwoole\Coroutine::run(function (): void {
            Store::defaultBackend('redis', [
                'url'    => (string) getenv('ZEALPHP_REDIS_URL'),
                'prefix' => 'zptest-facade',
            ]);
            $name = 't_' . uniqid();
            // No raw Table on Redis; Store::make returns null.
            $this->assertNull(Store::make($name, 16, ['v' => [Store::TYPE_STRING, 32]]));
            $this->assertTrue(Store::set($name, 'k', ['v' => 'hello']));
            $row = Store::get($name, 'k');
            $this->assertIsArray($row);
            $this->assertSame('hello', $row['v']);
            $this->assertTrue(Store::exists($name, 'k'));
            $this->assertSame(1, Store::count($name));
            Store::del($name, 'k');
            $this->assertFalse(Store::exists($name, 'k'));
            $this->assertFalse(Store::get($name, 'k'));
            Store::clear($name);
        });
    }

    public function testRedisBackendPingTrue(): void
    {
        $this->skipIfNoRedis();
        \OpenSwoole\Coroutine::run(function (): void {
            Store::defaultBackend('redis', (string) getenv('ZEALPHP_REDIS_URL'));
            $this->assertTrue(Store::ping());
        });
    }

    public function testUnknownKindRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Store::defaultBackend('nonsense');
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
