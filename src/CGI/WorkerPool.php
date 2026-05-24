<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

/**
 * SPIKE — Master-side pool manager for ZealPHP's native FCGI-style worker pool.
 *
 * Spawns N persistent PHP subprocesses (via `proc_open`) at construction,
 * dispatches request frames to idle workers via a free-list, auto-respawns
 * any worker that dies (crash, recycle, OOM).
 *
 * Goal of the spike: validate sub-5ms per-request latency, prove the
 * auto-respawn loop works, prove a no-side-effect PHP file can be served
 * 1000+ times without leaking workers. NOT integrated into App.php yet —
 * exposed as a standalone API used by tests + bench script.
 *
 * Concurrency model in the spike: synchronous dispatch (each call to
 * dispatch() picks a free worker, writes a frame, blocks on the response).
 * The production version will replace the free-list with a
 * Coroutine\Channel so multiple coroutines can dispatch in parallel.
 */
final class WorkerPool
{
    /** @var list<array{proc: resource, stdin: resource, stdout: resource, stderr: resource, pid: int, served: int}> */
    private array $workers = [];

    /** @var list<int> Indices into $workers that are currently idle. */
    private array $idle = [];

    private bool $closed = false;

    public function __construct(
        int $size = 4,
        private readonly int $maxRequestsPerWorker = 500,
        private readonly ?string $workerEntry = null,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException('WorkerPool size must be >= 1');
        }
        for ($i = 0; $i < $size; $i++) {
            $this->workers[] = $this->spawn();
            $this->idle[]   = $i;
        }
    }

    /**
     * Dispatch one request to an idle worker and return the response frame.
     *
     * @param array<mixed,mixed> $request The frame payload (file, server, get,
     *                                    post, cookies, files, body).
     * @return array<string,mixed>        The response frame
     *                                    (status, headers, cookies, body, return_value).
     */
    public function dispatch(array $request): array
    {
        if ($this->closed) {
            throw new \RuntimeException('WorkerPool: already closed');
        }
        $idx = $this->pickIdleWorker();
        $w   = $this->workers[$idx];

        IPC::writeFrame($w['stdin'], $request);
        $resp = IPC::readFrame($w['stdout']);

        $this->workers[$idx]['served']++;
        $served = $this->workers[$idx]['served'];

        // If the worker died mid-request OR hit the recycle limit, respawn it.
        if ($resp === null || $served >= $this->maxRequestsPerWorker || !$this->isAlive($w)) {
            $this->respawn($idx);
        } else {
            $this->idle[] = $idx; // return to free-list
        }

        if ($resp === null) {
            return [
                'status'  => 500,
                'body'    => 'WorkerPool: worker died mid-request — response not received',
                'headers' => [],
                'cookies' => [],
            ];
        }

        // Force-key by string for the strict return type; IPC::readFrame
        // returns array<mixed,mixed> so PHPStan needs an explicit narrowing.
        $out = [];
        foreach ($resp as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** How many workers are alive right now. */
    public function size(): int
    {
        return count($this->workers);
    }

    /**
     * Returns the per-worker `served` counter (for tests / observability).
     *
     * @return list<int>
     */
    public function servedCounts(): array
    {
        return array_map(static fn (array $w): int => $w['served'], $this->workers);
    }

    /** Shut down all workers cleanly (close pipes, wait for exit). */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->workers as $w) {
            @fclose($w['stdin']);  // signal EOF → worker exits its loop
            @fclose($w['stdout']);
            @fclose($w['stderr']);
            @proc_close($w['proc']);
        }
        $this->workers = [];
        $this->idle    = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    // -------------------------------------------------------------------
    // Internals

    /** Pick an idle worker index. Spike: simple LIFO, blocks if all busy. */
    private function pickIdleWorker(): int
    {
        // In the synchronous spike, all workers MUST be idle when we get here
        // (caller dispatches one-at-a-time). If not, dispatch synchronously
        // by reusing worker 0 — production version uses Coroutine\Channel.
        if (count($this->idle) === 0) {
            return 0;
        }

        return array_pop($this->idle);
    }

    /**
     * @param array{proc: resource, stdin: resource, stdout: resource, stderr: resource, pid: int, served: int} $w
     */
    private function isAlive(array $w): bool
    {
        return proc_get_status($w['proc'])['running'] === true;
    }

    /**
     * @return array{proc: resource, stdin: resource, stdout: resource, stderr: resource, pid: int, served: int}
     */
    private function spawn(): array
    {
        $entry = $this->workerEntry ?? dirname(__DIR__) . '/pool_worker.php';
        if (!is_file($entry)) {
            throw new \RuntimeException("WorkerPool: worker entry not found: $entry");
        }

        $desc = [
            0 => ['pipe', 'r'], // stdin   — parent writes requests
            1 => ['pipe', 'w'], // stdout  — parent reads responses
            2 => ['pipe', 'w'], // stderr  — diagnostics
        ];
        $pipes = [];
        $env = [
            'ZEALPHP_POOL_MAX_REQUESTS' => (string) $this->maxRequestsPerWorker,
        ];
        $proc = proc_open(['php', $entry], $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('WorkerPool: proc_open failed for ' . $entry);
        }

        // Wait for the READY signal on stderr — bounds boot time so callers
        // don't dispatch into a worker that hasn't loaded its autoloader yet.
        $ready = fgets($pipes[2]);
        if ($ready === false || !str_contains($ready, 'READY')) {
            // Worker died during boot — capture diagnostics and bail.
            $more = stream_get_contents($pipes[2]) ?: '';
            proc_terminate($proc);
            @proc_close($proc);
            throw new \RuntimeException("WorkerPool: worker boot failed: " . trim((string) $ready . $more));
        }

        $pid = proc_get_status($proc)['pid'];

        return [
            'proc'   => $proc,
            'stdin'  => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'pid'    => $pid,
            'served' => 0,
        ];
    }

    private function respawn(int $idx): void
    {
        $old = $this->workers[$idx];
        @fclose($old['stdin']);
        @fclose($old['stdout']);
        @fclose($old['stderr']);
        @proc_close($old['proc']);

        $this->workers[$idx] = $this->spawn();
        $this->idle[]       = $idx;
    }
}
