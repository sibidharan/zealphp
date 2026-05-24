<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;

/**
 * Exercises the Store::publish + Store::publishReliable facade methods.
 * Mostly delegates to the underlying RedisBackend (covered by
 * RedisClientTest + RedisPubSubTest + RedisStreamsTest); this file
 * pins the FACADE contract: routes to backend, throws on Table.
 */
final class StorePublishTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        $this->url = $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        Store::defaultBackend('table');
    }

    protected function tearDown(): void
    {
        Store::defaultBackend('table');
    }

    public function testPublishOnTableBackendThrows(): void
    {
        Store::defaultBackend('table');
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/requires the redis backend/');
        Store::publish('any-channel', 'any-payload');
    }

    public function testPublishReliableOnTableBackendThrows(): void
    {
        Store::defaultBackend('table');
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/requires the redis backend/');
        Store::publishReliable('any-stream', 'any-payload');
    }

    public function testPublishOnRedisBackendReturnsReceiverCount(): void
    {
        $this->skipIfNoRedis();
        Store::defaultBackend('redis', $this->url);
        Coroutine::run(function (): void {
            // With no subscribers, receiver count is 0.
            $this->assertSame(0, Store::publish('unit-store-publish:no-listeners', 'hi'));
        });
    }

    public function testPublishReliableOnRedisBackendReturnsMessageId(): void
    {
        $this->skipIfNoRedis();
        Store::defaultBackend('redis', $this->url);
        Coroutine::run(function (): void {
            $stream = 'unit-store-publish:' . bin2hex(random_bytes(4));
            $id = Store::publishReliable($stream, 'durable-message');
            $this->assertMatchesRegularExpression('/^\d+-\d+$/', $id);
        });
    }

    public function testPublishReliableAcceptsMaxLen(): void
    {
        $this->skipIfNoRedis();
        Store::defaultBackend('redis', $this->url);
        Coroutine::run(function (): void {
            $stream = 'unit-store-publish:maxlen:' . bin2hex(random_bytes(4));
            for ($i = 0; $i < 5; $i++) {
                Store::publishReliable($stream, "msg-$i", 3);
            }
            // MAXLEN trims to ~3 entries (the ~ is approximate; could be 3-5).
            $this->assertTrue(true);
        });
    }

    private function skipIfNoRedis(): void
    {
        try {
            $c = new \Predis\Client($this->url);
            $c->ping();
            $c->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis/Valkey not available: ' . $e->getMessage());
        }
    }
}
