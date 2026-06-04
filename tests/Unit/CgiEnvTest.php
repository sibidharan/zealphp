<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::resolveCgiEnv() — environment-variable config for the CGI subprocess
 * pool. Precedence: explicit fluent setter > ZEALPHP_CGI_* env > hardcoded
 * default (symmetric with ZEALPHP_WORKERS → worker_num).
 */
final class CgiEnvTest extends TestCase
{
    /** @var list<string> */
    private const ENV_VARS = [
        'ZEALPHP_CGI_MODE', 'ZEALPHP_CGI_WORKERS', 'ZEALPHP_CGI_MAX_REQUESTS',
        'ZEALPHP_CGI_TIMEOUT', 'ZEALPHP_FCGI_ADDRESS', 'ZEALPHP_CGI_FORK_MAX_CONCURRENT',
    ];

    protected function setUp(): void
    {
        $this->resetState();
    }

    protected function tearDown(): void
    {
        $this->resetState();
    }

    private function resetState(): void
    {
        App::$cgi_mode = 'pool';
        App::$fcgi_address = '127.0.0.1:9000';
        App::$cgi_pool_size = 4;
        App::$cgi_pool_max_requests = 500;
        App::$cgi_timeout = 60;
        App::$cgi_fork_max_concurrent = 16;
        App::$cgi_mode_set = false;
        App::$cgi_pool_size_set = false;
        App::$cgi_pool_max_requests_set = false;
        App::$cgi_timeout_set = false;
        App::$fcgi_address_set = false;
        App::$cgi_fork_max_concurrent_set = false;
        foreach (self::ENV_VARS as $v) {
            putenv($v); // unset
        }
    }

    public function testEnvAppliesWhenNotSetExplicitly(): void
    {
        putenv('ZEALPHP_CGI_MODE=proc');
        putenv('ZEALPHP_CGI_WORKERS=12');
        putenv('ZEALPHP_CGI_MAX_REQUESTS=1000');
        putenv('ZEALPHP_CGI_TIMEOUT=30');
        putenv('ZEALPHP_FCGI_ADDRESS=unix:/run/php-fpm.sock');
        putenv('ZEALPHP_CGI_FORK_MAX_CONCURRENT=64');

        App::resolveCgiEnv();

        $this->assertSame('proc', App::$cgi_mode);
        $this->assertSame(12, App::$cgi_pool_size);
        $this->assertSame(1000, App::$cgi_pool_max_requests);
        $this->assertSame(30, App::$cgi_timeout);
        $this->assertSame('unix:/run/php-fpm.sock', App::$fcgi_address);
        $this->assertSame(64, App::$cgi_fork_max_concurrent);
    }

    public function testExplicitSetterWinsOverEnv(): void
    {
        // Explicit code config first → the *_set flags flip true.
        App::cgiMode('fork');
        App::cgiPoolSize(8);
        App::cgiPoolMaxRequests(250);
        App::cgiTimeout(90);
        App::fcgiAddress('10.0.0.1:9000');
        App::cgiForkMaxConcurrent(32);

        // Env that would otherwise override — must be ignored.
        putenv('ZEALPHP_CGI_MODE=proc');
        putenv('ZEALPHP_CGI_WORKERS=12');
        putenv('ZEALPHP_CGI_MAX_REQUESTS=1000');
        putenv('ZEALPHP_CGI_TIMEOUT=30');
        putenv('ZEALPHP_FCGI_ADDRESS=unix:/run/php-fpm.sock');
        putenv('ZEALPHP_CGI_FORK_MAX_CONCURRENT=64');

        App::resolveCgiEnv();

        $this->assertSame('fork', App::$cgi_mode);
        $this->assertSame(8, App::$cgi_pool_size);
        $this->assertSame(250, App::$cgi_pool_max_requests);
        $this->assertSame(90, App::$cgi_timeout);
        $this->assertSame('10.0.0.1:9000', App::$fcgi_address);
        $this->assertSame(32, App::$cgi_fork_max_concurrent);
    }

    public function testUnsetEnvLeavesDefaults(): void
    {
        App::resolveCgiEnv();

        $this->assertSame('pool', App::$cgi_mode);
        $this->assertSame(4, App::$cgi_pool_size);
        $this->assertSame(500, App::$cgi_pool_max_requests);
        $this->assertSame(60, App::$cgi_timeout);
        $this->assertSame('127.0.0.1:9000', App::$fcgi_address);
        $this->assertSame(16, App::$cgi_fork_max_concurrent);
    }

    public function testInvalidEnvIsIgnored(): void
    {
        putenv('ZEALPHP_CGI_MODE=bogus');   // not a real mode
        putenv('ZEALPHP_CGI_WORKERS=0');    // below the min of 1
        putenv('ZEALPHP_CGI_TIMEOUT=abc');  // non-numeric
        putenv('ZEALPHP_FCGI_ADDRESS=');    // empty string

        App::resolveCgiEnv();

        $this->assertSame('pool', App::$cgi_mode);
        $this->assertSame(4, App::$cgi_pool_size);
        $this->assertSame(60, App::$cgi_timeout);
        $this->assertSame('127.0.0.1:9000', App::$fcgi_address);
    }

    public function testNewSettersReturnValueAndSetFlag(): void
    {
        $this->assertSame(7, App::cgiTimeout(7));
        $this->assertTrue(App::$cgi_timeout_set);
        $this->assertSame(20, App::cgiForkMaxConcurrent(20));
        $this->assertTrue(App::$cgi_fork_max_concurrent_set);
        // A no-arg getter call must not flip the flag back or mutate the value.
        $this->assertSame(7, App::cgiTimeout());
        $this->assertTrue(App::$cgi_timeout_set);
    }
}
