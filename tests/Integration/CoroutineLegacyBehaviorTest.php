<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * BEHAVIOURAL coroutine-legacy contract — the bugs this mode actually shipped.
 *
 * Where TrustBarIsolationTest / CoroutineIsolationContractTest assert per-primitive
 * ISOLATION FLAGS, this drives an App::mode('coroutine-legacy') server through the
 * real failure shapes under concurrency and asserts correct END-TO-END BEHAVIOUR:
 *
 *   - request-input MISROUTE: each concurrent request carries distinct
 *     $_GET/$_POST/$_COOKIE, yields mid-handler, then re-reads them — the response
 *     must reflect ITS OWN inputs (regression for the cross-client rebind fix).
 *   - OBJECT-GLOBAL across yield: `global $x; $x = new <ctor-yields>` — the value
 *     must survive the construction yield AND a further yield (the $wpdb pattern;
 *     object globals were NOT isolated before ext-zealphp 0.3.23 — 22/24 leak).
 *   - COLD class-with-inheritance under the first concurrent wave (preloaded ->
 *     LINKED -> no "Class not found"/duplicate-CE).
 *   - the universal return contract (int / array / string / Generator) under
 *     coroutine-legacy.
 *
 * Skips cleanly when the native stack (ext-curl + local ext-zealphp build +
 * OpenSwoole) is unavailable — it cannot run without a real coroutine server.
 */
final class CoroutineLegacyBehaviorTest extends TestCase
{
    private const PORT = 9834;
    private static ?int $pid = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $so = dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so';
        $fixture = __DIR__ . '/fixtures/coroutine_legacy_behavior_server.php';
        self::$log = sys_get_temp_dir() . '/zealphp_cl_behavior_' . getmypid() . '.log';

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
            '%s -d extension=%s -d opcache.enable_cli=0 %s %d 2 > %s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($so),
            escapeshellarg($fixture),
            self::PORT,
            escapeshellarg(self::$log)
        );
        self::$pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) !== false) {
                return;
            }
            if (self::$pid && !@posix_kill(self::$pid, 0)) {
                break;
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
            $this->markTestSkipped('Local ext-zealphp build not found.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) === false) {
            $detail = \is_file(self::$log) ? "\n" . substr((string) @file_get_contents(self::$log), -400) : '';
            $this->markTestSkipped('coroutine-legacy behavior fixture did not boot (OpenSwoole/ext unavailable).' . $detail);
        }
    }

    /**
     * Fire $n curl_multi requests; $build($i) returns the curl handle, $check($i, $json)
     * returns true on success. Returns [ok, errors].
     * @return array{0:int,1:int}
     */
    private function concurrent(int $n, callable $build, callable $check): array
    {
        $mh = curl_multi_init();
        $h = [];
        for ($i = 0; $i < $n; $i++) {
            $ch = $build($i);
            curl_multi_add_handle($mh, $ch);
            $h[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.2);
        } while ($running > 0 && $st === CURLM_OK);

        $ok = $err = 0;
        foreach ($h as $i => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            $d = json_decode($body, true);
            if (\is_array($d) && $check($i, $d)) {
                $ok++;
            } else {
                $err++;
            }
        }
        curl_multi_close($mh);
        return [$ok, $err];
    }

    public function testRequestInputNotMisroutedAcrossConcurrentRequests(): void
    {
        $this->skipUnlessServerUp();
        $n = 40;
        [$ok, $err] = $this->concurrent(
            $n,
            function (int $i) {
                $ch = curl_init('http://127.0.0.1:' . self::PORT . '/inputs?v=g' . $i);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => 'v=p' . $i,
                    CURLOPT_COOKIE => 'v=c' . $i,
                    CURLOPT_TIMEOUT => 20,
                ]);
                return $ch;
            },
            fn (int $i, array $d): bool =>
                ($d['get'] ?? null) === 'g' . $i
                && ($d['post'] ?? null) === 'p' . $i
                && ($d['cookie'] ?? null) === 'c' . $i
        );
        $this->assertSame(0, $err, "request-input misrouted across coroutines ($ok ok / $err mismatched of $n)");
        $this->assertSame($n, $ok);
    }

    public function testObjectGlobalSurvivesConstructorYieldAndFurtherYield(): void
    {
        $this->skipUnlessServerUp();
        // extra=1 re-reads the object global AFTER an additional yield — the exact
        // shape that leaked 22/24 before ext-zealphp 0.3.23 isolated object globals.
        foreach ([0, 1] as $extra) {
            $n = 30;
            [$ok, $err] = $this->concurrent(
                $n,
                function (int $i) use ($extra) {
                    $ch = curl_init('http://127.0.0.1:' . self::PORT . "/global-yield?x=gy$i&extra=$extra");
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
                    return $ch;
                },
                fn (int $i, array $d): bool => ($d['gv'] ?? null) === 'gy' . $i && ($d['x'] ?? null) === 'gy' . $i
            );
            $this->assertSame(0, $err, "object global leaked across coroutines (extra=$extra): $err of $n");
            $this->assertSame($n, $ok);
        }
    }

    public function testColdClassWithInheritanceUnderConcurrency(): void
    {
        $this->skipUnlessServerUp();
        // Preloaded cold corpus: each request instantiates a distinct
        // ColdChild{N} extends ColdBase — must be LINKED (no "Class not found").
        $n = 40;
        [$ok, $err] = $this->concurrent(
            $n,
            function (int $i) {
                $ch = curl_init('http://127.0.0.1:' . self::PORT . '/cold?n=' . ($i % 64));
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
                return $ch;
            },
            fn (int $i, array $d): bool => ($d['who'] ?? null) === 'child' . ($i % 64) && ($d['kind'] ?? null) === 'base'
        );
        $this->assertSame(0, $err, "cold class autoload failed under concurrency: $err of $n");
        $this->assertSame($n, $ok);
    }

    public function testUniversalReturnContractUnderCoroutineLegacy(): void
    {
        $this->skipUnlessServerUp();
        $base = 'http://127.0.0.1:' . self::PORT . '/contract';

        // int -> status, empty body
        $status = null;
        $body = $this->httpGet($base . '?kind=int', $status);
        $this->assertSame(404, $status, 'int return must map to HTTP status');

        // array -> JSON
        $body = $this->httpGet($base . '?kind=json&x=Z', $status);
        $this->assertSame(200, $status);
        $this->assertSame(['x' => 'Z', 'ok' => true], json_decode($body, true), 'array return must emit JSON');

        // string -> body
        $body = $this->httpGet($base . '?kind=str&x=Z', $status);
        $this->assertSame('BODY:Z', $body, 'string return must be the body');

        // Generator -> streamed body
        $body = $this->httpGet($base . '?kind=gen&x=Z', $status);
        $this->assertSame('chunk1:Z;chunk2:Z;', $body, 'Generator return must stream its yields');
    }

    private function httpGet(string $url, ?int &$status): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $body;
    }
}
