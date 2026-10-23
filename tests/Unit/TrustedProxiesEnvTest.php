<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/** ZEALPHP_TRUSTED_PROXIES fills App::trustedProxies() when the app didn't set it. */
final class TrustedProxiesEnvTest extends TestCase
{
    /** @var array<int, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = App::trustedProxies();
    }

    protected function tearDown(): void
    {
        App::trustedProxies($this->saved);
        putenv('ZEALPHP_TRUSTED_PROXIES');
        parent::tearDown();
    }

    public function testEnvFillsAnUnsetList(): void
    {
        App::trustedProxies([]);
        putenv('ZEALPHP_TRUSTED_PROXIES= 127.0.0.1 , 10.0.0.0/8,,');
        App::resolveTrustedProxiesEnv();
        $this->assertSame(['127.0.0.1', '10.0.0.0/8'], App::trustedProxies());
    }

    public function testCodeWinsOverEnv(): void
    {
        App::trustedProxies(['192.0.2.1']);
        putenv('ZEALPHP_TRUSTED_PROXIES=127.0.0.1');
        App::resolveTrustedProxiesEnv();
        $this->assertSame(['192.0.2.1'], App::trustedProxies());
    }

    public function testUnsetEnvTrustsNoProxy(): void
    {
        App::trustedProxies([]);
        putenv('ZEALPHP_TRUSTED_PROXIES');
        App::resolveTrustedProxiesEnv();
        $this->assertSame([], App::trustedProxies());
    }
}
