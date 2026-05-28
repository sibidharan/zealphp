<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * THE TRUST-BAR.
 *
 * The migration claim — "run existing request-style PHP under OpenSwoole
 * concurrency without changing its mental model" — only holds if request state
 * is isolated per coroutine. This spins up App::mode('coroutine-legacy') and
 * fires 40 concurrent interleaved requests (each yields mid-request), then
 * verifies every request kept ITS OWN value for:
 *
 *   $_GET $_POST $_REQUEST $_COOKIE $_FILES $_SERVER $_SESSION,
 *   class statics, $GLOBALS, constants (define), ini_set,
 *   header() / setcookie() (response-state from the wire), and that a
 *   bootstrap-time global stays visible.
 *
 * These are the ISOLATION CONTRACT — asserted hard (0 leaks across 40 coroutines).
 *
 * Two primitives are PROCESS-LEVEL by design and are NOT part of the contract:
 *   - function-local `static $x`  (lives in the op_array, not snapshotted)
 *   - putenv() / getenv()         (process environment, not snapshotted)
 * They are reported for transparency (the honest "process-level state remains
 * the developer's responsibility" caveat), not asserted.
 *
 * Raw OpenSwoole leaks all of these across coroutines; ZealPHP must leak none
 * of the contract set. Skips cleanly when the native stack is unavailable.
 */
final class TrustBarIsolationTest extends TestCase
{
    private const PORT = 9820;
    private const N = 40;

    /** The hard isolation contract — must be 0 leaks. */
    private const CONTRACT = [
        '$_GET','$_POST','$_REQUEST','$_COOKIE','$_FILES','$_SERVER','$_SESSION',
        'class_static','$GLOBALS','constant','ini_set','bootstrap',
    ];
    /** Process-level — reported, not asserted. */
    private const PROCESS_LEVEL = ['fn_static','putenv'];

    private static ?int $pid = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $so = dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so';
        $fixture = __DIR__ . '/fixtures/trustbar_server.php';
        self::$log = sys_get_temp_dir() . '/zealphp_trustbar_' . getmypid() . '.log';
        if (!\function_exists('curl_multi_init') || !\file_exists($so)) {
            return;
        }
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf(
            '%s -d extension=%s -d opcache.enable_cli=0 %s %d > %s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY), escapeshellarg($so), escapeshellarg($fixture), self::PORT, escapeshellarg(self::$log)
        );
        self::$pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) !== false) return;
            if (self::$pid && !@posix_kill(self::$pid, 0)) break;
            usleep(200000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) { @posix_kill(self::$pid, SIGKILL); self::$pid = null; }
        @unlink(self::$log);
    }

    public function testRequestStateIsolatedAcrossConcurrentCoroutines(): void
    {
        if (!\function_exists('curl_multi_init') || !\file_exists(dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so')) {
            $this->markTestSkipped('Native stack (ext-curl + local ext-zealphp build) required.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) === false) {
            $this->markTestSkipped('trust-bar fixture server did not boot (OpenSwoole unavailable).');
        }

        $mh = curl_multi_init();
        $h = [];
        for ($i = 0; $i < self::N; $i++) {
            $ch = curl_init('http://127.0.0.1:' . self::PORT . '/probe?x=req' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_multi_add_handle($mh, $ch);
            $h[$i] = ['ch' => $ch, 'x' => 'req' . $i];
        }
        do { $s = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.2); } while ($run > 0 && $s === CURLM_OK);

        $leak = array_fill_keys(array_merge(self::CONTRACT, self::PROCESS_LEVEL, ['resp_header', 'resp_cookie']), 0);
        $ok = $err = $peak = 0;
        foreach ($h as $i => $e) {
            $raw = (string) curl_multi_getcontent($e['ch']);
            curl_multi_remove_handle($mh, $e['ch']);
            [$hdr, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
            $d = json_decode($body, true);
            if (!is_array($d) || !isset($d['iso'])) { $err++; continue; }
            $peak = max($peak, (int) ($d['maxc'] ?? 0));
            foreach ($d['iso'] as $k => $v) { if (!$v) $leak[$k]++; }
            if (!preg_match('/^X-TB:\s*' . preg_quote($e['x'], '/') . '\s*$/mi', $hdr)) $leak['resp_header']++;
            if (!preg_match('/^Set-Cookie:\s*tbc=' . preg_quote($e['x'], '/') . '/mi', $hdr)) $leak['resp_cookie']++;
            $ok++;
        }
        curl_multi_close($mh);

        // Report the full matrix (visible with --debug / on failure).
        $report = "trust-bar (40 concurrent, peak=$peak): ";
        foreach ($leak as $k => $v) $report .= "$k=" . ($v === 0 ? 'iso' : "LEAK$v") . ' ';
        fwrite(STDERR, "\n$report\n");

        $this->assertSame(0, $err, 'request errors');
        $this->assertGreaterThan(1, $peak, 'requests did not run concurrently — test meaningless');
        foreach (array_merge(self::CONTRACT, ['resp_header', 'resp_cookie']) as $k) {
            $this->assertSame(0, $leak[$k], "ISOLATION CONTRACT VIOLATED: $k leaked across coroutines ($report)");
        }
        // Process-level primitives are documented landmines — assert nothing,
        // just record that the test exercised them.
        $this->addToAssertionCount(count(self::PROCESS_LEVEL));
    }
}
