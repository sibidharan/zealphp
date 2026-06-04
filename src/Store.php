<?php

declare(strict_types=1);

namespace ZealPHP;

use OpenSwoole\Table;
use ZealPHP\Store\CircuitBreakerBackend;
use ZealPHP\Store\DriverPreference;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\StoreBackendKind;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;

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

    // Backend kind constants — prefer over bare strings:
    //   Store::defaultBackend(Store::BACKEND_REDIS)  ← IDE-autocompleted, refactor-safe
    //   Store::defaultBackend('redis')              ← also works (BC).
    public const BACKEND_TABLE     = 'table';
    public const BACKEND_REDIS     = 'redis';
    public const BACKEND_TIERED    = 'tiered';
    public const BACKEND_MEMCACHED = 'memcached';

    // Driver-prefer constants for $conn['prefer'] / ZEALPHP_REDIS_PREFER:
    public const PREFER_AUTO     = 'auto';
    public const PREFER_PHPREDIS = 'phpredis';
    public const PREFER_PREDIS   = 'predis';

    private static ?StoreBackend $backend = null;
    /** @var array{kind:string, conn?: string|array<string,mixed>} */
    private static array $backendConfig = ['kind' => 'table'];

    /**
     * Get or set the process-wide default backend.
     *
     * Prefers `StoreBackendKind` (type-safe enum) but accepts the bare
     * string literal forms for BC: `'table'` / `'redis'`.
     *
     * @param  StoreBackendKind|string|null     $kind  enum or bare string; null to read current
     * @param  string|array<string,mixed>       $conn  redis URL string OR ['url'=>, 'pool_size'=>, 'prefix'=>, 'prefer'=>]
     */
    public static function defaultBackend(StoreBackendKind|string|null $kind = null, string|array $conn = []): StoreBackend
    {
        if ($kind !== null) {
            $kindStr = StoreBackendKind::coerce($kind)->value;
            self::$backendConfig = ['kind' => $kindStr, 'conn' => $conn];
            self::$backend = null;
        }
        return self::$backend ??= self::buildBackend(self::$backendConfig);
    }

    /** @param array{kind:string, conn?: string|array<string,mixed>} $cfg */
    private static function buildBackend(array $cfg): StoreBackend
    {
        if ($cfg['kind'] === 'tiered') {
            return self::buildTieredBackend($cfg['conn'] ?? []);
        }
        if ($cfg['kind'] === 'memcached') {
            $conn = $cfg['conn'] ?? '';
            if (is_string($conn)) {
                $servers = $conn !== '' ? $conn : self::memcachedServersFromEnv();
                return new \ZealPHP\Store\MemcachedBackend($servers);
            }
            $servers = isset($conn['servers']) && is_string($conn['servers']) ? $conn['servers'] : self::memcachedServersFromEnv();
            $prefix  = isset($conn['prefix']) && is_string($conn['prefix']) ? $conn['prefix'] : 'zealstore';
            return new \ZealPHP\Store\MemcachedBackend($servers, $prefix);
        }
        if ($cfg['kind'] !== 'redis') {
            return new TableBackend();
        }
        $conn = $cfg['conn'] ?? [];
        if (is_string($conn)) {
            $url  = $conn !== '' ? $conn : self::redisUrlFromEnv();
            $opts = self::poolOptsFromEnv();
            return new RedisBackend(new RedisConnectionPool($url, 8, $opts));
        }
        $url    = isset($conn['url']) && is_string($conn['url']) ? $conn['url'] : self::redisUrlFromEnv();
        $size   = isset($conn['pool_size']) && is_int($conn['pool_size']) ? $conn['pool_size'] : 8;
        $prefix = isset($conn['prefix']) && is_string($conn['prefix']) ? $conn['prefix'] : 'zealstore';
        $opts   = self::poolOptsFromEnv();
        // Allow ['prefer' => 'phpredis'|'predis'|'auto'] to override the env default.
        if (isset($conn['prefer'])) {
            // Accept either the enum or the bare string — both routes go
            // through DriverPreference::coerce.
            try {
                $opts['prefer'] = DriverPreference::coerce(
                    $conn['prefer'] instanceof DriverPreference || is_string($conn['prefer'])
                        ? $conn['prefer']
                        : '',
                )->value;
            } catch (\InvalidArgumentException) {
                /* silently fall back to env-default — invalid prefer is non-fatal */
            }
        }
        $backend = new RedisBackend(new RedisConnectionPool($url, $size, $opts), $prefix);

        // H4 — opt-in circuit breaker. When the user passes
        //   ['on_error' => 'fallback_table']  (optionally with a 'breaker'
        //   sub-array tuning the thresholds), wrap the Redis backend in a
        //   CircuitBreakerBackend whose fallback is a fresh TableBackend.
        // Default behaviour (no opt) — no decoration, throws on Redis down.
        $onError = $conn['on_error'] ?? null;
        if ($onError === 'fallback_table') {
            $breakerOpts = isset($conn['breaker']) && is_array($conn['breaker']) ? $conn['breaker'] : [];
            $threshold   = isset($breakerOpts['failure_threshold']) && is_int($breakerOpts['failure_threshold']) ? $breakerOpts['failure_threshold'] : 5;
            $windowSec   = isset($breakerOpts['failure_window_sec']) && is_int($breakerOpts['failure_window_sec']) ? $breakerOpts['failure_window_sec'] : 10;
            $openSec     = isset($breakerOpts['open_seconds']) && is_int($breakerOpts['open_seconds']) ? $breakerOpts['open_seconds'] : 30;
            return new CircuitBreakerBackend(
                primary:          $backend,
                fallback:         new TableBackend(),
                failureThreshold: $threshold,
                failureWindowSec: $windowSec,
                openDurationSec:  $openSec,
            );
        }
        return $backend;
    }

    /**
     * Tiered backend facade: L1=TableBackend (in-process, ns latency) +
     * L2=RedisBackend (cross-node, source of truth). The L2 build path
     * reuses the same conn-opts shape as `'redis'` so users only need to
     * learn one config dialect.
     *
     * Recognised opts:
     *   - 'url' / pool_size / prefix / prefer  → forwarded to the L2 RedisBackend (same as 'redis')
     *   - 'l1_ttl' (int seconds, default 5)    → L1 freshness window
     *   - 'invalidation_secret' (string|null)  → cross-node L1 invalidation HMAC secret
     *                                            (defaults to env ZEALPHP_TIERED_INVALIDATION_SECRET)
     *
     * @param string|array<string,mixed> $conn
     */
    private static function buildTieredBackend(string|array $conn): TieredBackend
    {
        // Re-use the 'redis' building blocks to build L2 — same conn shape.
        $url    = is_string($conn) ? ($conn !== '' ? $conn : self::redisUrlFromEnv())
                                   : (isset($conn['url']) && is_string($conn['url']) ? $conn['url'] : self::redisUrlFromEnv());
        $size   = is_array($conn) && isset($conn['pool_size']) && is_int($conn['pool_size']) ? $conn['pool_size'] : 8;
        $prefix = is_array($conn) && isset($conn['prefix']) && is_string($conn['prefix']) ? $conn['prefix'] : 'zealstore';
        $opts   = self::poolOptsFromEnv();
        if (is_array($conn) && isset($conn['prefer'])) {
            try {
                $opts['prefer'] = DriverPreference::coerce(
                    $conn['prefer'] instanceof DriverPreference || is_string($conn['prefer'])
                        ? $conn['prefer']
                        : '',
                )->value;
            } catch (\InvalidArgumentException) { /* fall back to env-default */ }
        }
        $l2 = new RedisBackend(new RedisConnectionPool($url, $size, $opts), $prefix);

        $l1Ttl  = is_array($conn) && isset($conn['l1_ttl']) && is_int($conn['l1_ttl']) ? $conn['l1_ttl'] : 5;
        $secret = is_array($conn) && isset($conn['invalidation_secret']) && is_string($conn['invalidation_secret']) ? $conn['invalidation_secret'] : null;

        return new TieredBackend(new TableBackend(), $l2, l1Ttl: $l1Ttl, invalidationSecret: $secret);
    }

    private static function redisUrlFromEnv(): string
    {
        $env = getenv('ZEALPHP_REDIS_URL');
        return is_string($env) && $env !== '' ? $env : 'redis://127.0.0.1:6379';
    }

    private static function memcachedServersFromEnv(): string
    {
        $env = getenv('ZEALPHP_MEMCACHED_SERVERS');
        return is_string($env) && $env !== '' ? $env : '127.0.0.1:11211';
    }

    /**
     * Build pool options from environment. ZEALPHP_REDIS_PREFER picks the
     * client lib — 'auto' (default), 'phpredis', or 'predis'. Use 'predis'
     * for pub/sub subscribers in production until the phpredis SUBSCRIBE +
     * HOOK_ALL spike has been benched in your environment.
     *
     * @return array{prefer?: 'auto'|'phpredis'|'predis'}
     */
    private static function poolOptsFromEnv(): array
    {
        $prefer = getenv('ZEALPHP_REDIS_PREFER');
        if (!is_string($prefer) || $prefer === '') { return []; }
        $prefer = strtolower($prefer);
        if (!in_array($prefer, ['auto', 'phpredis', 'predis'], true)) { return []; }
        return ['prefer' => $prefer];
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
        // Route hot-reload re-includes route/*.php (which often call Store::make).
        // A Store table is allocated once at boot (shared memory, master-created);
        // do NOT recreate it on reload — return the existing one.
        if (\ZealPHP\App::$reloading) {
            return $backend instanceof TableBackend ? $backend->rawTable($name) : null;
        }
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

    /**
     * Strict variant of get() — returns null on miss instead of false.
     *
     * Recommended for new code. The legacy `get()` keeps returning `false`
     * on miss for BC with code written before v0.2.39 that uses `=== false`
     * to detect misses; that BC contract is permanent.
     *
     * New code that wants the unambiguous null-or-value contract — e.g. to
     * use `??`-style fallbacks safely with stored falsy values — should
     * call `getStrict()` instead.
     *
     * Returns null on miss; the scalar when `$field` is set and the row exists;
     * the row array (`array<string, scalar>`) otherwise.
     */
    public static function getStrict(string $name, string $key, ?string $field = null): mixed
    {
        return self::defaultBackend()->get($name, $key, $field);
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

    // ── Direct Redis SET primitives (WS-2) ──────────────────────────────
    //
    // SADD / SREM / SCARD / SSCAN — usable from user code AND by the WS
    // Room class for per-room rosters. Backend semantics:
    //   - RedisBackend  : native SET ops (O(1) SCARD, paginated SSCAN).
    //   - TieredBackend : delegates to the L2 RedisBackend.
    //   - TableBackend  : throws StoreException — these are inherently
    //                     cross-node primitives. Use the existing
    //                     iterate()/count() against a tracked table on
    //                     Table; the user-facing Room class auto-detects
    //                     and falls back.
    //
    // Keys here are NOT prefixed by Store; caller owns the namespace.

    public static function sadd(string $key, string ...$members): int
    {
        $b = self::redisOrThrow('sadd');
        return $b->sadd($key, ...$members);
    }

    public static function srem(string $key, string ...$members): int
    {
        $b = self::redisOrThrow('srem');
        return $b->srem($key, ...$members);
    }

    public static function scard(string $key): int
    {
        $b = self::redisOrThrow('scard');
        return $b->scard($key);
    }

    /**
     * Run a Lua script atomically on the Redis backend. KEYS are raw /
     * absolute (un-prefixed). Values are passed as `KEYS`/`ARGV` (never
     * interpolated into the script body). Throws on the Table backend.
     *
     * @param  list<string> $keys
     * @param  list<string> $args
     */
    public static function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return self::redisOrThrow('eval')->eval($script, $keys, $args);
    }

    /**
     * Paginated SSCAN. Returns one batch + an opaque next-cursor — same
     * cursor protocol as `iteratePaged`. Cursor '0' starts a fresh scan;
     * a returned cursor of '0' signals end-of-scan.
     *
     * @return array{cursor: string, members: list<string>}
     */
    public static function sscanCursor(string $key, string $cursor = '0', int $count = 100): array
    {
        $b = self::redisOrThrow('sscanCursor');
        [$next, $members] = $b->sscanCursor($key, $cursor, $count);
        return ['cursor' => $next, 'members' => $members];
    }

    public static function sdel(string $key): bool
    {
        $b = self::redisOrThrow('sdel');
        return $b->sdel($key);
    }

    /**
     * Resolve the active backend to a RedisBackend (directly, or as the L2
     * of a Tiered/CircuitBreaker decorator). Throws when the active backend
     * is Table — set ops require cross-node Redis primitives.
     */
    private static function redisOrThrow(string $op): RedisBackend
    {
        $b = self::defaultBackend();
        if ($b instanceof RedisBackend) { return $b; }
        if ($b instanceof TieredBackend) { return $b->l2(); }
        throw new StoreException(
            "Store::$op requires the Redis or Tiered backend (current: " . self::$backendConfig['kind'] . "). " .
            "Set ops are inherently cross-node primitives; use iterate()/count() against a tracked table on the Table backend."
        );
    }

    /**
     * True when the active backend supports direct SET primitives
     * (sadd/srem/scard/sscanCursor/sdel). Use as the BC-safe guard before
     * calling Set ops from generic / backend-portable code.
     */
    public static function hasSetOps(): bool
    {
        $b = self::defaultBackend();
        return $b instanceof RedisBackend || $b instanceof TieredBackend;
    }

    /**
     * S-1 — execute a Redis Lua script atomically. Redis executes EVAL
     * server-side as a single atomic operation: no other client can
     * observe an intermediate state, no other command interleaves.
     * This is the canonical "transaction" primitive on Redis — every
     * MULTI/EXEC + WATCH pattern has a more efficient Lua equivalent.
     *
     * Use cases:
     *   - Atomic "get current value, derive new value, set" without
     *     race windows (the CAS pattern, server-side).
     *   - Multi-key atomic updates (the MULTI/EXEC use case).
     *   - Conditional ops ("set only if condition holds").
     *
     *     // Atomic compare-and-swap on a hash field:
     *     Store::evalScript(
     *         "local v = redis.call('HGET', KEYS[1], ARGV[1]); " .
     *         "if v == ARGV[2] then return redis.call('HSET', KEYS[1], ARGV[1], ARGV[3]); end; " .
     *         "return 0;",
     *         ['zealstore:mytable:rowkey'],
     *         ['col', 'expected_old', 'new_value'],
     *     );
     *
     * Throws StoreException on Table backend (Lua is Redis-server-side).
     *
     * MULTI/EXEC + WATCH via the driver protocol is intentionally NOT
     * exposed yet — the Lua approach above covers every documented use
     * case more atomically (one round-trip, server-atomic) and works on
     * both drivers without protocol-level glue. If your workload genuinely
     * needs the deferred-pipeline shape, file an issue with the use case.
     *
     * @param  array<int, string> $keys  Redis keys the script accesses (cluster-routing hint)
     * @param  array<int, string> $args  ARGV values
     */
    public static function evalScript(string $script, array $keys = [], array $args = []): mixed
    {
        $b = self::redisOrThrow('evalScript');
        return $b->pool()->with(fn(\ZealPHP\Store\RedisClient $c): mixed => $c->evalScript($script, $keys, $args));
    }

    /**
     * S-2 — optimistic compare-and-swap on a single Store row+column.
     * Atomic across nodes (Lua-backed); returns true if the swap landed
     * (current value matched `$expected`), false otherwise.
     *
     *     // Increment 'hits' by 1 only if it's still at 42:
     *     Store::compareAndSet('counters', 'user:42', 'hits', '42', '43');
     *
     * Use when the "natural" Counter::compareAndSet shape doesn't fit
     * (e.g., a Store row with mixed-type columns, not a standalone counter).
     * Throws on Table backend.
     */
    public static function compareAndSet(string $table, string $key, string $field, string $expected, string $new): bool
    {
        $b = self::redisOrThrow('compareAndSet');
        $prefix = $b->prefix();
        $rowKey = $prefix . ':' . $table . ':' . $key;
        $r = self::evalScript(
            "local v = redis.call('HGET', KEYS[1], ARGV[1]); " .
            "if v == ARGV[2] then redis.call('HSET', KEYS[1], ARGV[1], ARGV[3]); return 1; end; " .
            "return 0;",
            [$rowKey],
            [$field, $expected, $new],
        );
        return is_int($r) ? $r === 1 : (is_string($r) && $r === '1');
    }

    /**
     * Paginated iteration (S-3). Returns one batch + an opaque next-cursor.
     * Use for large tables where draining the full generator is impractical
     * (e.g. paginated UI over a 100k-member room roster). When the returned
     * `cursor` is `'0'` the scan is complete.
     *
     *     $next = '0';
     *     do {
     *         $page = Store::iteratePaged('rooms:lobby:members', $next, 100);
     *         foreach ($page['rows'] as $key => $row) { ... }
     *         $next = $page['cursor'];
     *     } while ($next !== '0');
     *
     * Cursors are NOT stable across schema changes or clear(); resume
     * within a single logically-consistent window. See StoreBackend
     * interface for full contract.
     *
     * @return array{cursor: string, rows: array<string, array<string, scalar>>}
     */
    public static function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        return self::defaultBackend()->iteratePaged($name, $cursor, $count);
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

    /**
     * Fire-and-forget Redis pub/sub.
     *
     * **Scope = the entire cluster.** Every app instance connected to
     * the same Redis (every ZealPHP process on every host) that has
     * called `App::subscribe('<this-channel>', ...)` receives the
     * message. That's Redis pub/sub's native PUBLISH semantics —
     * there's no "local mode" or per-host limiting. If you only want a
     * specific server to receive the message, route by channel name
     * (e.g. `ws:server:<server-id>` — the pattern `WSRouter::sendToClient`
     * uses).
     *
     * Returns the receiver count Redis itself reported — typically
     * `(subscribed workers per instance) × (instances in the cluster)`.
     * A return of 0 means no subscriber was listening when the message
     * was published. Throws `StoreException` when the default backend
     * is not Redis (Table has no pub/sub semantics).
     *
     * Pair with `App::subscribe()` to register handlers. Messages
     * published while a subscriber is mid-reconnect are **LOST** —
     * use `publishReliable()` for at-least-once delivery via Streams.
     */
    public static function publish(string $channel, string $payload): int
    {
        $b = self::defaultBackend();
        if (!($b instanceof RedisBackend)) {
            throw new StoreException("Store::publish requires the redis backend (current: " . self::$backendConfig['kind'] . ")");
        }
        return $b->publish($channel, $payload);
    }

    /**
     * Per-worker operational stats — pool acquires, timeouts, clients
     * created. Empty array on the Table backend (no stats surface).
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $b = self::defaultBackend();
        if ($b instanceof RedisBackend) {
            return $b->pool()->stats()->snapshot();
        }
        return [];
    }

    /**
     * Reliable variant via Redis Streams (XADD). Returns the Redis-generated
     * message ID. Durable when Redis has AOF/RDB; at-least-once delivery
     * via consumer groups (one consumer per worker by default).
     *
     * Pair with App::subscribeReliable() to register a consumer group
     * handler. Throws StoreException when backend is not Redis.
     */
    public static function publishReliable(string $stream, string $payload, ?int $maxLen = null): string
    {
        $b = self::defaultBackend();
        if (!($b instanceof RedisBackend)) {
            throw new StoreException("Store::publishReliable requires the redis backend (current: " . self::$backendConfig['kind'] . ")");
        }
        return $b->publishReliable($stream, $payload, $maxLen);
    }
}
