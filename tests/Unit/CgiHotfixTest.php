<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Unit tests for the 5 CGI security/reliability hot-fixes:
 *
 * 1. httpoxy CVE — HTTP_PROXY stripped from child env (buildCgiEnv)
 * 2. Subprocess timeout — hung child is killed, 500 returned
 * 3. stderr drain — remaining stderr after metadata line routed via elog()
 * 4. Missing CGI env vars — GATEWAY_INTERFACE, SERVER_SOFTWARE, DOCUMENT_ROOT,
 *    SERVER_ADMIN, AUTH_TYPE, REMOTE_USER, REMOTE_PORT, PATH_TRANSLATED
 * 5. Status: header parsing — CGI "Status: NNN Reason" sets HTTP status
 */
final class CgiHotfixTest extends TestCase
{
    private static string $tmpDir = '';

    /** @var array<int, string> */
    private array $fixtures = [];

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/zealphp_cgihotfix_' . getmypid();
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }
        // App::$cwd is a typed property set only during App::init(). Initialize
        // it to the project root so buildCgiEnv() / resolveDocumentRoot() work
        // in a bare test context without booting a full server.
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

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->fixtures = [];
    }

    private function fixture(string $name, string $php): string
    {
        $path = self::$tmpDir . '/' . $name;
        file_put_contents($path, $php);
        $this->fixtures[] = $path;
        return $path;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, string> $extraEnv
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runWorker(string $file, array $ctx = [], string $stdin = '', array $extraEnv = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($_ENV, $extraEnv);
        $env['ZEALPHP_REQUEST_CONTEXT'] = json_encode($ctx);

        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/cgi_worker.php', $file], $descriptors, $pipes, ZEALPHP_ROOT, $env);
        $this->assertIsResource($proc);

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
     * @return array<string, mixed>
     */
    private function parseMeta(string $stderr): array
    {
        $line = strtok($stderr, "\n");
        $this->assertNotFalse($line);
        $meta = json_decode((string)$line, true);
        $this->assertIsArray($meta);
        return $meta;
    }

    // -------------------------------------------------------------------------
    // Fix 1: httpoxy CVE — HTTP_PROXY must be stripped from child env
    // -------------------------------------------------------------------------

    public function testBuildCgiEnvStripsHttpProxy(): void
    {
        $server = [
            'HTTP_HOST'    => 'example.com',
            'HTTP_PROXY'   => 'http://evil.attacker.example/',
            'REQUEST_URI'  => '/',
            'SERVER_NAME'  => 'example.com',
        ];
        $env = App::buildCgiEnv($server, '{}');

        $this->assertArrayNotHasKey('HTTP_PROXY', $env,
            'HTTP_PROXY must be stripped to prevent httpoxy CVE-class attack');
        $this->assertArrayHasKey('HTTP_HOST', $env,
            'other HTTP_* headers must still pass through');
    }

    public function testBuildCgiEnvHttpProxyNotSynthesizedFromProxyHeader(): void
    {
        // Simulate a case where OpenSwoole has already synthesised the header
        // into HTTP_PROXY (the exact attack vector). buildCgiEnv must strip it
        // regardless of how it got there.
        $server = [
            'HTTP_PROXY'         => 'http://malicious/',
            'HTTP_PROXY_OVERRIDE' => 'also-blocked-by-name-match',
            'REQUEST_METHOD'     => 'GET',
        ];
        $env = App::buildCgiEnv($server, '{}');

        $this->assertArrayNotHasKey('HTTP_PROXY', $env);
        // HTTP_PROXY_OVERRIDE starts with HTTP_ but is NOT the exact key
        // HTTP_PROXY — it should still pass (only the exact key is blocked).
        $this->assertArrayHasKey('HTTP_PROXY_OVERRIDE', $env,
            'Only the exact HTTP_PROXY key is blocked; other HTTP_PROXY_* keys pass');
    }

    // -------------------------------------------------------------------------
    // Fix 2: Subprocess timeout — hung child gets killed, returns 500
    // -------------------------------------------------------------------------

    public function testCgiTimeoutPropertyExists(): void
    {
        $this->assertIsInt(App::$cgi_timeout, 'App::$cgi_timeout must be an integer');
        $this->assertGreaterThan(0, App::$cgi_timeout, 'App::$cgi_timeout must be positive');
    }

    public function testBuildCgiEnvWithShortTimeout(): void
    {
        // Verify the property is publicly writable (the timeout mechanism depends on it).
        $original = App::$cgi_timeout;
        App::$cgi_timeout = 5;
        $this->assertSame(5, App::$cgi_timeout);
        App::$cgi_timeout = $original;
    }

    public function testTimeoutKillsHungSubprocessAndYieldsNoMeta(): void
    {
        // Verify that a process which never writes to stderr can be killed via
        // proc_terminate() and leaves pipes empty — this is the observable
        // precondition that App::cgiSubprocess() relies on to detect a timeout
        // and return 500.
        $f = $this->fixture('hang.php', "<?php\nsleep(300);\n");

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($_ENV, [
            'ZEALPHP_REQUEST_CONTEXT' => '{}',
            'ZEALPHP_CWD' => ZEALPHP_ROOT,
        ]);

        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/cgi_worker.php', $f], $descriptors, $pipes, ZEALPHP_ROOT, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        // Immediately verify process is running (hasn't written anything yet).
        $st = proc_get_status($proc);
        $this->assertTrue($st['running'], 'Subprocess should be running (sleeping)');

        // Kill it — simulating what cgiSubprocess() does after timeout expires.
        proc_terminate($proc, 15); // SIGTERM
        $killDeadline = microtime(true) + 3.0;
        while (microtime(true) < $killDeadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            usleep(20000);
        }
        $st = proc_get_status($proc);
        if ($st['running']) {
            proc_terminate($proc, 9); // SIGKILL
            usleep(100000);
        }

        // After kill: stderr must be empty (no metadata was written before death).
        stream_set_blocking($pipes[2], false);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($pipes[1]);
        proc_close($proc);

        $this->assertSame('', $stderr,
            'A killed subprocess must leave stderr empty — no metadata line');
    }

    // -------------------------------------------------------------------------
    // Fix 3: stderr drain — remaining stderr routed via elog() channel
    // -------------------------------------------------------------------------

    public function testWorkerStderrAfterMetaLineIsPresent(): void
    {
        // The cgi_worker.php protocol: metadata JSON is written to stderr by
        // __z_send_meta() which fires in the register_shutdown_function AFTER
        // the script body runs. Any fwrite(STDERR, ...) calls in the script
        // body therefore appear BEFORE the metadata line on the raw stderr stream.
        //
        // App::cgiSubprocess() reads the FIRST fgets() from stderr as the
        // metadata line, then drains the remainder. So for the drain test we
        // need content that appears AFTER __z_send_meta() writes its JSON line.
        // The simplest way is to write to STDERR inside a register_shutdown_function
        // registered AFTER cgi_worker's own shutdown function (LIFO order means
        // ours runs first, but we want ours to run after). Instead, use error_log()
        // which PHP routes to stderr — and trigger it from inside the script body
        // so it appears before the metadata. Then verify:
        //  a) the metadata JSON line is present somewhere in stderr
        //  b) the extra content is also present (host-side drain will find it)
        $f = $this->fixture('stderr_extra.php', <<<'PHP'
<?php
// error_log() writes to STDERR — this appears BEFORE the shutdown-written
// metadata JSON line, which is the content the host-side drain picks up.
error_log('extra stderr content from cgi_worker test');
echo 'body';
PHP);

        $r = $this->runWorker($f);

        // Metadata JSON line must be present somewhere in stderr output.
        $lines = array_filter(explode("\n", $r['stderr']));
        $metaFound = false;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['status_code'])) {
                $metaFound = true;
                $this->assertSame(200, $decoded['status_code']);
                break;
            }
        }
        $this->assertTrue($metaFound, 'Metadata JSON frame must be present in subprocess stderr');

        // Extra stderr content is also present (available for host-side drain).
        $this->assertStringContainsString('extra stderr content from cgi_worker test', $r['stderr'],
            'Extra stderr content must be present in subprocess stderr for host-side drain');
        $this->assertSame('body', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // Fix 4: Missing CGI env vars — all 8 RFC 3875 vars present in buildCgiEnv
    // -------------------------------------------------------------------------

    public function testBuildCgiEnvContainsGatewayInterface(): void
    {
        $env = App::buildCgiEnv([], '{}');
        $this->assertArrayHasKey('GATEWAY_INTERFACE', $env);
        $this->assertSame('CGI/1.1', $env['GATEWAY_INTERFACE']);
    }

    public function testBuildCgiEnvContainsServerSoftware(): void
    {
        $env = App::buildCgiEnv([], '{}');
        $this->assertArrayHasKey('SERVER_SOFTWARE', $env);
        $this->assertStringStartsWith('ZealPHP/', $env['SERVER_SOFTWARE'],
            'SERVER_SOFTWARE must start with ZealPHP/');
    }

    public function testBuildCgiEnvContainsDocumentRoot(): void
    {
        $env = App::buildCgiEnv([], '{}');
        $this->assertArrayHasKey('DOCUMENT_ROOT', $env);
        $this->assertNotEmpty($env['DOCUMENT_ROOT']);
    }

    public function testBuildCgiEnvContainsServerAdminWhenSet(): void
    {
        $original = App::$server_admin;
        App::$server_admin = 'admin@example.com';

        $env = App::buildCgiEnv([], '{}');
        $this->assertArrayHasKey('SERVER_ADMIN', $env);
        $this->assertSame('admin@example.com', $env['SERVER_ADMIN']);

        App::$server_admin = $original;
    }

    public function testBuildCgiEnvOmitsServerAdminWhenNull(): void
    {
        $original = App::$server_admin;
        App::$server_admin = null;

        $env = App::buildCgiEnv([], '{}');
        $this->assertArrayNotHasKey('SERVER_ADMIN', $env);

        App::$server_admin = $original;
    }

    public function testBuildCgiEnvInjectsAuthTypeFromBasicHeader(): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz'];
        $env = App::buildCgiEnv($server, '{}');
        $this->assertArrayHasKey('AUTH_TYPE', $env);
        $this->assertSame('Basic', $env['AUTH_TYPE']);
    }

    public function testBuildCgiEnvPassesThroughRemoteUserFromServer(): void
    {
        $server = ['REMOTE_USER' => 'alice'];
        $env = App::buildCgiEnv($server, '{}');
        $this->assertArrayHasKey('REMOTE_USER', $env);
        $this->assertSame('alice', $env['REMOTE_USER']);
    }

    public function testBuildCgiEnvPassesThroughRemotePort(): void
    {
        $server = ['REMOTE_PORT' => '54321'];
        $env = App::buildCgiEnv($server, '{}');
        $this->assertArrayHasKey('REMOTE_PORT', $env);
        $this->assertSame('54321', $env['REMOTE_PORT']);
    }

    public function testBuildCgiEnvSetsPathTranslatedFromPathInfo(): void
    {
        $server = ['PATH_INFO' => '/extra/path'];
        $env = App::buildCgiEnv($server, '{}');
        $this->assertArrayHasKey('PATH_TRANSLATED', $env);
        $this->assertStringEndsWith('/extra/path', $env['PATH_TRANSLATED'],
            'PATH_TRANSLATED must be DOCUMENT_ROOT + PATH_INFO');
    }

    public function testBuildCgiEnvAllEightNewVarsPresentTogether(): void
    {
        $original = App::$server_admin;
        App::$server_admin = 'webmaster@example.com';

        $server = [
            'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
            'REMOTE_USER'        => 'bob',
            'REMOTE_PORT'        => '12345',
            'PATH_INFO'          => '/info',
        ];
        $env = App::buildCgiEnv($server, '{}');

        $this->assertArrayHasKey('GATEWAY_INTERFACE', $env);
        $this->assertArrayHasKey('SERVER_SOFTWARE', $env);
        $this->assertArrayHasKey('DOCUMENT_ROOT', $env);
        $this->assertArrayHasKey('SERVER_ADMIN', $env);
        $this->assertArrayHasKey('AUTH_TYPE', $env);
        $this->assertArrayHasKey('REMOTE_USER', $env);
        $this->assertArrayHasKey('REMOTE_PORT', $env);
        $this->assertArrayHasKey('PATH_TRANSLATED', $env);

        App::$server_admin = $original;
    }

    // -------------------------------------------------------------------------
    // Fix 5: Status: header parsing — CGI Status: NNN sets HTTP status code
    // -------------------------------------------------------------------------

    public function testWorkerStatusHeaderSetsStatusCode(): void
    {
        // Script uses header('Status: 404 Not Found') — the CGI/1.1 standard
        // way to set the response status. cgi_worker.php must capture this as
        // status_code=404 in the metadata frame.
        $f = $this->fixture('status_hdr.php', <<<'PHP'
<?php
header('Status: 404 Not Found');
echo 'not found body';
PHP);

        $r = $this->runWorker($f);
        $meta = $this->parseMeta($r['stderr']);

        $this->assertSame(404, $meta['status_code'],
            'Status: header must set the metadata status_code (mod_cgi parity)');
    }

    public function testWorkerStatusHeaderWithVariousCodesIsForwardedToMeta(): void
    {
        foreach ([301, 302, 307, 400, 403, 500, 503] as $code) {
            $f = $this->fixture("status_{$code}.php", "<?php\nheader('Status: {$code} Reason Text');\necho 'body';\n");
            $r = $this->runWorker($f);
            $meta = $this->parseMeta($r['stderr']);
            $this->assertSame($code, $meta['status_code'], "Status: {$code} must set status_code={$code}");
        }
    }

    public function testWorkerStatusHeaderStoredInHeadersForHostSideStripping(): void
    {
        // The Status: header must appear in meta['headers'] so that
        // App::cgiSubprocess() can strip it from the HTTP response headers
        // while still using the code. A client should never see "Status:" as
        // a real HTTP response header.
        $f = $this->fixture('status_in_headers.php', <<<'PHP'
<?php
header('Status: 201 Created');
header('X-Custom: present');
echo 'created';
PHP);

        $r = $this->runWorker($f);
        $meta = $this->parseMeta($r['stderr']);

        $this->assertSame(201, $meta['status_code']);

        // Status: must be in the headers array (host-side stripping needs it).
        $headerNames = array_map(static fn($h) => strtolower((string)($h[0] ?? '')), $meta['headers'] ?? []);
        $this->assertContains('status', $headerNames,
            'Status: header must appear in meta[headers] for host-side stripping');
        $this->assertContains('x-custom', $headerNames,
            'Other headers must still be present');
    }

    public function testWorkerStatusHeaderLastWriteWins(): void
    {
        // If multiple Status: or http_response_code() calls happen, the last one wins.
        $f = $this->fixture('status_last.php', <<<'PHP'
<?php
header('Status: 301 Moved');
header('Status: 302 Found');
echo 'redirect';
PHP);

        $r = $this->runWorker($f);
        $meta = $this->parseMeta($r['stderr']);
        $this->assertSame(302, $meta['status_code'],
            'Last Status: header write must win');
    }

    public function testWorkerStatusHeaderOutOfRangeIsIgnored(): void
    {
        // A Status: value outside 100-599 must be silently ignored (not crash).
        $f = $this->fixture('status_bad.php', <<<'PHP'
<?php
header('Status: 999 Bad Code');
echo 'bad status';
PHP);

        $r = $this->runWorker($f);
        $meta = $this->parseMeta($r['stderr']);
        // 999 is out of range — status stays at the default 200.
        $this->assertSame(200, $meta['status_code'],
            'Out-of-range Status: code must be ignored, leaving default 200');
    }

    // -------------------------------------------------------------------------
    // Fix 2 (Part B): SIGTERM→SIGKILL escalation — killCgiChild helper
    // -------------------------------------------------------------------------

    /**
     * Simulate what App::cgiSubprocess() does when the timeout fires:
     * send SIGTERM, poll until not-running, escalate to SIGKILL if needed.
     *
     * Returns true if the process was killed within the deadline, false otherwise.
     *
     * @param resource $proc
     */
    private function killCgiChild($proc, float $gracePeriod = 3.0, float $killWait = 1.0): bool
    {
        proc_terminate($proc, 15); // SIGTERM
        $deadline = microtime(true) + $gracePeriod;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                return true;
            }
            usleep(20000);
        }
        // SIGKILL escalation
        $st = proc_get_status($proc);
        if ($st['running']) {
            proc_terminate($proc, 9); // SIGKILL
            $killDeadline = microtime(true) + $killWait;
            while (microtime(true) < $killDeadline) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    return true;
                }
                usleep(10000);
            }
            return false;
        }
        return true;
    }

    public function testSigtermKillsNormalProcessWithoutSigkill(): void
    {
        // A process that exits promptly on SIGTERM must not need SIGKILL.
        $f = $this->fixture('quick_exit.php', "<?php\necho 'done';\n");

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge($_ENV, ['ZEALPHP_REQUEST_CONTEXT' => '{}', 'ZEALPHP_CWD' => ZEALPHP_ROOT]);
        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/cgi_worker.php', $f], $descriptors, $pipes, ZEALPHP_ROOT, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        // Let it run to completion naturally, then verify it exited.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            usleep(10000);
        }

        $killed = $this->killCgiChild($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // Process already exited before SIGTERM — killCgiChild returns true.
        $this->assertTrue($killed, 'killCgiChild must report success for an already-exited process');
    }

    public function testSigkillFallbackKillsProcessThatIgnoresSigterm(): void
    {
        // A process that sleeps (simulating SIGTERM ignore) must be killed via
        // SIGKILL escalation within the grace period.
        $f = $this->fixture('sleep_long.php', "<?php\nsleep(60);\n");

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge($_ENV, ['ZEALPHP_REQUEST_CONTEXT' => '{}', 'ZEALPHP_CWD' => ZEALPHP_ROOT]);
        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/cgi_worker.php', $f], $descriptors, $pipes, ZEALPHP_ROOT, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        // Verify it is running before we try to kill it.
        $st = proc_get_status($proc);
        $this->assertTrue($st['running'], 'Subprocess must be running before kill attempt');

        // Use a very short grace period so SIGKILL fires quickly in the test.
        $killed = $this->killCgiChild($proc, gracePeriod: 0.05, killWait: 2.0);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertTrue($killed, 'SIGKILL escalation must terminate a sleeping subprocess');
    }
}
