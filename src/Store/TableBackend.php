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
    }

    public function set(string $name, string $key, array $row): bool
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return false; }
        try { return $t->set($key, $row); }
        catch (\OpenSwoole\Exception) { return false; }
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return null; }
        $v = $field !== null ? $t->get($key, $field) : $t->get($key);
        return $v === false ? null : $v;
    }

    public function del(string $name, string $key): bool
    {
        return (bool) (($this->tables[$name] ?? null)?->del($key) ?? false);
    }

    public function exists(string $name, string $key): bool
    {
        return (bool) (($this->tables[$name] ?? null)?->exists($key) ?? false);
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return 0; }
        // OpenSwoole\Table::incr accepts int — floats are coerced to int here.
        // RedisBackend (Task 6) honours the float type via HINCRBYFLOAT.
        return $t->incr($key, $col, (int) $by);
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return 0; }
        return $t->decr($key, $col, (int) $by);
    }

    public function count(string $name): int
    {
        return ($this->tables[$name] ?? null)?->count() ?? 0;
    }

    public function iterate(string $name): \Generator
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return; }
        /** @var iterable<string, array<string, scalar>> $t */
        foreach ($t as $key => $row) {
            yield (string) $key => $row;
        }
    }

    public function clear(string $name): void
    {
        $t = $this->tables[$name] ?? null;
        if ($t === null) { return; }
        $keys = [];
        foreach ($t as $key => $_) { $keys[] = (string) $key; }
        foreach ($keys as $k) { $t->del($k); }
    }

    public function names(): array
    {
        return array_keys($this->tables);
    }

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
     * Translate Store::TYPE_* (which alias OpenSwoole\Table::TYPE_* — see
     * Task 10's facade constants) so the schema map is backend-neutral.
     * Existing code passing Table::TYPE_* directly still works.
     */
    private function mapType(int $type): int
    {
        // Store::TYPE_* === Table::TYPE_* by value (see Store facade const
        // declarations in Task 10); this method exists for symmetry with
        // the Redis backend where the TypeCodec uses these ints.
        return $type;
    }
}
