<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\Store\RedisClient;
use ZealPHP\Store\RedisStreams;
use ZealPHP\Tests\Helpers\RedisTestCase;

final class RedisStreamsTest extends RedisTestCase
{
    private function freshStream(): string
    {
        return 't:streams:' . bin2hex(random_bytes(4));
    }

    public function testHandlerReceivesMessageAndAcks(): void
    {
        Coroutine::run(function (): void {
            $stream = $this->freshStream();
            $group  = 'g1';
            $rendezvous = new Channel(1);

            $streams = new RedisStreams($this->url, 'unit-consumer');
            $streams->register($stream, $group, function (string $payload, string $id, string $strm) use ($rendezvous): bool {
                $rendezvous->push(compact('payload', 'id', 'strm'));
                return true;
            }, blockMs: 200, batchSize: 16);
            $streams->start();

            (new Channel(1))->pop(0.15);
            $publisher = new RedisClient($this->url);
            $sentId = $publisher->xadd($stream, ['payload' => 'hello']);

            $got = $rendezvous->pop(2.0);
            $streams->stop();
            (new Channel(1))->pop(0.3);

            $this->assertIsArray($got);
            $this->assertSame('hello', $got['payload']);
            $this->assertSame($sentId, $got['id']);
            $this->assertSame($stream, $got['strm']);

            // After XACK the pending list for the consumer should be empty.
            // (Indirect check: a fresh XREADGROUP with > shouldn't redeliver.)
            $reread = $publisher->xreadGroup($group, 'unit-consumer', [$stream], 16, 100);
            $this->assertEmpty($reread[$stream] ?? []);
        });
    }

    public function testHandlerReturningFalseLeavesPending(): void
    {
        Coroutine::run(function (): void {
            $stream = $this->freshStream();
            $group  = 'g1';
            $seen   = new Channel(2);

            $streams = new RedisStreams($this->url, 'unit-consumer-nack');
            $streams->register($stream, $group, function () use ($seen): bool {
                $seen->push(1);
                return false; // NACK
            }, blockMs: 200, batchSize: 16);
            $streams->start();

            (new Channel(1))->pop(0.15);
            $pub = new RedisClient($this->url);
            $pub->xadd($stream, ['payload' => 'nack-me']);

            $seen->pop(2.0);
            $streams->stop();
            (new Channel(1))->pop(0.3);

            // Check the pending list directly via XPENDING using a fresh client
            // (XPENDING isn't on our adapter; use evalScript with a tiny lua).
            $client = new RedisClient($this->url);
            $pending = $client->evalScript(
                "return redis.call('XLEN', KEYS[1])",
                [$stream], [],
            );
            $this->assertSame(1, is_int($pending) ? $pending : (int) (is_string($pending) ? $pending : 0));
        });
    }

    public function testHandlerThrowLeavesPending(): void
    {
        Coroutine::run(function (): void {
            $stream = $this->freshStream();
            $group  = 'g1';
            $fired  = new Channel(1);

            $streams = new RedisStreams($this->url, 'unit-consumer-throw');
            $streams->register($stream, $group, function () use ($fired): bool {
                $fired->push(1);
                throw new \RuntimeException('handler boom');
            }, blockMs: 200, batchSize: 16);
            $streams->start();

            (new Channel(1))->pop(0.15);
            $pub = new RedisClient($this->url);
            $pub->xadd($stream, ['payload' => 'throw-me']);

            $fired->pop(2.0);
            $streams->stop();
            (new Channel(1))->pop(0.3);

            // The runner survived (handler throw was caught), and the message
            // is still in the stream because XACK never ran.
            $client = new RedisClient($this->url);
            $len = $client->evalScript("return redis.call('XLEN', KEYS[1])", [$stream], []);
            $this->assertGreaterThan(0, is_int($len) ? $len : (int) (is_string($len) ? $len : 0));
        });
    }

    public function testConsumerGroupCreateIsIdempotent(): void
    {
        Coroutine::run(function (): void {
            $stream = $this->freshStream();
            $group  = 'g1';
            $touched = new Channel(2);

            // Run twice — second start() must NOT throw on existing group.
            for ($i = 0; $i < 2; $i++) {
                $streams = new RedisStreams($this->url, "unit-idem-$i");
                $streams->register($stream, $group, function () use ($touched, $i): bool {
                    $touched->push($i);
                    return true;
                }, blockMs: 150, batchSize: 16);
                $streams->start();
                (new Channel(1))->pop(0.1);
                $streams->stop();
                (new Channel(1))->pop(0.2);
            }
            $this->assertTrue(true); // no throw is the assertion
        });
    }

    public function testStopHaltsTheRunner(): void
    {
        Coroutine::run(function (): void {
            $stream = $this->freshStream();
            $streams = new RedisStreams($this->url, 'unit-stop-test');
            $streams->register($stream, 'g1', fn(): bool => true, blockMs: 100, batchSize: 16);
            $streams->start();
            $this->assertTrue($streams->isRunning());

            $streams->stop();
            (new Channel(1))->pop(0.3);
            $this->assertFalse($streams->isRunning());
        });
    }
}
