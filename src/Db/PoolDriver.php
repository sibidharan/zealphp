<?php

declare(strict_types=1);

namespace ZealPHP\Db;

/**
 * Connection-library adapter for {@see DbConnectionPool}.
 *
 * The pool itself is connection-agnostic — it manages a bounded channel of
 * opaque connection objects and delegates everything library-specific
 * (connect, transaction lifecycle, liveness, teardown) to a driver. This is
 * what lets one pool implementation serve both PDO (`pdo:`) and mysqli, and
 * makes a third driver (a coroutine-native client, say) a drop-in.
 *
 * @template TConn of object
 */
interface PoolDriver
{
    /**
     * Open a fresh connection. Throwing here propagates out of the pool's
     * fill/refill path (the caller sees the underlying connect error).
     *
     * @return TConn
     */
    public function connect(): object;

    /**
     * Make a borrowed connection transaction-clean before it returns to the
     * pool: roll back any transaction the borrower left open. Drivers that
     * cannot introspect transaction state (mysqli has no `inTransaction()`)
     * may no-op — see {@see MysqliDriver} for the documented limitation.
     *
     * @param TConn $conn
     */
    public function rollbackIfNeeded(object $conn): void;

    /** @param TConn $conn */
    public function begin(object $conn): void;

    /** @param TConn $conn */
    public function commit(object $conn): void;

    /** @param TConn $conn */
    public function rollback(object $conn): void;

    /**
     * Liveness probe — run `$query` and return false if the connection is
     * dead (server closed it while idle). Only called when the pool was
     * configured with a validation query.
     *
     * @param TConn $conn
     */
    public function isAlive(object $conn, string $query): bool;

    /**
     * Tear down a connection. PDO closes on reference release so this can be a
     * no-op there; mysqli needs an explicit `close()`.
     *
     * @param TConn $conn
     */
    public function disconnect(object $conn): void;
}
