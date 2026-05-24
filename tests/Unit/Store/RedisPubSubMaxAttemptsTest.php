<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\RedisPubSub;

/**
 * H10: RedisPubSub max-retry behaviour.
 *
 * Default (maxAttempts=0) preserves the pre-v0.2.41 unbounded-retry contract.
 * When set to N>0, the runner gives up after N consecutive failures.
 *
 * Most of RedisPubSub's runner exercises in-process Redis; this test only
 * verifies the constructor accepts the option and round-trips configuration
 * — the actual give-up-after-N path is a coroutine-runtime concern covered
 * by tests/Integration/StoreBackendIntegrationTest.php (under Redis down).
 */
final class RedisPubSubMaxAttemptsTest extends TestCase
{
    public function testConstructorAcceptsMaxAttemptsZero(): void
    {
        $r = new RedisPubSub('redis://127.0.0.1:9', 'zealstore', 0);
        // No handlers registered → start() is a no-op; isRunning stays false.
        self::assertFalse($r->isRunning());
    }

    public function testConstructorAcceptsMaxAttemptsPositive(): void
    {
        $r = new RedisPubSub('redis://127.0.0.1:9', 'zealstore', 5);
        self::assertFalse($r->isRunning());
    }

    public function testRegisterAndExposeChannels(): void
    {
        $r = new RedisPubSub('redis://127.0.0.1:9', 'zealstore', 3);
        $r->register('chat', fn() => null);
        $r->register('alerts:*', fn() => null);
        self::assertSame(['chat'], $r->exactChannels());
        self::assertSame(['alerts:*'], $r->patternChannels());
    }

    public function testStopIsIdempotentBeforeStart(): void
    {
        $r = new RedisPubSub('redis://127.0.0.1:9', 'zealstore', 2);
        $r->stop();
        self::assertFalse($r->isRunning());
    }
}
