# Store/Counter Pluggable Backends — Phase 1 Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Phase 1 of the Store/Counter pluggable-backend design: keep `OpenSwoole\Table`/`Atomic` as the unchanged default, add a Redis/Valkey backend (flat — no tiering, no pub/sub yet), preserve the existing `Store`/`Counter` public API verbatim, deliver "one-line adoption" via `Store::defaultBackend('redis')`.

**Architecture:** Introduce `StoreBackend` / `CounterBackend` interfaces. `Store`/`Counter` become thin facades that delegate to a backend instance. `TableBackend` / `AtomicBackend` wrap today's logic verbatim (BC). `RedisBackend` / `RedisCounterBackend` use a `RedisClient` adapter that auto-detects phpredis or predis. A `RedisConnectionPool` (per-worker `Coroutine\Channel` of N clients) prevents two coroutines from interleaving commands on one socket.

**Tech Stack:** PHP 8.3, OpenSwoole 26.2, `predis/predis` (dev dep, pure-PHP fallback), optional phpredis (preferred when present), `valkey-server` for tests.

**Spec reference:** `docs/superpowers/specs/2026-05-22-store-redis-backend-design.md`

---

## Conventions (apply to every task)

- **Strict types** at the top of every new `src/` file: `<?php declare(strict_types=1);`
- **Namespace:** `ZealPHP\Store` for backends + adapters + pool; `ZealPHP\Counter` reuses the `ZealPHP\` top-level. Old `ZealPHP\Store` and `ZealPHP\Counter` classes stay where they are.
- **PHPStan level 10:** no `mixed` widening to silence, no `@phpstan-ignore`. Fix the underlying type problem.
- **TDD:** every task writes the failing test first, then implementation, then verifies green.
- **Commit after each task** (frequent commits per CLAUDE.md). Messages follow the existing `<scope>: <imperative>` style.
- **Skip-on-no-valkey:** tests touching a real Redis instance call `RedisTestCase::skipIfNoRedis()` so the suite stays green where `valkey-server` isn't running.

---

## Task 1: Test infrastructure — Valkey lifecycle + predis dev dep + RedisTestCase

**Files:**
- Modify: `composer.json` (add `"predis/predis": "^2.2"` under `require-dev`)
- Create: `tests/Helpers/RedisTestCase.php`
- Create: `scripts/test-valkey-start.sh`, `scripts/test-valkey-stop.sh`
- Modify: `Makefile` (add `valkey-up` / `valkey-down` targets)
- Modify: `phpunit.xml` (add `ZEALPHP_REDIS_URL=redis://127.0.0.1:16379/0` env)
- Modify: `.github/workflows/*.yml` (add valkey service — Task 14)

- [ ] **1.1 — Add predis dev dep:** `composer require --dev predis/predis:^2.2`
- [ ] **1.2 — Create `tests/Helpers/RedisTestCase.php`:** boots a predis client to `ZEALPHP_REDIS_URL`, calls `$client->ping()`, calls `markTestSkipped` on `Throwable`, flushes db on setUp + tearDown.
- [ ] **1.3 — Start/stop scripts:** `scripts/test-valkey-start.sh` boots `valkey-server --port 16379 --daemonize yes --pidfile /tmp/zealphp-test-valkey/valkey.pid --save "" --appendonly no` then waits up to 1 s for `valkey-cli ping` to succeed. Stop script kills the pid.
- [ ] **1.4 — Makefile targets:** `valkey-up` and `valkey-down` invoke the scripts; both surface in `make help`.
- [ ] **1.5 — phpunit env:** add `<env name="ZEALPHP_REDIS_URL" value="redis://127.0.0.1:16379/0"/>` to the `<php>` block of `phpunit.xml`.
- [ ] **1.6 — Smoke + commit:** `make valkey-up && ./vendor/bin/phpunit tests/Unit/StoreTest.php` stays green. Commit message: `test(store): valkey-server harness + predis dev dep + RedisTestCase`.

---

## Task 2: `RedisClient` adapter — phpredis + predis dual-impl

**Files:**
- Create: `src/Store/RedisClient.php`, `src/Store/RedisDriver.php` (interface), `src/Store/PhpredisDriver.php`, `src/Store/PredisDriver.php`, `src/Store/StoreException.php`
- Create: `tests/Unit/Store/RedisClientTest.php`

**Why an adapter:** spec §Architecture — "RedisClient (adapter): thin wrapper over phpredis; the ONE place the client lib is referenced". Extended to predis-or-phpredis at construction time (phpredis preferred when extension is loaded, predis fallback).

- [ ] **2.1 — Failing tests:** `testGetSetDelRoundTrip`, `testHsetHmgetWorksAcrossDrivers`, `testHincrbyTypedReturn`, `testSaddSremScardSscan`, `testEvalLuaScript` (small CAS Lua script verifying server-side atomic compare-and-set), `testPingAndClose`. Each asserts on values returned from a real valkey via `RedisTestCase::$url`.

- [ ] **2.2 — Run, expect failure:** `Class ZealPHP\Store\RedisClient not found`.

- [ ] **2.3 — `RedisDriver` interface:** methods `name`, `set/get/del/exists/expire`, `hset/hgetall/hmget/hincrby/hincrbyfloat/hdel`, `sadd/srem/scard/sscan`, `incrby/decrby`, `eval` (Redis Lua scripting), `ping/close/pipeline/scanKeys`. All return types pinned (`int`, `bool`, `string|null`, `\Generator`, etc.) — no `mixed`.

- [ ] **2.4 — `PredisDriver`:** pass-through to `Predis\Client`. `hgetall` cast to `array<string,string>`. `sscan` wraps `SSCAN` cursor loop as a `\Generator`. `pipeline()` wraps `$predis->pipeline(...)`.

- [ ] **2.5 — `PhpredisDriver`:** same surface against the `Redis` class. Parse the URL; set `OPT_PREFIX` to empty. Cursor loop in `sscan`/`scanKeys`.

- [ ] **2.6 — `RedisClient`:** the public adapter. Constructor takes URL + `['prefer' => 'auto'|'phpredis'|'predis']`. `auto` picks phpredis when `extension_loaded('redis')`, else predis when `class_exists(\Predis\Client::class)`, else throws `StoreException`.

- [ ] **2.7 — `StoreException`:** extends `\RuntimeException`. Used everywhere the Store namespace throws.

- [ ] **2.8 — Verify + commit:** `./vendor/bin/phpunit tests/Unit/Store/RedisClientTest.php` green, PHPStan clean. Commit: `feat(store): RedisClient adapter — phpredis preferred, predis fallback`.

---

## Task 3: `RedisConnectionPool` — per-worker Channel of N clients

**Files:**
- Create: `src/Store/RedisConnectionPool.php`
- Create: `tests/Unit/Store/RedisConnectionPoolTest.php`

The pool **must** be per-worker because two coroutines sharing one socket interleave RESP frames and corrupt the stream. Default size 8.

- [ ] **3.1 — Failing test (concurrent 20 cors over 4-conn pool):**
  - Enable `HOOK_ALL`, build pool size 4, dispatch 20 coroutines each acquiring → `set` / `get` → releasing. Collect 20 results via a `Coroutine\Channel`; sort and compare to expected sorted list.
  - `testSizeIsBounded` asserts `$pool->size() === 2` after `new RedisConnectionPool($url, 2)`.

- [ ] **3.2 — Implement pool:** `__construct` prefills a `Coroutine\Channel` with N `RedisClient` instances. `acquire(float $timeout=5.0)` pops; on timeout throws `StoreException`. `release($c)` pushes back. `with(callable $fn)` does acquire→fn→release in `try/finally`.

- [ ] **3.3 — Sync-mode fallback (size-1):** in `acquire`, check `Coroutine::getCid() < 0`; if outside a coroutine, return a lazily-built single client (stored in `$this->syncClient`) instead of `pop`ing (which would block the worker). `release` is a no-op for the sync client.

- [ ] **3.4 — Verify + commit:** `feat(store): RedisConnectionPool — per-worker Channel of N RedisClients`.

---

## Task 4: `StoreBackend` interface + `TableBackend`

**Files:**
- Create: `src/Store/StoreBackend.php` (interface)
- Create: `src/Store/TableBackend.php` (lifts current `Store` static logic into an instance)
- Create: `tests/Unit/Store/TableBackendTest.php`

- [ ] **4.1 — Failing tests:** `testSetGetTypedRow`, `testIncrReturnsNewValue`, `testDelExistsCount`, `testIterateYieldsAllRows`, `testFieldGet` (single-field read via `$b->get($t, $k, $col)`), `testClearWipesAllRows`.

- [ ] **4.2 — Interface:** `make/set/get/del/exists/incr/decr/count/iterate/clear/names`. Param/return types fully pinned. `make()` takes `$opts` (used in Task 6 for Redis modes; Table backend ignores).

- [ ] **4.3 — `TableBackend` impl:** lift the body of every existing static `Store::*` method into instance methods on `TableBackend`. The shared map is `$this->tables` / `$this->schemas`. Add `mapType()` that translates `Store::TYPE_*` ints to `OpenSwoole\Table::TYPE_*` ints (they will be identical constants — see Task 10 — but the indirection is the BC layer).

- [ ] **4.4 — Verify + commit:** `feat(store): StoreBackend interface + TableBackend (extracts current logic)`.

---

## Task 5: Typed (de)serialization — `TypeCodec`

**Files:**
- Create: `src/Store/TypeCodec.php`
- Create: `tests/Unit/Store/TypeCodecTest.php`

Redis hash fields are stringly-typed on the wire; the column schema lets us coerce back so `get()` returns the same `int|float|string` shape as the Table backend.

- [ ] **5.1 — Failing tests:**
  - `testRoundTripPreservesTypes` — `encodeRow` then `decodeRow` returns the exact original typed row.
  - `testDecodeMissingFieldsAreNull` — `hmget` returned `[null, ...]`.
  - `testDecodeRowReturnsNullWhenEmpty` — empty `hgetall` (key didn't exist) → `null`.
  - `testDecodeField` — single-field coercion.

- [ ] **5.2 — Impl:** `encodeRow` does `(string) $val`. `decodeRow` returns `null` for empty wire, else builds via `coerce` per schema column type. `coerce(TYPE_INT) → (int)`, `TYPE_FLOAT → (float)`, `TYPE_STRING → $raw`. `null` raw with a typed schema gives default-by-type (`0`, `0.0`, `''`) — matches `OpenSwoole\Table`'s zero-value contract.

- [ ] **5.3 — Verify + commit:** `feat(store): TypeCodec — backend-neutral row (de)serialization`.

---

## Task 6: `RedisBackend` — tracked mode (SET-backed O(1) count)

**Files:**
- Create: `src/Store/RedisBackend.php`
- Create: `tests/Unit/Store/RedisBackendTrackedTest.php`

Tracked mode (default): every `set` of a NEW key SADDs the key into a `__keys__` set; `count()` is `SCARD` (O(1)); `iterate()` is `SSCAN`.

Key layout:
- Row: HASH at `{prefix}:{table}:{key}` (prefix defaults `zealstore`)
- Membership: SET at `{prefix}:{table}:__keys__`

- [ ] **6.1 — Failing tests:** parity with `TableBackendTest` plus tracked-specific (`testSetAddsToMembership`, `testDelRemovesFromMembership`, `testCountIsScard`, `testIterateUsesSscan`, `testClearWipesKeysAndSet`).

- [ ] **6.2 — Impl:** constructor takes `RedisConnectionPool` + `prefix`. `make()` stores schema + `mode='tracked'` in `$this->tableOpts`. `set()` does `EXISTS` check, `HSET`, `SADD` when new. `get()` uses `HGETALL` (full row) or `HMGET` (single field) routed through `TypeCodec`. `del()` does `DEL` + `SREM`. `incr` uses `HINCRBY` / `HINCRBYFLOAT` based on schema column type. `count()` is `SCARD` of the set key. `iterate()` is `SSCAN` → for each key, `HGETALL` + decode. `clear()` SSCANs all members, DELs them in pipelined batches, DELs the set.

- [ ] **6.3 — Verify against valkey + commit:** `feat(store): RedisBackend — tracked mode (SET-backed O(1) count)`.

---

## Task 7: `RedisBackend` — TTL mode (per-key expiry, SCAN-backed count)

**Files:**
- Modify: `src/Store/RedisBackend.php` (the existing tracked-mode branches widen for `ttl`)
- Modify: `src/Store/RedisDriver.php`, `PhpredisDriver`, `PredisDriver` — add `scanKeys(string $match, int $count): \Generator`
- Create: `tests/Unit/Store/RedisBackendTtlTest.php`

- [ ] **7.1 — Failing tests:** `testTtlExpiresKey` (sets a `ttl=1` table, writes, sleep(2), `get` returns `null`); `testCountUsesScanInTtlMode` (3 writes → count=3 after, then sleep, count=0).

- [ ] **7.2 — Impl:** `make()` accepts `$opts['mode'] = 'ttl'`, `$opts['ttl'] = N seconds`. In `set()`, the `EXPIRE` is applied unconditionally for ttl mode. `count()` and `iterate()` branch on mode: tracked uses set ops, ttl uses `scanKeys($prefix:$name:*, 200)` excluding the (now-unused) set key.

- [ ] **7.3 — Doc the trade-off:** in the class docblock + a small note inside `make()` warning that `mode=ttl` makes `count()` O(N).

- [ ] **7.4 — Verify + commit:** `feat(store): RedisBackend — ttl mode (per-key expiry, SCAN-backed count)`.

---

## Task 8: Bulk ops — `mget` / `mset` (pipelined on Redis, looped on Table)

**Files:**
- Modify: `src/Store/StoreBackend.php` (add `mget` / `mset`)
- Modify: `TableBackend`, `RedisBackend`
- Create: `tests/Unit/Store/BulkOpsTest.php` (parity matrix over both backends)

- [ ] **8.1 — Failing tests:** `testMgetReturnsRowsInOrder` (asserts each key → row OR `null` for missing); `testMsetWritesAllAtomically` (followed by `count()` proving N rows visible).

- [ ] **8.2 — Impl:**
  - `TableBackend::mget` — loop over keys, call `get()`.
  - `RedisBackend::mget` — `RedisClient::pipeline` queues `hgetall` per key in one round-trip; map results back through `TypeCodec`.
  - Same shape for `mset`.

- [ ] **8.3 — Verify + commit:** `feat(store): bulk mget/mset — pipelined on Redis, looped on Table`.

---

## Task 9: `CounterBackend` interface + AtomicBackend + RedisCounterBackend (Lua CAS)

**Files:**
- Create: `src/Counter/CounterBackend.php` (interface)
- Create: `src/Counter/AtomicBackend.php`, `src/Counter/RedisCounterBackend.php`
- Create: `tests/Unit/Counter/AtomicBackendTest.php`, `tests/Unit/Counter/RedisCounterBackendTest.php`

- [ ] **9.1 — Failing tests:** `testGetSet`, `testIncrDecr`, `testCompareAndSetMatches`, `testCompareAndSetRejectsMismatch`, `testReset`.

- [ ] **9.2 — Interface:** `get/set/incr/decr/compareAndSet/reset`, all pinned `int`.

- [ ] **9.3 — `AtomicBackend`:** lift current `Counter` logic — wraps `OpenSwoole\Atomic`.

- [ ] **9.4 — `RedisCounterBackend`:** `INCRBY` / `DECRBY` / `GET` / `SET 0`. `compareAndSet` runs a small Redis Lua script via `RedisClient::eval` for server-side atomic compare-and-set (one round-trip, no race). Lua script string lives as a private const.

- [ ] **9.5 — Verify + commit:** `feat(counter): CounterBackend + AtomicBackend (BC) + RedisCounterBackend with Lua CAS`.

---

## Task 10: `Store` + `Counter` facade refactor — delegate to backend

**Files:**
- Modify: `src/Store.php` — thin facade
- Modify: `src/Counter.php` — thin facade
- Modify: `tests/Unit/StoreTest.php`, `tests/Unit/CounterTest.php` — `@dataProvider backends`

This is **the BC moment.** Every existing test that today passes against `Store` MUST pass when run with `defaultBackend('table')` AND with `defaultBackend('redis')`. The data provider yields both, and the matrix becomes the parity proof.

- [ ] **10.1 — `Store::TYPE_*` constants:** declared as `public const TYPE_INT = OpenSwoole\Table::TYPE_INT` (same int values — old code passing `OpenSwoole\Table::TYPE_*` keeps working).

- [ ] **10.2 — `Store::defaultBackend(?string $kind, array|string $conn = [])` setter/getter:**
  - No args → returns the cached backend (lazily built).
  - With args → resets config, clears the cache.
  - `$conn` accepts a URL string OR an array (`host`, `port`, `url`, `pool_size`, `prefix`).
  - Reads `ZEALPHP_REDIS_URL` env if URL omitted, defaults `redis://127.0.0.1:6379`.

- [ ] **10.3 — Every static method delegates:**
  - `Store::make/set/get/del/exists/incr/decr/count/iterate/names/clear/mget/mset/ping` — one-liner each, forwarding to `self::defaultBackend()->...`.
  - `Store::table($name)`: only works for `TableBackend`; Redis backend throws `StoreException` (`raw table not exposable for redis backend`).

- [ ] **10.4 — `Counter` facade:** constructor accepts `$opts['backend']`; defaults to `Store::defaultBackend()`'s kind so the two stay in sync unless overridden explicitly.

- [ ] **10.5 — BC check:** run the EXISTING `tests/Unit/StoreTest.php` and `tests/Unit/CounterTest.php` unchanged — they pass on the new facade against `table` backend. (This is the no-regression gate.)

- [ ] **10.6 — Parity matrix:** convert both tests to a `@dataProvider backends` that yields `'table'` always and `'redis'` when phpredis or predis is present + a valkey ping succeeds. Every test now runs against both, asserting identical outputs.

- [ ] **10.7 — PHPStan + commit:** `feat(store): Store + Counter facades delegate to pluggable backend (BC preserved)`.

---

## Task 11: Boot wiring + error/health surface

**Files:**
- Modify: `src/App.php` (env-var-driven default in `App::run()` pre-fork)
- Modify: `src/Store/PhpredisDriver.php`, `PredisDriver` (wrap lib exceptions in `StoreException`)
- Add `Store::ping()` to the facade (delegates to backend; Table returns `true` unconditionally; Redis pings via the pool).
- Create: `tests/Unit/Store/StoreFacadeErrorTest.php`

- [ ] **11.1 — Env-var bootstrap:** in `App::run()` (before worker fork), if `getenv('ZEALPHP_STORE_BACKEND')` is set, call `Store::defaultBackend($kind)` so deployment can switch backends without an `app.php` edit.

- [ ] **11.2 — Wrap driver exceptions:** every driver method that calls into the lib (phpredis `\RedisException`, predis `\Predis\PredisException`) catches + rethrows as `StoreException` preserving message + previous. Smoke: a route handler `catch (StoreException $e)` works for both libs.

- [ ] **11.3 — `Store::ping()`:** facade method, returns `bool`. Test that an unreachable Redis throws `StoreException` instead of timing out silently.

- [ ] **11.4 — Opt-in fallback:** `Store::make($name, $cols, $opts = ['on_error' => 'fallback_table'])` registers a TableBackend fallback inside the RedisBackend wrapper for that table only; on `StoreException` during writes, route to the fallback + `elog` a warning. Test it explicitly.

- [ ] **11.5 — Commit:** `feat(store): boot wiring + StoreException wrapping + ping() + opt-in fallback`.

---

## Task 12: Integration tests — real route + two-node visibility check

**Files:**
- Create: `tests/Integration/StoreBackendIntegrationTest.php`
- Add route to `route/demo.php`: `GET /demo/store-redis-roundtrip` that does `Store::set + get + incr + count + del` and returns JSON.

- [ ] **12.1 — Single-node:** boot the demo server with `ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://127.0.0.1:16379/0`, hit `/demo/store-redis-roundtrip`, assert JSON shape.

- [ ] **12.2 — Two-node visibility:** `scripts/test-2node-up.sh` boots two ZealPHP processes (ports 8090 + 8091) sharing one valkey. Test writes via node-A, reads via node-B, asserts visibility. Proves cross-node guarantee.

- [ ] **12.3 — Commit:** `test(store): integration — single-node + two-node visibility over Redis`.

---

## Task 13: Docs + `/design-tradeoffs` row + CLAUDE.md

**Files:**
- Modify: `.claude/CLAUDE.md`
- Modify: `template/pages/store.php` (add `#backends` subsection)
- Modify: `template/pages/design-tradeoffs.php` (one new row)
- Create: `template/pages/learn/store-backends.php`
- Modify: `README.md` (feature mention)

- [ ] **13.1 — CLAUDE.md:** under the existing Store/Counter section, append a `Pluggable backends (v0.3.0)` paragraph: one-line adoption story + tracked/ttl trade-off pointer + Redis-only restriction on `Store::table()`.

- [ ] **13.2 — store.php #backends:** comparison table (Latency / Cross-node / Persistence / Use-case) + the literal one-line adoption snippet + cross-link to the learn guide.

- [ ] **13.3 — design-tradeoffs row:** "Local Table by default; Redis opt-in per Store" — gain: ns hot path stays, cross-node available with one line; cost: two backends to test against + type coercion at the Redis boundary.

- [ ] **13.4 — learn/store-backends.php:** sections — When to choose Redis · The one-line switch · Tracked vs TTL trade-off · Connection pool sizing · Bulk ops · Counter CAS · Error handling + fallback.

- [ ] **13.5 — README:** one paragraph under Features mentioning Redis/Valkey backend.

- [ ] **13.6 — Smoke + commit:** `php app.php restart`; browse the touched pages; confirm 0 console errors. Commit: `docs(store): pluggable backends — CLAUDE.md + store page + design-tradeoffs row + learn guide`.

---

## Task 14: CI — Valkey service + parity matrix on the runner

**Files:**
- Modify: `.github/workflows/ci.yml` (or whichever file defines the PR matrix)
- Confirm `phpunit.xml` env var lands.

- [ ] **14.1 — Add service:** `services.valkey: image: valkey/valkey:7-alpine` on port 16379 → 6379 with `healthcheck` + retries.

- [ ] **14.2 — Run unit + integration matrix:** the job exports `ZEALPHP_REDIS_URL=redis://127.0.0.1:16379/0` so `RedisTestCase` picks it up.

- [ ] **14.3 — Push branch, watch CI, fix any env-mismatch issues, open PR.**

- [ ] **14.4 — Open PR:**
  - `gh pr create --base master --head feat/store-redis-backend --title "feat(store): pluggable backends — Table (default) + Redis/Valkey (Phase 1)" --body "<link to spec + this plan>"`

---

## Verification (before declaring Phase 1 done)

1. `./vendor/bin/phpunit tests/Unit/ --testdox` — all green.
2. `./vendor/bin/phpunit tests/Integration/ --testdox` (server up) — all green.
3. `make valkey-up && ./vendor/bin/phpunit tests/Unit/Store/ tests/Unit/Counter/ --testdox` — every Redis test green.
4. `./vendor/bin/phpstan analyse --no-progress` — 0 errors, level 10.
5. Parity matrix proven: every existing `StoreTest`/`CounterTest` case runs against BOTH backends, identical assertions.
6. One-line adoption: `Store::defaultBackend('redis')` in `app.php`, no other changes — demo app works end-to-end against valkey.
7. PR open, CI green (incl. the valkey service).
8. Docs updated; live site smoke (browse `/learn/store-backends`, `/store#backends`, `/design-tradeoffs`).

---

## Risks & mitigations specific to Phase 1

| Risk | Mitigation |
|---|---|
| **phpredis missing on user machines** | predis fallback ships in `require-dev`; production users can `pecl install redis` for the perf bump. Adapter auto-detects. |
| **Adapter divergence between phpredis / predis** | One `RedisDriver` interface; both impls run the SAME `RedisClientTest` (driver-data-provider parity once both impls exist). |
| **Connection-pool exhaustion under high concurrency** | Configurable `pool_size`; `acquire` has a 5 s timeout that throws `StoreException` (fail loud — pool too small is a config bug, not silent slowness). |
| **Two coroutines sharing one socket → corrupt RESP** | The pool is the entire defence. Acquire-release contract tested by 20 concurrent coroutines on a 4-conn pool. |
| **`SCAN`-based count drift under writes** | Documented honestly; `ttl` mode users get O(N) `count()`. Steer to `tracked` mode or `Counter` when O(1) matters. |
| **`maxmemory` eviction drops keys from tracked SET** | Documented in CLAUDE.md and the learn guide; recommendation is `noeviction` for ZealPHP stores, or use `ttl` mode for cache-like workloads. |
| **predis CAS Lua differs from phpredis behavior** | Adapter `eval()` covers both; CAS test runs against both drivers. |
| **`OpenSwoole\Table` schema enforcement vs Redis advisory schema** | TypeCodec coerces on read — `get()` always returns the same typed shape. Pinned in the parity suite. |
| **Boot-time `Store::make()` before `run()` opens N×workers connections** | Pool lazy-opens in worker context — `make()` only registers the schema, the pool's channel pre-fill triggers on first `acquire()` inside a worker. |
| **BC break: `Store::table($name)` returns raw `OpenSwoole\Table`** | Throws `StoreException` ONLY when backend is Redis; Table backend keeps returning the raw object. Documented as a one-way restriction. |
