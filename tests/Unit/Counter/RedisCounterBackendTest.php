<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Counter;

use ZealPHP\Counter\RedisCounterBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Tests\Helpers\RedisTestCase;

final class RedisCounterBackendTest extends RedisTestCase
{
    private function backend(): RedisCounterBackend
    {
        return new RedisCounterBackend(new RedisConnectionPool($this->url, 4), 'zptest-counter');
    }

    public function testGetReturnsZeroForUntouchedCounter(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertSame(0, $b->get('untouched'));
        });
    }

    public function testIncrAndDecr(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertSame(1, $b->incr('hits'));
            $this->assertSame(6, $b->incr('hits', 5));
            $this->assertSame(5, $b->decr('hits'));
            $this->assertSame(2, $b->decr('hits', 3));
        });
    }

    public function testSetAndReset(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertTrue($b->set('hits', 42));
            $this->assertSame(42, $b->get('hits'));
            $b->reset('hits');
            $this->assertSame(0, $b->get('hits'));
        });
    }

    public function testCompareAndSetSucceedsViaLua(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->set('cas', 5);
            $this->assertTrue($b->compareAndSet('cas', 5, 10));
            $this->assertSame(10, $b->get('cas'));
        });
    }

    public function testCompareAndSetRejectsMismatch(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->set('cas', 5);
            $this->assertFalse($b->compareAndSet('cas', 6, 10));
            $this->assertSame(5, $b->get('cas'));
        });
    }

    public function testIndependentCountersByName(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->incr('hits', 5);
            $b->incr('errors', 2);
            $this->assertSame(5, $b->get('hits'));
            $this->assertSame(2, $b->get('errors'));
        });
    }
}
