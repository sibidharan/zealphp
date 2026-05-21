<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Input\RequestInput;

class RequestInputTest extends TestCase
{
    public function testFilterValueReturnsNullWhenKeyMissing(): void
    {
        $this->assertNull(RequestInput::filterValue([], 'absent', FILTER_DEFAULT, 0));
    }

    public function testFilterValueAppliesIntFilter(): void
    {
        $this->assertSame(42, RequestInput::filterValue(['n' => '42'], 'n', FILTER_VALIDATE_INT, 0));
    }

    public function testFilterValueFailsValidationReturnsFalse(): void
    {
        $this->assertFalse(RequestInput::filterValue(['n' => 'notanint'], 'n', FILTER_VALIDATE_INT, 0));
    }

    public function testFilterValueDefaultPassesThroughString(): void
    {
        $this->assertSame('hello', RequestInput::filterValue(['s' => 'hello'], 's', FILTER_DEFAULT, 0));
    }

    public function testFilterArrayAppliesPerKeyDefinition(): void
    {
        $bag = ['id' => '7', 'email' => 'a@b.com'];
        $def = ['id' => FILTER_VALIDATE_INT, 'email' => FILTER_VALIDATE_EMAIL];
        $out = RequestInput::filterArray($bag, $def, true);
        $this->assertSame(['id' => 7, 'email' => 'a@b.com'], $out);
    }

    public function testFilterArrayAddEmptyYieldsNullForMissingKeys(): void
    {
        $out = RequestInput::filterArray([], ['x' => FILTER_DEFAULT], true);
        $this->assertSame(['x' => null], $out);
    }

    public function testBagForUnknownTypeReturnsEmptyArray(): void
    {
        // Unknown INPUT_* type → empty bag (matches CLI "type unavailable").
        $this->assertSame([], RequestInput::bagFor(999));
    }

    public function testZealFilterInputDelegatesAndReturnsNullForMissing(): void
    {
        // No request/coroutine context here → bag empty → null, never fatal.
        $this->assertNull(\ZealPHP\filter_input(INPUT_GET, 'whatever'));
    }
}
