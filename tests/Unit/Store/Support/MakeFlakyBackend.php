<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store\Support;

use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\StoreException;

/**
 * Test helper for CircuitBreakerBackend #241 — a primary whose `make()` fails
 * the first `$makeFailures` times, then succeeds. Reads/writes for a table that
 * was never successfully `make()`'d throw "table not registered" (the real
 * RedisBackend/TableBackend contract). This lets the test prove the breaker
 * retries the pending make() on a later write instead of throwing forever.
 */
final class MakeFlakyBackend implements StoreBackend
{
    public int $makeAttempts = 0;
    public int $makeSuccesses = 0;

    /** @var array<string, true> */
    private array $registered = [];
    /** @var array<string, array<string, array<string, scalar>>> */
    private array $rows = [];

    public function __construct(public int $makeFailures = 1) {}

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $this->makeAttempts++;
        if ($this->makeAttempts <= $this->makeFailures) {
            throw new StoreException("MakeFlakyBackend: simulated make() failure #{$this->makeAttempts}");
        }
        $this->registered[$name] = true;
        $this->makeSuccesses++;
    }

    private function assertMade(string $name): void
    {
        if (!isset($this->registered[$name])) {
            throw new StoreException("MakeFlakyBackend: table not registered: $name");
        }
    }

    public function set(string $name, string $key, array $row): bool
    {
        $this->assertMade($name);
        $this->rows[$name][$key] = $row;
        return true;
    }

    public function get(string $name, string $key, ?string $field = null): mixed
    {
        $this->assertMade($name);
        $r = $this->rows[$name][$key] ?? null;
        if ($r === null) { return null; }
        return $field !== null ? ($r[$field] ?? null) : $r;
    }

    public function del(string $name, string $key): bool
    {
        $this->assertMade($name);
        unset($this->rows[$name][$key]);
        return true;
    }

    public function exists(string $name, string $key): bool
    {
        $this->assertMade($name);
        return isset($this->rows[$name][$key]);
    }

    public function incr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        $this->assertMade($name);
        $existing = $this->rows[$name][$key][$col] ?? 0;
        $v = (is_numeric($existing) ? $existing + 0 : 0) + $by;
        $this->rows[$name][$key][$col] = $v;
        return $v;
    }

    public function decr(string $name, string $key, string $col, int|float $by = 1): int|float
    {
        return $this->incr($name, $key, $col, -$by);
    }

    public function count(string $name): int
    {
        $this->assertMade($name);
        return count($this->rows[$name] ?? []);
    }

    public function names(): array
    {
        return array_keys($this->registered);
    }

    public function iterate(string $name): \Generator
    {
        $this->assertMade($name);
        foreach ($this->rows[$name] ?? [] as $k => $r) {
            yield $k => $r;
        }
    }

    public function iteratePaged(string $name, string $cursor = '0', int $count = 100): array
    {
        $this->assertMade($name);
        return ['cursor' => '0', 'rows' => $this->rows[$name] ?? []];
    }

    public function clear(string $name): void
    {
        $this->assertMade($name);
        unset($this->rows[$name]);
    }

    public function mget(string $name, array $keys): array
    {
        $this->assertMade($name);
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->rows[$name][$k] ?? null;
        }
        return $out;
    }

    public function mset(string $name, array $rows): bool
    {
        $this->assertMade($name);
        foreach ($rows as $k => $r) {
            $this->rows[$name][$k] = $r;
        }
        return true;
    }
}
