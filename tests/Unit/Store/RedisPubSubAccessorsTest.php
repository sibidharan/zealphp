<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Store\RedisPubSub;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Patch-coverage for RedisPubSub registration + accessor + stats surface
 * — everything that doesn't require entering the blocking SUBSCRIBE loop
 * (the loop itself can't be exercised under PHPUnit with phpredis;
 * see scripts/spike-phpredis-subscribe.php for that path).
 */
final class RedisPubSubAccessorsTest extends RedisTestCase
{
    public function testConstructWithDefaultPrefix(): void
    {
        $ps = new RedisPubSub($this->url);
        $this->assertNotSame('', $ps->stopChannel());
        $this->assertFalse($ps->isRunning());
    }

    public function testConstructWithCustomPrefix(): void
    {
        $ps = new RedisPubSub($this->url, 'custom-prefix');
        $this->assertStringContainsString('custom-prefix', $ps->stopChannel());
    }

    public function testRegisterExactChannel(): void
    {
        $ps = new RedisPubSub($this->url);
        $ps->register('chat:lobby', function (): void {});
        $this->assertSame(['chat:lobby'], $ps->exactChannels());
        $this->assertSame([], $ps->patternChannels());
    }

    public function testRegisterPatternChannel(): void
    {
        $ps = new RedisPubSub($this->url);
        $ps->register('chat:*', function (): void {});
        $this->assertSame([], $ps->exactChannels());
        $this->assertSame(['chat:*'], $ps->patternChannels());
    }

    public function testRegisterMixedExactAndPattern(): void
    {
        $ps = new RedisPubSub($this->url);
        $ps->register('exact:1', function (): void {});
        $ps->register('exact:2', function (): void {});
        $ps->register('pattern:*', function (): void {});
        $ps->register('users:*', function (): void {});
        $this->assertCount(2, $ps->exactChannels());
        $this->assertCount(2, $ps->patternChannels());
    }

    public function testRegisterMultipleHandlersOnSameChannel(): void
    {
        // The runner fires every handler on each message; the registry
        // de-duplicates the channel key.
        $ps = new RedisPubSub($this->url);
        $ps->register('multi', function (): void {});
        $ps->register('multi', function (): void {});
        $ps->register('multi', function (): void {});
        $this->assertSame(['multi'], $ps->exactChannels());
    }

    public function testIsRunningFalseBeforeStart(): void
    {
        $ps = new RedisPubSub($this->url);
        $this->assertFalse($ps->isRunning());
    }

    public function testStatsIsAStatsInstance(): void
    {
        $ps = new RedisPubSub($this->url);
        $this->assertInstanceOf(\ZealPHP\Store\Stats::class, $ps->stats());
    }

    public function testStopBeforeStartIsNoOp(): void
    {
        $ps = new RedisPubSub($this->url);
        $ps->stop();   // graceful no-op when not running
        $this->assertFalse($ps->isRunning());
    }

    public function testStopChannelIsStableAcrossCallsOnSameInstance(): void
    {
        $ps = new RedisPubSub($this->url);
        $c1 = $ps->stopChannel();
        $c2 = $ps->stopChannel();
        $this->assertSame($c1, $c2, 'same instance → same stopChannel');
    }
}
