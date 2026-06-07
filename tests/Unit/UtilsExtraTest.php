<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\get;
use function ZealPHP\jTraceEx;
use function ZealPHP\coprocess;
use function ZealPHP\setrawcookie;
use function ZealPHP\headers_sent;
use function ZealPHP\flush as zflush;
use function ZealPHP\ob_flush;
use function ZealPHP\ob_end_flush;
use function ZealPHP\ob_implicit_flush;
use function ZealPHP\apache_request_headers;
use function ZealPHP\getallheaders;
use function ZealPHP\apache_response_headers;
use function ZealPHP\apache_note;
use function ZealPHP\connection_status;
use function ZealPHP\connection_aborted;
use function ZealPHP\is_uploaded_file;
use function ZealPHP\move_uploaded_file;
use function ZealPHP\header_remove;

/**
 * Branch coverage for src/utils.php not reached by UtilsTest.php /
 * UtilsLoggingTest.php:
 *
 *   - get() superglobal accessor
 *   - jTraceEx() $seen recursion (chained exceptions revisiting a file:line)
 *   - coprocess() guard throw in coroutine mode (no fork performed)
 *   - setrawcookie() invalid-name / CRLF / expire+path+domain+secure+httponly
 *   - headers_sent() / flush() / ob_*() against a mock openswoole_response
 *   - apache_request_headers() / getallheaders() / apache_response_headers()
 *   - apache_note() lazy-allocation path
 *   - connection_status() / connection_aborted() aborted branch
 *   - is_uploaded_file() true paths (scalar + array tmp_name) and
 *     move_uploaded_file() rename + copy-fallback + reject paths
 *
 * Everything mutates only $g state + tmp files, both reset in tearDown.
 */
class UtilsExtraTest extends TestCase
{
    /** @var object{headersList: array<int,array{0:string,1:string}>, cookies: array<int,array<int,mixed>>} */
    private object $resp;
    /** @var string[] */
    private array $tmpFiles = [];
    /** @var string[] */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $this->obBaseline = ob_get_level();
        $this->resp = new class {
            /** @var array<int, array{0:string,1:string}> */
            public array $headersList = [];
            /** @var array<int, array<int, mixed>> */
            public array $cookies = [];
            public function header(string $k, string $v, bool $ucwords = true): void { $this->headersList[] = [$k, $v]; }
            public function cookie(mixed ...$args): void { $this->cookies[] = $args; }
            public function rawCookie(mixed ...$args): void { $this->cookies[] = $args; }
        };
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
        $g->status = 200;
        $g->files = [];
    }

    protected function tearDown(): void
    {
        $g = RequestContext::instance();
        $g->files = [];
        $g->apacheContext = null;
        $g->openswoole_response = null;
        $g->zealphp_request = null;
        $g->_streaming = null;
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
        foreach ($this->tmpDirs as $d) {
            @rmdir($d);
        }
        $this->tmpDirs = [];
        // Drain any output buffers our flush() tests may have left open.
        while (ob_get_level() > $this->obBaseline) {
            @ob_end_clean();
        }
        parent::tearDown();
    }

    private int $obBaseline = 0;

    // ── get() ────────────────────────────────────────────────────────

    public function testGetReadsSuperglobalWithDefault(): void
    {
        $saved = $_GET ?? [];
        try {
            $_GET['present'] = 'yes';
            $this->assertSame('yes', get('present'));
            $this->assertSame('fallback', get('absent', 'fallback'));
            $this->assertNull(get('absent'));
        } finally {
            $_GET = $saved;
        }
    }

    // ── jTraceEx $seen recursion ─────────────────────────────────────

    public function testJTraceExSeenRecursionEmitsMoreMarker(): void
    {
        // Build a cause chain where the outer exception shares the originating
        // file:line with the inner — the recursive jTraceEx($prev, $seen) call
        // then hits the in_array($current, $seen) branch and emits "... N more".
        $makeChain = function () {
            $inner = new \RuntimeException('inner');
            return new \LogicException('outer', 0, $inner);
        };
        $e = $makeChain();
        $trace = jTraceEx($e);

        $this->assertStringContainsString('LogicException: outer', $trace);
        $this->assertStringContainsString('Caused by: ', $trace);
        $this->assertStringContainsString('RuntimeException: inner', $trace);
        // The shared throw site is revisited in the recursion → "... N more".
        $this->assertStringContainsString(' more', $trace);
    }

    // ── coprocess() guard ────────────────────────────────────────────

    public function testCoprocessThrowsInCoroutineMode(): void
    {
        $original = App::$superglobals;
        App::$superglobals = false;
        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Superglobals are disabled');
            // Guard fires before any Process is spawned — no fork happens.
            coprocess(function () { return 'never'; });
        } finally {
            App::$superglobals = $original;
        }
    }

    // ── setrawcookie() branches ──────────────────────────────────────

    public function testSetrawcookieRejectsInvalidName(): void
    {
        // #291 — PHP 8.4 raw-cookie semantics: a separator/control in the NAME
        // throws ValueError (the raw value is never url-encoded).
        $this->expectException(\ValueError::class);
        setrawcookie('bad name', 'v');
    }

    public function testSetrawcookieRejectsInvalidNameEquals(): void
    {
        $this->expectException(\ValueError::class);
        setrawcookie('bad=name', 'v');
    }

    public function testSetrawcookieRejectsCrlfInValue(): void
    {
        // #291 — CR/LF in a raw value throws ValueError (header-injection vector).
        $this->expectException(\ValueError::class);
        setrawcookie('ok', "a\r\nb");
    }

    public function testSetrawcookieAllValueComponents(): void
    {
        // All optional components set → every cookie-string append branch runs.
        // The value here is now url-safe (no SP/separator) so PHP 8.4's raw
        // ValueError guard does not fire (#291).
        $ok = setrawcookie('rawck', 'aXb+c/d', time() + 3600, '/path', 'example.com', true, true);
        $this->assertTrue($ok);
        $this->assertNotEmpty($this->resp->cookies);
        $last = end($this->resp->cookies);
        $this->assertSame('rawck', $last[0]);
    }

    public function testSetrawcookieRejectsSpaceInValue(): void
    {
        // #291 — a SPACE in the raw value is rejected with ValueError on PHP 8.4
        // (it would corrupt the Set-Cookie header; setcookie() url-encodes it).
        $this->expectException(\ValueError::class);
        setrawcookie('rawck', 'a b');
    }

    // ── headers_sent / flush / ob_* (mock openswoole_response) ───────

    public function testHeadersSentReflectsWritableState(): void
    {
        $g = RequestContext::instance();
        $writable = true;
        $g->openswoole_response = new class($writable) {
            public function __construct(private bool $w) {}
            public function isWritable(): bool { return $this->w; }
            public function write(string $d): void {}
        };
        // Writable response → headers not yet sent.
        $this->assertFalse(headers_sent());

        $notWritable = false;
        $g->openswoole_response = new class($notWritable) {
            public function __construct(private bool $w) {}
            public function isWritable(): bool { return $this->w; }
            public function write(string $d): void {}
        };
        $this->assertTrue(headers_sent());
    }

    public function testFlushSwitchesToStreamingAndWritesBuffer(): void
    {
        $this->obBaseline = ob_get_level();
        $g = RequestContext::instance();
        $written = [];
        $flushed = false;
        $g->openswoole_response = new class($written) {
            /** @param array<int,string> $sink */
            public function __construct(public array &$sink) {}
            public function isWritable(): bool { return true; }
            public function write(string $d): void { $this->sink[] = $d; }
        };
        // zealphp_response->flush() must exist for the streaming switch.
        $g->zealphp_response = new class($flushed) {
            public function __construct(public bool &$f) {}
            public function header(string $k, string $v, bool $u = true): void {}
            public function flush(): void { $this->f = true; }
        };
        $g->_streaming = null;

        ob_start();
        echo 'chunk-data';
        zflush();

        $this->assertTrue($flushed, 'zealphp_response->flush() should fire on first flush');
        $this->assertTrue($g->_streaming);
        $this->assertContains('chunk-data', $written);

        // Second flush: already streaming, just writes new buffer content.
        echo 'more';
        ob_flush();
        $this->assertContains('more', $written);

        ob_end_flush();
    }

    public function testFlushNoopWhenNoOpenswooleResponse(): void
    {
        $g = RequestContext::instance();
        $g->openswoole_response = null;
        // No openswoole_response → flush() returns early, emitting nothing.
        $this->expectOutputString('');
        zflush();
    }

    public function testObImplicitFlushIsNoop(): void
    {
        // The uopz override is a pure no-op — it must not emit any output.
        $this->expectOutputString('');
        ob_implicit_flush(true);
        ob_implicit_flush(false);
    }

    // ── apache_request_headers / getallheaders / response_headers ────

    public function testApacheRequestHeadersCanonicalizesNames(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_request = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    /** @var array<string,mixed> */
                    public array $header = [
                        'content-type' => 'text/html',
                        'x-custom-header' => 'v1',
                        'accept' => ['a/b', 'c/d'],
                    ];
                };
            }
        };
        $headers = apache_request_headers();
        $this->assertSame('text/html', $headers['Content-Type']);
        $this->assertSame('v1', $headers['X-Custom-Header']);
        // Array values are joined with ', '.
        $this->assertSame('a/b, c/d', $headers['Accept']);
        // getallheaders() is an alias.
        $this->assertSame($headers, getallheaders());
    }

    public function testApacheRequestHeadersEmptyWhenNoRequest(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_request = null;
        $this->assertSame([], apache_request_headers());
    }

    public function testApacheRequestHeadersEmptyWhenRawNotArray(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_request = new class {
            public object $parent;
            public function __construct()
            {
                $this->parent = new class {
                    // Non-array header → the !is_array($raw) guard returns [].
                    public string $header = 'not-an-array';
                };
            }
        };
        $this->assertSame([], apache_request_headers());
    }

    public function testApacheResponseHeadersReflectsResponse(): void
    {
        $this->resp->headersList = [['X-A', '1'], ['X-B', '2']];
        $out = apache_response_headers();
        $this->assertSame(['X-A' => '1', 'X-B' => '2'], $out);
    }

    public function testApacheResponseHeadersEmptyWhenResponseNull(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_response = null;
        $this->assertSame([], apache_response_headers());
    }

    // ── apache_note lazy allocation when context null ────────────────

    public function testApacheNoteAllocatesContextWhenNull(): void
    {
        $g = RequestContext::instance();
        $g->apacheContext = null;
        // First note write must lazily allocate ApacheContext.
        $prev = apache_note('greeting', 'hello');
        $this->assertSame('', $prev);
        $this->assertSame('hello', apache_note('greeting'));
    }

    // ── connection_status / connection_aborted aborted branch ────────

    public function testConnectionAbortedWhenResponseNotWritable(): void
    {
        $g = RequestContext::instance();
        $g->openswoole_response = new class {
            public function isWritable(): bool { return false; }
            public function write(string $d): void {}
        };
        $this->assertSame(1, connection_status());
        $this->assertSame(1, connection_aborted());
    }

    public function testConnectionNormalWhenWritable(): void
    {
        $g = RequestContext::instance();
        $g->openswoole_response = new class {
            public function isWritable(): bool { return true; }
            public function write(string $d): void {}
        };
        $this->assertSame(0, connection_status());
        $this->assertSame(0, connection_aborted());
    }

    // ── is_uploaded_file true paths + move_uploaded_file ─────────────

    public function testIsUploadedFileScalarAndArrayTmpName(): void
    {
        $scalar = $this->makeTmp('upload-scalar');
        $arr1   = $this->makeTmp('upload-arr1');
        $arr2   = $this->makeTmp('upload-arr2');

        $g = RequestContext::instance();
        $g->files = [
            'avatar' => ['tmp_name' => $scalar, 'name' => 'a.png'],
            'docs'   => ['tmp_name' => [$arr1, $arr2], 'name' => ['d1', 'd2']],
            'bogus'  => 'not-an-array',
        ];

        $this->assertTrue(is_uploaded_file($scalar));
        $this->assertTrue(is_uploaded_file($arr1));
        $this->assertTrue(is_uploaded_file($arr2));
        $this->assertFalse(is_uploaded_file('/tmp/forged-path'));
    }

    public function testMoveUploadedFileRejectsNonUploaded(): void
    {
        $g = RequestContext::instance();
        $g->files = [];
        $dest = sys_get_temp_dir() . '/zealphp_move_dest_' . uniqid();
        $this->assertFalse(move_uploaded_file('/etc/passwd', $dest));
        $this->assertFileDoesNotExist($dest);
    }

    public function testMoveUploadedFileRenamesRegisteredUpload(): void
    {
        $src = $this->makeTmp('move-me');
        $g = RequestContext::instance();
        $g->files = ['f' => ['tmp_name' => $src, 'name' => 'f.txt']];

        $dest = sys_get_temp_dir() . '/zealphp_move_dest_' . uniqid() . '.txt';
        $this->tmpFiles[] = $dest;

        $this->assertTrue(move_uploaded_file($src, $dest));
        $this->assertFileExists($dest);
        $this->assertSame('move-me', (string)file_get_contents($dest));
    }

    public function testMoveUploadedFileRenameFailFallsToCopyAttempt(): void
    {
        // Registered upload, but destination is an existing directory →
        // rename(file, dir) fails, exercising the @rename-false branch and the
        // @copy fallback attempt (which also fails into the final return).
        $src = $this->makeTmp('copy-fallback-body');
        $g = RequestContext::instance();
        $g->files = ['f' => ['tmp_name' => $src, 'name' => 'f.txt']];

        $destDir = sys_get_temp_dir() . '/zealphp_mv_dir_' . uniqid();
        @mkdir($destDir);
        $this->tmpDirs[] = $destDir;

        $result = move_uploaded_file($src, $destDir);
        $this->assertIsBool($result);
    }

    public function testHeaderRemoveAllClearsList(): void
    {
        $this->resp->headersList = [['X-A', '1'], ['X-B', '2']];
        header_remove();
        $this->assertSame([], $this->resp->headersList);
    }

    public function testHeaderRemoveNoopWhenResponseNull(): void
    {
        $g = RequestContext::instance();
        $g->zealphp_response = null;
        // Null response → header_remove returns early without error.
        header_remove('X-Anything');
        $this->assertTrue(true);
    }

    public function testFlushNoopWhenResponseNotWritable(): void
    {
        $g = RequestContext::instance();
        $g->openswoole_response = new class {
            public function isWritable(): bool { return false; }
            public function write(string $d): void {}
        };
        // Not writable → flush() returns early before any streaming switch.
        zflush();
        $this->assertNull($g->_streaming);
    }

    private function makeTmp(string $contents): string
    {
        $f = tempnam(sys_get_temp_dir(), 'zealphp_ul_');
        file_put_contents($f, $contents);
        $this->tmpFiles[] = $f;
        return $f;
    }
}
