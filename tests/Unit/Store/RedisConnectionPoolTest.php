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
     * Two cors share a pool of size 1: A writes, then signals via a Channel
     * that B is allowed to read. Without HOOK_ALL the predis blocking I/O
     * makes the bare go()→go() spawn order racy at the system-call boundary;
     * the explicit Channel gate makes the data-flow deterministic without
     * depending on Runtime::enableCoroutine(HOOK_ALL) (process-global).
     */
    public function testTwoCoroutinesShareOneConnPool(): void
    {
        $pool = new RedisConnectionPool($this->url, 1);
        $bRead = null;
        \OpenSwoole\Coroutine::run(function () use ($pool, &$bRead): void {
            $signal = new \OpenSwoole\Coroutine\Channel(1);
            $done   = new \OpenSwoole\Coroutine\Channel(2);
            go(function () use ($pool, $signal, $done): void {
                $pool->with(fn(RedisClient $c) => $c->set('shared', 'wrote-by-A'));
                $signal->push(1);  // safe for B to read now
                $done->push(1);
            });
            go(function () use ($pool, $signal, $done, &$bRead): void {
                $signal->pop(2.0);  // block until A finished its set
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

    /**
     * #252 — a post-close() acquire() must THROW, not silently rebuild a fresh
     * N-client pool (which leaks the sockets the rebuilt pool opens). Sync-mode
     * path: close() nulls the lone $syncClient AND sets the new $closed flag, so
     * acquire() can tell "torn down" from "never built".
     */
    public function testAcquireAfterCloseThrowsInSyncMode(): void
    {
        $pool = new RedisConnectionPool($this->url, 4);
        $pool->acquire();  // builds the sync singleton
        $pool->close();

        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/after close|torn down/');
        $pool->acquire();
    }

    /**
     * #252 — same guard inside a coroutine: once close() has drained the
     * channel, a later acquire() throws instead of lazily rebuilding the pool.
     */
    public function testAcquireAfterCloseThrowsInCoroutineMode(): void
    {
        $pool = new RedisConnectionPool($this->url, 2);
        $hit = false;
        \OpenSwoole\Coroutine::run(function () use ($pool, &$hit): void {
            $pool->release($pool->acquire());  // build + return the channel
            $pool->close();
            try {
                $pool->acquire();
                $this->fail('expected StoreException after close()');
            } catch (StoreException $e) {
                $hit = true;
                $this->assertStringContainsString('torn down', $e->getMessage());
            }
        });
        $this->assertTrue($hit);
    }
}
