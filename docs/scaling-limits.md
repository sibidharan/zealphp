# Scaling limits & sizing

The hard limits and sizing formulas you need when running ZealPHP at scale, from
the 2026-06-03 scalability audit. None of these are bugs — they're properties of
the underlying primitives (`OpenSwoole\Table`, Redis connections) that you must
size for.

## Redis connection budget

Every worker, on every node, opens its own connections:

- A **data pool** (`RedisConnectionPool`, default `pool_size = 8`) per worker.
- A **dedicated SUBSCRIBE connection** per worker for each pub/sub runner
  (`App::subscribe` → `RedisPubSub`, `App::subscribeReliable` → `RedisStreams`).

So steady-state Redis connections ≈

```
(pool_size + subscriber_runners) × workers × nodes
```

For `pool_size = 8`, one pub/sub + one streams runner (`+2`), 16 workers, 4
nodes: `(8 + 2) × 16 × 4 = 640` connections against one Redis. Redis's default
`maxclients` is 10000, but a proxy/cluster or a small instance can be far lower.

**Sizing:** raise Redis `maxclients` to comfortably exceed the formula, OR front
Redis with a multiplexing proxy (Twemproxy / Envoy / Redis Cluster), OR lower
`pool_size` for large fleets. The per-node pub/sub aggregator
(`docs/architecture/2026-06-03-cross-node-fanout.md`) collapses the *subscriber*
term from `workers × nodes` to `nodes` once implemented.

`App::stats()` / `Store::stats()` expose `pool_clients_created_total` so you can
watch actual connection growth per worker.

## `OpenSwoole\Table` `maxRows` is a hard cap with NO eviction

`Store` / `Cache` on the **Table backend** allocate a fixed-size
`OpenSwoole\Table` at `maxRows` **at master boot**. It does not grow and does
**not evict** — once full, every new distinct key's `set()` **silently fails**
(returns `false`, the value is dropped).

- **Memory:** `RAM ≈ maxRows × (Σ column sizes + ~32 B/row)`. A 1M-row table with
  a 32-byte string column ≈ 280 MB allocated up front. Setting `maxRows =
  PHP_INT_MAX` will OOM-kill the master at boot.
- **Advisory:** `TableBackend::set()` now emits a **one-time warning per table**
  the first time a write fails, distinguishing "table FULL at maxRows" (no
  eviction → keys silently dropped) from "row didn't fit" (a column value
  exceeded its declared size). Grep your debug log for `is FULL at its hard
  maxRows cap`.

**Sizing:** size `maxRows` to the **full hot working set** up front (it can't
grow). For unbounded / TTL'd data, use the **Redis** or **Tiered** backend
(`Store::defaultBackend(Store::BACKEND_REDIS)`), or pair with `Cache::init(
ttlSeconds: …)` so entries expire instead of accumulating to the cap.

## `iteratePaged()` is O(N²) on the Table backend

`TableBackend::iteratePaged()` treats the cursor as a skip-offset and
**re-iterates from row 0 on every call**, so paginating all N rows costs
`O(N² / page)`. Fine for a few thousand rows; above that it's quadratic.

**Use instead:**

- The **Redis / Tiered** backend, whose `iteratePaged()` is a true `SSCAN`
  cursor (O(N) total).
- `iterate()` (single O(N) pass) when you can hold one pass in memory and don't
  need page boundaries.

`count()`, `names()`, and `iterate()` on the Table backend are O(N) single
passes and are fine.

## See also

- `docs/db-connection-pool.md` — bounding DB connections under coroutine
  concurrency (the same connection-budget concern for SQL).
- `docs/architecture/2026-06-03-cross-node-fanout.md` — the per-node pub/sub
  aggregator + WS room targeting design that reduces the cross-node fan-out.
- `docs/store.md` — backend selection (Table vs Redis vs Tiered).
