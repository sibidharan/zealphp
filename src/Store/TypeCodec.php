<?php

declare(strict_types=1);

namespace ZealPHP\Store;

use OpenSwoole\Table;

/**
 * Backend-neutral row (de)serialization.
 *
 * Redis HASH fields are byte strings on the wire (RESP2 has no typed
 * scalars). To keep `get()` returning the same `array<string, scalar>`
 * shape from both backends, the column schema decides how each field
 * is coerced back to int / float / string.
 *
 * Used by `RedisBackend` (Task 6); `TableBackend` doesn't need it
 * because OpenSwoole\Table enforces the schema natively.
 */
final class TypeCodec
{
    /**
     * Cast every row value to a string for the wire (HSET, HMGET args).
     * Returns a parallel array of strings keyed by column name.
     *
     * @param array<string, array{0:int, 1?:int}> $schema  ignored at encode time but kept for symmetry
     * @param array<string, scalar>               $row
     * @return array<string, string>
     */
    public function encodeRow(array $schema, array $row): array
    {
        $out = [];
        foreach ($row as $col => $val) {
            if (is_bool($val)) { $out[$col] = $val ? '1' : '0'; continue; }
            $out[$col] = (string) $val;
        }
        return $out;
    }

    /**
     * Inverse of encodeRow — coerces wire strings back to typed values
     * per the schema. Returns null when the row didn't exist (empty wire).
     * Missing fields in a known row get their zero-value-by-type (matches
     * OpenSwoole\Table's behaviour for unset columns).
     *
     * @param array<string, array{0:int, 1?:int}> $schema
     * @param array<string, string>               $wire
     * @return array<string, int|float|string>|null
     */
    public function decodeRow(array $schema, array $wire): ?array
    {
        if ($wire === []) { return null; }
        $out = [];
        foreach ($schema as $col => $spec) {
            $type = $spec[0];
            $raw  = $wire[$col] ?? null;
            $out[$col] = $this->coerce($type, $raw);
        }
        return $out;
    }

    /**
     * Coerce a single wire value (or null for "field missing") to the
     * schema's declared type. Used by single-field `get($t, $k, $col)`.
     */
    public function decodeField(int $type, ?string $raw): int|float|string
    {
        return $this->coerce($type, $raw);
    }

    /**
     * Coerce a raw Redis wire string (or `null` for a missing field) to the
     * PHP type declared by `$type` (`Table::TYPE_INT`, `Table::TYPE_FLOAT`,
     * or `Table::TYPE_STRING`). Returns the zero-value for the type on `null`.
     */
    private function coerce(int $type, ?string $raw): int|float|string
    {
        if ($raw === null) {
            return match ($type) {
                Table::TYPE_INT    => 0,
                Table::TYPE_FLOAT  => 0.0,
                Table::TYPE_STRING => '',
                default            => '',
            };
        }
        return match ($type) {
            Table::TYPE_INT    => (int) $raw,
            Table::TYPE_FLOAT  => (float) $raw,
            Table::TYPE_STRING => $raw,
            default            => $raw,
        };
    }
}
