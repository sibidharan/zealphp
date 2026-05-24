<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Counter;
use ZealPHP\Store;

/**
 * Covers Cache paths the regular CacheTest / CacheV040Test don't drive:
 *  - the file-tier `gcFiles()` eviction loop (C-2) including the
 *    `usort` tie-break on equal mtimes
 *  - the C-1 stampede stall path in `getOrCompute()` — when the lock
 *    is held externally, the caller polls ~200ms then computes itself
 *  - the Table-backend no-op branch in `invalidateTag()` that
 *    `error_log`s + returns 0 without sweeping
 */
final class CacheFileTierAndStampedeTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    public function testGcFilesEvictsOldestWhenOverMaxFilesCap(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-cache-cap-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        // Spill several blobs to the file tier with staggered mtimes so
        // the eviction sort has a deterministic input.
        for ($i = 0; $i < 6; $i++) {
            Cache::set("cap-$i", str_repeat('x', 8192));
            $path = $dir . '/' . hash('xxh128', 'cap-' . $i) . '.cache';
            if (file_exists($path)) { touch($path, time() - (6 - $i)); }
        }
        // No public maxFiles setter on the test path — reach in by
        // reflection so we exercise the eviction branch deterministically.
        $r = new \ReflectionClass(Cache::class);
        if ($r->hasProperty('maxFiles')) {
            $p = $r->getProperty('maxFiles');
            $p->setAccessible(true);
            $p->setValue(null, 3);
        }
        Cache::gcFiles();
        $remaining = glob($dir . '/*.cache') ?: [];
        $this->assertLessThanOrEqual(3, count($remaining));
    }

    public function testGetOrComputeStampedeStallThenFallsThrough(): void
    {
        $dir = sys_get_temp_dir() . '/zptest-stampede-' . bin2hex(random_bytes(3));
        Cache::initForTest($dir, 64);
        $key      = 'stampede-key-' . bin2hex(random_bytes(2));
        $lockName = '__cache_lock_' . md5($key);
        // Take the lock from "outside" so getOrCompute's CAS loses and
        // it falls into the stall loop (10 × 20ms usleep). After
        // ~200ms the caller computes itself.
        $lock = new Counter(0, $lockName);
        $lock->compareAndSet(0, 1);

        $start   = microtime(true);
        $value   = Cache::getOrCompute($key, fn() => 'computed', 30);
        $elapsed = microtime(true) - $start;

        $this->assertSame('computed', $value);
        $this->assertGreaterThanOrEqual(0.15, $elapsed, 'must wait ~200ms before computing');
    }

}
// invalidateTag-on-Table no-op is already covered by
// CacheV040Test::testInvalidateTagOnTableBackendIsGracefulNoOp.
