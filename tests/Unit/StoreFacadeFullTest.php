<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;

/**
 * Patch-coverage for the Store facade methods that StoreTest +
 * StoreFacadeParityTest + StoreV040ApiTest don't directly cover —
 * incr/decr/exists/clear/count facade-level delegation + iteratePaged
 * + names + the redisOrThrow private branch behavior surfaced via the
 * public S-1/S-2 entry points.
 */
final class StoreFacadeFullTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Store::make('sfft', 100, [
            'name' => [Store::TYPE_STRING, 32],
            'hits' => [Store::TYPE_INT, 8],
        ]);
    }

    public function testIncrAndDecrThroughFacade(): void
    {
        Store::set('sfft', 'k', ['name' => 'x', 'hits' => 10]);
        $this->assertSame(11, Store::incr('sfft', 'k', 'hits'));
        $this->assertSame(13, Store::incr('sfft', 'k', 'hits', 2));
        $this->assertSame(12, Store::decr('sfft', 'k', 'hits'));
        $this->assertSame(10, Store::decr('sfft', 'k', 'hits', 2));
    }

    public function testExistsAndDel(): void
    {
        Store::set('sfft', 'k', ['name' => 'x', 'hits' => 0]);
        $this->assertTrue(Store::exists('sfft', 'k'));
        $this->assertTrue(Store::del('sfft', 'k'));
        $this->assertFalse(Store::exists('sfft', 'k'));
    }

    public function testCountReturnsRowCount(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Store::set('sfft', "k$i", ['name' => "x$i", 'hits' => $i]);
        }
        $this->assertSame(5, Store::count('sfft'));
    }

    public function testNamesReturnsRegisteredTables(): void
    {
        $names = Store::names();
        $this->assertContains('sfft', $names);
    }

    public function testIterateYieldsRows(): void
    {
        Store::set('sfft', 'a', ['name' => 'A', 'hits' => 1]);
        Store::set('sfft', 'b', ['name' => 'B', 'hits' => 2]);
        $keys = [];
        foreach (Store::iterate('sfft') as $key => $row) {
            $keys[] = $key;
            $this->assertArrayHasKey('name', $row);
        }
        sort($keys);
        $this->assertSame(['a','b'], $keys);
    }

    public function testIteratePagedMultiBatch(): void
    {
        for ($i = 0; $i < 25; $i++) {
            Store::set('sfft', "p$i", ['name' => "n$i", 'hits' => $i]);
        }
        $next = '0';
        $total = 0;
        do {
            $page = Store::iteratePaged('sfft', $next, 7);
            $total += count($page['rows']);
            $next = $page['cursor'];
        } while ($next !== '0');
        $this->assertSame(25, $total);
    }

    public function testIteratePagedCursorZeroOnSingleBatch(): void
    {
        Store::set('sfft', 'a', ['name' => 'A', 'hits' => 1]);
        $page = Store::iteratePaged('sfft', '0', 100);
        $this->assertSame('0', $page['cursor']);
        $this->assertCount(1, $page['rows']);
    }

    public function testClearEmptiesTable(): void
    {
        Store::set('sfft', 'a', ['name' => 'A', 'hits' => 1]);
        Store::set('sfft', 'b', ['name' => 'B', 'hits' => 2]);
        Store::clear('sfft');
        $this->assertSame(0, Store::count('sfft'));
    }

    public function testMgetReturnsKeyedArray(): void
    {
        Store::set('sfft', 'a', ['name' => 'A', 'hits' => 1]);
        Store::set('sfft', 'b', ['name' => 'B', 'hits' => 2]);
        $r = Store::mget('sfft', ['a', 'b', 'missing']);
        $this->assertSame(['a','b','missing'], array_keys($r));
        $this->assertIsArray($r['a']);
        $this->assertIsArray($r['b']);
        $this->assertNull($r['missing']);
    }

    public function testMsetBulkWrites(): void
    {
        $this->assertTrue(Store::mset('sfft', [
            'a' => ['name' => 'A', 'hits' => 1],
            'b' => ['name' => 'B', 'hits' => 2],
            'c' => ['name' => 'C', 'hits' => 3],
        ]));
        $this->assertSame(3, Store::count('sfft'));
        $this->assertSame('A', Store::get('sfft', 'a', 'name'));
    }

    public function testGetFieldReturnsScalar(): void
    {
        Store::set('sfft', 'k', ['name' => 'alice', 'hits' => 42]);
        $this->assertSame('alice', Store::get('sfft', 'k', 'name'));
        $this->assertSame(42,      Store::get('sfft', 'k', 'hits'));
    }

    public function testGetUnknownKeyReturnsFalseForBc(): void
    {
        // Legacy `Store::get()` keeps the `=== false` BC semantics
        // permanently (H2). Use `Store::getStrict()` for null-on-miss
        // in new code.
        $this->assertFalse(Store::get('sfft', 'never-set'));
        $this->assertNull(Store::getStrict('sfft', 'never-set'));
    }

    public function testHasSetOpsFalseOnTable(): void
    {
        $this->assertFalse(Store::hasSetOps());
    }

    public function testStatsReturnsEmptyArrayOnTableBackend(): void
    {
        $s = Store::stats();
        $this->assertSame([], $s, 'Table backend has no stats surface');
    }

    public function testPingOnTableBackendReturnsTrue(): void
    {
        $this->assertTrue(Store::ping());
    }

    public function testTableAccessorReturnsTableInstanceOnTableBackend(): void
    {
        $t = Store::table('sfft');
        $this->assertInstanceOf(\OpenSwoole\Table::class, $t);
    }

    public function testTableAccessorReturnsNullForUnknownTable(): void
    {
        $this->assertNull(Store::table('no-such-table'));
    }
}
