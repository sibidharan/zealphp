<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Counter;

use ZealPHP\Counter\RedisCounterBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Patch-coverage for the N-1..N-4 / S-2 paths the existing
 * RedisCounterBackendTest doesn't directly exercise — Lua-backed
 * setIfAbsent, incrBounded, expire, mincr.
 */
final class RedisCounterBackendFullTest extends RedisTestCase
{
    private function backend(): RedisCounterBackend
    {
        return new RedisCounterBackend(new RedisConnectionPool($this->url, 4), 'rcfull-' . bin2hex(random_bytes(2)));
    }

    public function testSetIfAbsentReturnsTrueOnFirstWrite(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'sia-' . bin2hex(random_bytes(3));
            $this->assertTrue($b->setIfAbsent($name, 100));
            $this->assertSame(100, $b->get($name));
        });
    }

    public function testSetIfAbsentReturnsFalseWhenAlreadySet(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'sia-existing-' . bin2hex(random_bytes(3));
            $b->set($name, 42);
            $this->assertFalse($b->setIfAbsent($name, 999));
            $this->assertSame(42, $b->get($name), 'existing value preserved');
        });
    }

    public function testIncrBoundedAcceptsWithinCap(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'bound-' . bin2hex(random_bytes(3));
            $b->set($name, 0);
            $this->assertSame(1, $b->incrBounded($name, 1, 3));
            $this->assertSame(2, $b->incrBounded($name, 1, 3));
            $this->assertSame(3, $b->incrBounded($name, 1, 3));
            $this->assertNull($b->incrBounded($name, 1, 3), 'cap reached → null');
            $this->assertSame(3, $b->get($name), 'value unchanged on cap');
        });
    }

    public function testIncrBoundedBiggerStep(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'bound2-' . bin2hex(random_bytes(3));
            $b->set($name, 0);
            $this->assertSame(5, $b->incrBounded($name, 5, 10));
            $this->assertNull($b->incrBounded($name, 6, 10), '5 + 6 > 10');
        });
    }

    /**
     * #242 — a legitimately NEGATIVE result must be returned (and persisted),
     * NOT collapsed to null. The old code used `return -1` as the cap sentinel
     * AND for the value, so `($v < 0) ? null` lied about any negative outcome.
     * The structured `{ok, val}` Lua reply separates "capped" from "value is
     * negative": base 0 + incrBounded(-7, 100) → -7, written, returned.
     */
    public function testIncrBoundedReturnsNegativeResultInsteadOfNull(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'neg-' . bin2hex(random_bytes(3));
            $b->set($name, 0);
            $this->assertSame(-7, $b->incrBounded($name, -7, 100), 'negative result is returned, not null');
            $this->assertSame(-7, $b->get($name), 'negative result was persisted');
            // A further negative step that stays under the cap also returns + persists.
            $this->assertSame(-10, $b->incrBounded($name, -3, 100));
            $this->assertSame(-10, $b->get($name));
        });
    }

    /**
     * #242 — the cap path returns null AND leaves the stored value untouched,
     * with the structured reply ({0}) — distinct from the negative-value path.
     */
    public function testIncrBoundedCapLeavesValueUnchanged(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'cap-' . bin2hex(random_bytes(3));
            $b->set($name, 5);
            $this->assertNull($b->incrBounded($name, 10, 12), '5 + 10 > 12 → capped → null');
            $this->assertSame(5, $b->get($name), 'value unchanged when capped');
        });
    }

    public function testExpireOnExistingKey(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $name = 'exp-' . bin2hex(random_bytes(3));
            $b->set($name, 42);
            $this->assertTrue($b->expire($name, 60));
        });
    }

    public function testExpireOnMissingKeyReturnsFalse(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertFalse($b->expire('never-set-' . bin2hex(random_bytes(3)), 60));
        });
    }

    public function testMincrPipelinesBatch(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $a = 'mi-a-' . bin2hex(random_bytes(3));
            $bk = 'mi-b-' . bin2hex(random_bytes(3));
            $r = $b->mincr([$a => 5, $bk => 3]);
            $this->assertSame(5, $r[$a]);
            $this->assertSame(3, $r[$bk]);
        });
    }

    public function testMincrEmptyShortCircuits(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertSame([], $b->mincr([]));
        });
    }
}
