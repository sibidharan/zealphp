<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\TypeCodec;

final class TypeCodecTest extends TestCase
{
    /** @return array<string, array{0:int, 1?:int}> */
    private function userSchema(): array
    {
        return [
            'name'  => [Table::TYPE_STRING, 32],
            'age'   => [Table::TYPE_INT,    4],
            'score' => [Table::TYPE_FLOAT,  8],
        ];
    }

    public function testEncodeRowCastsEverythingToString(): void
    {
        $codec = new TypeCodec();
        $wire  = $codec->encodeRow($this->userSchema(), ['name' => 'Alice', 'age' => 30, 'score' => 4.5]);
        $this->assertSame(['name' => 'Alice', 'age' => '30', 'score' => '4.5'], $wire);
    }

    public function testEncodeRowSerialisesBoolsAsZeroOne(): void
    {
        $codec = new TypeCodec();
        $wire  = $codec->encodeRow(['flag' => [Table::TYPE_INT, 1]], ['flag' => true]);
        $this->assertSame(['flag' => '1'], $wire);
        $wire = $codec->encodeRow(['flag' => [Table::TYPE_INT, 1]], ['flag' => false]);
        $this->assertSame(['flag' => '0'], $wire);
    }

    public function testRoundTripPreservesTypes(): void
    {
        $codec  = new TypeCodec();
        $schema = $this->userSchema();
        $row    = ['name' => 'Alice', 'age' => 30, 'score' => 4.5];
        $wire   = $codec->encodeRow($schema, $row);
        $back   = $codec->decodeRow($schema, $wire);
        $this->assertSame($row, $back);
    }

    public function testDecodeRowReturnsNullForEmptyWire(): void
    {
        $codec = new TypeCodec();
        $this->assertNull($codec->decodeRow($this->userSchema(), []));
    }

    public function testDecodeRowSubstitutesZeroValueForMissingField(): void
    {
        // OpenSwoole\Table behaviour: a row with column 'age' unset reads back as 0.
        $codec = new TypeCodec();
        $back  = $codec->decodeRow($this->userSchema(), ['name' => 'Bob']);
        $this->assertSame(['name' => 'Bob', 'age' => 0, 'score' => 0.0], $back);
    }

    public function testDecodeFieldByType(): void
    {
        $codec = new TypeCodec();
        $this->assertSame(42,    $codec->decodeField(Table::TYPE_INT,    '42'));
        $this->assertSame(3.14,  $codec->decodeField(Table::TYPE_FLOAT,  '3.14'));
        $this->assertSame('hi',  $codec->decodeField(Table::TYPE_STRING, 'hi'));
    }

    public function testDecodeFieldMissingValueIsTypedZero(): void
    {
        $codec = new TypeCodec();
        $this->assertSame(0,    $codec->decodeField(Table::TYPE_INT,    null));
        $this->assertSame(0.0,  $codec->decodeField(Table::TYPE_FLOAT,  null));
        $this->assertSame('',   $codec->decodeField(Table::TYPE_STRING, null));
    }

    public function testIntegerOverflowPreservedByCastingChain(): void
    {
        // 8-byte int column; encoder writes the int as decimal text, decoder
        // restores it via (int) cast. Up to PHP_INT_MAX.
        $codec  = new TypeCodec();
        $schema = ['big' => [Table::TYPE_INT, 8]];
        $row    = ['big' => PHP_INT_MAX];
        $back   = $codec->decodeRow($schema, $codec->encodeRow($schema, $row));
        $this->assertSame($row, $back);
    }

    public function testFloatPrecisionWithinReason(): void
    {
        $codec  = new TypeCodec();
        $schema = ['f' => [Table::TYPE_FLOAT, 8]];
        $row    = ['f' => 1.0 / 3.0];
        $back   = $codec->decodeRow($schema, $codec->encodeRow($schema, $row));
        $this->assertNotNull($back);
        $this->assertEqualsWithDelta($row['f'], $back['f'], 1e-12);
    }
}
