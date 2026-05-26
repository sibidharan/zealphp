<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Cache;

class CacheTest extends TestCase
{
    private static string $cacheDir;
    private static bool $initialized = false;

    public static function setUpBeforeClass(): void
    {
        if (!self::$initialized) {
            self::$cacheDir = sys_get_temp_dir() . '/zealphp_cache_test_' . getmypid();
            Cache::initForTest(self::$cacheDir);
            self::$initialized = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_dir(self::$cacheDir)) {
            foreach (glob(self::$cacheDir . '/*.cache') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$cacheDir);
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();
    }

    public function testSetAndGetBasic(): void
    {
        Cache::set('greeting', 'hello world');
        $this->assertSame('hello world', Cache::get('greeting'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['name' => 'Alice', 'scores' => [10, 20, 30]];
        Cache::set('user', $data);
        $this->assertEquals($data, Cache::get('user'));
    }

    public function testGetReturnsDefaultOnMiss(): void
    {
        $this->assertNull(Cache::get('nonexistent'));
        $this->assertSame('fallback', Cache::get('nonexistent', 'fallback'));
    }

    public function testTtlExpiry(): void
    {
        Cache::set('ephemeral', 'gone soon', ttl: 1);
        $this->assertSame('gone soon', Cache::get('ephemeral'));
        sleep(2);
        $this->assertNull(Cache::get('ephemeral'));
    }

    public function testNoTtlLivesForever(): void
    {
        Cache::set('permanent', 'stays');
        $this->assertSame('stays', Cache::get('permanent'));
    }

    public function testDelRemovesEntry(): void
    {
        Cache::set('doomed', 'bye');
        $this->assertTrue(Cache::has('doomed'));
        Cache::del('doomed');
        $this->assertFalse(Cache::has('doomed'));
        $this->assertNull(Cache::get('doomed'));
    }

    public function testHasRespectsExpiry(): void
    {
        Cache::set('temp', 'exists', ttl: 1);
        $this->assertTrue(Cache::has('temp'));
        sleep(2);
        $this->assertFalse(Cache::has('temp'));
    }

    public function testLargeValueFallsToFileOnly(): void
    {
        $largeValue = str_repeat('x', 9000);
        Cache::set('big', $largeValue);

        $this->assertSame($largeValue, Cache::get('big'));

        $hash = md5('big');
        $filePath = self::$cacheDir . '/' . $hash . '.cache';
        $this->assertFileExists($filePath);
    }

    public function testFlushClearsBothTiers(): void
    {
        Cache::set('a', 1);
        Cache::set('b', 2);
        Cache::set('c', 3);
        $this->assertTrue(Cache::has('a'));

        Cache::flush();

        $this->assertFalse(Cache::has('a'));
        $this->assertFalse(Cache::has('b'));
        $this->assertFalse(Cache::has('c'));
    }

    public function testOverwriteExistingKey(): void
    {
        Cache::set('key', 'v1');
        Cache::set('key', 'v2');
        $this->assertSame('v2', Cache::get('key'));
    }

    public function testCountReflectsMemoryTier(): void
    {
        Cache::set('x', 1);
        Cache::set('y', 2);
        $this->assertGreaterThanOrEqual(2, Cache::count());
    }

    public function testGcMemoryCleansExpired(): void
    {
        Cache::set('gc-target', 'old', ttl: 1);
        sleep(2);
        Cache::gcMemory();
        $this->assertSame(0, Cache::count());
    }

    // ---------------------------------------------------------------------
    // Added coverage: branches CacheTest above does not exercise.
    // ---------------------------------------------------------------------

    public function testDeleteAliasMatchesDel(): void
    {
        Cache::set('alias-del', 'value');
        $this->assertTrue(Cache::has('alias-del'));
        $this->assertTrue(Cache::delete('alias-del'));
        $this->assertFalse(Cache::has('alias-del'));
    }

    public function testDeleteMissingKeyReturnsFalse(): void
    {
        // Neither tier holds the key => del()/delete() report no removal.
        $this->assertFalse(Cache::delete('never-existed-key'));
    }

    public function testClearAliasReturnsTrueAndEmptiesCache(): void
    {
        Cache::set('cl1', 1);
        Cache::set('cl2', 2);
        $this->assertTrue(Cache::clear());
        $this->assertFalse(Cache::has('cl1'));
        $this->assertFalse(Cache::has('cl2'));
    }

    public function testFileTierFallbackWhenMemoryEvicted(): void
    {
        // Write to both tiers, then drop the memory row directly so get()
        // must fall through to readFile().
        Cache::set('two-tier', 'persisted');
        $hash = md5('two-tier');
        $table = \ZealPHP\Store::table('__cache');
        $this->assertNotNull($table);
        $table->del($hash);

        // Memory miss => file hit.
        $this->assertSame('persisted', Cache::get('two-tier'));
    }

    public function testHasFileTierForValidUnexpiredFile(): void
    {
        // Memory evicted, valid file remains => has() returns true via the
        // file-tier fopen/fgets path.
        Cache::set('has-file', 'x', ttl: 60);
        $hash = md5('has-file');
        $table = \ZealPHP\Store::table('__cache');
        $this->assertNotNull($table);
        $table->del($hash);

        $this->assertTrue(Cache::has('has-file'));
    }

    public function testHasFileTierDeletesExpiredFile(): void
    {
        Cache::set('has-expired-file', 'x', ttl: 1);
        $hash = md5('has-expired-file');
        $table = \ZealPHP\Store::table('__cache');
        $this->assertNotNull($table);
        // Evict memory so has() consults the file, then let it expire.
        $table->del($hash);
        sleep(2);

        $filePath = self::$cacheDir . '/' . $hash . '.cache';
        $this->assertFileExists($filePath);
        $this->assertFalse(Cache::has('has-expired-file'));
        // Expired file is unlinked as a side effect.
        $this->assertFileDoesNotExist($filePath);
    }

    public function testGetExpiredFileReturnsDefault(): void
    {
        Cache::set('exp-file', 'value', ttl: 1);
        $hash = md5('exp-file');
        $table = \ZealPHP\Store::table('__cache');
        $this->assertNotNull($table);
        $table->del($hash);
        sleep(2);

        // readFile() sees an expired TTL => unlinks and returns null => default.
        $this->assertSame('fallback', Cache::get('exp-file', 'fallback'));
    }

    public function testGetCorruptFileWithoutNewlineReturnsDefault(): void
    {
        // A cache file with no newline separator is malformed => readFile()
        // unlinks it and returns null.
        $hash = md5('corrupt-key');
        $filePath = self::$cacheDir . '/' . $hash . '.cache';
        file_put_contents($filePath, 'no-newline-here');

        $this->assertSame('def', Cache::get('corrupt-key', 'def'));
        $this->assertFileDoesNotExist($filePath);
    }

    public function testGcFilesCleansExpiredFilesOnly(): void
    {
        Cache::set('gc-file-old', 'old', ttl: 1);
        Cache::set('gc-file-fresh', 'fresh', ttl: 3600);
        $oldHash = md5('gc-file-old');
        $freshHash = md5('gc-file-fresh');

        sleep(2);
        Cache::gcFiles();

        $this->assertFileDoesNotExist(self::$cacheDir . '/' . $oldHash . '.cache');
        $this->assertFileExists(self::$cacheDir . '/' . $freshHash . '.cache');
    }

    public function testStatsReflectHitsAndMisses(): void
    {
        // Fresh start so counters are predictable.
        Cache::flush();
        $before = Cache::stats();

        Cache::set('stat-key', 'v');
        Cache::get('stat-key');     // memory hit
        Cache::get('stat-missing'); // miss

        $after = Cache::stats();

        $this->assertArrayHasKey('memory_entries', $after);
        $this->assertArrayHasKey('hits_memory', $after);
        $this->assertArrayHasKey('hits_file', $after);
        $this->assertArrayHasKey('misses', $after);
        $this->assertArrayHasKey('spills_oversize', $after);
        $this->assertArrayHasKey('spills_full', $after);
        $this->assertArrayHasKey('hit_rate', $after);

        $this->assertGreaterThan($before['hits_memory'], $after['hits_memory']);
        $this->assertGreaterThan($before['misses'], $after['misses']);
        $this->assertGreaterThanOrEqual(0.0, $after['hit_rate']);
        $this->assertLessThanOrEqual(1.0, $after['hit_rate']);
    }

    public function testStatsTracksOversizeSpill(): void
    {
        $before = Cache::stats();
        // Value > 8KB skips the memory tier => spills_oversize increments.
        Cache::set('oversize', str_repeat('y', 9000));
        $after = Cache::stats();

        $this->assertGreaterThan($before['spills_oversize'], $after['spills_oversize']);
    }

    public function testGcFilesNoOpWhenDirMissing(): void
    {
        // Point the cache at a non-existent directory; gcFiles() must early
        // return without error, then restore the real dir for later tests.
        $missing = self::$cacheDir . '_does_not_exist_' . uniqid();
        $dirProp = new \ReflectionProperty(Cache::class, 'dir');
        $dirProp->setAccessible(true);
        $original = $dirProp->getValue();
        $dirProp->setValue(null, $missing);

        try {
            Cache::gcFiles();
            $this->assertDirectoryDoesNotExist($missing);
        } finally {
            $dirProp->setValue(null, $original);
        }
    }

    public function testMgetReturnsMultipleKeys(): void
    {
        Cache::set('a', 'alpha');
        Cache::set('b', 'bravo');
        Cache::set('c', 'charlie');

        $result = Cache::mget(['a', 'b', 'c']);
        $this->assertSame('alpha', $result['a']);
        $this->assertSame('bravo', $result['b']);
        $this->assertSame('charlie', $result['c']);
    }

    public function testMgetOmitsMissingKeys(): void
    {
        Cache::set('x', 'exists');
        $result = Cache::mget(['x', 'y', 'z']);

        $this->assertArrayHasKey('x', $result);
        $this->assertSame('exists', $result['x']);
        $this->assertArrayNotHasKey('y', $result);
        $this->assertArrayNotHasKey('z', $result);
    }

    public function testMgetSkipsExpired(): void
    {
        Cache::set('fresh', 'yes', 3600);
        Cache::set('stale', 'no', 1);
        sleep(2);

        $result = Cache::mget(['fresh', 'stale']);
        $this->assertArrayHasKey('fresh', $result);
        $this->assertArrayNotHasKey('stale', $result);
    }

    public function testMgetEmptyKeysReturnsEmpty(): void
    {
        $this->assertSame([], Cache::mget([]));
    }

    public function testMsetStoresMultipleKeys(): void
    {
        $count = Cache::mset(['m1' => 'one', 'm2' => 'two', 'm3' => 'three']);
        $this->assertSame(3, $count);
        $this->assertSame('one', Cache::get('m1'));
        $this->assertSame('two', Cache::get('m2'));
        $this->assertSame('three', Cache::get('m3'));
    }

    public function testMsetWithTtl(): void
    {
        Cache::mset(['t1' => 'val1', 't2' => 'val2'], 3600);
        $this->assertSame('val1', Cache::get('t1'));
        $this->assertSame('val2', Cache::get('t2'));
    }

    public function testMsetEmptyReturnsZero(): void
    {
        $this->assertSame(0, Cache::mset([]));
    }

    public function testMgetReadsArrayValues(): void
    {
        Cache::set('arr', ['nested' => true]);
        $result = Cache::mget(['arr']);
        $this->assertSame(['nested' => true], $result['arr']);
    }

    public function testInitConfiguresFreshCacheDir(): void
    {
        // Drive the production init() path (distinct from initForTest used by
        // the rest of the suite). gcIntervalMs: 0 skips the server-bound GC
        // timer so no running OpenSwoole server is required.
        $initProp = new \ReflectionProperty(Cache::class, 'initialized');
        $initProp->setAccessible(true);
        $initProp->setValue(null, false);

        // An explicit cacheDir is passed, so init() never reads App::$cwd.
        $freshDir = sys_get_temp_dir() . '/zealphp_cache_init_' . uniqid();

        try {
            Cache::init(maxRows: 16, cacheDir: $freshDir, gcIntervalMs: 0);
            $this->assertDirectoryExists($freshDir);

            Cache::set('init-key', 'init-val');
            $this->assertSame('init-val', Cache::get('init-key'));

            // Second init() is a no-op (already-initialized guard).
            Cache::init(maxRows: 16, cacheDir: $freshDir, gcIntervalMs: 0);
        } finally {
            // Restore the shared test cache so subsequent tests in this class
            // (and the rest of the suite) keep working.
            foreach (glob($freshDir . '/*.cache') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($freshDir);
            Cache::initForTest(self::$cacheDir);
        }
    }
}
