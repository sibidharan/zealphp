<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;

class SapiNameTest extends TestCase
{
    protected function tearDown(): void
    {
        App::$sapi_name = null; // reset opt-in between tests
        parent::tearDown();
    }

    public function testDefaultReturnsRealSapi(): void
    {
        App::$sapi_name = null;
        // Default (null) must not change behavior: returns the real PHP_SAPI.
        $this->assertSame(PHP_SAPI, \ZealPHP\php_sapi_name());
    }

    public function testSetterRoundTrips(): void
    {
        $this->assertNull(App::sapiName());      // no-arg getter, default null
        App::sapiName('apache2handler');
        $this->assertSame('apache2handler', App::sapiName());
    }

    public function testOverrideReturnsConfiguredValueWhenSet(): void
    {
        App::sapiName('apache2handler');
        $this->assertSame('apache2handler', \ZealPHP\php_sapi_name());
    }
}
