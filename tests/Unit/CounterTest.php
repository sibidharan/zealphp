<?php
namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\Counter;

class CounterTest extends TestCase
{
    public function testInitialValue(): void
    {
        $c = new Counter(0);
        $this->assertSame(0, $c->get());
    }

    public function testCustomInitialValue(): void
    {
        $c = new Counter(42);
        $this->assertSame(42, $c->get());
    }

    public function testIncrement(): void
    {
        $c   = new Counter(0);
        $new = $c->increment();
        $this->assertSame(1, $new);
        $this->assertSame(1, $c->get());
    }

    public function testIncrementByN(): void
    {
        $c = new Counter(0);
        $c->increment(5);
        $this->assertSame(5, $c->get());
    }

    public function testDecrement(): void
    {
        $c = new Counter(10);
        $c->decrement();
        $this->assertSame(9, $c->get());
    }

    public function testDecrementByN(): void
    {
        $c = new Counter(10);
        $c->decrement(4);
        $this->assertSame(6, $c->get());
    }

    public function testSet(): void
    {
        $c = new Counter(0);
        $c->set(99);
        $this->assertSame(99, $c->get());
    }

    public function testReset(): void
    {
        $c = new Counter(100);
        $c->reset();
        $this->assertSame(0, $c->get());
    }

    public function testCompareAndSetSuccess(): void
    {
        $c      = new Counter(5);
        $result = $c->compareAndSet(5, 10);
        $this->assertTrue($result);
        $this->assertSame(10, $c->get());
    }

    public function testCompareAndSetFailure(): void
    {
        $c      = new Counter(5);
        $result = $c->compareAndSet(99, 10); // wrong expected
        $this->assertFalse($result);
        $this->assertSame(5, $c->get()); // unchanged
    }

    public function testRaw(): void
    {
        $c = new Counter(7);
        $this->assertInstanceOf(\OpenSwoole\Atomic::class, $c->raw());
    }

    public function testChainedIncrements(): void
    {
        $c = new Counter(0);
        for ($i = 0; $i < 100; $i++) {
            $c->increment();
        }
        $this->assertSame(100, $c->get());
    }
}
