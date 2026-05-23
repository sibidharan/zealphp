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

    // ── pub/sub ─────────────────────────────────────────────────────────
    /** @return int receivers Redis delivered to */
    public function publish(string $channel, string $payload): int;

    /**
     * Block in a SUBSCRIBE+PSUBSCRIBE loop. The consumer is invoked once per
     * inbound message frame with `(string $payload, string $channel, ?string $pattern)`.
     * Throwing `PubSubStopException` from inside the consumer is the clean
     * stop signal — drivers MUST catch it, UNSUBSCRIBE, return.
     *
     * @param array<int, string> $exactChannels    channels for SUBSCRIBE
     * @param array<int, string> $patternChannels  patterns for PSUBSCRIBE (Redis * glob)
     * @param callable(string $payload, string $channel, ?string $pattern): void $consumer
     */
    public function subscribe(array $exactChannels, array $patternChannels, callable $consumer): void;

    // ── streams ─────────────────────────────────────────────────────────
    /**
     * Append a message; auto-generated ID via `*`. Returns the generated ID.
     *
     * @param array<string, string> $fields    field=>value pairs forming the stream entry
     * @param ?int                  $maxLen    if set, applied as `MAXLEN ~` trimming
     */
    public function xadd(string $stream, array $fields, ?int $maxLen = null): string;

    /**
     * Idempotent group create. Returns true if newly created, false if it already
     * existed (BUSYGROUP). MKSTREAM ensures the stream is created if absent.
     */
    public function xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): bool;

    /**
     * XREADGROUP COUNT N BLOCK ms STREAMS s1 s2 ... > >.
     *
     * @param array<int, string> $streams
     * @return array<string, list<array{id: string, payload: array<string, string>}>>  keyed by stream
     */
    public function xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array;

    /** @return int ids actually ACK'd */
    public function xack(string $stream, string $group, string ...$ids): int;

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
