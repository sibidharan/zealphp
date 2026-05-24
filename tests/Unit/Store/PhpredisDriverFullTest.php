<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use ZealPHP\Store\PhpredisDriver;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Full-surface coverage for PhpredisDriver. The phpredis extension is the
 * production-preferred driver when ext-redis is loaded; this test pins
 * every public method except the SUBSCRIBE path (which can't be exercised
 * under PHPUnit + phpredis — see RedisTestCase::requireYieldingSubscribe
 * for the documented limitation).
 */
final class PhpredisDriverFullTest extends RedisTestCase
{
    private PhpredisDriver $d;
    private string $kp;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis (phpredis) not loaded');
        }
        $this->kp = 'phdrv:' . bin2hex(random_bytes(3)) . ':';
        $this->d = new PhpredisDriver($this->url);
    }

    protected function tearDown(): void
    {
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
        $this->assertSame('phpredis', $this->d->name());
    }

    // ── string keys ─────────────────────────────────────────────────────

    public function testSetGetDelRoundTrip(): void
    {
        $this->assertTrue($this->d->set($this->kp . 'a', 'hello'));
        $this->assertSame('hello', $this->d->get($this->kp . 'a'));
        $this->assertSame(1, $this->d->del($this->kp . 'a'));
        $this->assertNull($this->d->get($this->kp . 'a'));
    }

    public function testSetWithTtl(): void
    {
        $this->assertTrue($this->d->set($this->kp . 'ttl', 'v', 60));
        $this->assertTrue($this->d->exists($this->kp . 'ttl'));
    }

    public function testExpire(): void
    {
        $this->d->set($this->kp . 'x', 'v');
        $this->assertTrue($this->d->expire($this->kp . 'x', 120));
    }

    public function testDelMultiple(): void
    {
        $this->d->set($this->kp . 'a', '1');
        $this->d->set($this->kp . 'b', '2');
        $this->assertSame(2, $this->d->del($this->kp . 'a', $this->kp . 'b'));
    }

    public function testExistsFalseForMissing(): void
    {
        $this->assertFalse($this->d->exists($this->kp . 'never-set'));
    }

    // ── hashes ──────────────────────────────────────────────────────────

    public function testHsetHgetallHmget(): void
    {
        $key = $this->kp . 'h1';
        $this->d->hset($key, ['name' => 'alice', 'age' => '30']);
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

    public function testSscanIterates(): void
    {
        $key = $this->kp . 's2';
        $expected = ['m1','m2','m3','m4','m5'];
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
    }

    // ── counters ────────────────────────────────────────────────────────

    public function testIncrbyDecrby(): void
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
        $d = new PhpredisDriver($this->url);
        $d->close();
        $d->close();
        $this->assertTrue(true);
    }

    // ── bulk primitives ────────────────────────────────────────────────

    public function testMhgetall(): void
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

    public function testUnlink(): void
    {
        $this->d->set($this->kp . 'u1', 'a');
        $this->d->set($this->kp . 'u2', 'b');
        $this->assertSame(2, $this->d->unlink($this->kp . 'u1', $this->kp . 'u2'));
        $this->assertFalse($this->d->exists($this->kp . 'u1'));
    }

    // ── pub/sub publish ────────────────────────────────────────────────

    public function testPublishReturnsReceiverCount(): void
    {
        $this->assertSame(0, $this->d->publish('phdrv-test-channel-' . random_int(1000, 9999), 'hi'));
    }

    // ── streams ────────────────────────────────────────────────────────

    public function testXaddReturnsId(): void
    {
        $stream = $this->kp . 'stream-1';
        $id = $this->d->xadd($stream, ['payload' => 'hello']);
        $this->assertMatchesRegularExpression('/^\d+-\d+$/', $id);
    }

    public function testXgroupCreateIsIdempotent(): void
    {
        $stream = $this->kp . 'stream-2';
        $this->d->xadd($stream, ['p' => '1']);
        $first  = $this->d->xgroupCreate($stream, 'g1', '0');
        $second = $this->d->xgroupCreate($stream, 'g1', '0');
        $this->assertTrue($first);
        $this->assertFalse($second);
    }
}
