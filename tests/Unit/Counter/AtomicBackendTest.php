<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Counter;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter\AtomicBackend;

final class AtomicBackendTest extends TestCase
{
    public function testGetReturnsZeroForUntouchedCounter(): void
    {
        $b = new AtomicBackend();
        $this->assertSame(0, $b->get('hits'));
    }

    public function testIncrReturnsNewValue(): void
    {
        $b = new AtomicBackend();
        $this->assertSame(1, $b->incr('hits'));
        $this->assertSame(6, $b->incr('hits', 5));
        $this->assertSame(6, $b->get('hits'));
    }

    public function testDecrReturnsNewValue(): void
    {
        $b = new AtomicBackend();
        $b->set('hits', 10);
        $this->assertSame(9, $b->decr('hits'));
        $this->assertSame(5, $b->decr('hits', 4));
    }

    public function testSetAndReset(): void
    {
        $b = new AtomicBackend();
        $b->set('hits', 42);
        $this->assertSame(42, $b->get('hits'));
        $b->reset('hits');
        $this->assertSame(0, $b->get('hits'));
    }

    public function testCompareAndSetSucceedsWhenExpectedMatches(): void
    {
        $b = new AtomicBackend();
        $b->set('cas', 5);
        $this->assertTrue($b->compareAndSet('cas', 5, 10));
        $this->assertSame(10, $b->get('cas'));
    }

    public function testCompareAndSetFailsWhenExpectedMismatches(): void
    {
        $b = new AtomicBackend();
        $b->set('cas', 5);
        $this->assertFalse($b->compareAndSet('cas', 6, 10));
        $this->assertSame(5, $b->get('cas'));
    }

    public function testIndependentCountersByName(): void
    {
        $b = new AtomicBackend();
        $b->incr('hits', 5);
        $b->incr('errors', 2);
        $this->assertSame(5, $b->get('hits'));
        $this->assertSame(2, $b->get('errors'));
    }
}
