# Pluggable Store/Counter Backends — Table (default) + Redis/Valkey — Design

**Status:** Design — awaiting review
**Date:** 2026-05-22

## Goal
Make `ZealPHP\Store` and `ZealPHP\Counter` backend-pluggable: keep `OpenSwoole\Table`/`Atomic` as the default (nanosecond, lock-free, millions of ops/sec — the hot path), and add **Redis/Valkey** as a first-class alternative for **cross-node + persistent** shared state. The public `Store`/`Counter` APIs do not change; callers pick a backend per-instance (with a global default).

## Motivation
Today `Store` (OpenSwoole\Table) and `Counter` (OpenSwoole\Atomic) are **single-node, in-memory, volatile**: state is shared only across the workers of one process tree and is lost on restart. Users want (1) **cross-node** shared state (horizontal scaling behind a load balancer), (2) **persistence** across restarts, and (3) **portability** to Redis/Valkey infra they already run. Redis provides all three. The catch: Redis is orders of magnitude slower than Table (socket round-trip vs in-memory), so the design must **never force hot local state through Redis** — backend choice is per-instance.

## Performance framing (the load-bearing constraint)
- `Table`: in-memory, lock-free reads, ~ns latency, millions ops/sec. The "2M reads" hot path.
- `Redis` (local): socket round-trip, ~tens of µs; cross-node ~ms. Orders slower than Table.
- **Therefore:** Redis backend is for cross-node/persistent SHARED state, not the hot path. Per-instance backend selection (hot→Table, shared→Redis) is the core protection. The tiered backend (Phase 2/3) serves the "hot AND cross-node" table.

## Design principle — elegant, native, power on tap
**Hide the complexity (pool, serialization, SET membership, pub/sub coherence); never hide the power.** This API must feel like ZealPHP, not like a Redis library bolted on. Concrete, non-negotiable ergonomics:

- **One-line adoption, zero handler changes.** `Store::defaultBackend('redis')` at boot is the ONLY change for the common case — every existing `Store::make()/set()/get()/incr()/count()` call works unchanged. Handlers stay backend-agnostic: `Store::get('sessions', $id)` is identical whether it's local `Table` or a Redis/Valkey cluster across 10 nodes. Switching backends is an ops decision, not a code rewrite.
- **Connection as a familiar URL + env.** `Store::defaultBackend('redis', 'redis://[:pass@]host:6379/0')` OR an array `['host'=>…, 'port'=>…]`, defaulting to the `ZEALPHP_REDIS_URL` env var (default `redis://127.0.0.1:6379`). `valkey://…` is an accepted alias; unix sockets supported. Nothing phpredis-shaped appears in user code.
- **Native vocabulary, fluent setters.** `Store::TYPE_*`, `Store::defaultBackend()`, `['backend' => 'redis']` — reads like ZealPHP and follows the `App::superglobals()` getter/setter convention (no-arg gets, one-arg sets). `OpenSwoole\Table::TYPE_*` keeps working (BC).
- **Sensible defaults — specify only the difference.** prefix (`zealstore`), `pool_size` 8, `mode` `'tracked'`, fail-loud — all defaulted. A user typically writes the host once and never thinks about the rest.
- **No foreign leakage.** phpredis `RedisException` is wrapped in `ZealPHP\Store\StoreException`; `get()` returns native, typed PHP values; user code never imports a phpredis symbol or sees a raw socket error.
- **Power opt-in via `$opts`, never in the way.** tiered cache / ttl mode / per-instance backend / pub/sub coherence are all `$opts` flags or boot config — the common path never sees them, the power user reaches them without leaving the `Store` API.

**Adoption example (the whole "switch to Redis" diff for a typical app):**
```php
// app.php — before run(); the ONLY change:
Store::defaultBackend('redis');                 // reads ZEALPHP_REDIS_URL (default localhost)
// (or) Store::defaultBackend('redis', 'redis://cache.internal:6379/1');

// Everywhere else — UNCHANGED:
Store::make('sessions', ['uid' => [Store::TYPE_STRING, 64], 'hits' => [Store::TYPE_INT, 4]]);
Store::set('sessions', $id, ['uid' => 'alice', 'hits' => 0]);
Store::incr('sessions', $id, 'hits');
$row = Store::get('sessions', $id);             // ['uid'=>'alice','hits'=>1] — typed, same as Table
```

## Architecture
```
StoreBackend (interface):
  set($table,$key,$row): bool
  get($table,$key,$field=null): mixed
  del($table,$key): bool
  exists($table,$key): bool
  incr($table,$key,$col,$by=1): int|float
  decr($table,$key,$col,$by=1): int|float
  count($table): int
  iterate($table): iterable           // yields [$key => $row]
  clear($table): void
  ├─ TableBackend   (default — wraps today's OpenSwoole\Table logic verbatim)
  ├─ RedisBackend   (phpredis via RedisClient adapter; SET-backed membership)
  └─ TieredBackend  (Phase 2/3 — TableBackend L1 + RedisBackend L2)

CounterBackend (interface): get/set/incr/decr/compareAndSet/reset
  ├─ AtomicBackend       (default — wraps OpenSwoole\Atomic)
  └─ RedisCounterBackend (INCRBY/DECRBY/GET/SET; CAS via Lua)

RedisClient (adapter): thin wrapper over phpredis; the ONE place the client lib is
  referenced, so predis / OpenSwoole\Coroutine\Redis can be swapped later.
RedisConnectionPool: per-worker OpenSwoole\Coroutine\Channel of N RedisClient conns.
RedisPubSub (Phase 3): per-worker dedicated subscriber coroutine for L1 invalidation.
```

`Store` and `Counter` keep their **exact current public API** and delegate to the configured backend instance.

### Config API
- `Store::defaultBackend(string $kind, array $conn = []): void` — `'table'` (default) or `'redis'`; `$conn` = host/port/auth/db/prefix/pool_size/timeouts. Shared by Store + Counter.
- `Counter::defaultBackend(...)` — same; if only `Store::defaultBackend` is set, Counter inherits the same connection config.
- Per-instance override:
  - `Store::make($name, $columns, $opts = [])` — `$opts['backend']` = `'table'|'redis'|'tiered'`; `$opts['mode']` = `'tracked'|'ttl'`; `$opts['ttl']` (seconds, ttl mode); `$opts['l1_ttl']` (tiered).
  - `new Counter($name, $opts = [])` — `$opts['backend']`.
- Connection config also settable once via a shared `App`-level helper if both Store+Counter use Redis (implementation detail; the per-class setters are canonical).

## Phase 1 — backend abstraction + flat Redis

### Connection management — per-worker coroutine pool (REQUIRED)
Concurrent coroutines in one worker **cannot share one phpredis socket** (interleaved commands corrupt the RESP stream). `RedisBackend` acquires a connection from a **per-worker pool** (`OpenSwoole\Coroutine\Channel` of N `RedisClient`s) for each op and releases it. Pool opens lazily in `workerStart`; size configurable (`pool_size`, default 8). HOOK_ALL makes phpredis socket I/O non-blocking in coroutine mode; in sync/superglobals mode a size-1 pool (sequential) is used. This pool is reusable infra (closes the "ConnectionPool absent" gap from the OpenSwoole audit).

### Types & serialization — backend-neutral `Store::TYPE_*`
New constants `Store::TYPE_INT`, `Store::TYPE_FLOAT`, `Store::TYPE_STRING` (alias `OpenSwoole\Table::TYPE_*` for the Table backend; the old constants keep working — BC). The column schema is **enforced** by Table and **advisory** for Redis, but used for **typed (de)serialization** so `get()` returns proper `int|float|string` across both backends (Redis hashes are stringly-typed). Identical return types regardless of backend.

### Key layout & namespacing
- Row: Redis HASH at `{prefix}:{table}:{key}` (columns → hash fields).
- Membership: Redis SET at `{prefix}:{table}:__keys__`.
- `prefix` configurable (default `zealstore`); enables multi-app isolation on shared Redis/Valkey.

### count / iterate — SET-backed (tracked mode)
- `set` of a NEW key → `SADD {table}:__keys__ key`; `del` → `SREM`; `clear` → del members + the SET.
- `count()` = `SCARD` (O(1)); `iterate()`/`names()` = `SSCAN`.
- Matches Table's O(1) count + full iteration exactly.

### TTL — per-table mode (chosen at make())
- `mode => 'tracked'` (default): SET-backed, O(1) count, NO TTL.
- `mode => 'ttl'`: supports per-key/per-table expiry (`EXPIRE`/`SETEX`-style). count()/iterate use `SCAN MATCH {table}:*` (O(N)) because an expired key cannot fire `SREM` (a tracked SET would drift). So it is "TTL **or** O(1)-count," picked per table. Documented honestly.

### Counter on Redis (RedisCounterBackend)
- `incr/decr` → `INCRBY`/`DECRBY`; `get/set` → `GET`/`SET`; `reset` → `SET 0`.
- `compareAndSet($expected,$new)` → a small **Lua script** (atomic CAS server-side).
- Cross-node atomic counters.

### Bulk ops (value-add)
- `Store::mget($table, array $keys): array` and `Store::mset($table, array $rows): bool` — pipelined (one round-trip), big cross-node latency win. Table backend implements them as a loop (still correct).

### Lifecycle / timing
- `Store::make()` before `run()` **registers** the schema + backend choice (no shared-memory alloc for Redis tables). The Redis pool connects per-worker in `workerStart`. Table backend unchanged (pre-fork shared memory inherited on fork).

### Error handling — fail-loud
- Redis unreachable at boot/first-connect → `RuntimeException` (do NOT silently degrade to Table — that hides misconfig and loses the cross-node guarantee).
- Mid-run op failure → throw + `elog`. One opt-in escape hatch: `$opts['on_error'] => 'fallback_table'` for users who explicitly accept local-degrade.

### Health / reconnect (value-add)
- Pool validates connections (`PING`) and reconnects dropped ones. `Store::ping($name): bool` exposed.

### Metrics (light value-add)
- Backend exposes op counts + pool usage (gauge) for the future Metrics surface. No heavy dependency.

## Phase 2 — Tiered backend (Table L1 + Redis L2)
- `backend => 'tiered'`: reads hit local `TableBackend` (L1) at full speed; on miss, fetch from `RedisBackend` (L2) and populate L1. Writes go to L2 (source of truth) then update/evict L1 (write-through).
- Coherence (single-node or until Phase 3): a configurable **bounded-staleness local TTL** (`l1_ttl`, e.g. 1–5s) caps how stale an L1 entry can be. Good enough for caches; not strongly consistent across nodes yet.
- L1 is itself a `Table` (fixed capacity) — eviction when full is LRU-ish (drop on capacity; re-fetch on next read).

## Phase 3 — Pub/Sub invalidation (coherent tier)
- `RedisPubSub`: each worker that hosts a tiered table runs a **dedicated subscriber coroutine** (separate connection, NOT from the op pool — SUBSCRIBE monopolizes a connection) listening on `{prefix}:invalidate:{table}`.
- On a tiered write, publish the invalidated key. Subscribers evict that key from their L1. Messages are **origin-tagged** (worker/node id) so a worker skips invalidations it published itself (its L1 already holds the fresh value).
- Result: cross-node L1 coherence (eventually-consistent within pub/sub propagation latency, ~sub-ms locally).
- **SPIKE (gates Phase 3):** verify phpredis `subscribe()` yields under `Runtime::enableCoroutine(HOOK_ALL)` inside a dedicated coroutine (doesn't block the worker), and that publish-from-pool + subscribe-on-dedicated-conn interoperate. If phpredis subscribe does NOT yield cleanly, fall back to `OpenSwoole\Coroutine\Redis`'s subscribe for the subscriber connection only.

## Docs changes
- Migrate `OpenSwoole\Table::TYPE_*` → `Store::TYPE_*` in docs/learn (BC note: old constants still work).
- New "Store backends" guide: Table vs Redis trade-offs (latency vs cross-node/persistence), the TTL-vs-count trade-off, tiered cache + coherence modes, fail-loud, connection/pool config.
- `/design-tradeoffs` row + `.claude/CLAUDE.md` Store/Counter sections + companion scaffold CLAUDE.md.

## Testing
- **Local Redis/Valkey** in the harness: prefer a `redis-server`/`valkey-server` binary on a test port, else Docker; skip Redis tests gracefully if neither is available (like the perl CGI tests).
- Unit: `RedisBackend`/`RedisCounterBackend` (typed round-trip, SET membership, tracked vs ttl mode, CAS via Lua, bulk mget/mset, pool concurrency — many coroutines hammering the pool), `TieredBackend` (read-through/write-through, l1_ttl staleness), `RedisPubSub` (invalidation evicts L1, self-invalidation skipped).
- **Parity suite:** the existing Store/Counter behavioral tests run against BOTH backends (Table + Redis) to prove identical semantics.
- Integration: a route-level test exercising a Redis-backed table end-to-end; a two-"node" (two server processes) test proving cross-node visibility + pub/sub L1 invalidation.
- CI: add a Redis/Valkey service container; gate Redis tests on its availability.

## Phasing (the implementation plan will sequence these as independent PRs)
1. **Phase 1** — backend interfaces + Table/Redis flat backends for Store + Counter + pool + types + count/iterate + TTL mode + bulk + errors + docs. Shippable on its own (delivers "configure Redis as Store/Counter backend").
2. **Phase 2** — tiered backend with bounded-staleness l1_ttl. Shippable.
3. **Phase 3** — pub/sub invalidation (spike-gated). Shippable.

Each phase is its own plan/PR with its own review + green CI before the next.

## Out of scope (future, noted not built)
- Redis **Cluster / Sentinel** HA topologies (phpredis supports them; adds config complexity).
- General-purpose pub/sub API beyond L1 invalidation (cross-node WebSocket broadcast, cache-invalidation events) — the `RedisPubSub` component is built minimal-for-invalidation but designed to be promotable later.
- Redis-backed **sessions** (a separate, related opportunity).

## Risks / open questions
- phpredis `subscribe()` coroutine behavior under HOOK_ALL — **spike resolves before Phase 3**.
- Pool sizing vs `pool_size` × workers × Redis `maxclients` — document the math; default conservative (8).
- `mode=ttl` count() is O(N) via SCAN — documented; steer high-frequency counting to a Counter or a tracked table.
- Redis `maxmemory` eviction can drop keys out from under a tracked SET (drift) — document; recommend `noeviction` or a TTL-mode table for cache-like use.
- L1 (Table) fixed capacity vs working-set size — document sizing; miss → L2 fetch (correctness preserved, just slower).
