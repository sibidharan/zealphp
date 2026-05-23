<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

use OpenSwoole\Atomic;

/**
 * Default CounterBackend — wraps OpenSwoole\Atomic.
 *
 * Each named counter gets a process-shared Atomic; the map must be
 * populated BEFORE workers fork (the shared memory segment is inherited).
 * Lock-free reads, CAS-based compareAndSet.
 */
final class AtomicBackend implements CounterBackend
{
    /** @var array<string, Atomic> */
    private array $counters = [];

    private function atomic(string $name): Atomic
    {
        return $this->counters[$name] ??= new Atomic(0);
    }

    public function get(string $name): int
    {
        return $this->atomic($name)->get();
    }

    public function set(string $name, int $value): bool
    {
        $this->atomic($name)->set($value);
        return true;
    }

    public function incr(string $name, int $by = 1): int
    {
        // OpenSwoole\Atomic::add() is stubbed as bool|int by ide-helper;
        // the real ext always returns the new int value (matches the
        // ignoreErrors entry for src/Counter.php in phpstan.neon).
        $r = $this->atomic($name)->add($by);
        return is_int($r) ? $r : $this->atomic($name)->get();
    }

    public function decr(string $name, int $by = 1): int
    {
        $r = $this->atomic($name)->sub($by);
        return is_int($r) ? $r : $this->atomic($name)->get();
    }

    public function compareAndSet(string $name, int $expected, int $new): bool
    {
        return $this->atomic($name)->cmpset($expected, $new);
    }

    public function reset(string $name): void
    {
        $this->atomic($name)->set(0);
    }
}
