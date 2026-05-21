<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Tests for App::registerCgiBackend() registry and App::resolveCgiBackend().
 *
 * Covers: default .php entry, happy-path registrations (proc/fork/fcgi),
 * fork-on-non-PHP rejection, invalid mode rejection, fcgi-without-address
 * rejection, and resolveCgiBackend fallback to global cgi_mode.
 */
final class CgiBackendRegistryTest extends TestCase
{
    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}> */
    private array $originalBackends = [];
    private string $originalCgiMode = 'proc';

    protected function setUp(): void
    {
        $this->originalBackends  = App::$cgi_backends;
        $this->originalCgiMode   = App::$cgi_mode;
        App::$cwd = ZEALPHP_ROOT;
    }

    protected function tearDown(): void
    {
        App::$cgi_backends = $this->originalBackends;
        App::$cgi_mode     = $this->originalCgiMode;
    }

    // ── Default state ─────────────────────────────────────────────────────────

    public function testDefaultRegistryIsEmpty(): void
    {
        // Empty by default — unregistered extensions fall through to App::$cgi_mode.
        $this->assertSame([], App::$cgi_backends);
    }

    public function testResolveCgiBackendPhpFallsBackToGlobalModeByDefault(): void
    {
        App::$cgi_mode = 'proc';
        $backend = App::resolveCgiBackend('/index.php');
        $this->assertSame('proc', $backend['mode'],
            '.php with empty registry must fall back to App::$cgi_mode');
    }

    // ── Happy-path registrations ───────────────────────────────────────────────

    public function testRegisterProcBackendForPerlExtension(): void
    {
        App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl']);
        $this->assertArrayHasKey('.pl', App::$cgi_backends);
        $this->assertSame('proc', App::$cgi_backends['.pl']['mode']);
        $this->assertSame('/usr/bin/perl', App::$cgi_backends['.pl']['interpreter']);
    }

    public function testRegisterProcBackendWithoutInterpreterUsesShebang(): void
    {
        App::registerCgiBackend('.cgi', ['mode' => 'proc']);
        $this->assertArrayHasKey('.cgi', App::$cgi_backends);
        $this->assertSame('proc', App::$cgi_backends['.cgi']['mode']);
        $this->assertArrayNotHasKey('interpreter', App::$cgi_backends['.cgi']);
    }

    public function testRegisterForkBackendForPhp(): void
    {
        App::registerCgiBackend('.php', ['mode' => 'fork']);
        $this->assertSame('fork', App::$cgi_backends['.php']['mode']);
    }

    public function testRegisterFcgiBackendWithTcpAddress(): void
    {
        App::registerCgiBackend('.py', [
            'mode'    => 'fcgi',
            'address' => '127.0.0.1:9001',
        ]);
        $this->assertArrayHasKey('.py', App::$cgi_backends);
        $this->assertSame('fcgi', App::$cgi_backends['.py']['mode']);
        $this->assertSame('127.0.0.1:9001', App::$cgi_backends['.py']['address']);
    }

    public function testRegisterFcgiBackendWithUnixSocket(): void
    {
        App::registerCgiBackend('.py', [
            'mode'    => 'fcgi',
            'address' => 'unix:/run/python-fpm.sock',
        ]);
        $this->assertSame('unix:/run/python-fpm.sock', App::$cgi_backends['.py']['address']);
    }

    public function testRegisterFcgiBackendWithFcgiParams(): void
    {
        App::registerCgiBackend('.py', [
            'mode'        => 'fcgi',
            'address'     => '127.0.0.1:9001',
            'fcgi_params' => ['SCRIPT_ROOT' => '/srv/python', 'APP_ENV' => 'prod'],
        ]);
        $this->assertSame(
            ['SCRIPT_ROOT' => '/srv/python', 'APP_ENV' => 'prod'],
            App::$cgi_backends['.py']['fcgi_params']
        );
    }

    public function testRegisterOverwritesExistingExtension(): void
    {
        App::registerCgiBackend('.php', ['mode' => 'proc']);
        App::registerCgiBackend('.php', ['mode' => 'fork']);
        $this->assertSame('fork', App::$cgi_backends['.php']['mode']);
    }

    // ── Validation errors ─────────────────────────────────────────────────────

    public function testInvalidModeThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'proc', 'fork', or 'fcgi'/");
        App::registerCgiBackend('.rb', ['mode' => 'cgi']);
    }

    public function testEmptyModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::registerCgiBackend('.rb', ['mode' => '']);
    }

    public function testMissingModeKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::registerCgiBackend('.rb', []);
    }

    public function testForkOnNonPhpExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fork mode requires a PHP target/');
        App::registerCgiBackend('.py', ['mode' => 'fork']);
    }

    public function testForkOnNonPhpErrorMessageIncludesExtension(): void
    {
        try {
            App::registerCgiBackend('.pl', ['mode' => 'fork']);
            $this->fail('Expected InvalidArgumentException not thrown');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('.pl', $e->getMessage());
        }
    }

    public function testFcgiWithoutAddressThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fcgi mode requires an .address./');
        App::registerCgiBackend('.py', ['mode' => 'fcgi']);
    }

    public function testFcgiWithEmptyAddressThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '']);
    }

    // ── resolveCgiBackend ─────────────────────────────────────────────────────

    public function testResolveCgiBackendReturnsRegisteredConfigForExtension(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9001']);
        $backend = App::resolveCgiBackend('/var/www/app/hello.py');
        $this->assertSame('fcgi', $backend['mode']);
        $this->assertSame('127.0.0.1:9001', $backend['address']);
    }

    public function testResolveCgiBackendFallsBackToGlobalCgiMode(): void
    {
        App::$cgi_mode = 'fcgi';
        $backend = App::resolveCgiBackend('/var/www/app/script.rb');
        $this->assertSame('fcgi', $backend['mode']);
    }

    public function testResolveCgiBackendFallsBackForUnregisteredExtension(): void
    {
        App::$cgi_mode = 'proc';
        $backend = App::resolveCgiBackend('/srv/app/page.rb');
        $this->assertSame('proc', $backend['mode']);
    }

    public function testResolveCgiBackendExtensionIsCaseInsensitive(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9001']);
        $backend = App::resolveCgiBackend('/app/script.PY');
        $this->assertSame('fcgi', $backend['mode']);
    }

    public function testResolveCgiBackendPhpUsesRegisteredEntry(): void
    {
        App::$cgi_backends['.php'] = ['mode' => 'fork'];
        $backend = App::resolveCgiBackend('/var/www/index.php');
        $this->assertSame('fork', $backend['mode']);
    }

    public function testResolveCgiBackendForFileWithNoExtension(): void
    {
        App::$cgi_mode = 'proc';
        // pathinfo('/app/script', PATHINFO_EXTENSION) returns '' → ext = '.'
        $backend = App::resolveCgiBackend('/app/script');
        $this->assertSame('proc', $backend['mode']);
    }
}
