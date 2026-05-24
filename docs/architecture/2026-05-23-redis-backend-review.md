# Redis Backend Architecture Review & Hardening Plan

**Date:** 2026-05-23
**Scope:** `src/Store.php`, `src/Counter.php`, `src/Store/*.php`, `src/Session/Handler/StoreSessionHandler.php`, `src/WSRouter.php` — the ~3,500 LOC of v0.2.39/v0.2.40 work that introduced the pluggable Store/Counter backends, Redis/Valkey driver, connection pool, pub/sub primitives, and tiered cache.
**Reviewer:** senior-eng pass.
**Status:** production-ready for documented scope; hardening pass landing in v0.2.41+ to close every gap below.

---

## Architecture

```
Store / Counter (facades — static, BC, the only public surface)
        ↓
StoreBackend (interface)              ← single abstraction
  ├ TableBackend                      (OpenSwoole\Table — ns latency, hot path)
  ├ RedisBackend                      (Hash+SET — cross-node)
  └ TieredBackend(L1=Table, L2=Redis) (ns reads + cross-node + L1 invalidation)
        ↓
RedisDriver (interface) + RedisConnectionPool
  ├ PhpredisDriver (ext-redis)
  └ PredisDriver   (pure PHP)
        ↓
RedisPubSub / RedisStreams (per-worker runners)
```

**What pays off about the layering:** swapping driver, swapping backend, swapping topology — each happens at a single seam. The static facade preserves every existing `Store::make/set/get/incr/count/iterate` call verbatim; the BC discipline is what made the migration tractable across ~3.5k LOC.

---

## Strengths (what's done right)

1. **Connection pool is correctly designed.** Per-worker `OpenSwoole\Coroutine\Channel` of N clients; each op acquires-uses-releases via `with()` (try/finally). Two coroutines never share a socket. Sync-context fallback to a singleton client. This is the single most important thing to get right in a coroutine-aware Redis adapter, and it is.

2. **Per-coroutine subscribe clients.** `PhpredisDriver::subscribe()` spawns fresh `\Redis` clients per coroutine (subscribe + psubscribe) via `buildClient($url)` instead of sharing `$this->c`. Fixes the "Socket has already been bound to another coroutine" class of bug at the source.

3. **PubSubStopException sentinel.** Clean shutdown via publish-to-private-channel + `Atomic` flag + sentinel throw + driver catches it. The phpredis double-catch (`PubSubStopException` AND `RedisException` because phpredis wraps the in-callback throw as a connection error) is exactly the kind of nuance that takes a week to discover and you'd never document if it weren't in the comments. It is.

4. **Lazy + idempotent boot.** `defaultBackend()` is the single choke point; backend builds on first use. `App::run()` reads `ZEALPHP_STORE_BACKEND` BEFORE workers fork. `new Counter(0, null)` is a no-op at construction time — critical because `route/*.php` constructs counters at boot in the master where the hooked `stream_socket_client` would otherwise crash. Subtle but load-bearing.

5. **Tiered backend invalidation has the right semantics.** Origin-tagged messages (`'origin' => $this->originId`) skip self-publishes; writers don't evict their own freshly-written L1 row. Best-effort publish (catch+swallow) keeps writes from failing on a Redis hiccup — peers just stay stale up to `$l1Ttl`. Correct trade-off.

6. **Type safety is real, not cosmetic.** PHPStan L10 with zero `@phpstan-ignore` comments. Defensive scalar narrowing on every Redis return value (`is_scalar`, `(string)`, mismatch → throw). `scalarRow()` helper coerces `mixed` rows into the L1 schema's strict type. Hard to achieve, easy to slip on.

7. **Test coverage at 65% of impl LOC.** ~2,260 lines of tests against ~3,480 lines of implementation, including integration tests against real Valkey, pool concurrency tests, pub/sub roundtrip tests, invalidation tests. Above the bar for OSS framework code at this layer.

---

## Risks (in priority order — every one addressed by the hardening pass below)

### R1. Silent footgun: `mode=tracked` + `ttl` opt
**Symptom:** `Store::make('rate_limits', 1024, [...], ['mode' => 'tracked', 'ttl' => 60])` is accepted; `ttl` is silently ignored because the membership SET would drift if it did.
**Fix:** Throw `StoreException` at `RedisBackend::make()` when both are present in conflict. **(H1)**

### R2. No eager connection validation
**Symptom:** `new RedisConnectionPool('redis://typo:6379')` doesn't fail. The error surfaces deep inside a coroutine handler on first `acquire()`, often hours later under load.
**Fix:** Eager boot-time `Store::ping()` advisory; `App::run()` logs a warning when Redis backend is configured and ping fails. **(H6)**

### R3. Bulk ops aren't pipelined
**Symptom:** `mget`/`mset` round-trip per key sequentially. For 100-key `mget` over local Redis: ~1ms+ wasted vs `MULTI/EXEC`.
**Fix:** Real `pipeline()` use in both drivers + `RedisBackend::mget/mset`. **(H3)**

### R4. No circuit breaker / fallback
**Symptom:** Redis down for 30s → every Store call throws `StoreException` for 30s. No "degrade to Table" option.
**Fix:** Per-table `['on_error' => 'fallback_table' | 'throw']` opt-in circuit breaker via a `CircuitBreakerBackend` decorator. 3-state (closed/open/half-open), configurable thresholds, opt-in only — default behaviour unchanged. **(H4)**

### R5. `TieredBackend::exists()` always hits L2
**Symptom:** Every `exists()` is a full Redis round-trip even when L1 has a fresh entry. Comment acknowledges L1 can lie.
**Fix:** Add `TieredBackend::existsCached()` opt-in for stale-OK fast-path exists. **(H8)**

### R6. `enableInvalidation()` boot-order
**Symptom:** Tables made before `enableInvalidation()` auto-pre-register; tables made after also auto-register. But the doc string doesn't make this fully clear.
**Fix:** Improve docstring; add a test that demonstrates both orderings work. **(documented; no code change needed — the implementation is correct)**

### R7. `Store::get()` BC contract leaks
**Symptom:** Facade rewrites `null` → `false` for legacy callers. New code that uses the backend interface directly sees `null`; through the facade sees `false`.
**Fix:** Add `Store::getStrict()` that returns `null` on miss for new code; facade `Store::get()` keeps the `false` legacy behaviour. **(H2)**

### R8. phpredis pubsub blocks worker without HOOK_ALL
**Symptom:** If anyone disables HOOK_ALL explicitly (uncommon but possible — testing setups, weird PDO interactions), phpredis subscribe deadlocks the worker.
**Fix:** Boot-time assertion in `App::run()`: when phpredis is the resolved driver AND HOOK_ALL is disabled AND there are registered pubsub handlers → log a loud warning. **(H7)**

---

## Architecture nice-to-haves (low-priority, all fixed in this pass)

### N1. No metrics surface
**Symptom:** No `Store::stats()` showing pool acquires, timeouts, reconnects, op counts.
**Fix:** Add `Store::stats()` returning per-backend `array{}` counters. Drivers expose `stats()`. Pool tracks acquires/timeouts/refills. Subscribers track reconnects/dispatched/handler-errors. **(H5)**

### N2. `scanKeys` clear is O(N) DEL, not pipelined
**Symptom:** `clear()` on a 10k-key TTL-mode table is multi-second.
**Fix:** Use `UNLINK` (lazy delete) where supported + pipelined batches of 100. **(H3 alongside the bulk-ops pipelining)**

### N3. `PhpredisDriver::close()` swallows all throwables
**Symptom:** Tolerant on shutdown is fine, but means disconnect bugs never surface.
**Fix:** Log via `elog()` at debug level. **(H9)**

### N4. `RedisPubSub` reconnect backoff has no max-retry cap
**Symptom:** Loops forever; fine for "eventually reconnect", but no escape hatch if Redis is permanently gone.
**Fix:** Add `$maxAttempts` constructor param (default `0` = unlimited, preserving BC); after N attempts emit one final `error_log` and stop. **(H10)**

---

## The hardening pass (H1–H10)

Each item is independently shippable; sequenced for logical commits.

| ID | Title | LOC | Files touched | Tests added |
|---|---|---|---|---|
| **H1** | Throw on `mode=tracked + ttl>0` conflict | ~15 | `RedisBackend.php` | 1 unit |
| **H2** | `Store::getStrict()` — null-on-miss variant | ~20 | `Store.php` | 1 unit |
| **H3** | Pipelined `mget`/`mset` + `UNLINK` in `clear()` | ~120 | `RedisBackend.php`, `RedisDriver.php`, `PhpredisDriver.php`, `PredisDriver.php` | 2 unit + 1 integration |
| **H4** | `CircuitBreakerBackend` decorator (opt-in) | ~250 | new `src/Store/CircuitBreakerBackend.php`, `Store.php` | 4 unit |
| **H5** | `Store::stats()` + pool/pubsub observability | ~100 | `Store.php`, `RedisConnectionPool.php`, `RedisPubSub.php`, `RedisBackend.php` | 1 unit |
| **H6** | Boot-time `Store::ping()` advisory in `App::run()` | ~25 | `App.php`, `Store.php` | 1 unit |
| **H7** | HOOK_ALL boot-time assertion for phpredis subscribers | ~20 | `App.php`, `RedisPubSub.php` | 1 unit |
| **H8** | `TieredBackend::existsCached()` opt-in | ~15 | `TieredBackend.php` | 1 unit |
| **H9** | Log `PhpredisDriver::close()` exceptions at debug | ~5 | `PhpredisDriver.php` | — |
| **H10** | `RedisPubSub` configurable max-retry | ~20 | `RedisPubSub.php` | 1 unit |

**Total:** ~590 LOC of impl + ~250 LOC of tests across 7-9 commits.

---

## Verdict

**Ship-grade architecture, finite hardening surface.** The deferred work (Cluster/Sentinel facade, tiered backend Phase 2 wire-pipelining) is correctly scoped to v0.2.41+. The hardening above brings the risk surface to zero for the documented use cases.

Top three pre-release recommendations folded into the hardening pass:
1. **H1** — throw on `mode=tracked + ttl>0`; silent ignore is the wrong default.
2. **H6** — boot-time `ping()` advisory so misconfigured URLs surface immediately.
3. **Document explicitly** — "no graceful-degradation by default; opt in via `['on_error' => 'fallback_table']`" (the new H4 capability). Users will assume there's a circuit breaker because every other Redis library has one. Making the opt-in visible is critical.

Everything else is post-release polish.
