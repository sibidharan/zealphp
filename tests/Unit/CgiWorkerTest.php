<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

/**
 * Coverage + protocol tests for src/cgi_worker.php.
 *
 * cgi_worker.php is NOT a class — it's a standalone CLI subprocess entry point
 * launched by ZealPHP's CGI bridge (App::include in superglobals(true) /
 * processIsolation(true) mode) via proc_open("php cgi_worker.php /file.php").
 * It receives a JSON request context in the ZEALPHP_REQUEST_CONTEXT env var,
 * populates $_GET/$_POST/$_SERVER/$_COOKIE/$_FILES, captures header()/setcookie()/
 * http_response_code() via uopz, applies the universal return contract, and
 * writes a newline-terminated JSON metadata frame to stderr + the body to stdout.
 *
 * Two strategies are combined here:
 *
 *  1. ONE in-process `require` of cgi_worker.php (testInProcessRequireRichContract).
 *     The script defines top-level functions (__z_send_meta, apache_* shims), so
 *     it can only be require'd ONCE per process — a second require fatals with
 *     "Cannot redeclare". This single require is what makes the bulk of the file
 *     count toward the merged pcov coverage report (the test process is the one
 *     pcov instruments). The rich fixture calls every uopz-overridden builtin so
 *     the closure bodies execute too.
 *
 *  2. SUBPROCESS invocations (proc_open) for the protocol-frame assertions and
 *     the mutually-exclusive return-contract variants (int / array / string /
 *     echo / generator / closure / 404 / thrown error / flush-streaming). These
 *     verify cgi_worker's observable wire protocol end-to-end.
 *
 * Note: separate-process isolation is intentionally NOT used — the in-process
 * require must run in the pcov-instrumented worker for its lines to be recorded.
 */
final class CgiWorkerTest extends TestCase
{
    private static string $tmpDir = '';

    /** @var array<int, string> absolute paths of fixtures to clean up */
    private array $fixtures = [];

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_cgi_test_' . getmypid();
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

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $f) {
            // is_file guard: move_uploaded_file() may have relocated a tracked
            // fixture, and cgi_worker.php installs a global error handler that
            // echoes warnings even for @unlink() of a missing path.
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->fixtures = [];
    }

    private function cgiWorkerPath(): string
    {
        return ZEALPHP_ROOT . '/src/cgi_worker.php';
    }

    /** Write a fixture PHP file under the temp dir and track it for cleanup. */
    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        $this->fixtures[] = $path;
        return $path;
    }

    /**
     * Run cgi_worker.php as a real subprocess and capture the protocol.
     *
     * @param array<string, mixed> $ctx ZEALPHP_REQUEST_CONTEXT payload
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runSubprocess(string $file, array $ctx = [], string $stdin = ''): array
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin (POST body)
            1 => ['pipe', 'w'], // stdout (body)
            2 => ['pipe', 'w'], // stderr (metadata frame)
        ];
        $env = $_ENV;
        $env['ZEALPHP_REQUEST_CONTEXT'] = json_encode($ctx);

        $cmd = [PHP_BINARY, $this->cgiWorkerPath(), $file];

        $proc = proc_open($cmd, $descriptors, $pipes, ZEALPHP_ROOT, $env);
        $this->assertIsResource($proc, 'proc_open should launch cgi_worker.php');

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
            'exit'   => $exit,
        ];
    }

    /**
     * Parse the stderr metadata frame: cgi_worker writes a single
     * newline-terminated JSON line, then (for non-streaming responses) the
     * register_shutdown_function flushes the body to stdout.
     *
     * @return array<string, mixed>
     */
    private function parseMeta(string $stderr): array
    {
        $line = strtok($stderr, "\n");
        $this->assertNotFalse($line, "stderr should contain a metadata line, got: $stderr");
        $meta = json_decode((string) $line, true);
        $this->assertIsArray($meta, "metadata frame should be JSON, got: $line");
        return $meta;
    }

    // ---------------------------------------------------------------------
    // In-process require — the coverage-bearing test (runs ONCE).
    // ---------------------------------------------------------------------

    /**
     * Require cgi_worker.php in-process with a fixture that exercises every
     * uopz-overridden builtin and the array return contract. Because the test
     * process is the pcov-instrumented one, this require makes the bulk of
     * cgi_worker.php (and its override-closure bodies) count toward coverage.
     *
     * The fixture deliberately avoids apache_*()/virtual() — those shims are
     * already declared by the autoloaded src/apache_shims.php, so cgi_worker's
     * own !function_exists() branches are skipped, and the autoloaded virtual()
     * touches App::$cwd which isn't initialised in the bare test process.
     */
    public function testInProcessRequireRichContract(): void
    {
        // Upload fixtures so is_uploaded_file()/move_uploaded_file() exercise the
        // success path. A second upload uses an array-form tmp_name so the
        // array-branch of cgi_worker's $_FILES registry loop is covered.
        $upTmp = self::$tmpDir . '/upload_src.bin';
        file_put_contents($upTmp, 'payload');
        $this->fixtures[] = $upTmp;
        $upArr = self::$tmpDir . '/upload_arr.bin';
        file_put_contents($upArr, 'arrpayload');
        $this->fixtures[] = $upArr;
        $moveDest = self::$tmpDir . '/upload_dest.bin';
        $this->fixtures[] = $moveDest;
        // A non-uploaded file: move_uploaded_file() must refuse it (return false).
        $notUploaded = self::$tmpDir . '/not_uploaded.bin';
        file_put_contents($notUploaded, 'x');
        $this->fixtures[] = $notUploaded;

        $richFixture = $this->fixture('rich.php', <<<'PHP'
<?php
// Ensure cgi_worker's set_error_handler() fires regardless of an error_reporting
// level a previously-run test in the same process may have lowered.
error_reporting(E_ALL);
header('Content-Type: text/plain');
header('X-First: 1');
header('X-First: 2', true);            // replace
header('X-Multi: a', false);           // append (no replace)
header('HTTP/1.1 207 Multi-Status');   // status line parse
header('X-Removeme: gone');
header_remove('X-Removeme');           // targeted removal
header('Set-By-Code: yes', true, 418); // response_code arg path
$list = headers_list();
$sent = headers_sent($f, $l);
setcookie('sid', 'abc', 0, '/', 'example.com', true, true, 'Lax');
setrawcookie('raw', 'v', 0, '/');
http_response_code(202);               // wins as last status write
$up = is_uploaded_file($_FILES['doc']['tmp_name'] ?? '');
$moved = move_uploaded_file($_FILES['doc']['tmp_name'] ?? '', getenv('ZEALPHP_TEST_MOVE_DEST'));
// move of a non-uploaded file → false (registry guard).
$refused = move_uploaded_file(getenv('ZEALPHP_TEST_NOT_UPLOADED'), getenv('ZEALPHP_TEST_MOVE_DEST') . '.2');
ob_implicit_flush(true);
echo 'rich body name=' . ($_GET['name'] ?? '');
// Trip every arm of cgi_worker's set_error_handler() match(). The handler
// echoes HTML blocks, so these run AFTER the body marker.
trigger_error('cgi worker warning probe', E_USER_WARNING);
trigger_error('cgi worker notice probe', E_USER_NOTICE);
trigger_error('cgi worker deprecated probe', E_USER_DEPRECATED);
return ['handled' => true, 'list' => $list, 'sent' => $sent, 'up' => $up, 'moved' => $moved, 'refused' => $refused];
PHP);

        putenv('ZEALPHP_TEST_MOVE_DEST=' . $moveDest);
        putenv('ZEALPHP_TEST_NOT_UPLOADED=' . $notUploaded);

        $ctx = [
            'get'    => ['name' => 'Rich'],
            'post'   => ['k' => 'v'],
            'cookie' => ['existing' => '1'],
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'cgi.test',
                'CONTENT_TYPE'   => 'text/plain',
            ],
            'env'    => ['CUSTOM_ENV' => 'set'],
            'files'  => [
                'doc'   => ['name' => 'd.txt', 'tmp_name' => $upTmp, 'error' => 0, 'size' => 7],
                // Array-form tmp_name (multi-file field) → covers the array branch
                // of the $_FILES → $__z_uploaded registry loop.
                'multi' => ['name' => ['a.bin'], 'tmp_name' => [$upArr], 'error' => [0], 'size' => [10]],
            ],
        ];

        // Drive cgi_worker.php in this very process. Set the LOCAL $argv — `require`
        // inside a method binds $argv to the method scope, and cgi_worker reads
        // $argv[1] for the include path.
        putenv('ZEALPHP_REQUEST_CONTEXT=' . json_encode($ctx));
        $argv = ['cgi_worker.php', $richFixture];

        // `require` inside a method binds cgi_worker.php's top-level variables to
        // THIS method scope. But its uopz override closures reference them via
        // `global $__z_headers` etc. — a distinct, uninitialised global scope.
        // Pre-seed those globals so header()/setcookie()/http_response_code()
        // write somewhere valid (otherwise array_filter() on null fatals). The
        // override-written state therefore lives in $GLOBALS; the return-contract
        // state ($__z_return_value/$__z_has_return) lives in this method scope.
        $GLOBALS['__z_headers'] = [];
        $GLOBALS['__z_cookies'] = [];
        $GLOBALS['__z_rawcookies'] = [];
        $GLOBALS['__z_status'] = 200;
        // The uploaded-file registry is also populated at cgi_worker's top level
        // (method scope here), so mirror it into the global the closures read.
        // Both the string-form and array-form uploads are registered.
        $GLOBALS['__z_uploaded'] = [$upTmp => true, $upArr => true];
        $GLOBALS['__z_meta_sent'] = false;

        // cgi_worker.php opens its own ob_start() (and a register_shutdown_function
        // that drains it) for the streaming path. Track the buffer level so we can
        // restore it after the require — otherwise the dangling buffer trips
        // PHPUnit's unexpected-output detection.
        $bufLevelBefore = ob_get_level();
        ob_start();
        require $this->cgiWorkerPath();
        // LIFO: this ob_get_clean() pops cgi_worker's inner buffer, returning the
        // echoed body.
        $body = ob_get_clean();
        // Drain any buffer cgi_worker left open above our starting level.
        while (ob_get_level() > $bufLevelBefore) {
            ob_end_clean();
        }
        // cgi_worker.php installs a set_error_handler() it never restores; pop it
        // so PHPUnit doesn't flag the test as risky for leaking a handler.
        restore_error_handler();
        // Neutralise cgi_worker's register_shutdown_function. The require ran in
        // THIS method scope, so cgi_worker's top-level vars are method locals — but
        // its shutdown closure uses `global $__z_meta_sent`, which is a distinct
        // (uninitialised) global. Set that real global to true so the handler
        // becomes a no-op at process shutdown (it would otherwise fwrite() a stray
        // metadata frame to STDERR when PHPUnit exits).
        $GLOBALS['__z_meta_sent'] = true;

        $this->assertStringStartsWith('rich body name=Rich', $body, 'echoed body should be captured');
        $this->assertStringContainsString('Warning', $body, 'error handler renders warnings inline');
        $this->assertStringContainsString('cgi worker warning probe', $body);

        // Override-written state lives in the globals the closures reference.
        $headers    = $GLOBALS['__z_headers'];
        $cookies    = $GLOBALS['__z_cookies'];
        $rawcookies = $GLOBALS['__z_rawcookies'];
        $status     = $GLOBALS['__z_status'];

        $this->assertSame(202, $status, 'http_response_code(202) is the last status write');

        // Header capture: replace, append, removal, status-line parse.
        $headerNames = array_map(static fn($h) => $h[0], $headers);
        $this->assertContains('Content-Type', $headerNames);
        $this->assertContains('X-Multi', $headerNames);
        $this->assertContains('Set-By-Code', $headerNames);
        $this->assertNotContains('X-Removeme', $headerNames, 'header_remove() should drop the header');
        // X-First was set twice with replace=true → only the last value survives.
        $xFirst = array_values(array_filter($headers, static fn($h) => $h[0] === 'X-First'));
        $this->assertCount(1, $xFirst, 'replace=true collapses duplicate header to one');
        $this->assertSame('2', $xFirst[0][1]);

        // Cookie capture (setcookie + setrawcookie).
        $this->assertCount(1, $cookies);
        $this->assertSame('sid', $cookies[0][0]);
        $this->assertSame('abc', $cookies[0][1]);
        $this->assertCount(1, $rawcookies);
        $this->assertSame('raw', $rawcookies[0][0]);

        // Universal return contract: array return surfaced verbatim.
        $this->assertTrue($__z_has_return);
        $this->assertIsArray($__z_return_value);
        $this->assertTrue($__z_return_value['handled']);
        $this->assertTrue($__z_return_value['up'], 'is_uploaded_file() recognises the context-declared tmp file');
        $this->assertTrue($__z_return_value['moved'], 'move_uploaded_file() succeeds for an uploaded file');
        $this->assertFalse($__z_return_value['refused'], 'move_uploaded_file() refuses a non-uploaded file');
        $this->assertFalse($__z_return_value['sent'], 'headers_sent() override always reports false');
        $this->assertContains('Content-Type: text/plain', $__z_return_value['list'], 'headers_list() formats name: value');

        // Globals were populated from the context.
        $this->assertSame('Rich', $_GET['name']);
        $this->assertSame('v', $_POST['k']);
        $this->assertSame('1', $_COOKIE['existing']);
        $this->assertSame('cgi.test', $_SERVER['HTTP_HOST']);
        $this->assertSame('set', $_ENV['CUSTOM_ENV']);
        // $_REQUEST is the merge of GET + POST.
        $this->assertSame('Rich', $_REQUEST['name']);
        $this->assertSame('v', $_REQUEST['k']);

        // The uploaded file was moved.
        $this->assertFileExists($moveDest);

        // Every arm of the error handler's match() fired.
        $this->assertStringContainsString('Notice', $body);
        $this->assertStringContainsString('Deprecated', $body);

        // header_remove() with no arg clears the whole header set (clear-all arm),
        // covering the branch the targeted-removal call above did not. Safe to run
        // after the header assertions. (__z_send_meta()'s body is not driven here:
        // it writes the frame straight to fd 2, which we cannot rebind from
        // userland — the subprocess tests exercise the frame end-to-end instead.)
        header_remove();
        $this->assertSame([], $GLOBALS['__z_headers'], 'header_remove() with no arg clears all headers');
    }

    // ---------------------------------------------------------------------
    // Subprocess protocol tests — verify the wire contract end-to-end.
    // ---------------------------------------------------------------------

    public function testSubprocessEchoBodyAndMetadataFrame(): void
    {
        $f = $this->fixture('echo.php', "<?php\necho 'Hello ' . (\$_GET['name'] ?? 'world');\n");
        $r = $this->runSubprocess($f, ['get' => ['name' => 'Zeal'], 'server' => ['REQUEST_METHOD' => 'GET']]);

        $this->assertSame(0, $r['exit']);
        $this->assertSame('Hello Zeal', $r['stdout'], 'body streams to stdout');

        // Metadata frame is a single newline-terminated JSON line on stderr.
        $this->assertStringEndsWith("\n", strtok($r['stderr'], "\0") . "\n");
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(200, $meta['status_code']);
        $this->assertArrayHasKey('headers', $meta);
        $this->assertArrayHasKey('cookies', $meta);
        $this->assertArrayHasKey('rawcookies', $meta);
        // No-explicit-return echo template: PHP include() returns int 1.
        $this->assertSame(1, $meta['return_value']);
    }

    public function testSubprocessFilterInputReadsSuperglobals(): void
    {
        // #316 — the subprocess runs under the CLI SAPI, whose internal request
        // tables are EMPTY: native filter_input() returns null even though
        // $_GET/$_POST/$_COOKIE/$_SERVER are fully populated from the IPC
        // context. The worker must override filter_input()/filter_input_array()
        // to read the live superglobals, like the main OpenSwoole worker does.
        $f = $this->fixture('filter_input.php', '<?php
            echo json_encode([
                "get"         => filter_input(INPUT_GET, "name"),
                "get_int_ok"  => filter_input(INPUT_GET, "age", FILTER_VALIDATE_INT),
                "get_int_bad" => filter_input(INPUT_GET, "name", FILTER_VALIDATE_INT),
                "post"        => filter_input(INPUT_POST, "city"),
                "cookie"      => filter_input(INPUT_COOKIE, "sid"),
                "server"      => filter_input(INPUT_SERVER, "REQUEST_METHOD"),
                "missing"     => filter_input(INPUT_GET, "nope"),
                "arr"         => filter_input_array(INPUT_GET, ["age" => FILTER_VALIDATE_INT], false),
            ]);
        ');
        $r = $this->runSubprocess($f, [
            'get'    => ['name' => 'alice', 'age' => '42'],
            'post'   => ['city' => 'mtl'],
            'cookie' => ['sid' => 'abc'],
            'server' => ['REQUEST_METHOD' => 'POST'],
        ]);

        $this->assertSame(0, $r['exit']);
        $out = json_decode($r['stdout'], true);
        $this->assertIsArray($out, 'fixture output should be JSON, got: ' . $r['stdout']);
        $this->assertSame('alice', $out['get'], 'INPUT_GET reads $_GET (#316)');
        $this->assertSame(42, $out['get_int_ok'], 'FILTER_VALIDATE_INT passes through');
        $this->assertFalse($out['get_int_bad'], 'failed validation is false, not null');
        $this->assertSame('mtl', $out['post']);
        $this->assertSame('abc', $out['cookie']);
        $this->assertSame('POST', $out['server']);
        $this->assertNull($out['missing'], 'missing key stays null');
        $this->assertSame(['age' => 42], $out['arr'], 'filter_input_array reads the same bag');
    }

    public function testSubprocessIntReturnContract(): void
    {
        $f = $this->fixture('int.php', "<?php\nreturn 404;\n");
        $r = $this->runSubprocess($f);

        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(404, $meta['return_value'], 'int return rides return_value, not status_code');
        $this->assertSame(200, $meta['status_code'], 'cgi_worker leaves status mapping to the host');
        $this->assertSame('', $r['stdout']);
    }

    public function testSubprocessArrayReturnContract(): void
    {
        $f = $this->fixture('array.php', "<?php\nreturn ['ok' => true, 'name' => \$_POST['name'] ?? null];\n");
        $r = $this->runSubprocess($f, ['post' => ['name' => 'Sub']]);

        $meta = $this->parseMeta($r['stderr']);
        $this->assertIsArray($meta['return_value']);
        $this->assertTrue($meta['return_value']['ok']);
        $this->assertSame('Sub', $meta['return_value']['name'], '$_POST populated from context');
    }

    public function testSubprocessStringReturnContract(): void
    {
        $f = $this->fixture('str.php', "<?php\nreturn 'explicit string body';\n");
        $r = $this->runSubprocess($f);

        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame('explicit string body', $meta['return_value']);
    }

    public function testSubprocessHeaderCookieStatusCapture(): void
    {
        $f = $this->fixture('headers.php', <<<'PHP'
<?php
header('X-Custom: yes');
header('Content-Type: application/json');
setcookie('sid', 'abc123', 0, '/');
setrawcookie('raw', 'v', 0, '/');
http_response_code(201);
echo 'with headers';
PHP);
        $r = $this->runSubprocess($f);

        $this->assertSame('with headers', $r['stdout']);
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(201, $meta['status_code']);

        $headerPairs = [];
        foreach ($meta['headers'] as $pair) {
            $headerPairs[$pair[0]] = $pair[1];
        }
        $this->assertSame('yes', $headerPairs['X-Custom']);
        $this->assertSame('application/json', $headerPairs['Content-Type']);

        $this->assertSame('sid', $meta['cookies'][0][0]);
        $this->assertSame('abc123', $meta['cookies'][0][1]);
        $this->assertSame('raw', $meta['rawcookies'][0][0]);
    }

    /**
     * #357 — header_register_callback() parity in legacy-cgi. A callback the
     * script registers must fire just before the buffered headers are captured,
     * so header() calls inside it still reach the wire. Before the fix the CGI
     * subprocess captured $__z_headers WITHOUT ever invoking the callback, so
     * any header it set was silently dropped (works in mixed/coroutine-legacy,
     * absent under legacy-cgi).
     */
    public function testSubprocessFiresHeaderRegisterCallbackBeforeHeaderCapture(): void
    {
        $f = $this->fixture('hrc.php', <<<'PHP'
<?php
header('X-Before: set-directly');
header_register_callback(function () {
    header('X-From-Callback: yes');
    // A callback may also REPLACE an already-set header — proves the callback
    // runs before capture, not after.
    header('X-Before: rewritten-in-callback', true);
});
echo 'callback body';
PHP);
        $r = $this->runSubprocess($f);

        $this->assertSame('callback body', $r['stdout']);
        $meta = $this->parseMeta($r['stderr']);

        $headerPairs = [];
        foreach ($meta['headers'] as $pair) {
            $headerPairs[$pair[0]] = $pair[1];
        }
        $this->assertArrayHasKey(
            'X-From-Callback',
            $headerPairs,
            'header() inside header_register_callback() must reach the captured headers'
        );
        $this->assertSame('yes', $headerPairs['X-From-Callback']);
        $this->assertSame(
            'rewritten-in-callback',
            $headerPairs['X-Before'],
            'callback fires before header capture, so its replace wins'
        );
    }

    public function testSubprocessGeneratorIsConsumedInline(): void
    {
        $f = $this->fixture('gen.php', "<?php\nreturn (function(){ yield '<a>'; yield '<b>'; })();\n");
        $r = $this->runSubprocess($f);

        $this->assertSame('<a><b>', $r['stdout'], 'generator chunks are echoed inline');
        $meta = $this->parseMeta($r['stderr']);
        // After streaming, return_value is nulled so the host does not double-emit.
        $this->assertNull($meta['return_value']);
    }

    public function testSubprocessClosureReturnIsInvoked(): void
    {
        $f = $this->fixture('closure.php', "<?php\nreturn function(){ return 'closure result body'; };\n");
        $r = $this->runSubprocess($f);

        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame('closure result body', $meta['return_value'], 'closure return is invoked with no args');
    }

    public function testSubprocessMissingFileReturns404(): void
    {
        $r = $this->runSubprocess(self::$tmpDir . '/does_not_exist.php');

        $this->assertSame(1, $r['exit'], 'missing file exits 1');
        $this->assertStringContainsString('404 Not Found', $r['stdout']);
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(404, $meta['status_code']);
    }

    public function testSubprocessThrownErrorBecomes500(): void
    {
        $f = $this->fixture('err.php', "<?php\nthrow new \\RuntimeException('boom');\n");
        $r = $this->runSubprocess($f);

        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(500, $meta['status_code'], 'uncaught throwable → 500');
        $this->assertStringContainsString('boom', $r['stdout'], 'error message rendered in body');
    }

    public function testSubprocessFlushStreamsMetadataBeforeBody(): void
    {
        $f = $this->fixture('flush.php', <<<'PHP'
<?php
header('Content-Type: text/event-stream');
echo "data: chunk1\n\n";
flush();
echo "data: chunk2\n\n";
ob_flush();
PHP);
        $r = $this->runSubprocess($f);

        // flush()/ob_flush() send the metadata frame first, then stream chunks.
        $this->assertStringContainsString('data: chunk1', $r['stdout']);
        $this->assertStringContainsString('data: chunk2', $r['stdout']);
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(200, $meta['status_code']);
        $ct = [];
        foreach ($meta['headers'] as $pair) {
            $ct[$pair[0]] = $pair[1];
        }
        $this->assertSame('text/event-stream', $ct['Content-Type']);
        // Metadata frame was emitted by flush() before the shutdown handler, so
        // no return_value rides the frame for a streaming response.
        $this->assertArrayNotHasKey('return_value', $meta);
    }

    public function testSubprocessEmptyContextDefaultsCleanly(): void
    {
        $f = $this->fixture('empty.php', "<?php\necho 'ok';\n");
        $r = $this->runSubprocess($f, []);

        $this->assertSame('ok', $r['stdout']);
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(200, $meta['status_code']);
    }
}
