<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store\Support;

use OpenSwoole\Coroutine\Channel;
use ZealPHP\Store\StoreBackend;
use ZealPHP\Store\StoreException;

/**
 * Test helper for CircuitBreakerBackend #255 — like ControllableBackend, but a
 * read can optionally PARK on a channel (`$blockOn`) so the admitted half-open
 * probe holds the PROBING state while concurrent callers arrive and must take
 * the fallback. `$callCount` counts how many calls actually reached this
 * primary; `$shouldThrow` toggles simulated failures.
 */
final class ControllableBackendProxy implements StoreBackend
{
    public bool $shouldThrow = false;
    public int $callCount = 0;
    public ?Channel $blockOn = null;

    /** @var array<string, array<string, array<string, scalar>>> */
    private array $rows = [];
    /** @var array<string, true> */
    private array $schemas = [];

    private function trip(): void
    {
        $this->callCount++;
        // The single admitted prober parks here until the test releases it,
        // keeping the breaker in PROBING while the herd of losers runs.
        if ($this->blockOn !== null) {
            $this->blockOn->pop(2.0);
        }
        if ($this->shouldThrow) {
            throw new StoreException('controllable-proxy: simulated failure');
        }
    }

    public function make(string $name, int $maxRows, array $columns, array $opts = []): void
    {
        $this->schemas[$name] = true;
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
        return ['cursor' => '0', 'rows' => $this->rows[$name] ?? []];
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
