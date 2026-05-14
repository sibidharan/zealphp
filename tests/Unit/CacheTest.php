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
            foreach (glob(self::$cacheDir . '/*.cache') as $f) {
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
}
