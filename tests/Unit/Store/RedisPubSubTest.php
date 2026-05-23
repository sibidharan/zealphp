<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\Store\RedisClient;
use ZealPHP\Store\RedisPubSub;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Drives the RedisPubSub lifecycle class against a live valkey. Each test
 * starts a runner, publishes via a separate RedisClient, asserts the
 * handler ran, then stops the runner cleanly via the sentinel-channel
 * shutdown protocol.
 */
final class RedisPubSubTest extends RedisTestCase
{
    public function testExactChannelHandlerReceivesPublish(): void
    {
        Coroutine::run(function (): void {
            $rendezvous = new Channel(1);
            $pubsub = new RedisPubSub($this->url, 'zptest-pubsub');
            $pubsub->register('t:exact:1', function (string $payload, string $channel) use ($rendezvous): void {
                $rendezvous->push(compact('payload', 'channel'));
            });
            $pubsub->start();

            (new Channel(1))->pop(0.1); // give the subscriber a moment to register

            $pub = new RedisClient($this->url);
            $pub->publish('t:exact:1', 'hello');

            $got = $rendezvous->pop(2.0);
            $pubsub->stop();

            $this->assertIsArray($got);
            $this->assertSame('hello',      $got['payload']);
            $this->assertSame('t:exact:1',  $got['channel']);
        });
    }

    public function testPatternHandlerCatchesMatchingPublish(): void
    {
        Coroutine::run(function (): void {
            $rendezvous = new Channel(1);
            $pubsub = new RedisPubSub($this->url, 'zptest-pubsub');
            $pubsub->register('t:p:*', function (string $payload, string $channel, ?string $pattern) use ($rendezvous): void {
                $rendezvous->push(compact('payload', 'channel', 'pattern'));
            });
            $pubsub->start();

            (new Channel(1))->pop(0.1);

            $pub = new RedisClient($this->url);
            $pub->publish('t:p:room1', 'broadcast');

            $got = $rendezvous->pop(2.0);
            $pubsub->stop();

            $this->assertIsArray($got);
            $this->assertSame('broadcast', $got['payload']);
            $this->assertSame('t:p:room1', $got['channel']);
            $this->assertSame('t:p:*',     $got['pattern']);
        });
    }

    public function testMultipleHandlersAllFireForOneMessage(): void
    {
        Coroutine::run(function (): void {
            $rendezvous = new Channel(3);
            $pubsub = new RedisPubSub($this->url, 'zptest-pubsub');
            for ($i = 0; $i < 3; $i++) {
                $idx = $i;
                $pubsub->register('t:multi', function (string $payload) use ($rendezvous, $idx): void {
                    $rendezvous->push(['idx' => $idx, 'payload' => $payload]);
                });
            }
            $pubsub->start();

            (new Channel(1))->pop(0.1);
            (new RedisClient($this->url))->publish('t:multi', 'broadcast');

            $hits = [];
            for ($i = 0; $i < 3; $i++) { $hits[] = $rendezvous->pop(2.0); }
            $pubsub->stop();

            $idxs = array_map(fn(array $h): int => (int) $h['idx'], $hits);
            sort($idxs);
            $this->assertSame([0, 1, 2], $idxs);
        });
    }

    public function testHandlerThrowDoesNotCrashRunner(): void
    {
        Coroutine::run(function (): void {
            $second = new Channel(1);
            $pubsub = new RedisPubSub($this->url, 'zptest-pubsub');
            $pubsub->register('t:throws', function (): void {
                throw new \RuntimeException('boom');
            });
            $pubsub->register('t:throws', function (string $payload) use ($second): void {
                $second->push($payload);
            });
            $pubsub->start();

            (new Channel(1))->pop(0.1);
            (new RedisClient($this->url))->publish('t:throws', 'survives');

            $value = $second->pop(2.0);
            $pubsub->stop();

            $this->assertSame('survives', $value);
        });
    }

    public function testStopHaltsTheRunner(): void
    {
        Coroutine::run(function (): void {
            $pubsub = new RedisPubSub($this->url, 'zptest-pubsub');
            $pubsub->register('t:stoptest', function (): void {});
            $pubsub->start();
            $this->assertTrue($pubsub->isRunning());

            $pubsub->stop();
            // Give the runner a beat to wind down.
            (new Channel(1))->pop(0.3);
            $this->assertFalse($pubsub->isRunning());
        });
    }
}
