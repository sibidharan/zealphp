<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\IPC;

/**
 * Pins the fork-master watchdog (the adversarial-review #1 fix): a child wedged
 * inside the legacy `include` (slow query / blocking call / infinite loop) is
 * SIGKILLed once it exceeds ZEALPHP_FORK_TIMEOUT, and its concurrency slot is
 * reclaimed — so a handful of hung requests can never permanently wedge the
 * fork-master. Runs with cap=1 + a 2 s timeout for a fast, deterministic check.
 */
final class ForkWatchdogTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $tmpDir = '';
    private string $sock = '';

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            $this->markTestSkipped('fork mode requires pcntl + posix');
        }
        $this->tmpDir = sys_get_temp_dir() . '/zealphp_forkwd_' . getmypid() . '_' . uniqid();
        @mkdir($this->tmpDir, 0777, true);
        $this->sock = $this->tmpDir . '/fm.sock';

        $env = array_merge($_ENV, [
            'ZEALPHP_FORK_SOCK'           => $this->sock,
            'ZEALPHP_FORK_MAX_CONCURRENT' => '1',   // a single slot — easy to saturate
            'ZEALPHP_FORK_TIMEOUT'        => '2',   // kill a wedged child after 2 s
            'ZEALPHP_CWD'                 => ZEALPHP_ROOT,
        ]);
        $errLog = $this->tmpDir . '/fm.log';
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $errLog, 'a'], 2 => ['file', $errLog, 'a']];
        $proc = proc_open([PHP_BINARY, ZEALPHP_ROOT . '/src/fork_master.php'], $desc, $this->pipes, ZEALPHP_ROOT, $env);
        if (!is_resource($proc)) {
            $this->markTestSkipped('could not spawn fork_master');
        }
        $this->proc = $proc;
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            clearstatcache(false, $this->sock);
            if (file_exists($this->sock)) {
                break;
            }
            usleep(20000);
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc, 9);
            foreach ($this->pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
            @proc_close($this->proc);
        }
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function fixture(string $name, string $php): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $php);
        return $path;
    }

    /** @return resource */
    private function connect()
    {
        $errno = 0;
        $errstr = '';
        $c = @stream_socket_client('unix://' . $this->sock, $errno, $errstr, 5.0);
        $this->assertIsResource($c, "connect: $errstr");
        return $c;
    }

    /** @return array<string,mixed> */
    private function frameFor(string $file): array
    {
        return ['file' => $file, 'server' => ['REQUEST_METHOD' => 'GET'], 'get' => [], 'post' => [], 'cookies' => [], 'files' => [], 'body' => ''];
    }

    public function testWedgedChildIsKilledAndSlotReclaimed(): void
    {
        $hang = $this->fixture('hang.php', "<?php\nsleep(30);\necho 'never';\n");
        $ok   = $this->fixture('ok.php', "<?php\necho 'RECLAIMED';\n");

        // 1) Occupy the single slot with a child that wedges for 30 s.
        $wedged = $this->connect();
        IPC::writeFrame($wedged, $this->frameFor($hang));
        usleep(200000); // let the master fork the wedged child (now at cap=1)

        // 2) A second request: the master is at the cap and spins in backpressure.
        //    The watchdog must SIGKILL the wedged child at ~2 s, free the slot,
        //    and then serve this request. Allow up to ~8 s.
        $t0 = microtime(true);
        $live = $this->connect();
        IPC::writeFrame($live, $this->frameFor($ok));
        $resp = IPC::readFrame($live, 8.0);
        $elapsed = microtime(true) - $t0;

        $this->assertIsArray($resp, 'second request must be served after the wedged child is reaped');
        $this->assertSame('RECLAIMED', $resp['body'] ?? null, 'the slot was reclaimed and a fresh fork served the request');
        $this->assertGreaterThan(1.5, $elapsed, 'it should have waited for the ~2 s watchdog (proving the slot WAS blocked then freed)');
        $this->assertLessThan(7.5, $elapsed, 'and not the full 30 s hang');

        @fclose($live);
        @fclose($wedged);
    }
}
