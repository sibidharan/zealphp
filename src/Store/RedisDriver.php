<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Behavioural contract for a Redis/Valkey client lib (phpredis or predis).
 * Each method is the typed shape the rest of ZealPHP\Store expects — the
 * driver impl is responsible for wrapping its lib's exceptions in
 * StoreException so callers never see a client-lib symbol.
 */
interface RedisDriver
{
    public function name(): string;

    // ── string keys ─────────────────────────────────────────────────────
    public function set(string $key, string $value, ?int $ttlSeconds = null): bool;
    public function get(string $key): ?string;
    public function del(string ...$keys): int;
    public function exists(string $key): bool;
    public function expire(string $key, int $ttlSeconds): bool;

    // ── hashes ──────────────────────────────────────────────────────────
    /** @param array<string, string> $fields */
    public function hset(string $key, array $fields): int;
    /** @return array<string, string> */
    public function hgetall(string $key): array;
    /**
     * @param array<int, string> $fields
     * @return array<int, string|null>
     */
    public function hmget(string $key, array $fields): array;
    public function hincrby(string $key, string $field, int $by): int;
    public function hincrbyfloat(string $key, string $field, float $by): float;
    public function hdel(string $key, string ...$fields): int;

    // ── sets ────────────────────────────────────────────────────────────
    /** @param array<int, string> $members */
    public function sadd(string $key, array $members): int;
    /** @param array<int, string> $members */
    public function srem(string $key, array $members): int;
    public function scard(string $key): int;
    /** @return \Generator<int, string> */
    public function sscan(string $key, int $batch = 100): \Generator;

    // ── counters ────────────────────────────────────────────────────────
    public function incrby(string $key, int $by): int;
    public function decrby(string $key, int $by): int;

    // ── scripting + keyspace scan ───────────────────────────────────────
    /**
     * @param array<int, string> $keys
     * @param array<int, string> $args
     */
    public function evalScript(string $script, array $keys, array $args): mixed;

    /** @return \Generator<int, string> */
    public function scanKeys(string $match, int $batch = 200): \Generator;

    // ── lifecycle ───────────────────────────────────────────────────────
    public function ping(): bool;
    public function close(): void;

    /**
     * The pipeline callable receives an "echoed" driver instance whose
     * methods enqueue commands instead of executing them; the return
     * is the list of results in command order.
     *
     * @return list<mixed>
     */
    public function pipeline(callable $batch): array;
}
