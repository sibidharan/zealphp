<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Tests for App::functionIsolation() and App::$function_isolation.
 *
 * functionIsolation() follows the same fluent getter/setter contract as the
 * other App configurables (defineIsolation, includeIsolation, etc.): a no-arg
 * call returns the current value; a one-arg call sets it and returns the new
 * value. It gates the per-request function/class/include cleanup via
 * ext-zealphp's process-state snapshot/clean.
 */
final class FunctionIsolationTest extends TestCase
{
    private static bool $orig = false;

    public static function setUpBeforeClass(): void
    {
        self::$orig = App::$function_isolation;
    }

    protected function setUp(): void
    {
        App::$function_isolation = false;
    }

    protected function tearDown(): void
    {
        App::$function_isolation = self::$orig;
    }

    public function testDefaultIsFalse(): void
    {
        $this->assertFalse(App::functionIsolation());
    }

    public function testGetterReflectsBackingProperty(): void
    {
        App::$function_isolation = true;
        $this->assertTrue(App::functionIsolation());

        App::$function_isolation = false;
        $this->assertFalse(App::functionIsolation());
    }

    public function testSetterToTrue(): void
    {
        $result = App::functionIsolation(true);
        $this->assertTrue($result, 'setter returns the new value');
        $this->assertTrue(App::$function_isolation, 'backing property updated');
        $this->assertTrue(App::functionIsolation(), 'getter agrees');
    }

    public function testSetterToFalse(): void
    {
        App::$function_isolation = true;
        $result = App::functionIsolation(false);
        $this->assertFalse($result);
        $this->assertFalse(App::$function_isolation);
        $this->assertFalse(App::functionIsolation());
    }

    public function testNullArgIsGetterOnly(): void
    {
        App::$function_isolation = true;
        // Explicit null must NOT mutate the value — it behaves as a getter.
        $result = App::functionIsolation(null);
        $this->assertTrue($result);
        $this->assertTrue(App::$function_isolation);
    }
}
