<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use ZealPHP\Store\CircuitBreakerBackend;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;
use ZealPHP\Tests\Unit\Store\Support\ControllableBackendProxy;
use ZealPHP\Tests\Unit\Store\Support\MakeFlakyBackend;

/**
 * #241 (retry a failed primary make() on later writes) and #255 (admit exactly
 * one half-open probe via an atomic CAS — no thundering herd).
 */
final class CircuitBreakerBackendRecoveryTest extends TestCase
{
    // ── #241: primary make() retry ──────────────────────────────────────

    public function testFailedPrimaryMakeIsRetriedOnNextWrite(): void
    {
        $primary  = new MakeFlakyBackend(makeFailures: 1); // first make() throws, then works
        $fallback = new TableBackend();
        // threshold high so one make() failure does NOT trip the breaker.
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 5);

        // make() fails on the primary (counted, not thrown) but lands on fallback.
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);
        self::assertSame('closed', $b->state(), 'one make failure must not open the breaker');
        self::assertSame(1, $primary->makeAttempts);
        self::assertSame(0, $primary->makeSuccesses, 'primary make did not land yet');

        // First write retries the pending make() (now succeeds) THEN writes.
        self::assertTrue($b->set('t', 'k', ['v' => 'hi']));
        self::assertSame(2, $primary->makeAttempts, 'make was retried on the write');
        self::assertSame(1, $primary->makeSuccesses, 'retry landed the table on the primary');
        self::assertSame(['v' => 'hi'], $primary->get('t', 'k'), 'the row is now on the primary');
        self::assertSame('closed', $b->state());
    }

    public function testRetriedMakeIsNotRepeatedAfterItSucceeds(): void
    {
        $primary  = new MakeFlakyBackend(makeFailures: 1);
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 5);

        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);
        $b->set('t', 'k1', ['v' => 'a']); // retries make → 2 attempts
        $b->set('t', 'k2', ['v' => 'b']); // pending cleared → no further make
        $b->incr('t', 'k3', 'v');         // still no further make (col is a string slot; value coerces)

        self::assertSame(2, $primary->makeAttempts, 'make is retried exactly once, then the pending entry is cleared');
        self::assertSame(1, $primary->makeSuccesses);
    }

    public function testWriteSucceedsAfterMultipleMakeFailuresOncePrimaryRecovers(): void
    {
        // make() fails twice, succeeds on the 3rd attempt.
        $primary  = new MakeFlakyBackend(makeFailures: 2);
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 5);

        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]); // attempt 1 fails
        // First write: retry attempt 2 fails → write records failure → throws.
        try {
            $b->set('t', 'k', ['v' => 'x']);
            $this->fail('expected the retry+write to throw while primary still failing');
        } catch (StoreException) {
            // expected
        }
        self::assertSame(2, $primary->makeAttempts);

        // Second write: retry attempt 3 succeeds → write lands.
        self::assertTrue($b->set('t', 'k', ['v' => 'recovered']));
        self::assertSame(3, $primary->makeAttempts);
        self::assertSame(['v' => 'recovered'], $primary->get('t', 'k'));
    }

    // ── #255: half-open single-probe admission ──────────────────────────

    public function testAdmitHalfOpenProbeLetsExactlyOneCallerThrough(): void
    {
        $primary  = new ControllableBackendProxy();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1);

        // Drive into HALF_OPEN_READY directly via the state Atomic.
        $stateProp = new ReflectionProperty(CircuitBreakerBackend::class, 'state');
        /** @var \OpenSwoole\Atomic $state */
        $state = $stateProp->getValue($b);
        $state->set(2); // HALF_OPEN_READY

        $admit = new ReflectionMethod(CircuitBreakerBackend::class, 'admitHalfOpenProbe');

        // Simulate a herd: many callers try to claim the single probe slot.
        $wins = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($admit->invoke($b) === true) { $wins++; }
        }
        self::assertSame(1, $wins, 'the atomic CAS admits exactly one probe, the rest are turned away');
        self::assertSame('half-open', $b->state(), 'state is HALF_OPEN_PROBING after the single admission');
    }

    public function testConcurrentHalfOpenReadersOnlyOneHitsPrimary(): void
    {
        $primary  = new ControllableBackendProxy();
        $fallback = new TableBackend();
        // openDurationSec=0 so the breaker becomes half-open-ready on the very
        // next call after it opens.
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1, openDurationSec: 0);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);
        $fallback->set('t', 'k', ['v' => 'from-fallback']);

        // Trip the breaker open (threshold 1).
        $primary->shouldThrow = true;
        try { $b->get('t', 'k'); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        // Heal the primary, and make the admitted probe BLOCK on a channel so it
        // holds the PROBING state while the herd arrives.
        $primary->shouldThrow = false;
        $primary->callCount = 0;

        Coroutine::run(function () use ($b, $primary): void {
            $gate = new Channel(1);
            $primary->blockOn = $gate;      // the probe will park here until released
            $done = new Channel(8);

            // Spawn 6 concurrent readers; whichever wins the CAS probes (and parks),
            // the rest must take the fallback without touching the primary.
            for ($i = 0; $i < 6; $i++) {
                go(function () use ($b, $done): void {
                    $b->get('t', 'k');
                    $done->push(1);
                });
            }

            // Give the losers time to fall through to the fallback while the
            // single prober is parked.
            (new Channel(1))->pop(0.15);
            self::assertSame(1, $primary->callCount, 'exactly one coroutine reached the primary in half-open');

            $gate->push(1);                 // release the parked prober
            for ($i = 0; $i < 6; $i++) { $done->pop(1.0); }
        });

        // After the probe succeeds the breaker is CLOSED again.
        self::assertSame('closed', $b->state());
    }
}
