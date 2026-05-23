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

    /** Origin tag stamped on every invalidation publish — the receiver skips messages
     *  that originated from itself so writers don't re-evict their own freshly-written L1. */
    private string $originId;
    /** Per-table set of channels we've registered an invalidation subscriber on. */
    /** @var array<string, true> */
    private array $invalidationChannels = [];
    private ?RedisPubSub $invalidationRunner = null;

    public function __construct(
        private TableBackend $l1,
        private RedisBackend $l2,
        private int          $l1Ttl = 5,
        ?string $originId = null,
    ) {
        if ($l1Ttl < 1) {
            throw new StoreException('TieredBackend l1Ttl must be >= 1 second');
        }
        $this->originId = $originId ?? gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    public function l1(): TableBackend { return $this->l1; }
    public function l2(): RedisBackend { return $this->l2; }
    public function l1Ttl(): int       { return $this->l1Ttl; }
    public function originId(): string { return $this->originId; }

    /**
     * Turn on cross-node L1 invalidation. After this, every write through
     * TieredBackend (set/del/incr/decr/clear) PUBLISHes an origin-tagged
     * invalidation message on `{prefix}:__l1_invalidate:{table}`; peer
     * TieredBackend instances on other nodes receive it and evict the
     * matching L1 entry. Self-publishes are skipped via the origin tag.
     *
     * MUST be called inside a coroutine context (spawns the subscriber cor).
     * Idempotent — repeated calls are no-ops; new tables registered after
     * enable() automatically subscribe themselves.
     */
    public function enableInvalidation(): void
    {
        if ($this->invalidationRunner !== null) { return; }
        $this->invalidationRunner = new RedisPubSub($this->l2->url(), $this->l2->prefix());
        // Pre-register subscribers for any tables already made().
        foreach ($this->invalidationChannels as $channel => $_) {
            $this->invalidationRunner->register((string) $channel, $this->invalidationHandler());
        }
        $this->invalidationRunner->start();
    }

    public function stopInvalidation(): void
    {
        if ($this->invalidationRunner === null) { return; }
        $this->invalidationRunner->stop();
        $this->invalidationRunner = null;
    }

    /** Handler that evicts the local L1 entry for the message's key (unless self-publish). */
    private function invalidationHandler(): callable
    {
        return function (string $payload) {
            $msg = json_decode($payload, true);
            if (!is_array($msg)) { return; }
            $origin = is_string($msg['origin'] ?? null) ? $msg['origin'] : '';
            if ($origin === $this->originId) { return; }      // skip self-publishes
            $name = is_string($msg['table'] ?? null) ? $msg['table'] : '';
            $key  = is_string($msg['key']   ?? null) ? $msg['key']   : '';
            if ($name === '' || $key === '') { return; }
            $this->l1->del($name, $key);
        };
    }

    /** Publish an invalidation marker after a successful L2 write. */
    private function publishInvalidation(string $table, string $key): void
    {
        if (!isset($this->invalidationChannels[$this->channel($table)])) { return; }
        try {
            $this->l2->publish($this->channel($table), (string) json_encode([
                'table'  => $table,
                'key'    => $key,
                'origin' => $this->originId,
            ]));
        } catch (\Throwable $e) {
            // Best-effort — invalidation drop just means peers stay stale up
            // to $l1Ttl. Don't propagate to the caller's write.
        }
    }

    private function channel(string $table): string
    {
        return '__l1_invalidate:' . $table;
    }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $l1Columns = $columns + [self::CACHED_AT => [Table::TYPE_INT, 8]];
        $this->l1->make($name, $maxRows, $l1Columns, $opts);
        $this->l2->make($name, $maxRows, $columns, $opts);
        // Track the invalidation channel for this table; if the runner is
        // already up, register the subscriber now.
        $this->invalidationChannels[$this->channel($name)] = true;
        if ($this->invalidationRunner !== null) {
            $this->invalidationRunner->register($this->channel($name), $this->invalidationHandler());
        }
    }

    public function set(string $name, string $key, array $row): bool
    {
        $ok = $this->l2->set($name, $key, $row);
        if ($ok) {
            $this->l1->set($name, $key, $row + [self::CACHED_AT => time()]);
            $this->publishInvalidation($name, $key);
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
        $ok = $this->l2->del($name, $key);
        if ($ok) { $this->publishInvalidation($name, $key); }
        return $ok;
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
        $this->publishInvalidation($name, $key);
        return $new;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $new = $this->l2->decr($name, $key, $col, $by);
        $this->l1->del($name, $key);
        $this->publishInvalidation($name, $key);
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
