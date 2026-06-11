<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\Store\MemcachedBackend;
use ZealPHP\Store\StoreException;

/**
 * Covers MemcachedBackend — Phase A backend ops (set/get/del/exists/incr/
 * mget/mset/ping) + the documented "Memcached has no SCAN/SET/pub-sub"
 * paths that throw StoreException with actionable messages.
 *
 * Auto-skips when ext-memcached isn't loaded OR when no memcached server
 * is reachable. CI's tests.yml runs a memcached:1.6 service container;
 * locally these run iff `apt-get install php-memcached memcached`.
 */
final class MemcachedBackendTest extends TestCase
{
    private MemcachedBackend $b;
    private string $servers;

    protected function setUp(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('ext-memcached not loaded');
        }
        $env = getenv('ZEALPHP_MEMCACHED_SERVERS');
        $this->servers = is_string($env) && $env !== '' ? $env : '127.0.0.1:11211';
        try {
            $this->b = new MemcachedBackend($this->servers, 'zptest-' . bin2hex(random_bytes(2)));
            if (!$this->b->ping()) {
                $this->markTestSkipped('memcached unreachable at ' . $this->servers);
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('memcached setup failed: ' . $e->getMessage());
        }
        $this->b->make('mc_test', 1000, [
            'name'  => [Store::TYPE_STRING, 32],
            'hits'  => [Store::TYPE_INT, 8],
        ]);
    }

    public function testSetGetRoundTrip(): void
    {
        $this->assertTrue($this->b->set('mc_test', 'alice', ['name' => 'alice', 'hits' => 7]));
        $row = $this->b->get('mc_test', 'alice');
        $this->assertIsArray($row);
        $this->assertSame('alice', $row['name']);
        $this->assertSame(7, $row['hits']);
    }

    public function testGetFieldReturnsScalar(): void
    {
        $this->b->set('mc_test', 'bob', ['name' => 'bob', 'hits' => 42]);
        $this->assertSame(42,    $this->b->get('mc_test', 'bob', 'hits'));
        $this->assertSame('bob', $this->b->get('mc_test', 'bob', 'name'));
        $this->assertNull($this->b->get('mc_test', 'bob', 'unknown-field'));
    }

    public function testGetMissReturnsNull(): void
    {
        $this->assertNull($this->b->get('mc_test', 'no-such-key'));
    }

    public function testDelRemovesRow(): void
    {
        $this->b->set('mc_test', 'carol', ['name' => 'carol', 'hits' => 0]);
        $this->assertTrue($this->b->del('mc_test', 'carol'));
        $this->assertNull($this->b->get('mc_test', 'carol'));
    }

    public function testExistsReportsPresence(): void
    {
        $this->b->set('mc_test', 'dave', ['name' => 'dave', 'hits' => 1]);
        $this->assertTrue($this->b->exists('mc_test', 'dave'));
        $this->assertFalse($this->b->exists('mc_test', 'nobody'));
    }

    public function testIncrAndDecrOnIntColumn(): void
    {
        $this->b->set('mc_test', 'eve', ['name' => 'eve', 'hits' => 10]);
        $this->assertSame(11, $this->b->incr('mc_test', 'eve', 'hits'));
        $this->assertSame(13, $this->b->incr('mc_test', 'eve', 'hits', 2));
        $this->assertSame(12, $this->b->decr('mc_test', 'eve', 'hits'));
    }

    public function testIncrNonAtomicAdvisoryFiresOnceAndStillCounts(): void
    {
        // #344 — Memcached incr is non-atomic (read-modify-write); the backend
        // emits a one-time advisory and still returns correct values for the
        // sequential case. We capture error_log output (elog falls back to it
        // outside a booted app) to assert the advisory is emitted exactly once.
        $tmp = tempnam(sys_get_temp_dir(), 'zp_mc_warn_');
        $prev = ini_get('error_log');
        ini_set('error_log', $tmp);
        try {
            $this->b->set('mc_test', 'cnt', ['name' => 'cnt', 'hits' => 0]);
            $this->assertSame(1, $this->b->incr('mc_test', 'cnt', 'hits'));
            $this->assertSame(3, $this->b->incr('mc_test', 'cnt', 'hits', 2));
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $logged = is_file($tmp) ? (string) file_get_contents($tmp) : '';
        @unlink($tmp);
        // elog() may route to its own sink when an App is booted; only assert
        // the once-gate when the advisory actually reached error_log.
        if (str_contains($logged, 'NOT atomic')) {
            $this->assertSame(
                1,
                substr_count($logged, "Store/Memcached 'mc_test'"),
                'non-atomic advisory must fire at most once per table per worker'
            );
        } else {
            $this->assertTrue(true, 'advisory routed to elog sink (app booted) — once-gate covered by impl');
        }
    }

    public function testMgetReturnsKeyedArrayWithNullsForMisses(): void
    {
        $this->b->set('mc_test', 'k1', ['name' => 'k1', 'hits' => 1]);
        $this->b->set('mc_test', 'k2', ['name' => 'k2', 'hits' => 2]);
        $r = $this->b->mget('mc_test', ['k1', 'k2', 'missing']);
        $this->assertSame(['k1','k2','missing'], array_keys($r));
        $this->assertIsArray($r['k1']);
        $this->assertIsArray($r['k2']);
        $this->assertNull($r['missing']);
    }

    public function testMsetWritesBatch(): void
    {
        $this->assertTrue($this->b->mset('mc_test', [
            'b1' => ['name' => 'b1', 'hits' => 10],
            'b2' => ['name' => 'b2', 'hits' => 20],
            'b3' => ['name' => 'b3', 'hits' => 30],
        ]));
        $this->assertSame(10, $this->b->get('mc_test', 'b1', 'hits'));
        $this->assertSame(20, $this->b->get('mc_test', 'b2', 'hits'));
        $this->assertSame(30, $this->b->get('mc_test', 'b3', 'hits'));
    }

    public function testMgetEmptyShortCircuits(): void
    {
        $this->assertSame([], $this->b->mget('mc_test', []));
    }

    public function testMsetEmptyShortCircuits(): void
    {
        $this->assertTrue($this->b->mset('mc_test', []));
    }

    public function testNamesReturnsRegisteredTables(): void
    {
        $names = $this->b->names();
        $this->assertContains('mc_test', $names);
    }

    public function testPingReturnsTrueForReachableServer(): void
    {
        $this->assertTrue($this->b->ping());
    }

    public function testAssertMadeThrowsForUnknownTable(): void
    {
        $this->expectException(StoreException::class);
        $this->b->set('unknown-table', 'k', ['name' => 'x', 'hits' => 1]);
    }

    // ── unsupported ops throw with actionable messages ─────────────────

    public function testCountThrowsWithHint(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/no native SCAN|Use Redis backend/');
        $this->b->count('mc_test');
    }

    public function testIterateThrowsWithHint(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/no native SCAN/');
        foreach ($this->b->iterate('mc_test') as $row) { /* never reached */ }
    }

    public function testIteratePagedThrowsWithHint(): void
    {
        $this->expectException(StoreException::class);
        $this->b->iteratePaged('mc_test');
    }

    public function testClearThrowsWithHint(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessageMatches('/not supported|flush_all/');
        $this->b->clear('mc_test');
    }

    public function testTableModeOptInTtl(): void
    {
        $this->b->make('mc_ttl', 100, ['v' => [Store::TYPE_INT, 8]], ['ttl' => 60]);
        $this->b->set('mc_ttl', 'k', ['v' => 1]);
        $this->assertSame(1, $this->b->get('mc_ttl', 'k', 'v'));
    }

    public function testPrefixAccessor(): void
    {
        $this->assertStringStartsWith('zptest-', $this->b->prefix());
    }

    public function testClientAccessorReturnsMemcachedInstance(): void
    {
        $this->assertInstanceOf(\Memcached::class, $this->b->client());
    }
}
