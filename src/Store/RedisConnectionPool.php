<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Per-worker pool of RedisClient connections.
 *
 * Two coroutines sharing one phpredis/predis socket interleave RESP frames
 * and corrupt the stream — so each op must acquire a private client from
 * this pool, use it, and release it back. The pool is sized N (default 8)
 * per worker; the OpenSwoole channel blocks until a client is available.
 *
 * Outside a coroutine context (sync mode, e.g. superglobals(true)), the
 * pool degrades to a single lazily-built client used sequentially — the
 * channel pop would block the worker indefinitely otherwise.
 */
final class RedisConnectionPool
{
    /** Channel + its pre-fill are created lazily inside a coroutine — Channel::push throws otherwise. */
    private ?Channel $ch = null;
    private int $size;
    private ?RedisClient $syncClient = null;

    /** @param array{prefer?: 'auto'|'phpredis'|'predis'} $opts */
    public function __construct(
        private string $url,
        int $size = 8,
        private array $opts = [],
    ) {
        $this->size = max(1, $size);
    }

    /**
     * Pop a client from the pool. In coroutine context, yields up to
     * $timeout seconds for one to become available; throws StoreException
     * on timeout. In sync context, returns a shared single client.
     */
    public function acquire(float $timeout = 5.0): RedisClient
    {
        if (Coroutine::getCid() < 0) {
            return $this->syncClient ??= new RedisClient($this->url, $this->opts);
        }
        $ch = $this->ensureChannel();
        $c = $ch->pop($timeout);
        if (!$c instanceof RedisClient) {
            throw new StoreException(
                "RedisConnectionPool: acquire timed out after {$timeout}s (size={$this->size})"
            );
        }
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
        try { return $fn($c); }
        finally { $this->release($c); }
    }

    public function size(): int { return $this->size; }
    public function url(): string { return $this->url; }

    /**
     * Tear down every connection. The channel is drained with a tiny
     * timeout so workers that haven't released their clients yet don't
     * block shutdown.
     */
    public function close(): void
    {
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

    private function ensureChannel(): Channel
    {
        if ($this->ch !== null) { return $this->ch; }
        // Must be called inside a coroutine — Channel::push throws otherwise.
        $ch = new Channel($this->size);
        for ($i = 0; $i < $this->size; $i++) {
            $ch->push(new RedisClient($this->url, $this->opts));
        }
        $this->ch = $ch;
        return $ch;
    }
}
