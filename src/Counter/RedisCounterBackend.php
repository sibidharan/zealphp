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
