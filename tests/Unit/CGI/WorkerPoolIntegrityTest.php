<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\IPC;
use ZealPHP\CGI\WorkerPool;

/**
 * Response-integrity guards for the native worker pool (C1, C3, C4).
 *
 * The pool is not a boundary between mutually distrusting tenants: every
 * script it runs belongs to the same owner. These tests pin the cheap guards
 * that stop a BUG in that code (a stray write to fd 3, a tampered loop
 * variable, a wedged reset) from handing one user's response to the next
 * user served by the same subprocess.
 *
 * Real subprocesses, no mocks: the guards live on both sides of the pipe.
 */
final class WorkerPoolIntegrityTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zptest-poolint-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function fixture(string $name, string $body): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, "<?php\n" . $body);
        return $path;
    }

    /** PHP that writes one complete, well-formed fd-3 response frame of its own. */
    private static function forgeFrameCode(string $body): string
    {
        return '$__f = fopen("php://fd/3", "w");'
            . '$__j = json_encode(["status" => 200, "headers" => [], "cookies" => [], "rawcookies" => [],'
            . ' "body_fd" => 3, "body_length" => ' . strlen($body) . ']);'
            . 'fwrite($__f, pack("N", strlen($__j)) . $__j . ' . var_export($body, true) . ');'
            . 'fflush($__f);';
    }

    public function testForgedFrameMidRequestIsRefusedAndNextUserGetsOwnPage(): void
    {
        // C1: a script writes a complete frame to fd 3 before the real one.
        // Before the fix the parent took the forged frame and left the real
        // frame + body in the pipe, so the NEXT request on this subprocess was
        // answered with the previous user's page.
        $forge  = $this->fixture('forge.php', self::forgeFrameCode('FORGED') . 'echo "SECRET-OF-USER-A";');
        $victim = $this->fixture('victim.php', 'echo "page-for-user-B";');
        $pool = new WorkerPool(size: 1);
        try {
            $a = $pool->dispatch(['file' => $forge], 5.0);
            $this->assertSame(502, $a['status'], 'a corrupted exchange is a 502, not the forged frame');
            $this->assertStringNotContainsString('FORGED', (string) $a['body']);
            $this->assertStringNotContainsString('SECRET-OF-USER-A', (string) $a['body']);

            $b = $pool->dispatch(['file' => $victim], 5.0);
            $this->assertSame(200, $b['status']);
            $this->assertSame('page-for-user-B', $b['body'], 'the next user gets their own page, not user A\'s');
            $this->assertSame([1], $pool->servedCounts(), 'the corrupted subprocess was replaced');
        } finally {
            $pool->close();
        }
    }

    public function testFrameWrittenAfterTheResponseIsCaughtBeforeTheNextRequest(): void
    {
        // C1: bytes that reach fd 3 after the real response (here from a
        // destructor that runs during the per-request reset) must not be read
        // as the next request's response. The parent sees them before it sends
        // the next request, replaces the subprocess, and serves the request on
        // a clean one.
        $late = $this->fixture('late.php',
            'class LateWriter { public function __destruct() { usleep(300000); ' . self::forgeFrameCode('STALE') . ' } }'
            . '$GLOBALS["late_writer"] = new LateWriter(); echo "first";');
        $victim = $this->fixture('victim.php', 'echo "page-for-user-B";');
        $pool = new WorkerPool(size: 1);
        try {
            $a = $pool->dispatch(['file' => $late], 5.0);
            $this->assertSame('first', $a['body']);
            usleep(600_000); // let the reset (and the destructor) run

            $b = $pool->dispatch(['file' => $victim], 5.0);
            $this->assertSame(200, $b['status']);
            $this->assertSame('page-for-user-B', $b['body'], 'stale fd 3 bytes are never served');
            $this->assertSame([1], $pool->servedCounts(), 'served on a fresh subprocess');
        } finally {
            $pool->close();
        }
    }

    public function testAppCannotRedirectTheResponseChannel(): void
    {
        // C1: the loop-control values used to be plain globals the included
        // script could overwrite. Nulling $__pw_fd3 sent the response down the
        // legacy STDOUT path the parent no longer reads, so the request hung
        // until the timeout.
        $tamper = $this->fixture('tamper.php',
            '$__pw_fd3 = null; $__pw_max_requests = PHP_INT_MAX; $__pw_count = -1000; echo "still-framed";');
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 500);
        try {
            $t0 = microtime(true);
            $resp = $pool->dispatch(['file' => $tamper], 3.0);
            $this->assertSame(200, $resp['status']);
            $this->assertSame('still-framed', $resp['body']);
            $this->assertLessThan(2.0, microtime(true) - $t0);
        } finally {
            $pool->close();
        }
    }

    public function testOversizedBodyLengthIsAProtocolErrorAndRespawns(): void
    {
        // C4: a frame announcing a body over the 64 MB cap used to be accepted
        // with the body silently zeroed, leaving the bytes in the pipe.
        $stub = $this->tmpDir . '/stub.php';
        file_put_contents($stub, '<?php
            require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
            $fd3 = fopen("php://fd/3", "w");
            fwrite(STDERR, "READY\n");
            while (($req = \ZealPHP\CGI\IPC::readFrame(STDIN)) !== null) {
                $frame = \ZealPHP\CGI\IPC::encodeFrame(["status" => 200, "headers" => [], "body_fd" => 3,
                    "body_length" => \ZealPHP\CGI\IPC::MAX_FRAME_BYTES + 1, "pid" => getmypid()]);
                fwrite($fd3, $frame . "tail");
                fflush($fd3);
            }');
        $pool = new WorkerPool(size: 1, workerEntry: $stub);
        try {
            $resp = $pool->dispatch(['file' => '/x'], 5.0);
            $this->assertSame(502, $resp['status']);
            $this->assertSame([0], $pool->servedCounts(), 'the subprocess was replaced');
            $again = $pool->dispatch(['file' => '/x'], 5.0);
            $this->assertSame(502, $again['status'], 'a fresh subprocess answers (and is replaced) again');
        } finally {
            $pool->close();
        }
    }

    public function testOversizedRequestFrameIsRefusedWith413(): void
    {
        // C4: a request frame over the IPC cap is refused before it reaches a
        // subprocess (which would drop the frame and exit).
        $echo = $this->fixture('echo.php', 'echo "ok";');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $echo, 'body' => str_repeat('x', IPC::MAX_FRAME_BYTES)], 5.0);
            $this->assertSame(413, $resp['status']);
            $this->assertSame([0], $pool->servedCounts(), 'no subprocess was used');
            $ok = $pool->dispatch(['file' => $echo], 5.0);
            $this->assertSame('ok', $ok['body']);
            $this->assertSame([1], $pool->servedCounts());
        } finally {
            $pool->close();
        }
    }

    public function testCoroutineRequestWriteHasADeadlineAndDrainsTheChild(): void
    {
        // C3: in a coroutine the request frame was written with a blocking
        // fwrite() after a single 64 KB drain of the child's stdout/stderr. A
        // child still busy writing a large stderr burst from the previous
        // request's reset never read its stdin, so the write (and the slot)
        // hung forever. The write now runs inside the select loop, draining
        // the child the whole time, under the dispatch deadline.
        $stall = $this->fixture('stall.php',
            'class StderrBurst { public function __destruct() { fwrite(STDERR, str_repeat("w", 1_000_000)); } }'
            . '$GLOBALS["burst"] = new StderrBurst(); echo "first";');
        $echo = $this->fixture('echo.php', 'echo strlen(file_get_contents("php://input"));');
        $result = [];
        $flags = \OpenSwoole\Runtime::getHookFlags();
        \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        try {
            \OpenSwoole\Coroutine::run(function () use ($stall, $echo, &$result): void {
                $pool = new WorkerPool(size: 1);
                try {
                    $result['a'] = $pool->dispatch(['file' => $stall], 5.0);
                    $t0 = microtime(true);
                    $result['b'] = $pool->dispatch(['file' => $echo, 'body' => str_repeat('p', 2_000_000)], 5.0);
                    $result['elapsed'] = microtime(true) - $t0;
                } finally {
                    $pool->close();
                }
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine($flags);
        }
        $this->assertSame('first', $result['a']['body'] ?? null);
        $this->assertSame(200, $result['b']['status'] ?? null);
        $this->assertSame('2000000', $result['b']['body'] ?? null, 'the whole 2 MB body reached the child');
        $this->assertLessThan(5.0, $result['elapsed'] ?? 99.0);
    }

    public function testCoroutineWriteToAWedgedChildTimesOut(): void
    {
        // C3: a child that never reads stdin must cost one 504 and a respawn,
        // not a slot leaked forever.
        $stub = $this->tmpDir . '/deaf.php';
        file_put_contents($stub, '<?php fwrite(STDERR, "READY\n"); sleep(4);');
        $result = [];
        $flags = \OpenSwoole\Runtime::getHookFlags();
        \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        try {
            \OpenSwoole\Coroutine::run(function () use ($stub, &$result): void {
                $pool = new WorkerPool(size: 1, workerEntry: $stub);
                try {
                    $t0 = microtime(true);
                    $result['r'] = $pool->dispatch(['file' => '/x', 'body' => str_repeat('p', 1_000_000)], 1.5);
                    $result['elapsed'] = microtime(true) - $t0;
                } finally {
                    $pool->close();
                }
            });
        } finally {
            \OpenSwoole\Runtime::enableCoroutine($flags);
        }
        $this->assertSame(504, $result['r']['status'] ?? null);
        $this->assertLessThan(4.0, $result['elapsed'] ?? 99.0);
    }
}
