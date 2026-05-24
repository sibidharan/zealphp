<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Store\RedisStreams;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Patch-coverage for RedisStreams registration + accessor surface
 * (without entering the blocking XREADGROUP loop).
 */
final class RedisStreamsAccessorsTest extends RedisTestCase
{
    public function testConstructWithDefaultConsumerName(): void
    {
        $s = new RedisStreams($this->url);
        $name = $s->consumerName();
        $this->assertNotSame('', $name);
        // Default is host-derived; just verify it's a non-empty string.
        $this->assertIsString($name);
    }

    public function testConstructWithExplicitConsumerName(): void
    {
        $s = new RedisStreams($this->url, 'consumer-A');
        $this->assertSame('consumer-A', $s->consumerName());
    }

    public function testRegisterStoresConsumerConfig(): void
    {
        $s = new RedisStreams($this->url);
        $s->register('orders', 'order-workers', function () { return true; });
        $consumers = $s->consumers();
        $this->assertCount(1, $consumers);
        $this->assertSame('orders',        $consumers[0]['stream']);
        $this->assertSame('order-workers', $consumers[0]['group']);
    }

    public function testRegisterMultipleStreams(): void
    {
        $s = new RedisStreams($this->url);
        $s->register('orders',   'g1', fn() => true);
        $s->register('payments', 'g2', fn() => true);
        $s->register('audit',    'g3', fn() => true);
        $this->assertCount(3, $s->consumers());
    }

    public function testRegisterWithCustomBlockMsAndBatchSize(): void
    {
        $s = new RedisStreams($this->url);
        $s->register('s1', 'g1', fn() => true, blockMs: 5000, batchSize: 32);
        $cfg = $s->consumers()[0];
        $this->assertSame(5000, $cfg['blockMs']);
        $this->assertSame(32,   $cfg['batchSize']);
    }

    public function testIsRunningFalseBeforeStart(): void
    {
        $s = new RedisStreams($this->url);
        $this->assertFalse($s->isRunning());
    }

    public function testStopBeforeStartIsNoOp(): void
    {
        $s = new RedisStreams($this->url);
        $s->stop();
        $this->assertFalse($s->isRunning());
    }

    public function testConsumerNameStableAcrossCalls(): void
    {
        $s = new RedisStreams($this->url, 'stable-name');
        $this->assertSame('stable-name', $s->consumerName());
        $this->assertSame('stable-name', $s->consumerName());
    }
}
