<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Patch-coverage for the TieredBackend method-delegation surface — the
 * L2-passthrough paths and the L1+L2 read-path orchestration that
 * TieredBackendTest doesn't already cover (`names`, `mset`, `clear`,
 * `iteratePaged`, `existsCached`, set-time L1 population, etc).
 */
final class TieredBackendDelegationTest extends RedisTestCase
{
    private TieredBackend $b;
    private string $tbl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tbl = 'tt-' . bin2hex(random_bytes(3));
        $l1 = new TableBackend();
        $l2 = new RedisBackend(new RedisConnectionPool($this->url, 4), 'tttest-' . bin2hex(random_bytes(2)));
        $this->b = new TieredBackend($l1, $l2, l1Ttl: 5);
        $this->b->make($this->tbl, 100, ['v' => [Table::TYPE_INT, 8]]);
    }

    protected function tearDown(): void
    {
        try { $this->b->clear($this->tbl); } catch (\Throwable $e) {}
        parent::tearDown();
    }

    public function testNamesIncludesMadeTable(): void
    {
        $this->assertContains($this->tbl, $this->b->names());
    }

    public function testMsetWritesThroughToL2AndL1(): void
    {
        $this->assertTrue($this->b->mset($this->tbl, [
            'a' => ['v' => 1], 'b' => ['v' => 2], 'c' => ['v' => 3],
        ]));
        $this->assertSame(1, $this->b->get($this->tbl, 'a', 'v'));
        $this->assertSame(2, $this->b->get($this->tbl, 'b', 'v'));
        $this->assertSame(3, $this->b->get($this->tbl, 'c', 'v'));
    }

    public function testClearWipesBothTiers(): void
    {
        $this->b->set($this->tbl, 'a', ['v' => 1]);
        $this->b->set($this->tbl, 'b', ['v' => 2]);
        $this->b->clear($this->tbl);
        $this->assertSame(0, $this->b->count($this->tbl));
        $this->assertNull($this->b->get($this->tbl, 'a'));
    }

    public function testIteratePagedDelegatesToL2(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->b->set($this->tbl, "k$i", ['v' => $i]);
        }
        $page = $this->b->iteratePaged($this->tbl, '0', 100);
        $this->assertCount(5, $page['rows']);
    }

    public function testExistsCachedSetFastPath(): void
    {
        $this->b->set($this->tbl, 'fast', ['v' => 7]);
        // Fresh L1 entry — existsCached should hit L1 without touching L2.
        $this->assertTrue($this->b->existsCached($this->tbl, 'fast'));
        // Missing key — falls through to L2.
        $this->assertFalse($this->b->existsCached($this->tbl, 'nothing'));
    }

    public function testDelEvictsBothTiers(): void
    {
        $this->b->set($this->tbl, 'k', ['v' => 9]);
        $this->assertTrue($this->b->del($this->tbl, 'k'));
        $this->assertNull($this->b->get($this->tbl, 'k'));
    }

    public function testDecrEvictsL1ForNextRead(): void
    {
        $this->b->set($this->tbl, 'k', ['v' => 10]);
        $this->assertSame(9, $this->b->decr($this->tbl, 'k', 'v'));
        // Subsequent get reads from L2 (L1 was evicted on decr).
        $this->assertSame(9, $this->b->get($this->tbl, 'k', 'v'));
    }

    public function testIsInvalidationAuthenticatedDefaultsFalse(): void
    {
        $this->assertFalse($this->b->isInvalidationAuthenticated());
    }

    public function testIsInvalidationAuthenticatedWithSecret(): void
    {
        $l1 = new TableBackend();
        $l2 = new RedisBackend(new RedisConnectionPool($this->url, 4), 'tttest-auth');
        $b  = new TieredBackend($l1, $l2, l1Ttl: 5, invalidationSecret: 'hmac-secret');
        $this->assertTrue($b->isInvalidationAuthenticated());
    }

    public function testL1AndL2Accessors(): void
    {
        $this->assertInstanceOf(TableBackend::class, $this->b->l1());
        $this->assertInstanceOf(RedisBackend::class, $this->b->l2());
        $this->assertSame(5, $this->b->l1Ttl());
        $this->assertNotSame('', $this->b->originId());
    }

    public function testMgetMixedL1L2HitsAndMisses(): void
    {
        $this->b->set($this->tbl, 'warm', ['v' => 42]);
        // Force an L2-only key (write to L2 directly via accessor).
        $this->b->l2()->set($this->tbl, 'cold', ['v' => 100]);
        $r = $this->b->mget($this->tbl, ['warm', 'cold', 'missing']);
        $this->assertSame(42,  $r['warm']['v']);
        $this->assertSame(100, $r['cold']['v']);
        $this->assertNull($r['missing']);
    }

    public function testExistsAlwaysHitsL2(): void
    {
        $this->b->set($this->tbl, 'persistent', ['v' => 1]);
        $this->assertTrue($this->b->exists($this->tbl, 'persistent'));
        // L2-only key
        $this->b->l2()->set($this->tbl, 'l2only', ['v' => 2]);
        $this->assertTrue($this->b->exists($this->tbl, 'l2only'));
    }
}
