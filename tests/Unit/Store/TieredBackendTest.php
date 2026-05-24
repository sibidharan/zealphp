<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreException;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

final class TieredBackendTest extends RedisTestCase
{
    private function backend(int $l1Ttl = 5): TieredBackend
    {
        $l1 = new TableBackend();
        $l2 = new RedisBackend(new RedisConnectionPool($this->url, 4), 'zptest-tier-' . bin2hex(random_bytes(4)));
        return new TieredBackend($l1, $l2, $l1Ttl);
    }

    public function testL1TtlMustBePositive(): void
    {
        $this->expectException(StoreException::class);
        new TieredBackend(new TableBackend(), new RedisBackend(new RedisConnectionPool($this->url, 1)), 0);
    }

    public function testSetGetRoundTripWritesThroughL2(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('users', 100, [
                'name' => [\OpenSwoole\Table::TYPE_STRING, 32],
                'age'  => [\OpenSwoole\Table::TYPE_INT,    4],
            ]);
            $b->set('users', 'alice', ['name' => 'Alice', 'age' => 30]);

            // Read direct from L2 — should be present (write-through).
            $this->assertSame(['name' => 'Alice', 'age' => 30], $b->l2()->get('users', 'alice'));

            // Read via Tiered — strips the __cached_at column.
            $this->assertSame(['name' => 'Alice', 'age' => 30], $b->get('users', 'alice'));
        });
    }

    public function testGetHitsL1WhenFresh(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend(60);
            $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_STRING, 16]]);
            $b->set('t', 'k', ['v' => 'from-tier']);

            // Mutate L2 BEHIND tiered's back — L1 should NOT see the change while fresh.
            $b->l2()->set('t', 'k', ['v' => 'rewritten-on-l2']);
            $this->assertSame(['v' => 'from-tier'], $b->get('t', 'k'),
                'L1 hit returns the cached value, not the freshly-rewritten L2 value');
        });
    }

    public function testGetReloadsFromL2WhenL1Stale(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend(1);   // l1_ttl = 1 second
            $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_STRING, 32]]);
            $b->set('t', 'k', ['v' => 'first']);

            $b->l2()->set('t', 'k', ['v' => 'second']);
            // Within l1_ttl — L1 wins.
            $this->assertSame(['v' => 'first'], $b->get('t', 'k'));

            sleep(2);   // wait past l1_ttl
            // L1 stale → re-fetch from L2.
            $this->assertSame(['v' => 'second'], $b->get('t', 'k'));
        });
    }

    public function testFieldGetThroughTiers(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('users', 100, [
                'name' => [\OpenSwoole\Table::TYPE_STRING, 32],
                'age'  => [\OpenSwoole\Table::TYPE_INT,    4],
            ]);
            $b->set('users', 'alice', ['name' => 'Alice', 'age' => 30]);
            $this->assertSame('Alice', $b->get('users', 'alice', 'name'));
            $this->assertSame(30,      $b->get('users', 'alice', 'age'));
        });
    }

    public function testIncrEvictsL1SoNextReadIsFresh(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend(60);
            $b->make('hits', 100, ['n' => [\OpenSwoole\Table::TYPE_INT, 4]]);
            $b->set('hits', 'page', ['n' => 0]);
            $b->get('hits', 'page'); // warm L1

            $new = $b->incr('hits', 'page', 'n', 5);
            $this->assertSame(5, $new);
            // After incr, L1 must NOT serve a stale 0 — Tiered evicts it.
            $this->assertSame(['n' => 5], $b->get('hits', 'page'));
        });
    }

    public function testDelClearsBothTiers(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_STRING, 8]]);
            $b->set('t', 'k', ['v' => 'x']);
            $this->assertTrue($b->del('t', 'k'));
            $this->assertFalse($b->exists('t', 'k'));
            $this->assertNull($b->l1()->get('t', 'k'));
            $this->assertNull($b->l2()->get('t', 'k'));
        });
    }

    public function testCountAndIterateGoThroughL2(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_STRING, 8]]);
            $b->set('t', 'a', ['v' => '1']);
            $b->set('t', 'b', ['v' => '2']);
            $b->set('t', 'c', ['v' => '3']);
            $this->assertSame(3, $b->count('t'));
            $keys = [];
            foreach ($b->iterate('t') as $key => $_) { $keys[] = $key; }
            sort($keys);
            $this->assertSame(['a', 'b', 'c'], $keys);
        });
    }

    public function testMgetHitsL1ForWarmKeysAndL2ForMisses(): void
    {
        Coroutine::run(function (): void {
            $b = $this->backend(60);
            $b->make('t', 100, ['v' => [\OpenSwoole\Table::TYPE_STRING, 8]]);
            $b->set('t', 'a', ['v' => 'A']);
            $b->set('t', 'b', ['v' => 'B']);
            $b->set('t', 'c', ['v' => 'C']);

            // Now drop 'b' from L1 only — Tiered's mget must repopulate from L2.
            $b->l1()->del('t', 'b');

            $rows = $b->mget('t', ['a', 'b', 'c', 'missing']);
            $this->assertSame(['v' => 'A'], $rows['a']);
            $this->assertSame(['v' => 'B'], $rows['b']);
            $this->assertSame(['v' => 'C'], $rows['c']);
            $this->assertNull($rows['missing']);
        });
    }
}
