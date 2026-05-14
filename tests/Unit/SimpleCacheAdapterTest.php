<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Cache;
use ZealPHP\Cache\SimpleCacheAdapter;
use ZealPHP\Cache\InvalidCacheKeyException;
use Psr\SimpleCache\CacheInterface;

class SimpleCacheAdapterTest extends TestCase
{
    private static string $cacheDir;
    private static bool $initialized = false;
    private SimpleCacheAdapter $adapter;

    public static function setUpBeforeClass(): void
    {
        if (!self::$initialized) {
            self::$cacheDir = sys_get_temp_dir() . '/zealphp_psr16_test_' . uniqid();
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

    protected function setUp(): void
    {
        $this->adapter = new SimpleCacheAdapter();
    }

    protected function tearDown(): void
    {
        Cache::flush();
    }

    public function testImplementsCacheInterface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->adapter);
    }

    public function testGetAndSet(): void
    {
        $this->assertTrue($this->adapter->set('greeting', 'hello world'));
        $this->assertSame('hello world', $this->adapter->get('greeting'));
    }

    public function testGetDefault(): void
    {
        $this->assertNull($this->adapter->get('nonexistent'));
        $this->assertSame('fallback', $this->adapter->get('nonexistent', 'fallback'));
    }

    public function testDelete(): void
    {
        $this->adapter->set('doomed', 'bye');
        $this->assertTrue($this->adapter->has('doomed'));
        $this->adapter->delete('doomed');
        $this->assertFalse($this->adapter->has('doomed'));
    }

    public function testClear(): void
    {
        $this->adapter->set('a', 1);
        $this->adapter->set('b', 2);
        $result = $this->adapter->clear();
        $this->assertTrue($result);
        $this->assertFalse($this->adapter->has('a'));
        $this->assertFalse($this->adapter->has('b'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->adapter->has('key'));
        $this->adapter->set('key', 'value');
        $this->assertTrue($this->adapter->has('key'));
        $this->adapter->delete('key');
        $this->assertFalse($this->adapter->has('key'));
    }

    public function testGetMultiple(): void
    {
        $this->adapter->set('x', 10);
        $this->adapter->set('y', 20);
        $this->adapter->set('z', 30);

        $result = $this->adapter->getMultiple(['x', 'y', 'z', 'missing']);
        $this->assertSame(10, $result['x']);
        $this->assertSame(20, $result['y']);
        $this->assertSame(30, $result['z']);
        $this->assertNull($result['missing']);
    }

    public function testSetMultiple(): void
    {
        $result = $this->adapter->setMultiple(['a' => 'alpha', 'b' => 'beta', 'c' => 'gamma']);
        $this->assertTrue($result);
        $this->assertSame('alpha', $this->adapter->get('a'));
        $this->assertSame('beta', $this->adapter->get('b'));
        $this->assertSame('gamma', $this->adapter->get('c'));
    }

    public function testDeleteMultiple(): void
    {
        $this->adapter->set('d1', 'v1');
        $this->adapter->set('d2', 'v2');
        $this->adapter->set('d3', 'v3');

        $this->adapter->deleteMultiple(['d1', 'd2']);
        $this->assertFalse($this->adapter->has('d1'));
        $this->assertFalse($this->adapter->has('d2'));
        $this->assertTrue($this->adapter->has('d3'));
    }

    public function testDateIntervalTtl(): void
    {
        $interval = new \DateInterval('PT1S'); // 1 second
        $this->adapter->set('expiring', 'soon', $interval);
        $this->assertSame('soon', $this->adapter->get('expiring'));
        sleep(2);
        $this->assertNull($this->adapter->get('expiring'));
    }

    public function testNegativeTtlDeletesEntry(): void
    {
        $this->adapter->set('alive', 'yes');
        $this->assertTrue($this->adapter->has('alive'));

        $this->adapter->set('alive', 'no', -1);
        $this->assertFalse($this->adapter->has('alive'));
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->adapter->get('invalid{key}');
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        $this->adapter->get('');
    }
}
