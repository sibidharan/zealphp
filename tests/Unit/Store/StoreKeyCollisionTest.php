<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Keys that collided in storage. OpenSwoole\Table truncates keys to 63 bytes
 * in the stored row, so a long key (a WS room member key, a 64-hex session
 * id) could not be found again from iterate(); and a Redis tracked-table row
 * keyed `__keys__` landed on the table's membership SET.
 */
final class StoreKeyCollisionTest extends RedisTestCase
{
    protected function setUp(): void
    {
        // The Table cases need no Redis; only the Redis ones go through the
        // parent's connect-or-skip.
        if (str_starts_with($this->name(), 'testRedis')) {
            parent::setUp();
        }
    }

    protected function tearDown(): void
    {
        if (str_starts_with($this->name(), 'testRedis')) {
            parent::tearDown();
        }
    }

    public function testTableLongKeysAreDistinctAndFoundAgainFromIterate(): void
    {
        $b = new TableBackend();
        $b->make('lk', 64, ['v' => [Table::TYPE_STRING, 16]]);
        $k1 = '5:lobby:' . str_repeat('c', 60) . ':alice';
        $k2 = '5:lobby:' . str_repeat('c', 60) . ':mallory';
        $this->assertTrue($b->set('lk', $k1, ['v' => 'alice']));
        $this->assertTrue($b->set('lk', $k2, ['v' => 'mallory']));
        $this->assertSame(['v' => 'alice'], $b->get('lk', $k1));
        $this->assertSame(['v' => 'mallory'], $b->get('lk', $k2));
        $this->assertTrue($b->set('lk', 'short', ['v' => 's']));

        $seen = [];
        foreach ($b->iterate('lk') as $key => $row) {
            $this->assertSame($row, $b->get('lk', $key), "iterate() yielded '$key', which get() cannot find");
            $seen[] = $key;
        }
        $this->assertContains('short', $seen, 'short keys are stored unchanged');
        foreach ($seen as $key) {
            $this->assertTrue($b->del('lk', $key), "iterate() yielded '$key', which del() cannot delete");
        }
        $this->assertSame(0, $b->count('lk'));
    }

    public function testTableKeyMappingIsStableAndShortKeysAreUnchanged(): void
    {
        $long = str_repeat('f', 64);
        $this->assertSame('abc', TableBackend::key('abc'));
        $this->assertSame(str_repeat('x', 63), TableBackend::key(str_repeat('x', 63)));
        $this->assertLessThanOrEqual(63, strlen(TableBackend::key($long)));
        $this->assertSame(TableBackend::key($long), TableBackend::key(TableBackend::key($long)));
        $this->assertNotSame(TableBackend::key($long), TableBackend::key(str_repeat('f', 63) . 'e'));
    }

    private function redis(): RedisBackend
    {
        return new RedisBackend(new RedisConnectionPool($this->url, 2), 'zpkc');
    }

    public function testRedisRowKeyedLikeTheMembershipSetDoesNotBreakTheTable(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->redis();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $this->assertTrue($b->set('t', 'a', ['v' => '1']));
            $this->assertTrue($b->set('t', '__keys__', ['v' => 'evil']));
            $this->assertTrue($b->set('t', 'b', ['v' => '2']));
            $this->assertTrue($b->set('t', '~x', ['v' => '3']));
            $this->assertSame(4, $b->count('t'));
            $this->assertSame(['v' => 'evil'], $b->get('t', '__keys__'));
            $rows = iterator_to_array($b->iterate('t'));
            ksort($rows);
            $this->assertSame(['__keys__', 'a', 'b', '~x'], array_keys($rows));
            $this->assertTrue($b->del('t', '__keys__'));
            $this->assertSame(3, $b->count('t'));
            $b->clear('t');
            $this->assertSame(0, $b->count('t'));
        });
    }

    public function testRedisTtlModeIterationReturnsTheCallersKeys(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->redis();
            $b->make('tt', 100, ['v' => [Table::TYPE_STRING, 16]], ['mode' => 'ttl', 'ttl' => 60]);
            $b->set('tt', '__keys__', ['v' => '1']);
            $b->set('tt', '~y', ['v' => '2']);
            $b->set('tt', 'z', ['v' => '3']);
            $keys = array_keys(iterator_to_array($b->iterate('tt')));
            sort($keys);
            $this->assertSame(['__keys__', 'z', '~y'], $keys);
            $page = $b->iteratePaged('tt', '0', 100);
            $pk = array_keys($page['rows']);
            sort($pk);
            $this->assertSame(['__keys__', 'z', '~y'], $pk);
            $this->assertSame(3, $b->count('tt'));
        });
    }
}
