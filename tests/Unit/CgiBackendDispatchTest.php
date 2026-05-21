<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Legacy\FastCgiClient;
use ZealPHP\RequestContext;

/**
 * Tests for App::include() / App::includeFile() dispatch via resolveCgiBackend().
 *
 * Verifies that the correct internal dispatch method (cgiSubprocess / cgiFork /
 * cgiFcgi) is invoked for each registered extension, and that unregistered
 * extensions fall back to App::$cgi_mode.
 */
final class CgiBackendDispatchTest extends TestCase
{
    private static string $tmpDir = '';

    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}> */
    private array $originalBackends = [];
    private string $originalCgiMode = 'proc';
    private bool $originalCoproc    = false;

    /** @var list<string> */
    private array $uopzRestores = [];

    public static function setUpBeforeClass(): void
    {
        App::$cwd          = ZEALPHP_ROOT;
        App::$document_root = 'public';

        if (App::instance() === null) {
            App::superglobals(false);
            App::init('0.0.0.0', 19996, ZEALPHP_ROOT);
        }

        self::$tmpDir = sys_get_temp_dir() . '/zealphp_dispatch_' . getmypid();
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tmpDir !== '' && is_dir(self::$tmpDir)) {
            foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$tmpDir);
        }
    }

    protected function setUp(): void
    {
        $this->originalBackends = App::$cgi_backends;
        $this->originalCgiMode  = App::$cgi_mode;
        $this->originalCoproc   = App::$coproc_implicit_request_handler;

        $g = RequestContext::instance();
        $g->server  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $g->get     = [];
        $g->post    = [];
        $g->cookie  = [];
        $g->files   = [];
        $g->status  = 200;
        $g->zealphp_request  = null;
        $g->zealphp_response = null;
    }

    protected function tearDown(): void
    {
        App::$cgi_backends                 = $this->originalBackends;
        App::$cgi_mode                     = $this->originalCgiMode;
        App::$coproc_implicit_request_handler = $this->originalCoproc;

        foreach ($this->uopzRestores as $method) {
            uopz_unset_return(App::class, $method);
        }
        $this->uopzRestores = [];
    }

    private function fixture(string $name, string $content): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    private function stubResponse(): object
    {
        $stub = new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
        RequestContext::instance()->zealphp_response = $stub;
        return $stub;
    }

    // ── resolveCgiBackend returns registered config ────────────────────────────

    public function testResolveCgiBackendReturnsPyFcgiConfig(): void
    {
        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9002']);
        $backend = App::resolveCgiBackend('/foo.py');
        $this->assertSame('fcgi', $backend['mode']);
        $this->assertSame('127.0.0.1:9002', $backend['address']);
    }

    public function testResolveCgiBackendFallsBackToGlobalCgiModeForUnregistered(): void
    {
        App::$cgi_mode = 'proc';
        $backend = App::resolveCgiBackend('/foo.rb');
        $this->assertSame('proc', $backend['mode']);
    }

    public function testResolveCgiBackendFallsBackWithFcgiGlobalMode(): void
    {
        App::$cgi_mode = 'fcgi';
        $backend = App::resolveCgiBackend('/foo.rb');
        $this->assertSame('fcgi', $backend['mode']);
    }

    // ── include() picks correct dispatch via resolveCgiBackend ────────────────

    public function testIncludePicksProcForRegisteredProcExtension(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl']);

        $called = null;
        uopz_set_return(App::class, 'cgiSubprocess', function (string $p, ?string $interp = null) use (&$called): string {
            $called = ['path' => $p, 'interpreter' => $interp];
            return 'proc-result';
        }, true);
        $this->uopzRestores[] = 'cgiSubprocess';

        App::$coproc_implicit_request_handler = true;

        // Create a .pl file inside the doc root so includeCheck passes
        $docRoot = App::resolveDocumentRoot();
        $plPath  = $docRoot . '/test_dispatch.pl';
        file_put_contents($plPath, "#!/usr/bin/perl\nprint 'hi';\n");

        try {
            App::include('/test_dispatch.pl');
        } finally {
            @unlink($plPath);
        }

        $this->assertNotNull($called, 'cgiSubprocess must be called for proc mode');
        $this->assertSame('/usr/bin/perl', $called['interpreter']);
    }

    public function testIncludePicksFcgiForRegisteredFcgiExtension(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9003']);

        $called = null;
        uopz_set_return(App::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$called): string {
            $called = ['path' => $p, 'address' => $addr, 'params' => $params];
            return 'fcgi-result';
        }, true);
        $this->uopzRestores[] = 'cgiFcgi';

        App::$coproc_implicit_request_handler = true;

        $docRoot = App::resolveDocumentRoot();
        $pyPath  = $docRoot . '/test_dispatch.py';
        file_put_contents($pyPath, "print('hi')\n");

        try {
            App::include('/test_dispatch.py');
        } finally {
            @unlink($pyPath);
        }

        $this->assertNotNull($called, 'cgiFcgi must be called for fcgi mode');
        $this->assertSame('127.0.0.1:9003', $called['address']);
        $this->assertSame([], $called['params']);
    }

    public function testIncludePassesFcgiParamsToFcgi(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.py', [
            'mode'        => 'fcgi',
            'address'     => '127.0.0.1:9003',
            'fcgi_params' => ['APP_ENV' => 'test'],
        ]);

        $called = null;
        uopz_set_return(App::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$called): string {
            $called = $params;
            return 'fcgi-params-result';
        }, true);
        $this->uopzRestores[] = 'cgiFcgi';

        App::$coproc_implicit_request_handler = true;

        $docRoot = App::resolveDocumentRoot();
        $pyPath  = $docRoot . '/test_fcgiparams.py';
        file_put_contents($pyPath, "print('hi')\n");

        try {
            App::include('/test_fcgiparams.py');
        } finally {
            @unlink($pyPath);
        }

        $this->assertSame(['APP_ENV' => 'test'], $called);
    }

    // ── includeFile() picks correct dispatch ───────────────────────────────────

    public function testIncludeFilePicksProcWithInterpreterForPl(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl']);

        $called = null;
        uopz_set_return(App::class, 'cgiSubprocess', function (string $p, ?string $interp = null) use (&$called): string {
            $called = $interp;
            return 'perl-result';
        }, true);
        $this->uopzRestores[] = 'cgiSubprocess';

        App::$coproc_implicit_request_handler = true;
        $fixture = $this->fixture('hello.pl', "#!/usr/bin/perl\nprint 'hi';\n");
        App::includeFile($fixture);

        $this->assertSame('/usr/bin/perl', $called);
    }

    public function testIncludeFilePicksFcgiForPy(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9004']);

        $calledAddr = null;
        uopz_set_return(App::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$calledAddr): string {
            $calledAddr = $addr;
            return 'py-fcgi-result';
        }, true);
        $this->uopzRestores[] = 'cgiFcgi';

        App::$coproc_implicit_request_handler = true;
        $fixture = $this->fixture('hello.py', "print('hi')\n");
        App::includeFile($fixture);

        $this->assertSame('127.0.0.1:9004', $calledAddr);
    }

    public function testIncludeFileUnregisteredExtensionFallsBackToGlobalMode(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::$cgi_mode = 'proc';

        $called = false;
        uopz_set_return(App::class, 'cgiSubprocess', function () use (&$called): string {
            $called = true;
            return 'fallback-result';
        }, true);
        $this->uopzRestores[] = 'cgiSubprocess';

        App::$coproc_implicit_request_handler = true;
        $fixture = $this->fixture('script.rb', "puts 'hi'\n");
        App::includeFile($fixture);

        $this->assertTrue($called, 'Unregistered extension must fall back to global cgi_mode dispatch');
    }

    // ── fcgi_params merged into env (via FastCgiClient mock) ──────────────────

    public function testFcgiParamsMergedIntoEnvForRegisteredExtension(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.py', [
            'mode'        => 'fcgi',
            'address'     => '127.0.0.1:9005',
            'fcgi_params' => ['CUSTOM_PARAM' => 'hello'],
        ]);

        $capturedEnv = null;
        uopz_set_return(FastCgiClient::class, 'request', function (array $env, string $body = '') use (&$capturedEnv): array {
            $capturedEnv = $env;
            return ['status' => 200, 'headers' => [], 'body' => 'ok', 'stderr' => ''];
        }, true);

        $this->stubResponse();
        App::$coproc_implicit_request_handler = true;
        $fixture = $this->fixture('merge_test.py', "print('hi')\n");
        App::includeFile($fixture);

        uopz_unset_return(FastCgiClient::class, 'request');

        $this->assertNotNull($capturedEnv);
        $this->assertSame('hello', $capturedEnv['CUSTOM_PARAM'] ?? null,
            'fcgi_params must be merged into the FCGI env');
    }
}
