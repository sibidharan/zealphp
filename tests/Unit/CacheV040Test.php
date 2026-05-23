<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Store;

/**
 * Patch-coverage for the C-1/C-2/C-3 + stats/file-tier paths on Cache
 * that the broader CacheTest doesn't directly exercise.
 */
final class CacheV040Test extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->cacheDir = sys_get_temp_dir() . '/zealphp-cache-test-' . bin2hex(random_bytes(3));
        Cache::initForTest($this->cacheDir, 256);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') as $f) { @unlink($f); }
            @rmdir($this->cacheDir);
        }
    }

    public function testGetOrComputeCachesResult(): void
    {
        $computes = 0;
        $r1 = Cache::getOrCompute('k', function () use (&$computes) {
            $computes++;
            return "value-$computes";
        });
        $r2 = Cache::getOrCompute('k', function () use (&$computes) {
            $computes++;
            return "value-$computes";
        });
        $this->assertSame('value-1', $r1);
        $this->assertSame('value-1', $r2, 'second call serves from cache');
        $this->assertSame(1, $computes, 'compute fired once');
    }

    public function testGetOrComputeCachesNullValueViaSentinel(): void
    {
        $computes = 0;
        $r1 = Cache::getOrCompute('null-key', function () use (&$computes) {
            $computes++;
            return null;
        });
        $r2 = Cache::getOrCompute('null-key', function () use (&$computes) {
            $computes++;
            return null;
        });
        $this->assertNull($r1);
        $this->assertNull($r2);
        $this->assertSame(1, $computes, 'stored null distinguished from miss');
    }

    public function testSetWithTtlExpires(): void
    {
        Cache::set('ttl-key', 'value', 1);   // 1-second TTL
        $this->assertSame('value', Cache::get('ttl-key'));
        sleep(2);
        $this->assertNull(Cache::get('ttl-key'));
    }

    public function testHasReturnsFalseForExpired(): void
    {
        Cache::set('h-key', 'value', 1);
        $this->assertTrue(Cache::has('h-key'));
        sleep(2);
        $this->assertFalse(Cache::has('h-key'));
    }

    public function testDelRemovesEntry(): void
    {
        Cache::set('del-key', 'v');
        $this->assertTrue(Cache::del('del-key'));
        $this->assertNull(Cache::get('del-key'));
    }

    public function testFlushClearsEverything(): void
    {
        Cache::set('k1', 'v1');
        Cache::set('k2', 'v2');
        Cache::flush();
        $this->assertNull(Cache::get('k1'));
        $this->assertNull(Cache::get('k2'));
    }

    public function testClearAliasReturnsTrue(): void
    {
        Cache::set('k', 'v');
        $this->assertTrue(Cache::clear());
        $this->assertNull(Cache::get('k'));
    }

    public function testDeleteAliasWorks(): void
    {
        Cache::set('k', 'v');
        $this->assertTrue(Cache::delete('k'));
        $this->assertNull(Cache::get('k'));
    }

    public function testStatsExposesNewCountersForV040(): void
    {
        $s = Cache::stats();
        foreach (['memory_entries', 'hits_memory', 'hits_file', 'misses',
                  'spills_oversize', 'spills_full', 'stampede_blocked',
                  'file_rotations', 'tag_invalidations', 'hit_rate'] as $key) {
            $this->assertArrayHasKey($key, $s, "stats() missing key: $key");
        }
        $this->assertIsInt($s['memory_entries']);
        $this->assertIsFloat($s['hit_rate']);
    }

    public function testStatsHitRateClimbs(): void
    {
        Cache::set('hr', 'value');
        Cache::get('hr');
        Cache::get('hr');
        $s = Cache::stats();
        $this->assertGreaterThan(0, $s['hits_memory']);
        $this->assertGreaterThan(0.0, $s['hit_rate']);
    }

    public function testGcFilesRunsWithoutError(): void
    {
        // Write a few entries with mixed TTLs.
        Cache::set('exp-1', 'x', 1);   // expires in 1s
        Cache::set('keep',  'y');       // no TTL
        sleep(2);
        Cache::gcFiles();
        // The keep entry survives; the expired one is gone.
        $this->assertSame('y', Cache::get('keep'));
    }

    public function testInvalidateTagOnTableBackendIsGracefulNoOp(): void
    {
        // C-3 — tag invalidation requires Redis. On Table, the call
        // logs a warning to error_log + returns 0.
        Cache::set('user:42:profile', ['name' => 'alice'], 0, ['user:42']);
        $dropped = Cache::invalidateTag('user:42');
        $this->assertSame(0, $dropped);
        // The cache entry is NOT invalidated on Table backend.
        $this->assertSame(['name' => 'alice'], Cache::get('user:42:profile'));
    }

    public function testCountReportsEntries(): void
    {
        Cache::set('a', 'x');
        Cache::set('b', 'y');
        $this->assertGreaterThanOrEqual(2, Cache::count());
    }

    public function testSetLargeValueSpillsToFileTier(): void
    {
        $big = str_repeat('x', 16 * 1024);   // larger than the 8KB memory-tier max
        $this->assertTrue(Cache::set('big', $big));
        $this->assertSame($big, Cache::get('big'));
        $s = Cache::stats();
        $this->assertGreaterThan(0, $s['spills_oversize']);
    }

    public function testGetMissReturnsDefault(): void
    {
        $this->assertSame('default', Cache::get('nope', 'default'));
    }
}
