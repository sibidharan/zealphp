<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\CGI;

use PHPUnit\Framework\TestCase;
use ZealPHP\CGI\WorkerPool;

/**
 * SPIKE — End-to-end validation for the worker pool. Spawns real PHP
 * subprocesses (not mocked) so the IPC + spawn + dispatch + recycle
 * loop is exercised against the real entry script.
 *
 * NOTE: these tests do real proc_open and disk I/O. Each test creates a
 * temporary fixture PHP file in sys_get_temp_dir() and deletes it on
 * tearDown. Slower than pure-unit tests but the spike needs the real
 * end-to-end roundtrip to be validated, not mocked stubs.
 */
final class WorkerPoolTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/zptest-pool-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        // Recursive rm of the per-test tmp dir.
        if (!is_dir($this->tmpDir)) {
            return;
        }
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $f) {
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

    public function testSpawnsRequestedPoolSize(): void
    {
        $pool = new WorkerPool(size: 2);
        try {
            $this->assertSame(2, $pool->size());
            $this->assertSame([0, 0], $pool->servedCounts());
        } finally {
            $pool->close();
        }
    }

    public function testDispatchExecutesSimpleEchoFile(): void
    {
        $file = $this->fixture('echo.php', 'echo "ok";');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $file]);
            $this->assertSame('ok', $resp['body']);
            $this->assertSame(200, $resp['status']);
        } finally {
            $pool->close();
        }
    }

    public function testDispatchReturnsArrayPayload(): void
    {
        $file = $this->fixture('json.php', 'return ["hello" => "world", "n" => 42];');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $file]);
            $this->assertSame(['hello' => 'world', 'n' => 42], $resp['return_value']);
        } finally {
            $pool->close();
        }
    }

    public function testDispatchPopulatesSuperglobalsFromRequest(): void
    {
        $file = $this->fixture('echo-get.php', 'echo $_GET["q"] ?? "missing";');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $file, 'get' => ['q' => 'hello']]);
            $this->assertSame('hello', $resp['body']);
        } finally {
            $pool->close();
        }
    }

    public function testWorkerHandlesMultipleSequentialRequests(): void
    {
        $file = $this->fixture('count.php', 'echo "tick";');
        $pool = new WorkerPool(size: 1);
        try {
            for ($i = 0; $i < 10; $i++) {
                $resp = $pool->dispatch(['file' => $file]);
                $this->assertSame('tick', $resp['body'], "iter $i");
            }
            // Same worker handled all 10 (single-worker pool).
            $this->assertSame([10], $pool->servedCounts());
        } finally {
            $pool->close();
        }
    }

    /**
     * FPM-style $GLOBALS cleanup contract (issue #18 follow-up).
     *
     * pool_worker.php snapshots $GLOBALS at boot and unsets any added keys
     * between requests. This is THE mechanism that PHP-FPM uses at the SAPI
     * level to prevent request-scoped globals from leaking across requests in
     * a long-lived process. Without it, anything the first request put into
     * `global $x` or `$GLOBALS['y']` is visible to the second request — which
     * is exactly what breaks WordPress's `wp_did_header` sentinel pattern.
     *
     * This test pins the contract: a global set in request N is NOT visible
     * in request N+1 on the same worker.
     */
    public function testGlobalsAreCleanedBetweenRequestsFpmStyle(): void
    {
        // Request 1 sets $GLOBALS['leak_canary']; request 2 probes for it.
        $setter = $this->fixture(
            'set-global.php',
            '$GLOBALS["leak_canary"] = "from_request_1"; echo "set";'
        );
        $reader = $this->fixture(
            'read-global.php',
            'echo isset($GLOBALS["leak_canary"]) ? "LEAK:" . $GLOBALS["leak_canary"] : "clean";'
        );
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 100);
        try {
            $resp1 = $pool->dispatch(['file' => $setter]);
            $this->assertSame('set', $resp1['body'], 'request 1 should set the global');

            $resp2 = $pool->dispatch(['file' => $reader]);
            $this->assertSame(
                'clean',
                $resp2['body'],
                'request 2 must NOT see request 1\'s $GLOBALS["leak_canary"] — FPM-style cleanup contract'
            );

            // Same worker handled both (single-worker pool, no recycle yet).
            $this->assertSame([2], $pool->servedCounts());
        } finally {
            $pool->close();
        }
    }

    /**
     * The cleanup must NOT touch superglobals (the pool_worker.php
     * reset_request_state() explicitly resets those — but the $GLOBALS
     * cleanup loop must skip them). This test confirms a `$_GET['x']`
     * set BY THE PARENT'S REQUEST FRAME (not by the included file)
     * still reaches the subprocess on request N+1 after a different
     * request N that didn't have it.
     */
    public function testSuperglobalsStillFlowAcrossRequestsAfterGlobalsCleanup(): void
    {
        $file = $this->fixture(
            'get-probe.php',
            'echo $_GET["q"] ?? "missing";'
        );
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 100);
        try {
            $resp1 = $pool->dispatch(['file' => $file, 'get' => ['q' => 'first']]);
            $this->assertSame('first', $resp1['body']);

            // Request 2 with DIFFERENT GET — must see its own value, not leak from req 1.
            $resp2 = $pool->dispatch(['file' => $file, 'get' => ['q' => 'second']]);
            $this->assertSame('second', $resp2['body']);

            // Request 3 with NO GET — must see clean (nothing leaking from req 1 or 2).
            $resp3 = $pool->dispatch(['file' => $file]);
            $this->assertSame('missing', $resp3['body']);
        } finally {
            $pool->close();
        }
    }

    public function testWorkerCrashReturns500WithStderr(): void
    {
        // posix_kill(SIGKILL) kills the subprocess before it can send a
        // response frame — exercises the stderr-capture + null-response path.
        $file = $this->fixture('crash.php', 'posix_kill(getmypid(), 9);');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $file]);
            $this->assertSame(500, $resp['status']);
            $this->assertStringContainsString('response not received', $resp['body']);
        } finally {
            $pool->close();
        }
    }

    public function testMissingFileReturns404(): void
    {
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => '/nonexistent/file.php']);
            $this->assertSame(404, $resp['status']);
        } finally {
            $pool->close();
        }
    }

    public function testThrowingFileReturns500(): void
    {
        $file = $this->fixture('boom.php', 'throw new \RuntimeException("kaboom");');
        $pool = new WorkerPool(size: 1);
        try {
            $resp = $pool->dispatch(['file' => $file]);
            $this->assertSame(500, $resp['status']);
            $this->assertIsString($resp['body']);
            $this->assertStringContainsString('kaboom', $resp['body']);
        } finally {
            $pool->close();
        }
    }

    public function testRecycleAfterMaxRequests(): void
    {
        $file = $this->fixture('cycle.php', 'echo getmypid();');
        // Recycle after 2 requests — should spawn a fresh worker on the 3rd dispatch.
        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 2);
        try {
            $r1 = $pool->dispatch(['file' => $file])['body'];
            $r2 = $pool->dispatch(['file' => $file])['body'];
            $r3 = $pool->dispatch(['file' => $file])['body'];

            $this->assertSame($r1, $r2, 'r1 + r2 same worker pid');
            $this->assertNotSame($r1, $r3, 'r3 should be a recycled worker (different pid)');
        } finally {
            $pool->close();
        }
    }

    public function testInvalidSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerPool(size: 0);
    }

    /**
     * Regression for issue #108 — session data set inside one pool dispatch
     * MUST persist to disk so a subsequent dispatch with the same PHPSESSID
     * sees it.
     *
     * Pre-fix: pool_reset_request_state() nulled $_SESSION between requests
     * WITHOUT calling session_write_close(), so PHP's native shutdown
     * sequence never fired (the worker doesn't shut down between frames)
     * and the session file on disk stayed empty. Reading on the next
     * dispatch returned an empty session → "Value: EMPTY".
     *
     * Post-fix: pool_reset_request_state() calls session_write_close()
     * before nulling, so request N's writes are flushed and visible to
     * request N+1.
     */
    public function testSessionDataPersistsAcrossPoolDispatches(): void
    {
        $sid       = 'zptest' . bin2hex(random_bytes(6));
        $sessDir   = sys_get_temp_dir() . '/zptest-sess-' . bin2hex(random_bytes(4));
        mkdir($sessDir, 0700, true);
        $setter = $this->fixture(
            'set.php',
            'session_save_path(' . var_export($sessDir, true) . ');'
            . ' session_start();'
            . ' $_SESSION["test"] = "Success!";'
            . ' echo "Session Set!";'
        );
        $getter = $this->fixture(
            'get.php',
            'session_save_path(' . var_export($sessDir, true) . ');'
            . ' session_start();'
            . ' echo "Value: " . ($_SESSION["test"] ?? "EMPTY");'
        );

        $pool = new WorkerPool(size: 1, maxRequestsPerWorker: 100);
        try {
            $r1 = $pool->dispatch([
                'file'    => $setter,
                'cookies' => ['PHPSESSID' => $sid],
            ]);
            $this->assertSame('Session Set!', $r1['body']);

            $r2 = $pool->dispatch([
                'file'    => $getter,
                'cookies' => ['PHPSESSID' => $sid],
            ]);
            $this->assertSame(
                'Value: Success!',
                $r2['body'],
                'issue #108 — session data set in request 1 must survive into request 2 on the same pool worker'
            );
        } finally {
            $pool->close();
            // Best-effort cleanup of the per-test session dir.
            foreach (glob($sessDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($sessDir);
        }
    }
}
