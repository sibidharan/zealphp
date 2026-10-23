<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ZealPHP\Tests\TestCase;

/**
 * A session that cannot be written (unserializable value, a handler that
 * throws) must fail that request's write only. It used to throw out of the
 * session manager's request-end `finally`, killing the worker and every
 * request in flight on it.
 *
 * Fixture: fixtures/session_write_failure_server.php (own servers, own ports).
 */
final class SessionWriteFailureTest extends TestCase
{
    private const SERVERS = ['coroutine' => 9836, 'mixed' => 9837];

    /** @var array<string, int> */
    private static array $pids = [];
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            return;
        }
        self::$log = sys_get_temp_dir() . '/zealphp_sess_write_' . getmypid() . '.log';
        $fixture = __DIR__ . '/fixtures/session_write_failure_server.php';
        foreach (self::SERVERS as $mode => $port) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
            usleep(200000);
            $cmd = sprintf('%s -d opcache.enable_cli=0 %s %d %s >> %s 2>&1 & echo $!',
                escapeshellarg(PHP_BINARY), escapeshellarg($fixture), $port, $mode, escapeshellarg(self::$log));
            self::$pids[$mode] = (int) trim((string) shell_exec($cmd));
        }
        foreach (self::SERVERS as $port) {
            for ($i = 0; $i < 50; $i++) {
                $ctx = stream_context_create(['http' => ['timeout' => 1]]);
                if (@file_get_contents("http://127.0.0.1:$port/ping", false, $ctx) !== false) {
                    break;
                }
                usleep(200000);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pids as $pid) {
            @posix_kill($pid, SIGKILL);
        }
        foreach (self::SERVERS as $port) {
            @shell_exec('fuser -k ' . $port . '/tcp 2>/dev/null');
        }
        self::$pids = [];
        if (self::$log !== '') {
            @unlink(self::$log);
        }
    }

    private function pidFrom(int $port, string $path): ?int
    {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
        if ($body === false) {
            return null;
        }
        $d = json_decode($body, true);
        return is_array($d) && isset($d['pid']) ? (int) $d['pid'] : null;
    }

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['coroutine (CoSessionManager)' => ['coroutine'], 'mixed (SessionManager)' => ['mixed']];
    }

    #[DataProvider('modes')]
    public function testUnwritableSessionDoesNotKillTheWorker(string $mode): void
    {
        $port = self::SERVERS[$mode];
        $before = $this->pidFrom($port, '/pid');
        if ($before === null) {
            $this->markTestSkipped("$mode fixture did not boot (OpenSwoole unavailable).");
        }
        $this->assertSame($before, $this->pidFrom($port, '/unwritable'), 'request with an unwritable session must still be answered');
        usleep(300000); // a dying worker would be replaced by now
        $this->assertSame($before, $this->pidFrom($port, '/pid'), "$mode: the worker died after a failed session write");
    }
}
