<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\CircuitBreakerBackend;
use ZealPHP\Store\TableBackend;
use ZealPHP\Tests\Unit\Store\Support\ControllableBackend;

/**
 * Patch-coverage: the StoreBackend method-delegation surface on
 * CircuitBreakerBackend (everything OTHER than the 3-state machine
 * that's already covered in CircuitBreakerBackendTest).
 *
 * Exercises every backend method (make/set/get/del/exists/incr/decr/
 * count/names/iterate/iteratePaged/clear/mget/mset) through the closed-
 * state pass-through path, the open-state fallback path, and the
 * no-fallback throw path — the three branches each method has.
 */
final class CircuitBreakerBackendDelegationTest extends TestCase
{
    private function primary(): ControllableBackend
    {
        $b = new ControllableBackend();
        $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_INT, 8]]);
        return $b;
    }

    private function fallback(): TableBackend
    {
        $b = new TableBackend();
        $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_INT, 8]]);
        return $b;
    }

    private function cb(ControllableBackend $primary, ?TableBackend $fallback = null): CircuitBreakerBackend
    {
        return new CircuitBreakerBackend(
            primary: $primary,
            fallback: $fallback,
            failureThreshold: 2,
            failureWindowSec: 60,
            openDurationSec: 30,
        );
    }

    // ── closed-state pass-through ─────────────────────────────────────

    public function testMakePropagatesToBothBackends(): void
    {
        $p = $this->primary();
        $f = $this->fallback();
        $cb = $this->cb($p, $f);
        $cb->make('newtbl', 50, ['x' => [\OpenSwoole\Table::TYPE_STRING, 32]]);
        $this->assertContains('newtbl', $p->names());
        $this->assertContains('newtbl', $f->names());
    }

    public function testSetReturnsTruthFromPrimary(): void
    {
        $p = $this->primary();
        $cb = $this->cb($p);
        $this->assertTrue($cb->set('t', 'k1', ['v' => 42]));
        $this->assertSame(42, $cb->get('t', 'k1', 'v'));
    }

    public function testDelExistsRoundTrip(): void
    {
        $cb = $this->cb($this->primary());
        $cb->set('t', 'k1', ['v' => 1]);
        $this->assertTrue($cb->exists('t', 'k1'));
        $this->assertTrue($cb->del('t', 'k1'));
        $this->assertFalse($cb->exists('t', 'k1'));
    }

    public function testIncrDecrCountAndNames(): void
    {
        $cb = $this->cb($this->primary());
        $cb->set('t', 'k1', ['v' => 10]);
        $this->assertSame(11, $cb->incr('t', 'k1', 'v'));
        $this->assertSame(13, $cb->incr('t', 'k1', 'v', 2));
        $this->assertSame(12, $cb->decr('t', 'k1', 'v'));
        $this->assertSame(1,  $cb->count('t'));
        $this->assertContains('t', $cb->names());
    }

    public function testIterateYieldsRowsThroughClosedState(): void
    {
        $cb = $this->cb($this->primary());
        $cb->set('t', 'a', ['v' => 1]);
        $cb->set('t', 'b', ['v' => 2]);
        $keys = [];
        foreach ($cb->iterate('t') as $k => $row) { $keys[] = $k; }
        sort($keys);
        $this->assertSame(['a', 'b'], $keys);
    }

    public function testIteratePagedThroughClosedState(): void
    {
        $cb = $this->cb($this->primary());
        for ($i = 0; $i < 10; $i++) { $cb->set('t', "k$i", ['v' => $i]); }
        $page = $cb->iteratePaged('t', '0', 100);
        $this->assertSame('0', $page['cursor']);
        $this->assertCount(10, $page['rows']);
    }

    public function testClearPassesThrough(): void
    {
        $cb = $this->cb($this->primary());
        $cb->set('t', 'k1', ['v' => 1]);
        $cb->clear('t');
        $this->assertSame(0, $cb->count('t'));
    }

    public function testMgetMsetThroughClosedState(): void
    {
        $cb = $this->cb($this->primary());
        $this->assertTrue($cb->mset('t', ['a' => ['v' => 1], 'b' => ['v' => 2]]));
        $r = $cb->mget('t', ['a', 'b', 'missing']);
        $this->assertSame(['a','b','missing'], array_keys($r));
        $this->assertIsArray($r['a']);
        $this->assertNull($r['missing']);
    }

    // ── open-state fallback path ──────────────────────────────────────

    public function testOpenStateReadsFromFallback(): void
    {
        $p = $this->primary();
        $f = $this->fallback();
        $cb = $this->cb($p, $f);
        $f->set('t', 'fallback-only', ['v' => 99]);
        // Force open
        $p->shouldThrow = true;
        try { $cb->get('t', 'never'); } catch (\Throwable $e) {}
        try { $cb->get('t', 'never'); } catch (\Throwable $e) {}
        $this->assertSame('open', $cb->state());
        // From here, reads should go to fallback.
        $this->assertSame(99, $cb->get('t', 'fallback-only', 'v'));
    }

    public function testOpenStateCountIterateNamesUseFallback(): void
    {
        $p = $this->primary();
        $f = $this->fallback();
        $cb = $this->cb($p, $f);
        $f->set('t', 'a', ['v' => 1]);
        $f->set('t', 'b', ['v' => 2]);
        // Trip the breaker
        $p->shouldThrow = true;
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        $this->assertSame('open', $cb->state());
        $this->assertSame(2, $cb->count('t'));
        $names = $cb->names();
        $this->assertContains('t', $names);
        $keys = [];
        foreach ($cb->iterate('t') as $k => $_) { $keys[] = $k; }
        sort($keys);
        $this->assertSame(['a','b'], $keys);
    }

    public function testIteratePagedFallsBackOnOpen(): void
    {
        $p = $this->primary();
        $f = $this->fallback();
        $cb = $this->cb($p, $f);
        for ($i = 0; $i < 5; $i++) { $f->set('t', "k$i", ['v' => $i]); }
        $p->shouldThrow = true;
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        $page = $cb->iteratePaged('t', '0', 100);
        $this->assertSame('0', $page['cursor']);
        $this->assertCount(5, $page['rows']);
    }

    public function testWritesThrowOnOpenWithoutFallback(): void
    {
        $p = $this->primary();
        $cb = $this->cb($p);   // no fallback
        $p->shouldThrow = true;
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $cb->set('t', 'kk', ['v' => 1]);
    }

    public function testIncrOnOpenThrowsEvenWithFallback(): void
    {
        // Writes (incr/decr/del/clear/set/mset) DO NOT fall back — they
        // would diverge from the cluster truth on Redis recovery.
        $p = $this->primary();
        $f = $this->fallback();
        $cb = $this->cb($p, $f);
        $p->shouldThrow = true;
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        try { $cb->get('t', 'x'); } catch (\Throwable $e) {}
        $this->assertSame('open', $cb->state());
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $cb->incr('t', 'k', 'v');
    }

    public function testStatsReturnsStatsInstance(): void
    {
        $cb = $this->cb($this->primary());
        $this->assertInstanceOf(\ZealPHP\Store\Stats::class, $cb->stats());
    }
}
