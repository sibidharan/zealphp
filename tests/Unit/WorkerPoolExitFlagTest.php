<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\WorkerPool;

/**
 * Tests for WorkerPool._exit flag behaviour (PR #150 / fix/pool-worker-exit-survival).
 *
 * When a pool subprocess calls exit() it fires a registered shutdown function
 * that writes a final IPC frame with `_exit => true` before the process
 * terminates. On the next dispatch() call that reads this frame, WorkerPool
 * detects the flag and respawns the worker proactively — instead of waiting
 * for the next request to fail with a broken pipe.
 *
 * Coverage targets:
 *   - dispatch() source contains the _exit flag check and respawn call.
 *   - The four respawn triggers (null resp, exitFlag, recycle limit, dead process)
 *     are all present in dispatch().
 *   - The exitFlag derivation is correct (is_array guard + _exit key).
 *   - dispatch() with a normal response increments served count.
 *   - dispatch() when response carries _exit=true triggers respawn
 *     (served count resets to 0 on the new worker).
 *   - dispatch() on a closed pool throws RuntimeException.
 *
 * Live-subprocess tests use minimal PHP stubs that correctly implement the
 * WorkerPool IPC contract: emit "READY\n" on stderr, then loop reading and
 * writing IPC frames.
 */
final class WorkerPoolExitFlagTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Write a temporary stub PHP file and return its path. The caller is
     * responsible for unlinking it after the test.
     */
    private function writeStub(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zealphp_wp_stub_') . '.php';
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($path, "<?php\nrequire " . var_export($autoload, true) . ";\nuse ZealPHP\\CGI\\IPC;\n" . $body);
        return $path;
    }

    // ── Structural checks (no live subprocess needed) ─────────────────────

    /**
     * The dispatch() source must read the _exit key from the response frame
     * and use it to decide whether to respawn the worker.
     */
    public function testDispatchSourceContainsExitFlagCheck(): void
    {
        $rm = new \ReflectionMethod(WorkerPool::class, 'dispatch');
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        $this->assertStringContainsString('_exit', $body,
            'dispatch() must read the _exit flag from the response frame');
        $this->assertStringContainsString('respawn', $body,
            'dispatch() must call respawn when _exit flag is detected');
        $this->assertStringContainsString('exitFlag', $body,
            'dispatch() must use an $exitFlag variable (not inline the condition)');
    }

    /**
     * The respawn condition must cover _exit, null response, recycle limit,
     * and dead process — all four triggers must be present in dispatch().
     */
    public function testDispatchRespawnConditionCoversAllFourTriggers(): void
    {
        $rm = new \ReflectionMethod(WorkerPool::class, 'dispatch');
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        $this->assertStringContainsString('$resp === null', $body,
            'dispatch() must handle null response (subprocess died)');
        $this->assertStringContainsString('$exitFlag', $body,
            'dispatch() must handle exitFlag');
        $this->assertStringContainsString('maxRequestsPerWorker', $body,
            'dispatch() must handle recycle limit');
        $this->assertStringContainsString('isAlive', $body,
            'dispatch() must check isAlive()');
    }

    /**
     * The exitFlag must be derived from the response's _exit key with an
     * is_array guard — not from a hardcoded string or a different field.
     */
    public function testExitFlagDerivationIsCorrect(): void
    {
        $rm = new \ReflectionMethod(WorkerPool::class, 'dispatch');
        $file  = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        $this->assertMatchesRegularExpression(
            '/is_array\(\$resp\).*_exit/s',
            $body,
            "exitFlag derivation must guard is_array(\$resp) before accessing ['_exit']"
        );
    }

    // ── Live dispatch — normal (non-exit) path ────────────────────────────

    /**
     * A normal request that completes successfully increments the served
     * counter and returns the worker to the idle pool — pool size stays the same.
     */
    public function testNormalDispatchIncrementsServedCount(): void
    {
        // Stub: emits READY, then loops handling requests via the FD-3 IPC
        // contract — metadata frame (with body_length) on fd 3, body bytes on
        // STDOUT — exactly like the real pool_worker.php. (Writing the frame
        // to STDOUT instead would make dispatch() block the full timeout on
        // fd 3, which stays open while this long-lived stub keeps looping.)
        $stub = $this->writeStub(<<<'PHP'
            $fd3 = @fopen('php://fd/3', 'w');
            fwrite(STDERR, "READY\n");
            while (true) {
                $req = IPC::readFrame(STDIN);
                if ($req === null) break;
                $body = 'ok';
                if ($fd3 !== false) {
                    IPC::writeFrame($fd3, [
                        'status'       => 200,
                        'headers'      => [],
                        'cookies'      => [],
                        'body_length'  => strlen($body),
                        'return_value' => null,
                    ]);
                    fwrite(STDOUT, $body);
                    fflush(STDOUT);
                } else {
                    IPC::writeFrame(STDOUT, [
                        'status'       => 200,
                        'headers'      => [],
                        'cookies'      => [],
                        'body'         => $body,
                        'return_value' => null,
                    ]);
                }
            }
            PHP);

        try {
            $pool = new WorkerPool(1, 500, $stub);
            $this->assertSame([0], $pool->servedCounts(), 'served=0 before any dispatch');

            $resp = $pool->dispatch([
                'file' => '/fake.php', 'server' => [], 'get' => [],
                'post' => [], 'cookies' => [], 'files' => [], 'body' => '',
            ]);

            $this->assertSame(200, $resp['status'] ?? null, 'normal dispatch returns 200');
            $this->assertSame([1], $pool->servedCounts(), 'served incremented to 1');
            $this->assertSame(1, $pool->size(), 'pool size unchanged after normal dispatch');
        } finally {
            @unlink($stub);
        }
    }

    /**
     * When the response frame contains _exit=true the worker is respawned.
     * After respawn the pool size is the same (new worker) and its served count
     * is 0.
     */
    public function testExitFlagCausesRespawn(): void
    {
        // Stub: emits READY, handles one request with _exit=true, then exits.
        // After respawn the replacement stub also emits READY and handles requests.
        $stub = $this->writeStub(<<<'PHP'
            fwrite(STDERR, "READY\n");
            $req = IPC::readFrame(STDIN);
            if ($req !== null) {
                IPC::writeFrame(STDOUT, [
                    'status'       => 200,
                    'headers'      => [],
                    'cookies'      => [],
                    'body'         => 'exiting',
                    'return_value' => null,
                    '_exit'        => true,
                ]);
            }
            // Process exits — WorkerPool must respawn it.
            PHP);

        try {
            $pool = new WorkerPool(1, 500, $stub);

            $resp = $pool->dispatch([
                'file' => '/fake.php', 'server' => [], 'get' => [],
                'post' => [], 'cookies' => [], 'files' => [], 'body' => '',
            ]);

            $this->assertSame(200, $resp['status'] ?? null);
            $this->assertSame('exiting', $resp['body'] ?? null);

            // Pool size is still 1 — respawn replaced the exiting worker.
            $this->assertSame(1, $pool->size(),
                'pool size must remain 1 after respawn triggered by _exit flag');

            // The new worker has served=0 (fresh spawn).
            $this->assertSame([0], $pool->servedCounts(),
                'respawned worker served count must reset to 0');
        } finally {
            @unlink($stub);
        }
    }

    // ── Closed pool guard ─────────────────────────────────────────────────

    public function testDispatchOnClosedPoolThrows(): void
    {
        $stub = $this->writeStub(<<<'PHP'
            fwrite(STDERR, "READY\n");
            while (true) {
                $req = IPC::readFrame(STDIN);
                if ($req === null) break;
                IPC::writeFrame(STDOUT, ['status' => 200, 'headers' => [], 'cookies' => [], 'body' => '']);
            }
            PHP);

        try {
            $pool = new WorkerPool(1, 500, $stub);
            $pool->close();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already closed');
            $pool->dispatch([]);
        } finally {
            @unlink($stub);
        }
    }
}
