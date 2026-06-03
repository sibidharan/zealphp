<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Db;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Db\DbConnectionPool;
use ZealPHP\Db\MysqliDriver;

/**
 * DbConnectionPool against the mysqli driver — the same pool, a different
 * connection library. Requires a reachable MySQL/MariaDB (CI wires a mariadb
 * service; local runs without one skip gracefully). Connection params come
 * from ZEALPHP_TEST_MYSQL_* env (phpunit.xml / CI), defaulting to a local
 * 127.0.0.1:3306 root.
 */
final class MysqliPoolTest extends TestCase
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $db;

    protected function setUp(): void
    {
        $this->host = getenv('ZEALPHP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $this->port = (int) (getenv('ZEALPHP_TEST_MYSQL_PORT') ?: '3306');
        $this->user = getenv('ZEALPHP_TEST_MYSQL_USER') ?: 'root';
        $this->pass = getenv('ZEALPHP_TEST_MYSQL_PASS') ?: '';
        $this->db   = getenv('ZEALPHP_TEST_MYSQL_DB') ?: 'zealtest';
        $this->skipIfNoMysql();
    }

    private function pool(int $size = 4): DbConnectionPool
    {
        return DbConnectionPool::mysqli(
            $this->host, $this->user, $this->pass, $this->db, $this->port,
            size: $size, validationQuery: 'SELECT 1',
        );
    }

    public function testMysqliWithReturnsResult(): void
    {
        Coroutine::run(function (): void {
            $pool = $this->pool();
            $val  = $pool->with(function (\mysqli $db): int {
                $res = $db->query('SELECT 7 AS n');
                $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
                return (int) ($row['n'] ?? 0);
            });
            self::assertSame(7, $val);
            $pool->close();
        });
    }

    public function testMysqliTransactionCommitsAndRollsBack(): void
    {
        Coroutine::run(function (): void {
            $table = 't_' . bin2hex(random_bytes(4));
            $pool  = $this->pool();
            $pool->with(fn (\mysqli $db) => $db->query("CREATE TABLE {$table} (id INT) ENGINE=InnoDB"));
            try {
                $pool->transaction(function (\mysqli $db) use ($table): void {
                    $db->query("INSERT INTO {$table} VALUES (1)");
                    $db->query("INSERT INTO {$table} VALUES (2)");
                });
                // A rolled-back transaction must not persist its row.
                try {
                    $pool->transaction(function (\mysqli $db) use ($table): void {
                        $db->query("INSERT INTO {$table} VALUES (99)");
                        throw new \RuntimeException('rollback');
                    });
                } catch (\RuntimeException) {
                }
                $count = $pool->with(function (\mysqli $db) use ($table): int {
                    $res = $db->query("SELECT COUNT(*) AS c FROM {$table}");
                    $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
                    return (int) ($row['c'] ?? 0);
                });
                self::assertSame(2, $count, 'committed rows persisted; rolled-back insert did not');
            } finally {
                $pool->with(fn (\mysqli $db) => $db->query("DROP TABLE IF EXISTS {$table}"));
                $pool->close();
            }
        });
    }

    public function testMysqliPoisonPillDiscardKeepsPoolUsable(): void
    {
        Coroutine::run(function (): void {
            $pool = $this->pool(2);
            $pool->with(fn (\mysqli $db) => $db->query('SELECT 1'));
            try {
                $pool->with(function (\mysqli $db): void { throw new \RuntimeException('boom'); });
            } catch (\RuntimeException) {
            }
            self::assertGreaterThanOrEqual(1, $pool->stats()->get('pool_clients_discarded_total'));
            // Pool refilled — still serves work.
            $v = $pool->with(function (\mysqli $db): int {
                $res = $db->query('SELECT 5 AS n');
                $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
                return (int) ($row['n'] ?? 0);
            });
            self::assertSame(5, $v);
            $pool->close();
        });
    }

    // NOTE: no concurrent-borrow test here. Without HOOK_ALL (PHPUnit does not
    // enable it) mysqli's socket I/O is a blocking C call, so several coroutines
    // doing real mysqli queries don't truly interleave and error under the
    // scheduler. The pool's channel-concurrency invariant is driver-agnostic and
    // is covered by the PDO SQLite coroutine tests in DbConnectionPoolTest; the
    // mysqli tests above cover mysqli-specific behaviour (query / transaction /
    // poison-pill) sequentially.

    private function skipIfNoMysql(): void
    {
        try {
            $m = @new \mysqli($this->host, $this->user, $this->pass, $this->db, $this->port);
            $m->query('SELECT 1');
            $m->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL/MariaDB not available at ' . $this->host . ':' . $this->port . ' — ' . $e->getMessage());
        }
    }
}
