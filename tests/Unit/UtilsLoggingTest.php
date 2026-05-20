<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

use function ZealPHP\resolve_log_dir;
use function ZealPHP\log_file_for;
use function ZealPHP\log_write;
use function ZealPHP\debug_logging_enabled;
use function ZealPHP\access_logging_enabled;
use function ZealPHP\async_logging_enabled;
use function ZealPHP\jTraceEx;
use function ZealPHP\elog;
use function ZealPHP\zlog;
use function ZealPHP\access_log;
use function ZealPHP\get_config;
use function ZealPHP\virtual;
use function ZealPHP\ignore_user_abort;
use function ZealPHP\output_add_rewrite_var;
use function ZealPHP\output_reset_rewrite_vars;
use function ZealPHP\set_error_handler;
use function ZealPHP\restore_error_handler;
use function ZealPHP\set_exception_handler;
use function ZealPHP\restore_exception_handler;
use function ZealPHP\register_shutdown_function;
use function ZealPHP\error_reporting;

/**
 * Coverage for the logging + error-handler-stack + misc Apache shim helpers
 * in src/utils.php that UtilsTest.php / LoggerTest.php do not exercise.
 *
 * These functions write to disk and read process-wide env. Several memoize in
 * `static` vars (resolve_log_dir, *_logging_enabled, log_file_for), so we drive
 * the env to a known-good state in a once-per-process bootstrap and assert the
 * resolved contract rather than toggling env mid-test.
 */
class UtilsLoggingTest extends TestCase
{
    private static string $logDir;
    private object $resp;

    public static function setUpBeforeClass(): void
    {
        // resolve_log_dir(), log_file_for(), and the *_logging_enabled() flags
        // all memoize in process-wide statics. If an earlier test already
        // triggered them (full-suite ordering), our env tweaks here would be
        // ignored — so these tests are written to be ordering-independent:
        // they discover the framework's ACTUAL resolved log paths via
        // log_file_for() and write/read against those, rather than asserting a
        // dir we pinned. We still nudge async off so the synchronous write path
        // is exercised when the static hasn't memoized yet.
        putenv('ZEALPHP_LOG_ASYNC=0');
        self::$logDir = (string)(\ZealPHP\resolve_log_dir() ?? sys_get_temp_dir());
    }

    public static function tearDownAfterClass(): void
    {
        putenv('ZEALPHP_LOG_ASYNC');
    }

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
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
        // A render-time base so zlog()'s timer computation has a numeric start.
        $g->session['__start_time'] = microtime(true);
    }

    // ── log-dir / log-file resolution ────────────────────────────

    public function testResolveLogDirIsWritableDirOrNull(): void
    {
        $dir = resolve_log_dir();
        // Memoized — null is acceptable only if no writable candidate existed,
        // but in CI /tmp/zealphp is always creatable.
        $this->assertNotNull($dir);
        $this->assertIsString($dir);
        $this->assertDirectoryExists($dir);
        $this->assertDirectoryIsWritable($dir);
        // Memoized — second call identical (same static).
        $this->assertSame($dir, resolve_log_dir());
    }

    public function testLogFileForEachKindResolvesUnderLogDir(): void
    {
        $dir    = (string)resolve_log_dir();
        $debug  = log_file_for('debug');
        $access = log_file_for('access');
        $zlog   = log_file_for('zlog');
        // Each kind resolves to its own filename under the resolved dir
        // (unless an env override file is set, which the suite doesn't set).
        $this->assertSame($dir . '/debug.log', $debug);
        $this->assertSame($dir . '/access.log', $access);
        $this->assertSame($dir . '/zlog.log', $zlog);
        // Cached per kind.
        $this->assertSame($debug, log_file_for('debug'));
    }

    // ── *_logging_enabled flags (memoized contract) ──────────────

    public function testLoggingEnabledFlagsAreBoolAndStable(): void
    {
        // These memoize process-wide; assert the type + stability contract
        // rather than a specific value (which depends on suite-wide env at
        // first-call time).
        $this->assertIsBool(debug_logging_enabled());
        $this->assertIsBool(access_logging_enabled());
        $this->assertIsBool(async_logging_enabled());
        $this->assertSame(debug_logging_enabled(), debug_logging_enabled());
        $this->assertSame(access_logging_enabled(), access_logging_enabled());
        $this->assertSame(async_logging_enabled(), async_logging_enabled());
    }

    // ── log_write (synchronous path) ─────────────────────────────

    public function testLogWriteDebugAppendsToDebugFile(): void
    {
        // log_write() always writes regardless of *_logging_enabled (those
        // gates live in elog/zlog/access_log). It targets log_file_for($kind).
        $file = (string)log_file_for('debug');
        $marker = 'LOGWRITE-' . uniqid();
        log_write($marker . "-A\n", 'debug');
        log_write($marker . "-B\n", 'debug');
        $this->assertFileExists($file);
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString($marker . '-A', $contents);
        $this->assertStringContainsString($marker . '-B', $contents);
    }

    public function testLogWriteAccessAndZlogUseDistinctFiles(): void
    {
        $access = (string)log_file_for('access');
        $zlog   = (string)log_file_for('zlog');
        $this->assertNotSame($access, $zlog);
        $am = 'ACCESS-' . uniqid();
        $zm = 'ZLOG-' . uniqid();
        log_write($am . "\n", 'access');
        log_write($zm . "\n", 'zlog');
        $this->assertStringContainsString($am, (string)file_get_contents($access));
        $this->assertStringContainsString($zm, (string)file_get_contents($zlog));
    }

    // ── jTraceEx ─────────────────────────────────────────────────

    public function testJTraceExBasicShape(): void
    {
        $e = new \RuntimeException('boom');
        $trace = jTraceEx($e);
        $this->assertIsString($trace);
        $this->assertStringContainsString('RuntimeException: boom', $trace);
        $this->assertStringContainsString(' at ', $trace);
    }

    public function testJTraceExIncludesCausedByForChainedException(): void
    {
        $root  = new \LogicException('root cause');
        $outer = new \RuntimeException('outer failure', 0, $root);
        $trace = jTraceEx($outer);
        $this->assertStringContainsString('RuntimeException: outer failure', $trace);
        $this->assertStringContainsString('Caused by: ', $trace);
        $this->assertStringContainsString('LogicException: root cause', $trace);
    }

    // ── elog / zlog ──────────────────────────────────────────────

    public function testElogWritesTaggedEntry(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('debug');
        $marker = 'elog-' . uniqid();
        elog($marker, 'unittag');
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString('[unittag]', $contents);
        $this->assertStringContainsString($marker, $contents);
    }

    public function testElogWordpressTagIsSuppressed(): void
    {
        $file = (string)log_file_for('debug');
        $marker = 'wp-suppressed-' . uniqid();
        elog($marker, 'wordpress');
        // The 'wordpress' tag returns early before any write — the marker
        // never reaches the debug file (regardless of logging-enabled state).
        $contents = is_file($file) ? (string)file_get_contents($file) : '';
        $this->assertStringNotContainsString($marker, $contents);
    }

    public function testZlogWritesToZlogFileWithValidTag(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('zlog');
        $marker = 'zlog-info-' . uniqid();
        $_SERVER['REQUEST_URI'] = '/zlog/test';
        zlog($marker, 'info');
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString('#info', $contents);
        $this->assertStringContainsString($marker, $contents);
    }

    public function testZlogArrayIsJsonEncoded(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('zlog');
        $u = uniqid();
        $_SERVER['REQUEST_URI'] = '/zlog/array';
        zlog(['k' => "v$u", 'n' => 5], 'debug');
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString('"k":"v' . $u . '"', $contents);
        $this->assertStringContainsString('"n":5', $contents);
    }

    public function testZlogInvalidTagIsDropped(): void
    {
        $file = (string)log_file_for('zlog');
        $marker = 'badtag-' . uniqid();
        $_SERVER['REQUEST_URI'] = '/zlog/badtag';
        // Invalid tag → returns before write regardless of logging-enabled.
        zlog($marker, 'not-a-valid-tag');
        $contents = is_file($file) ? (string)file_get_contents($file) : '';
        $this->assertStringNotContainsString($marker, $contents);
    }

    public function testZlogFilterMissReturnsEarly(): void
    {
        $file = (string)log_file_for('zlog');
        $marker = 'filtered-out-' . uniqid();
        $_SERVER['REQUEST_URI'] = '/some/other/path';
        // Filter requires REQUEST_URI to contain 'wont-match' → returns early.
        zlog($marker, 'info', 'wont-match');
        $contents = is_file($file) ? (string)file_get_contents($file) : '';
        $this->assertStringNotContainsString($marker, $contents);
    }

    public function testZlogFilterHitWrites(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('zlog');
        $marker = 'filter-hit-' . uniqid();
        $_SERVER['REQUEST_URI'] = '/match/here/now';
        zlog($marker, 'info', 'match');
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString($marker, $contents);
    }

    public function testZlogInvertFilterReturnsEarly(): void
    {
        $file = (string)log_file_for('zlog');
        $marker = 'inverted-' . uniqid();
        $_SERVER['REQUEST_URI'] = '/invert/here';
        // Filter matches REQUEST_URI, but invert_filter=true → returns before write.
        zlog($marker, 'info', 'invert', true);
        $contents = is_file($file) ? (string)file_get_contents($file) : '';
        $this->assertStringNotContainsString($marker, $contents);
    }

    public function testZlogObjectIsPurifiedAndEncoded(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('zlog');
        $u = uniqid();
        $obj = new \stdClass();
        $obj->field = "objval-$u";
        $_SERVER['REQUEST_URI'] = '/zlog/object';
        // is_object($log) → purify_array() → is_array → json_encode.
        zlog($obj, 'debug');
        $contents = (string)file_get_contents($file);
        $this->assertStringContainsString("objval-$u", $contents);
    }

    public function testZlogDefaultsRequestUriToCliWhenUnset(): void
    {
        if (!debug_logging_enabled()) {
            $this->markTestSkipped('debug logging disabled in this process');
        }
        $file = (string)log_file_for('zlog');
        $saved = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']);
        try {
            $marker = 'cli-uri-' . uniqid();
            // No REQUEST_URI → zlog sets $_SERVER['REQUEST_URI'] = 'cli'.
            zlog($marker, 'info');
            $this->assertSame('cli', $_SERVER['REQUEST_URI']);
            $contents = (string)file_get_contents($file);
            $this->assertStringContainsString($marker, $contents);
        } finally {
            if ($saved === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $saved;
            }
        }
    }

    // ── access_log ───────────────────────────────────────────────

    public function testAccessLogWritesFormattedLine(): void
    {
        if (!access_logging_enabled()) {
            $this->markTestSkipped('access logging disabled in this process');
        }
        $file = (string)log_file_for('access');
        $before = is_file($file) ? (string)file_get_contents($file) : '';
        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'    => '203.0.113.5',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/access/test-' . uniqid(),
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        access_log(200, 1234, 0.005);
        $this->assertFileExists($file);
        $after = (string)file_get_contents($file);
        // A new line was appended (length grew) and it carries the status.
        $this->assertGreaterThan(strlen($before), strlen($after));
        $this->assertStringContainsString('200', substr($after, strlen($before)));
    }

    // ── get_config ───────────────────────────────────────────────

    public function testGetConfigReadsGlobalJson(): void
    {
        $GLOBALS['__site_config'] = json_encode(['mykey' => 'myval', 'num' => 7]);
        $this->assertSame('myval', get_config('mykey'));
        $this->assertSame(7, get_config('num'));
        $this->assertNull(get_config('absent'));
    }

    public function testGetConfigNullWhenConfigNotScalar(): void
    {
        unset($GLOBALS['__site_config']);
        $this->assertNull(get_config('anything'));
    }

    // ── error/exception-handler stacks ───────────────────────────
    //
    // These call ZealPHP\set_error_handler / restore_error_handler directly
    // (the per-request $g stack functions — they never touch PHP's native
    // handler chain). Once any earlier test boots App, uopz globally
    // redirects PHP's native set_error_handler / restore_error_handler to
    // those same ZealPHP\* functions (App.php:493). That makes PHPUnit's own
    // per-test handler bookkeeping (which calls the native, now-overridden
    // functions to snapshot + restore) a no-op, which it flags risky
    // ("removed error handlers other than its own"). #[WithoutErrorHandler]
    // tells PHPUnit not to install/track its error handler around the test,
    // so the heuristic doesn't run — the framework functions are still fully
    // exercised.

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testSetAndRestoreErrorHandler(): void
    {
        $g = RequestContext::instance();
        $g->error_handlers_stack = [];
        $h1 = function () { return true; };
        $h2 = function () { return false; };

        // First registration returns previous (none).
        $prev = set_error_handler($h1);
        $this->assertNull($prev);
        // Second registration returns the first handler.
        $prev2 = set_error_handler($h2);
        $this->assertSame($h1, $prev2);
        $this->assertCount(2, $g->error_handlers_stack);

        $this->assertTrue(restore_error_handler());
        $this->assertCount(1, $g->error_handlers_stack);

        // Passing null pops the stack.
        set_error_handler(null);
        $this->assertCount(0, $g->error_handlers_stack);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testSetAndRestoreExceptionHandler(): void
    {
        $g = RequestContext::instance();
        $g->exception_handlers_stack = [];
        $h1 = function (\Throwable $t): void {};
        $h2 = function (\Throwable $t): void {};

        $this->assertNull(set_exception_handler($h1));
        $this->assertSame($h1, set_exception_handler($h2));
        $this->assertCount(2, $g->exception_handlers_stack);

        $this->assertTrue(restore_exception_handler());
        $this->assertCount(1, $g->exception_handlers_stack);

        set_exception_handler(null);
        $this->assertCount(0, $g->exception_handlers_stack);
    }

    public function testRegisterShutdownFunctionAppendsToStack(): void
    {
        $g = RequestContext::instance();
        $g->shutdown_functions = [];
        register_shutdown_function(function () {}, 'arg1', 'arg2');
        register_shutdown_function(function () {});
        $this->assertCount(2, $g->shutdown_functions);
        $this->assertSame(['arg1', 'arg2'], $g->shutdown_functions[0][1]);
        $this->assertSame([], $g->shutdown_functions[1][1]);
    }

    public function testErrorReportingGetSet(): void
    {
        $g = RequestContext::instance();
        $g->error_reporting_level = null;
        // First read falls back to App boot capture (an int).
        $first = error_reporting();
        $this->assertIsInt($first);
        // Set then read back.
        $prev = error_reporting(E_ERROR | E_WARNING);
        $this->assertIsInt($prev);
        $this->assertSame(E_ERROR | E_WARNING, error_reporting());
    }

    // ── misc Apache / output shims ───────────────────────────────

    public function testVirtualReturnsFalse(): void
    {
        $this->assertFalse(virtual('/some/subrequest'));
    }

    public function testIgnoreUserAbortGetSet(): void
    {
        $g = RequestContext::instance();
        $g->ignore_user_abort_state = 0;
        // Returns previous (0) and sets new state.
        $this->assertSame(0, ignore_user_abort(true));
        $this->assertSame(1, ignore_user_abort(false));
        // Read-only with null returns current without changing it.
        $this->assertSame(0, ignore_user_abort(null));
        $this->assertSame(0, ignore_user_abort());
    }

    public function testOutputRewriteVarShims(): void
    {
        $this->assertFalse(output_add_rewrite_var('foo', 'bar'));
        $this->assertTrue(output_reset_rewrite_vars());
    }

    public function testApacheGetenvWalkToTopVariant(): void
    {
        $g = RequestContext::instance();
        $g->apacheContext = null;
        // walk_to_top arg is accepted; getenv on a fresh context is false.
        $this->assertFalse(\ZealPHP\apache_getenv('NOPE', true));
        \ZealPHP\apache_setenv('WALKVAR', 'wval', true);
        $this->assertSame('wval', \ZealPHP\apache_getenv('WALKVAR', true));
    }
}
