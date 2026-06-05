<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

use ZealPHP\Store\RedisClient;
use ZealPHP\Store\RedisConnectionPool;

/**
 * Redis/Valkey-backed CounterBackend.
 *
 * Each counter is one Redis string at `{prefix}:counter:{name}`.
 * INCRBY/DECRBY/GET/SET are native one-op atomic. compareAndSet
 * runs a small Lua script for server-side CAS in one round-trip.
 */
final class RedisCounterBackend implements CounterBackend
{
    private const LUA_CAS = "if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('SET', KEYS[1], ARGV[2]); return 1; else return 0; end";

    public function __construct(
        private RedisConnectionPool $pool,
        private string $prefix = 'zealstore',
    ) {}

    public function get(string $name): int
    {
        return $this->pool->with(function (RedisClient $c) use ($name): int {
            $v = $c->get($this->key($name));
            return $v === null ? 0 : (int) $v;
        });
    }

    public function set(string $name, int $value): bool
    {
        return $this->pool->with(fn(RedisClient $c): bool => $c->set($this->key($name), (string) $value));
    }

    public function setIfAbsent(string $name, int $value): bool
    {
        // SETNX (atomic "set if not exists") via Lua — works across both
        // drivers without needing a dedicated client method.
        $r = $this->pool->with(fn(RedisClient $c): mixed => $c->evalScript(
            "return redis.call('SETNX', KEYS[1], ARGV[1])",
            [$this->key($name)],
            [(string) $value],
        ));
        return is_int($r) ? $r === 1 : (is_string($r) && $r === '1');
    }

    public function incrBounded(string $name, int $by, int $maxBound): ?int
    {
        // Server-side atomic CHECK-AND-INCREMENT in one round-trip via Lua.
        // #242: the script returns a STRUCTURED 2-element table —
        //   {0}          → cap exceeded, nothing written
        //   {1, nxt}     → written; nxt is the new value (which may legitimately
        //                  be NEGATIVE, e.g. base 0 + incrBounded(-7, 100))
        // The old `return -1` sentinel collapsed any legitimately-negative
        // result to null ("not incremented"), a lie — a -1 status and a -1
        // value were indistinguishable. The {ok, val} shape separates them.
        // Redis maps a Lua table to a multi-bulk reply (array) on both drivers.
        $r = $this->pool->with(fn(RedisClient $c): mixed => $c->evalScript(
            "local cur = tonumber(redis.call('GET', KEYS[1]) or '0'); " .
            "local nxt = cur + tonumber(ARGV[1]); " .
            "if nxt > tonumber(ARGV[2]) then return {0}; end; " .
            "redis.call('SET', KEYS[1], nxt); " .
            "return {1, nxt};",
            [$this->key($name)],
            [(string) $by, (string) $maxBound],
        ));
        if (!is_array($r) || (($r[0] ?? 0) !== 1)) {
            return null; // capped (or an unexpected reply shape) — nothing written
        }
        $val = $r[1] ?? 0;
        return is_numeric($val) ? (int) $val : 0;
    }

    public function expire(string $name, int $seconds): bool
    {
        return $this->pool->with(fn(RedisClient $c): bool => $c->expire($this->key($name), $seconds));
    }

    public function mincr(array $deltas): array
    {
        if ($deltas === []) { return []; }
        return $this->pool->with(function (RedisClient $c) use ($deltas): array {
            $out = [];
            foreach ($deltas as $name => $by) {
                $key = $this->key((string) $name);
                $out[(string) $name] = $c->incrby($key, (int) $by);
            }
            return $out;
        });
    }

    public function incr(string $name, int $by = 1): int
    {
        return $this->pool->with(fn(RedisClient $c): int => $c->incrby($this->key($name), $by));
    }

    public function decr(string $name, int $by = 1): int
    {
        return $this->pool->with(fn(RedisClient $c): int => $c->decrby($this->key($name), $by));
    }

    public function compareAndSet(string $name, int $expected, int $new): bool
    {
        $r = $this->pool->with(fn(RedisClient $c): mixed => $c->evalScript(
            self::LUA_CAS,
            [$this->key($name)],
            [(string) $expected, (string) $new],
        ));
        return is_int($r) ? $r === 1 : (is_string($r) && $r === '1');
    }

    public function reset(string $name): void
    {
        $this->pool->with(function (RedisClient $c) use ($name): void {
            $c->set($this->key($name), '0');
        });
    }

    private function key(string $name): string
    {
        return $this->prefix . ':counter:' . $name;
    }
}
