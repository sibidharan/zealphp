<?php

declare(strict_types=1);

namespace ZealPHP\Counter;

use OpenSwoole\Atomic\Long as Atomic;

/**
 * Default CounterBackend — wraps OpenSwoole\Atomic\Long (a 64-bit SIGNED atomic).
 *
 * Each named counter gets a process-shared atomic; the map must be populated
 * BEFORE workers fork (the shared memory segment is inherited). Lock-free reads,
 * CAS-based compareAndSet. Uses the 64-bit `Long` variant rather than the 32-bit
 * unsigned `OpenSwoole\Atomic` so a busy long-lived counter (a global request
 * counter, etc.) can't silently wrap at 2^32 — its range is the full signed
 * 64-bit space.
 */
final class AtomicBackend implements CounterBackend
{
    /** @var array<string, Atomic> */
    private array $counters = [];

    private function atomic(string $name): Atomic
    {
        return $this->counters[$name] ??= new Atomic(0);
    }

    /**
     * Return the underlying Atomic for a named counter — used by the
     * Counter facade's `raw()` BC method.
     */
    public function atomicFor(string $name): Atomic
    {
        return $this->atomic($name);
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

    public function setIfAbsent(string $name, int $value): bool
    {
        // "Already exists" = we've previously instantiated the Atomic.
        // Atomic shared memory has no first-write detection; this map's
        // presence IS the marker. Note: per-process — different worker
        // processes see independent maps but all point at the same
        // shared-memory slot (the value is preserved across processes
        // even when the map is fresh; that's the documented Atomic
        // behaviour). For "set if absent" we trust the map: if we
        // haven't touched the name, we initialize.
        if (isset($this->counters[$name])) { return false; }
        $this->counters[$name] = new Atomic($value);
        return true;
    }

    public function incrBounded(string $name, int $by, int $maxBound): ?int
    {
        // Atomic::cmpset gives us optimistic CAS — load + check + set in
        // a retry loop. Bounded by 100 attempts to avoid infinite spin
        // under heavy contention.
        $a = $this->atomic($name);
        for ($i = 0; $i < 100; $i++) {
            $cur = $a->get();
            $next = $cur + $by;
            if ($next > $maxBound) { return null; }
            if ($a->cmpset($cur, $next)) { return $next; }
        }
        return null;   // contention loss
    }

    public function expire(string $name, int $seconds): bool
    {
        // Atomic has no TTL — shared memory lives with the master.
        return false;
    }

    public function mincr(array $deltas): array
    {
        $out = [];
        foreach ($deltas as $name => $by) {
            $out[$name] = $this->incr((string) $name, (int) $by);
        }
        return $out;
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
