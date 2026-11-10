<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Stopping a server is quick and quiet. Before: every worker with a recurring
 * timer waited out max_wait_time and was killed ("worker exit timeout, forced
 * termination"), and a worker whose async log writer was parked on its queue
 * printed "all coroutines (count: 1) are asleep - deadlock!".
 *
 * Fixture: fixtures/graceful_shutdown_server.php (own server, own port).
 */
final class GracefulShutdownTest extends TestCase
{
    private const PORT = 9861;
    private const REQUESTS = 6;

    private ?int $pid = null;
    private string $dir = '';
    private string $out = '';

    protected function setUp(): void
    {
        $mods = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' -m 2>/dev/null');
        if (stripos($mods, 'openswoole') === false) {
            $this->markTestSkipped('OpenSwoole not loaded');
        }
        $this->dir = sys_get_temp_dir() . '/zealphp_shutdown_' . getmypid() . '_' . bin2hex(random_bytes(3));
        @mkdir($this->dir, 0700, true);
        $this->out = $this->dir . '/server.out';
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        usleep(200000);
        $cmd = sprintf(
            // Async logging pinned on: the coverage script turns it off globally.
            'ZEALPHP_LOG_ASYNC=1 ZEALPHP_LOG_DIR=%s %s -d opcache.enable_cli=0 %s %d > %s 2>&1 & echo $!',
            escapeshellarg($this->dir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/fixtures/graceful_shutdown_server.php'),
            self::PORT,
            escapeshellarg($this->out)
        );
        $this->pid = (int) trim((string) shell_exec($cmd));
        for ($i = 0; $i < 50; $i++) {
            if ($this->ping(0) !== null) {
                return;
            }
            if (!@posix_kill($this->pid, 0)) {
                break;
            }
            usleep(200000);
        }
        $this->markTestSkipped('fixture did not boot: ' . substr((string) @file_get_contents($this->out), -400));
    }

    protected function tearDown(): void
    {
        if ($this->pid !== null && @posix_kill($this->pid, 0)) {
            @posix_kill($this->pid, SIGKILL);
        }
        @shell_exec('fuser -k ' . self::PORT . '/tcp 2>/dev/null');
        if ($this->dir !== '' && is_dir($this->dir)) {
            @shell_exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function ping(int $n): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $body = @file_get_contents('http://127.0.0.1:' . self::PORT . '/ping?n=' . $n, false, $ctx);
        return $body === false ? null : $body;
    }

    public function testSigtermStopsQuicklyWithoutDeadlockAndKeepsEveryLogLine(): void
    {
        for ($n = 1; $n <= self::REQUESTS; $n++) {
            $this->assertNotNull($this->ping($n), "request $n failed");
        }
        usleep(300000); // let the writers flush, as on a server between requests

        $pid = (int) $this->pid;
        $start = microtime(true);
        posix_kill($pid, SIGTERM);
        while (@posix_kill($pid, 0) && microtime(true) - $start < 10) {
            usleep(20000);
        }
        $took = microtime(true) - $start;
        $this->assertFalse(@posix_kill($pid, 0), 'server still running 10 s after SIGTERM');

        $out = (string) @file_get_contents($this->out);
        $this->assertStringNotContainsString('deadlock', $out);
        $this->assertStringNotContainsString('worker exit timeout', $out);
        $this->assertLessThan(2.0, $took, "stopping took {$took}s; max_wait_time is 3 s\n" . $out);

        $log = (string) @file_get_contents($this->dir . '/debug.log');
        for ($n = 1; $n <= self::REQUESTS; $n++) {
            $this->assertMatchesRegularExpression("/shutdown-test ping $n\\b/", $log, "log line $n lost");
        }
    }
}
