<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\TableBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * mget/mset parity across TableBackend and RedisBackend. Every
 * assertion runs against both backends — the Redis path skips
 * automatically if valkey isn't reachable.
 */
final class BulkOpsTest extends RedisTestCase
{
    /** @return list<array{0:string, 1: callable(): StoreBackend}> */
    public static function backendFactories(): array
    {
        return [
            ['table', fn(): StoreBackend => new TableBackend()],
            ['redis', fn(): StoreBackend => new RedisBackend(
                new RedisConnectionPool(getenv('ZEALPHP_REDIS_URL') ?: 'redis://127.0.0.1:16379/0', 4),
                'zptest-bulk',
            )],
        ];
    }

    /**
     * Each test runs a closure once per backend. For Redis, wraps the
     * call in Coroutine::run; for Table runs directly. Keeps the
     * assertions simple.
     */
    private function eachBackend(callable $assertions): void
    {
        foreach (self::backendFactories() as [$kind, $factory]) {
            $b = $factory();
            $run = function () use ($b, $kind, $assertions): void { $assertions($b, $kind); };
            if ($kind === 'redis') {
                \OpenSwoole\Coroutine::run($run);
            } else {
                $run();
            }
        }
    }

    public function testMgetReturnsRowsInOrderWithNullForMissing(): void
    {
        $this->eachBackend(function (StoreBackend $b, string $kind): void {
            $b->make('users', 100, ['name' => [Table::TYPE_STRING, 32]]);
            $b->set('users', 'a', ['name' => 'Alice']);
            $b->set('users', 'b', ['name' => 'Bob']);
            $rows = $b->mget('users', ['a', 'b', 'missing']);
            $this->assertSame(['name' => 'Alice'], $rows['a'], "$kind: row a");
            $this->assertSame(['name' => 'Bob'],   $rows['b'], "$kind: row b");
            $this->assertNull($rows['missing'], "$kind: missing key is null");
        });
    }

    public function testMsetWritesAllAtomicallyVisible(): void
    {
        $this->eachBackend(function (StoreBackend $b, string $kind): void {
            $b->make('catalog', 100, ['title' => [Table::TYPE_STRING, 32], 'price' => [Table::TYPE_INT, 4]]);
            $ok = $b->mset('catalog', [
                'sku1' => ['title' => 'A', 'price' => 10],
                'sku2' => ['title' => 'B', 'price' => 20],
                'sku3' => ['title' => 'C', 'price' => 30],
            ]);
            $this->assertTrue($ok, "$kind: mset returns true on success");
            $this->assertSame(3, $b->count('catalog'), "$kind: count after mset");

            $rows = $b->mget('catalog', ['sku1', 'sku2', 'sku3']);
            $this->assertSame(['title' => 'A', 'price' => 10], $rows['sku1'], "$kind: sku1");
            $this->assertSame(['title' => 'B', 'price' => 20], $rows['sku2'], "$kind: sku2");
            $this->assertSame(['title' => 'C', 'price' => 30], $rows['sku3'], "$kind: sku3");
        });
    }

    public function testMgetWithEmptyKeysListReturnsEmptyArray(): void
    {
        $this->eachBackend(function (StoreBackend $b, string $kind): void {
            $b->make('t', 10, ['v' => [Table::TYPE_STRING, 8]]);
            $this->assertSame([], $b->mget('t', []), "$kind: empty input");
        });
    }

    public function testMsetWithEmptyMapReturnsTrueWithoutSideEffects(): void
    {
        $this->eachBackend(function (StoreBackend $b, string $kind): void {
            $b->make('t', 10, ['v' => [Table::TYPE_STRING, 8]]);
            $this->assertTrue($b->mset('t', []), "$kind: empty mset");
            $this->assertSame(0, $b->count('t'), "$kind: nothing written");
        });
    }
}
