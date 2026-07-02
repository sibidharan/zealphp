<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::sharedStatics() — the "statics are worker-global, everything else
 * per-request" knob for coroutine-legacy. Pins the fluent getter/setter
 * contract, the ZEALPHP_SHARED_STATICS env fallback, and the
 * runRequestStaticsBeginRefresh() gate (begin-refresh must be a no-op when
 * statics are deliberately shared).
 */
final class SharedStaticsTest extends TestCase
{
    private ?bool $savedSharedStatics = null;

    protected function setUp(): void
    {
        $this->savedSharedStatics = App::$shared_statics;
        App::$shared_statics = null;
        \putenv('ZEALPHP_SHARED_STATICS');   // unset
    }

    protected function tearDown(): void
    {
        App::$shared_statics = $this->savedSharedStatics;
        \putenv('ZEALPHP_SHARED_STATICS');   // unset
    }

    public function testDefaultIsOff(): void
    {
        $this->assertFalse(App::sharedStatics());
    }

    public function testSetterRoundTrip(): void
    {
        $this->assertTrue(App::sharedStatics(true));
        $this->assertTrue(App::sharedStatics());
        $this->assertFalse(App::sharedStatics(false));
        $this->assertFalse(App::sharedStatics());
    }

    public function testEnvFallbackWhenUnset(): void
    {
        \putenv('ZEALPHP_SHARED_STATICS=1');
        $this->assertTrue(App::sharedStatics());
    }

    public function testExplicitSetterWinsOverEnv(): void
    {
        \putenv('ZEALPHP_SHARED_STATICS=1');
        $this->assertFalse(App::sharedStatics(false));
        $this->assertFalse(App::sharedStatics());
    }

    public function testEnvNonOneValueIsOff(): void
    {
        \putenv('ZEALPHP_SHARED_STATICS=0');
        $this->assertFalse(App::sharedStatics());
    }

    public function testBeginRefreshIsNoOpWhenShared(): void
    {
        App::sharedStatics(true);
        // Must short-circuit BEFORE any ext call — false regardless of
        // whether ext-zealphp is loaded or the per-request resets are active.
        $this->assertFalse(App::runRequestStaticsBeginRefresh());
    }
}
