<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

/**
 * Behavioural contract for Counter backends.
 *
 * The default `AtomicBackend` wraps `OpenSwoole\Atomic` for lock-free
 * intra-process sharing. `RedisCounterBackend` uses INCRBY/DECRBY with
 * a Lua `compareAndSet` for cross-node atomicity.
 *
 * Counters are addressed by name; a backend instance can host many.
 */
interface CounterBackend
{
    public function get(string $name): int;
    public function set(string $name, int $value): bool;
    public function incr(string $name, int $by = 1): int;
    public function decr(string $name, int $by = 1): int;
    public function compareAndSet(string $name, int $expected, int $new): bool;
    public function reset(string $name): void;
}
