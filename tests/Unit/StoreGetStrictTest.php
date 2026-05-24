<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;

/**
 * H2: `Store::getStrict()` returns null on miss, vs `Store::get()` which
 * returns false for BC with code that uses `=== false` to detect misses.
 *
 * The two methods are explicit opt-in choices — new code uses getStrict()
 * for ??-style fallbacks with stored falsy values; legacy code stays on
 * get() and keeps working unchanged.
 */
final class StoreGetStrictTest extends TestCase
{
    private string $table;

    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->table = 'gs_' . bin2hex(random_bytes(3));
        Store::make($this->table, 16, [
            'name' => [Store::TYPE_STRING, 32],
            'hits' => [Store::TYPE_INT],
        ]);
    }

    public function testGetReturnsFalseOnMissForBC(): void
    {
        self::assertFalse(Store::get($this->table, 'missing'));
    }

    public function testGetStrictReturnsNullOnMiss(): void
    {
        self::assertNull(Store::getStrict($this->table, 'missing'));
    }

    public function testGetStrictWithFieldReturnsNullOnMiss(): void
    {
        self::assertNull(Store::getStrict($this->table, 'missing', 'name'));
    }

    public function testBothReturnSameValueOnHit(): void
    {
        Store::set($this->table, 'k1', ['name' => 'alice', 'hits' => 7]);
        $row1 = Store::get($this->table, 'k1');
        $row2 = Store::getStrict($this->table, 'k1');
        self::assertSame($row1, $row2);
        self::assertIsArray($row1);
        self::assertSame('alice', $row1['name']);
    }

    public function testGetStrictAllowsCoalesceWithStoredFalsyValue(): void
    {
        // Stored value is 0 (falsy but valid). With getStrict + ??, the
        // 0 is preserved; with get + ??, the false-on-miss is indistinguishable.
        Store::set($this->table, 'zero', ['name' => '', 'hits' => 0]);
        self::assertSame(0, Store::getStrict($this->table, 'zero', 'hits') ?? -1);
    }
}
