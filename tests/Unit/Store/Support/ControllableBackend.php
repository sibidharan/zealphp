<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store\Support;

use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\StoreException;

/**
 * Test helper — a controllable StoreBackend used by CircuitBreakerBackendTest.
 *
 * Set `$shouldThrow = true` to make every op throw StoreException; toggle
 * `$callCount` to verify whether the breaker actually skipped the primary
 * in OPEN state.
 */
final class ControllableBackend implements StoreBackend
{
    public bool $shouldThrow = false;
    public int $callCount = 0;

    /** @var array<string, array<string, array<string, scalar>>> */
    private array $rows = [];
    /** @var array<string, array<string, array{0:int,1?:int}>> */
    private array $schemas = [];

    private function trip(): void
    {
        $this->callCount++;
        if ($this->shouldThrow) {
            throw new StoreException('controllable: simulated failure');
        }
    }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $this->schemas[$name] = $columns;
    }

    public function set(string $name, string $key, array $row): bool
    {
        $this->trip();
        $this->rows[$name][$key] = $row;
        return true;
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $this->trip();
        $r = $this->rows[$name][$key] ?? null;
        if ($r === null) { return null; }
        return $field !== null ? ($r[$field] ?? null) : $r;
    }

    public function del(string $name, string $key): bool
    {
        $this->trip();
        unset($this->rows[$name][$key]);
        return true;
    }

    public function exists(string $name, string $key): bool
    {
        $this->trip();
        return isset($this->rows[$name][$key]);
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $this->trip();
        $existing = $this->rows[$name][$key][$col] ?? 0;
        $existingNum = is_numeric($existing) ? $existing + 0 : 0;
        $v = $existingNum + $by;
        $this->rows[$name][$key][$col] = $v;
        return $v;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        return $this->incr($name, $key, $col, -$by);
    }

    public function count(string $name): int
    {
        $this->trip();
        return count($this->rows[$name] ?? []);
    }

    public function names(): array
    {
        return array_keys($this->schemas);
    }

    public function iterate(string $name): \Generator
    {
        $this->trip();
        foreach ($this->rows[$name] ?? [] as $k => $r) {
            yield $k => $r;
        }
    }

    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        $this->trip();
        $skip = max(0, (int) $cursor);
        $rows = [];
        $i = 0;
        $taken = 0;
        foreach ($this->rows[$name] ?? [] as $k => $r) {
            if ($i++ < $skip) { continue; }
            $rows[(string) $k] = $r;
            if (++$taken >= $count) {
                return ['cursor' => (string) ($skip + $taken), 'rows' => $rows];
            }
        }
        return ['cursor' => '0', 'rows' => $rows];
    }

    public function clear(string $name): void
    {
        $this->trip();
        unset($this->rows[$name]);
    }

    public function mget(string $name, array $keys): array
    {
        $this->trip();
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->rows[$name][$k] ?? null;
        }
        return $out;
    }

    public function mset(string $name, array $rows): bool
    {
        $this->trip();
        foreach ($rows as $k => $r) {
            $this->rows[$name][$k] = $r;
        }
        return true;
    }
}
