<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * IOStreamWrapper contract under coroutine-legacy concurrency:
 * `php://input` must hand every request ITS OWN body — across a mid-request
 * yield, re-readable, with `php://temp` delegation intact. A process-wide
 * input buffer (or a wrapper regression) shows up here as one request
 * echoing another's body.
 *
 * Same boot/skip harness as TrustBarIsolationTest. Skips cleanly when the
 * native stack (ext-curl + local ext-zealphp build + OpenSwoole) is absent.
 */
final class PhpInputIsolationTest extends TestCase
{
    private const PORT = 9821;
    private const N = 24;

    private static ?int $pid = null;
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $so = dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so';
        $fixture = __DIR__ . '/fixtures/phpinput_server.php';
        self::$log = sys_get_temp_dir() . '/zealphp_phpinput_' . getmypid() . '.log';
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

    public function testEveryConcurrentRequestReadsItsOwnBody(): void
    {
        if (!\function_exists('curl_multi_init')
            || !\file_exists(dirname(__DIR__, 2) . '/ext/zealphp/modules/zealphp.so')) {
            $this->markTestSkipped('Native stack (ext-curl + local ext-zealphp build) required.');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1]]);
        if (@file_get_contents('http://127.0.0.1:' . self::PORT . '/ping', false, $ctx) === false) {
            $this->markTestSkipped('php://input fixture server did not boot (OpenSwoole unavailable).');
        }

        $mh = curl_multi_init();
        $h = [];
        for ($i = 0; $i < self::N; $i++) {
            $body = 'BODY-req' . $i . '-' . str_repeat(chr(65 + ($i % 26)), 16 + $i);
            $ch = curl_init('http://127.0.0.1:' . self::PORT . '/echo');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_multi_add_handle($mh, $ch);
            $h[$i] = ['ch' => $ch, 'body' => $body];
        }
        do {
            $s = curl_multi_exec($mh, $run);
            if ($run) {
                curl_multi_select($mh, 0.2);
            }
        } while ($run > 0 && $s === CURLM_OK);

        $bad = [];
        $ok = 0;
        foreach ($h as $i => $e) {
            $raw = (string) curl_multi_getcontent($e['ch']);
            curl_multi_remove_handle($mh, $e['ch']);
            $d = json_decode($raw, true);
            if (!is_array($d)
                || ($d['raw'] ?? null) !== $e['body']
                || ($d['again'] ?? false) !== true
                || ($d['temp'] ?? '') !== 'TEMPOK') {
                $bad[] = "req$i: " . substr($raw, 0, 120);
                continue;
            }
            $ok++;
        }
        curl_multi_close($mh);

        $this->assertSame(
            [],
            $bad,
            'php://input cross-coroutine contamination or wrapper regression: ' . implode(' | ', $bad)
        );
        $this->assertSame(self::N, $ok);
    }
}
