<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\StoreException;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * RedisBackend driven against a live valkey instance. Tracked + TTL modes
 * are exercised here together — they share most of the impl and only
 * branch in count(), iterate(), and the post-set EXPIRE call.
 */
final class RedisBackendTest extends RedisTestCase
{
    private function backend(string $prefix = 'zptest'): RedisBackend
    {
        return new RedisBackend(new RedisConnectionPool($this->url, 4), $prefix);
    }

    /** @return array<string, array{0:int, 1?:int}> */
    private function userSchema(): array
    {
        return [
            'name' => [Table::TYPE_STRING, 32],
            'age'  => [Table::TYPE_INT,    4],
        ];
    }

    // ── core CRUD ─────────────────────────────────────────────────────────

    public function testSetGetTypedRow(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('users', 100, $this->userSchema());
            $this->assertTrue($b->set('users', 'alice', ['name' => 'Alice', 'age' => 30]));
            $this->assertSame(['name' => 'Alice', 'age' => 30], $b->get('users', 'alice'));
        });
    }

    public function testGetReturnsNullOnMissingKey(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $this->assertNull($b->get('t', 'absent'));
            $this->assertNull($b->get('t', 'absent', 'v'));
        });
    }

    public function testFieldRead(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('users', 100, $this->userSchema());
            $b->set('users', 'alice', ['name' => 'Alice', 'age' => 30]);
            $this->assertSame('Alice', $b->get('users', 'alice', 'name'));
            $this->assertSame(30,      $b->get('users', 'alice', 'age'));
            $this->assertNull($b->get('users', 'alice', 'unknown_col'));
        });
    }

    public function testExistsAndDel(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $b->set('t', 'k', ['v' => 'x']);
            $this->assertTrue($b->exists('t', 'k'));
            $this->assertTrue($b->del('t', 'k'));
            $this->assertFalse($b->exists('t', 'k'));
            $this->assertFalse($b->del('t', 'k'), 'del on missing key returns false');
        });
    }

    public function testIncrIntColumnReturnsNewValue(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('hits', 100, ['n' => [Table::TYPE_INT, 4]]);
            $this->assertSame(5, $b->incr('hits', 'k', 'n', 5));
            $this->assertSame(7, $b->incr('hits', 'k', 'n', 2));
            $this->assertSame(6, $b->decr('hits', 'k', 'n', 1));
        });
    }

    public function testIncrFloatColumnUsesHincrbyfloat(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('balances', 100, ['amt' => [Table::TYPE_FLOAT, 8]]);
            $r = $b->incr('balances', 'k', 'amt', 1.5);
            $this->assertEqualsWithDelta(1.5, $r, 1e-9);
            $r = $b->incr('balances', 'k', 'amt', 0.25);
            $this->assertEqualsWithDelta(1.75, $r, 1e-9);
        });
    }

    // ── tracked mode (default) ────────────────────────────────────────────

    public function testTrackedCountIsScard(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $b->set('t', 'a', ['v' => '1']);
            $b->set('t', 'b', ['v' => '2']);
            $b->set('t', 'c', ['v' => '3']);
            $this->assertSame(3, $b->count('t'));
            $b->del('t', 'b');
            $this->assertSame(2, $b->count('t'));
        });
    }

    public function testTrackedIterateYieldsEveryRow(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('users', 100, $this->userSchema());
            $b->set('users', 'a', ['name' => 'Alice', 'age' => 30]);
            $b->set('users', 'b', ['name' => 'Bob',   'age' => 25]);
            $rows = [];
            foreach ($b->iterate('users') as $k => $row) { $rows[$k] = $row; }
            ksort($rows);
            $this->assertSame([
                'a' => ['name' => 'Alice', 'age' => 30],
                'b' => ['name' => 'Bob',   'age' => 25],
            ], $rows);
        });
    }

    public function testTrackedReSetSameKeyDoesNotDoubleCount(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 8]]);
            $b->set('t', 'k', ['v' => 'a']);
            $b->set('t', 'k', ['v' => 'b']);
            $this->assertSame(1, $b->count('t'));
        });
    }

    /**
     * #254 — a tracked-mode set() with an EMPTY row makes `HSET key` (no
     * fields) a Redis no-op, so the hash is never created. The phantom SADD
     * (gated out by the fix) used to add a membership member anyway, making
     * count() (SCARD) over-report vs get()/iterate(), which both skip the
     * absent hash. After the fix count()===0 agrees with get()===null.
     */
    public function testTrackedEmptyRowDoesNotPhantomCount(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 8]]);
            // Empty row → empty wire → HSET no-op → hash never created.
            $b->set('t', 'ghost', []);
            $this->assertNull($b->get('t', 'ghost'), 'no hash was created for the empty row');
            $this->assertFalse($b->exists('t', 'ghost'));
            $this->assertSame(0, $b->count('t'), 'count() must agree with get()/exists() — no phantom member');
            // iterate() also sees nothing.
            $seen = [];
            foreach ($b->iterate('t') as $k => $row) { $seen[] = $k; }
            $this->assertSame([], $seen);
        });
    }

    /**
     * #254 — a NON-empty row in the same table still tracks correctly (the
     * fix only skips the SADD when the wire is empty, not in general).
     */
    public function testTrackedNonEmptyRowStillCountsAfterEmptyRowFix(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 8]]);
            $b->set('t', 'empty', []);              // skipped — no member
            $b->set('t', 'real', ['v' => 'x']);     // tracked — one member
            $this->assertSame(1, $b->count('t'));
            $this->assertSame(['v' => 'x'], $b->get('t', 'real'));
            $this->assertNull($b->get('t', 'empty'));
        });
    }

    public function testTrackedClearWipesEveryRowAndTheMembershipSet(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 8]]);
            $b->set('t', 'a', ['v' => '1']);
            $b->set('t', 'b', ['v' => '2']);
            $b->clear('t');
            $this->assertSame(0, $b->count('t'));
            $this->assertNull($b->get('t', 'a'));
        });
    }

    // ── ttl mode ──────────────────────────────────────────────────────────

    public function testTtlModeRefusesZeroTtl(): void
    {
        // make() does not touch the pool — no coroutine wrapper needed; exceptions
        // raised inside Coroutine::run wouldn't propagate to PHPUnit's expectException.
        $b = $this->backend();
        $this->expectException(StoreException::class);
        $b->make('cache', 100, ['v' => [Table::TYPE_STRING, 64]], ['mode' => 'ttl']);
    }

    public function testTtlExpiresKeyWithinWindow(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('cache', 100, ['v' => [Table::TYPE_STRING, 64]], ['mode' => 'ttl', 'ttl' => 1]);
            $b->set('cache', 'k1', ['v' => 'hello']);
            $this->assertSame(['v' => 'hello'], $b->get('cache', 'k1'));
            sleep(2);
            $this->assertNull($b->get('cache', 'k1'));
        });
    }

    public function testTtlModeCountUsesScan(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('cache', 100, ['v' => [Table::TYPE_STRING, 16]], ['mode' => 'ttl', 'ttl' => 60]);
            $b->set('cache', 'a', ['v' => '1']);
            $b->set('cache', 'b', ['v' => '2']);
            $b->set('cache', 'c', ['v' => '3']);
            $this->assertSame(3, $b->count('cache'));
        });
    }

    public function testTtlIterateAfterPartialExpiry(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('cache', 100, ['v' => [Table::TYPE_STRING, 16]], ['mode' => 'ttl', 'ttl' => 60]);
            $b->set('cache', 'a', ['v' => 'A']);
            $b->set('cache', 'b', ['v' => 'B']);
            $rows = [];
            foreach ($b->iterate('cache') as $k => $row) { $rows[$k] = $row['v']; }
            ksort($rows);
            $this->assertSame(['a' => 'A', 'b' => 'B'], $rows);
        });
    }

    // ── lifecycle / errors ────────────────────────────────────────────────

    public function testUnknownModeRefused(): void
    {
        $b = $this->backend();
        $this->expectException(StoreException::class);
        $b->make('t', 100, ['v' => [Table::TYPE_STRING, 8]], ['mode' => 'nonsense']);
    }

    public function testOpsOnUnregisteredTableThrow(): void
    {
        // assertMade() fires before the pool->with() call — outside-cor is fine
        // and lets PHPUnit's expectException catch the throw.
        $b = $this->backend();
        $this->expectException(StoreException::class);
        $b->set('absent', 'k', ['v' => 'x']);
    }

    public function testNamesListsRegisteredTables(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $b->make('t1', 10, ['v' => [Table::TYPE_STRING, 8]]);
            $b->make('t2', 10, ['v' => [Table::TYPE_STRING, 8]]);
            $names = $b->names();
            sort($names);
            $this->assertSame(['t1', 't2'], $names);
        });
    }

    public function testPingReachesValkey(): void
    {
        \OpenSwoole\Coroutine::run(function (): void {
            $b = $this->backend();
            $this->assertTrue($b->ping());
        });
    }
}
