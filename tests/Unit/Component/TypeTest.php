<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZealPHP\Component\Type;
use ZealPHP\Tests\Unit\Component\Fixtures\Plain;
use ZealPHP\Tests\Unit\Component\Fixtures\Priority;
use ZealPHP\Tests\Unit\Component\Fixtures\Size;

class TypeTest extends TestCase
{
    private static function type(string $php): Type
    {
        $fn = eval("return fn({$php} \$x) => null;");
        $param = (new \ReflectionFunction($fn))->getParameters()[0];
        return Type::fromReflection($param->getType(), 'test');
    }

    /** @return iterable<string, array{string, mixed, mixed}> */
    public static function conversions(): iterable
    {
        yield 'string' => ['string', 'abc', 'abc'];
        yield 'string from int' => ['string', 5, '5'];
        yield 'int' => ['int', '42', 42];
        yield 'int negative padded' => ['int', ' -7 ', -7];
        yield 'int native' => ['int', 3, 3];
        yield 'float' => ['float', '1.5', 1.5];
        yield 'float from int' => ['float', 2, 2.0];
        yield 'bool on' => ['bool', 'on', true];
        yield 'bool 1' => ['bool', '1', true];
        yield 'bool empty' => ['bool', '', false];
        yield 'bool off' => ['bool', 'off', false];
        yield 'bool int' => ['bool', 0, false];
        yield 'array' => ['array', ['a', ['b' => 1]], ['a', ['b' => 1]]];
        yield 'nullable int empty' => ['?int', '', null];
        yield 'nullable null' => ['?string', null, null];
        yield 'nullable string keeps empty' => ['?string', '', ''];
        yield 'enum' => [Size::class, 'l', Size::Large];
        yield 'enum instance' => [Size::class, Size::Small, Size::Small];
        yield 'nullable enum empty' => ['?' . Size::class, '', null];
        yield 'bool padded upper' => ['bool', ' ON ', true];
        yield 'bool yes' => ['bool', 'yes', true];
        yield 'bool no' => ['bool', 'NO', false];
        yield 'int enum from string' => [Priority::class, '9', Priority::High];
        yield 'int enum from int' => [Priority::class, 1, Priority::Low];
        yield 'array depth 4' => ['array', [[['x']]], [[['x']]]];
        yield 'utf-8 string' => ['string', 'café', 'café'];
    }

    #[DataProvider('conversions')]
    public function testFromInputConverts(string $php, mixed $in, mixed $out): void
    {
        $this->assertSame($out, self::type($php)->fromInput($in));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function rejections(): iterable
    {
        yield 'int from text' => ['int', 'abc'];
        yield 'int from float text' => ['int', '1.5'];
        yield 'int overflow' => ['int', '9999999999999999999'];
        yield 'float from text' => ['float', 'x'];
        yield 'bool from text' => ['bool', 'maybe'];
        yield 'string from array' => ['string', ['a']];
        yield 'array from string' => ['array', 'a'];
        yield 'array holding object' => ['array', [new \stdClass()]];
        yield 'array too deep' => ['array', [[[[['x']]]]]];
        yield 'enum unknown' => [Size::class, 'zz'];
        yield 'enum from array' => [Size::class, ['s']];
        yield 'null for non-nullable' => ['int', null];
        yield 'int enum from non-numeric string' => [Priority::class, 'high'];
        yield 'int enum unknown' => [Priority::class, '5'];
        yield 'int enum trailing newline' => [Priority::class, "9\n"];
        yield 'invalid utf-8 string' => ['string', "\xC3\x28"];
        yield 'array with invalid utf-8' => ['array', ["\xFF"]];
        yield 'array with invalid utf-8 key' => ['array', ["\xFF" => 'a']];
        yield 'array with INF' => ['array', [INF]];
        yield 'infinite float' => ['float', '1e999'];
        yield 'NAN float' => ['float', NAN];
        yield 'int from bool' => ['int', true];
    }

    #[DataProvider('rejections')]
    public function testFromInputRejects(string $php, mixed $in): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::type($php)->fromInput($in);
    }

    /** @return iterable<string, array{string}> */
    public static function disallowedTypes(): iterable
    {
        yield 'object' => ['\stdClass'];
        yield 'union' => ['int|string'];
        yield 'mixed' => ['mixed'];
        yield 'callable' => ['callable'];
    }

    #[DataProvider('disallowedTypes')]
    public function testDisallowedDeclaredTypes(string $php): void
    {
        $this->expectException(\LogicException::class);
        self::type($php);
    }

    public function testPureEnumsAreDisallowed(): void
    {
        $this->expectException(\LogicException::class);
        self::type(Plain::class);
    }

    public function testIntFromSnapshotStaysInt(): void
    {
        $this->assertSame(1, self::type('int')->fromSnapshot(1));
        $this->assertSame(Priority::High, self::type(Priority::class)->fromSnapshot(9));
    }

    public function testSnapshotRefusesInfAndInvalidUtf8(): void
    {
        foreach ([[self::type('float'), INF], [self::type('string'), "\xFF"], [self::type('array'), ['a' => NAN]]] as [$type, $value]) {
            try {
                $type->toSnapshot($value, 'w');
                $this->fail('accepted ' . var_export($value, true));
            } catch (\LogicException $e) {
                $this->assertStringStartsWith('w: ', $e->getMessage());
            }
        }
    }

    public function testUntypedIsDisallowed(): void
    {
        $this->expectException(\LogicException::class);
        Type::fromReflection(null, 'test');
    }

    public function testSnapshotRoundTrip(): void
    {
        $enum = self::type(Size::class);
        $this->assertSame('l', $enum->toSnapshot(Size::Large, 'w'));
        $this->assertSame(Size::Large, $enum->fromSnapshot('l'));
        $this->assertSame(1.0, self::type('float')->fromSnapshot(1));
        $this->assertNull(self::type('?int')->toSnapshot(null, 'w'));
        $this->assertSame([1, 'a'], self::type('array')->toSnapshot([1, 'a'], 'w'));
    }

    public function testSnapshotRejectsObjectsAndNullForNonNullable(): void
    {
        try {
            self::type('array')->toSnapshot([new \stdClass()], 'w');
            $this->fail('object in array accepted');
        } catch (\LogicException) {
        }
        try {
            self::type('string')->toSnapshot(new \stdClass(), 'w');
            $this->fail('object accepted');
        } catch (\LogicException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        self::type('int')->fromSnapshot(null);
    }

    public function testDescribe(): void
    {
        $this->assertSame('?int', self::type('?int')->describe());
        $this->assertSame(Size::class, self::type(Size::class)->describe());
    }
}
