<?php

declare(strict_types=1);

namespace ZealPHP\Db;

/**
 * {@see PoolDriver} for `\mysqli` connections — for code that uses mysqli
 * directly rather than PDO (WordPress's `$wpdb`, legacy apps, mysqlnd-native
 * code). Like `PDO_MYSQL`, mysqli rides mysqlnd → `php_stream`, so its socket
 * I/O IS coroutinized under `HOOK_ALL` (non-blocking per query) — the full
 * coroutine benefit, not just connection bounding.
 *
 * ## Transaction-cleanup limitation (important)
 *
 * mysqli has **no `inTransaction()`** — there is no portable way to ask a
 * mysqli handle "is a transaction open right now?". So `rollbackIfNeeded()`
 * (the pool's defense against a `with()` body that opened a transaction and
 * returned without committing) is a **no-op** for mysqli. Two consequences:
 *
 *  - Use `$pool->transaction($fn)` for transactional work — it owns the
 *    begin/commit/rollback and is always clean.
 *  - A raw `$mysqli->begin_transaction()` inside a plain `with()` body that you
 *    don't commit/rollback yourself can leak the open transaction to the next
 *    borrower. Don't do that — commit/rollback it, or use `transaction()`.
 *
 * The error path is still safe: a `with()`/`transaction()` body that **throws**
 * has its connection discarded (poison-pill), and `disconnect()` (`close()`)
 * rolls back any open transaction server-side as the connection drops.
 *
 * @implements PoolDriver<\mysqli>
 */
final class MysqliDriver implements PoolDriver
{
    /** @var \Closure(): \mysqli */
    private \Closure $factory;

    /**
     * @param callable(): \mysqli $factory Builds a fresh mysqli connection.
     */
    public function __construct(callable $factory)
    {
        $this->factory = \Closure::fromCallable($factory);
    }

    /**
     * Build a driver that connects with the given parameters. `$charset`
     * defaults to `utf8mb4`. For SSL / custom init commands, pass your own
     * factory to the constructor instead.
     */
    public static function params(
        string $host,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        int $port = 3306,
        ?string $socket = null,
        string $charset = 'utf8mb4',
    ): self {
        return new self(static function () use ($host, $username, $password, $database, $port, $socket, $charset): \mysqli {
            // PHP 8.1+ defaults mysqli_report to MYSQLI_REPORT_ERROR|STRICT, so
            // a failed connect throws mysqli_sql_exception rather than returning
            // a broken handle — which is what the pool wants (the throw
            // propagates out of connect()).
            $m = new \mysqli($host, $username, $password, $database ?? '', $port, $socket ?? '');
            $m->set_charset($charset);
            return $m;
        });
    }

    public function connect(): object
    {
        return ($this->factory)();
    }

    public function rollbackIfNeeded(object $conn): void
    {
        // mysqli has no inTransaction() — cannot detect a leaked transaction.
        // Documented limitation: use transaction() for transactional work; the
        // poison-pill discard + close() covers the throwing path. No-op here.
    }

    public function begin(object $conn): void
    {
        $conn->begin_transaction();
    }

    public function commit(object $conn): void
    {
        $conn->commit();
    }

    public function rollback(object $conn): void
    {
        $conn->rollback();
    }

    public function isAlive(object $conn, string $query): bool
    {
        try {
            $conn->query($query);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function disconnect(object $conn): void
    {
        try {
            $conn->close();
        } catch (\Throwable) {
            // already gone
        }
    }
}
