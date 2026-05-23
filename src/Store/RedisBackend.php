<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Table;

/**
 * Redis/Valkey-backed StoreBackend.
 *
 * Row layout: HASH at `{prefix}:{table}:{key}` (columns → hash fields).
 * Membership (tracked mode): SET at `{prefix}:{table}:__keys__`.
 *
 * Two modes chosen at make():
 *  - 'tracked' (default) — SET-backed; count() is O(1) via SCARD,
 *    iterate() via SSCAN. No TTL (an expired key cannot fire SREM
 *    on the membership set, which would drift).
 *  - 'ttl' — per-key expiry via SETEX-style; count() / iterate() use
 *    SCAN MATCH (O(N)) because tracked membership isn't viable. Pick
 *    one per table; the trade-off is documented in CLAUDE.md.
 *
 * Every op goes through RedisConnectionPool::with() so concurrent
 * coroutines never share a socket.
 */
final class RedisBackend implements StoreBackend
{
    /** @var array<string, array<string, array{0:int, 1?:int}>> */
    private array $schemas = [];
    /** @var array<string, array{mode:string, ttl:int}> */
    private array $tableOpts = [];

    private TypeCodec $codec;

    public function __construct(
        private RedisConnectionPool $pool,
        private string $prefix = 'zealstore',
    ) {
        $this->codec = new TypeCodec();
    }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        if ($columns === []) {
            $columns = ['value' => [Table::TYPE_STRING, 256]];
        }
        $mode = $opts['mode'] ?? 'tracked';
        if (!is_string($mode) || !in_array($mode, ['tracked', 'ttl'], true)) {
            throw new StoreException("RedisBackend::make: unknown mode '" . (is_string($mode) ? $mode : get_debug_type($mode)) . "' (expected 'tracked' or 'ttl')");
        }
        $rawTtl = $opts['ttl'] ?? 0;
        $ttl = is_numeric($rawTtl) ? (int) $rawTtl : 0;
        if ($mode === 'ttl' && $ttl < 1) {
            throw new StoreException("RedisBackend::make: 'ttl' mode requires \$opts['ttl'] >= 1 second");
        }
        // H1: tracked + ttl>0 silently ignored ttl pre-v0.2.41 (membership SET would drift —
        // an expired key can't fire SREM on the tracked SET, so count()/iterate() would lie).
        // Throw at make() so the conflict surfaces at boot, not after the first expiry.
        if ($mode === 'tracked' && $ttl > 0) {
            throw new StoreException(
                "RedisBackend::make: 'tracked' mode does not support TTL " .
                "(expired keys cannot fire SREM on the membership set — " .
                "the tracked SET would drift and count()/iterate() would lie). " .
                "Use mode='ttl' for per-key expiry."
            );
        }
        $this->schemas[$name]   = $columns;
        $this->tableOpts[$name] = ['mode' => $mode, 'ttl' => $ttl];
    }

    public function set(string $name, string $key, array $row): bool
    {
        $this->assertMade($name);
        $rk     = $this->rowKey($name, $key);
        $sk     = $this->setKey($name);
        $schema = $this->schemas[$name];
        $opts   = $this->tableOpts[$name];
        $wire   = $this->codec->encodeRow($schema, $row);
        return (bool) $this->pool->with(function (RedisClient $c) use ($rk, $sk, $wire, $key, $opts): bool {
            $isNew = !$c->exists($rk);
            $c->hset($rk, $wire);
            if ($opts['mode'] === 'tracked' && $isNew) {
                $c->sadd($sk, [$key]);
            }
            if ($opts['mode'] === 'ttl' && $opts['ttl'] > 0) {
                $c->expire($rk, $opts['ttl']);
            }
            return true;
        });
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $this->assertMade($name);
        $rk     = $this->rowKey($name, $key);
        $schema = $this->schemas[$name];
        return $this->pool->with(function (RedisClient $c) use ($rk, $schema, $field): mixed {
            if ($field !== null) {
                if (!isset($schema[$field])) { return null; }
                $raw = $c->hmget($rk, [$field])[0] ?? null;
                if ($raw === null && !$c->exists($rk)) { return null; }
                return $this->codec->decodeField($schema[$field][0], $raw);
            }
            return $this->codec->decodeRow($schema, $c->hgetall($rk));
        });
    }

    public function del(string $name, string $key): bool
    {
        $this->assertMade($name);
        $rk = $this->rowKey($name, $key);
        $sk = $this->setKey($name);
        $opts = $this->tableOpts[$name];
        return (bool) $this->pool->with(function (RedisClient $c) use ($rk, $sk, $key, $opts): bool {
            $removed = $c->del($rk) > 0;
            if ($removed && $opts['mode'] === 'tracked') {
                $c->srem($sk, [$key]);
            }
            return $removed;
        });
    }

    public function exists(string $name, string $key): bool
    {
        $this->assertMade($name);
        $rk = $this->rowKey($name, $key);
        return (bool) $this->pool->with(fn(RedisClient $c): bool => $c->exists($rk));
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $this->assertMade($name);
        $schema = $this->schemas[$name];
        $rk     = $this->rowKey($name, $key);
        $sk     = $this->setKey($name);
        $opts   = $this->tableOpts[$name];
        $type   = $schema[$col][0] ?? Table::TYPE_INT;

        return $this->pool->with(function (RedisClient $c) use ($rk, $sk, $key, $col, $by, $type, $opts): int|float {
            $isNew = !$c->exists($rk);
            $r = $type === Table::TYPE_FLOAT
                ? $c->hincrbyfloat($rk, $col, (float) $by)
                : $c->hincrby($rk, $col, (int) $by);
            if ($opts['mode'] === 'tracked' && $isNew) {
                $c->sadd($sk, [$key]);
            }
            if ($opts['mode'] === 'ttl' && $opts['ttl'] > 0) {
                $c->expire($rk, $opts['ttl']);
            }
            return $r;
        });
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        return $this->incr($name, $key, $col, -$by);
    }

    public function count(string $name): int
    {
        $this->assertMade($name);
        if ($this->tableOpts[$name]['mode'] === 'ttl') {
            return $this->countViaScan($name);
        }
        $sk = $this->setKey($name);
        return $this->pool->with(fn(RedisClient $c): int => $c->scard($sk));
    }

    public function iterate(string $name): \Generator
    {
        $this->assertMade($name);
        $schema = $this->schemas[$name];
        $sk     = $this->setKey($name);
        $prefix = $this->prefix . ':' . $name . ':';

        // In tracked mode iterate the membership SET; in ttl mode SCAN the keyspace.
        if ($this->tableOpts[$name]['mode'] === 'ttl') {
            $client = $this->pool->acquire();
            try {
                foreach ($client->scanKeys($prefix . '*') as $rowKey) {
                    if ($rowKey === $sk) { continue; }
                    $key   = substr($rowKey, strlen($prefix));
                    $row   = $this->codec->decodeRow($schema, $client->hgetall($rowKey));
                    if ($row !== null) { yield $key => $row; }
                }
            } finally { $this->pool->release($client); }
            return;
        }
        $client = $this->pool->acquire();
        try {
            foreach ($client->sscan($sk) as $key) {
                $row = $this->codec->decodeRow($schema, $client->hgetall($this->rowKey($name, $key)));
                if ($row !== null) { yield $key => $row; }
            }
        } finally { $this->pool->release($client); }
    }

    public function clear(string $name): void
    {
        $this->assertMade($name);
        $sk     = $this->setKey($name);
        $prefix = $this->prefix . ':' . $name . ':';
        $this->pool->with(function (RedisClient $c) use ($sk, $prefix, $name): void {
            if ($this->tableOpts[$name]['mode'] === 'ttl') {
                foreach ($c->scanKeys($prefix . '*') as $rk) {
                    $c->del($rk);
                }
                return;
            }
            $members = iterator_to_array($c->sscan($sk), false);
            foreach (array_chunk($members, 100) as $batch) {
                $rkeys = array_map(fn(string $k): string => $prefix . $k, $batch);
                $c->del(...$rkeys);
            }
            $c->del($sk);
        });
    }

    public function names(): array
    {
        return array_keys($this->schemas);
    }

    public function mget(string $name, array $keys): array
    {
        $this->assertMade($name);
        if ($keys === []) { return []; }
        $schema = $this->schemas[$name];
        return $this->pool->with(function (RedisClient $c) use ($name, $keys, $schema): array {
            $out = [];
            foreach ($keys as $k) {
                $wire = $c->hgetall($this->rowKey($name, $k));
                $out[$k] = $this->codec->decodeRow($schema, $wire);
            }
            return $out;
        });
    }

    public function mset(string $name, array $rows): bool
    {
        $this->assertMade($name);
        if ($rows === []) { return true; }
        $schema = $this->schemas[$name];
        $opts   = $this->tableOpts[$name];
        $sk     = $this->setKey($name);

        return (bool) $this->pool->with(function (RedisClient $c) use ($name, $rows, $schema, $opts, $sk): bool {
            $newKeys = [];
            foreach ($rows as $key => $row) {
                $skey = (string) $key;
                $rk   = $this->rowKey($name, $skey);
                $isNew = !$c->exists($rk);
                $c->hset($rk, $this->codec->encodeRow($schema, $row));
                if ($opts['mode'] === 'tracked' && $isNew) { $newKeys[] = $skey; }
                if ($opts['mode'] === 'ttl' && $opts['ttl'] > 0) {
                    $c->expire($rk, $opts['ttl']);
                }
            }
            if ($newKeys !== []) { $c->sadd($sk, $newKeys); }
            return true;
        });
    }

    /** Health check via PING — `Store::ping()` (Task 11) delegates here. */
    public function ping(): bool
    {
        return (bool) $this->pool->with(fn(RedisClient $c): bool => $c->ping());
    }

    /** Pub/sub publish through the pool; returns receivers Redis delivered to. */
    public function publish(string $channel, string $payload): int
    {
        return $this->pool->with(fn(RedisClient $c): int => $c->publish($channel, $payload));
    }

    /**
     * Streams append. Auto-MAXLEN-trims when $maxLen is set. Payload is
     * stored under the 'payload' field; matches the consumer-side
     * convention in RedisStreams.
     */
    public function publishReliable(string $stream, string $payload, ?int $maxLen = null): string
    {
        return $this->pool->with(fn(RedisClient $c): string => $c->xadd($stream, ['payload' => $payload], $maxLen));
    }

    public function url(): string { return $this->pool->url(); }
    public function pool(): RedisConnectionPool { return $this->pool; }
    public function prefix(): string { return $this->prefix; }

    private function countViaScan(string $name): int
    {
        $sk     = $this->setKey($name);
        $prefix = $this->prefix . ':' . $name . ':';
        return $this->pool->with(function (RedisClient $c) use ($sk, $prefix): int {
            $n = 0;
            foreach ($c->scanKeys($prefix . '*') as $k) {
                if ($k === $sk) { continue; }
                $n++;
            }
            return $n;
        });
    }

    private function rowKey(string $table, string $key): string
    {
        return $this->prefix . ':' . $table . ':' . $key;
    }

    private function setKey(string $table): string
    {
        return $this->prefix . ':' . $table . ':__keys__';
    }

    private function assertMade(string $name): void
    {
        if (!isset($this->schemas[$name])) {
            throw new StoreException("RedisBackend: table not registered: $name");
        }
    }
}
