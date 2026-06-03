# Database connection pool — `ZealPHP\Db\DbConnectionPool`

A coroutine-aware, connection-library-agnostic pool of SQL connections. It is
the DB counterpart to `ZealPHP\Store\RedisConnectionPool`.

## Why

Under coroutine mode with `Runtime::HOOK_ALL`, ZealPHP's documented DB pattern
is **one connection per coroutine** — two coroutines sharing one `\PDO`/`mysqli`
handle interleave wire frames and corrupt the socket (see the DB note in the
architecture docs). That is correct, but it does **not** scale: peak live DB
connections = peak concurrent requests, so a few hundred concurrent queries blow
past MySQL/Postgres `max_connections`.

`DbConnectionPool` bounds connections to **`size × workers × nodes`** regardless
of request concurrency: each query borrows a private connection, uses it, and
returns it to the pool.

## Quick start

```php
use ZealPHP\Db\DbConnectionPool;

// Build the pool once per worker (App::onWorkerStart is the natural place).
$pool = DbConnectionPool::pdo(
    'mysql:host=127.0.0.1;dbname=app', 'user', 'pass',
    size: 16,
    validationQuery: 'SELECT 1',
);

// Borrow a connection for one unit of work:
$users = $pool->with(fn (\PDO $db) =>
    $db->query('SELECT * FROM users LIMIT 10')->fetchAll());

// Transactional work (BEGIN / COMMIT, or ROLLBACK on throw):
$pool->transaction(function (\PDO $db): void {
    $db->exec('UPDATE accounts SET balance = balance - 10 WHERE id = 1');
    $db->exec('UPDATE accounts SET balance = balance + 10 WHERE id = 2');
});
```

### mysqli (e.g. WordPress `$wpdb`-style code)

```php
$pool = DbConnectionPool::mysqli('127.0.0.1', 'user', 'pass', 'app', size: 16);

$n = $pool->with(function (\mysqli $db): int {
    $res = $db->query('SELECT COUNT(*) AS c FROM posts');
    return (int) ($res->fetch_assoc()['c'] ?? 0);
});
```

## API

| Method | Purpose |
|--------|---------|
| `DbConnectionPool::pdo($dsn, $user, $pass, $options, $size, $validationQuery)` | Pool of `\PDO` connections from a DSN (any PDO driver). `ERRMODE_EXCEPTION` + `FETCH_ASSOC` applied unless overridden. |
| `DbConnectionPool::mysqli($host, $user, $pass, $db, $port, $socket, $charset, $size, $validationQuery)` | Pool of `\mysqli` connections. |
| `new DbConnectionPool(PoolDriver $driver, $size, $validationQuery)` | Advanced — supply a custom `PoolDriver` (e.g. `new PdoDriver($factory)` for custom PDO setup). |
| `with(callable(TConn): T): T` | Borrow → run `$fn` → return. The recommended entry point. |
| `transaction(callable(TConn): T): T` | `with()` wrapped in `BEGIN`/`COMMIT` (`ROLLBACK` on throw). |
| `acquire(float $timeout = 5.0): TConn` | Low-level borrow (yields/blocks until free or timeout → `DbException`). Pair with `release()`. |
| `release(TConn $conn): void` | Return a borrowed connection (rolls back a leaked transaction first). |
| `stats(): Stats` | Per-worker counters (see below). |
| `size(): int` | Configured capacity. |
| `close(): void` | Drain + close; a closed pool refuses `acquire()`. Call at worker shutdown. |

`stats()` keys: `pool_acquires_total`, `pool_acquire_timeouts_total`,
`pool_clients_created_total`, `pool_clients_discarded_total` (poison-pill
discards), `pool_validation_replacements_total` (idle-closed connections
swapped on the liveness probe).

## Semantics

- **Coroutine context:** a bounded `OpenSwoole\Coroutine\Channel` of `size`
  connections; `acquire()` yields until one is free or `$timeout` elapses.
- **Sync context** (no scheduler, e.g. `superglobals(true)`): degrades to a
  single sequential connection.
- **Transaction-safe return:** `release()` rolls back any transaction the
  borrower left open (PDO). **mysqli has no `inTransaction()`**, so its
  `rollbackIfNeeded()` is a no-op — use `transaction()` for transactional work
  on mysqli, and don't leak a raw `begin_transaction()` from a plain `with()`.
- **Poison-pill discard:** a `with()`/`transaction()` body that throws discards
  its connection and refills the pool, so a broken handle is never reused. The
  thrown error still propagates to the caller.
- **Liveness:** with `validationQuery`, a connection the server closed while
  idle (`wait_timeout`) is replaced transparently on the next acquire.

## Sizing

Keep `size × workers × nodes ≤ db_max_connections − headroom`. Example: 4
workers × 2 nodes against `max_connections = 150` → a pool size of ~16 leaves
headroom (`16 × 4 × 2 = 128`). Also cap the OpenSwoole server's `max_coroutine`
so request concurrency can't outrun the pool and pile up on `acquire()` waits.

## Driver coverage & the coroutine nuance

The pool is **PDO-driver-agnostic** (and adds mysqli). But the **connection
bounding** benefit and the **coroutine non-blocking** benefit are different:

| Driver | Connection bounding | Non-blocking under `HOOK_ALL` |
|--------|---------------------|-------------------------------|
| `PDO_MYSQL` (mysqlnd) | ✅ | ✅ (rides `php_stream`) |
| `mysqli` (mysqlnd) | ✅ | ✅ |
| `PDO_PGSQL` (`libpq`), Oracle/ODBC | ✅ | ❌ — C-side socket I/O, blocks the worker per query |
| SQLite | ✅ | n/a (local file) |

For true async PostgreSQL, use OpenSwoole's native `Coroutine\PostgreSQL` (not
wrapped by this pool).

## Not for MongoDB

`zealphp-mongodb` uses the official **MongoDB Rust driver** (the `mongodb` crate
+ Tokio, with a C++ coroutine bridge to OpenSwoole) — **not** PDO, mysqli, or
libmongoc. The Rust `mongodb::Client` already pools connections internally, so
`DbConnectionPool` does not apply: keep one `Client` per worker and let the
driver pool.

## Extending — custom drivers

Implement `ZealPHP\Db\PoolDriver` (`connect`, `begin`/`commit`/`rollback`,
`rollbackIfNeeded`, `isAlive`, `disconnect`) to pool any connection type (e.g.
a coroutine-native client), then `new DbConnectionPool($yourDriver, $size)`.
