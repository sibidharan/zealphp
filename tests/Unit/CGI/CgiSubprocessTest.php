<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\RequestContext;

/**
 * End-to-end coverage for Dispatcher::cgiSubprocess() — the proc-mode CGI path
 * that spawns src/cgi_worker.php and reads back the response. Exercises the
 * concurrent stdout/stderr stream_select drain (the Issue 3 deadlock fix),
 * header/status/cookie application from the metadata frame, the explicit
 * return-value contract, and the SSE streaming branch.
 *
 * $g->zealphp_response / openswoole_response are `mixed` slots, so a no-op stub
 * stands in for the OpenSwoole response a real request would carry. No server
 * boot needed; stream_select is a plain blocking syscall outside a coroutine.
 */
class CgiSubprocessTest extends TestCase
{
    private static string $tmpDir = '';
    /** @var array<int,string> */
    private array $fixtures = [];
    /** @var int|bool|null */
    private $origPi;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_cgisub_' . getmypid();
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }
        App::$cwd = ZEALPHP_ROOT;
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
        parent::setUp();
        // Keep cgiOwnsSessions() false so mintCgiSession() is a no-op.
        $this->origPi = App::$process_isolation;
        App::$process_isolation = false;

        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_NAME' => 'localhost'];
        $g->get = [];
        $g->post = [];
        $g->cookie = [];
        $g->files = [];
        $g->env = [];
        $g->status = null;
        $g->zealphp_response = $this->responseStub();
        $g->openswoole_response = $this->openswooleStub();
        $g->zealphp_request = $this->requestStub();
    }

    protected function tearDown(): void
    {
        App::$process_isolation = $this->origPi;
        foreach ($this->fixtures as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->fixtures = [];
        parent::tearDown();
    }

    private function responseStub(): object
    {
        return new class {
            /** @var array<string,string> */
            public array $headers = [];
            /** @var array<int,array<mixed>> */
            public array $cookies = [];
            public function header(mixed $k, mixed $v): void { $this->headers[(string) $k] = (string) $v; }
            public function cookie(mixed ...$a): void { $this->cookies[] = $a; }
            public function rawCookie(mixed ...$a): void { $this->cookies[] = $a; }
            public function __call(string $n, array $a): mixed { return true; }
        };
    }

    private function openswooleStub(): object
    {
        return new class {
            public string $written = '';
            public function isWritable(): bool { return true; }
            public function isEstablished(): bool { return true; }
            public function write(mixed $c): bool { $this->written .= (string) $c; return true; }
            public function __call(string $n, array $a): mixed { return true; }
        };
    }

    /** Stub the request wrapper so cgiSubprocess() can read the POST body for STDIN. */
    private function requestStub(): object
    {
        return new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    public string $body = '';
                    public function getContent(): string { return $this->body; }
                };
            }
        };
    }

    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        $this->fixtures[] = $path;
        return $path;
    }

    public function testBufferedResponseReturnsEchoedBody(): void
    {
        $f = $this->fixture('plain.php', "<?php\necho 'HELLO-CGI';\n");
        $this->assertSame('HELLO-CGI', Dispatcher::cgiSubprocess($f));
    }

    public function testLargeBodyDrainedAcrossMultipleIterations(): void
    {
        // > the ~64 KB OS pipe buffer → the drain loop iterates and the body
        // can't deadlock against undrained stderr (the Issue 3 fix).
        $f = $this->fixture('big.php', "<?php\necho str_repeat('A', 200000);\n");
        $out = Dispatcher::cgiSubprocess($f);
        $this->assertIsString($out);
        $this->assertSame(200000, strlen($out));
    }

    /**
     * Regression: the .php path buffered the script's whole output with no cap,
     * so ~40 MB under a limit with 48 MB free killed the parent with a fatal.
     */
    public function testPhpOutputOverTheCapFailsWithoutAFatal(): void
    {
        $saved = (string) ini_get('memory_limit');
        $limit = memory_get_usage(true) + 48 * 1024 * 1024;
        if (ini_set('memory_limit', (string) $limit) === false) {
            $this->markTestSkipped('could not set memory_limit');
        }
        try {
            $f = $this->fixture('huge.php', "<?php\necho str_repeat('A', 40 * 1024 * 1024);\n");
            $this->assertSame(500, Dispatcher::cgiSubprocess($f));
        } finally {
            ini_set('memory_limit', $saved);
        }
    }

    /** Output written before the metadata line is capped too, not cut short. */
    public function testPhpOutputBeforeMetadataOverTheCapFails(): void
    {
        $saved = (string) ini_get('memory_limit');
        $limit = memory_get_usage(true) + 48 * 1024 * 1024;
        if (ini_set('memory_limit', (string) $limit) === false) {
            $this->markTestSkipped('could not set memory_limit');
        }
        try {
            $f = $this->fixture('early.php', "<?php\nfwrite(STDOUT, str_repeat('B', 46 * 1024 * 1024));\n");
            $this->assertSame(500, Dispatcher::cgiSubprocess($f));
        } finally {
            ini_set('memory_limit', $saved);
        }
    }

    /** A script still running when the body drain times out is stopped. */
    public function testDrainTimeoutStopsTheScript(): void
    {
        $saved = App::$cgi_timeout;
        // 2 s, so a slow-to-start child still sends its metadata in time and
        // the drain timeout (not the metadata timeout) is what fires.
        App::$cgi_timeout = 2;
        try {
            $f = $this->fixture('slow.php', "<?php\necho 'x';\nflush();\nsleep(8);\n");
            $t0 = microtime(true);
            $this->assertSame(500, Dispatcher::cgiSubprocess($f));
            $this->assertLessThan(6.0, microtime(true) - $t0, 'the script is stopped, not waited out');
        } finally {
            App::$cgi_timeout = $saved;
        }
    }

    public function testStderrWarningsDoNotWedgeTheResponse(): void
    {
        // error_log() writes to the child's stderr; the concurrent drain must
        // read it without blocking the stdout body.
        $f = $this->fixture('warn.php', "<?php\nerror_log('a noisy warning');\necho 'body-ok';\n");
        $this->assertSame('body-ok', Dispatcher::cgiSubprocess($f));
    }

    public function testHeadersAndStatusFromMetadataAreApplied(): void
    {
        $f = $this->fixture('hdr.php', "<?php\nheader('X-Foo: bar');\nheader('Status: 201 Created');\necho 'made';\n");
        $out = Dispatcher::cgiSubprocess($f);
        $this->assertSame('made', $out);
        $this->assertSame(201, RequestContext::instance()->status, 'Status: header sets the response status');
        $resp = RequestContext::instance()->zealphp_response;
        $this->assertSame('bar', $resp->headers['X-Foo'] ?? null, 'custom header applied to the response');
        $this->assertArrayNotHasKey('Status', $resp->headers, 'Status: pseudo-header is stripped, not forwarded');
    }

    public function testExplicitReturnValueRidesTheContract(): void
    {
        $f = $this->fixture('ret.php', "<?php\nreturn 404;\n");
        $this->assertSame(404, Dispatcher::cgiSubprocess($f), 'an explicit int return becomes the status');
    }

    public function testPostBodyReachesSubprocessViaPhpInput(): void
    {
        // The body is written to the child's STDIN; cgi_worker.php reads it and
        // serves it via CgiInputStream, so file_get_contents('php://input') in
        // the target file sees it (the WP REST / block-editor bridge).
        RequestContext::instance()->zealphp_request->parent->body = 'payload=42&x=y';
        $f = $this->fixture('input.php', "<?php\necho file_get_contents('php://input');\n");
        $this->assertSame('payload=42&x=y', Dispatcher::cgiSubprocess($f));
    }

    public function testEchoThenStringReturnConcatenates(): void
    {
        // echo-shell-then-return-body idiom: a string return is folded onto the
        // echoed output (matches executeFile()).
        $f = $this->fixture('echoret.php', "<?php\necho 'shell-';\nreturn 'body';\n");
        $this->assertSame('shell-body', Dispatcher::cgiSubprocess($f));
    }

    public function testSseStreamingPathWritesToTheResponse(): void
    {
        $f = $this->fixture('sse.php', "<?php\nheader('Content-Type: text/event-stream');\necho \"data: stream-hi\\n\\n\";\n");
        $out = Dispatcher::cgiSubprocess($f);
        // The streaming branch writes to openswoole_response and returns null.
        $this->assertNull($out);
        $this->assertStringContainsString('data: stream-hi', RequestContext::instance()->openswoole_response->written);
    }
}
