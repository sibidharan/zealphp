<?php

declare(strict_types=1);

namespace ZealPHP\Store;

/**
 * Thin adapter over phpredis (preferred when ext-redis is loaded) or
 * predis (pure-PHP fallback). The ONE place the client lib is referenced
 * by name in ZealPHP — every other class talks to this adapter.
 *
 * Construct with a redis URL; pass ['prefer' => 'phpredis'|'predis'|'auto']
 * to force the driver. 'auto' (default) picks phpredis when the ext is
 * loaded, predis when it isn't, throws when neither is available.
 */
final class RedisClient
{
    /** Active driver — either `PhpredisDriver` or `PredisDriver`, selected at construction time. */
    private RedisDriver $driver;

    /**
     * Connect to `$url` using the best available driver. Pass
     * `$opts['prefer']` as `'phpredis'`, `'predis'`, or `'auto'`
     * (default) to control driver selection explicitly.
     *
     * @param array{prefer?: 'auto'|'phpredis'|'predis'} $opts
     */
    public function __construct(string $url, array $opts = [])
    {
        $this->driver = self::pickDriver($url, $opts['prefer'] ?? 'auto');
    }

    /**
     * Select and instantiate the appropriate `RedisDriver` based on `$prefer`
     * and the extensions available at runtime. Throws `StoreException` when
     * `$prefer` is not one of `'auto'`, `'phpredis'`, or `'predis'`, or when
     * `'auto'` is chosen but neither ext-redis nor predis is available.
     */
    private static function pickDriver(string $url, string $prefer): RedisDriver
    {
        if ($prefer === 'phpredis') {
            return new PhpredisDriver($url);
        }
        if ($prefer === 'predis') {
            return new PredisDriver($url);
        }
        if ($prefer !== 'auto') {
            throw new StoreException("Unknown 'prefer' value: $prefer (use auto|phpredis|predis)");
        }
        if (extension_loaded('redis')) {
            return new PhpredisDriver($url);
        }
        if (class_exists(\Predis\Client::class)) {
            return new PredisDriver($url);
        }
        throw new StoreException(
            'No Redis client available — install ext-redis (pecl install redis) or `composer require predis/predis`'
        );
    }

    /** Return the active driver name — `'phpredis'` or `'predis'`. Useful for diagnostics and feature guards. */
    public function driverName(): string { return $this->driver->name(); }

    // ── string keys ─────────────────────────────────────────────────────
    /** Set a plain string key, optionally with a TTL in seconds. Returns `true` on success. */
    public function set(string $key, string $value, ?int $ttlSeconds = null): bool { return $this->driver->set($key, $value, $ttlSeconds); }
    /** Get a plain string key. Returns `null` on miss. */
    public function get(string $key): ?string { return $this->driver->get($key); }
    /** Delete one or more keys. Returns the count of keys that were removed. */
    public function del(string ...$keys): int { return $this->driver->del(...$keys); }
    /** Return `true` when `$key` exists in Redis. */
    public function exists(string $key): bool { return $this->driver->exists($key); }
    /** Set a TTL of `$ttlSeconds` on `$key` via `EXPIRE`. Returns `true` on success. */
    public function expire(string $key, int $ttlSeconds): bool { return $this->driver->expire($key, $ttlSeconds); }

    // ── hashes ──────────────────────────────────────────────────────────
    /** @param array<string, string> $fields */
    public function hset(string $key, array $fields): int { return $this->driver->hset($key, $fields); }
    /** @return array<string, string> */
    public function hgetall(string $key): array { return $this->driver->hgetall($key); }
    /**
     * @param array<int, string> $fields
     * @return array<int, string|null>
     */
    public function hmget(string $key, array $fields): array { return $this->driver->hmget($key, $fields); }
    /** Atomically increment hash field by `$by` (int) via `HINCRBY`. Returns the new value. */
    public function hincrby(string $key, string $field, int $by): int { return $this->driver->hincrby($key, $field, $by); }
    /** Atomically increment hash field by `$by` (float) via `HINCRBYFLOAT`. Returns the new value. */
    public function hincrbyfloat(string $key, string $field, float $by): float { return $this->driver->hincrbyfloat($key, $field, $by); }
    /** Delete one or more hash fields via `HDEL`. Returns the number of fields removed. */
    public function hdel(string $key, string ...$fields): int { return $this->driver->hdel($key, ...$fields); }

    // ── sets ────────────────────────────────────────────────────────────
    /** @param array<int, string> $members */
    public function sadd(string $key, array $members): int { return $this->driver->sadd($key, $members); }
    /** @param array<int, string> $members */
    public function srem(string $key, array $members): int { return $this->driver->srem($key, $members); }
    public function scard(string $key): int { return $this->driver->scard($key); }
    /** @return \Generator<int, string> */
    public function sscan(string $key, int $batch = 100): \Generator { yield from $this->driver->sscan($key, $batch); }
    /** @return array{0:string, 1:list<string>} */
    public function sscanCursor(string $key, string $cursor, int $count): array { return $this->driver->sscanCursor($key, $cursor, $count); }

    // ── counters ────────────────────────────────────────────────────────
    /** Atomically increment a plain string counter by `$by` via `INCRBY`. Returns the new value. */
    public function incrby(string $key, int $by): int { return $this->driver->incrby($key, $by); }
    /** Atomically decrement a plain string counter by `$by` via `DECRBY`. Returns the new value. */
    public function decrby(string $key, int $by): int { return $this->driver->decrby($key, $by); }

    // ── scripting + scan + lifecycle ────────────────────────────────────
    /**
     * @param array<int, string> $keys
     * @param array<int, string> $args
     */
    public function evalScript(string $script, array $keys, array $args): mixed { return $this->driver->evalScript($script, $keys, $args); }

    /** @return \Generator<int, string> */
    public function scanKeys(string $match, int $batch = 200): \Generator { yield from $this->driver->scanKeys($match, $batch); }
    /** @return array{0:string, 1:list<string>} */
    public function scanCursor(string $match, string $cursor, int $count): array { return $this->driver->scanCursor($match, $cursor, $count); }

    /** Ping the Redis server. Returns `true` when the server is reachable. */
    public function ping(): bool { return $this->driver->ping(); }
    /** Close the underlying driver connection and release resources. */
    public function close(): void { $this->driver->close(); }
    /** @return list<mixed> */
    public function pipeline(callable $batch): array { return $this->driver->pipeline($batch); }

    // ── bulk primitives (H3) ────────────────────────────────────────────
    /**
     * @param array<int, string> $keys
     * @return array<int, array<string, string>>
     */
    public function mhgetall(array $keys): array { return $this->driver->mhgetall($keys); }

    /** @param array<int, array{rk:string, fields:array<string,string>, sk?:string}> $writes */
    public function mhsetWithMembership(array $writes, ?string $setKey = null, ?int $ttl = null): void
    {
        $this->driver->mhsetWithMembership($writes, $setKey, $ttl);
    }

    /** Asynchronously delete keys via `UNLINK` (non-blocking, unlike `DEL`). Returns the count of keys removed. */
    public function unlink(string ...$keys): int { return $this->driver->unlink(...$keys); }

    // ── pub/sub ─────────────────────────────────────────────────────────
    /** Publish `$payload` to a pub/sub `$channel`. Returns the number of subscribers that received it. */
    public function publish(string $channel, string $payload): int { return $this->driver->publish($channel, $payload); }

    /**
     * @param array<int, string> $exactChannels
     * @param array<int, string> $patternChannels
     * @param callable(string $payload, string $channel, ?string $pattern): void $consumer
     */
    public function subscribe(array $exactChannels, array $patternChannels, callable $consumer): void
    { $this->driver->subscribe($exactChannels, $patternChannels, $consumer); }

    // ── streams ─────────────────────────────────────────────────────────
    /** @param array<string, string> $fields */
    public function xadd(string $stream, array $fields, ?int $maxLen = null): string
    { return $this->driver->xadd($stream, $fields, $maxLen); }

    /**
     * Create a Redis Stream consumer group. Returns `true` on success, `false`
     * when the group already exists (`BUSYGROUP` — idempotent). Creates the
     * stream when `$mkStream` is `true` and the stream doesn't yet exist.
     */
    public function xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): bool
    { return $this->driver->xgroupCreate($stream, $group, $id, $mkStream); }

    /**
     * @param array<int, string> $streams
     * @return array<string, list<array{id: string, payload: array<string, string>}>>
     */
    public function xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array
    { return $this->driver->xreadGroup($group, $consumer, $streams, $count, $blockMs); }

    /** Acknowledge stream entries via `XACK`, removing them from the consumer group's pending list. Returns the count acknowledged. */
    public function xack(string $stream, string $group, string ...$ids): int
    { return $this->driver->xack($stream, $group, ...$ids); }

    /**
     * @return array{0:string, 1:list<array{id:string, payload:array<string, string>}>}
     */
    public function xautoclaim(
        string $stream,
        string $group,
        string $consumer,
        int $minIdleMs,
        string $start = '0-0',
        int $count = 16,
    ): array {
        return $this->driver->xautoclaim($stream, $group, $consumer, $minIdleMs, $start, $count);
    }
}
