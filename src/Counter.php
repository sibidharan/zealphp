<?php
namespace ZealPHP;

/**
 * Counter — OpenSwoole\Atomic adapter
 *
 * Lock-free integer counter shared across all worker processes.
 * Uses a spinlock (CAS) internally — safe for concurrent reads/writes
 * from multiple coroutines and workers simultaneously.
 *
 * IMPORTANT: Instantiate BEFORE $app->run() so the shared memory segment
 * is inherited by all forked workers.
 *
 * Usage:
 *   // Before app->run():
 *   $hits    = new Counter();
 *   $errors  = new Counter(0);
 *   $version = new Counter(1);
 *
 *   // Anywhere (all workers share the same value):
 *   $hits->increment();          // +1, returns new value
 *   $hits->increment(5);         // +5
 *   $hits->decrement();          // -1
 *   $hits->get();                // current value
 *   $hits->set(0);               // force-set (not atomic vs concurrent reads)
 *   $hits->reset();              // alias for set(0)
 *
 *   // Conditional update (compare-and-swap):
 *   $hits->compareAndSet($expected, $new); // returns bool
 */
class Counter
{
    private \OpenSwoole\Atomic $atomic;

    public function __construct(int $initial = 0)
    {
        $this->atomic = new \OpenSwoole\Atomic($initial);
    }

    /** Atomically add $by and return the new value. */
    public function increment(int $by = 1): int
    {
        return $this->atomic->add($by);
    }

    /** Atomically subtract $by and return the new value. */
    public function decrement(int $by = 1): int
    {
        return $this->atomic->sub($by);
    }

    /** Read the current value. */
    public function get(): int
    {
        return $this->atomic->get();
    }

    /** Set the value (not atomic relative to concurrent add/sub). */
    public function set(int $value): void
    {
        $this->atomic->set($value);
    }

    /** Reset to zero. */
    public function reset(): void
    {
        $this->atomic->set(0);
    }

    /**
     * Compare-and-swap: if current value equals $expected, set to $new.
     * Returns true if the swap happened.
     */
    public function compareAndSet(int $expected, int $new): bool
    {
        return $this->atomic->cmpset($expected, $new);
    }

    /** Return the raw OpenSwoole\Atomic for advanced use. */
    public function raw(): \OpenSwoole\Atomic
    {
        return $this->atomic;
    }
}
