<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\CGI\FastCgiClient;
use ZealPHP\RequestContext;

/**
 * Tests for App::include() / App::includeFile() dispatch via resolveCgiBackend().
 *
 * Verifies that the correct internal dispatch method (cgiSubprocess / cgiPool /
 * cgiFcgi) is invoked for each registered extension, and that unregistered
 * extensions fall back to App::$cgi_mode. The dispatch methods moved to
 * \ZealPHP\CGI\Dispatcher in Phase 2, so the uopz overrides target Dispatcher.
 */
final class CgiBackendDispatchTest extends TestCase
{
    private static string $tmpDir = '';

    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}> */
    private array $originalBackends = [];
    /** @var array<string, array{mode:string, interpreter?:string|null, address?:string, fcgi_params?:array<string,string>}> */
    private array $originalAliases = [];
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
        $this->originalAliases  = App::$cgi_script_aliases;
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
        App::$cgi_script_aliases           = $this->originalAliases;
        App::$cgi_mode                     = $this->originalCgiMode;
        App::$coproc_implicit_request_handler = $this->originalCoproc;

        foreach ($this->uopzRestores as $method) {
            if (function_exists('uopz_unset_return')) {
                uopz_unset_return(Dispatcher::class, $method);
            }
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
        $backend = App::resolveCgiBackend('/foo.py')['backend'];
        $this->assertSame('fcgi', $backend['mode']);
        $this->assertSame('127.0.0.1:9002', $backend['address']);
    }

    public function testResolveCgiBackendFallsBackToGlobalCgiModeForUnregistered(): void
    {
        App::$cgi_mode = 'proc';
        $backend = App::resolveCgiBackend('/foo.rb')['backend'];
        $this->assertSame('proc', $backend['mode']);
    }

    public function testResolveCgiBackendFallsBackWithFcgiGlobalMode(): void
    {
        App::$cgi_mode = 'fcgi';
        $backend = App::resolveCgiBackend('/foo.rb')['backend'];
        $this->assertSame('fcgi', $backend['mode']);
    }

    // ── include() picks correct dispatch via resolveCgiBackend ────────────────

    public function testIncludePicksProcForRegisteredProcExtension(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl', 'exec_paths' => ['/test_dispatch.pl']]);

        $called = null;
        uopz_set_return(Dispatcher::class, 'cgiSubprocess', function (string $p, ?string $interp = null) use (&$called): string {
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

        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9003', 'exec_paths' => ['/test_dispatch.py']]);

        $called = null;
        uopz_set_return(Dispatcher::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$called): string {
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
            'exec_paths'  => ['/test_fcgiparams.py'],
        ]);

        $called = null;
        uopz_set_return(Dispatcher::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$called): string {
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

        // includeFile() uses the absolute path AS the URL, so the exec scope is
        // the fixture's own URL path (a file path — not a directory, so it
        // passes the URL-prefix validation added for GitHub #155).
        $fixture = $this->fixture('hello.pl', "#!/usr/bin/perl\nprint 'hi';\n");
        App::registerCgiBackend('.pl', ['mode' => 'proc', 'interpreter' => '/usr/bin/perl', 'exec_paths' => [$fixture]]);

        $called = null;
        uopz_set_return(Dispatcher::class, 'cgiSubprocess', function (string $p, ?string $interp = null) use (&$called): string {
            $called = $interp;
            return 'perl-result';
        }, true);
        $this->uopzRestores[] = 'cgiSubprocess';

        App::$coproc_implicit_request_handler = true;
        App::includeFile($fixture);

        $this->assertSame('/usr/bin/perl', $called);
    }

    public function testIncludeFilePicksFcgiForPy(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        $fixture = $this->fixture('hello.py', "print('hi')\n");
        App::registerCgiBackend('.py', ['mode' => 'fcgi', 'address' => '127.0.0.1:9004', 'exec_paths' => [$fixture]]);

        $calledAddr = null;
        uopz_set_return(Dispatcher::class, 'cgiFcgi', function (string $p, ?string $addr = null, array $params = []) use (&$calledAddr): string {
            $calledAddr = $addr;
            return 'py-fcgi-result';
        }, true);
        $this->uopzRestores[] = 'cgiFcgi';

        App::$coproc_implicit_request_handler = true;
        App::includeFile($fixture);

        $this->assertSame('127.0.0.1:9004', $calledAddr);
    }

    /**
     * ExecCGI gate (Task 5): an unregistered, non-PHP file is NOT in any exec
     * scope, so resolveCgiBackend() returns mayExecute=false. The framework must
     * REFUSE it with 403 — never PHP-parse it via executeFile() and never serve
     * its source (Apache ExecCGI-off leaks script source; we refuse instead).
     * It must NOT dispatch to any CGI backend.
     */
    public function testIncludeFileUnregisteredExtensionRefusedWith403(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        App::$cgi_mode = 'proc';

        $called = false;
        uopz_set_return(Dispatcher::class, 'cgiSubprocess', function () use (&$called): string {
            $called = true;
            return 'fallback-result';
        }, true);
        $this->uopzRestores[] = 'cgiSubprocess';

        App::$coproc_implicit_request_handler = true;
        $fixture = $this->fixture('script.rb', "puts 'hi'\n");
        $result  = App::includeFile($fixture);

        $this->assertFalse($called, 'Unregistered non-PHP file must NOT dispatch to a CGI backend');
        $this->assertSame(403, $result, 'Unregistered non-PHP file (no exec scope) must be refused with 403');
    }

    // ── fcgi_params merged into env (via FastCgiClient mock) ──────────────────

    public function testFcgiParamsMergedIntoEnvForRegisteredExtension(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz required');
        }

        $fixture = $this->fixture('merge_test.py', "print('hi')\n");
        App::registerCgiBackend('.py', [
            'mode'        => 'fcgi',
            'address'     => '127.0.0.1:9005',
            'fcgi_params' => ['CUSTOM_PARAM' => 'hello'],
            'exec_paths'  => [$fixture],
        ]);

        $capturedEnv = null;
        uopz_set_return(FastCgiClient::class, 'request', function (array $env, string $body = '') use (&$capturedEnv): array {
            $capturedEnv = $env;
            return ['status' => 200, 'headers' => [], 'body' => 'ok', 'stderr' => ''];
        }, true);

        $this->stubResponse();
        App::$coproc_implicit_request_handler = true;
        App::includeFile($fixture);

        uopz_unset_return(FastCgiClient::class, 'request');

        $this->assertNotNull($capturedEnv);
        $this->assertSame('hello', $capturedEnv['CUSTOM_PARAM'] ?? null,
            'fcgi_params must be merged into the FCGI env');
    }

    // ── exec_paths URL-prefix validation (GitHub #155) ────────────────────────

    /**
     * #155: exec_paths is matched against the request URL via pathUnderPrefix(),
     * so a filesystem path (an existing directory) can never match and yields a
     * silent 403. registerCgiBackend() must fail fast instead.
     */
    public function testRegisterCgiBackendRejectsFilesystemPathExecPaths(): void
    {
        // self::$tmpDir is a real directory on disk → is_dir() === true.
        $this->assertDirectoryExists(self::$tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL path prefix');

        App::registerCgiBackend('.py', [
            'mode'       => 'proc',
            'interpreter'=> '/usr/bin/python3',
            'exec_paths' => [self::$tmpDir],
        ]);
    }

    /**
     * #155: a relative (non-/-rooted) exec_paths value is also a misuse — it can
     * never equal a normalized request URL. Reject it at registration.
     */
    public function testRegisterCgiBackendRejectsRelativeExecPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("starting with '/'");

        App::registerCgiBackend('.py', [
            'mode'       => 'proc',
            'interpreter'=> '/usr/bin/python3',
            'exec_paths' => ['cgi-bin'],
        ]);
    }

    /**
     * A genuine URL prefix like '/cgi-bin' is not a directory on a normal host,
     * so correct configs pass validation untouched and resolve as before.
     */
    public function testRegisterCgiBackendAcceptsUrlPrefixExecPaths(): void
    {
        $this->assertDirectoryDoesNotExist('/cgi-bin');

        App::registerCgiBackend('.py', [
            'mode'       => 'proc',
            'interpreter'=> '/usr/bin/python3',
            'exec_paths' => ['/cgi-bin'],
        ]);

        $resolved = App::resolveCgiBackend('/var/www/public/cgi-bin/report.py', '/cgi-bin/report.py');
        $this->assertTrue($resolved['mayExecute'], 'A URL under the exec scope must be executable');
        $this->assertSame('/usr/bin/python3', $resolved['backend']['interpreter'] ?? null);

        // A URL outside the scope must still be refused (mayExecute=false).
        $outside = App::resolveCgiBackend('/var/www/public/uploads/evil.py', '/uploads/evil.py');
        $this->assertFalse($outside['mayExecute'], 'A URL outside the exec scope must not be executable');
    }

    /**
     * #155: cgiScriptAlias() takes the URL prefix directly — it must apply the
     * same filesystem-path guard.
     */
    public function testCgiScriptAliasRejectsFilesystemPathPrefix(): void
    {
        $this->assertDirectoryExists(self::$tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('URL path prefix');

        App::cgiScriptAlias(self::$tmpDir, ['mode' => 'proc']);
    }

    public function testCgiScriptAliasAcceptsUrlPrefix(): void
    {
        $this->assertDirectoryDoesNotExist('/cgi-bin');

        App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc']);

        $resolved = App::resolveCgiBackend('/var/www/public/cgi-bin/hello.sh', '/cgi-bin/hello.sh');
        $this->assertTrue($resolved['mayExecute'], 'A file under a ScriptAlias prefix must be executable');
    }
}
