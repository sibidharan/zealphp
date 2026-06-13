<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Table;

/**
 * Default StoreBackend — wraps OpenSwoole\Table.
 *
 * Lifts the existing static logic of `ZealPHP\Store` into instance methods
 * so the facade in Task 10 can delegate. Public semantics (including the
 * "graceful return on missing table" pattern from the current impl) are
 * preserved verbatim.
 */
final class TableBackend implements StoreBackend
{
    /** @var array<string, Table> */
    private array $tables = [];
    /** @var array<string, array<string, array{0:int, 1?:int}>> */
    private array $schemas = [];
    /** @var array<string, int> Declared `maxRows` per table — for the silent-full advisory. */
    private array $maxRows = [];
    /** @var array<string, true> Tables a capacity warning has already been emitted for (rate-limit to once per table per worker). */
    private array $capacityWarned = [];

    /**
     * Create a named `OpenSwoole\Table` with the given schema. Each column in
     * `$columns` is `[TYPE_*, $size]`. When `$columns` is empty, a single
     * `'value'` column of type `TYPE_STRING(256)` is created. `$opts` is
     * accepted for interface compatibility but unused by this backend.
     */
    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $t = new Table($maxRows);
        if ($columns === []) {
            $t->column('value', Table::TYPE_STRING, 256);
            $columns = ['value' => [Table::TYPE_STRING, 256]];
        } else {
            foreach ($columns as $col => $spec) {
                $type = $spec[0];
                $size = $spec[1] ?? 0;
                $t->column($col, $this->mapType($type), $size);
            }
        }
        $t->create();
        $this->tables[$name]  = $t;
        $this->schemas[$name] = $columns;
        $this->maxRows[$name] = $maxRows;
    }

    /**
     * Write a row to the named table. Returns `false` when the table doesn't
     * exist or when `OpenSwoole\Table::set()` fails — most commonly because the
     * table is **full** (`maxRows` is a HARD boot-time cap with NO eviction, so
     * every new distinct key past the cap is silently dropped) or the row
     * exceeded a declared column size. The FIRST such failure per table emits a
     * one-time advisory (the failure would otherwise be silent — see the
     * scaling-limits doc); the return value is unchanged.
     */
    public function set(string $name, string $key, array $row): bool
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return false; }
        try {
            $ok = $t->set($key, $row);
        } catch (\OpenSwoole\Exception) {
            $ok = false;
        }
        if (!$ok) {
            $this->warnCapacity($name, $t, $key);
        }
        return $ok;
    }

    /**
     * Surface the otherwise-silent `set()` failure ONCE per table per worker.
     * Distinguishes "table full" (count at the hard `maxRows` cap, no eviction)
     * from "row didn't fit" (a column value exceeded its declared size).
     */
    private function warnCapacity(string $name, Table $t, string $key): void
    {
        if (isset($this->capacityWarned[$name])) { return; }
        $this->capacityWarned[$name] = true;
        $max   = $this->maxRows[$name] ?? 0;
        $count = $t->count();
        $full  = $max > 0 && $count >= $max;
        $msg   = $full
            ? "Store/Table '{$name}' is FULL at its hard maxRows cap ({$count}/{$max}) — "
              . "OpenSwoole\\Table does NOT evict, so further new keys are SILENTLY DROPPED. "
              . "Size maxRows to the full hot working set up front, or flip to the Redis/Tiered backend."
            : "Store/Table '{$name}' set('{$key}') failed (count {$count}/{$max}) — likely a row/column "
              . "value exceeded its declared size. Increase the column size at make() or use the Redis backend.";
        if (function_exists('ZealPHP\\elog')) {
            \ZealPHP\elog($msg, 'warn');
        } else {
            error_log($msg);
        }
    }

    /**
     * Read a row or a single field from the named table. Returns `null` when
     * the table or key doesn't exist. When `$field` is provided, returns the
     * field's scalar value or `null` if absent.
     */
    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return null; }
        $v = $field !== null ? $t->get($key, $field) : $t->get($key);
        return $v === false ? null : $v;
    }

    /** Delete a row by key. Returns `false` when the table doesn't exist or the key was already absent. */
    public function del(string $name, string $key): bool
    {
        return (bool) (($this->tables[$name] ?? null)?->del($key) ?? false);
    }

    /** Return `true` when the named table contains a row with the given key. */
    public function exists(string $name, string $key): bool
    {
        return (bool) (($this->tables[$name] ?? null)?->exists($key) ?? false);
    }

    /**
     * Atomically increment column `$col` by `$by`. Returns the new column value
     * — `int` for an INT column, `float` for a FLOAT column (`OpenSwoole\Table`
     * supports both) — or `0` when the table doesn't exist. Matches the
     * `StoreBackend::incr(): int|float` contract. `$by` is passed through
     * unchanged so a FLOAT column increments by a fractional amount (#420 — the
     * old `(int) $by` cast truncated the step AND the narrow `: int` return type
     * fatally rejected the float a FLOAT column returns).
     */
    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return 0; }
        // The openswoole/ide-helper stub types Table::incr as (int): int, but the
        // real ext accepts a float $by and returns a float for a FLOAT column —
        // the param/return stub mismatches are suppressed in phpstan.neon (#420).
        return $t->incr($key, $col, $by);
    }

    /**
     * Atomically decrement column `$col` by `$by`. Returns the new column value
     * (`int`/`float` per the column type), or `0` when the table doesn't exist.
     */
    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return 0; }
        // Same ide-helper stub mismatch as incr() — see phpstan.neon (#420).
        return $t->decr($key, $col, $by);
    }

    /** Return the number of rows in the named table, or `0` when the table doesn't exist. */
    public function count(string $name): int
    {
        return ($this->tables[$name] ?? null)?->count() ?? 0;
    }

    /**
     * Iterate all rows in the named table as a `Generator`. Yields string keys
     * mapped to `array<string, scalar>` row arrays. Returns immediately when
     * the table doesn't exist.
     *
     * @return \Generator<string, array<string, scalar>>
     */
    public function iterate(string $name): \Generator
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return; }
        /** @var iterable<string, array<string, scalar>> $t */
        foreach ($t as $key => $row) {
            yield (string) $key => $row;
        }
    }

    /**
     * Paginated iteration. **O(N²/page) on the Table backend** — the cursor is a
     * skip-offset and each call re-iterates from row 0, discarding `$cursor`
     * rows before collecting the next `$count`. Paginating all N rows therefore
     * costs `O(N² / page)`. Fine for a few thousand rows; for large tables use
     * the Redis/Tiered backend (true `SCAN` cursor) instead, or `iterate()` if
     * you can hold one pass in memory. See the scaling-limits doc.
     *
     * @return array{cursor: string, rows: array<string, array<string, scalar>>}
     */
    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return ['cursor' => '0', 'rows' => []]; }
        // Cursor = decimal offset into a deterministic iteration. Table doesn't
        // expose a native SCAN, but it iterates in insertion order under the
        // same worker; we treat the cursor as "how many rows to skip" and
        // return the next $count. End-of-scan signalled by '0'.
        //
        // Note: this is best-effort under concurrent mutations. Insertions
        // during pagination may or may not appear in the next page (Table
        // is a fixed-slot hash; iteration order can shift on rehash). The
        // contract is "no consistency guarantee across cursors" — see the
        // backend interface docblock.
        $skip = max(0, (int) $cursor);
        $i = 0;
        $taken = 0;
        $rows = [];
        /** @var iterable<string, array<string, scalar>> $t */
        foreach ($t as $key => $row) {
            if ($i++ < $skip) { continue; }
            $rows[(string) $key] = $row;
            if (++$taken >= $count) {
                return ['cursor' => (string) ($skip + $taken), 'rows' => $rows];
            }
        }
        return ['cursor' => '0', 'rows' => $rows];
    }

    /**
     * Delete all rows from the named table by iterating and deleting each
     * key individually (no native bulk-delete on `OpenSwoole\Table`).
     * No-op when the table doesn't exist.
     */
    public function clear(string $name): void
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return; }
        $keys = [];
        foreach ($t as $key => $_) { $keys[] = (string) $key; }
        foreach ($keys as $k) { $t->del($k); }
    }

    /** Return the names of all tables registered via `make()`. */
    public function names(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Bulk read multiple rows. Missing keys are returned as `null` in
     * the result map. Non-scalar column values are silently skipped
     * (they cannot arise from a normal `set()` call on this backend).
     */
    public function mget(string $name, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $row = $this->get($name, $k);
            if (!is_array($row)) { $out[$k] = null; continue; }
            $coerced = [];
            foreach ($row as $col => $val) {
                if (is_scalar($val)) {
                    $coerced[(string) $col] = $val;
                }
                // Non-scalar values can't reach here for a Table-stored row,
                // but skip defensively rather than coercing nulls/arrays to ''.
            }
            $out[$k] = $coerced;
        }
        return $out;
    }

    /**
     * Bulk write multiple rows by calling `set()` for each entry in sequence.
     * Returns `true` when all writes succeeded; `false` when any single write
     * failed (the rest still proceed — no rollback).
     */
    public function mset(string $name, array $rows): bool
    {
        $allOk = true;
        foreach ($rows as $key => $row) {
            if (!$this->set($name, (string) $key, $row)) { $allOk = false; }
        }
        return $allOk;
    }

    /**
     * Direct access to the underlying Table — used by the Store facade
     * to keep `Store::table($name)` working under the table backend.
     */
    public function rawTable(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    /**
     * Column schema as registered via make(). Exposed for tests + the
     * future tiered backend; mirrors what RedisBackend stores internally
     * for TypeCodec decoding.
     *
     * @return array<string, array{0:int, 1?:int}>|null
     */
    public function schema(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }

    /**
     * Translate `Store::TYPE_*` constants (which alias `OpenSwoole\Table::TYPE_*` —
     * see the facade constant declarations in `Store.php`) so the schema map
     * stays backend-neutral. Existing code passing `Table::TYPE_*` directly
     * still works because the numeric values are identical.
     */
    private function mapType(int $type): int
    {
        // Store::TYPE_* === Table::TYPE_* by value (see Store facade const
        // declarations in Task 10); this method exists for symmetry with
        // the Redis backend where the TypeCodec uses these ints.
        return $type;
    }
}
