<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Tests\Helpers\RedisTestCase;
use ZealPHP\Store\RedisClient;
use ZealPHP\Store\StoreException;

/**
 * Drives the RedisClient adapter against a real valkey instance.
 * Skips automatically when no Redis is reachable at ZEALPHP_REDIS_URL.
 *
 * Note: in this environment phpredis ext is NOT loaded; the auto-detect
 * picks predis. The PhpredisDriver path is covered separately when the
 * ext IS present (CI matrix in Task 14).
 */
final class RedisClientTest extends RedisTestCase
{
    public function testAutoPicksPredisWhenPhpredisAbsent(): void
    {
        $c = new RedisClient($this->url);
        if (extension_loaded('redis')) {
            $this->assertSame('phpredis', $c->driverName());
        } else {
            $this->assertSame('predis', $c->driverName());
        }
    }

    public function testForcedDriverFailsLoudly(): void
    {
        $this->expectException(StoreException::class);
        new RedisClient($this->url, ['prefer' => 'nonsense']);
    }

    public function testGetSetDelRoundTrip(): void
    {
        $c = new RedisClient($this->url);
        $this->assertTrue($c->set('k1', 'hello'));
        $this->assertSame('hello', $c->get('k1'));
        $this->assertSame(1, $c->del('k1'));
        $this->assertNull($c->get('k1'));
    }

    public function testSetWithTtlExpires(): void
    {
        $c = new RedisClient($this->url);
        $this->assertTrue($c->set('temp', 'v', 1));
        $this->assertSame('v', $c->get('temp'));
        sleep(2);
        $this->assertNull($c->get('temp'));
    }

    public function testExistsAndExpire(): void
    {
        $c = new RedisClient($this->url);
        $c->set('e1', 'v');
        $this->assertTrue($c->exists('e1'));
        $this->assertTrue($c->expire('e1', 60));
        $this->assertFalse($c->exists('missing'));
    }

    public function testHsetHgetallHmgetHincrby(): void
    {
        $c = new RedisClient($this->url);
        $c->hset('h1', ['a' => '1', 'b' => 'hi']);
        $this->assertSame(['a' => '1', 'b' => 'hi'], $c->hgetall('h1'));
        $this->assertSame(['1', 'hi', null], $c->hmget('h1', ['a', 'b', 'missing']));
        $this->assertSame(5, $c->hincrby('h1', 'counter', 5));
        $this->assertSame(7, $c->hincrby('h1', 'counter', 2));
        $this->assertSame(3.5, $c->hincrbyfloat('h1', 'fp', 3.5));
    }

    public function testHdel(): void
    {
        $c = new RedisClient($this->url);
        $c->hset('h2', ['a' => '1', 'b' => '2', 'c' => '3']);
        $this->assertSame(2, $c->hdel('h2', 'a', 'b'));
        $this->assertSame(['c' => '3'], $c->hgetall('h2'));
    }

    public function testSetMembershipAndSscan(): void
    {
        $c = new RedisClient($this->url);
        $c->sadd('s1', ['a', 'b', 'c']);
        $this->assertSame(3, $c->scard('s1'));
        $c->srem('s1', ['b']);
        $this->assertSame(2, $c->scard('s1'));
        $members = iterator_to_array($c->sscan('s1'), false);
        sort($members);
        $this->assertSame(['a', 'c'], $members);
    }

    public function testIncrbyDecrby(): void
    {
        $c = new RedisClient($this->url);
        $this->assertSame(5, $c->incrby('n', 5));
        $this->assertSame(3, $c->decrby('n', 2));
    }

    public function testEvalAtomicCas(): void
    {
        $c = new RedisClient($this->url);
        $c->set('cas', '5');
        $script = "if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('SET', KEYS[1], ARGV[2]); return 1; else return 0; end";
        $this->assertSame(1, (int) $c->evalScript($script, ['cas'], ['5', '10']));
        $this->assertSame('10', $c->get('cas'));
        $this->assertSame(0, (int) $c->evalScript($script, ['cas'], ['5', '20']));
        $this->assertSame('10', $c->get('cas'));
    }

    public function testScanKeysPattern(): void
    {
        $c = new RedisClient($this->url);
        $c->set('zsk:a', '1');
        $c->set('zsk:b', '2');
        $c->set('other', '3');
        $found = iterator_to_array($c->scanKeys('zsk:*'), false);
        sort($found);
        $this->assertSame(['zsk:a', 'zsk:b'], $found);
    }

    public function testPingAndClose(): void
    {
        $c = new RedisClient($this->url);
        $this->assertTrue($c->ping());
        $c->close();
    }

    public function testPipelineExecutesInOrder(): void
    {
        $c = new RedisClient($this->url);
        $results = $c->pipeline(function ($p): void {
            $p->set('p1', 'a');
            $p->set('p2', 'b');
            $p->get('p1');
            $p->get('p2');
        });
        $this->assertCount(4, $results);
        // last two are the GETs
        $this->assertSame('a', (string) $results[2]);
        $this->assertSame('b', (string) $results[3]);
    }

    public function testPublishReturnsReceiverCountWithNoSubscribers(): void
    {
        $c = new RedisClient($this->url);
        $this->assertSame(0, $c->publish('no-listeners', 'hi'));
    }

    public function testSubscribeReceivesPublishViaChannel(): void
    {
        $this->requireYieldingSubscribe();
        \OpenSwoole\Coroutine::run(function (): void {
            $sub  = new RedisClient($this->url);
            $pub  = new RedisClient($this->url);
            $rendezvous = new \OpenSwoole\Coroutine\Channel(1);

            go(function () use ($sub, $rendezvous): void {
                $sub->subscribe(['t:exact'], [], function (string $payload, string $channel, ?string $pattern) use ($rendezvous): void {
                    $rendezvous->push(compact('payload', 'channel', 'pattern'));
                    throw new \ZealPHP\Store\PubSubStopException();
                });
            });

            // Tiny yield so the subscriber registers before we publish.
            (new \OpenSwoole\Coroutine\Channel(1))->pop(0.1);
            $pub->publish('t:exact', 'hello');
            $received = $rendezvous->pop(2.0);

            $this->assertIsArray($received);
            $this->assertSame('hello',   $received['payload']);
            $this->assertSame('t:exact', $received['channel']);
            $this->assertNull($received['pattern']);
        });
    }

    public function testSubscribePatternReceivesMatchingPublish(): void
    {
        $this->requireYieldingSubscribe();
        \OpenSwoole\Coroutine::run(function (): void {
            $sub  = new RedisClient($this->url);
            $pub  = new RedisClient($this->url);
            $rendezvous = new \OpenSwoole\Coroutine\Channel(1);

            go(function () use ($sub, $rendezvous): void {
                $sub->subscribe([], ['t:p:*'], function (string $payload, string $channel, ?string $pattern) use ($rendezvous): void {
                    $rendezvous->push(compact('payload', 'channel', 'pattern'));
                    throw new \ZealPHP\Store\PubSubStopException();
                });
            });
            (new \OpenSwoole\Coroutine\Channel(1))->pop(0.1);
            $pub->publish('t:p:room1', 'broadcast');
            $received = $rendezvous->pop(2.0);

            $this->assertIsArray($received);
            $this->assertSame('broadcast', $received['payload']);
            $this->assertSame('t:p:room1', $received['channel']);
            $this->assertSame('t:p:*',     $received['pattern']);
        });
    }

    public function testXaddXreadGroupXackRoundTrip(): void
    {
        $c = new RedisClient($this->url);
        $stream = 't:stream:' . bin2hex(random_bytes(4));
        $group  = 'g1';

        $this->assertTrue($c->xgroupCreate($stream, $group, '$', true));
        $this->assertFalse($c->xgroupCreate($stream, $group, '$', true), 'second call is BUSYGROUP → false');

        $id = $c->xadd($stream, ['kind' => 'order', 'qty' => '3']);
        $this->assertMatchesRegularExpression('/^\d+-\d+$/', $id);

        \OpenSwoole\Coroutine::run(function () use ($c, $stream, $group, $id): void {
            $r = $c->xreadGroup($group, 'c1', [$stream], 10, 1000);
            $this->assertArrayHasKey($stream, $r);
            $this->assertCount(1, $r[$stream]);
            $this->assertSame($id, $r[$stream][0]['id']);
            $this->assertSame(['kind' => 'order', 'qty' => '3'], $r[$stream][0]['payload']);
            $this->assertSame(1, $c->xack($stream, $group, $id));
        });
    }
}
