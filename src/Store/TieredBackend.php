<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Table;

/**
 * Three-tier-ready hybrid backend — `TableBackend` as L1 (in-process,
 * ns latency, bounded staleness) + `RedisBackend` as L2 (cross-node,
 * source of truth, ~µs to ~ms).
 *
 * Read path: L1 first; if entry is fresh (within `$l1Ttl` seconds), return
 * it. Otherwise fetch from L2, populate L1, return.
 *
 * Write path: write-through to L2 (source of truth), then refresh the L1
 * entry. Concurrent writers across nodes converge at L2; local L1 stays
 * bounded-stale by `$l1Ttl`. For instant cross-node L1 eviction layer
 * `RedisPubSub` invalidation on top (`L1InvalidationConsumer`, separate
 * file — Phase 3).
 *
 * Staleness bookkeeping: every L1 row gets a synthetic `__cached_at` INT
 * column appended to the schema at `make()` time. The L1 schema is
 * `$columns + ['__cached_at' => [TYPE_INT, 8]]`. The user-facing
 * `get()` strips this column before returning.
 *
 * Use when you want: hot keys with ns reads, cross-node visibility for
 * cold keys, can tolerate bounded staleness. Pick the underlying backends
 * to match your throughput vs durability needs.
 */
final class TieredBackend implements StoreBackend
{
    private const CACHED_AT = '__cached_at';

    public function __construct(
        private TableBackend $l1,
        private RedisBackend $l2,
        private int          $l1Ttl = 5,
    ) {
        if ($l1Ttl < 1) {
            throw new StoreException('TieredBackend l1Ttl must be >= 1 second');
        }
    }

    public function l1(): TableBackend { return $this->l1; }
    public function l2(): RedisBackend { return $this->l2; }
    public function l1Ttl(): int       { return $this->l1Ttl; }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $l1Columns = $columns + [self::CACHED_AT => [Table::TYPE_INT, 8]];
        $this->l1->make($name, $maxRows, $l1Columns, $opts);
        $this->l2->make($name, $maxRows, $columns, $opts);
    }

    public function set(string $name, string $key, array $row): bool
    {
        $ok = $this->l2->set($name, $key, $row);
        if ($ok) {
            $this->l1->set($name, $key, $row + [self::CACHED_AT => time()]);
        }
        return $ok;
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $now = time();
        $l1Row = $this->l1->get($name, $key);
        if (is_array($l1Row)) {
            $cachedAt = is_numeric($l1Row[self::CACHED_AT] ?? null) ? (int) $l1Row[self::CACHED_AT] : 0;
            if ($cachedAt > 0 && ($now - $cachedAt) < $this->l1Ttl) {
                unset($l1Row[self::CACHED_AT]);
                return $field !== null ? ($l1Row[$field] ?? null) : $l1Row;
            }
        }
        // L1 miss or stale — fetch from L2, repopulate L1.
        $l2Row = $this->l2->get($name, $key);
        if (is_array($l2Row)) {
            $this->l1->set($name, $key, self::scalarRow($l2Row) + [self::CACHED_AT => $now]);
            return $field !== null ? ($l2Row[$field] ?? null) : $l2Row;
        }
        return $field !== null ? null : $l2Row;
    }

    /**
     * Narrow an opaque row to array<string, scalar> for the L1 schema.
     * Non-scalar values are stringified; nulls become ''.
     *
     * @param  array<int|string, mixed> $row
     * @return array<string, scalar>
     */
    private static function scalarRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $sk = (string) $k;
            if (is_scalar($v)) { $out[$sk] = $v; continue; }
            if ($v === null)   { $out[$sk] = ''; continue; }
            $out[$sk] = is_object($v) && method_exists($v, '__toString') ? (string) $v : '';
        }
        return $out;
    }

    public function del(string $name, string $key): bool
    {
        $this->l1->del($name, $key);
        return $this->l2->del($name, $key);
    }

    public function exists(string $name, string $key): bool
    {
        // L1 might lie about existence (stale entry); always defer to L2.
        return $this->l2->exists($name, $key);
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $new = $this->l2->incr($name, $key, $col, $by);
        // L1 might hold a stale view of this row; evict so the next get
        // re-fetches the authoritative value from L2.
        $this->l1->del($name, $key);
        return $new;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $new = $this->l2->decr($name, $key, $col, $by);
        $this->l1->del($name, $key);
        return $new;
    }

    public function count(string $name): int
    {
        // L1 is a partial cache, not authoritative. Always count L2.
        return $this->l2->count($name);
    }

    public function iterate(string $name): \Generator
    {
        // Iterate L2 — it's authoritative and complete.
        yield from $this->l2->iterate($name);
    }

    public function clear(string $name): void
    {
        $this->l1->clear($name);
        $this->l2->clear($name);
    }

    public function names(): array
    {
        return $this->l2->names();
    }

    public function mget(string $name, array $keys): array
    {
        $now = time();
        $out = [];
        $missing = [];
        foreach ($keys as $key) {
            $l1Row = $this->l1->get($name, $key);
            if (is_array($l1Row)) {
                $cachedAt = is_numeric($l1Row[self::CACHED_AT] ?? null) ? (int) $l1Row[self::CACHED_AT] : 0;
                if ($cachedAt > 0 && ($now - $cachedAt) < $this->l1Ttl) {
                    unset($l1Row[self::CACHED_AT]);
                    $out[$key] = self::scalarRow($l1Row);
                    continue;
                }
            }
            $missing[] = $key;
            $out[$key] = null;
        }
        if ($missing === []) { return $out; }
        // Fetch the misses from L2 in one round-trip, repopulate L1.
        $l2Rows = $this->l2->mget($name, $missing);
        foreach ($missing as $key) {
            $row = $l2Rows[$key] ?? null;
            if (is_array($row)) {
                $scalar = self::scalarRow($row);
                $this->l1->set($name, $key, $scalar + [self::CACHED_AT => $now]);
                $out[$key] = $scalar;
            }
        }
        return $out;
    }

    public function mset(string $name, array $rows): bool
    {
        $ok = $this->l2->mset($name, $rows);
        if ($ok) {
            $now = time();
            $l1Rows = [];
            foreach ($rows as $key => $row) {
                $l1Rows[(string) $key] = $row + [self::CACHED_AT => $now];
            }
            $this->l1->mset($name, $l1Rows);
        }
        return $ok;
    }
}
