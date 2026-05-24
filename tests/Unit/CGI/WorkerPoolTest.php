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
}
