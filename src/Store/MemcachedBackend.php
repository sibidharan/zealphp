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

    public function set(string $name, string $key, array $row): bool
    {
        $this->assertMade($name);
        $ttl = $this->tableOpts[$name]['ttl'];
        return $this->client->set($this->rowKey($name, $key), $row, $ttl);
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $this->assertMade($name);
        /** @var mixed $raw */
        $raw = $this->client->get($this->rowKey($name, $key));
        if (!is_array($raw)) { return null; }
        if ($field !== null) {
            return $raw[$field] ?? null;
        }
        return $raw;
    }

    public function del(string $name, string $key): bool
    {
        $this->assertMade($name);
        return $this->client->delete($this->rowKey($name, $key));
    }

    public function exists(string $name, string $key): bool
    {
        $this->assertMade($name);
        /** @var mixed $r */
        $r = $this->client->get($this->rowKey($name, $key));
        // Memcached returns false on miss. Distinguish from a stored
        // `false` value (which Cache never stores at the row level — rows
        // are always arrays here).
        return $r !== false;
    }

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

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        return $this->incr($name, $key, $col, -$by);
    }

    public function count(string $name): int
    {
        throw new StoreException(
            "MemcachedBackend::count: Memcached has no native SCAN — count is not supported. " .
            "Use Redis backend for iteration workloads, or maintain your own cardinality counter."
        );
    }

    public function iterate(string $name): \Generator
    {
        throw new StoreException(
            "MemcachedBackend::iterate: Memcached has no native SCAN — iteration is not supported. " .
            "Use Redis backend for iterable Store tables."
        );
    }

    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        throw new StoreException(
            "MemcachedBackend::iteratePaged: Memcached has no native SCAN. " .
            "Use Redis backend for paginated Store iteration."
        );
    }

    public function clear(string $name): void
    {
        throw new StoreException(
            "MemcachedBackend::clear: would need to track every key written; not supported. " .
            "Use the `flush_all` Memcached admin command, or set per-table TTLs and let them expire."
        );
    }

    public function names(): array
    {
        return array_keys($this->schemas);
    }

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
            $out[$k] = is_array($v) ? $this->normalizeRow($v) : null;
        }
        return $out;
    }

    public function mset(string $name, array $rows): bool
    {
        $this->assertMade($name);
        if ($rows === []) { return true; }
        $ttl   = $this->tableOpts[$name]['ttl'];
        /** @var array<string, array<string, scalar>> $batch */
        $batch = [];
        foreach ($rows as $key => $row) {
            $batch[$this->rowKey($name, (string) $key)] = $row;
        }
        // setMulti is one round-trip + atomic per-key (not across keys).
        return $this->client->setMulti($batch, $ttl);
    }

    public function ping(): bool
    {
        // getStats() returns the per-server stats map. PHPStan's stub
        // narrows it to a non-empty array, but at runtime an unreachable
        // server gives back an empty array — that's our "down" signal.
        return $this->client->getStats() !== [];
    }

    public function client(): \Memcached { return $this->client; }
    public function prefix(): string { return $this->prefix; }

    /**
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

    private function rowKey(string $table, string $key): string
    {
        // Memcached key max is 250 chars and bans control chars. Hash long
        // composite keys to keep within bounds.
        $raw = $this->prefix . ':' . $table . ':' . $key;
        return strlen($raw) > 240 ? $this->prefix . ':' . $table . ':' . sha1($raw) : $raw;
    }

    /**
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

    private function assertMade(string $name): void
    {
        if (!isset($this->schemas[$name])) {
            throw new StoreException("MemcachedBackend: table not registered: $name");
        }
    }
}
