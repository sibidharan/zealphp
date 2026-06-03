<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Per-worker pub/sub runner.
 *
 * Owns ONE dedicated Redis connection (separate from the pool — SUBSCRIBE
 * monopolises a connection) and one runner coroutine. Routes inbound
 * messages to registered handlers via `go()` per message so a slow
 * handler can't block the next read.
 *
 * Wake-up + clean shutdown: a private sentinel channel is subscribed
 * alongside the user channels; `stop()` publishes a marker to it via a
 * separate client. The subscribe consumer detects the marker, throws
 * PubSubStopException, the driver catches it, the runner exits.
 *
 * Reconnect: connection drop while subscribed → bounded exponential
 * backoff (capped at 5 s) → re-connect → re-SUBSCRIBE / re-PSUBSCRIBE
 * everything. Messages published during the reconnect window are lost
 * (Redis pub/sub has no buffering) — use `Store::publishReliable` for
 * at-least-once.
 */
final class RedisPubSub
{
    /**
     * Exact-channel handlers keyed by channel name.
     *
     * @var array<string, list<callable>>
     */
    private array $exactHandlers = [];
    /**
     * Pattern handlers keyed by PSUBSCRIBE pattern (contains `*`).
     *
     * @var array<string, list<callable>>
     */
    private array $patternHandlers = [];
    /** Atomic flag (`1` = running, `0` = stopped). Cross-coroutine visibility via `OpenSwoole\Atomic`. */
    private \OpenSwoole\Atomic $running;
    /** Private sentinel channel used by `stop()` to wake the subscriber loop cleanly. */
    private string $stopChannel;
    /** Per-worker counters exposed via `stats()`. */
    private Stats $stats;

    /**
     * @param int $maxAttempts  Bounded reconnect attempts (0 = unlimited, the
     *                          default; preserves pre-H10 behaviour). When set
     *                          to N > 0, the runner gives up after N consecutive
     *                          backoff cycles and logs a final error. Use this
     *                          when "keep trying forever" isn't acceptable —
     *                          e.g. a CI worker that should fail loudly if its
     *                          Redis disappears.
     */
    public function __construct(
        private string $url,
        private string $prefix = 'zealstore',
        private int $maxAttempts = 0,
    ) {
        $this->running = new \OpenSwoole\Atomic(0);
        $this->stopChannel = $this->prefix . ':__pubsub_stop:' . bin2hex(random_bytes(4));
        $this->stats = new Stats();
    }

    /** Per-worker stats — pubsub_reconnects_total, pubsub_messages_received_total, pubsub_handler_errors_total. */
    public function stats(): Stats { return $this->stats; }

    /**
     * Register a handler. Channels containing '*' are PSUBSCRIBE patterns;
     * everything else is SUBSCRIBE exact. Multiple handlers per channel
     * allowed; all fire on each message.
     */
    public function register(string $channelOrPattern, callable $handler): void
    {
        if (str_contains($channelOrPattern, '*')) {
            $this->patternHandlers[$channelOrPattern][] = $handler;
        } else {
            $this->exactHandlers[$channelOrPattern][] = $handler;
        }
    }

    /**
     * Return the exact channel names with registered handlers.
     *
     * @return list<string>
     */
    public function exactChannels(): array { return array_keys($this->exactHandlers); }

    /**
     * Return the PSUBSCRIBE pattern strings with registered handlers.
     *
     * @return list<string>
     */
    public function patternChannels(): array { return array_keys($this->patternHandlers); }

    /** Return the private stop-sentinel channel name (used by `stop()` internally). */
    public function stopChannel(): string { return $this->stopChannel; }

    /** Return `true` when the runner coroutine is active. */
    public function isRunning(): bool { return $this->running->get() === 1; }

    /**
     * Spawn the runner coroutine. Idempotent — re-calling while already
     * running is a no-op. MUST be called from inside a coroutine context.
     */
    public function start(): void
    {
        if ($this->running->get() === 1) { return; }
        if ($this->exactHandlers === [] && $this->patternHandlers === []) { return; }
        $this->running->set(1);
        go(function (): void { $this->runner(); });
    }

    /**
     * Signal the runner to exit cleanly. Publishes a sentinel to the
     * private stop channel via a NEW client (the running subscriber owns
     * its connection, can't publish to itself).
     */
    public function stop(): void
    {
        if ($this->running->get() === 0) { return; }
        $this->running->set(0);
        try {
            $signaller = new RedisClient($this->url);
            $signaller->publish($this->stopChannel, '__stop__');
            $signaller->close();
        } catch (\Throwable) { /* tolerant; runner will see connection drop and exit */ }
    }

    /**
     * Main subscriber loop running inside its own coroutine.
     *
     * Connects a dedicated `RedisClient`, subscribes to all registered exact
     * channels + PSUBSCRIBE patterns + the private `$stopChannel`, then reads
     * messages in a blocking loop. On `PubSubStopException` (sentinel received
     * via `stop()`) the loop exits cleanly. On `StoreException` (connection
     * drop) it applies bounded exponential backoff and reconnects, up to
     * `$maxAttempts` times (0 = unlimited).
     */
    private function runner(): void
    {
        $attempt = 0;
        while ($this->running->get() === 1) {
            try {
                $client = new RedisClient($this->url);
                $exacts = array_merge(array_keys($this->exactHandlers), [$this->stopChannel]);
                $patterns = array_keys($this->patternHandlers);
                $attempt = 0;
                $client->subscribe($exacts, $patterns, function (string $payload, string $channel, ?string $pattern): void {
                    if ($channel === $this->stopChannel) {
                        throw new PubSubStopException();
                    }
                    $this->stats->inc('pubsub_messages_received_total');
                    $this->dispatch($payload, $channel, $pattern);
                });
                // Driver returned cleanly (PubSubStopException caught) — exit.
                $client->close();
                return;
            } catch (StoreException $e) {
                // Indirect read so PHPStan doesn't constant-fold the atomic.
                if (self::atomicIsZero($this->running)) { return; }
                // H10: bounded retries. maxAttempts=0 → loop forever (default).
                if ($this->maxAttempts > 0 && $attempt >= $this->maxAttempts) {
                    error_log("RedisPubSub: giving up after {$this->maxAttempts} attempts ({$e->getMessage()})");
                    $this->running->set(0);
                    return;
                }
                $this->stats->inc('pubsub_reconnects_total');
                $wait = self::backoffSeconds($attempt++);
                error_log("RedisPubSub: subscribe loop dropped ({$e->getMessage()}) — backoff {$wait}s");
                (new \OpenSwoole\Coroutine\Channel(1))->pop($wait);
            } catch (\Throwable $e) {
                error_log("RedisPubSub: unexpected runner exception: {$e->getMessage()}");
                return;
            }
        }
    }

    /**
     * Fan out an inbound message to all matching handlers via `go()`.
     *
     * Each handler runs in its own coroutine so a slow handler cannot block
     * the next message read. Exceptions thrown by handlers are caught,
     * counted in `pubsub_handler_errors_total`, and logged via `error_log()`.
     */
    private function dispatch(string $payload, string $channel, ?string $pattern): void
    {
        $handlers = [];
        if ($pattern !== null && isset($this->patternHandlers[$pattern])) {
            $handlers = $this->patternHandlers[$pattern];
        } elseif ($pattern === null && isset($this->exactHandlers[$channel])) {
            $handlers = $this->exactHandlers[$channel];
        }
        foreach ($handlers as $handler) {
            go(function () use ($handler, $payload, $channel, $pattern): void {
                try { $handler($payload, $channel, $pattern); }
                catch (\Throwable $e) {
                    $this->stats->inc('pubsub_handler_errors_total');
                    error_log("RedisPubSub handler threw on {$channel}: {$e->getMessage()}");
                }
            });
        }
    }

    /**
     * Compute the reconnect wait time for a given attempt number.
     *
     * Uses bounded exponential backoff: `0.1 × 2^attempt` seconds, capped
     * at `5.0` s. Sequence: `0.1, 0.2, 0.4, 0.8, 1.6, 3.2, 5.0, 5.0, …`
     */
    private static function backoffSeconds(int $attempt): float
    {
        return min(0.1 * (2 ** $attempt), 5.0);
    }

    /**
     * Read `$a->get() === 0` via an out-of-line method.
     *
     * An indirect read prevents PHPStan from constant-folding the
     * `while ($this->running->get() === 1)` loop condition when it can
     * prove `running` was set to `1` just above — which would eliminate
     * the loop body entirely under strict flow analysis.
     */
    private static function atomicIsZero(\OpenSwoole\Atomic $a): bool
    {
        return $a->get() === 0;
    }
}
