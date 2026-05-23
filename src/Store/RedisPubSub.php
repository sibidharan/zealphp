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
    /** @var array<string, list<callable>> */
    private array $exactHandlers = [];
    /** @var array<string, list<callable>> */
    private array $patternHandlers = [];
    /** Atomic so cross-coroutine mutation by stop() is visible to the runner. */
    private \OpenSwoole\Atomic $running;
    private string $stopChannel;

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
    }

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

    /** @return list<string> */
    public function exactChannels(): array { return array_keys($this->exactHandlers); }
    /** @return list<string> */
    public function patternChannels(): array { return array_keys($this->patternHandlers); }
    public function stopChannel(): string { return $this->stopChannel; }
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
                $wait = self::backoffSeconds($attempt++);
                error_log("RedisPubSub: subscribe loop dropped ({$e->getMessage()}) — backoff {$wait}s");
                (new \OpenSwoole\Coroutine\Channel(1))->pop($wait);
            } catch (\Throwable $e) {
                error_log("RedisPubSub: unexpected runner exception: {$e->getMessage()}");
                return;
            }
        }
    }

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
                    error_log("RedisPubSub handler threw on {$channel}: {$e->getMessage()}");
                }
            });
        }
    }

    /** Backoff: 0.1, 0.2, 0.4, 0.8, 1.6, 3.2, 5.0 (capped) seconds. */
    private static function backoffSeconds(int $attempt): float
    {
        return min(0.1 * (2 ** $attempt), 5.0);
    }

    /** Indirect read defeats PHPStan flow analysis on the loop condition. */
    private static function atomicIsZero(\OpenSwoole\Atomic $a): bool
    {
        return $a->get() === 0;
    }
}
