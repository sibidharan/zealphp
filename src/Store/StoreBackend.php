<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Behavioural contract every Store backend implements.
 *
 * The default `TableBackend` wraps `OpenSwoole\Table` (single-node,
 * in-memory, lock-free reads, nanosecond latency). `RedisBackend`
 * (Task 6) talks to Redis/Valkey for cross-node + persistent state.
 * Phase 2's `TieredBackend` layers them.
 *
 * Implementations MUST preserve identical typed shapes across backends:
 * a `get()` returning `['hits' => 1]` from Table must return the same
 * `['hits' => 1]` from Redis after a `TypeCodec` decode pass.
 */
interface StoreBackend
{
    /**
     * Register a named table with a column schema. For Table this allocates
     * shared memory immediately; for Redis it stores the schema and defers
     * connection until first use.
     *
     * @param array<string, array{0:int, 1?:int}> $columns
     * @param array<string, mixed>                $opts    backend-specific: mode/ttl/etc.
     */
    public function make(string $name, int $maxRows, array $columns, array $opts = []): void;

    /** @param array<string, scalar> $row */
    public function set(string $name, string $key, array $row): bool;

    /**
     * Read a row OR a single field.
     *
     * Returns `array<string, scalar>` when `$field` is null, the field's
     * scalar value when set, or `null` on miss. The exact typed shape
     * across backends is preserved by `TypeCodec` (Task 5).
     */
    public function get(string $name, string $key, ?string $field = null): mixed;

    public function del(string $name, string $key): bool;
    public function exists(string $name, string $key): bool;
    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float;
    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float;
    public function count(string $name): int;

    /** @return \Generator<string, array<string, scalar>> */
    public function iterate(string $name): \Generator;

    public function clear(string $name): void;

    /** @return list<string> */
    public function names(): array;

    /**
     * Bulk read. Returns `[key => row]`; rows are `null` for missing keys
     * (NOT omitted) so callers can distinguish "not found" from "empty
     * row". RedisBackend pipelines this into one round-trip; TableBackend
     * loops.
     *
     * @param  list<string> $keys
     * @return array<string, array<string, scalar>|null>
     */
    public function mget(string $name, array $keys): array;

    /**
     * Bulk write. Returns true on full success, false if any individual
     * row failed (TableBackend overflow). The Redis impl is pipelined.
     *
     * @param array<string, array<string, scalar>> $rows
     */
    public function mset(string $name, array $rows): bool;
}
