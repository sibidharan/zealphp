<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Per-worker Redis Streams consumer runner.
 *
 * The reliable counterpart to RedisPubSub. Owns one dedicated connection
 * (XREADGROUP can block, doesn't fit the pool model) and one runner
 * coroutine. Loops `XREADGROUP COUNT $batchSize BLOCK $blockMs STREAMS ...`
 * across every registered stream, dispatches each entry via `go()`,
 * `XACK`s the entry if the handler returns true. Non-true returns + any
 * thrown exception leave the entry pending (retried on next reconnect /
 * consumer recovery).
 *
 * The block-timeout (default 1 s) is the natural wake-up cadence — the
 * runner checks the stop flag every block window without needing a
 * sentinel-channel trick like RedisPubSub.
 *
 * Orphan recovery (XAUTOCLAIM): XREADGROUP with `>` only ever delivers
 * NEW entries. An entry that was delivered to a consumer that then
 * crashed/OOMed/was-recycled mid-handler — OR whose handler returned
 * false / threw — is left PENDING and `>` never re-delivers it. Nothing
 * would ever reclaim it, silently breaking the at-least-once promise for
 * the exact failure mode it exists for. The runner therefore performs a
 * periodic reclaim pass: roughly every `$reclaimEverySec` of wall time it
 * XAUTOCLAIM-iterates each consumer's pending list (entries idle longer
 * than `$reclaimMinIdleMs`), re-dispatching each reclaimed entry through
 * the SAME handler + XACK-on-success path as a fresh read. A reclaim
 * failure is caught + backed-off exactly like a read failure; it never
 * kills the runner.
 */
final class RedisStreams
{
    /** Default wall-time cadence (seconds) between orphan-reclaim passes. */
    public const DEFAULT_RECLAIM_EVERY_SEC = 30;

    /** Default minimum idle time (ms) before a pending entry is eligible for reclaim. */
    public const DEFAULT_RECLAIM_MIN_IDLE_MS = 60000;

    /** Max XAUTOCLAIM cursor iterations per reclaim pass (drain-loop safety cap). */
    private const RECLAIM_MAX_ITERATIONS = 10000;

    /** @var list<array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int}> */
    private array $consumers = [];
    private \OpenSwoole\Atomic $running;
    private string $consumerName;

    /** Wall-time cadence (seconds) between orphan-reclaim passes. */
    private int $reclaimEverySec;

    /** Minimum idle time (ms) before a pending entry is reclaimed. */
    private int $reclaimMinIdleMs;

    /** Per-pass XAUTOCLAIM batch size (entries claimed per cursor iteration). */
    private int $reclaimCount;

    /**
     * Small dedicated pool of clients for XACK. Each ACKed message used to
     * spin up a BRAND-NEW connection (connect + close per message), so a
     * busy stream opened a TCP connection per delivery — a connection storm
     * under load. A size-2 pool reuses connections across messages while
     * still giving each dispatch coroutine a private socket (XACK from two
     * cors on one socket would interleave RESP frames). Built lazily in the
     * runner coroutine; closed by stop().
     */
    private ?RedisConnectionPool $ackPool = null;

    /**
     * @param array{prefer?: 'auto'|'phpredis'|'predis'} $opts Driver preference
     *        for the runner's connections. Forced to `['prefer' => 'predis']`
     *        by App::wirePubSubBoot() under the H7 phpredis+HOOK_ALL=0 deadlock
     *        condition (predis blocking reads yield without HOOK_ALL).
     * @param int $reclaimEverySec  Wall-time cadence between orphan-reclaim passes
     *        (default 30s). 0 disables periodic reclaim entirely.
     * @param int $reclaimMinIdleMs Minimum pending idle time before a message is
     *        eligible for reclaim (default 60000ms = 60s) — keeps the reclaim from
     *        racing a still-running handler on a healthy consumer.
     * @param int $reclaimCount     XAUTOCLAIM batch size per cursor step (default 64).
     */
    public function __construct(
        private string $url,
        ?string $consumerName = null,
        private array $opts = [],
        int $reclaimEverySec = self::DEFAULT_RECLAIM_EVERY_SEC,
        int $reclaimMinIdleMs = self::DEFAULT_RECLAIM_MIN_IDLE_MS,
        int $reclaimCount = 64,
    ) {
        $this->running = new \OpenSwoole\Atomic(0);
        $this->consumerName = $consumerName ?? (gethostname() . '-' . getmypid());
        $this->reclaimEverySec  = max(0, $reclaimEverySec);
        $this->reclaimMinIdleMs = max(0, $reclaimMinIdleMs);
        $this->reclaimCount     = max(1, $reclaimCount);
    }

    /**
     * Tune the orphan-reclaim cadence + min-idle without re-constructing.
     * `$everySec = 0` disables periodic reclaim. Returns `$this` for chaining.
     */
    public function reclaimPolicy(int $everySec, int $minIdleMs, ?int $count = null): self
    {
        $this->reclaimEverySec  = max(0, $everySec);
        $this->reclaimMinIdleMs = max(0, $minIdleMs);
        if ($count !== null) {
            $this->reclaimCount = max(1, $count);
        }
        return $this;
    }

    /** Configured wall-time cadence (seconds) between orphan-reclaim passes. */
    public function reclaimEverySec(): int { return $this->reclaimEverySec; }

    /** Configured minimum pending idle (ms) before a message is reclaimed. */
    public function reclaimMinIdleMs(): int { return $this->reclaimMinIdleMs; }

    /**
     * Register a stream + group + handler. Handler signature:
     *   `function (string $payload, string $messageId, string $stream): bool`
     * Return true to XACK (message removed from pending). Return false OR
     * throw to leave pending (retried on consumer recovery).
     */
    public function register(string $stream, string $group, callable $handler, int $blockMs = 1000, int $batchSize = 16): void
    {
        $this->consumers[] = compact('stream', 'group', 'handler', 'blockMs', 'batchSize');
    }

    /** @return list<array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int}> */
    public function consumers(): array { return $this->consumers; }
    public function consumerName(): string { return $this->consumerName; }
    public function isRunning(): bool { return $this->running->get() === 1; }

    /** Spawn the runner coroutine. MUST be called from inside a coroutine context. */
    public function start(): void
    {
        if ($this->running->get() === 1) { return; }
        if ($this->consumers === []) { return; }
        $this->running->set(1);
        go(function (): void { $this->runner(); });
    }

    /** Signal the runner to exit cleanly at the next BLOCK timeout. */
    public function stop(): void
    {
        $this->running->set(0);
        if ($this->ackPool !== null) {
            $this->ackPool->close();
            $this->ackPool = null;
        }
    }

    /**
     * Lazily-built ACK connection pool (size 2). Constructing it is cheap —
     * the pool only connects on first `acquire()`, which happens inside the
     * dispatch coroutine. Reused across messages so a busy stream doesn't
     * connect-per-XACK.
     */
    private function ackPool(): RedisConnectionPool
    {
        return $this->ackPool ??= new RedisConnectionPool($this->url, 2, $this->opts);
    }

    /**
     * Group consumers by (group, blockMs, batchSize) so one XREADGROUP call
     * can fetch from multiple streams sharing the same consumer-group params.
     * Streams under DIFFERENT groups run via separate XREADGROUP calls; the
     * runner just rotates through them.
     */
    private function runner(): void
    {
        $client = null;
        $attempt = 0;
        $lastReclaim = time();
        try {
            while (!self::atomicIsZero($this->running)) {
                try {
                    if ($client === null) {
                        $client = new RedisClient($this->url, $this->opts);
                        $this->ensureGroups($client);
                        $attempt = 0;
                    }
                    // For each registered consumer, do one XREADGROUP pass.
                    // Could be optimised by grouping same-group streams but
                    // groups are typically per-stream in practice.
                    foreach ($this->consumers as $entry) {
                        if (self::atomicIsZero($this->running)) { break; }
                        $r = $client->xreadGroup(
                            $entry['group'], $this->consumerName,
                            [$entry['stream']], $entry['batchSize'], $entry['blockMs'],
                        );
                        if (!isset($r[$entry['stream']])) { continue; }
                        foreach ($r[$entry['stream']] as $msg) {
                            $this->dispatch($entry, $msg);
                        }
                    }
                    // Periodic orphan recovery: reclaim pending entries left
                    // by crashed / recycled / nacking consumers. Gated on the
                    // wall-clock cadence so it doesn't run every loop tick.
                    if ($this->reclaimEverySec > 0 && (time() - $lastReclaim) >= $this->reclaimEverySec) {
                        $this->reclaimAll($client);
                        $lastReclaim = time();
                    }
                } catch (StoreException $e) {
                    if (self::atomicIsZero($this->running)) { return; }
                    if ($client !== null) { $client->close(); $client = null; }
                    $wait = self::backoffSeconds($attempt++);
                    error_log("RedisStreams: runner dropped ({$e->getMessage()}) — backoff {$wait}s");
                    (new \OpenSwoole\Coroutine\Channel(1))->pop($wait);
                }
            }
        } catch (\Throwable $e) {
            error_log("RedisStreams: unexpected runner exception: {$e->getMessage()}");
        } finally {
            if ($client !== null) { $client->close(); }
        }
    }

    /**
     * Run one orphan-reclaim pass across every registered consumer. Each
     * consumer's pending list is XAUTOCLAIM-iterated (cursor walked to '0-0')
     * and every reclaimed entry is re-dispatched through the same handler +
     * XACK-on-success path as a fresh read.
     *
     * A reclaim error on one consumer is logged and skipped — it must NOT
     * abort the whole pass or kill the runner (the StoreException is
     * re-raised to the runner's read-path backoff so the connection is
     * recycled, matching the read failure handling).
     */
    private function reclaimAll(RedisClient $client): void
    {
        $seen = [];
        foreach ($this->consumers as $entry) {
            if (self::atomicIsZero($this->running)) { return; }
            $key = $entry['stream'] . '|' . $entry['group'];
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $this->reclaimPass(
                fn (string $cursor): array => $client->xautoclaim(
                    $entry['stream'], $entry['group'], $this->consumerName,
                    $this->reclaimMinIdleMs, $cursor, $this->reclaimCount,
                ),
                function (array $msg) use ($entry): void { $this->dispatch($entry, $msg); },
            );
        }
    }

    /**
     * Drive the XAUTOCLAIM cursor for ONE consumer until it returns to '0-0'
     * (end of the pending list), dispatching each reclaimed entry. Both the
     * claim mechanism and the per-entry dispatch are injected so the
     * cursor-iteration logic is unit-testable without a live RedisClient
     * (the runner injects `$client->xautoclaim()` + the real `dispatch()`;
     * a test injects a fake page source + a synchronous dispatch recorder).
     *
     * @param callable(string): array{0:string, 1:list<array{id:string, payload:array<string, string>}>} $claim
     * @param callable(array{id:string, payload:array<string, string>}): void                             $dispatch
     */
    private function reclaimPass(callable $claim, callable $dispatch): void
    {
        $cursor = '0-0';
        $iterations = 0;
        do {
            [$next, $entries] = $claim($cursor);
            foreach ($entries as $msg) {
                if (self::atomicIsZero($this->running)) { return; }
                $dispatch($msg);
            }
            $cursor = $next;
            // '0-0' = end of scan. Guard against a pathological non-converging
            // cursor (or an empty page that doesn't advance) so the pass can't
            // spin forever.
            if ($entries === [] && $cursor !== '0-0') { break; }
        } while ($cursor !== '0-0' && ++$iterations < self::RECLAIM_MAX_ITERATIONS
            && !self::atomicIsZero($this->running));
    }

    /**
     * @internal Testing seam — drives a complete reclaim pass for a single
     *           consumer synchronously (no `go()` / no live RedisClient), so
     *           the cursor-iteration + handler-decision + XACK-on-success
     *           logic can be asserted in a plain unit test. NOT for runtime
     *           use; the runner uses `reclaimAll()` which dispatches via
     *           `go()` on the dedicated connection.
     *
     * `$claim(cursor): [nextCursor, entries]` simulates `RedisClient::xautoclaim`.
     * `$ack(stream, group, id): void` records an XACK (only called on a true
     * handler decision). Returns the ordered list of `[id, acked]` decisions.
     *
     * @param array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int} $entry
     * @param callable(string): array{0:string, 1:list<array{id:string, payload:array<string, string>}>} $claim
     * @param callable(string, string, string): void $ack
     * @return list<array{id:string, acked:bool}>
     */
    public function reclaimPassForTest(array $entry, callable $claim, callable $ack): array
    {
        $decisions = [];
        // The reclaim loop honours the stop flag mid-pass; mark running so the
        // synchronous test pass behaves like a live one. Restore after.
        $wasRunning = $this->running->get();
        $this->running->set(1);
        try {
            $this->reclaimPass(
                $claim,
                function (array $msg) use ($entry, $ack, &$decisions): void {
                    $id = $msg['id'];
                    $payload = $msg['payload']['payload'] ?? '';
                    $acked = self::runHandlerDecision(
                        $entry['handler'], $payload, $id, $entry['stream'], $msg['payload'],
                        function () use ($ack, $entry, $id): int {
                            $ack($entry['stream'], $entry['group'], $id);
                            return 1;
                        },
                    );
                    $decisions[] = ['id' => $id, 'acked' => $acked];
                },
            );
        } finally {
            $this->running->set($wasRunning);
        }
        return $decisions;
    }

    private function ensureGroups(RedisClient $client): void
    {
        $seen = [];
        foreach ($this->consumers as $c) {
            $key = $c['stream'] . '|' . $c['group'];
            if (isset($seen[$key])) { continue; }
            $client->xgroupCreate($c['stream'], $c['group'], '$', true);
            $seen[$key] = true;
        }
    }

    /**
     * @param array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int} $entry
     * @param array{id:string, payload:array<string, string>}                                  $msg
     */
    private function dispatch(array $entry, array $msg): void
    {
        $stream = $entry['stream'];
        $group  = $entry['group'];
        $handler = $entry['handler'];
        $id      = $msg['id'];
        // RedisStreams stores the user payload as the 'payload' field; if
        // user XADDs raw multi-field shapes, hand the whole map through.
        $payload = $msg['payload']['payload'] ?? '';
        $payloadFields = $msg['payload'];
        $ackPool = $this->ackPool();

        go(function () use ($ackPool, $stream, $group, $id, $payload, $payloadFields, $handler): void {
            // The XACK runs on a pooled client (size-2 pool) rather than a
            // fresh per-message connection — $pool->with() hands each dispatch
            // coroutine a private socket from the channel (two cors XACKing on
            // one socket would interleave RESP frames) and returns it after,
            // so a busy stream reuses connections instead of connecting per ACK.
            //
            // Read and reclaim paths share this one code path: a reclaimed
            // entry is dispatched through the exact same handler-decision +
            // XACK-on-success flow as a fresh read.
            self::runHandlerDecision(
                $handler, $payload, $id, $stream, $payloadFields,
                fn (): int => $ackPool->with(fn (RedisClient $c): int => $c->xack($stream, $group, $id)),
            );
        });
    }

    /**
     * Pure handler-decision core shared by read + reclaim dispatch. Runs the
     * handler; on a strict `true` return it invokes `$ack` (the XACK action);
     * a non-true return OR a thrown handler leaves the entry pending (no
     * XACK) and is swallowed so the runner survives. Returns the decision
     * (`true` = ACKed) for testability — the caller's `$ack` does the real
     * network XACK on a private pooled socket.
     *
     * @param callable                    $handler the user handler `(payload,id,stream,fields): bool`
     * @param array<string, string>       $fields  full field map of the entry
     * @param callable(): int             $ack     the XACK action (runs only on a true decision)
     */
    private static function runHandlerDecision(
        callable $handler,
        string $payload,
        string $id,
        string $stream,
        array $fields,
        callable $ack,
    ): bool {
        try {
            $ok = $handler($payload, $id, $stream, $fields);
            if ($ok === true) {
                $ack();
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            error_log("RedisStreams handler threw on {$stream}/{$id}: {$e->getMessage()}");
            return false;
        }
    }

    private static function backoffSeconds(int $attempt): float
    {
        return min(0.1 * (2 ** $attempt), 5.0);
    }

    private static function atomicIsZero(\OpenSwoole\Atomic $a): bool
    {
        return $a->get() === 0;
    }
}
