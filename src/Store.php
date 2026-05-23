<?php

declare(strict_types=1);

namespace ZealPHP;

use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;

/**
 * `Store` — backend-agnostic key-value store.
 *
 * Default backend is `OpenSwoole\Table` (single-node, in-memory,
 * lock-free, nanosecond latency — the hot path). Flip to Redis/Valkey
 * for cross-node + persistent shared state with one line in app.php:
 *
 * ```php
 * Store::defaultBackend('redis');                         // ZEALPHP_REDIS_URL env
 * Store::defaultBackend('redis', 'redis://cache:6379/0'); // explicit URL
 * ```
 *
 * Every existing call site keeps working unchanged — the static API is
 * preserved verbatim.
 *
 * Column types — prefer the backend-neutral `Store::TYPE_*` constants
 * for new code; `OpenSwoole\Table::TYPE_*` still works for BC:
 *   `Store::TYPE_INT`    — 1, 2, 4, or 8 bytes
 *   `Store::TYPE_FLOAT`  — 8 bytes
 *   `Store::TYPE_STRING` — up to N bytes (specify max length)
 *
 * IMPORTANT: `Store::make()` must be called BEFORE `$app->run()`
 * (before workers are forked). The Table backend's shared memory
 * segment is inherited on fork; the Redis backend's connection pool
 * is lazily built per worker.
 *
 * Usage (identical across backends):
 *
 * ```php
 * Store::make('sessions', 4096, [
 *     'uid' => [Store::TYPE_STRING, 64],
 *     'hits' => [Store::TYPE_INT,   4],
 * ]);
 * Store::set('sessions', $id, ['uid' => 'alice', 'hits' => 0]);
 * Store::get('sessions', $id);                    // ['uid' => 'alice', ...]
 * Store::incr('sessions', $id, 'hits');
 * Store::count('sessions');                       // O(1)
 * ```
 */
class Store
{
    public const TYPE_INT    = Table::TYPE_INT;
    public const TYPE_FLOAT  = Table::TYPE_FLOAT;
    public const TYPE_STRING = Table::TYPE_STRING;

    private static ?StoreBackend $backend = null;
    /** @var array{kind:string, conn?: string|array<string,mixed>} */
    private static array $backendConfig = ['kind' => 'table'];

    /**
     * Get or set the process-wide default backend.
     *
     * @param  ?string                          $kind  'table' (default) or 'redis'; null to read current
     * @param  string|array<string,mixed>       $conn  redis URL string, OR ['url'=>, 'pool_size'=>, 'prefix'=>]
     */
    public static function defaultBackend(?string $kind = null, string|array $conn = []): StoreBackend
    {
        if ($kind !== null) {
            if (!in_array($kind, ['table', 'redis'], true)) {
                throw new \InvalidArgumentException("Unknown Store backend kind: $kind (use 'table' or 'redis')");
            }
            self::$backendConfig = ['kind' => $kind, 'conn' => $conn];
            self::$backend = null;
        }
        return self::$backend ??= self::buildBackend(self::$backendConfig);
    }

    /** @param array{kind:string, conn?: string|array<string,mixed>} $cfg */
    private static function buildBackend(array $cfg): StoreBackend
    {
        if ($cfg['kind'] !== 'redis') {
            return new TableBackend();
        }
        $conn = $cfg['conn'] ?? [];
        if (is_string($conn)) {
            $url = $conn !== '' ? $conn : self::redisUrlFromEnv();
            return new RedisBackend(new RedisConnectionPool($url));
        }
        $url    = isset($conn['url']) && is_string($conn['url']) ? $conn['url'] : self::redisUrlFromEnv();
        $size   = isset($conn['pool_size']) && is_int($conn['pool_size']) ? $conn['pool_size'] : 8;
        $prefix = isset($conn['prefix']) && is_string($conn['prefix']) ? $conn['prefix'] : 'zealstore';
        return new RedisBackend(new RedisConnectionPool($url, $size), $prefix);
    }

    private static function redisUrlFromEnv(): string
    {
        $env = getenv('ZEALPHP_REDIS_URL');
        return is_string($env) && $env !== '' ? $env : 'redis://127.0.0.1:6379';
    }

    /**
     * Create a named table. Returns the underlying `OpenSwoole\Table`
     * when the backend is `table`, or null for redis (the raw object
     * has no equivalent there — use the backend-neutral methods).
     *
     * @param  array<string, array{0:int, 1?:int}> $columns
     * @param  array<string, mixed>                $opts    backend-specific: mode/ttl/etc.
     */
    public static function make(string $name, int $maxRows = 1024, array $columns = [], array $opts = []): ?Table
    {
        $backend = self::defaultBackend();
        $backend->make($name, $maxRows, $columns, $opts);
        return $backend instanceof TableBackend ? $backend->rawTable($name) : null;
    }

    /**
     * Direct access to the underlying `OpenSwoole\Table`. Only available
     * on the table backend; throws `StoreException` on the redis backend
     * (the raw object has no Redis equivalent — use the static methods).
     */
    public static function table(string $name): ?Table
    {
        $backend = self::defaultBackend();
        if (!($backend instanceof TableBackend)) {
            throw new StoreException(
                "Store::table() returns the raw OpenSwoole\\Table — only available on the 'table' backend (current: " . self::$backendConfig['kind'] . ")"
            );
        }
        return $backend->rawTable($name);
    }

    /** @param array<string, scalar> $row */
    public static function set(string $name, string $key, array $row): bool
    {
        return self::defaultBackend()->set($name, $key, $row);
    }

    /**
     * Returns the row array when `$field` is null, the field's scalar
     * value when set, or `false` on miss (legacy BC — `Store::get` has
     * always returned `false` for missing keys; existing callers narrow
     * via `is_array()` / `is_scalar()` and depend on that contract).
     */
    public static function get(string $name, string $key, ?string $field = null): mixed
    {
        $v = self::defaultBackend()->get($name, $key, $field);
        return $v === null ? false : $v;
    }

    public static function del(string $name, string $key): bool
    {
        return self::defaultBackend()->del($name, $key);
    }

    public static function exists(string $name, string $key): bool
    {
        return self::defaultBackend()->exists($name, $key);
    }

    public static function incr(string $name, string $key, string $col, int $by = 1): int
    {
        $r = self::defaultBackend()->incr($name, $key, $col, $by);
        return is_int($r) ? $r : (int) $r;
    }

    public static function decr(string $name, string $key, string $col, int $by = 1): int
    {
        $r = self::defaultBackend()->decr($name, $key, $col, $by);
        return is_int($r) ? $r : (int) $r;
    }

    public static function count(string $name): int
    {
        return self::defaultBackend()->count($name);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::defaultBackend()->names();
    }

    /** @return \Generator<string, array<string, scalar>> */
    public static function iterate(string $name): \Generator
    {
        yield from self::defaultBackend()->iterate($name);
    }

    public static function clear(string $name): void
    {
        self::defaultBackend()->clear($name);
    }

    /**
     * Bulk read. Missing keys come back as `null` in the result map.
     *
     * @param  list<string> $keys
     * @return array<string, array<string, scalar>|null>
     */
    public static function mget(string $name, array $keys): array
    {
        return self::defaultBackend()->mget($name, $keys);
    }

    /** @param array<string, array<string, scalar>> $rows */
    public static function mset(string $name, array $rows): bool
    {
        return self::defaultBackend()->mset($name, $rows);
    }

    /**
     * Health check. True on Table backend (always reachable); on Redis,
     * PINGs the pool and returns the result.
     */
    public static function ping(): bool
    {
        $b = self::defaultBackend();
        return $b instanceof RedisBackend ? $b->ping() : true;
    }
}
