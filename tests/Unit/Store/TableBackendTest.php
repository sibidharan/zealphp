<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\TableBackend;

final class TableBackendTest extends TestCase
{
    public function testSetGetTypedRow(): void
    {
        $b = new TableBackend();
        $b->make('users', 100, [
            'name' => [Table::TYPE_STRING, 32],
            'age'  => [Table::TYPE_INT,    4],
        ]);
        $this->assertTrue($b->set('users', 'alice', ['name' => 'Alice', 'age' => 30]));
        $row = $b->get('users', 'alice');
        $this->assertIsArray($row);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame(30, $row['age']);
    }

    public function testGetReturnsNullOnMissingKey(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 16]]);
        $this->assertNull($b->get('t', 'nope'));
        $this->assertNull($b->get('t', 'nope', 'v'));
    }

    public function testGetReturnsNullForUnknownTable(): void
    {
        $b = new TableBackend();
        $this->assertNull($b->get('absent', 'k'));
    }

    public function testSetReturnsFalseForUnknownTable(): void
    {
        $b = new TableBackend();
        $this->assertFalse($b->set('absent', 'k', ['v' => 'x']));
    }

    public function testFieldRead(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 32]]);
        $b->set('t', 'k1', ['v' => 'hello']);
        $this->assertSame('hello', $b->get('t', 'k1', 'v'));
    }

    public function testIncrDecrReturnsNewValue(): void
    {
        $b = new TableBackend();
        $b->make('hits', 10, ['n' => [Table::TYPE_INT, 4]]);
        $b->set('hits', 'k', ['n' => 0]);
        $this->assertSame(5, $b->incr('hits', 'k', 'n', 5));
        $this->assertSame(7, $b->incr('hits', 'k', 'n', 2));
        $this->assertSame(6, $b->decr('hits', 'k', 'n', 1));
    }

    public function testIncrOnMissingTableReturnsZero(): void
    {
        $b = new TableBackend();
        $this->assertSame(0, $b->incr('absent', 'k', 'n'));
        $this->assertSame(0, $b->decr('absent', 'k', 'n'));
    }

    public function testDelExistsAndCount(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 16]]);
        $b->set('t', 'a', ['v' => 'x']);
        $b->set('t', 'b', ['v' => 'y']);
        $this->assertSame(2, $b->count('t'));
        $this->assertTrue($b->exists('t', 'a'));
        $this->assertTrue($b->del('t', 'a'));
        $this->assertFalse($b->exists('t', 'a'));
        $this->assertSame(1, $b->count('t'));
    }

    public function testIterateYieldsAllRows(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 16]]);
        $b->set('t', 'a', ['v' => 'alpha']);
        $b->set('t', 'b', ['v' => 'beta']);
        $rows = [];
        foreach ($b->iterate('t') as $key => $row) { $rows[$key] = $row['v']; }
        ksort($rows);
        $this->assertSame(['a' => 'alpha', 'b' => 'beta'], $rows);
    }

    public function testClearWipesAllRows(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 16]]);
        $b->set('t', 'a', ['v' => 'x']);
        $b->set('t', 'b', ['v' => 'y']);
        $b->set('t', 'c', ['v' => 'z']);
        $this->assertSame(3, $b->count('t'));
        $b->clear('t');
        $this->assertSame(0, $b->count('t'));
    }

    public function testNamesListsAllRegisteredTables(): void
    {
        $b = new TableBackend();
        $b->make('t1', 10, ['v' => [Table::TYPE_STRING, 8]]);
        $b->make('t2', 10, ['v' => [Table::TYPE_STRING, 8]]);
        $names = $b->names();
        sort($names);
        $this->assertSame(['t1', 't2'], $names);
    }

    public function testRawTableReturnsUnderlyingTable(): void
    {
        $b = new TableBackend();
        $b->make('t', 10, ['v' => [Table::TYPE_STRING, 8]]);
        $this->assertInstanceOf(Table::class, $b->rawTable('t'));
        $this->assertNull($b->rawTable('absent'));
    }

    public function testMakeWithEmptyColumnsCreatesValueColumn(): void
    {
        // BC with the current Store::make() behaviour.
        $b = new TableBackend();
        $b->make('t', 10, []);
        $this->assertTrue($b->set('t', 'k', ['value' => 'hi']));
        $row = $b->get('t', 'k');
        $this->assertIsArray($row);
        $this->assertSame('hi', $row['value']);
    }
}
