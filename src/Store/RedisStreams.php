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
 */
final class RedisStreams
{
    /** @var list<array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int}> */
    private array $consumers = [];
    private \OpenSwoole\Atomic $running;
    private string $consumerName;

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
     */
    public function __construct(private string $url, ?string $consumerName = null, private array $opts = [])
    {
        $this->running = new \OpenSwoole\Atomic(0);
        $this->consumerName = $consumerName ?? (gethostname() . '-' . getmypid());
    }

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
            try {
                $ok = $handler($payload, $id, $stream, $payloadFields);
                if ($ok === true) {
                    $ackPool->with(fn (RedisClient $c): int => $c->xack($stream, $group, $id));
                }
            } catch (\Throwable $e) {
                error_log("RedisStreams handler threw on {$stream}/{$id}: {$e->getMessage()}");
            }
        });
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
