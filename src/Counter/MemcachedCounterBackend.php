<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

use ZealPHP\Store\StoreException;

/**
 * Memcached-backed `CounterBackend`.
 *
 * Memcached has native atomic `increment` / `decrement` that operate
 * server-side on integer values — cross-node atomic without the round-
 * trip cost of Redis Lua. Compare-and-swap uses the `gets` + `cas` pair.
 *
 * Trade-offs vs. Redis:
 *   - No TTL on counter keys via this API (Memcached `increment` won't
 *     create a key; we initialize lazily via `add` which DOES respect TTL —
 *     but only on first creation). `expire()` is a no-op here.
 *   - No native batch increment (mincr loops sequentially).
 *   - No native bounded-increment (Lua-on-Redis is the only way to get
 *     atomic check-then-increment); incrBounded is implemented as a CAS
 *     retry loop, bounded at 100 attempts under contention.
 *
 * Use case: cross-server counters where Memcached is the established
 * shared-cache infrastructure and a Redis dependency is undesired.
 */
final class MemcachedCounterBackend implements CounterBackend
{
    private \Memcached $client;

    /**
     * @param string $servers comma-separated host[:port] list
     */
    public function __construct(
        string $servers = '127.0.0.1:11211',
        private string $prefix = 'zealstore',
    ) {
        if (!extension_loaded('memcached')) {
            throw new StoreException(
                'MemcachedCounterBackend requires ext-memcached. ' .
                '`pecl install memcached` or `apt-get install php-memcached`.'
            );
        }
        $this->client = new \Memcached();
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        foreach (self::parseServers($servers) as $hp) {
            $this->client->addServer($hp[0], $hp[1]);
        }
    }

    public function get(string $name): int
    {
        /** @var mixed $r */
        $r = $this->client->get($this->key($name));
        return is_numeric($r) ? (int) $r : 0;
    }

    public function set(string $name, int $value): bool
    {
        return $this->client->set($this->key($name), $value);
    }

    public function setIfAbsent(string $name, int $value): bool
    {
        // Memcached::add only sets when the key doesn't exist — perfect
        // SETNX semantics. Returns false when the key already has a value.
        return $this->client->add($this->key($name), $value);
    }

    public function incr(string $name, int $by = 1): int
    {
        $k = $this->key($name);
        // Memcached::increment returns false when the key doesn't exist —
        // initialize via add+0 if so, then increment. Two round-trips on
        // first call only; one on the hot path.
        $r = $this->client->increment($k, $by);
        if ($r !== false) { return (int) $r; }
        // Lazy init: add() with initial 0; if that succeeds, the slot is
        // fresh — fall through to increment. If add() fails, another
        // worker created it between our two calls; just increment again.
        $this->client->add($k, 0);
        $r = $this->client->increment($k, $by);
        return is_numeric($r) ? (int) $r : 0;
    }

    public function decr(string $name, int $by = 1): int
    {
        $k = $this->key($name);
        $r = $this->client->decrement($k, $by);
        if ($r !== false) { return (int) $r; }
        $this->client->add($k, 0);
        $r = $this->client->decrement($k, $by);
        return is_numeric($r) ? (int) $r : 0;
    }

    public function compareAndSet(string $name, int $expected, int $new): bool
    {
        $k = $this->key($name);
        /** @var mixed $cur */
        $cur = $this->client->get($k, null, \Memcached::GET_EXTENDED);
        if (!is_array($cur)) { return false; }
        $value = is_numeric($cur['value'] ?? null) ? (int) $cur['value'] : 0;
        $cas   = is_numeric($cur['cas']   ?? null) ? (float) $cur['cas'] : 0.0;
        if ($value !== $expected) { return false; }
        return $this->client->cas($cas, $k, $new);
    }

    public function incrBounded(string $name, int $by, int $maxBound): ?int
    {
        // CAS retry loop. Bounded at 100 attempts to avoid infinite spin
        // under heavy contention.
        $k = $this->key($name);
        for ($i = 0; $i < 100; $i++) {
            /** @var mixed $cur */
            $cur = $this->client->get($k, null, \Memcached::GET_EXTENDED);
            if (!is_array($cur)) {
                // Lazy init at 0, then continue the loop so the next iter
                // does the bounded check from a known state.
                $this->client->add($k, 0);
                continue;
            }
            $value = is_numeric($cur['value'] ?? null) ? (int) $cur['value'] : 0;
            $cas   = is_numeric($cur['cas']   ?? null) ? (float) $cur['cas'] : 0.0;
            $next  = $value + $by;
            if ($next > $maxBound) { return null; }
            if ($this->client->cas($cas, $k, $next)) {
                return $next;
            }
        }
        return null;
    }

    public function expire(string $name, int $seconds): bool
    {
        // Memcached::touch sets a new TTL on an existing key. Returns
        // false if the key doesn't exist — matches the documented
        // CounterBackend contract.
        return $this->client->touch($this->key($name), $seconds);
    }

    public function mincr(array $deltas): array
    {
        // Sequential — Memcached has no native MINCR. Pipelining wouldn't
        // help much: increment is a server-side op so latency is bounded
        // by RTT per key already. For huge batches, use Redis.
        $out = [];
        foreach ($deltas as $name => $by) {
            $out[(string) $name] = $this->incr((string) $name, (int) $by);
        }
        return $out;
    }

    public function reset(string $name): void
    {
        $this->client->set($this->key($name), 0);
    }

    private function key(string $name): string
    {
        return $this->prefix . ':counter:' . $name;
    }

    /**
     * @return list<array{0:string, 1:int}>
     */
    private static function parseServers(string $servers): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $servers))) as $hp) {
            $parts = explode(':', $hp, 2);
            $host  = $parts[0];
            $port  = isset($parts[1]) ? max(1, (int) $parts[1]) : 11211;
            $out[] = [$host, $port];
        }
        if ($out === []) { $out[] = ['127.0.0.1', 11211]; }
        return $out;
    }
}
