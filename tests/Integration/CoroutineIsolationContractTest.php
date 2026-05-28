<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * THE EXTENSION ISOLATION CONTRACT.
 *
 * Spins up an App::mode('coroutine-legacy') server (Mode 4: superglobals +
 * coroutine + ext-zealphp per-coroutine isolation) and fires 40 concurrent
 * requests that are FORCED to interleave (each yields ~40ms mid-request via a
 * Timer+Channel). Under that interleave it asserts, for every request:
 *
 *   - the superglobal ($_GET) it set is still its own after the yield      (no superglobal leak)
 *   - the user global (global $req_global) it set is still its own          (no $GLOBALS leak)
 *   - a bootstrap-time global (set in onWorkerStart) is visible             (re-baseline works)
 *   - the response reflects ITS OWN request, not a neighbour's              (no cross-request bleed)
 *
 * and that the requests genuinely ran concurrently (peak coroutines > 1).
 *
 * If ANY of these fail, the per-coroutine isolation that ext-zealphp provides
 * is broken — that is a contract violation, not a flaky test. Raw OpenSwoole
 * (no ZealPHP) leaks 39/40 here; ZealPHP must leak 0.
 *
 * Skips cleanly when OpenSwoole / the local ext build aren't available (CI
 * without the native stack) — it cannot run without a real coroutine server.
 */
final class CoroutineIsolationContractTest extends TestCase
{
    private const PORT = 9911;
    private const N = 40;
    private static ?int $pid = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $so = dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so';
        $fixture = __DIR__ . '/fixtures/coroutine_isolation_server.php';
        self::$log = sys_get_temp_dir() . '/zealphp_coro_contract_' . getmypid() . '.log';

        // Environment gates — skip (not fail) when the native stack is absent.
        if (!\function_exists('curl_multi_init')) {
            return; // skip decided in test via markers below
        }
        if (!\file_exists($so)) {
            return;
        }
        // Does the system PHP have OpenSwoole available to the spawned server?
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }

        // Clear any stale listener on the port.
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);

        $cmd = sprintf(
            '%s -d extension=%s -d opcache.enable_cli=0 %s %d > %s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($so),
            escapeshellarg($fixture),
            self::PORT,
            escapeshellarg(self::$log)
        );
        self::$pid = (int) trim((string) shell_exec($cmd));

        // Wait for the server to answer /ping (up to ~10s).
        for ($i = 0; $i < 50; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) !== false) {
                return;
            }
            if (self::$pid && !@posix_kill(self::$pid, 0)) {
                break; // server died during boot
            }
            usleep(200000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pid) {
            @posix_kill(self::$pid, SIGKILL);
            self::$pid = null;
        }
        @unlink(self::$log);
    }

    private function skipUnlessServerUp(): void
    {
        if (!\function_exists('curl_multi_init')) {
            $this->markTestSkipped('ext-curl (curl_multi) required to drive concurrent requests.');
        }
        if (!\file_exists(dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so')) {
            $this->markTestSkipped('Local ext-zealphp build (ext/zealphp/modules/zealphp.so) not found.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) === false) {
            $detail = \is_file(self::$log) ? "\n" . substr((string) @file_get_contents(self::$log), -400) : '';
            $this->markTestSkipped('coroutine-legacy fixture server did not boot (OpenSwoole/ext unavailable).' . $detail);
        }
    }

    public function testPerCoroutineIsolationUnderConcurrentInterleave(): void
    {
        $this->skipUnlessServerUp();

        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < self::N; $i++) {
            $ch = curl_init('http://127.0.0.1:' . self::PORT . '/probe?x=req' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = ['ch' => $ch, 'x' => 'req' . $i];
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.2);
        } while ($running > 0 && $st === CURLM_OK);

        $sgLeaks = $gvLeaks = $bootMissing = $crossReq = $errors = 0;
        $peak = 0;
        $failExamples = [];
        foreach ($handles as $i => $h) {
            $body = curl_multi_getcontent($h['ch']);
            curl_multi_remove_handle($mh, $h['ch']);
            $d = json_decode((string) $body, true);
            if (!is_array($d) || !isset($d['sg_stable'])) {
                $errors++;
                if (count($failExamples) < 3) $failExamples[] = "req$i bad response: " . substr((string) $body, 0, 100);
                continue;
            }
            $peak = max($peak, (int) ($d['maxc'] ?? 0));
            if (!$d['sg_stable']) { $sgLeaks++; if (count($failExamples) < 3) $failExamples[] = "req$i superglobal leaked"; }
            if (!$d['gv_stable']) { $gvLeaks++; if (count($failExamples) < 3) $failExamples[] = "req$i user-global leaked"; }
            if (!$d['boot_visible']) { $bootMissing++; if (count($failExamples) < 3) $failExamples[] = "req$i bootstrap global missing"; }
            if (($d['expect'] ?? '') !== $h['x']) { $crossReq++; if (count($failExamples) < 3) $failExamples[] = "req$i saw {$d['expect']}"; }
        }
        curl_multi_close($mh);

        $msg = "isolation contract failed: " . implode('; ', $failExamples);
        $this->assertSame(0, $errors, "request errors — $msg");
        $this->assertGreaterThan(1, $peak, 'requests did not actually run concurrently (peak coroutines <= 1) — test would be meaningless');
        $this->assertSame(0, $sgLeaks, "superglobal (\$_GET) leaked across coroutines — $msg");
        $this->assertSame(0, $gvLeaks, "user global (global \$x) leaked across coroutines — $msg");
        $this->assertSame(0, $bootMissing, "bootstrap global not visible in request coroutine (re-baseline broke) — $msg");
        $this->assertSame(0, $crossReq, "cross-request bleed (response saw another request's data) — $msg");
    }
}
