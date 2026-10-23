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
     * FD-3 IPC architecture: the subprocess writes the response metadata
     * frame (status, headers, cookies, `body_length`, `body_fd` = 3) to fd 3
     * and the body bytes right after it on fd 3. App code cannot reach fd 3
     * by accident, so nothing it does can shift the framing.
     *
     * The metadata channel uses a destructor on a static class instance —
     * PHP runs destructors EVEN AFTER `exit()` from inside a shutdown
     * function (phpMyAdmin's `ResponseRenderer->response()` does exactly
     * this). Routing metadata through fd 3 gives us a guaranteed delivery
     * path for status/headers regardless of how the app terminates.
     *
     * STDOUT is NOT the body. It used to be (only the length was framed), and
     * any direct write to fd 1 (`fwrite(STDOUT)`, a php://stdout logger, a
     * destructor echo after output buffering closed) then leaked into the
     * NEXT request's response on that subprocess. STDOUT and STDERR are now
     * drained on every dispatch and logged, never returned to a client.
     *
     * Backward compat — entry scripts that put the body on STDOUT after an
     * fd 3 frame without `body_fd`, or the whole frame on STDOUT with no fd 3
     * frame at all, are still read (see exchange()).
     *
     * @var list<array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int, proto: int}>
     */
    private array $workers = [];

    /** Most stray stdout / stderr kept per dispatch for the log; the rest is read and dropped. */
    private const DIAG_CAP_BYTES = 1024 * 1024;

    /** Bytes per request-pipe write inside a coroutine (POSIX PIPE_BUF on Linux); see exchange(). */
    private const PIPE_BUF = 4096;

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

        // C1 — a fresh token per request. A proto=2 subprocess echoes it in
        // its response frame, so a frame some script wrote to fd 3 (it cannot
        // know the token) is never taken for this request's response.
        $token = bin2hex(random_bytes(8));
        $request['_zp_token'] = $token;
        $frame = IPC::encodeFrame($request);

        // C4 — a frame over the IPC cap would be dropped by the subprocess
        // (which then exits); refuse it here, before it costs a subprocess.
        if (strlen($frame) - 4 > IPC::MAX_FRAME_BYTES) {
            return [
                'status'  => 413,
                'body'    => 'WorkerPool: request exceeds the ' . IPC::MAX_FRAME_BYTES . '-byte IPC frame cap',
                'headers' => [],
                'cookies' => [],
            ];
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

        // C1 — anything already waiting on fd 3 was written after the previous
        // response (a destructor during the reset, say) and belongs to no
        // request. Reading it as this request's response would hand this user
        // whatever that was. Replace the subprocess and serve on a clean one.
        if (self::hasUnsolicitedBytes($this->workers[$idx])) {
            self::logProtocolError($this->workers[$idx]['pid'], 'bytes on fd 3 between requests; subprocess replaced');
            $this->replace($idx);
        }

        $w = $this->workers[$idx];

        // One select loop does the whole exchange: it writes the request frame,
        // reads the response off fd 3, and drains STDOUT and STDERR the whole
        // time. Draining matters both ways. A subprocess whose stderr (warnings)
        // or stdout fills its 64 KB pipe blocks inside fwrite() and never
        // answers, so a blocking read on fd 3 alone waits forever (M9). And
        // bytes written straight to fd 1 by app code (fwrite(STDOUT),
        // php://stdout loggers, a destructor echo after the output buffer is
        // closed) must never be counted as response body: when the body rode
        // STDOUT with only its length framed, a stray write shifted bytes into
        // the NEXT request's response, i.e. into another user's page (H2).
        // The pool worker now sends the body on fd 3 after the frame
        // (`body_fd` = 3); STDOUT is diagnostics only and goes to the log, as
        // FPM's catch_workers_output does.
        $io = $this->exchange($w, $frame, $timeout, $w['proto'] >= 2 ? $token : null);
        $resp = $io['resp'];
        if ($io['stdout'] !== '') {
            self::logSubprocessOutput('stdout', $w['pid'], $io['stdout']);
        }
        if ($io['stderr'] !== '') {
            self::logSubprocessOutput('stderr', $w['pid'], $io['stderr']);
        }

        // C1 / C4 — the exchange did not add up: a frame without our token,
        // bytes left over after the response, an over-cap body_length, or a
        // corrupt frame. Whatever is still in the pipes would become the next
        // user's response, so this subprocess is killed and replaced, and this
        // request gets a 502.
        if ($io['error'] !== null) {
            self::logProtocolError($w['pid'], $io['error'] . '; subprocess replaced');
            $this->replace($idx);
            $this->returnToIdle($idx);
            return [
                'status'  => 502,
                'body'    => 'WorkerPool: protocol error from subprocess (' . $io['error'] . ')',
                'headers' => [],
                'cookies' => [],
            ];
        }

        $this->workers[$idx]['served']++;
        $served = $this->workers[$idx]['served'];

        // Respawn if the subprocess died mid-request, hit the recycle
        // limit, the OS reports it's no longer running, or the response
        // carries the _exit flag (the pool worker's shutdown function sent
        // an IPC frame before exit() terminated the process — the worker
        // may still be exiting when isAlive checks, so the flag forces
        // respawn to avoid dispatching to a zombie). A timed-out exchange
        // also respawns: the subprocess may be mid-write, and the next
        // request must not read the tail of this one.
        $err = $resp === null ? $io['stderr'] : '';
        $exitFlag = is_array($resp) && !empty($resp['_exit']);

        if ($resp === null || $exitFlag || $served >= $this->maxRequestsPerWorker || !$this->isAlive($w)) {
            $alive = false;
            if ($resp === null && !$io['eof']) {
                // Every channel at EOF means the subprocess exited, even if
                // the OS has not reaped it yet; only a deadline miss is a 504.
                try {
                    $alive = $this->isAlive($w);
                } catch (\Throwable) {
                    $alive = false;
                }
                if ($alive) {
                    // Wedged, not dead: proc_close() would wait on it forever.
                    @proc_terminate($w['proc'], 9);
                }
            }
            $this->respawn($idx);
        } else {
            $alive = true;
            $this->returnToIdle($idx);
        }

        if ($resp === null) {
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
     * Run one request/response exchange with a subprocess over non-blocking
     * pipes: write `$frame` to stdin, read the response from fd 3, and drain
     * stdout + stderr throughout so the child can never block on a full pipe.
     * The request write happens inside the same select loop, under the same
     * deadline, in a coroutine too (C3): a child that is not reading its stdin
     * costs one timed-out request, never a slot held forever.
     *
     * Response shapes accepted, in order of preference:
     *  - fd 3 frame with `body_fd` = 3: the body follows the frame on fd 3
     *    (`body_length` bytes). Everything on STDOUT is stray output.
     *  - fd 3 frame without `body_fd` (older entry scripts / test stubs): the
     *    body is the first `body_length` bytes on STDOUT.
     *  - no fd 3, or fd 3 closed without a frame: a single legacy frame
     *    (metadata + body) on STDOUT.
     *
     * Integrity (C1, C4): with a `$token` (proto=2 subprocess) the frame must
     * carry it. Bytes left on the response channel after the response (fd 3,
     * or STDOUT when STDOUT carried the body), a `body_length` over the cap,
     * or a complete-but-undecodable frame set `error`; the caller then kills
     * the subprocess, because those bytes would otherwise be read as the next
     * request's response.
     *
     * Returns `resp` = null on timeout or when every channel hit EOF before a
     * full response arrived (`eof` = true for the latter). `stdout` holds the STDOUT bytes not used as body,
     * `stderr` everything read from stderr (each capped at
     * self::DIAG_CAP_BYTES; the rest is read and discarded).
     *
     * @param array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int, proto: int} $w
     * @return array{resp: array<mixed,mixed>|null, eof: bool, stdout: string, stderr: string, error: string|null}
     */
    private function exchange(array $w, string $frame, float $timeout, ?string $token = null): array
    {
        $deadline = microtime(true) + $timeout;
        /** @var array<int, resource> $open */
        $open = [1 => $w['stdout'], 2 => $w['stderr']];
        if ($w['fd3'] !== null) {
            $open[3] = $w['fd3'];
        }
        $buf = [1 => '', 2 => '', 3 => ''];
        $pending = $frame;
        $stdin = $w['stdin'];
        $resp = null;
        $error = null;
        $stdoutBody = false; // true when the body is taken from the STDOUT buffer
        // Under HOOK_ALL a non-blocking fwrite() that hits a full pipe fails
        // and breaks the hooked stream instead of returning a short count. So
        // in a coroutine, write only once select reports the pipe writable and
        // at most PIPE_BUF bytes at a time: a pipe that is writable has room
        // for that much, so the write never meets a full pipe.
        $chunk = Coroutine::getCid() > 0 ? self::PIPE_BUF : \PHP_INT_MAX;

        while (true) {
            // Parse whatever has arrived so far.
            if ($buf[3] !== '') {
                $meta = self::parseFrame($buf[3]);
                if ($meta === false) {
                    $error = 'corrupt frame on fd 3';
                    break;
                }
                if ($meta !== null) {
                    [$frameData, $used] = $meta;
                    $len = (isset($frameData['body_length']) && is_numeric($frameData['body_length']))
                        ? max(0, (int) $frameData['body_length'])
                        : 0;
                    if ($len > IPC::MAX_FRAME_BYTES) {
                        // C4: a protocol error, not a body to zero and skip —
                        // its bytes would stay in the pipe for the next request.
                        $error = 'body_length ' . $len . ' exceeds the ' . IPC::MAX_FRAME_BYTES . '-byte cap';
                        break;
                    }
                    if (($frameData['body_fd'] ?? null) === 3) {
                        if (strlen($buf[3]) - $used >= $len) {
                            $frameData['body'] = (string) substr($buf[3], $used, $len);
                            $buf[3] = (string) substr($buf[3], $used + $len);
                            $resp = $frameData;
                            break;
                        }
                    } elseif (strlen($buf[1]) >= $len || !isset($open[1])) {
                        $frameData['body'] = (string) substr($buf[1], 0, $len);
                        $buf[1] = (string) substr($buf[1], $len);
                        $buf[3] = (string) substr($buf[3], $used);
                        $stdoutBody = true;
                        $resp = $frameData;
                        break;
                    }
                }
            }
            if ($w['fd3'] === null || (!isset($open[3]) && self::parseFrame($buf[3]) === null)) {
                // Legacy single-channel frame on STDOUT.
                $legacy = self::parseFrame($buf[1]);
                if (is_array($legacy)) {
                    $resp = $legacy[0];
                    $buf[1] = (string) substr($buf[1], $legacy[1]);
                    $stdoutBody = true;
                    break;
                }
            }
            $readers = array_values($open);
            if ($readers === [] || microtime(true) >= $deadline) {
                break;
            }

            $writers = $pending !== '' ? [$stdin] : [];
            $except = null;
            // At least 1 ms: under HOOK_ALL a zero timeout is rejected by the
            // coroutine timer ("msec value[0] is invalid") and the select then
            // waits for the next event instead, which can be past the deadline.
            $wait = max(0.001, min(1.0, $deadline - microtime(true)));
            $sec = (int) $wait;
            $sel = @stream_select($readers, $writers, $except, $sec, (int) (($wait - $sec) * 1_000_000));
            if ($sel === false) {
                usleep(1000); // EINTR (e.g. SIGCHLD) — retry
                continue;
            }
            if ($writers !== []) { // only selected while a request is pending
                $n = @fwrite($stdin, $chunk >= strlen($pending) ? $pending : substr($pending, 0, $chunk));
                if ($n === false) {
                    $pending = ''; // broken pipe: the reads below see the EOFs
                } elseif ($n > 0) {
                    $pending = (string) substr($pending, $n);
                }
            }
            foreach ($readers as $stream) {
                $fd = (int) array_search($stream, $open, true);
                $data = @fread($stream, 65536);
                if ($data === false || $data === '') {
                    if (feof($stream)) {
                        unset($open[$fd]); // EOF on this channel — stop selecting it
                    }
                    continue;
                }
                // fd 3 carries frame + body, each bounded by the frame cap.
                // stdout is bounded by the frame cap too (the legacy protocols
                // put the body there); stderr is diagnostics only.
                $cap = match ($fd) {
                    3       => 2 * IPC::MAX_FRAME_BYTES + 4,
                    1       => IPC::MAX_FRAME_BYTES + 4,
                    default => self::DIAG_CAP_BYTES,
                };
                if (strlen($buf[$fd]) < $cap) {
                    $buf[$fd] .= $data;
                }
            }
        }

        if ($resp !== null) {
            // The response is in; pick up anything already sitting in the
            // pipes without waiting for more.
            foreach ([1, 2, 3] as $fd) {
                if (!isset($open[$fd])) {
                    continue;
                }
                $data = @fread($open[$fd], 65536);
                if (is_string($data) && $data !== '' && strlen($buf[$fd]) < self::DIAG_CAP_BYTES) {
                    $buf[$fd] .= $data;
                }
            }
            if ($token !== null && ($resp['_zp_token'] ?? null) !== $token) {
                $error = 'response frame without this request\'s token (a script wrote to fd 3?)';
            } elseif ($buf[3] !== '') {
                $error = strlen($buf[3]) . ' unread bytes on fd 3 after the response';
            } elseif ($stdoutBody && $buf[1] !== '') {
                $error = strlen($buf[1]) . ' unread bytes on stdout after the response';
            }
            unset($resp['_zp_token']);
        }
        if ($error !== null) {
            $resp = null;
        }

        $stray = $buf[1];
        if ($stdoutBody) {
            $stray = ''; // reported through `error` above, never as output
        } elseif (strlen($stray) > self::DIAG_CAP_BYTES) {
            $stray = substr($stray, 0, self::DIAG_CAP_BYTES);
        }

        return ['resp' => $resp, 'eof' => $open === [], 'stdout' => $stray, 'stderr' => $buf[2], 'error' => $error];
    }

    /**
     * Decode one complete length-prefixed frame from the front of `$buf`.
     *
     * @return array{0: array<mixed,mixed>, 1: int}|false|null The frame and the
     *         bytes it used; null when `$buf` does not yet hold a complete frame;
     *         false when it never will (a length outside 1..MAX_FRAME_BYTES, or a
     *         complete frame that is not a JSON object).
     */
    private static function parseFrame(string $buf): array|false|null
    {
        if (strlen($buf) < 4) {
            return null;
        }
        /** @var array{1:int} $u */
        $u = unpack('N', substr($buf, 0, 4));
        $len = $u[1];
        if ($len <= 0 || $len > IPC::MAX_FRAME_BYTES) {
            return false;
        }
        if (strlen($buf) < 4 + $len) {
            return null;
        }
        $decoded = json_decode(substr($buf, 4, $len), true);
        return is_array($decoded) ? [$decoded, 4 + $len] : false;
    }

    /**
     * True when the subprocess has written to fd 3 while no request was in
     * flight. Never blocks: fd 3 is non-blocking.
     *
     * @param array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int, proto: int} $w
     */
    private static function hasUnsolicitedBytes(array $w): bool
    {
        if ($w['fd3'] === null) {
            return false;
        }
        $data = @fread($w['fd3'], 65536);
        return is_string($data) && $data !== '';
    }

    /** Log a broken exchange; the caller replaces the subprocess. */
    private static function logProtocolError(int $pid, string $what): void
    {
        if (function_exists('ZealPHP\elog')) {
            \ZealPHP\elog("[cgi_pool] subprocess {$pid}: {$what}", 'error');
        }
    }

    /**
     * Send a subprocess's stray stdout / stderr to the framework log. Never to
     * the client: under FPM and mod_php, php://stdout is not the response.
     */
    private static function logSubprocessOutput(string $channel, int $pid, string $bytes): void
    {
        if (!function_exists('ZealPHP\elog')) {
            return;
        }
        $text = rtrim($bytes);
        if ($text === '') {
            return;
        }
        if (strlen($text) > 8192) {
            $text = substr($text, 0, 8192) . ' … [' . (strlen($text) - 8192) . ' more bytes]';
        }
        \ZealPHP\elog("[cgi_pool] subprocess {$pid} {$channel}: " . $text, 'cgi_pool');
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
     * @param array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int, proto: int} $w
     */
    private function isAlive(array $w): bool
    {
        return proc_get_status($w['proc'])['running'] === true;
    }

    /**
     * Build the environment handed to a pool subprocess.
     *
     * Previously this was `array_merge(getenv(), …)` — every parent env var
     * (DB passwords, `ZEALPHP_TIERED_INVALIDATION_SECRET`, `AWS_*`, k8s/systemd
     * secrets) was inherited by the long-lived subprocess running arbitrary
     * legacy app code, and — unlike the proc/fork CGI path (`Dispatcher::buildCgiEnv`)
     * — the request-controlled `HTTP_PROXY` was forwarded (httpoxy, CVE-2016-5385).
     *
     * Default (empty `$allowlist`): pass the parent env through for legacy-app
     * compatibility but ALWAYS drop `HTTP_PROXY`. Set `App::cgiPoolEnvAllowlist()`
     * to a list of exact names / `PREFIX*` globs to harden to a strict allowlist
     * (the pool IPC var is always included). Per-request CGI vars travel over the
     * fd-3 IPC frame, not this spawn env, so a strict allowlist does not lose them.
     *
     * @param array<string,string> $parentEnv  Usually `getenv()`.
     * @param list<string>         $allowlist  Exact names or `PREFIX*` globs; empty = pass-through.
     * @return array<string,string>
     */
    public static function filterSubprocessEnv(array $parentEnv, array $allowlist, int $maxRequests): array
    {
        $out = [];
        foreach ($parentEnv as $name => $value) {
            // httpoxy: a request `Proxy:` header lands as HTTP_PROXY and would
            // hijack the subprocess's outbound HTTP. Never forward it.
            if (\strcasecmp($name, 'HTTP_PROXY') === 0) {
                continue;
            }
            if ($allowlist === []) {
                $out[$name] = (string) $value;
                continue;
            }
            foreach ($allowlist as $rule) {
                $match = \str_ends_with($rule, '*')
                    ? \str_starts_with($name, \substr($rule, 0, -1))
                    : $name === $rule;
                if ($match) {
                    $out[$name] = (string) $value;
                    break;
                }
            }
        }
        $out['ZEALPHP_POOL_MAX_REQUESTS'] = (string) $maxRequests;
        return $out;
    }

    /**
     * @return array{proc: resource, stdin: resource, stdout: resource, stderr: resource, fd3: resource|null, pid: int, served: int, proto: int}
     */
    private function spawn(): array
    {
        $entry = $this->workerEntry ?? dirname(__DIR__) . '/pool_worker.php';
        if (!is_file($entry)) {
            throw new \RuntimeException("WorkerPool: worker entry not found: $entry");
        }

        // FD-3 IPC: fd 3 carries the response (metadata frame + body). STDOUT
        // and STDERR are diagnostics, drained and logged by exchange(), so
        // user code that writes to STDOUT can't corrupt the IPC framing, and
        // a frame written from a destructor still gets delivered even when
        // phpMyAdmin-style apps call exit() mid-shutdown.
        $desc = [
            0 => ['pipe', 'r'], // stdin   — parent writes requests
            1 => ['pipe', 'w'], // stdout  — stray app output (logged)
            2 => ['pipe', 'w'], // stderr  — diagnostics (logged)
            3 => ['pipe', 'w'], // fd 3    — IPC response channel
        ];
        $pipes = [];
        /** @var array<string,string> $parentEnv */
        $parentEnv = getenv();
        $env = self::filterSubprocessEnv(
            $parentEnv,
            \ZealPHP\App::$cgi_pool_env_allowlist,
            $this->maxRequestsPerWorker
        );
        $proc = proc_open([\PHP_BINARY, '-d', 'display_errors=stderr', '-d', \ZealPHP\App::subprocessStrictModeIni(), $entry], $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('WorkerPool: proc_open failed for ' . $entry);
        }

        // Bounded wait for READY signal on stderr — guarantees the
        // subprocess has loaded its autoloader + uopz overrides before we
        // dispatch the first frame. Read lines in a loop to skip any
        // deprecation warnings that PHP/OpenSwoole emit before READY (#133).
        $bootLines = '';
        $foundReady = false;
        $proto = 1;
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
                // `proto=2` (src/pool_worker.php): response frames echo the
                // per-request token. Custom entries that predate it get none.
                $proto = preg_match('/\bproto=(\d+)/', $line, $m) === 1 ? (int) $m[1] : 1;
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

        // Every channel is non-blocking from here on: exchange() multiplexes
        // them with stream_select so no single full pipe can wedge either side.
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if ($fd3 !== null) {
            stream_set_blocking($fd3, false);
        }

        return [
            'proc'   => $proc,
            'stdin'  => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'fd3'    => $fd3,
            'pid'    => $pid,
            'served' => 0,
            'proto'  => $proto,
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
        $this->reap($idx);
        $this->workers[$idx] = $this->spawn();
        $this->returnToIdle($idx);
    }

    /**
     * Kill the subprocess at `$idx` (it is not trusted to exit by itself, and
     * whatever it still writes must not reach anyone) and put a fresh one in
     * its slot. Unlike respawn(), the slot is NOT returned to the idle pool:
     * the caller is still holding it.
     */
    private function replace(int $idx): void
    {
        $old = $this->workers[$idx];
        try {
            if ($this->isAlive($old)) {
                @proc_terminate($old['proc'], 9);
            }
        } catch (\Throwable) {
            // already gone
        }
        $this->reap($idx);
        $this->workers[$idx] = $this->spawn();
    }

    /** Close every pipe of the subprocess at `$idx` and wait for it to exit. */
    private function reap(int $idx): void
    {
        $old = $this->workers[$idx];
        @fclose($old['stdin']);
        @fclose($old['stdout']);
        @fclose($old['stderr']);
        if ($old['fd3'] !== null) {
            @fclose($old['fd3']);
        }
        @proc_close($old['proc']);
    }
}
