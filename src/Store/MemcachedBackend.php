<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Table;

/**
 * Memcached-backed `StoreBackend`.
 *
 * Memcached is a flat KV with native atomic increment/decrement and TTL —
 * no hashes, no sets, no pub/sub, no Lua, no SCAN. This backend fits
 * cross-server cache-like workloads where Redis is overkill (or where
 * Memcached is the established infrastructure).
 *
 * Row layout: each Store row is serialized as a single Memcached value
 * keyed by `{prefix}:{table}:{key}`. Multi-column rows round-trip through
 * PHP serialize/unserialize.
 *
 * Security — object-injection defence: rows are stored as a `serialize()`d
 * STRING (bypassing ext-memcached's built-in PHP serializer, which would run
 * an unrestricted `unserialize()` on every `get()` and fire `__wakeup`/
 * `__destruct` of ANY class present in the payload). On read the string is
 * deserialized with `['allowed_classes' => false]`, so a hostile blob can only
 * round-trip to scalars/arrays — any object becomes `__PHP_Incomplete_Class`
 * and no magic methods run. This mirrors every other `unserialize()` site in
 * the codebase (`Cache`, `Session/utils.php`).
 *
 * Constraints surfaced as `StoreException`:
 *   - `iterate()`, `count()`, `clear()` — Memcached has no SCAN. Throws
 *     with a clear "use Redis for iteration workloads" message.
 *   - `sadd/srem/scard/sscanCursor/sdel` — no Set type.
 *   - `publish/publishReliable`/Lua — no pub/sub or scripting.
 *
 * Use cases that DO work end-to-end:
 *   - `Cache` (set/get/del/has + per-key TTL).
 *   - `Counter` (via the companion `MemcachedCounterBackend`).
 *   - Direct `Store::set/get/del/incr/decr/exists/mget/mset` calls.
 */
final class MemcachedBackend implements StoreBackend
{
    /** @var array<string, array<string, array{0:int, 1?:int}>> */
    private array $schemas = [];
    /** @var array<string, array{ttl:int}> */
    private array $tableOpts = [];

    private \Memcached $client;

    /**
     * @param string $servers comma-separated host[:port] list (default 11211 port)
     *                        e.g. "127.0.0.1" or "cache1:11211,cache2:11211"
     */
    public function __construct(
        string $servers = '127.0.0.1:11211',
        private string $prefix = 'zealstore',
    ) {
        if (!extension_loaded('memcached')) {
            throw new StoreException(
                'MemcachedBackend requires ext-memcached. Install via `pecl install memcached` ' .
                'OR `apt-get install php-memcached` (on Debian/Ubuntu).'
            );
        }
        $this->client = new \Memcached();
        $this->client->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        $this->client->setOption(\Memcached::OPT_TCP_NODELAY, true);
        foreach (self::parseServers($servers) as $hp) {
            $this->client->addServer($hp[0], $hp[1]);
        }
    }

    /**
     * Register a named table with its column schema. `$maxRows` is informational
     * only — Memcached is a global pool with server-side LRU eviction; no hard
     * cap is enforced here. Pass `$opts['ttl']` (int seconds, default `0` = no
     * expiry) to set a per-table TTL applied on every `set()` call.
     */
    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        if ($columns === []) {
            $columns = ['value' => [Table::TYPE_STRING, 256]];
        }
        $rawTtl = $opts['ttl'] ?? 0;
        $ttl = is_numeric($rawTtl) ? max(0, (int) $rawTtl) : 0;
        // $maxRows is informational only — Memcached is a global pool
        // with server-side eviction (LRU when memory caps hit). Not enforced.
        $this->schemas[$name]   = $columns;
        $this->tableOpts[$name] = ['ttl' => $ttl];
    }

    /**
     * Serialize and store a row in Memcached. The row is stored as a single
     * serialized value at `{prefix}:{table}:{key}`. The table-level TTL
     * (set via `make()` `$opts['ttl']`) is applied; `0` means no expiry.
     */
    public function set(string $name, string $key, array $row): bool
    {
        $this->assertMade($name);
        $ttl = $this->tableOpts[$name]['ttl'];
        return $this->client->set($this->rowKey($name, $key), self::encodeRow($row), $ttl);
    }

    /**
     * Retrieve a row or a single field from Memcached. Returns `null` on
     * miss. When `$field` is provided, returns the scalar value of that
     * column or `null` if the column is absent.
     */
    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $this->assertMade($name);
        /** @var mixed $raw */
        $raw = $this->client->get($this->rowKey($name, $key));
        $row = self::decodeRow($raw);
        if ($row === null) { return null; }
        if ($field !== null) {
            return $row[$field] ?? null;
        }
        return $row;
    }

    /** Delete a row from Memcached. Returns `true` when the key existed and was removed. */
    public function del(string $name, string $key): bool
    {
        $this->assertMade($name);
        return $this->client->delete($this->rowKey($name, $key));
    }

    /** Return `true` when a row exists in Memcached for the given key. */
    public function exists(string $name, string $key): bool
    {
        $this->assertMade($name);
        /** @var mixed $r */
        $r = $this->client->get($this->rowKey($name, $key));
        // Memcached returns false on miss. A present row is stored as a
        // non-false `serialize()`d string, so a strict !== false check is
        // an accurate existence signal.
        return $r !== false;
    }

    /**
     * Read-modify-write increment of column `$col` by `$by`.
     * NOT atomic across concurrent workers — Memcached has no native hash
     * `HINCRBY` equivalent. For cross-node atomic counters use `Counter`
     * with a `RedisCounterBackend` instead.
     */
    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $this->assertMade($name);
        $schema = $this->schemas[$name];
        // Memcached::increment only works on plain numeric keys; our rows
        // are serialized arrays. Read-modify-write under best-effort
        // semantics (no atomicity guarantee across multi-key races —
        // contrast with Redis HINCRBY which IS atomic). For atomic cross-
        // node counters, use the standalone Counter facade.
        $row = $this->get($name, $key);
        $row = is_array($row) ? $row : [];
        $type = $schema[$col][0] ?? Table::TYPE_INT;
        /** @var array<string, scalar> $row */
        $raw  = $row[$col] ?? 0;
        if ($type === Table::TYPE_FLOAT) {
            $cur = is_numeric($raw) ? (float) $raw : 0.0;
            $new = $cur + (float) $by;
        } else {
            $cur = is_numeric($raw) ? (int) $raw : 0;
            $new = $cur + (int) $by;
        }
        $row[$col] = $new;
        $this->set($name, $key, $row);
        return $new;
    }

    /** Decrement column `$col` by `$by` via `incr()` with a negated delta. Not atomic — see `incr()`. */
    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        return $this->incr($name, $key, $col, -$by);
    }

    /**
     * Not supported — Memcached has no native SCAN.
     * Throws `StoreException`. Use the Redis backend for iteration workloads,
     * or maintain your own cardinality counter.
     */
    public function count(string $name): int
    {
        throw new StoreException(
            "MemcachedBackend::count: Memcached has no native SCAN — count is not supported. " .
            "Use Redis backend for iteration workloads, or maintain your own cardinality counter."
        );
    }

    /**
     * Not supported — Memcached has no native SCAN.
     * Throws `StoreException`. Use the Redis backend for iterable Store tables.
     */
    public function iterate(string $name): \Generator
    {
        throw new StoreException(
            "MemcachedBackend::iterate: Memcached has no native SCAN — iteration is not supported. " .
            "Use Redis backend for iterable Store tables."
        );
    }

    /**
     * Not supported — Memcached has no native SCAN.
     * Throws `StoreException`. Use the Redis backend for paginated Store iteration.
     */
    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        throw new StoreException(
            "MemcachedBackend::iteratePaged: Memcached has no native SCAN. " .
            "Use Redis backend for paginated Store iteration."
        );
    }

    /**
     * Not supported — tracking every written key would be required.
     * Throws `StoreException`. Use the `flush_all` Memcached admin command,
     * or set per-table TTLs via `make()` and let entries expire naturally.
     */
    public function clear(string $name): void
    {
        throw new StoreException(
            "MemcachedBackend::clear: would need to track every key written; not supported. " .
            "Use the `flush_all` Memcached admin command, or set per-table TTLs and let them expire."
        );
    }

    /** Return the names of all tables registered via `make()`. */
    public function names(): array
    {
        return array_keys($this->schemas);
    }

    /**
     * Bulk read multiple rows in one `getMulti()` round-trip.
     * Missing keys are returned as `null` in the result map.
     */
    public function mget(string $name, array $keys): array
    {
        $this->assertMade($name);
        if ($keys === []) { return []; }
        $strKeys = array_map('strval', $keys);
        $mcKeys  = array_map(fn(string $k): string => $this->rowKey($name, $k), $strKeys);
        /** @var array<string, mixed>|false $raw */
        $raw = $this->client->getMulti($mcKeys);
        $rawArr = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($strKeys as $i => $k) {
            $mck = $mcKeys[$i];
            $v = $rawArr[$mck] ?? null;
            $decoded = self::decodeRow($v);
            $out[$k] = $decoded === null ? null : $this->normalizeRow($decoded);
        }
        return $out;
    }

    /**
     * Bulk write multiple rows via one `setMulti()` round-trip.
     * Atomicity is per-key (not across all keys). Returns `true` when
     * all writes succeeded.
     */
    public function mset(string $name, array $rows): bool
    {
        $this->assertMade($name);
        if ($rows === []) { return true; }
        $ttl   = $this->tableOpts[$name]['ttl'];
        /** @var array<string, string> $batch */
        $batch = [];
        foreach ($rows as $key => $row) {
            $batch[$this->rowKey($name, (string) $key)] = self::encodeRow($row);
        }
        // setMulti is one round-trip + atomic per-key (not across keys).
        return $this->client->setMulti($batch, $ttl);
    }

    /**
     * Ping the Memcached cluster. Returns `true` when at least one server
     * responded to `getStats()`. Returns `false` when all servers are unreachable.
     */
    public function ping(): bool
    {
        // getStats() returns the per-server stats map. PHPStan's stub
        // narrows it to a non-empty array, but at runtime an unreachable
        // server gives back an empty array — that's our "down" signal.
        return $this->client->getStats() !== [];
    }

    /** Return the underlying `\Memcached` client instance for direct use. */
    public function client(): \Memcached { return $this->client; }

    /** Return the key prefix used for all Memcached keys (e.g. `zealstore`). */
    public function prefix(): string { return $this->prefix; }

    /**
     * Parse a comma-separated host[:port] server list into an array of
     * `[host, port]` pairs. Defaults to port `11211` when omitted.
     * Falls back to `['127.0.0.1', 11211]` when `$servers` is empty.
     *
     * @return list<array{0:string, 1:int}>
     */
    private static function parseServers(string $servers): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $servers))) as $hp) {
            $parts = explode(':', $hp, 2);
            $host  = $parts[0];
            $port  = isset($parts[1]) ? max(1, (int) $parts[1]) : 11211;
            $out[] = [$host, $port];
        }
        if ($out === []) { $out[] = ['127.0.0.1', 11211]; }
        return $out;
    }

    /**
     * Build the Memcached key for a row. Keeps composite keys within
     * Memcached's 250-character limit by SHA1-hashing keys longer than 240 chars.
     */
    private function rowKey(string $table, string $key): string
    {
        // Memcached key max is 250 chars and bans control chars. Hash long
        // composite keys to keep within bounds.
        $raw = $this->prefix . ':' . $table . ':' . $key;
        return strlen($raw) > 240 ? $this->prefix . ':' . $table . ':' . sha1($raw) : $raw;
    }

    /**
     * Serialize a row to the string we store in Memcached. Stored as a
     * plain `serialize()` string (NOT handed to ext-memcached's serializer)
     * so reads can deserialize with an `allowed_classes` whitelist —
     * see `decodeRow()` and the class docblock's object-injection note.
     *
     * @param  array<string, scalar> $row
     */
    private static function encodeRow(array $row): string
    {
        return serialize($row);
    }

    /**
     * Safely deserialize a value read back from Memcached. Object injection
     * is blocked via `['allowed_classes' => false]`: any object in the blob
     * decodes to `__PHP_Incomplete_Class` and no `__wakeup`/`__destruct`
     * runs. Returns the decoded array, or `null` on a miss (`false` from
     * Memcached), a non-string value, or a payload that doesn't deserialize
     * to an array.
     *
     * @return array<int|string, mixed>|null
     */
    private static function decodeRow(mixed $raw): ?array
    {
        if (!is_string($raw)) { return null; }
        /** @var mixed $decoded */
        $decoded = unserialize($raw, ['allowed_classes' => false]);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Coerce a raw deserialized row to `array<string, scalar>`, dropping
     * any non-scalar values (which can't arise from `set()` but may appear
     * from external writes or future schema changes).
     *
     * @param  array<int|string, mixed> $row
     * @return array<string, scalar>
     */
    private function normalizeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (is_scalar($v)) { $out[(string) $k] = $v; }
        }
        return $out;
    }

    /**
     * Assert that a table has been registered via `make()`.
     * Throws `StoreException` when the table is unknown, surfacing the "call
     * `Store::make()` before `App::run()`" contract violation clearly.
     */
    private function assertMade(string $name): void
    {
        if (!isset($this->schemas[$name])) {
            throw new StoreException("MemcachedBackend: table not registered: $name");
        }
    }
}
