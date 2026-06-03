<?php

declare(strict_types=1);

namespace ZealPHP\Db;

/**
 * {@see PoolDriver} for `\PDO` connections — works with any PDO driver
 * (MySQL, PostgreSQL, SQLite, SQL Server, Oracle, …).
 *
 * NOTE: the pool *bounding* benefit applies to every PDO driver, but the
 * coroutine *non-blocking* benefit does not: under `HOOK_ALL` only
 * mysqlnd-based `PDO_MYSQL` rides `php_stream` and yields. `libpq`-based
 * `PDO_PGSQL`, Oracle/ODBC, etc. do their own C-side socket I/O and block
 * the worker per query — the pool still caps their connection count, but you
 * don't get coroutine concurrency during the query.
 *
 * @implements PoolDriver<\PDO>
 */
final class PdoDriver implements PoolDriver
{
    /** @var \Closure(): \PDO */
    private \Closure $factory;

    /**
     * @param callable(): \PDO $factory Builds a fresh PDO connection.
     */
    public function __construct(callable $factory)
    {
        $this->factory = \Closure::fromCallable($factory);
    }

    /**
     * Build a driver from a DSN. `ERRMODE_EXCEPTION` + `FETCH_ASSOC` are
     * applied unless overridden in `$options`.
     *
     * @param array<int, mixed> $options
     */
    public static function dsn(string $dsn, ?string $username = null, ?string $password = null, array $options = []): self
    {
        $options += [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];
        return new self(static fn (): \PDO => new \PDO($dsn, $username, $password, $options));
    }

    public function connect(): object
    {
        return ($this->factory)();
    }

    public function rollbackIfNeeded(object $conn): void
    {
        if ($conn->inTransaction()) {
            $conn->rollBack();
            error_log('DbConnectionPool: rolled back a transaction left open by a released PDO connection');
        }
    }

    public function begin(object $conn): void
    {
        $conn->beginTransaction();
    }

    public function commit(object $conn): void
    {
        $conn->commit();
    }

    public function rollback(object $conn): void
    {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
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
        // PDO closes its connection when the last reference is released; the
        // pool drops the reference after calling this, so there's nothing to
        // do explicitly.
    }
}
