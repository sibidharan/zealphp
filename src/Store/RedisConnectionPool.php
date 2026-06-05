<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Per-worker pool of `RedisClient` connections.
 *
 * Two coroutines sharing one phpredis/predis socket interleave RESP frames
 * and corrupt the stream — so each op must acquire a private client from
 * this pool, use it, and release it back. The pool is sized N (default `8`)
 * per worker; the `OpenSwoole\Coroutine\Channel` blocks until a client is available.
 *
 * Outside a coroutine context (sync mode, e.g. `superglobals(true)`), the
 * pool degrades to a single lazily-built client used sequentially — the
 * channel `pop()` would block the worker indefinitely otherwise.
 */
final class RedisConnectionPool
{
    /**
     * The coroutine channel holding available `RedisClient` instances.
     *
     * Created lazily on the first `acquire()` inside a coroutine context
     * because `Channel::push()` throws outside the scheduler.
     */
    private ?Channel $ch = null;

    /** Configured pool capacity (minimum `1`). */
    private int $size;

    /** Single `RedisClient` used in sync (non-coroutine) mode. */
    private ?RedisClient $syncClient = null;

    /**
     * Set once `close()` has run. Distinguishes "torn down" from "never
     * built" (both leave `$ch === null`), so a post-`close()` `acquire()`
     * throws instead of silently rebuilding a fresh N-client pool — which
     * would leak the sockets the rebuilt pool opens (#252).
     */
    private bool $closed = false;

    /** Per-worker counters (`pool_acquires_total`, `pool_acquire_timeouts_total`, `pool_clients_created_total`). */
    private Stats $stats;

    /**
     * @param string                                       $url  Redis connection URL (e.g. `redis://127.0.0.1:6379`).
     * @param int                                          $size Maximum pool size per worker (default `8`).
     * @param array{prefer?: 'auto'|'phpredis'|'predis'}  $opts Driver preference options.
     */
    public function __construct(
        private string $url,
        int $size = 8,
        private array $opts = [],
    ) {
        $this->size = max(1, $size);
        $this->stats = new Stats();
    }

    /** Per-worker stats — pool_acquires_total, pool_acquire_timeouts_total, pool_clients_created_total. */
    public function stats(): Stats { return $this->stats; }

    /**
     * Pop a client from the pool. In coroutine context, yields up to
     * $timeout seconds for one to become available; throws StoreException
     * on timeout. In sync context, returns a shared single client.
     */
    public function acquire(float $timeout = 5.0): RedisClient
    {
        if ($this->closed) {
            throw new StoreException('RedisConnectionPool: acquire() after close() — the pool is torn down');
        }
        if (Coroutine::getCid() < 0) {
            if ($this->syncClient === null) {
                $this->syncClient = new RedisClient($this->url, $this->opts);
                $this->stats->inc('pool_clients_created_total');
            }
            $this->stats->inc('pool_acquires_total');
            return $this->syncClient;
        }
        $ch = $this->ensureChannel();
        $c = $ch->pop($timeout);
        if (!$c instanceof RedisClient) {
            $this->stats->inc('pool_acquire_timeouts_total');
            throw new StoreException(
                "RedisConnectionPool: acquire timed out after {$timeout}s (size={$this->size})"
            );
        }
        $this->stats->inc('pool_acquires_total');
        return $c;
    }

    /**
     * Return a client to the pool. No-op when the released client is the
     * sync-mode singleton (it lives outside the channel).
     */
    public function release(RedisClient $client): void
    {
        if ($this->syncClient !== null && $client === $this->syncClient) { return; }
        if ($this->ch === null) { return; }
        $this->ch->push($client);
    }

    /**
     * Acquire + use + release in one call. Exception-safe via try/finally;
     * the client always goes back into the pool even when $fn throws.
     *
     * @template T
     * @param  callable(RedisClient): T $fn
     * @return T
     */
    public function with(callable $fn): mixed
    {
        $c = $this->acquire();
        try {
            $result = $fn($c);
        } catch (\Throwable $e) {
            // The op threw — likely a connection/protocol error, so the client may
            // have a dead socket or a half-consumed RESP frame. Returning it to the
            // pool would poison every future borrower (the pre-existing bug: a dead
            // connection was re-pooled and reused forever). Discard it and refill
            // the pool with a fresh client so capacity is preserved, then re-throw.
            $this->discard($c);
            throw $e;
        }
        $this->release($c);
        return $result;
    }

    /**
     * Drop a (possibly broken) client instead of returning it to the pool, and
     * refill the pool with a fresh connection so capacity is preserved. The
     * sync-mode singleton is just nulled so acquire() lazily rebuilds it.
     */
    private function discard(RedisClient $client): void
    {
        try { $client->close(); } catch (\Throwable) { /* already dead */ }
        if ($this->syncClient !== null && $client === $this->syncClient) {
            $this->syncClient = null;
            return;
        }
        if ($this->ch === null) { return; }
        try {
            $this->ch->push(new RedisClient($this->url, $this->opts));
            $this->stats->inc('pool_clients_created_total');
        } catch (\Throwable) {
            // Couldn't reconnect right now; the pool runs one short until a future
            // acquire times out and a fresh client is built — still better than
            // pushing a known-dead client back.
        }
    }

    /** Return the configured pool capacity. */
    public function size(): int { return $this->size; }

    /** Return the Redis connection URL this pool connects to. */
    public function url(): string { return $this->url; }

    /**
     * Tear down every connection. The channel is drained with a tiny
     * timeout so workers that haven't released their clients yet don't
     * block shutdown.
     */
    public function close(): void
    {
        $this->closed = true;
        if ($this->syncClient !== null) {
            $this->syncClient->close();
            $this->syncClient = null;
        }
        if ($this->ch === null) { return; }
        $ch   = $this->ch;
        $size = $this->size;
        $drain = function () use ($ch, $size): void {
            for ($i = 0; $i < $size; $i++) {
                $c = $ch->pop(0.01);
                if ($c instanceof RedisClient) { $c->close(); }
            }
        };
        if (Coroutine::getCid() >= 0) {
            $drain();
        } else {
            // Channel::pop must run inside a coroutine; create one to drain.
            Coroutine::run($drain);
        }
        $this->ch = null;
    }

    /**
     * Lazily initialise the `Channel` and pre-fill it with `$size` clients.
     *
     * IMPORTANT: must be called inside a coroutine — `Channel::push()` throws
     * outside the scheduler.
     */
    private function ensureChannel(): Channel
    {
        if ($this->closed) {
            throw new StoreException('RedisConnectionPool: cannot build channel after close() — the pool is torn down');
        }
        if ($this->ch !== null) { return $this->ch; }
        // Must be called inside a coroutine — Channel::push throws otherwise.
        $ch = new Channel($this->size);
        for ($i = 0; $i < $this->size; $i++) {
            $ch->push(new RedisClient($this->url, $this->opts));
            $this->stats->inc('pool_clients_created_total');
        }
        $this->ch = $ch;
        return $ch;
    }
}
