<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

/**
 * scripts/zealphp.sh only ever signals its OWN server: when the PID file is
 * missing it falls back to the process listening on the port, and that
 * fallback must skip a ZealPHP server started from another app directory.
 *
 * Each case runs the real script against a stand-in "server" (`php … app.php`
 * holding a listen socket) on a private port and log dir.
 */
final class ZealphpShScriptTest extends TestCase
{
    private string $logDir = '';

    protected function setUp(): void
    {
        if (!is_dir('/proc/self') || trim((string) shell_exec('command -v ss')) === '') {
            $this->markTestSkipped('needs /proc and ss');
        }
        $this->logDir = sys_get_temp_dir() . '/zp_sh_' . getmypid() . '_' . uniqid();
        mkdir($this->logDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);
    }

    /** Start `php -r <listen+sleep> app.php` in $cwd; return its pid. */
    private function spawnListener(string $cwd, int $port): int
    {
        $code = '$s = stream_socket_server("tcp://127.0.0.1:' . $port . '"); sleep(30);';
        $pid = (int) trim((string) shell_exec('cd ' . escapeshellarg($cwd) . ' && '
            . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' app.php > /dev/null 2>&1 & echo $!'));
        for ($i = 0; $i < 50; $i++) {
            if (str_contains((string) shell_exec("ss -Hltn 'sport = :{$port}' 2>/dev/null"), ":{$port}")) {
                break;
            }
            usleep(100000);
        }
        return $pid;
    }

    private function runScript(string $command, int $port): string
    {
        $env = 'ZEALPHP_PORT=' . $port . ' ZEALPHP_LOG_DIR=' . escapeshellarg($this->logDir);
        return (string) shell_exec($env . ' bash ' . escapeshellarg(ZEALPHP_ROOT . '/scripts/zealphp.sh')
            . ' ' . escapeshellarg($command) . ' 2>&1 < /dev/null');
    }

    public function testStopLeavesAnotherAppsServerOnThePortAlone(): void
    {
        $other = sys_get_temp_dir() . '/zp_sh_other_' . getmypid();
        @mkdir($other);
        $pid = $this->spawnListener($other, 59991);
        try {
            $out = $this->runScript('stop', 59991);
            $this->assertStringContainsString('another app directory', $out);
            $this->assertTrue(posix_kill($pid, 0), "another app's server must not be signalled");
        } finally {
            @posix_kill($pid, SIGKILL);
            @rmdir($other);
        }
    }

    public function testStopFindsThisAppsServerByPort(): void
    {
        $pid = $this->spawnListener(ZEALPHP_ROOT, 59992);
        try {
            $out = $this->runScript('stop', 59992);
            $this->assertStringContainsString("Stopped pid {$pid}", $out);
            usleep(200000);
            $this->assertFalse(@posix_kill($pid, 0), 'this app\'s server is stopped');
        } finally {
            @posix_kill($pid, SIGKILL);
        }
    }
}
