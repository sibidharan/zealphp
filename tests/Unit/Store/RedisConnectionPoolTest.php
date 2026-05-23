<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Tests\Helpers\RedisTestCase;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\RedisClient;
use ZealPHP\Store\StoreException;

final class RedisConnectionPoolTest extends RedisTestCase
{
    public function testSizeIsBoundedAtLeastOne(): void
    {
        $pool = new RedisConnectionPool($this->url, 4);
        $this->assertSame(4, $pool->size());
        $tiny = new RedisConnectionPool($this->url, 0);
        $this->assertSame(1, $tiny->size(), 'size clamps to >= 1');
        $pool->close();
        $tiny->close();
    }

    public function testWithExecutesUnderCoroutineAndReturnsClientToPool(): void
    {
        $pool = new RedisConnectionPool($this->url, 2);
        $seen = [];
        \OpenSwoole\Coroutine::run(function () use ($pool, &$seen): void {
            $seen[] = $pool->with(function (RedisClient $c): string {
                $c->set('p1', 'alpha');
                return $c->get('p1') ?? '';
            });
            // pool now full again — second `with` reuses one of the released clients
            $seen[] = $pool->with(function (RedisClient $c): string {
                $c->set('p2', 'beta');
                return $c->get('p2') ?? '';
            });
        });
        $this->assertSame(['alpha', 'beta'], $seen);
        $pool->close();
    }

    /**
     * Two cors share a pool of size 1: both complete via `with()` and the
     * data written by the first is visible to the second (proving they
     * actually serialized on the SAME client, which got recycled). The
     * stronger "cor B blocked until A released" assertion lives in
     * testAcquireTimesOutInsideCoroutineWhenPoolStarves below.
     */
    public function testTwoCoroutinesShareOneConnPool(): void
    {
        $pool = new RedisConnectionPool($this->url, 1);
        $bRead = null;
        \OpenSwoole\Coroutine::run(function () use ($pool, &$bRead): void {
            $done = new \OpenSwoole\Coroutine\Channel(2);
            go(function () use ($pool, $done): void {
                $pool->with(fn(RedisClient $c) => $c->set('shared', 'wrote-by-A'));
                $done->push(1);
            });
            go(function () use ($pool, $done, &$bRead): void {
                $pool->with(function (RedisClient $c) use (&$bRead): void {
                    $bRead = $c->get('shared');
                });
                $done->push(1);
            });
            $done->pop(2.0);
            $done->pop(2.0);
        });
        $this->assertSame('wrote-by-A', $bRead);
        $pool->close();
    }

    public function testWithReleasesClientEvenWhenCallableThrows(): void
    {
        $pool = new RedisConnectionPool($this->url, 1);
        \OpenSwoole\Coroutine::run(function () use ($pool): void {
            try {
                $pool->with(function (RedisClient $c): void {
                    throw new \RuntimeException('boom');
                });
                $this->fail('expected exception not thrown');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            // Pool of 1 — if release hadn't fired, this next acquire would deadlock.
            $value = $pool->with(fn(RedisClient $c) => $c->ping());
            $this->assertTrue($value);
        });
        $pool->close();
    }

    public function testSyncModeReturnsTheSameClientOnRepeatedAcquires(): void
    {
        // Called from outside a coroutine — should NOT block, returns a
        // singleton client that release() leaves untouched.
        $pool = new RedisConnectionPool($this->url, 4);
        $a = $pool->acquire();
        $b = $pool->acquire();
        $this->assertSame($a, $b, 'sync-mode acquires share one client');
        // release() must be tolerant — the singleton lives outside the channel
        $pool->release($a);
        $pool->release($b);
        $c = $pool->acquire();
        $this->assertSame($a, $c);
        $pool->close();
    }

    public function testAcquireTimesOutInsideCoroutineWhenPoolStarves(): void
    {
        $pool = new RedisConnectionPool($this->url, 1);
        $hit = false;
        \OpenSwoole\Coroutine::run(function () use ($pool, &$hit): void {
            $held = $pool->acquire();   // pool now empty
            try {
                $pool->acquire(0.1);     // should time out
                $this->fail('expected StoreException');
            } catch (StoreException $e) {
                $hit = true;
                $this->assertStringContainsString('timed out', $e->getMessage());
            }
            $pool->release($held);
        });
        $this->assertTrue($hit);
        $pool->close();
    }
}
