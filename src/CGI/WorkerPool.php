<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Master-side pool manager for ZealPHP's native FCGI-style worker pool.
 *
 * Spawns N persistent PHP subprocesses (via `proc_open`) at construction.
 * Each subprocess loops on stdin frames — reads a request payload, runs
 * the requested PHP file in its own clean global scope (mod_php-style
 * isolation per request), writes a response frame, then yields. Auto-
 * respawns any subprocess that dies (crash, `exit()`, OOM) or hits the
 * recycle limit (FPM `pm.max_requests` parity).
 *
 * Concurrency: idle subprocesses live in a `Coroutine\Channel`. Multiple
 * coroutines on the parent OpenSwoole worker dispatch in parallel — each
 * `dispatch()` call pops a worker from the channel (yields the coroutine
 * if the channel is empty), writes the request frame, reads the response
 * frame (pipe I/O yields under HOOK_ALL), and pushes the worker back to
 * the channel. The parent worker handles thousands of concurrent dispatch
 * coroutines while the subprocess pool executes legacy PHP synchronously.
 *
 * That's the architectural shape: PHP HTTP server (OpenSwoole worker) +
 * FPM-style isolation (subprocess pool) + async dispatch (coroutines).
 *
 * Outside a coroutine context (tests, CLI tools), `dispatch()` falls back
 * to a synchronous LIFO array — the same code path the spike used. This
 * makes the test surface trivial: `new WorkerPool() → $pool->dispatch()`
 * works without wrapping in `Co::run()`.
 */
final class WorkerPool
{
    /** @var list<array{proc: resource, stdin: resource, stdout: resource, stderr: resource, pid: int, served: int}> */
    private array $workers = [];

    /**
     * Coroutine idle queue. Created lazily — Channel push REQUIRES coroutine
     * context, so we can't populate it at constructor time (parent worker
     * boot is typically non-coroutine). First dispatch in a coroutine
     * transfers the sync queue into the channel; from then on, all idle/busy
     * transitions go through the channel for proper parallel-dispatch yield.
     */
    private ?Channel $idleChan = null;

    /** @var list<int> Idle worker indices — primary queue at boot + the fallback when no coroutine context. */
    private array $idleSync = [];

    private bool $channelPopulated = false;
    private bool $closed = false;
    private readonly int $size;

    public function __construct(
        int $size = 4,
        private readonly int $maxRequestsPerWorker = 500,
        private readonly ?string $workerEntry = null,
    ) {
        if ($size < 1) {
            throw new \InvalidArgumentException('WorkerPool size must be >= 1');
        }
        $this->size = $size;

        for ($i = 0; $i < $size; $i++) {
            $this->workers[] = $this->spawn();
            $this->idleSync[] = $i;
        }
    }

    /**
     * Dispatch one request to an idle worker and return the response frame.
     * Coroutine-aware: yields if all workers are busy until one frees up.
     *
     * @param array<mixed,mixed> $request Frame payload (file, server, get,
     *                                    post, cookies, files, body).
     * @param float              $timeout Max seconds to wait for an idle
     *                                    worker before returning 503.
     * @return array<string,mixed>        Response (status, headers, cookies,
     *                                    body, return_value).
     */
    public function dispatch(array $request, float $timeout = 30.0): array
    {
        if ($this->closed) {
            throw new \RuntimeException('WorkerPool: already closed');
        }

        $idx = $this->popIdleWorker($timeout);
        if ($idx === null) {
            return [
                'status'  => 503,
                'body'    => 'WorkerPool: all subprocesses busy (timeout after ' . $timeout . 's)',
                'headers' => [],
                'cookies' => [],
            ];
        }

        $w = $this->workers[$idx];

        IPC::writeFrame($w['stdin'], $request);
        $resp = IPC::readFrame($w['stdout']);

        $this->workers[$idx]['served']++;
        $served = $this->workers[$idx]['served'];

        // Respawn if the subprocess died mid-request, hit the recycle
        // limit, or the OS reports it's no longer running (proc_get_status
        // ['running' => false]). FPM-equivalent recovery semantics.
        $err = '';
        if ($resp === null) {
            stream_set_blocking($w['stderr'], false);
            $err = stream_get_contents($w['stderr']);
        }
        
        if ($resp === null || $served >= $this->maxRequestsPerWorker || !$this->isAlive($w)) {
            $this->respawn($idx);
        } else {
            $this->returnToIdle($idx);
        }

        if ($resp === null) {
            return [
                'status'  => 500,
                'body'    => 'WorkerPool: subprocess died mid-request — response not received. Stderr: ' . $err,
                'headers' => [],
                'cookies' => [],
            ];
        }

        // Force-narrow to array<string,mixed> for the strict return type;
        // IPC::readFrame returns array<mixed,mixed>.
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
     * Per-worker `served` counter (for tests / observability / /healthz).
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
            @fclose($w['stdin']);   // signal EOF → subprocess exits its loop
            @fclose($w['stdout']);
            @fclose($w['stderr']);
            @proc_close($w['proc']);
        }
        $this->workers  = [];
        $this->idleSync = [];
        if ($this->idleChan !== null) {
            $this->idleChan->close();
            $this->idleChan = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // -------------------------------------------------------------------
    // Internals

    /**
     * Pop an idle worker index. In a coroutine, lazy-promotes the queue to
     * a Coroutine\Channel (first call) and yields on it until a worker is
     * freed or the timeout expires. Outside a coroutine, uses the
     * synchronous LIFO — no yield, returns null if empty.
     */
    private function popIdleWorker(float $timeout): ?int
    {
        if (Coroutine::getCid() > 0 && class_exists(Channel::class, false)) {
            $this->ensureChannelPopulated();
            if ($this->idleChan !== null) {
                /** @var int|false $popped */
                $popped = $this->idleChan->pop($timeout);
                return $popped === false ? null : (int) $popped;
            }
        }
        return count($this->idleSync) > 0 ? (int) array_pop($this->idleSync) : null;
    }

    /**
     * Return a worker to the idle pool. Routes to the channel (in coroutine
     * context, once promoted) so any coroutine waiting on `pop` wakes up;
     * otherwise pushes to the sync LIFO.
     */
    private function returnToIdle(int $idx): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->idleChan !== null && Coroutine::getCid() > 0) {
            // Push with a small timeout — channel is sized to N, can never
            // be full beyond the pool size, so this never blocks meaningfully.
            $this->idleChan->push($idx, 0.001);
            return;
        }
        $this->idleSync[] = $idx;
    }

    /**
     * One-time promotion of the sync idle queue into a Coroutine\Channel.
     * Runs on the first dispatch inside a coroutine context, where push is
     * legal. After this, the sync queue is empty and the channel owns
     * idle-worker tracking for all subsequent dispatches.
     */
    private function ensureChannelPopulated(): void
    {
        if ($this->channelPopulated) {
            return;
        }
        if ($this->idleChan === null) {
            $this->idleChan = new Channel($this->size);
        }
        foreach ($this->idleSync as $i) {
            $this->idleChan->push($i, 0.001);
        }
        $this->idleSync         = [];
        $this->channelPopulated = true;
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
        $env = array_merge(getenv(), [
            'ZEALPHP_POOL_MAX_REQUESTS' => (string) $this->maxRequestsPerWorker,
        ]);
        $proc = proc_open([\PHP_BINARY, '-d', 'display_errors=stderr', $entry], $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('WorkerPool: proc_open failed for ' . $entry);
        }

        // Bounded wait for READY signal on stderr — guarantees the
        // subprocess has loaded its autoloader + uopz overrides before we
        // dispatch the first frame.
        $ready = fgets($pipes[2]);
        if ($ready === false || !str_contains($ready, 'READY')) {
            $more = stream_get_contents($pipes[2]) ?: '';
            proc_terminate($proc);
            @proc_close($proc);
            throw new \RuntimeException("WorkerPool: subprocess boot failed: " . trim((string) $ready . $more));
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
        $this->returnToIdle($idx);
    }
}
