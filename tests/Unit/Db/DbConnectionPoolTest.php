<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Db;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Db\DbConnectionPool;
use ZealPHP\Db\DbException;
use ZealPHP\Db\PdoDriver;

/**
 * DbConnectionPool — the coroutine-aware PDO pool.
 *
 * Tests run OUTSIDE a coroutine (co::getCid() === -1), exercising the
 * sync-mode path: a single lazily-built connection reused sequentially.
 * That path covers acquire/release/with/transaction/discard semantics
 * without standing up the OpenSwoole scheduler. Each pool uses an in-memory
 * SQLite DB; the single sync connection keeps schema across calls (until a
 * discard rebuilds it).
 */
final class DbConnectionPoolTest extends TestCase
{
    private function memoryPool(int $size = 4, ?string $validationQuery = null): DbConnectionPool
    {
        return DbConnectionPool::pdo('sqlite::memory:', null, null, [], $size, $validationQuery);
    }

    public function testSizeFloorsAtOne(): void
    {
        self::assertSame(1, (new DbConnectionPool(new PdoDriver(fn () => new \PDO('sqlite::memory:')), 0))->size());
        self::assertSame(8, DbConnectionPool::pdo('sqlite::memory:')->size());
    }

    public function testWithReturnsHandlerResult(): void
    {
        $pool = $this->memoryPool();
        $val  = $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT 7')->fetchColumn());
        self::assertSame(7, $val);
    }

    public function testAcquireReturnsPdoInSyncMode(): void
    {
        $pool = $this->memoryPool();
        $c    = $pool->acquire();
        self::assertInstanceOf(\PDO::class, $c);
        // Sync mode reuses ONE connection.
        self::assertSame($c, $pool->acquire());
        self::assertSame(2, $pool->stats()->get('pool_acquires_total'));
        self::assertSame(1, $pool->stats()->get('pool_clients_created_total'));
    }

    public function testPdoDefaultsApplyErrmodeException(): void
    {
        $pool = $this->memoryPool();
        $this->expectException(\PDOException::class);
        $pool->with(fn (\PDO $db) => $db->query('SELECT * FROM no_such_table'));
    }

    public function testWithDiscardsConnectionOnThrowAndPoolStaysUsable(): void
    {
        $pool = $this->memoryPool();
        try {
            $pool->with(function (\PDO $db): void {
                throw new \RuntimeException('boom');
            });
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertGreaterThanOrEqual(1, $pool->stats()->get('pool_clients_discarded_total'));
        // The pool rebuilt the sync connection — still usable.
        self::assertSame(5, $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT 5')->fetchColumn()));
    }

    public function testReleaseRollsBackLeakedTransaction(): void
    {
        $pool = $this->memoryPool();
        // Set up a table (autocommit) on the shared sync connection.
        $pool->with(fn (\PDO $db) => $db->exec('CREATE TABLE t (id INTEGER)'));
        // Leak a transaction: insert inside a manual BEGIN, return without commit.
        $pool->with(function (\PDO $db): void {
            $db->beginTransaction();
            $db->exec('INSERT INTO t VALUES (1)');
            // no commit — release() must roll this back
        });
        $count = $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertSame(0, $count, 'leaked transaction must be rolled back on release');
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $pool = $this->memoryPool();
        $pool->with(fn (\PDO $db) => $db->exec('CREATE TABLE t (id INTEGER)'));
        $pool->transaction(function (\PDO $db): void {
            $db->exec('INSERT INTO t VALUES (1)');
            $db->exec('INSERT INTO t VALUES (2)');
        });
        $count = $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn());
        self::assertSame(2, $count);
    }

    public function testTransactionRollsBackOnThrow(): void
    {
        $pool = $this->memoryPool();
        $pool->with(fn (\PDO $db) => $db->exec('CREATE TABLE t (id INTEGER)'));
        try {
            $pool->transaction(function (\PDO $db): void {
                $db->exec('INSERT INTO t VALUES (1)');
                throw new \RuntimeException('rollback me');
            });
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback me', $e->getMessage());
        }
        // The throw discards the connection (rolled back), so the table the
        // FIRST with() created on the now-discarded sync connection is gone —
        // re-create + assert the rolled-back row never landed.
        $count = $pool->with(function (\PDO $db): int {
            $db->exec('CREATE TABLE IF NOT EXISTS t (id INTEGER)');
            return (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn();
        });
        self::assertSame(0, $count);
    }

    public function testValidationQueryDoesNotBreakHealthyAcquire(): void
    {
        $pool = $this->memoryPool(4, 'SELECT 1');
        self::assertSame(9, $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT 9')->fetchColumn()));
    }

    public function testFactoryConstructorIsUsed(): void
    {
        $built = 0;
        $pool  = new DbConnectionPool(new PdoDriver(function () use (&$built): \PDO {
            $built++;
            return new \PDO('sqlite::memory:');
        }), 2);
        $pool->acquire();
        self::assertSame(1, $built, 'factory invoked exactly once for the sync client');
    }

    public function testAcquireTimeoutMessageShapeIsReachableViaException(): void
    {
        // We can't trigger a real channel timeout in sync mode, but DbException
        // is the declared failure type; assert it's a RuntimeException subtype
        // so callers can catch it uniformly.
        self::assertTrue(is_subclass_of(DbException::class, \RuntimeException::class));
    }

    public function testAcquireAfterCloseThrows(): void
    {
        $pool = $this->memoryPool();
        $pool->acquire();
        $pool->close();
        $this->expectException(DbException::class);
        $pool->acquire();
    }

    // ── Coroutine-context (channel path) ──────────────────────────────────
    // These exercise the bounded Channel path (Coroutine::getCid() >= 0) that
    // the sync tests above can't reach — the part with the real concurrency
    // invariants.

    public function testChannelPathServesConcurrentBorrowsWithinCapacity(): void
    {
        $results = [];
        $created = 0;
        Coroutine::run(function () use (&$results, &$created): void {
            // Pool of 2 against SELECT-only work (each sqlite::memory: is its
            // own DB, so no shared schema needed). 6 concurrent borrowers must
            // all complete, serialising through just 2 connections.
            $pool = DbConnectionPool::pdo('sqlite::memory:', null, null, [], 2);
            $done = new \OpenSwoole\Coroutine\Channel(6);
            for ($i = 0; $i < 6; $i++) {
                go(function () use ($pool, $done, $i): void {
                    $v = $pool->with(function (\PDO $db) use ($i): int {
                        Coroutine::usleep(20000); // hold the connection (20ms) so the pool must rotate
                        return (int) $db->query('SELECT ' . ($i + 1))->fetchColumn();
                    });
                    $done->push($v);
                });
            }
            for ($i = 0; $i < 6; $i++) { $results[] = $done->pop(5.0); }
            $created = $pool->stats()->get('pool_clients_created_total');
            $pool->close();
        });
        sort($results);
        self::assertSame([1, 2, 3, 4, 5, 6], $results, 'every concurrent borrow completed');
        self::assertSame(2, $created, 'pool built exactly size=2 connections, never one-per-borrow');
    }

    public function testChannelPathPoisonPillDiscardPreservesCapacity(): void
    {
        $createdBefore = 0;
        $createdAfter  = 0;
        $discarded     = 0;
        $okAfter       = null;
        Coroutine::run(function () use (&$createdBefore, &$createdAfter, &$discarded, &$okAfter): void {
            $pool = DbConnectionPool::pdo('sqlite::memory:', null, null, [], 2);
            // Prime the channel (2 built).
            $pool->with(fn (\PDO $db) => $db->query('SELECT 1'));
            $createdBefore = $pool->stats()->get('pool_clients_created_total');
            // Poison one connection via a throwing body → discard + refill.
            try {
                $pool->with(function (\PDO $db): void { throw new \RuntimeException('boom'); });
            } catch (\RuntimeException) {
            }
            $discarded    = $pool->stats()->get('pool_clients_discarded_total');
            $createdAfter = $pool->stats()->get('pool_clients_created_total');
            // Pool must still have full capacity — 2 more concurrent borrows work.
            $done = new \OpenSwoole\Coroutine\Channel(2);
            for ($i = 0; $i < 2; $i++) {
                go(function () use ($pool, $done): void {
                    $done->push($pool->with(fn (\PDO $db): int => (int) $db->query('SELECT 9')->fetchColumn()));
                });
            }
            $okAfter = [$done->pop(5.0), $done->pop(5.0)];
            $pool->close();
        });
        self::assertSame(2, $createdBefore, 'channel pre-filled to size');
        self::assertSame(1, $discarded, 'the throwing body discarded exactly one connection');
        self::assertSame(3, $createdAfter, 'discard refilled with a fresh connection (2 + 1)');
        self::assertSame([9, 9], $okAfter, 'pool retained full capacity after the discard');
    }

    public function testChannelPathTransactionCommitAndRollback(): void
    {
        // File-backed SQLite so all pool connections share one DB.
        $file = tempnam(sys_get_temp_dir(), 'zealdbpool_') ?: (sys_get_temp_dir() . '/zealdbpool_' . getmypid());
        $committed = null;
        $rolled    = null;
        Coroutine::run(function () use ($file, &$committed, &$rolled): void {
            $pool = DbConnectionPool::pdo('sqlite:' . $file, null, null, [], 3);
            $pool->with(fn (\PDO $db) => $db->exec('CREATE TABLE t (id INTEGER)'));
            $pool->transaction(function (\PDO $db): void {
                $db->exec('INSERT INTO t VALUES (10)');
                $db->exec('INSERT INTO t VALUES (20)');
            });
            try {
                $pool->transaction(function (\PDO $db): void {
                    $db->exec('INSERT INTO t VALUES (30)');
                    throw new \RuntimeException('rollback');
                });
            } catch (\RuntimeException) {
            }
            $committed = $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT COUNT(*) FROM t')->fetchColumn());
            $rolled    = $pool->with(fn (\PDO $db): int => (int) $db->query("SELECT COUNT(*) FROM t WHERE id = 30")->fetchColumn());
            $pool->close();
        });
        @unlink($file);
        self::assertSame(2, $committed, 'committed rows persisted; rolled-back insert did not');
        self::assertSame(0, $rolled);
    }

    public function testChannelAcquireTimesOutWhenExhausted(): void
    {
        $timedOut = false;
        Coroutine::run(function () use (&$timedOut): void {
            $pool = DbConnectionPool::pdo('sqlite::memory:', null, null, [], 1);
            $held = $pool->acquire(); // take the only connection, never release
            $ch   = new \OpenSwoole\Coroutine\Channel(1);
            go(function () use ($pool, $ch): void {
                try {
                    $pool->acquire(0.1);
                    $ch->push('ok');
                } catch (DbException) {
                    $ch->push('timeout');
                }
            });
            $timedOut = ($ch->pop(5.0) === 'timeout');
            $pool->release($held);
            $pool->close();
        });
        self::assertTrue($timedOut, 'acquire on an exhausted pool throws DbException after the timeout');
    }

    public function testColdConcurrentAcquireBuildsExactlyOneChannel(): void
    {
        // #322 — ensureChannel() TOCTOU. A real connect() yields (network I/O),
        // so every cold concurrent acquirer used to pass the `$this->ch === null`
        // check, build its OWN full channel of `size` connections, and overwrite
        // `$this->ch` — leaking every channel but the last (and its connections).
        // The yielding factory below reproduces the cold-burst shape; the pool
        // must build exactly `size` connections, no matter how many concurrent
        // borrowers hit it cold.
        $created = 0;
        $results = [];
        Coroutine::run(function () use (&$created, &$results): void {
            $driver = new PdoDriver(function () use (&$created): \PDO {
                Coroutine::usleep(5000); // simulated connect latency — a yield point
                $created++;
                return new \PDO('sqlite::memory:');
            });
            $pool = new DbConnectionPool($driver, 2);
            $done = new \OpenSwoole\Coroutine\Channel(10);
            for ($i = 0; $i < 10; $i++) {
                go(function () use ($pool, $done): void {
                    $done->push($pool->with(
                        fn (\PDO $db): int => (int) $db->query('SELECT 1')->fetchColumn()
                    ));
                });
            }
            for ($i = 0; $i < 10; $i++) { $results[] = $done->pop(5.0); }
            $pool->close();
        });
        self::assertSame(array_fill(0, 10, 1), $results, 'every cold concurrent borrow completed');
        self::assertSame(2, $created, 'cold burst built exactly size=2 connections — not one channel per acquirer (#322)');
    }

    public function testPartialFillFailureDoesNotLeakOrBrickThePool(): void
    {
        // #322 companion — a connect that throws mid-fill (DB down at cold
        // boot) must drain + disconnect the partial build and leave the pool
        // unbuilt, so the NEXT acquire retries cleanly instead of timing out
        // forever against a half-filled (or empty) channel.
        $attempts = 0;
        $value    = null;
        Coroutine::run(function () use (&$attempts, &$value): void {
            $driver = new PdoDriver(function () use (&$attempts): \PDO {
                Coroutine::usleep(1000);
                $attempts++;
                if ($attempts === 2) { throw new \RuntimeException('connect refused'); }
                return new \PDO('sqlite::memory:');
            });
            $pool = new DbConnectionPool($driver, 2);
            try {
                $pool->acquire(1.0);
                self::fail('first cold acquire must surface the connect failure');
            } catch (\RuntimeException $e) {
                self::assertSame('connect refused', $e->getMessage());
            }
            // The failed build must NOT brick the pool — a later acquire
            // rebuilds from scratch and succeeds (attempts 3 + 4 connect).
            $value = $pool->with(fn (\PDO $db): int => (int) $db->query('SELECT 3')->fetchColumn());
            $pool->close();
        });
        self::assertSame(3, $value, 'pool recovered after a failed cold build');
        self::assertSame(4, $attempts, 'retry rebuilt the full channel (2 failed-batch attempts + 2 fresh)');
    }

    public function testValidationReplacementFiresWhenProbeFails(): void
    {
        // A probe that always errors on a healthy connection forces the pool to
        // replace the connection on acquire — exercises the validation-failure
        // path + its dedicated counter. First sync acquire builds without
        // probing (nothing to probe yet); the second probes → fails → replaces.
        $pool = $this->memoryPool(2, 'SELECT 1 FROM __no_such_table__');
        $pool->acquire();
        $pool->acquire();
        self::assertGreaterThanOrEqual(
            1,
            $pool->stats()->get('pool_validation_replacements_total'),
            'a failing liveness probe counts as a validation replacement',
        );
        // It is NOT counted as a poison-pill discard.
        self::assertSame(0, $pool->stats()->get('pool_clients_discarded_total'));
    }
}
