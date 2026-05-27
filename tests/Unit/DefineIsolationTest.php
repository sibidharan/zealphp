<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Tests for App::defineIsolation() and App::$define_isolation.
 *
 * defineIsolation() follows the same fluent getter/setter contract as the
 * other App configurables: no-arg call returns current value; one-arg call
 * sets and returns the new value.
 */
final class DefineIsolationTest extends TestCase
{
    private static bool $origDefineIsolation = false;
    private static bool $origRunHasStarted = false;

    public static function setUpBeforeClass(): void
    {
        self::$origDefineIsolation = App::$define_isolation;
        self::$origRunHasStarted   = App::$run_has_started;
    }

    protected function setUp(): void
    {
        // Ensure setters are accepted (pre-boot state).
        App::$run_has_started  = false;
        App::$define_isolation = false;
    }

    protected function tearDown(): void
    {
        App::$run_has_started  = self::$origRunHasStarted;
        App::$define_isolation = self::$origDefineIsolation;
    }

    // ── Getter behaviour ──────────────────────────────────────────────────

    public function testDefaultIsFalse(): void
    {
        $this->assertFalse(App::defineIsolation());
    }

    public function testGetterReturnsCurrentBackingProperty(): void
    {
        App::$define_isolation = true;
        $this->assertTrue(App::defineIsolation());

        App::$define_isolation = false;
        $this->assertFalse(App::defineIsolation());
    }

    // ── Setter behaviour ─────────────────────────────────────────────────

    public function testSetterToTrue(): void
    {
        App::defineIsolation(true);
        $this->assertTrue(App::$define_isolation);
        $this->assertTrue(App::defineIsolation());
    }

    public function testSetterToFalse(): void
    {
        App::$define_isolation = true;
        App::defineIsolation(false);
        $this->assertFalse(App::$define_isolation);
        $this->assertFalse(App::defineIsolation());
    }

    public function testSetterReturnsSameValueAfterSet(): void
    {
        $result = App::defineIsolation(true);
        $this->assertTrue($result);

        $result2 = App::defineIsolation(false);
        $this->assertFalse($result2);
    }

    // ── Null arg (getter-only call) ──────────────────────────────────────

    public function testNullArgActsAsGetter(): void
    {
        App::$define_isolation = true;
        // Passing null explicitly should NOT change the value.
        $result = App::defineIsolation(null);
        $this->assertTrue($result);
        $this->assertTrue(App::$define_isolation);
    }
}
