<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;

/**
 * Patch-coverage for the v0.2.40 Counter hardening — N-1..N-4.
 *   N-1: setIfAbsent (constructor no-clobber semantics)
 *   N-2: incrementBounded (atomic check-then-increment with cap)
 *   N-3: expire (TTL — no-op on Atomic, EXPIRE on Redis)
 *   N-4: mincr (batch increment)
 *
 * All paths exercise the Atomic backend (no Redis dependency). The Redis
 * paths share an interface contract enforced by PHPStan + structural
 * tests in StoreRedisBackendTest.
 */
final class CounterN1ToN4Test extends TestCase
{
    protected function setUp(): void
    {
        // Reset to Atomic backend for each test.
        Counter::defaultBackend('atomic');
    }

    /** N-1 — `new Counter(0, $name)` doesn't clobber a previously-set named counter. */
    public function testSetIfAbsentPreservesExistingValue(): void
    {
        $c1 = new Counter(0, 'n1-preserve');
        $c1->set(42);
        $c2 = new Counter(0, 'n1-preserve');
        $this->assertSame(42, $c2->get(), 'Re-construction must not reset the named counter');
    }

    /** N-1 — explicit reset() still works for callers that DO want to clear. */
    public function testResetStillClearsToZero(): void
    {
        $c = new Counter(0, 'n1-reset');
        $c->set(99);
        $c->reset();
        $this->assertSame(0, $c->get());
    }

    /** N-2 — incrementBounded returns new value within cap, null when exceeded. */
    public function testIncrementBoundedCapsAtMax(): void
    {
        $slots = new Counter(0, 'n2-cap');
        $slots->reset();
        $this->assertSame(1, $slots->incrementBounded(1, 3));
        $this->assertSame(2, $slots->incrementBounded(1, 3));
        $this->assertSame(3, $slots->incrementBounded(1, 3));
        $this->assertNull($slots->incrementBounded(1, 3), '+1 would exceed cap=3 — returns null');
        $this->assertSame(3, $slots->get(), 'Value unchanged on cap hit (atomic)');
    }

    /** N-2 — bigger steps still respect the bound. */
    public function testIncrementBoundedAcceptsStepGreaterThanOne(): void
    {
        $c = new Counter(0, 'n2-step');
        $c->reset();
        $this->assertSame(5, $c->incrementBounded(5, 10));
        $this->assertNull($c->incrementBounded(6, 10), '5 + 6 > 10 → null');
    }

    /** N-3 — expire() is a no-op on the Atomic backend (no TTL semantics). */
    public function testExpireIsNoOpOnAtomicBackend(): void
    {
        $c = new Counter(0, 'n3-atomic');
        $this->assertFalse($c->expire(60), 'Atomic backend has no TTL → returns false');
    }

    /** N-4 — Counter::mincr pipelines a batch of increments. */
    public function testMincrIncrementsMultipleNamedCounters(): void
    {
        // Init each at 0 then increment.
        Counter::mincr(['n4-a' => 0, 'n4-b' => 0, 'n4-c' => 0]);
        $result = Counter::mincr(['n4-a' => 5, 'n4-b' => 3, 'n4-c' => 7]);
        $this->assertSame(['n4-a' => 5, 'n4-b' => 3, 'n4-c' => 7], $result);
    }

    /** N-4 — empty batch is a no-op (no round-trip). */
    public function testMincrEmptyArrayShortCircuits(): void
    {
        $this->assertSame([], Counter::mincr([]));
    }

    /** N-4 — incrementing existing counters accumulates. */
    public function testMincrAccumulatesOnExistingCounters(): void
    {
        // Reset to a known state first
        $a = new Counter(0, 'n4-acc-a');  $a->reset();
        $b = new Counter(0, 'n4-acc-b');  $b->reset();

        Counter::mincr(['n4-acc-a' => 10, 'n4-acc-b' => 20]);
        $second = Counter::mincr(['n4-acc-a' => 3, 'n4-acc-b' => 7]);
        $this->assertSame(13, $second['n4-acc-a']);
        $this->assertSame(27, $second['n4-acc-b']);
    }

    /** Backend constants resolve via coerce + map to the right kind. */
    public function testBackendConstantsResolveCorrectly(): void
    {
        $this->assertSame('atomic',    Counter::BACKEND_ATOMIC);
        $this->assertSame('redis',     Counter::BACKEND_REDIS);
        $this->assertSame('memcached', Counter::BACKEND_MEMCACHED);
    }
}
