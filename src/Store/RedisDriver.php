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

    /**
     * One-batch cursor-based SSCAN (S-3). Returns [nextCursor, members].
     * `$cursor === '0'` starts a fresh scan; when the returned next-cursor
     * is `'0'` the scan is exhausted. Use when iterating large SETs without
     * blocking on a full generator drain — required for paginated UIs.
     *
     * @return array{0:string, 1:list<string>}
     */
    public function sscanCursor(string $key, string $cursor, int $count): array;

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

    /**
     * One-batch cursor-based SCAN MATCH (S-3). Same semantics as
     * `sscanCursor` but against the keyspace via SCAN.
     *
     * @return array{0:string, 1:list<string>}
     */
    public function scanCursor(string $match, string $cursor, int $count): array;

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

    /**
     * XAUTOCLAIM — atomically claim messages that have been pending in the
     * group for longer than `$minIdleMs` and assign them to `$consumer`.
     * Used for stale-consumer recovery: when a consumer crashes mid-processing,
     * its pending messages stay pending forever; a healthy peer steals them
     * after the idle threshold.
     *
     * Returns a tuple of [next-cursor, claimed-entries]. Iterate `$start`
     * with the next-cursor until it comes back as '0-0' to drain.
     *
     * @return array{0:string, 1:list<array{id:string, payload:array<string, string>}>}
     */
    public function xautoclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start = '0-0',
        int $count = 16,
    ): array;

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

    // ── bulk primitives (H3 — Phase B pipelining) ─────────────────────────
    //
    // These exist so RedisBackend::mget / mset / clear can do their work
    // in one round-trip instead of N. Each driver pipelines using its
    // native shape (phpredis multi(PIPELINE), predis $c->pipeline()) so
    // the backend never has to know what the underlying shape looks like.

    /**
     * Bulk HGETALL. Pipelined into a single round-trip; rows returned
     * indexed by the input order. Missing keys come back as `[]` (empty
     * hash) — Redis HGETALL on a non-existent key is empty, not an error.
     *
     * @param  array<int, string> $keys  full Redis keys (already prefixed by caller)
     * @return array<int, array<string, string>>
     */
    public function mhgetall(array $keys): array;

    /**
     * Bulk write hashes + optional SET membership + optional EXPIRE,
     * in one pipelined round-trip. Used by `RedisBackend::mset` and the
     * tracked-mode mset path.
     *
     * Each $writes entry:
     *   - `rk`     : full Redis key for the HSET target (already prefixed)
     *   - `fields` : the hash payload
     *   - `sk`     : (optional) the logical user key to add to $setKey
     *
     * Behaviour:
     *   - HMSET $rk fields
     *   - if $ttl > 0:   EXPIRE $rk $ttl
     *   - if $setKey !== null and any entries have `sk`: SADD $setKey sk1 sk2 ...
     *     (idempotent on existing members — no isNew check needed, but
     *      tracked mode stays consistent because SADD returns 0 for dupes)
     *
     * @param array<int, array{rk:string, fields:array<string,string>, sk?:string}> $writes
     */
    public function mhsetWithMembership(array $writes, ?string $setKey = null, ?int $ttl = null): void;

    /**
     * Best-effort non-blocking delete (UNLINK, Redis 4.0+). Falls back to
     * DEL if UNLINK is unavailable. Returns count of keys removed.
     *
     * Used by `RedisBackend::clear` to avoid blocking the Redis main thread
     * on large tables (UNLINK runs the actual reclaim in a background thread).
     */
    public function unlink(string ...$keys): int;
}
