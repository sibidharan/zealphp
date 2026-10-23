<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\CGI\Dispatcher;
use ZealPHP\CGI\WorkerPool;
use ZealPHP\RequestContext;

/**
 * proc-mode and ScriptAlias hardening (C7, C8, C10, C11, C12).
 *
 *  - C7: the cgi_worker metadata line rides fd 3, so a stderr write before it
 *    (error_log(), a debug fwrite) no longer drops the status and headers.
 *  - C12: the request context reaches cgi_worker over a pipe, not one env var
 *    that hits E2BIG at ~128 KB.
 *  - C10: an interpreter script's stderr is drained while stdout is read, and
 *    its buffered output is capped.
 *  - C11: ScriptAlias runs only files with a `#!` line that are not group- or
 *    world-writable (Apache / suexec parity).
 *  - C8: the host emits the CGI session cookie with the app's
 *    session_get_cookie_params(), not hard-coded defaults.
 */
final class CgiProcHardeningTest extends TestCase
{
    private static string $tmpDir = '';
    /** @var int|bool|null */
    private $origPi;
    private bool $origSg;
    private int $origTimeout;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_cgihard_' . getmypid();
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0700, true);
        }
        App::$cwd = ZEALPHP_ROOT;
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$tmpDir);
    }

    protected function setUp(): void
    {
        $this->origPi = App::$process_isolation;
        $this->origSg = App::$superglobals;
        $this->origTimeout = App::$cgi_timeout;
        App::$process_isolation = false;

        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_NAME' => 'localhost'];
        $g->get = [];
        $g->post = [];
        $g->cookie = [];
        $g->files = [];
        $g->env = [];
        $g->status = null;
        $g->zealphp_response = new class {
            /** @var array<string,string> */
            public array $headers = [];
            /** @var list<array<mixed>> */
            public array $cookies = [];
            public function header(mixed $k, mixed $v, mixed $replace = true): void { $this->headers[(string) $k] = (string) $v; }
            public function cookie(mixed ...$a): void { $this->cookies[] = $a; }
            public function rawCookie(mixed ...$a): void { $this->cookies[] = $a; }
            public function isWritable(): bool { return true; }
            public function __call(string $n, array $a): mixed { return true; }
        };
        $g->openswoole_response = new class {
            public string $written = '';
            public function isWritable(): bool { return true; }
            public function write(mixed $c): bool { $this->written .= (string) $c; return true; }
            public function __call(string $n, array $a): mixed { return true; }
        };
        $g->zealphp_request = new class {
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

    protected function tearDown(): void
    {
        App::$process_isolation = $this->origPi;
        App::$superglobals = $this->origSg;
        App::$cgi_timeout = $this->origTimeout;
    }

    private function file(string $name, string $content, int $mode = 0600): string
    {
        $path = self::$tmpDir . '/' . $name;
        @unlink($path);
        file_put_contents($path, $content);
        chmod($path, $mode);
        return $path;
    }

    // ── C7 ────────────────────────────────────────────────────────────────

    public function testStderrWriteBeforeHeadersKeepsStatusAndHeaders(): void
    {
        $f = $this->file('guard.php', "<?php\nerror_log('auth check: no session');\n"
            . "fwrite(STDERR, \"debug: redirecting\\n\");\n"
            . "header('Location: /login');\nhttp_response_code(302);\necho 'protected page';\n");
        $out = Dispatcher::cgiSubprocess($f);
        $g = RequestContext::instance();
        $this->assertSame(302, $g->status, 'the redirect status survives an earlier stderr write');
        $this->assertSame('/login', $g->zealphp_response->headers['Location'] ?? null);
        $this->assertSame('protected page', $out);
    }

    public function testStderrFloodBeforeMetadataDoesNotWedge(): void
    {
        App::$cgi_timeout = 10;
        $f = $this->file('flood.php', "<?php\nfwrite(STDERR, str_repeat(\"w\", 300000));\n"
            . "header('X-After: flood');\necho 'ok';\n");
        $t0 = microtime(true);
        $this->assertSame('ok', Dispatcher::cgiSubprocess($f));
        $this->assertSame('flood', RequestContext::instance()->zealphp_response->headers['X-After'] ?? null);
        $this->assertLessThan(5.0, microtime(true) - $t0);
    }

    public function testStreamingStillWorksWithFd3Metadata(): void
    {
        $f = $this->file('sse.php', "<?php\nerror_log('early');\nheader('Content-Type: text/event-stream');\n"
            . "echo \"data: one\\n\\n\";\nflush();\necho \"data: two\\n\\n\";\n");
        $this->assertNull(Dispatcher::cgiSubprocess($f));
        $written = RequestContext::instance()->openswoole_response->written;
        $this->assertStringContainsString('data: one', $written);
        $this->assertStringContainsString('data: two', $written);
    }

    // ── C12 ───────────────────────────────────────────────────────────────

    public function testLargeRequestContextDoesNotHitE2big(): void
    {
        $g = RequestContext::instance();
        $g->post = ['big' => str_repeat('a', 300_000)];
        $g->cookie = ['c' => str_repeat('b', 100_000)];
        $f = $this->file('big.php', "<?php\necho strlen(\$_POST['big']), ':', strlen(\$_COOKIE['c']), ':', "
            . "getenv('ZEALPHP_REQUEST_CONTEXT') === false ? 'no-env' : 'env';\n");
        $this->assertSame('300000:100000:no-env', Dispatcher::cgiSubprocess($f));
    }

    public function testPostBodyAndContextBothArrive(): void
    {
        $g = RequestContext::instance();
        $g->get = ['q' => 'v'];
        $g->zealphp_request->parent->body = str_repeat('p', 200_000);
        $f = $this->file('both.php', "<?php\necho \$_GET['q'], ':', strlen(file_get_contents('php://input'));\n");
        $this->assertSame('v:200000', Dispatcher::cgiSubprocess($f));
    }

    // ── C10 ───────────────────────────────────────────────────────────────

    public function testInterpreterStderrIsDrainedWhileStdoutIsRead(): void
    {
        App::$cgi_timeout = 10;
        $sh = $this->file('noisy.sh', "head -c 300000 /dev/zero | tr '\\0' e >&2\n"
            . "printf 'Content-Type: text/plain\\r\\n\\r\\n'\nprintf done\n");
        $t0 = microtime(true);
        $this->assertSame('done', Dispatcher::cgiSubprocess($sh, '/bin/sh'));
        $this->assertLessThan(5.0, microtime(true) - $t0, 'no wait for the CGI timeout');
    }

    public function testInterpreterOutputOverTheCapIsRefused(): void
    {
        App::$cgi_timeout = 30;
        $sh = $this->file('huge.sh', "printf 'Content-Type: text/plain\\r\\n\\r\\n'\n"
            . 'head -c ' . (Dispatcher::CGI_OUTPUT_CAP_BYTES + 1) . " /dev/zero\n");
        $this->assertSame(500, Dispatcher::cgiSubprocess($sh, '/bin/sh'));
    }

    // ── C11 ───────────────────────────────────────────────────────────────

    public function testScriptAliasRefusesAFileWithoutShebang(): void
    {
        $marker = self::$tmpDir . '/ran-noshebang';
        @unlink($marker);
        $f = $this->file('noshebang.cgi', "touch " . escapeshellarg($marker) . "\necho 'Content-Type: text/plain'\necho\necho ran\n", 0700);
        $this->assertSame(500, Dispatcher::cgiSubprocess($f));
        $this->assertFileDoesNotExist($marker, 'a file without #! is never handed to /bin/sh');
    }

    public function testScriptAliasRefusesWritableScripts(): void
    {
        foreach (['world' => 0757, 'group' => 0775] as $who => $mode) {
            $marker = self::$tmpDir . '/ran-' . $who;
            @unlink($marker);
            $f = $this->file($who . '.cgi', "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\nprintf 'Content-Type: text/plain\\n\\nran'\n", $mode);
            $this->assertSame(500, Dispatcher::cgiSubprocess($f), "{$who}-writable script refused");
            $this->assertFileDoesNotExist($marker);
        }
    }

    public function testScriptAliasRunsAProperScript(): void
    {
        $f = $this->file('ok.cgi', "#!/bin/sh\nprintf 'Content-Type: text/plain\\nX-Cgi: yes\\n\\nran'\n", 0755);
        $this->assertSame('ran', Dispatcher::cgiSubprocess($f));
        $this->assertSame('yes', RequestContext::instance()->zealphp_response->headers['X-Cgi'] ?? null);
    }

    // ── C8 ────────────────────────────────────────────────────────────────

    private static function sessionScript(string $dir): string
    {
        return "<?php\nsession_save_path(" . var_export($dir, true) . ");\n"
            . "session_set_cookie_params(['lifetime' => 3600, 'path' => '/app', 'domain' => 'example.test',"
            . " 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);\n"
            . "session_start();\n\$_SESSION['n'] = 1;\necho 'ok';\n";
    }

    public function testProcSessionCookieUsesTheAppsCookieParams(): void
    {
        App::$superglobals = true;
        App::$process_isolation = true; // cgiOwnsSessions()
        $f = $this->file('sess.php', self::sessionScript(self::$tmpDir));
        $before = time();
        $this->assertSame('ok', Dispatcher::cgiSubprocess($f));
        $cookies = RequestContext::instance()->zealphp_response->cookies;
        $this->assertCount(1, $cookies);
        [$name, $sid, $expires, $path, $domain, $secure, $httponly, $samesite] = $cookies[0];
        $this->assertSame('PHPSESSID', $name);
        $this->assertNotSame('', $sid);
        $this->assertGreaterThanOrEqual($before + 3600, $expires);
        $this->assertSame('/app', $path);
        $this->assertSame('example.test', $domain);
        $this->assertTrue($secure);
        $this->assertTrue($httponly);
        $this->assertSame('Strict', $samesite);
    }

    public function testPoolFrameCarriesTheSessionCookieParams(): void
    {
        $f = $this->file('psess.php', self::sessionScript(self::$tmpDir));
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $f], 5.0);
        } finally {
            $pool->close();
        }
        $this->assertSame('ok', $resp['body']);
        $this->assertSame(
            ['lifetime' => 3600, 'path' => '/app', 'domain' => 'example.test', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'],
            $resp['session_cookie'] ?? null
        );
    }

    public function testEmitWithoutReportedParamsKeepsTheOldSafeDefaults(): void
    {
        App::$superglobals = true;
        App::$process_isolation = true;
        $g = RequestContext::instance();
        $m = new \ReflectionMethod(Dispatcher::class, 'emitCgiSessionCookieFromMeta');
        $m->invoke(null, $g, $g->zealphp_response, ['session_id' => 'abc', 'session_name' => 'S']);
        $this->assertSame([['S', 'abc', 0, '/', '', false, true, '']], $g->zealphp_response->cookies);
    }

    public function testEmitNeverDropsHttpOnlyAndClampsBadParams(): void
    {
        App::$superglobals = true;
        App::$process_isolation = true;
        $g = RequestContext::instance();
        $m = new \ReflectionMethod(Dispatcher::class, 'emitCgiSessionCookieFromMeta');
        $m->invoke(null, $g, $g->zealphp_response, ['session_id' => 'abc', 'session_cookie' => [
            'lifetime' => -5, 'path' => '', 'domain' => ['x'], 'secure' => 'yes', 'httponly' => false, 'samesite' => 'Lax',
        ]]);
        $this->assertSame([['PHPSESSID', 'abc', 0, '/', '', true, true, 'Lax']], $g->zealphp_response->cookies);
    }

    public function testHostMintedCookieFollowsTheHostSecureSetting(): void
    {
        // C8 — the auto-start mint runs before the script, so only the host's
        // own setting is known: ZEALPHP_SESSION_SECURE decides Secure.
        App::$superglobals = true;
        App::$process_isolation = true;
        $origAuto = App::$cgi_session_auto_start;
        $origEnv = getenv('ZEALPHP_SESSION_SECURE');
        App::$cgi_session_auto_start = true;
        $m = new \ReflectionMethod(Dispatcher::class, 'mintCgiSession');
        try {
            foreach (['1' => true, '0' => false] as $env => $expected) {
                putenv('ZEALPHP_SESSION_SECURE=' . $env);
                $g = RequestContext::instance();
                $g->cookie = [];
                $g->openswoole_response = new class {
                    /** @var list<array<mixed>> */
                    public array $cookies = [];
                    public function isWritable(): bool { return true; }
                    public function cookie(mixed ...$a): bool { $this->cookies[] = $a; return true; }
                };
                $sid = $m->invoke(null, $g);
                $this->assertIsString($sid);
                $this->assertSame([['PHPSESSID', $sid, 0, '/', '', $expected, true]], $g->openswoole_response->cookies);
            }
        } finally {
            App::$cgi_session_auto_start = $origAuto;
            putenv($origEnv === false ? 'ZEALPHP_SESSION_SECURE' : 'ZEALPHP_SESSION_SECURE=' . $origEnv);
        }
    }
}
