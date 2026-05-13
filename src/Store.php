<?php
namespace ZealPHP;

/**
 * Store — OpenSwoole\Table adapter
 *
 * Cross-worker shared-memory key-value store backed by OpenSwoole\Table.
 * Data is visible to ALL worker processes simultaneously with no locking needed
 * (Table uses spinlocks internally per row).
 *
 * IMPORTANT: Store::make() must be called BEFORE $app->run() (before workers
 * are forked). The shared memory segment is inherited by all workers on fork.
 *
 * Column types:
 *   \OpenSwoole\Table::TYPE_INT    — 1, 2, 4, or 8 bytes
 *   \OpenSwoole\Table::TYPE_FLOAT  — 8 bytes (double)
 *   \OpenSwoole\Table::TYPE_STRING — up to N bytes (specify max length)
 *
 * Usage:
 *   // Before app->run():
 *   Store::make('sessions', 4096, [
 *       'uid'  => [\OpenSwoole\Table::TYPE_STRING, 64],
 *       'room' => [\OpenSwoole\Table::TYPE_STRING, 32],
 *       'hits' => [\OpenSwoole\Table::TYPE_INT,    4],
 *   ]);
 *
 *   // Anywhere (all workers share the same data):
 *   Store::set('sessions', $fd, ['uid' => 'alice', 'room' => 'general', 'hits' => 0]);
 *   Store::get('sessions', $fd);        // ['uid' => 'alice', ...]
 *   Store::incr('sessions', $fd, 'hits');
 *   Store::del('sessions', $fd);
 *   Store::count('sessions');
 *
 *   // Or use the Table object directly:
 *   $t = Store::table('sessions');
 *   foreach ($t as $key => $row) { ... }
 */
class Store
{
    /** @var array<string, \OpenSwoole\Table> */
    private static array $tables = [];

    /**
     * Create a named shared-memory table.
     * Call once before $app->run().
     *
     * @param string $name    Logical name
     * @param int    $maxRows Power-of-2 capacity (actual allocation is next power of 2 ≥ $maxRows)
     * @param array  $columns ['colName' => [TYPE, size]]
     */
    public static function make(string $name, int $maxRows = 1024, array $columns = []): \OpenSwoole\Table
    {
        $table = new \OpenSwoole\Table($maxRows);

        if (empty($columns)) {
            // Default schema: a single string value column
            $table->column('value', \OpenSwoole\Table::TYPE_STRING, 256);
        } else {
            foreach ($columns as $col => [$type, $size]) {
                $table->column($col, $type, $size);
            }
        }

        $table->create();
        self::$tables[$name] = $table;
        return $table;
    }

    /** Get the raw Table object by name. */
    public static function table(string $name): ?\OpenSwoole\Table
    {
        return self::$tables[$name] ?? null;
    }

    /** Set a row. $key must be a string. */
    public static function set(string $table, string $key, array $row): bool
    {
        return (self::$tables[$table] ?? null)?->set($key, $row) ?? false;
    }

    /** Get a row. Returns array|false. */
    public static function get(string $table, string $key, ?string $field = null): mixed
    {
        $t = self::$tables[$table] ?? null;
        if (!$t) return false;
        return $field ? $t->get($key, $field) : $t->get($key);
    }

    /** Delete a row. */
    public static function del(string $table, string $key): bool
    {
        return (self::$tables[$table] ?? null)?->del($key) ?? false;
    }

    /** Check if a row exists. */
    public static function exists(string $table, string $key): bool
    {
        return (self::$tables[$table] ?? null)?->exists($key) ?? false;
    }

    /** Atomically increment an integer column and return new value. */
    public static function incr(string $table, string $key, string $col, int $by = 1): int
    {
        return (self::$tables[$table] ?? null)?->incr($key, $col, $by) ?? 0;
    }

    /** Atomically decrement an integer column and return new value. */
    public static function decr(string $table, string $key, string $col, int $by = 1): int
    {
        return (self::$tables[$table] ?? null)?->decr($key, $col, $by) ?? 0;
    }

    /** Number of rows currently stored. */
    public static function count(string $table): int
    {
        return (self::$tables[$table] ?? null)?->count() ?? 0;
    }

    /** List all registered store names. */
    public static function names(): array
    {
        return array_keys(self::$tables);
    }
}
