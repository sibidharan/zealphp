<?php

declare(strict_types=1);

namespace ZealPHP\Db;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\Store\Stats;

/**
 * Per-worker pool of database connections — the DB counterpart to
 * {@see \ZealPHP\Store\RedisConnectionPool}.
 *
 * Connection-library-agnostic: the pool manages a bounded channel of opaque
 * connection objects and delegates everything library-specific (connect,
 * transaction lifecycle, liveness, teardown) to a {@see PoolDriver}. Ships
 * with {@see PdoDriver} (any PDO driver) and {@see MysqliDriver} (mysqli),
 * with `::pdo()` / `::mysqli()` convenience constructors.
 *
 * ## Why this exists
 *
 * Under coroutine mode with `Runtime::HOOK_ALL`, ZealPHP's documented DB
 * pattern is "one connection per coroutine" — because two coroutines sharing
 * one handle interleave wire frames and corrupt the socket. That is SAFE but
 * does NOT scale: peak live DB connections = peak concurrent requests, so a
 * few hundred concurrent queries blow past MySQL/Postgres `max_connections`.
 * This pool bounds connections to `size × workers × nodes` regardless of
 * request concurrency: each query borrows a private connection, uses it, and
 * returns it.
 *
 * **Sizing:** keep `size × workers × nodes ≤ db_max_connections − headroom`,
 * and cap the OpenSwoole server's `max_coroutine` so request concurrency can't
 * outrun the pool and pile up on `acquire()` waits.
 *
 * ## Semantics
 *
 * - **Coroutine context:** a bounded `Channel` of `size` connections; `acquire`
 *   blocks (yields) until one is free or `$timeout` elapses (→ `DbException`).
 * - **Sync context** (no scheduler, e.g. `superglobals(true)`): degrades to a
 *   single lazily-built connection used sequentially.
 * - **Transaction-safe return:** `release()` asks the driver to roll back any
 *   transaction the borrower left open (PDO; mysqli can't introspect — see
 *   {@see MysqliDriver}).
 * - **Poison-pill discard:** a `with()` body that throws discards its
 *   connection (half-finished transaction or dead socket) and refills the pool.
 * - **Optional liveness check:** `$validationQuery` (e.g. `'SELECT 1'`) pings a
 *   connection on acquire and replaces it if the server closed it while idle.
 *
 * ## Usage
 *
 * ```php
 * $pool = DbConnectionPool::pdo('mysql:host=127.0.0.1;dbname=app', 'user', 'pass',
 *     size: 16, validationQuery: 'SELECT 1');
 * // or mysqli (WordPress $wpdb-style code):
 * $pool = DbConnectionPool::mysqli('127.0.0.1', 'user', 'pass', 'app', size: 16);
 *
 * $rows = $pool->with(fn (\PDO $db) =>
 *     $db->query('SELECT * FROM users LIMIT 10')->fetchAll());
 *
 * $pool->transaction(function (\PDO $db): void {
 *     $db->exec('UPDATE accounts SET balance = balance - 10 WHERE id = 1');
 *     $db->exec('UPDATE accounts SET balance = balance + 10 WHERE id = 2');
 * });
 * ```
 *
 * @template TConn of object
 */
final class DbConnectionPool
{
    /**
     * The coroutine channel holding available connections. Created lazily on
     * the first `acquire()` inside a coroutine (`Channel::push()` throws
     * outside the scheduler).
     */
    private ?Channel $ch = null;

    /** Configured pool capacity (minimum `1`). */
    private int $size;

    /**
     * Single connection used in sync (non-coroutine) mode.
     *
     * @var TConn|null
     */
    private ?object $syncClient = null;

    /**
     * Per-worker counters: `pool_acquires_total`, `pool_acquire_timeouts_total`,
     * `pool_clients_created_total`, `pool_clients_discarded_total` (poison-pill
     * discards of a connection whose `with()` body threw), and
     * `pool_validation_replacements_total` (connections replaced because they
     * failed the on-acquire liveness probe).
     */
    private Stats $stats;

    /** @var PoolDriver<TConn> */
    private PoolDriver $driver;

    /** Optional liveness probe run on acquire (null = no check). */
    private ?string $validationQuery;

    /** Set by `close()` — a closed pool refuses `acquire()` rather than silently rebuilding a fresh (never-drained) channel. */
    private bool $closed = false;

    /**
     * Size-1 channel used as a coroutine mutex around channel construction.
     * Every `build()` in the fill loop yields on network I/O, so without this
     * gate every cold concurrent acquirer passed the `$ch === null` check and
     * built its OWN full channel — leaking all but the last (#322).
     */
    private ?Channel $buildLock = null;

    /**
     * @param PoolDriver<TConn> $driver           Connection-library adapter (PDO, mysqli, …).
     * @param int               $size             Max pool size per worker (default `8`).
     * @param ?string           $validationQuery  Liveness probe run on acquire (e.g. `'SELECT 1'`); null = skip.
     */
    public function __construct(PoolDriver $driver, int $size = 8, ?string $validationQuery = null)
    {
        $this->driver          = $driver;
        $this->size            = max(1, $size);
        $this->validationQuery = $validationQuery;
        $this->stats           = new Stats();
    }

    /**
     * Pool of `\PDO` connections from a DSN. `ERRMODE_EXCEPTION` +
     * `FETCH_ASSOC` are applied unless overridden in `$options`.
     *
     * @param  array<int, mixed> $options PDO driver options (merged over the defaults).
     * @return self<\PDO>
     */
    public static function pdo(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        int $size = 8,
        ?string $validationQuery = null,
    ): self {
        return new self(PdoDriver::dsn($dsn, $username, $password, $options), $size, $validationQuery);
    }

    /**
     * Pool of `\mysqli` connections. `$charset` defaults to `utf8mb4`. For
     * SSL / custom init, build a {@see MysqliDriver} from your own factory and
     * pass it to the constructor instead.
     *
     * @return self<\mysqli>
     */
    public static function mysqli(
        string $host,
        ?string $username = null,
        ?string $password = null,
        ?string $database = null,
        int $port = 3306,
        ?string $socket = null,
        string $charset = 'utf8mb4',
        int $size = 8,
        ?string $validationQuery = null,
    ): self {
        return new self(
            MysqliDriver::params($host, $username, $password, $database, $port, $socket, $charset),
            $size,
            $validationQuery,
        );
    }

    /** Per-worker stats — acquires, timeouts, clients created/discarded, validation replacements. */
    public function stats(): Stats { return $this->stats; }

    /** Return the configured pool capacity. */
    public function size(): int { return $this->size; }

    /**
     * Pop a connection from the pool. In coroutine context, yields up to
     * `$timeout` seconds for one to become available; throws `DbException` on
     * timeout. In sync context, returns a shared single connection.
     *
     * When `$validationQuery` is configured, a connection that fails the probe
     * (server closed it while idle) is transparently replaced with a fresh one.
     *
     * @return TConn
     */
    public function acquire(float $timeout = 5.0): object
    {
        if ($this->closed) {
            throw new DbException('DbConnectionPool: acquire() on a closed pool');
        }
        if (Coroutine::getCid() < 0) {
            if ($this->syncClient === null || !$this->aliveEnough($this->syncClient)) {
                $this->syncClient = $this->build();
            }
            $this->stats->inc('pool_acquires_total');
            return $this->syncClient;
        }
        $ch = $this->ensureChannel();
        $c  = $ch->pop($timeout);
        if (!is_object($c)) {
            $this->stats->inc('pool_acquire_timeouts_total');
            throw new DbException(
                "DbConnectionPool: acquire timed out after {$timeout}s (size={$this->size})"
            );
        }
        /** @var TConn $c */
        if (!$this->aliveEnough($c)) {
            $c = $this->build();
        }
        $this->stats->inc('pool_acquires_total');
        return $c;
    }

    /**
     * Return a connection to the pool. Rolls back any transaction the borrower
     * left open first (driver-dependent). A connection that can't even be
     * inspected is discarded + replaced. No-op for the sync-mode singleton.
     *
     * @param TConn $client
     */
    public function release(object $client): void
    {
        try {
            $this->driver->rollbackIfNeeded($client);
        } catch (\Throwable) {
            // Connection is unusable — don't return a broken handle to the pool.
            $this->discard($client);
            return;
        }
        if ($this->syncClient !== null && $client === $this->syncClient) { return; }
        if ($this->ch === null) { return; }
        $this->ch->push($client);
    }

    /**
     * Acquire + use + release in one call. On success the connection returns to
     * the pool (transaction-cleaned); if `$fn` throws, the connection is
     * discarded and the pool refilled, then the exception re-thrown.
     *
     * @template T
     * @param  callable(TConn): T $fn
     * @return T
     */
    public function with(callable $fn): mixed
    {
        $c = $this->acquire();
        try {
            $result = $fn($c);
        } catch (\Throwable $e) {
            $this->discard($c);
            throw $e;
        }
        $this->release($c);
        return $result;
    }

    /**
     * Run `$fn` inside a transaction on a pooled connection: BEGIN, then COMMIT
     * on success or ROLLBACK on throw. The connection returns to the pool
     * afterward (cleaned either way).
     *
     * @template T
     * @param  callable(TConn): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        return $this->with(function (object $c) use ($fn): mixed {
            $this->driver->begin($c);
            try {
                $result = $fn($c);
                $this->driver->commit($c);
                return $result;
            } catch (\Throwable $e) {
                $this->driver->rollback($c);
                throw $e;
            }
        });
    }

    /**
     * Tear down the pool. Drains every channel connection (driver
     * `disconnect()` + reference drop) and marks the pool closed so a later
     * `acquire()` throws instead of rebuilding a fresh channel that would never
     * be drained.
     *
     * Call only at worker shutdown / when no borrows are in flight — a
     * connection a coroutine is still holding can't be drained here; it closes
     * when that borrower's reference drops (its `release()` becomes a no-op on
     * the closed pool).
     */
    public function close(): void
    {
        $this->closed = true;
        if ($this->syncClient !== null) {
            $this->driver->disconnect($this->syncClient);
            $this->syncClient = null;
        }
        if ($this->ch === null) { return; }
        $ch     = $this->ch;
        $size   = $this->size;
        $driver = $this->driver;
        $drain  = static function () use ($ch, $size, $driver): void {
            for ($i = 0; $i < $size; $i++) {
                $c = $ch->pop(0.01);
                if (is_object($c)) {
                    /** @var TConn $c */
                    $driver->disconnect($c);
                }
            }
        };
        if (Coroutine::getCid() >= 0) {
            $drain();
        } else {
            Coroutine::run($drain);
        }
        $this->ch = null;
    }

    /**
     * Discard a connection (broken / poisoned) and preserve pool capacity by
     * refilling with a fresh one. For the sync singleton, just null it so the
     * next acquire rebuilds.
     *
     * @param TConn $client
     */
    private function discard(object $client): void
    {
        $this->stats->inc('pool_clients_discarded_total');
        $this->driver->disconnect($client);
        if ($this->syncClient !== null && $client === $this->syncClient) {
            $this->syncClient = null;
            return;
        }
        if ($this->ch === null) { return; }
        try {
            $this->ch->push($this->build());
        } catch (\Throwable $e) {
            error_log('DbConnectionPool: failed to refill the pool after discard: ' . $e->getMessage());
        }
    }

    /**
     * True if no validation is configured, or the connection answers the
     * validation probe. A connection that fails is counted as a validation
     * replacement so the caller rebuilds.
     *
     * @param TConn $client
     */
    private function aliveEnough(object $client): bool
    {
        if ($this->validationQuery === null) { return true; }
        if ($this->driver->isAlive($client, $this->validationQuery)) { return true; }
        // A validation-replacement is NOT a poison-pill discard (a body that
        // threw) — count it separately so the two events stay distinguishable.
        $this->stats->inc('pool_validation_replacements_total');
        $this->driver->disconnect($client);
        return false;
    }

    /**
     * Lazily initialise the channel and pre-fill it with `$size` connections.
     * MUST be called inside a coroutine — `Channel::push()` throws otherwise.
     *
     * Coroutine-safe (#322): exactly ONE cold acquirer builds the channel;
     * concurrent acquirers queue on `$buildLock` and re-check after waking.
     * A fill that throws mid-way drains + disconnects its partial build so a
     * transient connect failure can't leak connections or brick the pool —
     * the next acquirer simply retries the build.
     */
    private function ensureChannel(): Channel
    {
        $existing = $this->ch;
        if ($existing !== null) { return $existing; }
        // `new Channel()` + assignment has no yield point, so this lazy init
        // is race-free — unlike the connection fill below.
        if ($this->buildLock === null) { $this->buildLock = new Channel(1); }
        $this->buildLock->push(true); // lock — cold peers park here until the build settles
        try {
            return $this->buildChannelLocked();
        } finally {
            $this->buildLock->pop(0.001); // release — wake the next parked acquirer
        }
    }

    /**
     * The serialized half of {@see ensureChannel()} — runs under `$buildLock`.
     * Re-checks `$ch` first: a parked peer wakes here AFTER the winner built,
     * so it must adopt the winner's channel instead of building another.
     */
    private function buildChannelLocked(): Channel
    {
        $existing = $this->ch; // re-read — a peer may have built it while we waited at the lock
        if ($existing !== null) { return $existing; }
        if ($this->closed) {
            throw new DbException('DbConnectionPool: acquire() on a closed pool');
        }
        $ch     = new Channel($this->size);
        $filled = 0;
        try {
            for ($i = 0; $i < $this->size; $i++) {
                $ch->push($this->build()); // yields — peers are held at the lock
                $filled++;
            }
        } catch (\Throwable $e) {
            // Partial fill must not leak: disconnect what we built and
            // leave `$this->ch` null so a later acquire retries cleanly.
            for ($i = 0; $i < $filled; $i++) {
                $c = $ch->pop(0.001);
                if (is_object($c)) {
                    /** @var TConn $c */
                    $this->driver->disconnect($c);
                }
            }
            throw $e;
        }
        $this->ch = $ch;
        return $ch;
    }

    /**
     * Build a fresh connection via the driver and count it.
     *
     * @return TConn
     */
    private function build(): object
    {
        $conn = $this->driver->connect();
        $this->stats->inc('pool_clients_created_total');
        return $conn;
    }
}
