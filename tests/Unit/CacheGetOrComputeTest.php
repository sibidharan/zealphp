<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Cache;
use ZealPHP\Store;

/**
 * Cache::getOrCompute — the canonical read-through cache helper.
 */
final class CacheGetOrComputeTest extends TestCase
{
    private function freshCache(): void
    {
        $r = new \ReflectionProperty(Cache::class, 'initialized');
        $r->setAccessible(true);
        $r->setValue(null, false);
    }

    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->freshCache();

        // File-tier persists ACROSS test runs — wipe before each test so a
        // prior run's stored values don't pre-satisfy getOrCompute.
        $dir = '/tmp/cache-goc-test-' . bin2hex(random_bytes(3));
        Cache::init(maxRows: 64, cacheDir: $dir);
    }

    public function testFirstCallComputesAndStores(): void
    {
        $count = 0;
        $val = Cache::getOrCompute('k1', function () use (&$count): string {
            $count++;
            return 'computed';
        });
        self::assertSame('computed', $val);
        self::assertSame(1, $count);
    }

    public function testSecondCallSkipsCompute(): void
    {
        $count = 0;
        $cb = function () use (&$count): string {
            $count++;
            return 'computed';
        };
        Cache::getOrCompute('k2', $cb);
        Cache::getOrCompute('k2', $cb);
        Cache::getOrCompute('k2', $cb);
        self::assertSame(1, $count, 'compute called exactly once across 3 fetches of same key');
    }

    public function testDistinctKeysComputeIndependently(): void
    {
        $count = 0;
        $cb = function () use (&$count): int {
            return ++$count;
        };
        $a = Cache::getOrCompute('a', $cb);
        $b = Cache::getOrCompute('b', $cb);
        $a2 = Cache::getOrCompute('a', $cb);
        self::assertSame(1, $a);
        self::assertSame(2, $b);
        self::assertSame(1, $a2, 'cached value of a returned, not re-computed');
        self::assertSame(2, $count);
    }

    public function testNullIsCachedAsAValidValue(): void
    {
        // Distinguishing "stored null" from "miss" is the whole reason the
        // helper uses an internal sentinel — verify a stored null isn't
        // mistaken for a miss on subsequent reads.
        $count = 0;
        $cb = function () use (&$count): ?string {
            $count++;
            return null;
        };
        $a = Cache::getOrCompute('null-key', $cb);
        $b = Cache::getOrCompute('null-key', $cb);
        self::assertSame($a, $b);
        self::assertSame(1, $count, 'second call must not re-compute even though stored value is null');
    }

    public function testTtlIsRespected(): void
    {
        $count = 0;
        $cb = function () use (&$count): string {
            $count++;
            return 'v' . $count;
        };
        $a = Cache::getOrCompute('ttl-test', $cb, 60);
        $b = Cache::getOrCompute('ttl-test', $cb, 60);
        self::assertSame($a, $b);
        self::assertSame(1, $count, 'TTL hasnt expired → cached value reused');
    }
}
