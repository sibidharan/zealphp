<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Counter;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter\MemcachedCounterBackend;

/**
 * Covers MemcachedCounterBackend — increment/decrement/get/set/
 * setIfAbsent/compareAndSet/incrBounded/expire/reset/mincr.
 *
 * Auto-skips when ext-memcached isn't loaded OR memcached server is
 * unreachable. CI tests.yml runs a memcached:1.6 service container.
 */
final class MemcachedCounterBackendTest extends TestCase
{
    private MemcachedCounterBackend $b;

    protected function setUp(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached not loaded');
        }
        $env = getenv('ZEALPHP_MEMCACHED_SERVERS');
        $servers = is_string($env) && $env !== '' ? $env : '127.0.0.1:11211';
        try {
            $this->b = new MemcachedCounterBackend($servers, 'mctest-' . bin2hex(random_bytes(2)));
            // Quick reachability check via add+get.
            $this->b->set('probe', 1);
            if ($this->b->get('probe') !== 1) {
                $this->markTestSkipped('memcached unreachable at ' . $servers);
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('memcached setup failed: ' . $e->getMessage());
        }
    }

    public function testSetGetRoundTrip(): void
    {
        $this->assertTrue($this->b->set('cnt-1', 42));
        $this->assertSame(42, $this->b->get('cnt-1'));
    }

    public function testGetMissReturnsZero(): void
    {
        $this->assertSame(0, $this->b->get('never-set-' . bin2hex(random_bytes(3))));
    }

    public function testSetIfAbsentOnlySetsFirstTime(): void
    {
        $key = 'sia-' . bin2hex(random_bytes(3));
        $this->assertTrue($this->b->setIfAbsent($key, 100));
        $this->assertFalse($this->b->setIfAbsent($key, 999), 'second call must be no-op');
        $this->assertSame(100, $this->b->get($key), 'original value preserved');
    }

    public function testIncrementCreatesAndIncrements(): void
    {
        $key = 'inc-' . bin2hex(random_bytes(3));
        $this->assertSame(1, $this->b->incr($key));
        $this->assertSame(2, $this->b->incr($key));
        $this->assertSame(7, $this->b->incr($key, 5));
    }

    public function testDecrementClampsAtZero(): void
    {
        $key = 'dec-' . bin2hex(random_bytes(3));
        $this->b->set($key, 10);
        $this->assertSame(7, $this->b->decr($key, 3));
        // Memcached decrement clamps at 0 (won't go negative).
        $this->assertSame(0, $this->b->decr($key, 999));
    }

    public function testCompareAndSet(): void
    {
        $key = 'cas-' . bin2hex(random_bytes(3));
        $this->b->set($key, 42);
        $this->assertTrue($this->b->compareAndSet($key, 42, 100), 'expected match → swap');
        $this->assertSame(100, $this->b->get($key));
        $this->assertFalse($this->b->compareAndSet($key, 42, 999), 'stale expected → no swap');
        $this->assertSame(100, $this->b->get($key));
    }

    public function testCompareAndSetFailsForMissingKey(): void
    {
        $key = 'cas-miss-' . bin2hex(random_bytes(3));
        $this->assertFalse($this->b->compareAndSet($key, 0, 1));
    }

    public function testIncrBoundedCaps(): void
    {
        $key = 'ib-' . bin2hex(random_bytes(3));
        $this->b->set($key, 0);
        $this->assertSame(1, $this->b->incrBounded($key, 1, 3));
        $this->assertSame(2, $this->b->incrBounded($key, 1, 3));
        $this->assertSame(3, $this->b->incrBounded($key, 1, 3));
        $this->assertNull($this->b->incrBounded($key, 1, 3), 'over cap → null');
        $this->assertSame(3, $this->b->get($key), 'value unchanged on cap hit');
    }

    public function testExpireTouchUpdatesTtl(): void
    {
        $key = 'exp-' . bin2hex(random_bytes(3));
        $this->b->set($key, 42);
        // touch returns true on existing keys.
        $this->assertTrue($this->b->expire($key, 60));
        // missing key → false
        $this->assertFalse($this->b->expire('nx-' . bin2hex(random_bytes(3)), 60));
    }

    public function testMincrSequentialBatch(): void
    {
        $a = 'mi-a-' . bin2hex(random_bytes(3));
        $b = 'mi-b-' . bin2hex(random_bytes(3));
        $r = $this->b->mincr([$a => 5, $b => 3]);
        $this->assertSame(5, $r[$a]);
        $this->assertSame(3, $r[$b]);
        // Subsequent batch accumulates.
        $r2 = $this->b->mincr([$a => 2, $b => 4]);
        $this->assertSame(7, $r2[$a]);
        $this->assertSame(7, $r2[$b]);
    }

    public function testResetClearsToZero(): void
    {
        $key = 'rst-' . bin2hex(random_bytes(3));
        $this->b->set($key, 999);
        $this->b->reset($key);
        $this->assertSame(0, $this->b->get($key));
    }
}
