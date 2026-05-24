<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Legacy\FastCgiClient;
use ZealPHP\Legacy\FastCgiException;
use ZealPHP\RequestContext;

/**
 * Unit tests for App::cgiFcgi() dispatch path (cgi_mode='fcgi').
 *
 * FastCgiClient is mocked via uopz_set_mock so no real php-fpm process is needed.
 * Tests verify: env vars built correctly, body returned, stderr→elog,
 * connection failure→502, SCRIPT_FILENAME set, match() branches in include()
 * and includeFile(), and the happy-path header/status propagation.
 */
class CgiFcgiDispatchTest extends TestCase
{
    private static string $tmpDir = '';
    private static string $pubFile = '';

    /** @var array<int,string> */
    private array $mocks = [];

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::$document_root = 'public';

        // Ensure App singleton exists for includeCheck()
        if (App::instance() === null) {
            App::superglobals(false);
            App::init('0.0.0.0', 19997, ZEALPHP_ROOT);
        }

        // Temp dir for outside-docroot fixtures
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_fcgi_' . getmypid();
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }

        // A real file inside the document root for App::include() tests
        self::$pubFile = ZEALPHP_ROOT . '/public/api.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$tmpDir !== '' && is_dir(self::$tmpDir)) {
            foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$tmpDir);
        }
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    protected function setUp(): void
    {
        App::$cgi_mode = 'fcgi';
        App::$fcgi_address = '127.0.0.1:9000';
        App::$cgi_timeout = 30;
        App::$coproc_implicit_request_handler = false;

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
        App::$cgi_mode = 'proc';
        App::$fcgi_address = '127.0.0.1:9000';
        App::$coproc_implicit_request_handler = false;

        foreach ($this->mocks as $class) {
            uopz_unset_return($class, 'request');
        }
        $this->mocks = [];
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        return $path;
    }

    /**
     * Install a uopz mock for FastCgiClient and register for teardown.
     * uopz_set_mock substitutes the named class entirely — the mock does NOT
     * need to extend the original (which is final).
     *
     * @param array{status:int,headers:array<string,string>,body:string,stderr:string} $response
     */
    private function mockFastCgiClient(array $response): void
    {
        $resp = $response;
        uopz_set_return(FastCgiClient::class, 'request', function () use ($resp): array {
            return $resp;
        }, true);
        $this->mocks[] = FastCgiClient::class;
    }

    /**
     * Install a uopz mock for FastCgiClient::request that throws.
     */
    private function mockFastCgiClientThrows(\Throwable $e): void
    {
        $ex = $e;
        uopz_set_return(FastCgiClient::class, 'request', function () use ($ex): array {
            throw $ex;
        }, true);
        $this->mocks[] = FastCgiClient::class;
    }

    /**
     * Set up a minimal zealphp_response stub so header() calls in cgiFcgi()
     * don't error out.
     */
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

    // ── Configurables (setters / getters) ─────────────────────────────────────

    public function testCgiModeAcceptsFcgiValue(): void
    {
        $this->assertSame('fcgi', App::cgiMode('fcgi'));
        $this->assertSame('fcgi', App::cgiMode());
        $this->assertSame('fcgi', App::$cgi_mode);
    }

    public function testFcgiAddressSetterAndGetter(): void
    {
        App::fcgiAddress('unix:/run/php/php8.3-fpm.sock');
        $this->assertSame('unix:/run/php/php8.3-fpm.sock', App::fcgiAddress());
        $this->assertSame('unix:/run/php/php8.3-fpm.sock', App::$fcgi_address);
        App::$fcgi_address = '127.0.0.1:9000';
    }

    public function testCgiModeRejectsInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'proc', 'fork', 'fcgi', or 'pool'/");
        App::cgiMode('cgi');
    }

    // ── buildCgiEnv / SCRIPT_FILENAME logic ───────────────────────────────────

    public function testBuildCgiEnvSetsScriptFilenameAndName(): void
    {
        $docRoot = App::resolveDocumentRoot();
        $fakePath = $docRoot . '/index.php';
        $env = App::buildCgiEnv(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '{}');

        $env['SCRIPT_FILENAME'] = $fakePath;
        $env['SCRIPT_NAME'] = str_starts_with($fakePath, $docRoot)
            ? '/' . ltrim(substr($fakePath, strlen($docRoot)), '/')
            : $fakePath;

        $this->assertSame($fakePath, $env['SCRIPT_FILENAME']);
        $this->assertSame('/index.php', $env['SCRIPT_NAME']);
    }

    public function testFastCgiClientRequestEnvContainsScriptFilename(): void
    {
        $env = App::buildCgiEnv(
            ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '2'],
            '{}'
        );
        $env['SCRIPT_FILENAME'] = '/var/www/html/api.php';
        $env['SCRIPT_NAME']     = '/api.php';

        $this->assertArrayHasKey('SCRIPT_FILENAME', $env);
        $this->assertSame('/var/www/html/api.php', $env['SCRIPT_FILENAME']);
        $this->assertArrayHasKey('GATEWAY_INTERFACE', $env);
        $this->assertArrayHasKey('SERVER_SOFTWARE', $env);
    }

    // ── FastCgiException / FastCgiClient class assertions ────────────────────

    public function testFastCgiExceptionIsRuntimeException(): void
    {
        // FastCgiException lives in the same file as FastCgiClient — touching
        // FastCgiClient first ensures the file is loaded and the class is available.
        new FastCgiClient('127.0.0.1:9000', 1);
        $e = new FastCgiException('FastCGI: cannot connect to 127.0.0.1:19998: connection refused');
        $this->assertStringContainsString('cannot connect', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testFastCgiClientConstructorAcceptsAddress(): void
    {
        App::fcgiAddress('127.0.0.1:19998');
        $this->assertSame('127.0.0.1:19998', App::$fcgi_address);
        $client = new FastCgiClient(App::$fcgi_address, 1);
        $this->assertInstanceOf(FastCgiClient::class, $client);
    }

    // ── parseStdout ───────────────────────────────────────────────────────────

    public function testParseStdoutMapsStderrContent(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $stderrContent = 'PHP Warning: something went wrong in /app/index.php on line 5';
        $result = $client->parseStdout(
            "Content-Type: text/html\r\n\r\nHello",
            $stderrContent,
            0
        );

        $this->assertSame($stderrContent, $result['stderr'],
            'stderr content must be forwarded in the response array for elog() in cgiFcgi');
        $this->assertSame(200, $result['status']);
        $this->assertSame('Hello', $result['body']);
    }

    public function testParseStdoutBodyReturnedCorrectly(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $result = $client->parseStdout(
            "Content-Type: application/json\r\nX-App: zealphp\r\n\r\n{\"ok\":true}",
            '',
            0
        );
        $this->assertSame('{"ok":true}', $result['body']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
        $this->assertSame('zealphp', $result['headers']['X-App']);
    }

    public function testParseStdoutStatusHeaderExtracted(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $result = $client->parseStdout(
            "Status: 404 Not Found\r\nContent-Type: text/plain\r\n\r\nnot found",
            '',
            0
        );
        $this->assertSame(404, $result['status']);
        $this->assertSame('not found', $result['body']);
        $this->assertArrayNotHasKey('Status', $result['headers']);
    }

    public function testParseStdoutNoBlankLineReturnedAsBody(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $result = $client->parseStdout('just raw body no headers', '', 0);
        $this->assertSame('just raw body no headers', $result['body']);
        $this->assertSame(200, $result['status']);
    }

    // ── cgiFcgi() dispatch via App::includeFile() (outside-docroot path) ──────

    public function testCgiFcgiHappyPathBodyReturnedViaIncludeFile(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        $fixture = $this->fixture('hello.php', "<?php echo 'hello';\n");
        $stub    = $this->stubResponse();

        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => ['Content-Type' => 'text/html'],
            'body'    => 'hello from fpm',
            'stderr'  => '',
        ]);

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame('hello from fpm', $result,
            'cgiFcgi must return the FastCGI response body');
        $this->assertSame('text/html', $stub->headers['Content-Type'],
            'cgiFcgi must propagate response headers to zealphp_response');
    }

    public function testCgiFcgiSetsStatusViaResponseSetStatus(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        $fixture = $this->fixture('redir.php', "<?php\n");
        $this->stubResponse();

        $this->mockFastCgiClient([
            'status'  => 301,
            'headers' => ['Location' => 'https://example.com/'],
            'body'    => '',
            'stderr'  => '',
        ]);

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $g = RequestContext::instance();
        $this->assertSame(301, $g->status,
            'cgiFcgi must call response_set_status() with the FastCGI status');
        $this->assertSame('', $result);
    }

    public function testCgiFcgiStderrNonEmptyIsLogged(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        $fixture = $this->fixture('warn.php', "<?php\n");
        $this->stubResponse();

        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => [],
            'body'    => 'output',
            'stderr'  => 'PHP Warning: division by zero',
        ]);

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        // elog() writes to the debug log; we verify cgiFcgi returned normally
        // (didn't 502) even when stderr is non-empty.
        $this->assertSame('output', $result,
            'cgiFcgi must return body even when stderr is non-empty');
    }

    public function testCgiFcgiFastCgiExceptionReturns502(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        $fixture = $this->fixture('down.php', "<?php\n");
        $this->stubResponse();

        $this->mockFastCgiClientThrows(
            new FastCgiException('FastCGI: cannot connect to 127.0.0.1:9000: connection refused')
        );

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame(502, $result,
            'FastCgiException must cause cgiFcgi to return 502');
    }

    public function testCgiFcgiGenericThrowableReturns502(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        $fixture = $this->fixture('crash.php', "<?php\n");
        $this->stubResponse();

        $this->mockFastCgiClientThrows(new \RuntimeException('socket timeout'));

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame(502, $result,
            'Any Throwable from FastCgiClient must cause cgiFcgi to return 502');
    }

    public function testCgiFcgiNoRequestBodyFallsBackToEmpty(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $fixture = $this->fixture('nobody.php', "<?php\n");
        $this->stubResponse();
        // zealphp_request is null → rawContent() throws → caught → stdinBody = ''
        RequestContext::instance()->zealphp_request = null;

        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => [],
            'body'    => 'ok',
            'stderr'  => '',
        ]);

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame('ok', $result,
            'cgiFcgi must succeed with empty stdin when zealphp_request is null');
    }

    public function testCgiFcgiWithRequestBodyReadsRawContent(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $fixture = $this->fixture('withbody.php', "<?php\n");
        $this->stubResponse();

        // Mock zealphp_request so rawContent() returns a string body.
        $mockRequest = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    public function rawContent(): string
                    {
                        return '{"key":"value"}';
                    }
                };
            }
        };
        RequestContext::instance()->zealphp_request = $mockRequest;

        $capturedStdin = null;
        $resp = ['status' => 200, 'headers' => [], 'body' => 'got body', 'stderr' => ''];
        uopz_set_return(FastCgiClient::class, 'request', function (array $params, string $stdinBody = '') use ($resp, &$capturedStdin): array {
            $capturedStdin = $stdinBody;
            return $resp;
        }, true);
        $this->mocks[] = FastCgiClient::class;

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame('got body', $result);
        $this->assertSame('{"key":"value"}', $capturedStdin,
            'cgiFcgi must pass the request body as stdin to FastCgiClient');

        RequestContext::instance()->zealphp_request = null;
    }

    public function testCgiFcgiScriptNameOutsideDocRoot(): void
    {
        if (!function_exists('uopz_set_mock')) {
            $this->markTestSkipped('uopz_set_mock not available');
        }

        // Absolute path that does NOT start with the doc root →
        // SCRIPT_NAME should be set to the full path (the else branch in cgiFcgi).
        $fixture = $this->fixture('outside.php', "<?php\n");
        $this->stubResponse();

        $capturedEnv = null;
        $docRoot = App::resolveDocumentRoot();
        // Verify this fixture is outside doc root
        $this->assertFalse(str_starts_with($fixture, $docRoot),
            'Fixture must be outside the document root for this test');

        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => [],
            'body'    => 'outside',
            'stderr'  => '',
        ]);

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        $this->assertSame('outside', $result);
    }

    // ── match() branch in App::include() (inside-docroot path) ───────────────

    public function testIncludeFileInsideDocRootDelegatesToInclude(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $this->stubResponse();
        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => [],
            'body'    => 'delegated',
            'stderr'  => '',
        ]);

        // Pass an absolute path inside the doc root to includeFile() —
        // it must strip the docroot prefix and delegate to App::include().
        $docRoot = App::resolveDocumentRoot();
        $absPath = $docRoot . '/api.php';

        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($absPath);

        $this->assertSame('delegated', $result,
            'includeFile() with inside-docroot absolute path must delegate to App::include()');
    }

    public function testMatchForkArmInIncludeCallsCgiFork(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $this->stubResponse();

        uopz_set_return(App::class, 'cgiFork', function (): string {
            return 'fork-result';
        }, true);

        App::$cgi_mode = 'fork';
        App::$coproc_implicit_request_handler = true;
        $result = App::include('/api.php');

        uopz_unset_return(App::class, 'cgiFork');
        App::$cgi_mode = 'fcgi';

        $this->assertSame('fork-result', $result,
            "App::include() match 'fork' arm must call cgiFork");
    }

    public function testMatchForkArmInIncludeFileCallsCgiFork(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $fixture = $this->fixture('forktest.php', "<?php\n");
        $this->stubResponse();

        uopz_set_return(App::class, 'cgiFork', function (): string {
            return 'fork-file-result';
        }, true);

        App::$cgi_mode = 'fork';
        App::$coproc_implicit_request_handler = true;
        $result = App::includeFile($fixture);

        uopz_unset_return(App::class, 'cgiFork');
        App::$cgi_mode = 'fcgi';

        $this->assertSame('fork-file-result', $result,
            "App::includeFile() match 'fork' arm must call cgiFork");
    }

    public function testMatchFcgiBranchTriggeredViaInclude(): void
    {
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz_set_return not available');
        }

        $this->stubResponse();

        $this->mockFastCgiClient([
            'status'  => 200,
            'headers' => ['X-Via' => 'fpm'],
            'body'    => 'fpm response',
            'stderr'  => '',
        ]);

        App::$coproc_implicit_request_handler = true;
        // 'api.php' resolves to public/api.php which exists and passes includeCheck
        $result = App::include('/api.php');

        $this->assertSame('fpm response', $result,
            'App::include() fcgi match() branch must call cgiFcgi and return body');
    }

    public function testMatchFcgiBranchReturns403WhenFileMissing(): void
    {
        // No mock needed — includeCheck() returns false for missing file
        App::$coproc_implicit_request_handler = true;
        $result = App::include('/nonexistent_file_zealphp_test.php');
        $this->assertSame(403, $result,
            'App::include() must return 403 for missing/invalid path');
    }

    // ── encodeRecord / encodeParams (FastCgiClient framing) ──────────────────

    public function testEncodeRecordProducesCorrectHeader(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $record = $client->encodeRecord(FastCgiClient::FCGI_PARAMS, 1, 'ab');
        // 8-byte header + 2-byte body + 6-byte padding = 16 bytes
        $this->assertSame(16, strlen($record));
        $this->assertSame(FastCgiClient::FCGI_VERSION, ord($record[0]));
        $this->assertSame(FastCgiClient::FCGI_PARAMS, ord($record[1]));
    }

    public function testEncodeParamsShortNameValue(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $encoded = $client->encodeParams(['REQUEST_METHOD' => 'GET']);
        $this->assertNotEmpty($encoded);
        // Short name (14 chars ≤ 127) → 1 length byte each
        $this->assertSame(1 + 1 + strlen('REQUEST_METHOD') + strlen('GET'), strlen($encoded));
    }

    public function testEncodeRecordBodyTooLargeThrows(): void
    {
        $client = new FastCgiClient('127.0.0.1:9000', 30);
        $this->expectException(FastCgiException::class);
        $this->expectExceptionMessageMatches('/too large/');
        $client->encodeRecord(FastCgiClient::FCGI_STDIN, 1, str_repeat('x', 65536));
    }
}
