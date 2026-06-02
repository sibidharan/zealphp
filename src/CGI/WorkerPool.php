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
    /**
     * FD-3 IPC architecture (v0.3.x): the subprocess writes the response BODY
     * to STDOUT freely (no length-prefixed framing → no risk of corruption
     * from user code that calls `flush()` / `fastcgi_finish_request()`) and
     * writes the response METADATA frame (status, headers, cookies) to fd 3.
     *
     * The metadata channel uses a destructor on a static class instance —
     * PHP runs destructors EVEN AFTER `exit()` from inside a shutdown
     * function (phpMyAdmin's `ResponseRenderer->response()` does exactly
     * this). Routing metadata through fd 3 keeps the body channel clean and
     * gives us a guaranteed delivery path for status/headers regardless of
     * how the app terminates.
     *
     * Parent reads the metadata frame from `pipes[3]` first (small,
     * ~256 bytes), then drains body bytes from STDOUT until EOF.
     *
     * Backward compat — older worker entry scripts that still ship the
     * IPC-frame-on-STDOUT protocol fall through to the legacy single-channel
     * read path when no fd 3 frame is received within a short window.
     *
     * @var list<array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int}>
     */
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

    /** `true` after the sync idle queue has been migrated into `$idleChan` on the first coroutine dispatch. */
    private bool $channelPopulated = false;
    /** `true` after `close()` has been called; prevents double-close and guards `dispatch()`. */
    private bool $closed = false;
    /** Total number of worker slots allocated at construction (immutable after `__construct()`). */
    private readonly int $size;

    /**
     * Spawn `$size` worker subprocesses immediately. All workers are ready
     * (READY signal received) before the constructor returns.
     *
     * @param int         $size                   Number of persistent worker subprocesses (pool concurrency).
     * @param int         $maxRequestsPerWorker   Requests served before a worker is recycled (`pm.max_requests` parity).
     * @param string|null $workerEntry            Absolute path to the pool worker entry script;
     *                                            defaults to `src/pool_worker.php`.
     * @throws \InvalidArgumentException When `$size < 1`.
     * @throws \RuntimeException         When a worker subprocess fails to start or doesn't emit `READY` within 10 s.
     */
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

        // FD-3 IPC: when fd 3 is open, it's the CANONICAL completion signal.
        // The subprocess's destructor + shutdown handler writes the metadata
        // frame on fd 3, regardless of whether user code did exit()/die()
        // mid-output. STDOUT carries the body bytes and is read AFTER fd 3
        // tells us `body_length`. Racing fd 3 vs STDOUT readability was a
        // pre-destructor design that broke apps which echo HTML mid-request
        // (phpMyAdmin: HTML hits STDOUT first → parent picks legacy STDOUT
        // path → tries to parse HTML as a JSON frame → "subprocess died").
        //
        // We still keep the legacy STDOUT-frame path for test stubs that
        // don't open fd 3 at all — but only as a fallback when fd 3 EOFs
        // without ever delivering a frame (i.e., the subprocess closed fd 3
        // before writing). A live subprocess always writes fd 3 first.
        $resp = null;
        $bodyFromStdout = null;
        if ($w['fd3'] !== null) {
            $resp = IPC::readFrame($w['fd3'], $timeout);
            if ($resp !== null) {
                $bodyLen = (isset($resp['body_length']) && is_numeric($resp['body_length']))
                    ? (int) $resp['body_length']
                    : 0;
                if ($bodyLen > 0) {
                    $bodyFromStdout = $this->readBody($w['stdout'], $bodyLen, $timeout);
                } else {
                    $bodyFromStdout = '';
                }
            }
        }
        if ($resp === null) {
            // Legacy: metadata + body in one frame on STDOUT.
            // Reached when: (1) fd 3 not opened (old subprocess / test stub),
            // (2) subprocess died before writing fd 3 frame.
            $resp = IPC::readFrame($w['stdout'], $timeout);
        }

        $this->workers[$idx]['served']++;
        $served = $this->workers[$idx]['served'];

        // Respawn if the subprocess died mid-request, hit the recycle
        // limit, the OS reports it's no longer running, or the response
        // carries the _exit flag (the pool worker's shutdown function sent
        // an IPC frame before exit() terminated the process — the worker
        // may still be exiting when isAlive checks, so the flag forces
        // respawn to avoid dispatching to a zombie).
        $err = '';
        if ($resp === null) {
            stream_set_blocking($w['stderr'], false);
            $err = (string) stream_get_contents($w['stderr']);
        }
        $exitFlag = is_array($resp) && !empty($resp['_exit']);

        if ($resp === null || $exitFlag || $served >= $this->maxRequestsPerWorker || !$this->isAlive($w)) {
            $this->respawn($idx);
        } else {
            $this->returnToIdle($idx);
        }

        if ($resp === null) {
            try {
                $alive = $this->isAlive($w);
            } catch (\Throwable) {
                $alive = false;
            }
            $status = $alive ? 504 : 500;
            $msg = $status === 504
                ? 'WorkerPool: subprocess timed out after ' . $timeout . 's'
                : 'WorkerPool: subprocess died mid-request — response not received. Stderr: ' . $err;
            return [
                'status'  => $status,
                'body'    => $msg,
                'headers' => [],
                'cookies' => [],
            ];
        }

        // FD-3 IPC: when the metadata frame came from fd 3, the actual
        // response body lives on STDOUT (not in the frame). Override.
        if ($bodyFromStdout !== null) {
            $resp['body'] = $bodyFromStdout;
        }

        // Decode base64-encoded bodies (binary responses that can't be JSON-encoded directly).
        if (isset($resp['body_encoding']) && $resp['body_encoding'] === 'base64'
            && isset($resp['body']) && is_string($resp['body'])) {
            $resp['body'] = base64_decode($resp['body']);
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

    /**
     * Read exactly $n body bytes from STDOUT. The subprocess wrote
     * `body_length = $n` into the fd 3 metadata frame and immediately
     * after streamed $n bytes to STDOUT. Hard-capped at IPC::MAX_FRAME_BYTES
     * (64 MB) to match the framing channel — anything bigger is a corrupted
     * length signal.
     *
     * @param resource $fp
     * @param int<0, max> $n
     */
    private function readBody($fp, int $n, float $timeout): string
    {
        if ($n <= 0) {
            return '';
        }
        if ($n > IPC::MAX_FRAME_BYTES) {
            // Corrupted or hostile length — refuse silently.
            return '';
        }
        $out = '';
        $deadline = microtime(true) + $timeout;
        while (strlen($out) < $n) {
            $remaining = $n - strlen($out);
            if ($remaining < 1) {
                break;
            }
            $chunk = fread($fp, min($remaining, 65536));
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
                continue;
            }
            if (feof($fp)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(5000);
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
            if ($w['fd3'] !== null) {
                @fclose($w['fd3']);
            }
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
     * @param array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int} $w
     */
    private function isAlive(array $w): bool
    {
        return proc_get_status($w['proc'])['running'] === true;
    }

    /**
     * @return array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int}
     */
    private function spawn(): array
    {
        $entry = $this->workerEntry ?? dirname(__DIR__) . '/pool_worker.php';
        if (!is_file($entry)) {
            throw new \RuntimeException("WorkerPool: worker entry not found: $entry");
        }

        // FD-3 IPC: fd 3 is the metadata channel (status/headers/cookies).
        // STDOUT is the body channel (raw user output). Splitting the two
        // streams means user code that writes to STDOUT (via flush() or
        // fastcgi_finish_request()) can't corrupt the IPC framing, and a
        // metadata frame written from a destructor still gets delivered
        // even when phpMyAdmin-style apps call exit() mid-shutdown.
        $desc = [
            0 => ['pipe', 'r'], // stdin   — parent writes requests
            1 => ['pipe', 'w'], // stdout  — parent reads response BODY stream
            2 => ['pipe', 'w'], // stderr  — diagnostics
            3 => ['pipe', 'w'], // fd 3    — IPC metadata frame channel
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
        // dispatch the first frame. Read lines in a loop to skip any
        // deprecation warnings that PHP/OpenSwoole emit before READY (#133).
        $bootLines = '';
        $foundReady = false;
        $deadline = microtime(true) + 10.0; // 10s timeout
        while (microtime(true) < $deadline) {
            $line = fgets($pipes[2]);
            if ($line === false) {
                if (feof($pipes[2])) break;
                usleep(10000);
                continue;
            }
            if (str_contains($line, 'READY')) {
                $foundReady = true;
                break;
            }
            $bootLines .= $line;
        }
        if (!$foundReady) {
            proc_terminate($proc);
            @proc_close($proc);
            throw new \RuntimeException("WorkerPool: subprocess boot failed (no READY within 10s): " . trim($bootLines));
        }

        $pid = proc_get_status($proc)['pid'];

        // fd 3 is optional — if proc_open didn't open it (very old PHP /
        // mocked test stubs that override descriptor setup), fall back to
        // the legacy single-channel protocol on STDOUT.
        $fd3 = isset($pipes[3]) && is_resource($pipes[3]) ? $pipes[3] : null;

        return [
            'proc'   => $proc,
            'stdin'  => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'fd3'    => $fd3,
            'pid'    => $pid,
            'served' => 0,
        ];
    }

    /**
     * Close all pipes for the worker at `$idx`, reap the subprocess via
     * `proc_close()`, spawn a fresh replacement, and immediately return it
     * to the idle pool. Called when a worker dies mid-request, hits its
     * `$maxRequestsPerWorker` limit, or sends the `_exit` flag in its IPC frame.
     */
    private function respawn(int $idx): void
    {
        $old = $this->workers[$idx];
        @fclose($old['stdin']);
        @fclose($old['stdout']);
        @fclose($old['stderr']);
        if ($old['fd3'] !== null) {
            @fclose($old['fd3']);
        }
        @proc_close($old['proc']);

        $this->workers[$idx] = $this->spawn();
        $this->returnToIdle($idx);
    }
}
