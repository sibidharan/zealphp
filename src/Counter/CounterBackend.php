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

    /**
     * Initialize a counter ONLY if it doesn't already exist (N-1).
     * Returns true on first-time set; false if the counter already has a
     * value (existing value is preserved).
     */
    public function setIfAbsent(string $name, int $value): bool;

    public function incr(string $name, int $by = 1): int;
    public function decr(string $name, int $by = 1): int;
    public function compareAndSet(string $name, int $expected, int $new): bool;

    /**
     * Bounded atomic increment (N-2). Increments by `$by` only if the
     * resulting value would NOT exceed `$maxBound`. Returns the new
     * value on success, or NULL when the cap would be exceeded (no-op).
     *
     * Use case: rate-limit slots, pool counters where the cap matters.
     */
    public function incrBounded(string $name, int $by, int $maxBound): ?int;

    /**
     * Set TTL on a counter (N-3). Returns true if the TTL was applied.
     * Atomic backend: no-op (returns false — shared memory has no TTL;
     * the counter lives until the master dies).
     * Redis backend: EXPIRE on the underlying key.
     */
    public function expire(string $name, int $seconds): bool;

    /**
     * Batch increment (N-4). Pipelined where the backend supports it.
     *
     * @param  array<string, int> $deltas  name → delta (defaults to 1 each)
     * @return array<string, int> name → new value (in input order)
     */
    public function mincr(array $deltas): array;

    public function reset(string $name): void;
}
