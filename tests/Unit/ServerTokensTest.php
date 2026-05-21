<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;

/**
 * Apache ServerTokens parity — App::poweredByHeader() resolves the
 * X-Powered-By value (or null = omit) from App::$server_tokens.
 */
class ServerTokensTest extends TestCase
{
    protected function tearDown(): void
    {
        App::$server_tokens = 'Full';
        parent::tearDown();
    }

    public function testFullIsDefaultAndAdvertisesOpenSwoole(): void
    {
        App::$server_tokens = 'Full';
        $this->assertSame('ZealPHP + OpenSwoole', App::poweredByHeader());
    }

    public function testReducedTokensAdvertiseProductOnly(): void
    {
        foreach (['Prod', 'Major', 'Minor', 'Min', 'OS'] as $t) {
            App::$server_tokens = $t;
            $this->assertSame('ZealPHP', App::poweredByHeader(), "token $t");
        }
    }

    public function testNoneOmitsHeader(): void
    {
        App::$server_tokens = 'None';
        $this->assertNull(App::poweredByHeader());
        App::$server_tokens = '';
        $this->assertNull(App::poweredByHeader());
    }

    public function testSetterRoundTrips(): void
    {
        $this->assertSame('Full', App::serverTokens());
        App::serverTokens('Prod');
        $this->assertSame('Prod', App::serverTokens());
    }
}
