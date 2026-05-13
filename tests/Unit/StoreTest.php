<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Store;

class StoreTest extends TestCase
{
    private string $table = 'test_store_' . __CLASS__;

    protected function setUp(): void
    {
        // Each test gets its own table name to avoid collisions
        $this->table = 'test_' . uniqid();
    }

    public function testMakeCreatesTable(): void
    {
        $t = Store::make($this->table, 16, [
            'value' => [\OpenSwoole\Table::TYPE_STRING, 64],
        ]);
        $this->assertInstanceOf(\OpenSwoole\Table::class, $t);
    }

    public function testTableIsRegisteredByName(): void
    {
        Store::make($this->table, 16, ['val' => [\OpenSwoole\Table::TYPE_STRING, 32]]);
        $this->assertContains($this->table, Store::names());
    }

    public function testTableAccessor(): void
    {
        Store::make($this->table, 16, ['val' => [\OpenSwoole\Table::TYPE_STRING, 32]]);
        $this->assertInstanceOf(\OpenSwoole\Table::class, Store::table($this->table));
    }

    public function testSetAndGet(): void
    {
        Store::make($this->table, 16, ['name' => [\OpenSwoole\Table::TYPE_STRING, 64]]);
        Store::set($this->table, 'k1', ['name' => 'alice']);
        $row = Store::get($this->table, 'k1');
        $this->assertSame('alice', $row['name']);
    }

    public function testGetField(): void
    {
        Store::make($this->table, 16, ['score' => [\OpenSwoole\Table::TYPE_INT, 4]]);
        Store::set($this->table, 'u1', ['score' => 42]);
        $this->assertSame(42, Store::get($this->table, 'u1', 'score'));
    }

    public function testExists(): void
    {
        Store::make($this->table, 16, ['v' => [\OpenSwoole\Table::TYPE_STRING, 16]]);
        Store::set($this->table, 'exists_key', ['v' => 'yes']);
        $this->assertTrue(Store::exists($this->table, 'exists_key'));
        $this->assertFalse(Store::exists($this->table, 'no_such_key'));
    }

    public function testDel(): void
    {
        Store::make($this->table, 16, ['v' => [\OpenSwoole\Table::TYPE_STRING, 16]]);
        Store::set($this->table, 'del_key', ['v' => 'x']);
        Store::del($this->table, 'del_key');
        $this->assertFalse(Store::exists($this->table, 'del_key'));
    }

    public function testCount(): void
    {
        Store::make($this->table, 16, ['v' => [\OpenSwoole\Table::TYPE_STRING, 16]]);
        $this->assertSame(0, Store::count($this->table));
        Store::set($this->table, 'a', ['v' => 'x']);
        Store::set($this->table, 'b', ['v' => 'y']);
        $this->assertSame(2, Store::count($this->table));
    }

    public function testIncr(): void
    {
        Store::make($this->table, 16, ['hits' => [\OpenSwoole\Table::TYPE_INT, 8]]);
        Store::set($this->table, 'page', ['hits' => 0]);
        $new = Store::incr($this->table, 'page', 'hits');
        $this->assertSame(1, $new);
        $new = Store::incr($this->table, 'page', 'hits', 5);
        $this->assertSame(6, $new);
    }

    public function testDecr(): void
    {
        Store::make($this->table, 16, ['stock' => [\OpenSwoole\Table::TYPE_INT, 8]]);
        Store::set($this->table, 'item', ['stock' => 10]);
        $new = Store::decr($this->table, 'item', 'stock', 3);
        $this->assertSame(7, $new);
    }

    public function testIteration(): void
    {
        Store::make($this->table, 16, ['v' => [\OpenSwoole\Table::TYPE_STRING, 16]]);
        Store::set($this->table, 'x', ['v' => 'hello']);
        Store::set($this->table, 'y', ['v' => 'world']);
        $keys = [];
        foreach (Store::table($this->table) as $key => $row) {
            $keys[] = $key;
        }
        $this->assertContains('x', $keys);
        $this->assertContains('y', $keys);
    }

    public function testMissingTableReturnsNull(): void
    {
        $this->assertNull(Store::table('does_not_exist_xyz'));
    }
}
