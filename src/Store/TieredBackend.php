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

    /**
     * Origin tag stamped on every invalidation publish — the receiver skips messages
     * that originated from itself so writers don't re-evict their own freshly-written L1.
     */
    private string $originId;

    /**
     * Channels (`__l1_invalidate:{table}`) for which a subscriber has been registered
     * on the `$invalidationRunner`.
     *
     * @var array<string, true>
     */
    private array $invalidationChannels = [];
    private ?RedisPubSub $invalidationRunner = null;
    /**
     * C2: Shared HMAC secret for invalidation message authentication.
     * NULL ⇒ insecure trust mode (any Redis writer can forge an evict).
     * Set this on every node in the cluster via either constructor arg
     * or ZEALPHP_TIERED_INVALIDATION_SECRET env var.
     */
    private ?string $invalidationSecret;

    public function __construct(
        private TableBackend $l1,
        private RedisBackend $l2,
        private int          $l1Ttl = 5,
        ?string $originId = null,
        ?string $invalidationSecret = null,
    ) {
        if ($l1Ttl < 1) {
            throw new StoreException('TieredBackend l1Ttl must be >= 1 second');
        }
        $this->originId = $originId ?? gethostname() . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        // Read secret from env if not explicitly passed. Empty env → null.
        if ($invalidationSecret === null) {
            $env = getenv('ZEALPHP_TIERED_INVALIDATION_SECRET');
            $invalidationSecret = (is_string($env) && $env !== '') ? $env : null;
        }
        $this->invalidationSecret = $invalidationSecret;
    }

    /** True if HMAC verification is active. */
    public function isInvalidationAuthenticated(): bool
    {
        return $this->invalidationSecret !== null;
    }

    /**
     * True once `enableInvalidation()` has spun up the cross-node L1
     * invalidation subscriber. While false, peer writes do NOT evict local L1
     * entries — L1 staleness is bounded only by `$l1Ttl` (a key updated on
     * node A serves stale from node B's L1 for up to that window). Used by the
     * `Store::tieredBootChecks()` advisory to surface the silent gap.
     */
    public function isInvalidationEnabled(): bool
    {
        return $this->invalidationRunner !== null;
    }

    /** Return the L1 `TableBackend` instance (in-process shared-memory tier). */
    public function l1(): TableBackend { return $this->l1; }

    /** Return the L2 `RedisBackend` instance (cross-node source-of-truth tier). */
    public function l2(): RedisBackend { return $this->l2; }

    /** Return the configured L1 TTL in seconds (maximum staleness window for L1 reads). */
    public function l1Ttl(): int       { return $this->l1Ttl; }

    /** Return the origin ID string stamped on invalidation publishes (hostname + PID + random suffix). */
    public function originId(): string { return $this->originId; }

    /**
     * Turn on cross-node L1 invalidation. After this, every write through
     * TieredBackend (set/del/incr/decr/clear) PUBLISHes an origin-tagged
     * invalidation message on `{prefix}:__l1_invalidate:{table}`; peer
     * TieredBackend instances on other nodes receive it and evict the
     * matching L1 entry. Self-publishes are skipped via the origin tag.
     *
     * MUST be called inside a coroutine context (spawns the subscriber cor).
     * Idempotent — repeated calls are no-ops.
     *
     * BOOT-ORDER REQUIREMENT — call `enableInvalidation()` AFTER every
     * `make()`, or call it once up front and `make()` tables afterwards:
     * a table `make()`d BEFORE this runner is up auto-subscribes itself when
     * enable() runs (its channel is replayed from `$invalidationChannels`),
     * and a table `make()`d AFTER enable() auto-subscribes at make() time.
     * The ONLY broken ordering is enabling, then making — which IS handled
     * here — but a runner that is restarted (`stopInvalidation()` then
     * `enableInvalidation()`) only re-subscribes channels still tracked in
     * `$invalidationChannels`. In short: register all tables AND call
     * enableInvalidation() during boot, before request concurrency; do not
     * interleave make()/stop()/enable() at runtime.
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

    /**
     * Stop the cross-node L1 invalidation subscriber coroutine.
     * After this call, peer writes will no longer evict local L1 entries;
     * L1 staleness reverts to the `$l1Ttl` window. Safe to call when no
     * invalidation runner is active (no-op).
     */
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

            // C2: HMAC verification. If a secret is configured, every peer
            // message MUST carry a matching hmac. Drops silently (warn-log)
            // on missing/invalid signature — defeats the
            // "anyone with Redis write access can DoS the cluster's L1"
            // attack.
            if ($this->invalidationSecret !== null) {
                $hmac = is_string($msg['hmac'] ?? null) ? $msg['hmac'] : '';
                $expected = $this->computeHmac($name, $key, $origin);
                if ($hmac === '' || !hash_equals($expected, $hmac)) {
                    if (function_exists('elog')) {
                        elog(
                            "TieredBackend: dropped invalidation with bad/missing HMAC for {$name}:{$key} (origin={$origin})",
                            'warn',
                        );
                    }
                    return;
                }
            }
            $this->l1->del($name, $key);
        };
    }

    /** Publish an invalidation marker after a successful L2 write. */
    private function publishInvalidation(string $table, string $key): void
    {
        // #256(A): only publish when invalidation is actually ENABLED. The
        // channel map is populated unconditionally by make() (it feeds runner
        // registration), so gating on it alone meant every write published an
        // invalidation even when enableInvalidation() was never called — a
        // wasted Redis round-trip + a surprising write-time side effect on a
        // dormant feature, with no subscriber to receive it. The runner being
        // non-null is the truthful "invalidation is active" signal.
        if ($this->invalidationRunner === null) { return; }
        if (!isset($this->invalidationChannels[$this->channel($table)])) { return; }
        try {
            $msg = [
                'table'  => $table,
                'key'    => $key,
                'origin' => $this->originId,
            ];
            if ($this->invalidationSecret !== null) {
                $msg['hmac'] = $this->computeHmac($table, $key, $this->originId);
            }
            $this->l2->publish($this->channel($table), (string) json_encode($msg));
        } catch (\Throwable $e) {
            // Best-effort — invalidation drop just means peers stay stale up
            // to $l1Ttl. Don't propagate to the caller's write.
        }
    }

    /**
     * Deterministic 64-bit truncated HMAC-SHA256 over `table|key|origin`.
     * 64 bits is plenty for cache-invalidation forgery resistance — an
     * attacker would need ~2^32 trials to land a collision, infeasible
     * within any reasonable cache window. We don't need 256-bit MAC
     * resistance because the payload is not a credential.
     */
    private function computeHmac(string $table, string $key, string $origin): string
    {
        $secret = $this->invalidationSecret ?? '';
        return substr(hash_hmac('sha256', "$table|$key|$origin", $secret), 0, 16);
    }

    /** Build the Redis pub/sub channel name for L1 invalidation of `$table`. */
    private function channel(string $table): string
    {
        return '__l1_invalidate:' . $table;
    }

    /**
     * Create a named table in both L1 and L2 backends.
     *
     * The L1 schema receives a synthetic `__cached_at` `INT` column appended
     * to `$columns` for staleness bookkeeping; user-facing `get()` strips it.
     * Registers the invalidation channel for the new table; if `enableInvalidation()`
     * has already been called, the subscriber is registered immediately.
     */
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

    /**
     * Write-through: persist `$row` to L2 first (source of truth), then refresh
     * the L1 entry and publish an invalidation so peers evict their stale copies.
     * Returns `false` only when the L2 write itself fails.
     */
    public function set(string $name, string $key, array $row): bool
    {
        $ok = $this->l2->set($name, $key, $row);
        if ($ok) {
            // If the L1 (Table) write fails — e.g. the row exceeds the L1 column
            // size or the table is full — do NOT leave a partial/stale entry that
            // get() would serve as authoritative within the TTL window. Evict it so
            // reads fall through to L2 (the source of truth).
            if (!$this->l1->set($name, $key, $row + [self::CACHED_AT => time()])) {
                $this->l1->del($name, $key);
            }
            $this->publishInvalidation($name, $key);
        }
        return $ok;
    }

    /**
     * Read from L1 if the entry is fresh (within `$l1Ttl` seconds); otherwise
     * fetch from L2, repopulate L1, and return. The synthetic `__cached_at`
     * column is stripped before returning the row.
     *
     * Returns `null` (field lookup) or `false` (full row) on miss, matching
     * `TableBackend::get()` semantics.
     */
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
            // Don't cache a truncated/partial copy in L1 (see set()): evict on a
            // failed L1 write so the next read re-fetches L2 instead of serving a
            // corrupt L1 entry.
            if (!$this->l1->set($name, $key, self::scalarRow($l2Row) + [self::CACHED_AT => $now])) {
                $this->l1->del($name, $key);
            }
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

    /**
     * Delete `$key` from both L1 and L2, then publish an invalidation to peers.
     * Returns the L2 delete result (`true` when the key existed and was removed).
     */
    public function del(string $name, string $key): bool
    {
        $this->l1->del($name, $key);
        $ok = $this->l2->del($name, $key);
        if ($ok) { $this->publishInvalidation($name, $key); }
        return $ok;
    }

    /**
     * Authoritative existence check — always defers to L2.
     * L1 may hold a stale entry for a key that has since been deleted on another
     * node; only L2 is the source of truth.
     */
    public function exists(string $name, string $key): bool
    {
        // L1 might lie about existence (stale entry); always defer to L2.
        return $this->l2->exists($name, $key);
    }

    /**
     * Stale-OK exists check (H8).
     *
     * Returns `true` if L1 has a fresh entry (within `$l1Ttl` of now);
     * otherwise defers to L2 (the strict authoritative check). Use when
     * "probably exists" is good enough — saves a Redis round-trip on
     * hot read paths where the caller already tolerates `$l1Ttl`-bounded
     * staleness for `get()`.
     *
     * The strict `exists()` always hits L2 (consistency); this variant
     * trades that consistency for speed at the caller's explicit request.
     * Never returns a false positive (L1 entries are always set after a
     * confirmed L2 set/get; stale-but-still-present is the only window).
     */
    public function existsCached(string $name, string $key): bool
    {
        $l1Row = $this->l1->get($name, $key);
        if (is_array($l1Row)) {
            $cachedAt = is_numeric($l1Row[self::CACHED_AT] ?? null) ? (int) $l1Row[self::CACHED_AT] : 0;
            if ($cachedAt > 0 && (time() - $cachedAt) < $this->l1Ttl) {
                return true;
            }
        }
        return $this->l2->exists($name, $key);
    }

    /**
     * Increment `$col` in L2 (authoritative), evict the stale L1 entry so the
     * next `get()` re-fetches the updated value, and publish an invalidation to peers.
     */
    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $new = $this->l2->incr($name, $key, $col, $by);
        // L1 might hold a stale view of this row; evict so the next get
        // re-fetches the authoritative value from L2.
        $this->l1->del($name, $key);
        $this->publishInvalidation($name, $key);
        return $new;
    }

    /**
     * Decrement `$col` in L2 (authoritative), evict the stale L1 entry, and
     * publish an invalidation to peers. Mirror of `incr()`.
     */
    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $new = $this->l2->decr($name, $key, $col, $by);
        $this->l1->del($name, $key);
        $this->publishInvalidation($name, $key);
        return $new;
    }

    /**
     * Return the authoritative row count from L2. L1 is a partial cache and
     * must not be counted (it holds only a subset of the L2 key space).
     */
    public function count(string $name): int
    {
        // L1 is a partial cache, not authoritative. Always count L2.
        return $this->l2->count($name);
    }

    /**
     * Yield all rows from L2 (authoritative and complete).
     * L1 is not iterated — it holds only a hot subset of the L2 key space.
     */
    public function iterate(string $name): \Generator
    {
        // Iterate L2 — it's authoritative and complete.
        yield from $this->l2->iterate($name);
    }

    /**
     * Return a cursor-paginated page of rows from L2.
     * Same reasoning as `iterate()`: L2 is the authoritative source.
     */
    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        // Defer to L2 — same reasoning as iterate(): authoritative + complete.
        return $this->l2->iteratePaged($name, $cursor, $count);
    }

    /** Clear all rows from both L1 and L2 for the given table. */
    public function clear(string $name): void
    {
        $this->l1->clear($name);
        $this->l2->clear($name);
    }

    /** Return the list of table names known to L2 (the authoritative registry). */
    public function names(): array
    {
        return $this->l2->names();
    }

    /**
     * Batch get: serve L1-fresh entries from the cache tier and fetch the rest
     * from L2 in one round-trip, populating L1 on the way back.
     *
     * Returns an array keyed by the original `$keys`; values are the row arrays
     * (with `__cached_at` stripped) or `null` for keys not found in either tier.
     */
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

    /**
     * Batch set: write all rows to L2, then repopulate L1 with `__cached_at`
     * timestamps. Returns `true` only when the L2 bulk write succeeds.
     */
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
