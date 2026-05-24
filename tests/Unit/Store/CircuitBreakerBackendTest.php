<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store\CircuitBreakerBackend;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;
use ZealPHP\Tests\Unit\Store\Support\ControllableBackend;

/**
 * H4: CircuitBreakerBackend state machine.
 *
 * Tests drive the breaker via a controllable mock primary that can be
 * toggled between "healthy" and "throws StoreException". Reads can fall
 * back to a real TableBackend (so we can verify the fallback path serves
 * data); writes never fall back (verified by expectException).
 */
final class CircuitBreakerBackendTest extends TestCase
{
    public function testClosedStatePassesThroughToPrimary(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 3);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $b->set('t', 'k', ['v' => 'hi']);
        self::assertSame('closed', $b->state());
        self::assertSame(['v' => 'hi'], $b->get('t', 'k'));
    }

    public function testThresholdFailuresTripBreakerOpen(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 3);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);
        $primary->shouldThrow = true;

        // 3 read attempts → 3 failures → OPEN.
        for ($i = 0; $i < 3; $i++) {
            $b->get('t', 'missing'); // each falls back, but failure counted
        }
        self::assertSame('open', $b->state());
    }

    public function testOpenReadUsesFallback(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        // Seed the fallback directly so we can verify reads go there.
        $fallback->set('t', 'cached', ['v' => 'from-fallback']);

        $primary->shouldThrow = true;
        $b->get('t', 'cached'); // trips breaker (threshold=1)
        self::assertSame('open', $b->state());

        // Subsequent read: fast-path fallback, no primary call.
        $primary->callCount = 0;
        $row = $b->get('t', 'cached');
        self::assertSame(['v' => 'from-fallback'], $row);
        self::assertSame(0, $primary->callCount, 'OPEN state must skip primary entirely');
    }

    public function testOpenWriteThrows(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        try { $b->set('t', 'k', ['v' => 'x']); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/refusing write/');
        $b->set('t', 'k', ['v' => 'x']);
    }

    public function testNoFallbackOnOpenReadThrows(): void
    {
        $primary = $this->controllable();
        $b = new CircuitBreakerBackend($primary, fallback: null, failureThreshold: 1);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        try { $b->get('t', 'k'); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/no fallback configured/');
        $b->get('t', 'k');
    }

    public function testHalfOpenSuccessClosesBreaker(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        // openDurationSec=0 means probe immediately on next call after open.
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1, openDurationSec: 0);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        try { $b->get('t', 'k'); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        // Heal primary; next op probes (HALF_OPEN), succeeds, → CLOSED.
        $primary->shouldThrow = false;
        $b->get('t', 'k');
        self::assertSame('closed', $b->state());
    }

    public function testHalfOpenFailureReopensBreaker(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1, openDurationSec: 0);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        try { $b->get('t', 'k'); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        // Probe under continuing failure → reopen.
        $b->get('t', 'k'); // half-open probe, fails, → open
        self::assertSame('open', $b->state());
    }

    public function testResetForcesBreakerClosed(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        try { $b->get('t', 'k'); } catch (StoreException) {}
        self::assertSame('open', $b->state());

        $b->reset();
        self::assertSame('closed', $b->state());
    }

    public function testStatsTrackOpenEvents(): void
    {
        $primary = $this->controllable();
        $fallback = new TableBackend();
        $b = new CircuitBreakerBackend($primary, $fallback, failureThreshold: 1);
        $b->make('t', 16, ['v' => [Table::TYPE_STRING, 16]]);

        $primary->shouldThrow = true;
        $b->get('t', 'k'); // trips breaker
        self::assertSame(1, $b->stats()->get('breaker_opened_total'));

        // Subsequent read in OPEN state → short-circuit
        $b->get('t', 'k');
        self::assertSame(1, $b->stats()->get('breaker_short_circuited_total'));
    }

    private function controllable(): ControllableBackend
    {
        return new ControllableBackend();
    }
}
