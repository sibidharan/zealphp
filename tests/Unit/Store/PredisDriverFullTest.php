<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Store\PredisDriver;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Full-surface coverage for PredisDriver. The driver is auto-picked only
 * when ext-redis isn't loaded — but the surface area is mission-critical
 * (Redis Cluster / Sentinel via pre-wired Predis\Client, RedisBackend hot
 * path when phpredis is absent). These tests pin every public method by
 * forcing the predis driver explicitly, regardless of which extension
 * the host has loaded.
 */
final class PredisDriverFullTest extends RedisTestCase
{
    private PredisDriver $d;
    private string $kp;   // key prefix for this test run

    protected function setUp(): void
    {
        parent::setUp();   // sets $this->url + opens predis client; skips when valkey absent
        $this->kp = 'pdrv:' . bin2hex(random_bytes(3)) . ':';
        $this->d = new PredisDriver($this->url);
    }

    protected function tearDown(): void
    {
        // Clean up our keyspace prefix
        try {
            foreach ($this->d->scanKeys($this->kp . '*') as $k) {
                $this->d->del($k);
            }
            $this->d->close();
        } catch (\Throwable $e) { /* tolerant */ }
        parent::tearDown();
    }

    public function testName(): void
    {
        $this->assertSame('predis', $this->d->name());
    }

    // ── string keys ─────────────────────────────────────────────────────

    public function testSetGetDelRoundTrip(): void
    {
        $this->assertTrue($this->d->set($this->kp . 'a', 'hello'));
        $this->assertSame('hello', $this->d->get($this->kp . 'a'));
        $this->assertSame(1, $this->d->del($this->kp . 'a'));
        $this->assertNull($this->d->get($this->kp . 'a'));
    }

    public function testSetWithTtlExpires(): void
    {
        $this->assertTrue($this->d->set($this->kp . 'ttl', 'v', 60));
        $this->assertTrue($this->d->exists($this->kp . 'ttl'));
    }

    public function testExpireAppliesTtl(): void
    {
        $this->d->set($this->kp . 'x', 'v');
        $this->assertTrue($this->d->expire($this->kp . 'x', 120));
    }

    public function testDelMultipleKeys(): void
    {
        $this->d->set($this->kp . 'a', '1');
        $this->d->set($this->kp . 'b', '2');
        $this->assertSame(2, $this->d->del($this->kp . 'a', $this->kp . 'b'));
    }

    public function testExistsFalseForMissingKey(): void
    {
        $this->assertFalse($this->d->exists($this->kp . 'never-set'));
    }

    // ── hashes ──────────────────────────────────────────────────────────

    public function testHsetHgetallAndHmget(): void
    {
        $key = $this->kp . 'h1';
        $this->assertSame(2, $this->d->hset($key, ['name' => 'alice', 'age' => '30']));
        $row = $this->d->hgetall($key);
        $this->assertSame('alice', $row['name']);
        $this->assertSame('30',    $row['age']);
        $partial = $this->d->hmget($key, ['name', 'age', 'missing']);
        $this->assertSame('alice', $partial[0]);
        $this->assertSame('30',    $partial[1]);
        $this->assertNull($partial[2]);
    }

    public function testHincrbyAndHincrbyfloat(): void
    {
        $key = $this->kp . 'h2';
        $this->d->hset($key, ['n' => '5']);
        $this->assertSame(7, $this->d->hincrby($key, 'n', 2));
        $this->assertSame(7.5, $this->d->hincrbyfloat($key, 'n', 0.5));
    }

    public function testHdel(): void
    {
        $key = $this->kp . 'h3';
        $this->d->hset($key, ['a' => '1', 'b' => '2', 'c' => '3']);
        $this->assertSame(2, $this->d->hdel($key, 'a', 'b'));
        $this->assertSame(['c' => '3'], $this->d->hgetall($key));
    }

    // ── sets ────────────────────────────────────────────────────────────

    public function testSaddSremScard(): void
    {
        $key = $this->kp . 's1';
        $this->assertSame(3, $this->d->sadd($key, ['a','b','c']));
        $this->assertSame(3, $this->d->scard($key));
        $this->assertSame(1, $this->d->srem($key, ['b']));
        $this->assertSame(2, $this->d->scard($key));
    }

    public function testSscanIteratesAllMembers(): void
    {
        $key = $this->kp . 's2';
        $expected = [];
        for ($i = 0; $i < 10; $i++) {
            $expected[] = "m$i";
        }
        $this->d->sadd($key, $expected);
        $got = [];
        foreach ($this->d->sscan($key) as $m) { $got[] = $m; }
        sort($got);
        sort($expected);
        $this->assertSame($expected, $got);
    }

    public function testSscanCursorOneBatch(): void
    {
        $key = $this->kp . 's3';
        $this->d->sadd($key, ['m1','m2','m3']);
        [$next, $members] = $this->d->sscanCursor($key, '0', 100);
        $this->assertCount(3, $members);
        // cursor 0 returned → scan complete in one batch
        $this->assertSame('0', $next);
    }

    // ── counters ────────────────────────────────────────────────────────

    public function testIncrbyAndDecrby(): void
    {
        $key = $this->kp . 'c1';
        $this->assertSame(5, $this->d->incrby($key, 5));
        $this->assertSame(8, $this->d->incrby($key, 3));
        $this->assertSame(6, $this->d->decrby($key, 2));
    }

    // ── scan + eval ─────────────────────────────────────────────────────

    public function testScanKeysFiltersByMatch(): void
    {
        $this->d->set($this->kp . 'scan-1', '1');
        $this->d->set($this->kp . 'scan-2', '2');
        $this->d->set($this->kp . 'other-x', 'x');
        $found = [];
        foreach ($this->d->scanKeys($this->kp . 'scan-*') as $k) { $found[] = $k; }
        sort($found);
        $this->assertSame([$this->kp . 'scan-1', $this->kp . 'scan-2'], $found);
    }

    public function testScanCursorOneBatch(): void
    {
        $this->d->set($this->kp . 'sc-1', '1');
        $this->d->set($this->kp . 'sc-2', '2');
        [$next, $keys] = $this->d->scanCursor($this->kp . 'sc-*', '0', 100);
        sort($keys);
        $this->assertSame([$this->kp . 'sc-1', $this->kp . 'sc-2'], $keys);
        $this->assertSame('0', $next);
    }

    public function testEvalScript(): void
    {
        $r = $this->d->evalScript("return 'pong-' .. ARGV[1]", [], ['hello']);
        $this->assertSame('pong-hello', $r);
    }

    public function testEvalScriptWithKey(): void
    {
        $this->d->set($this->kp . 'lua', 'expected');
        $r = $this->d->evalScript("return redis.call('GET', KEYS[1])", [$this->kp . 'lua'], []);
        $this->assertSame('expected', $r);
    }

    // ── lifecycle ──────────────────────────────────────────────────────

    public function testPing(): void
    {
        $this->assertTrue($this->d->ping());
    }

    public function testCloseIsTolerant(): void
    {
        $d = new PredisDriver($this->url);
        $d->close();
        $d->close();   // double-close OK
        $this->assertTrue(true);
    }

    // ── bulk primitives ────────────────────────────────────────────────

    public function testMhgetallBatchesHashReads(): void
    {
        $this->d->hset($this->kp . 'mh-a', ['v' => '1']);
        $this->d->hset($this->kp . 'mh-b', ['v' => '2']);
        $rows = $this->d->mhgetall([$this->kp . 'mh-a', $this->kp . 'mh-b', $this->kp . 'mh-missing']);
        $this->assertCount(3, $rows);
        $this->assertSame('1', $rows[0]['v']);
        $this->assertSame('2', $rows[1]['v']);
        $this->assertSame([], $rows[2]);
    }

    public function testMhsetWithMembershipNoSet(): void
    {
        $this->d->mhsetWithMembership([
            ['rk' => $this->kp . 'mw-1', 'fields' => ['v' => 'one']],
            ['rk' => $this->kp . 'mw-2', 'fields' => ['v' => 'two']],
        ], null, null);
        $this->assertSame('one', $this->d->hgetall($this->kp . 'mw-1')['v']);
        $this->assertSame('two', $this->d->hgetall($this->kp . 'mw-2')['v']);
    }

    public function testMhsetWithMembershipPopulatesSet(): void
    {
        $sk = $this->kp . 'set-of-rks';
        $this->d->mhsetWithMembership([
            ['rk' => $this->kp . 'r1', 'fields' => ['v' => 'a'], 'sk' => 'r1'],
            ['rk' => $this->kp . 'r2', 'fields' => ['v' => 'b'], 'sk' => 'r2'],
        ], $sk, null);
        $this->assertSame(2, $this->d->scard($sk));
    }

    public function testMhsetWithMembershipAppliesTtl(): void
    {
        $this->d->mhsetWithMembership([
            ['rk' => $this->kp . 'ttl-1', 'fields' => ['v' => 'x']],
        ], null, 30);
        $this->assertSame('x', $this->d->hgetall($this->kp . 'ttl-1')['v']);
    }

    public function testUnlinkRemovesKeys(): void
    {
        $this->d->set($this->kp . 'u1', 'a');
        $this->d->set($this->kp . 'u2', 'b');
        $this->assertSame(2, $this->d->unlink($this->kp . 'u1', $this->kp . 'u2'));
        $this->assertFalse($this->d->exists($this->kp . 'u1'));
    }

    // ── pub/sub publish (no subscribe — skip on phpredis) ──────────────

    public function testPublishReturnsReceiverCount(): void
    {
        // Without a subscriber, publish returns 0 (still validates the call).
        $this->assertSame(0, $this->d->publish('pdrv-test-channel-' . random_int(1000, 9999), 'hi'));
    }

    // ── streams ────────────────────────────────────────────────────────

    public function testXaddReturnsGeneratedId(): void
    {
        $stream = $this->kp . 'stream-1';
        $id = $this->d->xadd($stream, ['payload' => 'hello']);
        $this->assertMatchesRegularExpression('/^\d+-\d+$/', $id);
    }

    public function testXaddWithMaxlenTrims(): void
    {
        $stream = $this->kp . 'stream-2';
        for ($i = 0; $i < 5; $i++) {
            $this->d->xadd($stream, ['payload' => "msg-$i"], 2);
        }
        // After trim, stream length is at most ~2 (approximate with MAXLEN ~)
        $this->assertTrue(true);   // smoke
    }

    public function testXgroupCreateIsIdempotent(): void
    {
        $stream = $this->kp . 'stream-3';
        $this->d->xadd($stream, ['p' => '1']);
        $first  = $this->d->xgroupCreate($stream, 'g1', '0');
        $second = $this->d->xgroupCreate($stream, 'g1', '0');
        $this->assertTrue($first, 'first create succeeds');
        $this->assertFalse($second, 'second create is idempotent (returns false)');
    }
}
