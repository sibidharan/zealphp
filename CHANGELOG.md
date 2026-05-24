# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

ZealPHP-native FCGI-style worker pool — the v0.3.0 "warm + global scope" CGI bridge, shipped. `cgiMode('pool')` is now the framework default. `cgiMode('fork')` removed entirely. The CGI bridge's per-request cost drops from ~30–50 ms (proc) to ~1–3 ms (pool), matching FPM territory, while preserving full mod_php-style global-scope isolation (unmodified WordPress / Drupal works). Parent OpenSwoole worker dispatches to the pool via `Coroutine\Channel` — thousands of concurrent coroutines fan out across N pre-spawned PHP subprocesses without blocking the event loop. PHP HTTP server + FPM-style worker pool + async dispatch.

### Added

- `ZealPHP\CGI\WorkerPool` — master-side pool manager. Spawns `App::cgiPoolSize()` persistent PHP subprocesses per OpenSwoole worker at first dispatch. Auto-respawns on subprocess death (FPM-equivalent recovery via `proc_get_status['running']`). Recycle after `App::cgiPoolMaxRequests()` requests (FPM `pm.max_requests` parity). Lazy-promoted `Coroutine\Channel` for async dispatch in coroutine context; sync LIFO fallback for tests / non-coroutine code paths.
- `ZealPHP\CGI\IPC` — symmetric length-prefixed JSON framing (`[4-byte BE length][JSON payload]`). Parent <-> subprocess wire protocol; 64 MB per-frame sanity cap.
- `src/pool_worker.php` — persistent subprocess entry. Loops on stdin frames: read request → execute PHP file → write response → reset state → next iteration. uopz overrides for `header()`, `header_remove()`, `setcookie()`, `setrawcookie()`, `http_response_code()`, `headers_list()`, `headers_sent()` mirror `src/cgi_worker.php` exactly so response shapes are identical to `cgiMode('proc')`. Exits cleanly on EOF or after `ZEALPHP_POOL_MAX_REQUESTS` requests.
- `App::cgiPoolSize(?int)` / `App::cgiPoolMaxRequests(?int)` — fluent setters mirroring the `App::superglobals()` precedent. FPM `pm.max_children` + `pm.max_requests` parity respectively. Defaults: 4 / 500.
- `App::$cgi_pool_instance` — per-OpenSwoole-worker singleton `WorkerPool` accessor.
- `CgiMode::Pool = 'pool'` enum case.
- `App::cgiPool(string $path): mixed` — private dispatch method. Builds request frame from `RequestContext`, dispatches via the singleton pool, applies captured headers/cookies/status to `$g->zealphp_response`, returns body or `return_value` per the universal return contract. Same response-shape handling as `cgiSubprocess()` / `cgiFcgi()` — host-side response builder treats all dispatch paths uniformly.
- 16 unit tests pinning the IPC framing + WorkerPool roundtrip (real subprocesses, no mocks): `tests/Unit/CGI/IPCTest`, `tests/Unit/CGI/WorkerPoolTest`. 7 reflection-driven unit tests for `App::cgiPool()` covering simple echo dispatch, header capture, cookie capture, `http_response_code` status flow, array-return universal contract, missing-file 404, GET superglobal reaching the subprocess: `tests/Unit/CGI/CgiPoolDispatchTest`.
- `scripts/bench-fcgi-pool.php` — standalone bench harness for the pool. Defaults: 1000 requests × 4-worker pool. p50/p90/p99 + throughput.

### Changed

- **`App::$cgi_mode` default flipped from `'proc'` to `'pool'`.** Every `processIsolation(true)` app gets the FPM-style worker pool without having to opt in. Apps explicitly setting `App::cgiMode('proc')` keep their existing behavior.
- `CgiMode::coerce()` error message: `'pool', 'proc', or 'fcgi'` (was `'proc', 'fork', or 'fcgi'`).
- `App::registerCgiBackend()` mode validation: accepts `'pool'`/`'proc'`/`'fcgi'`. The PHP-only constraint that applied to `'fork'` now applies to `'pool'` (a PHP-runtime concept; non-`.php` extensions must use `'fcgi'` or `'proc'`).
- Default CGI dispatch arm in `App::include()` / `App::includeFile()`: routes to `cgiPool()` (was `cgiSubprocess()`).
- `template/pages/vs-fpm.php`, `template/pages/legacy-apps.php` — rewritten to document `'pool'` as the recommended path. Startup-cost table replaced fork row with pool row.

### Removed

- **`App::cgiMode('fork')` and the `cgiFork()` method (~215 LOC).** Fork mode forked the warm OpenSwoole worker via `OpenSwoole\Process` (copy-on-write), but the file ran in the fork closure's *function scope* — bare top-level `$x` wasn't visible via `global $x`, so unmodified WordPress / Drupal (`global $wpdb`) needed `'proc'` instead. Pool covers every case fork did + the WordPress global case fork couldn't. Any app explicitly calling `App::cgiMode('fork')` now throws `InvalidArgumentException` at boot; one-character upgrade: `'fork'` → `'pool'`.
- `CgiMode::Fork` enum case.
- `'fork'` arms from all 4 dispatch `match()` statements in `App::include()` / `App::includeFile()`.
- `$GLOBALS['__zeal_fork_return']` / `$GLOBALS['__zeal_fork_return_set']` — fork-mode globals.
- 2 fork-specific tests in `tests/Unit/CgiFcgiDispatchTest`.

## [0.2.40] - 2026-05-23

Production-grade pluggable backends + federated WebSocket fabric. Includes the previously-authored v0.2.39 plumbing (Pluggable Store/Counter backends — Phase 1) plus the production-hardening pass that landed on top of it: cross-host federated rooms, per-room SET optimisation, HMAC-signed pub/sub, stampede-gated cache, Lua-backed atomic transactions, paginated iteration for large Redis tables, Memcached as a fourth backend, aggregated `App::stats()`, and six early v0.3.0 helpers (`App::parallel`, `App::onSignal`, `App::onProcess`, `App::stats`, typed outbound HTTP, federated WS rooms).

Cross-host federation validated end-to-end against two physical hosts sharing a Valkey via wireguard — alice writes, bob reads on a different host, both see the same `Room::members()` roster, `Cache::invalidateTag` drops keys cluster-wide.

PR #83.

### Added

- `ZealPHP\Store\StoreBackend` interface + `TableBackend` (default, wraps `OpenSwoole\Table`) + `RedisBackend` (Redis/Valkey, supports tracked + ttl modes).
- `ZealPHP\Counter\CounterBackend` interface + `AtomicBackend` (default, wraps `OpenSwoole\Atomic`) + `RedisCounterBackend` (Lua-script `compareAndSet` for cross-node atomic CAS).
- `Store::defaultBackend(?string $kind, string|array $conn = [])` and `Counter::defaultBackend(...)` — fluent getter/setter for the process-wide default. Accepts `'table'`/`'atomic'` or `'redis'` plus a connection URL/array (`redis://[:pass@]host:port/db`, `valkey://...` alias, unix sockets supported).
- `Store::TYPE_INT` / `TYPE_FLOAT` / `TYPE_STRING` constants — backend-neutral; existing `OpenSwoole\Table::TYPE_*` keeps working (constants are int-identical).
- `Store::mget()` / `Store::mset()` — bulk read/write on every backend (sequential round-trip; pipelined wire-batching deferred to a future release).
- `Store::iterate()`, `Store::clear()`, `Store::ping()` — surface the full `StoreBackend` contract through the facade.
- `ZealPHP\Store\RedisClient` adapter (preferred = phpredis when `ext-redis` is loaded; pure-PHP predis fallback). The only place either lib's symbols are referenced.
- `ZealPHP\Store\RedisConnectionPool` — per-worker `Coroutine\Channel` of N (default 8) clients. Two coroutines can't share a socket without interleaving RESP frames; this is the framework's defence.
- `ZealPHP\Store\TypeCodec` — backend-neutral row (de)serialization across the Redis byte-string wire.
- `ZEALPHP_STORE_BACKEND` env var bootstraps the default backend in `App::run()` before workers fork. `ZEALPHP_REDIS_URL` feeds the connection URL (default `redis://127.0.0.1:6379`).
- `make valkey-up` / `make valkey-down` test-harness targets boot an isolated `valkey-server` on port 16379 for the unit-test suite.
- CI workflow spins up a `valkey/valkey:7-alpine` service container per PHPUnit matrix job and installs `ext-redis` so both driver paths get coverage.
- `/demo/store-roundtrip` demo route exercises the full Store API through whichever backend is configured; integration test asserts the round-trip on both backends.

### Changed

- All in-tree demo/tutorial code (`route/*.php`, `app.php`, `template/pages/**/*.php`, `README.md`, `docs/websocket.md`) migrated from `OpenSwoole\Table::TYPE_*` to the new `Store::TYPE_*` constants. Old constants still work — `Store::TYPE_INT === OpenSwoole\Table::TYPE_INT` by value, every existing user-app schema keeps passing through unchanged.

### Documentation

- `template/pages/store.php` — new `#backends` section with backend comparison table (latency / cross-node / persistence / use-case) + one-line adoption snippet.
- `README.md` Features table — new "Pluggable Store/Counter" row.
- `docs/superpowers/specs/2026-05-22-store-redis-backend-design.md` — full spec.
- `docs/superpowers/plans/2026-05-23-store-redis-phase-1.md` — task-by-task implementation plan.
- `docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md` — three-layer spike validation (in-process predis-subscribe yields under HOOK_ALL, cross-process two-server pub/sub, cross-host pub/sub via wireguard hop @ 0.53ms median).
- `predis/predis` added under `require-dev` (pure-PHP fallback so the suite stays green where `ext-redis` is absent).
- **Pub/sub + Streams primitives.** `Store::publish($channel, $payload): int` + `App::onPubSub($channelOrPattern, callable)` for fire-and-forget Redis pub/sub. `Store::publishReliable($stream, $payload, ?$maxLen): string` + `App::onReliableMessage($stream, callable, ?$group, $blockMs, $batchSize)` for Streams-backed at-least-once via consumer groups. Patterns (PSUBSCRIBE) supported. Default consumer group name = `'zealphp-' + sha1(canonicalHost())[:8]` so a cluster shares one group.
- `ZealPHP\Store\RedisPubSub` and `ZealPHP\Store\RedisStreams` lifecycle classes — dedicated subscriber coroutine per worker, `go()` per message for concurrent dispatch, bounded exponential reconnect backoff (capped 5 s), sentinel-channel clean shutdown (RedisPubSub) / atomic-flag shutdown (RedisStreams via natural BLOCK timeout). Auto-spawned in `onWorkerStart` when handlers are registered AND backend is Redis.
- `App::onPubSub` / `App::onReliableMessage` / `App::offPubSub` — public registration API.
- `Store::publish` / `Store::publishReliable` — public facade methods (throw `StoreException` on Table backend).
- New demo routes: `/demo/pubsub/publish`, `/demo/pubsub/publish-reliable`, `/demo/pubsub/log` exercise the API end-to-end against the running server.
- Three Phase 3 validation spikes shipped as artifacts: in-process (`scripts/spike-predis-subscribe.php` — predis SUBSCRIBE yields under HOOK_ALL), cross-process (`scripts/spike-crossnode-server.php` — two ZealPHP servers exchange via shared valkey, ~0.3 ms one-way), cross-host (`scripts/spike-crosshost-{publish,subscribe}.php` — subscriber on a remote box via wireguard tunnel, 0.53 ms median end-to-end). Documented in `docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md`.

### Added — production-hardening pass (this release)

- **`Counter` N-1..N-4** — `CounterBackend::setIfAbsent` (atomic SETNX on Redis, fresh-map check on Atomic) so `new Counter(0, 'foo')` no longer clobbers existing per-room/per-user counters. `Counter::incrementBounded(int $by, int $max): ?int` — bounded atomic increment (Lua-server-side on Redis). `Counter::expire(int $seconds): bool` — TTL on counter keys (Redis only; no-op on Atomic). `Counter::mincr(array)` — pipelined batch increment.
- **`Store` S-1/S-2/S-3** — `Store::evalScript($script, $keys, $args)` — atomic Redis Lua execution (the canonical replacement for MULTI/EXEC). `Store::compareAndSet($table, $key, $field, $expected, $new)` — optimistic CAS on a single Store row+column. `Store::iteratePaged($name, $cursor='0', $count=100): {cursor, rows}` — paginated SSCAN/SCAN-MATCH iteration for large Redis tables. Set primitives `Store::sadd/srem/scard/sscanCursor/sdel` exposed for power-user SET workloads (and the framework's own Room layer). `Store::hasSetOps()` guard for backend-portable code.
- **`Cache` C-1/C-2/C-3** — `Cache::getOrCompute()` now elects a single lock-holder via `Counter::compareAndSet` so concurrent misses don't all run `$compute` (stampede protection). Losers wait up to 200ms in 20ms increments (yielded via `usleep` under HOOK_ALL). `Cache::init($maxFiles)` — file-tier eviction cap (oldest-mtime-first beyond the cap) for unbounded-TTL workloads. `Cache::set($k, $v, $ttl, $tags = [])` + `Cache::invalidateTag($tag)` — bulk invalidation via per-tag Redis SET (requires Redis/Tiered backend). New `stats()` counters: `stampede_blocked`, `file_rotations`, `tag_invalidations`.
- **`WSRouter` WS-1/2/3/4/5** — sendToClient default sink now routes through `pushWithBackpressure` (`pushes_dropped_slow_consumer` surfaced for slow consumers; pre-WS-1 only `Room::push` fan-out had the guard). Per-room Redis SET maintained by `Room::join`/`leave`: O(1) `Room::size()` via SCARD, paginated `Room::members()` + new `Room::membersPaged($cursor, $count)`. `WSRouter::setChannelHmacSecret($secret)` — shared HMAC-SHA256 over every `ws:server:*` and `ws:room:*` publish; subscribers verify on receive (mismatch dropped + `hmac_verify_failures_total` bumped). `WSRouter::setClientRateLimit($n, $windowSec)` + `checkClientRate($id)` — per-client sliding-window rate limit. WebSocket close-code constants (RFC 6455 1000–1099 + ZealPHP app range 4001/4002/4003/4013/4029/4040).
- **`App::stats()` X-4** — aggregated framework snapshot adds `cache`, `ws_router`, `backends.{store_kind,counter_kind}` keys. Each subsystem wrapped in `safeStats` so a single subsystem failure (e.g. WSRouter uninitialised at /healthz time) returns `{_error: ...}` instead of crashing the snapshot. Prom-friendly array shape.
- **WSRouter production hardening (foundational)** — `WSRouter::initOptions(ownerCapacity, roomMembersCapacity, slowConsumerBytes)`. `CapacityException extends StoreException` raised by `own()` and `Room::join()` when the cluster-wide tables fill. Per-room rate limit via `WSRouter::setRoomRateLimit($n, $windowSec)`. Server registry table with heartbeat + GC sweep (`SERVER_HEARTBEAT_INTERVAL_MS` / `SERVER_GC_INTERVAL_MS` / `SERVER_STALE_AFTER_SEC`). `WSRouter::onlineCount()` / `onlineByServer()` cluster-wide connection counts. `WSRouter::stats(): Stats` with 14 counters surfaced via snapshot().
- **Memcached backend** — fourth `StoreBackend` + third `CounterBackend`. Wired via `Store::BACKEND_MEMCACHED` / `Counter::BACKEND_MEMCACHED` constants + `StoreBackendKind::Memcached` / `CounterBackendKind::Memcached` enum cases. Store: per-row serialize on `set/get/del/exists/incr/decr/mget/mset` works end-to-end; throws StoreException with a "use Redis for this" message on `iterate`/`count`/`clear`/Set ops/pub-sub (Memcached has no SCAN, SET type, pub/sub, or Lua). Counter: native server-side atomic `increment`/`decrement` (lazy-init via `add+0`), `compareAndSet` via gets/cas, bounded-increment via CAS retry loop. `ZEALPHP_MEMCACHED_SERVERS` env var bootstraps the default backend.
- **Tiered backend (Phase 2 — `TableBackend` L1 + `RedisBackend` L2)** — `ZealPHP\Store\TieredBackend` with bounded `l1_ttl` staleness window + synthetic `__cached_at` per-row column. `enableInvalidation()` enables cross-node L1 eviction via origin-tagged pub/sub on `__l1_invalidate:{table}`. `TieredBackend::existsCached()` — stale-OK opt-in fast path (H8). Optional HMAC-signed invalidation messages (`ZEALPHP_TIERED_INVALIDATION_SECRET`) — C2 hardening defeats the "anyone with Redis write access DoSes the cluster's L1" attack.
- **Production hardening pass (v0.2.41 review)** — 3 critical + 10 medium gaps closed across the Redis stack. `WSRouter` per-fd `conn_id` nonce defeats fd-reuse races (C1). `RedisBackend::make()` rejects `mode='tracked' + ttl>0` at boot (H1). `Store::getStrict()` null-on-miss variant for new code (H2). Pipelined `mhgetall`/`mhsetWithMembership`/`unlink` driver primitives — `mget(100)` → 1 RTT; 10k-key `clear()` → sub-second (H3). Opt-in `CircuitBreakerBackend` decorator with 3-state machine (closed/open/half-open) + sliding-window threshold (H4). `Store::stats()` per-worker pool counters (H5). Boot-time advisories: eager Redis ping + HOOK_ALL+phpredis+subscribers compatibility check (H6+H7). `PhpredisDriver::close()` diagnoseable failure trace via `elog('debug')` (H9). `RedisPubSub::$maxAttempts` bounded reconnect for CI workers (H10). TLS via `rediss://` / `tls://` schemes (C3).
- **Federated WebSocket Rooms (v0.3.0 P1.1, landed early)** — `WSRouter::room($name): Room` — first-class room abstraction on top of v0.2.40 Store + pub/sub fabric. Membership in cluster-wide `ws_room_members` Store table; one PSUBSCRIBE pattern subscriber per worker covers every room; per-worker local-membership cache populated from presence events. API: `$r->join/leave/isMember/size/members/membersPaged/push/onMessage/onPresence`. Cross-host federation validated against two physical ZealPHP instances + shared Valkey.
- **Early v0.3.0 helpers (landed in this release)** — `App::parallel(array $tasks): array` + `App::parallelLimit(array, callable, int $concurrency): array` (P1.4, fork-join + bounded fan-out via `Coroutine\Channel` — `WaitGroup` isn't in OpenSwoole 22.x). `App::onSignal(int $signal, callable $handler, bool $workerOnly = false)` (P1.12, master vs worker scoping). `App::stats(): array` (P1.10 partial; full `/healthz` Middleware + Prometheus exposition queued). `ZealPHP\HTTP::get/post/put/delete/request/all` + `ZealPHP\HTTPResponse` (P1.11, typed outbound HTTP wrapper over `OpenSwoole\Coroutine\Http\Client`). `App::onProcess(string $name, callable $fn, int $workers = 1, bool $coroutine = true)` (P2.1, sidecar long-running process registration, `cli_set_process_title("zealphp:{$name}")`).
- **`Cache::getOrCompute($key, $compute, $ttl)`** — read-through cache helper that also caches `null` via internal sentinel (distinguishes "stored null" from "miss"). Pair with `Cache::init(maxRows: …, ttlSeconds: …)` for bounded growth on the Redis backend.
- **Three-backend Store facade** — `Store::defaultBackend()` accepts `Store::BACKEND_TABLE` / `BACKEND_REDIS` / `BACKEND_TIERED` / `BACKEND_MEMCACHED` (canonical class constants — bare strings work too for BC).
- **Pub/sub WebSocket helper** — `ZealPHP\WSRouter` bundles the cross-server WS routing pattern: `init($serverId?, $sink?)`, `own($clientId, $fd)`, `release($clientId)`, `sendToClient($id, $payload)`, `broadcast($channel, $payload)`. Stores `client_id → server_id` in the cluster-wide `ws_owner` Store table; each server subscribes to its identity channel `ws:server:{ID}`.
- **Cluster-wide WebSocket rooms** — `WSRouter::room('chat:42')->join/leave/push/members/onMessage/onPresence` (federated via Store + pub/sub).
- **Streams `XAUTOCLAIM`** — `RedisClient::xautoclaim` for orphan-message recovery from dead consumers.
- **Redis-backed sessions** — `ZealPHP\Session\Handler\StoreSessionHandler` rides whichever backend `Store::defaultBackend()` is configured with (Table for single-node, Redis for cross-node, Tiered for both).

### Documentation (this release)

- `docs/WSROUTER-PRODUCTION.md` — comprehensive WSRouter production-hardening guide (capacity, heartbeat/GC, backpressure, metrics, rate-limiting, ordering, trust model, auth + reconnect docs).
- `docs/architecture/2026-05-23-redis-backend-review.md` — senior-engineer production-readiness review of the Redis backend surface; risk-by-risk mapping for the 13 hardening fixes.
- `docs/architecture/2026-05-23-v0.3.0-roadmap.md` — v0.3.0 scope plan, marking P1.1/P1.4/P1.10/P1.11/P1.12/P2.1 SHIPPED in this release.
- `docs/superpowers/plans/2026-05-23-auth-system.md` — Phase 1 auth design plan (P1.3, queued for v0.3.0 implementation pass).
- `docs/superpowers/plans/2026-05-23-redis-backend-hardening.md` — the plan that drove the production-hardening pass.
- `scripts/smoke-v0.2.40.php` + `scripts/smoke-federation.php` — cross-host federation smoke scripts (this release's validation harness).

### Changed (this release)

- **Pub/sub API renamed for clarity + symmetric front door** — `App::onPubSub` → `App::subscribe`, `App::offPubSub` → `App::unsubscribe`, `App::onReliableMessage` → `App::subscribeReliable`. NEW symmetric publish side: `App::publish($channel, $payload)` + `App::publishReliable($stream, $payload, ?$maxLen)` — thin delegates to the lower-level `Store::publish` / `Store::publishReliable` so the framework's pub/sub surface reads as one coherent pair ("App publishes, App subscribes"). The old `on*`/`off*` names are kept as BC aliases — existing call sites keep working — but new code should use the verb-form names. All in-tree call sites (route/demo.php, src/WSRouter.php, src/Store.php docblocks) migrated.
- **Sidecar process API renamed** — `App::onProcess` → `App::addProcess`. Mirrors OpenSwoole's native `$server->addProcess()` API; the on*-prefixed name was a misnomer because the method REGISTERS a process, not an event. `App::onProcess` retained as a BC alias.
- **Docblock formatting** — example snippets across `App::onSignal` and `App::addProcess` now use proper triple-backtick `php` fences (renders correctly in phpDocumentor HTML output).
- `Counter::__construct(int $initial = 0, ?string $name = null)` no longer overwrites an existing same-named counter. Previously every `new Counter(0, 'foo')` invocation called `set($name, 0)`, clobbering existing state — hidden footgun for per-room / per-user monotonic counters. Now uses `setIfAbsent` (SETNX on Redis, fresh-map check on Atomic). Explicit `Counter->reset()` keeps the old behaviour available.
- `Cache::stats()` returns 3 new keys: `stampede_blocked`, `file_rotations`, `tag_invalidations`. The shape stays backwards-compatible (no key removals).
- `App::stats()` shape extended with `cache`, `ws_router`, `backends` keys.
- `ZealPHP\Http` → `ZealPHP\HTTP` (class rename to match the `ZealPHP\HTTP\Request` namespace convention). PHP allows the same identifier as both class and namespace; existing `ZealPHP\HTTP\Request` / `Response` keep working. Same for `HttpResponse` → `HTTPResponse`.
- `StoreException` is no longer `final` so `WS\CapacityException` can extend it.
- All in-tree demo/tutorial code (`route/*.php`, `app.php`, `template/pages/**/*.php`, `README.md`, `docs/websocket.md`) migrated from `OpenSwoole\Table::TYPE_*` to the new `Store::TYPE_*` constants. Old constants still work — `Store::TYPE_INT === OpenSwoole\Table::TYPE_INT` by value, every existing user-app schema keeps passing through unchanged.

### Documentation (foundational — pluggable backends)

- `template/pages/store.php` — new `#backends` section with backend comparison table (latency / cross-node / persistence / use-case) + one-line adoption snippet.
- `README.md` Features table — new "Pluggable Store/Counter" row.
- `docs/superpowers/specs/2026-05-22-store-redis-backend-design.md` — full spec.
- `docs/superpowers/plans/2026-05-23-store-redis-phase-1.md` — task-by-task implementation plan.
- `docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md` — three-layer spike validation (in-process predis-subscribe yields under HOOK_ALL, cross-process two-server pub/sub, cross-host pub/sub via wireguard hop @ 0.53ms median).
- `predis/predis` added under `require-dev` (pure-PHP fallback so the suite stays green where `ext-redis` is absent).
- **Pub/sub + Streams primitives.** `Store::publish($channel, $payload): int` + `App::onPubSub($channelOrPattern, callable)` for fire-and-forget Redis pub/sub. `Store::publishReliable($stream, $payload, ?$maxLen): string` + `App::onReliableMessage($stream, callable, ?$group, $blockMs, $batchSize)` for Streams-backed at-least-once via consumer groups. Patterns (PSUBSCRIBE) supported. Default consumer group name = `'zealphp-' + sha1(canonicalHost())[:8]` so a cluster shares one group.
- `ZealPHP\Store\RedisPubSub` and `ZealPHP\Store\RedisStreams` lifecycle classes — dedicated subscriber coroutine per worker, `go()` per message for concurrent dispatch, bounded exponential reconnect backoff (capped 5 s), sentinel-channel clean shutdown (RedisPubSub) / atomic-flag shutdown (RedisStreams via natural BLOCK timeout). Auto-spawned in `onWorkerStart` when handlers are registered AND backend is Redis.
- `App::onPubSub` / `App::onReliableMessage` / `App::offPubSub` — public registration API.
- `Store::publish` / `Store::publishReliable` — public facade methods (throw `StoreException` on Table backend).
- New demo routes: `/demo/pubsub/publish`, `/demo/pubsub/publish-reliable`, `/demo/pubsub/log` exercise the API end-to-end against the running server.
- Three Phase 3 validation spikes shipped as artifacts: in-process (`scripts/spike-predis-subscribe.php` — predis SUBSCRIBE yields under HOOK_ALL), cross-process (`scripts/spike-crossnode-server.php` — two ZealPHP servers exchange via shared valkey, ~0.3 ms one-way), cross-host (`scripts/spike-crosshost-{publish,subscribe}.php` — subscriber on a remote box via wireguard tunnel, 0.53 ms median end-to-end). Documented in `docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md`.

### Out of scope (deferred)

- Pipelined `mget`/`mset` via a driver-shaped Pipeline proxy (basic batching landed; the driver-shaped Pipeline proxy is queued for v0.2.41).
- Redis Cluster / Sentinel topologies as a first-class facade (works today via pre-wired Predis Client + Phase 1 `PredisDriver`; `Store::clusterBackend()` / `sentinelBackend()` ergonomic helpers queued).
- MULTI/EXEC + WATCH via the driver protocol — every documented use case is covered atomically by `Store::evalScript` in one round-trip; will revisit if a workload surfaces that genuinely needs deferred-pipeline shape.

## [0.2.38] - 2026-05-21

Apache + nginx parity release. Two source-diff audits (httpd 2.5.1 + nginx 1.31.1) drove a wave of security fixes, conformance fixes, and new APIs across the HTTP core and the middleware stack. PR #38.

### Security

- **Referer `example.*` no longer matches `example.evil.com`** — `RefererMiddleware` now uses DNS-label-boundary matching (mirrors nginx `dns_wc_head`); previously `str_starts_with($host, "example.")` allowed `example.evil.com` through the allow-list.
- **Symlink-escape via static file serving** closed — `App::includeCheck()` now realpath()-canonicalizes both the file and the document root with boundary-aware containment (`pathWithinRoot()`), refusing symlinks that escape docroot. Non-regular files (FIFO/device/socket) are rejected; ENOTDIR returns 403 (matches Apache "deny rather than assume not found").
- **APR1 (`$apr1$`) htpasswd digest encoding** now matches Apache exactly (the prior native PHP md5 was non-Apache-compatible); pinned against `openssl passwd -apr1` oracle vectors. DES-salt allow-list now matches the real `[./0-9A-Za-z]` alphabet (previously `ctype_alnum` would 401 legitimate DES hashes whose salt contained `.` or `/`).
- **Double-encoded traversal (`%252e%252e`)** rejected with 400 — pre-routing guard now decodes-until-stable before checking for `..`.
- **`mod_expires` no longer stamps `Cache-Control: max-age=N` on error responses** — 4xx/5xx are skipped (Apache parity); past-expiry clamped to `max-age=0`.
- **`enable_static_handler`** is now documented as OpenSwoole-governed (a parity ceiling): the C-level static handler serves `/css`,`/js`,`/img` before PHP, so the PHP-layer normalization/%2F-reject/symlink-containment guards do NOT apply to those prefixes. Deploy guidance added to `STANDARDS.md`.
- **Multi-range DoS cap** added to `RangeMiddleware` and `Response::sendFile()` — bounded at 200 ranges (matches Apache `AP_DEFAULT_MAX_RANGES`, CVE-2011-3192 class).
- **Plaintext htpasswd** now refused with an explicit prefix guard (previously rejected only by `crypt()` happening to fail).
- **Error responses no longer leak handler-set headers** — `App::renderError()` clears prior `Content-Type`/custom headers before emitting an error body; preserves `Location`, `Allow`, `WWW-Authenticate` (Apache `apr_table_clear(headers_out)` parity).

### Added

- **`ZealPHP\HTTP\ConditionalRequest`** — new shared evaluator implementing Apache's `ap_meets_conditions` precedence (If-Match → If-Unmodified-Since → If-None-Match → If-Modified-Since), weak/strong ETag comparison, `*` wildcard, 412 outcomes. Wired into `ETagMiddleware`; If-Match and If-Unmodified-Since are now supported (REST PUT/DELETE optimistic-locking works).
- **`ZealPHP\HTTP\MimeResolver`** — multi-suffix content-type resolver mirroring Apache `mod_mime` `find_ct`: walks all dot-separated suffixes left-to-right accumulating Content-Type + Content-Encoding + Content-Language. `document.html.gz` now correctly emits `Content-Type: text/html` + `Content-Encoding: gzip`. Dotfile rule fixed (`.png` is a hidden file with no type).
- **`ContentEncodingMiddleware`** (Apache `AddEncoding`) and **`ContentLanguageMiddleware`** (Apache `AddLanguage`) — additive, opt-in, driven by the same multi-suffix resolver.
- **`RangeMiddleware`**: `If-Range` HTTP-date support (parsed via `strtotime`, compared to `Last-Modified` with Apache's 1-minute clock-skew rule); invalid spec now invalidates the WHOLE Range header per RFC 7233 §2.1.
- **`Response::sendFile()`**: full multi-range support (206 multipart/byteranges with boundary framing matching `RangeMiddleware`); `If-Modified-Since` future-date guard; `If-Range` entity-tag + HTTP-date.
- **`ConcurrencyLimitMiddleware`**: per-key concurrency limiting (Store-backed, keyed by `App::clientIp()` — proxy-aware), opt-in `dryRun` (observe + `elog`, no enforcement), configurable `rejectStatus` (default 503, nginx parity).
- **`RateLimitMiddleware`**: per-rule `burst=`, `nodelay=`, configurable `rejectStatus`, opt-in `dryRun`. Bucket keying now uses `App::clientIp()` (X-Forwarded-For + trusted proxies) instead of raw `REMOTE_ADDR`.
- **`HostRouterMiddleware`**: trailing-wildcard (`www.*`) and regex (`~^…`) server_name support, full nginx precedence (exact > leading-wc > trailing-wc > regex > default), HTTP/1.1 missing/duplicate/invalid-Host → 400, trailing-dot normalization, correct IPv6 host+port parsing (`[::1]:80`).
- **`HeaderMiddleware`**: nginx `add_header` status-conditional default (per-rule `always` opt-out). See note in Changed.
- **`App::KNOWN_METHODS`** + 501 guard for unrecognised verbs; real `TRACE` handler (echoes request as `message/http` with 413 guard) when `traceEnabled(true)` — note both are defense-in-depth; OpenSwoole's C parser intercepts unknown methods + `TRACE` with 400 before PHP runs (documented in `STANDARDS.md` "OpenSwoole-governed surfaces").
- **`App::$limit_request_fields`** is now actually enforced — `ResponseMiddleware` counts `HTTP_*` keys per request and returns **400** over the limit.
- **`ExpiresMiddleware`**: optional `emitCacheControl` (dual Expires + Cache-Control atomic emission); `base: 'M'` (modification-time) in addition to access-time; suppresses both headers on 4xx/5xx; clamps past expiry to `max-age=0`.

### Changed

- **`HeaderMiddleware` default** is now nginx-style status-conditional (`add_header` applies only to 2xx/3xx unless `always=true` per rule) — **mild BC change**, see the rule's `always` flag to restore the prior unconditional behaviour.
- **`Store::set()`** now catches `OpenSwoole\Exception` on table-full and returns `false` (matches its declared `bool` return); previously threw.
- **`BodySizeLimitMiddleware`** now enforces the cap on chunked / no-Content-Length uploads (Apache `LimitRequestBody` parity); a limit of `0` correctly means **unlimited** (was rejecting all non-empty bodies).
- **`RedirectMiddleware`**: when the redirect target already contains `?`, the incoming request query string is now merged with `&` (Apache `QSA` parity); previously dropped.
- **`CompressionMiddleware`**: `Vary` header now merges (preserves `Vary: Origin` from CORS) instead of replacing; `Accept-Encoding: q=0` correctly refuses compression (RFC 7231 §5.3.4); strong ETags are weakened (`W/` prefix) on compressed responses (RFC 7232 §2.1); `Accept-Ranges` cleared when compression fires.
- **`ETagMiddleware`** uses the new shared `ConditionalRequest` evaluator: full RFC 9110 precondition precedence, weak/strong compare, `*` wildcard, 412 outcomes, GET+HEAD ETag generation.
- **`OPTIONS *`** returns **200** with empty body (Apache parity) instead of 204.
- **HEAD body strip** now applied on error and streaming response paths (was previously normal-response-only).

### Fixed

- All ten bugs (B1–B10) catalogued in `docs/nginx-parity-audit.md`: referer wildcard over-match, rate-limit proxy-IP keying, body-limit `0` semantics, mod_expires error-caching, compression Vary overwrite, error-header leak, regex case-sensitivity, IPv6 host parse, redirect QSA query-drop, fail-open logging.
- ETag path consistency documented (audit gap H7): both paths emit **weak** ETags; `ETagMiddleware` bails on streaming/empty bodies so it never clobbers `sendFile()`'s stat-based ETag; mutually exclusive per response.

### Documentation

- **`docs/apache-parity-audit.md`** — source-diff audit of 10 HTTP-core subsystems against `httpd 2.5.1` with a severity-ranked gap register and per-lane evidence-cited reports.
- **`docs/nginx-parity-audit.md`** — source-diff audit of nginx-parity middleware against `nginx 1.31.1` + deeper Apache edge-case lanes (`mod_rewrite`, `ErrorDocument`, `mod_deflate`, `mod_expires`) with 10 bug findings and structural gap analysis.
- **`STANDARDS.md`** — new **OpenSwoole-governed surfaces** table documenting the parity ceiling (method-line 400-not-501 and the static-handler bypass for `/css,/js,/img`); honest `LimitRequest*` enforcement table marking which knobs are enforced (`Fields`) vs OpenSwoole-governed (`Line`/`FieldSize`).

### Tests

- New unit suites: `ConditionalRequestTest`, `MimeResolverTest`, `RangeMiddlewareConformanceTest`, `UriSecurityConformanceTest`, `IncludeCheckSecurityTest`, `ContentEncodingMiddlewareTest`, `ContentLanguageMiddlewareTest`, `MethodSemanticsTest`. Extended `ETagMiddlewareTest`, `BasicAuthMiddlewareTest`, `BodySizeLimitMiddlewareTest`, `MimeTypeMiddlewareTest`, `HTTP/ResponseTest`, `AppPipelineExtraTest`.
- Mutation-coverage hardening: 154 escaped mutants triaged across 9 middleware (97 killed by targeted assertions, 57 catalogued as provably-equivalent with one-line rationales each); both gates pass (MSI 90%/floor 88, Covered-MSI 93%/floor 92).
- New integration cases: HostRouter validation, conditional-request precedence end-to-end, multi-range sendFile, double-encoded-traversal, encoded-slash rejection on PHP-routed paths, error-header isolation (`ErrorHeaderLeakTest`).

## [0.2.37] - 2026-05-21

Mutation-hardening + conformance-audit release. Raises Infection **covered-MSI from 65% to 95%** (1680/1763 covered mutants killed; the 83 survivors are all provably-equivalent, catalogued in `STANDARDS.md`), fixes a real **HTTP Basic Auth APR1 bug** surfaced by that effort, adds an **Apache httpd core-logic diff + non-support register**, and lands **runnable HTTP fuzz harnesses** (radamsa / gabbi / slowhttptest) wired into CI.

### Fixed

- **`BasicAuthMiddleware` could never verify an Apache `htpasswd -m` (APR1) credential.** The `crypt_apr1_md5()` final to64 encoding assembled its base64 groups in **reversed byte order**, so the computed digest was the byte-reverse of a real `$apr1$` hash and `hash_equals()` always failed. Replaced the strtr/reverse trick with the canonical `apr_md5_encode` interleave (`0,6,12 / 1,7,13 / … / 4,10,5 / 11`, emitted LSB-first). Now verifies credentials from Apache `htpasswd`, `openssl passwd -apr1`, and other standard APR1 producers. Pinned against those independent oracles so it can't regress. (bcrypt / SHA-1 / crypt-DES / SHA-512-crypt paths were already correct.)

### Testing / conformance

- **Mutation score: covered-MSI 65% → 95%** (Infection gate ratcheted `minMsi 55/60` → `88/92`). Every file in the mutation scope (`src/Middleware`, `src/HTTP`, `src/Input`, `src/Diagnostics`) driven to its equivalent-mutant ceiling with real, behaviour-pinning assertions — new/extended unit tests for ~30 classes (Basic auth, Referer, BodySize, Range, RateLimit, Cors, SetEnvIf, HostRouter, Header, RequestHeader, IpAccess, Expires, CacheControl, MimeType, Compression, BodyRewrite, Redirect, Concurrency, Return, ETag, Charset, Scoped, BlockPhpExt, MergeSlashes, IniIsolation, PhpInfo, Response, LazyServerRequest, RequestInput, HTTP factories/exceptions). The 83 surviving mutants are all **provably-equivalent** — `STANDARDS.md` gains an equivalent-mutant register (8 equivalence classes with proofs) explaining why 100% is mathematically unreachable by testing and why the project declines to prop it up with `@infection-ignore` pragmas.
- **Apache httpd core-logic diff** (`STANDARDS.md`) — request-line parsing, header folding, Host enforcement, CL/TE smuggling resolution, the 405/`Allow` path, 404-vs-403, and default request limits, each compared against the Apache httpd 2.5.x source (function cited) and the ZealPHP impl + proving test. Plus the honest **Apache non-support register**: ProxyPass, TLS termination, WebDAV, CGI/FastCGI, full `mod_rewrite`, `.htaccess`/`<Directory>`, content negotiation, SSI, on-the-fly content filters, HTTP cache, LDAP/digest/form/JWT auth, `mod_reqtimeout`, `mod_ratelimit` — each with rationale + substitute.
- **Runnable HTTP fuzz harnesses** (`scripts/fuzz/`, `tests/gabbi/`, `docs/fuzzing.md`, `.github/workflows/fuzz.yml`) — actually executed, not just configured: **Radamsa** 500 wire mutations → 0 hangs / 0 stack-trace leaks; **Gabbi** 7/7 declarative contract cases; **slowhttptest** confirms the documented OpenSwoole read-timeout gap. http-garden differential-vs-Apache documented (Docker-gated). CI runs radamsa + gabbi as gates.

## [0.2.36] - 2026-05-21

HTTP/1.1 method-handling conformance + visible mutation metric. Adds **405 Method Not Allowed** with an `Allow` header (RFC 9110 §15.5.6) so a known resource hit with the wrong method is rejected correctly instead of falling through to 404, surfaces the CI-measured Mutation Score Indicator as a README badge, and extends the conformance battery with symlink-escape and chunked-framing edge cases.

### Added

- **405 Method Not Allowed + `Allow` header (RFC 9110 §15.5.6)** — a request whose URI matches a registered route but whose method does not now returns **405** with an `Allow` header listing the supported methods (`GET` implies `HEAD`; `OPTIONS` always included), instead of a misleading 404. To make this correct for static-style URLs, the three implicit document-root routes (`/`, `/{file}`, `/{dir}/{uri}`) are now scoped to `GET`/`POST` (Apache static-handler parity) — `PUT`/`DELETE`/`PATCH` on a static path now reach the 405 path rather than being silently absorbed. These implicit routes remain user-overridable defaults.
- **Mutation Score Indicator badge** — the README now shows the CI-measured MSI (shields-endpoint badge backed by `.github/badges/mutation.json`); the `Mutation` workflow refreshes it on every `master` push so the displayed score always reflects the latest run.

### Testing / conformance

- **Symlink-escape refusal** (`StaticServingConformanceTest`) — a symlink under the document root pointing outside it (`→ /etc/passwd`) is refused (403/404) and never leaks target content.
- **Chunked-framing edge cases** (`Http1FramingConformanceTest`) — chunk extensions, trailers, and leading-zero chunk sizes are handled without misframing.
- `STANDARDS.md` gains an **advanced-testing roadmap** mapping each tool class to its role: Infection (code mutation), http-garden (parser differential vs Apache/nginx), Radamsa (wire fuzzing), slowhttptest (reactor/slowloris), and Gabbi (declarative contract).

## [0.2.35] - 2026-05-21

HTTP/1.1 + static-serving conformance hardening: enforces the RFC 9112 §3.2 `Host` rule (missing Host on HTTP/1.1 → 400), and adds proven conformance suites for static document-root serving (traversal corpus, dotfile protection, autoindex-off, MIME, conditional 304), Host rules, and response-splitting (`header()` CR/LF/NUL). `STANDARDS.md` gains the request-line/Host/static matrix + an honest OpenSwoole-parser deviation register.

### Added

- **HTTP/1.1 `Host` enforcement (RFC 9112 §3.2)** — an HTTP/1.1 request without a `Host` header is now rejected with **400** (`ResponseMiddleware` guard, before routing); HTTP/1.0 stays exempt. Closes a vhost-confusion / smuggling gap (OpenSwoole previously accepted it as 200).

### Testing / conformance

- **Static document-root serving conformance** (`tests/Integration/StaticServingConformanceTest.php`) — proves the "serve a directory safely" surface: a directory-traversal corpus (encoded / double-encoded / backslash / null-byte) never escapes the document root, dotfiles (`.env`/`.git`/`.htaccess`/`.ssh`) are never served, a bare directory never leaks a listing (autoindex off), and common assets get correct MIME types + conditional 304.
- **HTTP/1.1 `Host`-rule conformance** added to `Http1FramingConformanceTest` (missing-Host → 400, with-Host → 200, HTTP/1.0 exempt).
- **Response-splitting / header-injection conformance** (`tests/Unit/ResponseSplittingConformanceTest.php`) — `header()` refuses CR/LF/NUL (including `Location:` from tainted input), pinning the no-response-splitting guarantee.
- `STANDARDS.md` expanded with the request-line/`Host`/static matrix and the honest OpenSwoole-parser deviation register (`%00` truncation, duplicate-`Host` merge, generic-4xx-not-`431`/`414`, `Expect`/keep-alive as server settings).

## [0.2.34] - 2026-05-21

A standards-conformance + Apache/nginx-parity release. Adds a documented, gated conformance program (`STANDARDS.md`): exhaustive IANA status-code, RFC 6265 cookie, and RFC 9110 §5.6.7 IMF-date suites; a raw-socket HTTP/1.1 framing & request-smuggling proof (RFC 9112 §6–§7); a live directory-traversal proof; plus CI gates — an 80% coverage floor, Infection mutation testing (ratcheted to the measured baseline), and a perf-regression smoke. Ships six new directive middleware (Scoped / RequestHeader / MergeSlashes / BodySizeLimit / Referer / Return), session-format cross-server parity ([#23](https://github.com/sibidharan/zealphp/issues/23)), and the `.php` 404 fix ([#25](https://github.com/sibidharan/zealphp/issues/25)).

### Testing / conformance

- **HTTP/1.1 framing & request-smuggling conformance** (RFC 9112 §6–§7) — a raw-socket suite (`tests/Integration/Http1FramingConformanceTest.php`) that *proves* the smuggling surface is closed: `Content-Length`+`Transfer-Encoding` → 400, duplicate `Content-Length` / bare-LF / invalid chunk size → connection dropped, oversized header block → 400, well-formed chunked → parsed. Results + the two documented leniencies are published in `STANDARDS.md`. (HTTP/2 h2spec is the next conformance step; currently *Documented*, not yet *Exhaustive*.)

### Fixed

- **Nonexistent `.php` URLs now return 404, not 403** ([#25](https://github.com/sibidharan/zealphp/issues/25)). With `ignore_php_ext` on (default), the `*.php` catch-all returned a blanket `403 Forbidden` for every `.php` URL — telling clients "no permission" when the truth was "doesn't exist." It now checks the file on disk (under the document root): an existing `.php` blocked from direct access → **403**, a `.php` URL with no backing file → **404** (Apache parity).

### Added

- **`ScopedMiddleware` (Apache `<Location>` / `<LocationMatch>` / `<FilesMatch>` parity)** — wrap any middleware so it applies only to matching request paths: `ScopedMiddleware::location($inner, '/admin')` (literal URL-path prefix) or `ScopedMiddleware::match($inner, '#^/api/#')` (PCRE). Out of scope the inner middleware is skipped and the request passes straight through; in scope it runs normally (free to short-circuit). The middleware-composition equivalent of Apache's scoped directive containers — e.g. `BasicAuthMiddleware` only under `/admin`.
- **`RequestHeaderMiddleware` (Apache mod_headers `RequestHeader`)** — manipulate inbound request headers before handlers run (`set` / `append` / `add` / `unset`), written into `$g->server` as `HTTP_<NAME>` so `apache_request_headers()` / `getallheaders()` reflect them — the mod_php convention.
- **`MergeSlashesMiddleware` (Apache `MergeSlashes On` / nginx `merge_slashes`)** — collapse runs of consecutive slashes in the request path before routing (`/a//b///c` → `/a/b/c`), an internal rewrite of `$g->server['REQUEST_URI']`; the query string is preserved.
- **`BodySizeLimitMiddleware` (nginx `client_max_body_size` / Apache `LimitRequestBody` / PHP `post_max_size`)** — refuses requests whose `Content-Length` exceeds a configured cap with `413 Payload Too Large`. Accepts a byte count or an nginx-style size string (`'10m'`, `'512k'`, `'1g'`). OpenSwoole's `package_max_length` remains the transport hard cap; this is the configurable app-level limit with the standard 413.
- **`RefererMiddleware` (nginx `valid_referers` / `$invalid_referer`)** — hotlink protection: 403s requests whose `Referer` isn't in the allowed set. Mirrors nginx semantics — `none` (missing), `blocked` (scheme-less), exact host, `*.example.com` / `example.*` wildcards (with optional URI prefix, port ignored), and `~regex`.
- **`ReturnMiddleware` (nginx `return`)** — unconditionally returns a fixed response (status-only, `Location` redirect for 3xx, or a fixed body), like nginx `return` in a `location`. Pair with `ScopedMiddleware` for the `location { return ...; }` shape.

## [0.2.33] - 2026-05-21

Coroutine-safety fix for the Redis session handler — resolves session corruption under concurrent load ([#16](https://github.com/sibidharan/zealphp/issues/16)).

### Fixed

- **`RedisSessionHandler` is now coroutine-safe — fixes session corruption under concurrent load** ([#16](https://github.com/sibidharan/zealphp/issues/16)). The handler held a single `\Redis` connection; sharing one instance across coroutines (the `onWorkerStart` pattern) multiplexed concurrent commands onto the same socket, and phpredis is not coroutine-safe — interleaved request/response frames made `read()` return the wrong/empty session, which `write_close()` then persisted (a 24-key session collapsing to a few keys under a rapid request sweep). The handler now keeps **one connection per coroutine** (stored in the coroutine context, reaped on coroutine end); outside a coroutine it uses a single fallback connection created at construction. Constructor behaviour is unchanged (still connects eagerly to validate config). High-throughput deployments should front this with a connection pool to avoid per-request connection churn. *(Root cause was the shared socket, not the `write_close()` merge; the file-handler default was never affected.)*

## [0.2.32] - 2026-05-21

A second Apache/mod_php parity wave: new built-in overrides (`php_sapi_name`, `filter_input`/`filter_input_array`, `header_register_callback`, `error_log`), `$_SERVER` completeness (`GATEWAY_INTERFACE`/`REQUEST_SCHEME`/`HTTPS`), new directive middleware (`RedirectMiddleware`, `SetEnvIfMiddleware`) and config (`ServerTokens`, `FileETag`, `default_mimetype`) — plus two session/output correctness fixes ([#20](https://github.com/sibidharan/zealphp/issues/20), [#21](https://github.com/sibidharan/zealphp/issues/21)).

### Fixed

- **Void return (`return;`) no longer discards buffered output** ([#20](https://github.com/sibidharan/zealphp/issues/20)). `executeFile()` only treated PHP's `int(1)` (no `return`) as "surface the echoed output"; a bare `return;` yields `null` and fell through to the explicit-return branch, silently dropping all rendered HTML (a common pattern: `echo`/template output followed by `return;`). `$result === null && $output !== ''` is now also treated as the no-explicit-return case — consistent with the universal return contract (`null` = no body override).
- **`unset($g->session['key'])` now persists through a custom session handler** ([#21](https://github.com/sibidharan/zealphp/issues/21)). `zeal_session_write_close()`'s concurrent-race merge (`array_merge(stored, current)`) resurrected keys that were `unset()` during the request — a merge can't tell "never existed here" from "deleted here". The session's keys are now snapshotted at load (`RequestContext::$session_loaded_keys`); the merge drops keys that were loaded but are now absent (in-request deletions) while preserving keys never loaded here (concurrent adds). Apache `$_SESSION` unset parity. Only affected custom `SessionHandlerInterface` implementations (e.g. Redis); the file-handler default already wrote the live array directly.

### Added

- **Apache `ServerTokens` parity (`App::serverTokens()` / `App::$server_tokens`)** — controls the `X-Powered-By` response header: `'Full'` (default) → `ZealPHP + OpenSwoole`; `'Prod'`/`'Major'`/`'Minor'`/`'Min'`/`'OS'` → `ZealPHP`; `'None'`/`''` → header omitted (info-leak hardening). `App::poweredByHeader()` resolves the value at the emission boundary. Non-breaking default.
- **`RedirectMiddleware` (Apache mod_alias `Redirect` / `RedirectMatch`)** — declarative URL redirects: prefix (`/old` → `/new`, remainder appended) and regex (with `$n` backreferences). First match short-circuits with a `Location` redirect; query string preserved; default status 302.
- **`SetEnvIfMiddleware` (Apache mod_setenvif `SetEnvIf` / `BrowserMatch`)** — sets request env vars into `$g->server` (mod_php `$_SERVER`) when a request attribute matches a regex. Apache special attributes (`Remote_Addr`, `Request_Method`, `Request_URI`, `Request_Protocol`, …) plus any header name (`User-Agent` = `BrowserMatch`).
- **Apache `FileETag` parity (`App::fileETag()` / `App::$file_etag`)** — set `false` for `FileETag None`: `ETagMiddleware` then emits no `ETag` and never returns 304. Default true.

- **`error_log()` override** — Apache/mod_php parity. Native `error_log()` under the CLI SAPI writes to stderr / the php.ini `error_log` path; ZealPHP routes `message_type` 0 (system logger) and 4 (SAPI) into the framework's async log (`debug.log`, falling back to stderr when logging is off) so legacy `error_log()` calls land with the rest of the app's diagnostics. `message_type` 3 (append to file) is honored verbatim; `message_type` 1 (email) is unsupported under the coroutine runtime and returns `false`. As part of this, `log_write()`'s three last-resort fallbacks now write to stderr directly instead of `error_log()` (which is now overridden — avoids a recursion loop).
- **`default_mimetype` parity (`App::$default_mimetype` / `App::defaultMimeType()`)** — `CharsetMiddleware` now applies a default `Content-Type` (mod_php's `text/html`, configurable; `''` to disable) to responses that don't set one, before appending the charset. Apache `DefaultType` / PHP `default_mimetype` parity. Opt-in via the middleware, consistent with the other Apache-directive middleware.

- **`php_sapi_name()` override + `App::sapiName(?string)`** — Apache/mod_php parity for SAPI identity. Under the CLI SAPI `php_sapi_name()` natively returns `"cli"`, which legacy apps branch on to disable web-only behavior. Opt in with `App::sapiName('apache2handler')` (or `'fpm-fcgi'`) during boot and the override reports that value so such code takes its web path. Default (`App::$sapi_name === null`) returns the real `PHP_SAPI` — **zero behavior change** unless configured. The `PHP_SAPI` *constant* still reads `"cli"` (uopz cannot redefine it — documented limitation).
- **`header_register_callback()` override** — Apache/mod_php parity. Native PHP fires the callback when the SAPI is about to send headers, which never happens the normal way under OpenSwoole. ZealPHP stores it per-request (coroutine-safe, in `$g->memo`) and invokes it once just before the buffered response headers flush, so `header()` calls inside the callback still land. Last registration wins (matches native's single-callback model). Fires for buffered responses; streaming/SSE paths flush eagerly and are intentionally excluded, consistent with the framework's buffered-vs-streaming split.
- **`$_SERVER` mod_php-parity keys** — the request `$_SERVER` / `$g->server` now includes `GATEWAY_INTERFACE` (`CGI/1.1`), `REQUEST_SCHEME` (`http`/`https`), and `HTTPS` (`on` under TLS only, absent on plain HTTP) — keys mod_php always populates that OpenSwoole's `$request->server` does not. Scheme is derived from a direct `HTTPS=on`, an `X-Forwarded-Proto: https` (behind a proxy), or `SERVER_PORT` 443, mirroring the session-cookie secure detection. (`REQUEST_TIME`, `REQUEST_TIME_FLOAT`, `SERVER_PROTOCOL`, `REMOTE_PORT`, `SERVER_PORT` were already provided by OpenSwoole.)
- **`filter_input()` / `filter_input_array()` overrides** — Apache/mod_php parity for input filtering. Native `filter_input()` reads PHP's internal SAPI request tables, which OpenSwoole never populates, so legacy code using `INPUT_GET` / `INPUT_POST` / `INPUT_COOKIE` / `INPUT_SERVER` / `INPUT_ENV` silently received `null`. The overrides resolve the value from `RequestContext` (`$g`) and apply the requested filter via the pure, unit-tested `ZealPHP\Input\RequestInput` helper. Purely additive (CLI returned `null` before) — no breaking change. Part of the Apache/mod_php parity effort (design: `docs/superpowers/specs/2026-05-21-phpinfo-override-and-modphp-parity-design.md`).

## [0.2.31] - 2026-05-21

Apache/mod_php parity continues — `phpinfo()` now renders real HTML — alongside two parity bug fixes ([#18](https://github.com/sibidharan/zealphp/issues/18) DOCUMENT_ROOT, [#19](https://github.com/sibidharan/zealphp/issues/19) session-ID regeneration) and the test-coverage push to ~80% combined.

### Added

- **`phpinfo()` now renders a self-contained styled HTML document** (Apache + mod_php parity) instead of the CLI SAPI's plain-text `key => value` dump. Implemented via the new `ZealPHP\Diagnostics\PhpInfo` renderer and a uopz override of `phpinfo()`; honors the `INFO_*` flag bitmask, escapes every emitted value, and reports `Server API: ZealPHP (OpenSwoole <ver>)`. Module-specific detail is captured once per worker at boot (before the override installs) to surface extension rows `ini_get_all()` can't reach, without recursing into the override. No gating — matches mod_php, so **do not expose `/phpinfo` in production**. First step of the broader Apache/mod_php parity effort (design: `docs/superpowers/specs/2026-05-21-phpinfo-override-and-modphp-parity-design.md`).
- **`App::onWorkerStop(callable $fn)`** — register a per-worker shutdown hook, the mirror of `App::onWorkerStart()`. Runs inside the worker process when it exits (max_request recycle, graceful shutdown, or reload), *before* the process terminates. Unlike `register_shutdown_function`, it fires on OpenSwoole's signal-driven worker stop — the reliable place to flush per-worker state (counters, buffered I/O, coverage dumps). Invoked as `$fn($server, $workerId)`; a throwing hook is caught + logged so it can't abort worker teardown.

### Fixed

- **API routes no longer clobber `$_SERVER['DOCUMENT_ROOT']`** ([#18](https://github.com/sibidharan/zealphp/issues/18)). `ZealAPI::processApi()` overwrote `DOCUMENT_ROOT` to `<cwd>/api` for module routes, so handlers that include files relative to `DOCUMENT_ROOT` (the mod_php convention, e.g. `require $_SERVER['DOCUMENT_ROOT'].'/src/...'`) resolved to a path under `/api` and 500'd. Apache keeps `DOCUMENT_ROOT` at the web root regardless of which script runs; ZealPHP now does too — it resolves to `App::resolveDocumentRoot()`, with `SCRIPT_NAME`/`PHP_SELF` rooted at the URL (`/api/<module>/<request>.php`) and `SCRIPT_FILENAME` the real handler file. Pinned by `tests/Unit/ZealApiDocumentRootTest.php`.
- **`session_regenerate_id()` is now custom-handler-aware** ([#19](https://github.com/sibidharan/zealphp/issues/19)). It previously only `rename()`d the on-disk `sess_<id>` file, ignoring a registered `SessionHandlerInterface`. With a Redis/Valkey (phpredis) handler the regenerated ID therefore pointed at an empty session and no `Set-Cookie` was emitted — so OAuth callbacks that regenerate post-login stranded the auth fields (`sub`/`tokens`/`profile`/`username`) under an ID the client never received, and they vanished on the next request. Regeneration now migrates the live session data to the new ID via the handler (and destroys the old ID when `$delete_old_session` is true), and emits the new-ID cookie gated exactly like `zeal_session_start()` (`App::$session_lifecycle` + `use_cookies` + writable response, so the Symfony bridge / manual-cookie apps aren't raced). Pinned by `tests/Unit/SessionRegenerateIdHandlerTest.php`.

### Testing / coverage

- **Massive test-coverage expansion: ~29% → ~80% combined line coverage.** Added ~600 regression tests across the previously-untested surface — HTTP wrappers (Response/Request/LazyServerRequest), every middleware, the session layer (handlers, managers, `zeal_session_*`), `utils.php` globals, `RequestContext`, `ZealAPI`/`REST`, `IOStreamWrapper`, `cgi_worker.php`, the file-execution family, the in-process `ResponseMiddleware` pipeline, and App.php static helpers (CLI arg parsing, status emission, route registration, error rendering) — plus `tests/Integration/WebSocketTest.php`, real assertion-based coverage of all six `route/ws.php` endpoints over a coroutine WS client (the `onOpen`/`onMessage`/`onClose` dispatch closures, previously uncovered).
- **Server-process coverage merge** (`scripts/coverage_full.sh` + `scripts/merge_coverage.php`): instruments the live OpenSwoole server (gated `ZEALPHP_COVERAGE_DIR` hook in `app.php`, dumping on `App::onWorkerStop`) so the integration suite's exercise of the event loop — routing, middleware, session managers, WebSocket — counts toward coverage, not just the in-process unit tests. CI now uploads the merged unit+integration clover to Codecov. Genuinely-untestable fork/async helpers (`coprocess`/`coproc`, the `log_sink_for` consumer, network clients, the CGI subprocess) carry justified `@codeCoverageIgnore` markers so the figure reflects the testable surface.

## [0.2.30] - 2026-05-20

Closes the rest of [issue #17](https://github.com/sibidharan/zealphp/issues/17) (GURU PRASANTH M, v0.2.29): the proc-mode CGI autoloader gap, the CLI `restart`/`start -d` output races, and — the headline — full superglobal aliasing so `$g->get` is genuinely the same array as `$_GET` in superglobals mode (not a per-request snapshot).

### Fixed

- **`$g->get` / `$g->post` / `$g->cookie` / `$g->files` / `$g->server` / `$g->request` are now LIVE ALIASES of the superglobals in `superglobals(true)` mode** ([#17](https://github.com/sibidharan/zealphp/issues/17)). Previously the declared `public array $get = []` property shadowed `RequestContext::__get()`, so the per-request handler populated `$g->get` and `$GLOBALS['_GET']` as **separate arrays** — mutating `$_GET` after dispatch wasn't visible through `$g->get` (and vice versa). The handler now `unset()`s those declared slots after populating the `$GLOBALS['_*']` family, so reads AND writes route through the `__get`/`__set` proxy by reference — the same live-alias mechanism `$g->session` has had since v0.2.27. In superglobals mode the two names are now genuinely the same array. (Coroutine mode is unchanged: superglobals stay unpopulated, `$g->X` is the per-coroutine source of truth.) Pinned by `testGetAliasMutationCrosses` in `tests/Integration/SuperglobalsParityTest.php`. *(Supersedes the initial "working as designed" triage on the issue — the reporter was right that separate arrays are wrong for superglobals mode.)*
- **Proc-mode CGI worker now loads the Composer autoloader** ([#17](https://github.com/sibidharan/zealphp/issues/17)). `src/cgi_worker.php` (the `proc_open` subprocess used by `cgiMode('proc')`) never required `vendor/autoload.php`, so `\ZealPHP\App` and other project classes were undefined inside legacy `public/*.php` files dispatched through it. Fork mode (`cgiMode('fork')`) already had them via copy-on-write inheritance of the warm worker — this closes the inconsistency. Resolves both repo-root and installed-as-dependency vendor layouts; missing autoloader stays non-fatal (unmodified WordPress/Drupal ships its own bootstrap). Guarded by `tests/Unit/CgiWorkerAutoloadTest.php`.
- **CLI `restart` no longer prints its confirmation over the next shell prompt** ([#17](https://github.com/sibidharan/zealphp/issues/17)). The watcher was a detached child that outlived the terminal-attached parent, which daemonized and exited first — so `Restarted (pid X, port Y)` landed after the prompt returned. The fork is now flipped: the terminal-attached process polls for the new daemon's PID file and prints the confirmation last, while the child boots the (self-daemonizing) server.

### Added

- **`start -d` / `--daemonize` now prints a confirmation** ([#17](https://github.com/sibidharan/zealphp/issues/17)): `Started ZealPHP in detached mode (pid X, port Y).` Previously detached starts returned silently. Shares the same `forkStartupReporter()` path as the `restart` fix above.

### Tests

- 400 unit + 157 integration tests pass (new `testGetAliasMutationCrosses`). PHPStan level 10 clean.

## [0.2.29] - 2026-05-20

Adds a second CGI bridge backend — `App::cgiMode('fork')` — a warm `OpenSwoole\Process` fork that's ~5× faster than the default `proc_open` path while preserving full per-request isolation. Opt-in; `'proc'` stays the default, so unmodified WordPress/Drupal see no change.

### Added

- **`App::cgiMode('fork')`** — a second CGI bridge backend. Where the default `'proc'` mode `proc_open`s a cold PHP interpreter per request (~30–50 ms — true global scope, what unmodified WordPress/Drupal need), `'fork'` mode forks the already-booted worker via `OpenSwoole\Process` (copy-on-write). The interpreter, classmap, and opcache are inherited — no exec, no PHP startup, no autoload. Measured **~5× faster** on a trivial probe (814 vs 160 req/s, 24.6 ms vs 124 ms; `ab -n 3000 -c 20`, Intel i9-14900K). Full per-request isolation is preserved: `define()`, classes, `ini_set()`, and even `die()`/`exit()` die with the child, never the worker. **Trade-off:** the file runs in the fork closure's function scope, so a bare top-level `$x` isn't visible via `global $x` — `cgiMode('fork')` targets "modernised legacy" apps that read request state through superglobals; unmodified `global $wpdb`-style code stays on `'proc'`. IPC uses 4-byte length-prefixed framing (`OpenSwoole\Process::read()` blocks past EOF rather than returning `""`). Configured like the other lifecycle knobs: `App::cgiMode('proc'|'fork')` before `App::init()`; no-arg returns the current value; unknown values throw `InvalidArgumentException`.
- **`tests/Unit/AppConfigurablesTest.php`** — 3 new cases pinning `cgiMode` defaults to `'proc'`, round-trips the setter, and rejects unknown modes.

### Documentation

- **`/vs-fpm`** — refreshed the measured benchmark from 4 ways to **5 ways** (added fork CGI between Mixed-mode and proc CGI), re-run on one Intel i9-14900K box (`scripts/bench_vs_fpm.sh`). Added the "PHP interpreter lifecycle" fork row, reframed the v0.3.0 roadmap box around `cgiMode('fork')` being available now, and corrected the cost-recovery prose (proc 160 → fork 814 → in-process 21,964 req/s).
- **`scripts/bench_vs_fpm.sh`** — added the `FORK_CGI_URL` knob and a fork-CGI benchmark section so the 5-way comparison is reproducible.

## [0.2.28] - 2026-05-19

Documentation + tooling follow-up to v0.2.27. No framework behaviour change — ships the canonical dual-runtime compat shim as a package artifact and grounds the PHP-FPM comparison in real measured numbers.

### Added

- **`compat/g.php`** — the canonical dual-runtime `$g` compat shim, now shipped inside the package. Lets one source tree run on both Apache+mod_php (no ZealPHP loaded → `$g` built from `&$_GET` references) and ZealPHP (any mode → `RequestContext::instance()`). Standalone, dependency-free, includable without the autoloader: `require_once 'vendor/sibidharan/zealphp/compat/g.php'`. Formalises the pattern SNA Labs ran in production (previously a hand-rolled `load.php` snippet). It is **included by the app, not loaded by the framework** — by design, since on Apache the framework isn't present at all.
- **`tests/Unit/CompatShimDriftTest.php`** — 4 tests guarding the shim against drift: canonical file exists, Apache-branch keys match the expected request-data surface, the LAMP scaffold copy matches canonical, and every shim key is an array-typed declared property on `RequestContext`.

### Documentation

- **`/vs-fpm`** — replaced illustrative "shape" numbers with a **real measured 4-way benchmark** (Apache+mod_php, ZealPHP coroutine, Mixed-mode, legacy CGI) on one machine, same trivial `public/probe.php`, `ab -n 3000 -c 20`. Honest findings: the CGI bridge's `proc_open` fork is the entire 179 req/s story (turning `processIsolation(false)` recovers ~71×), and Apache mod_php (46k) beats ZealPHP on trivial legacy-file echo — ZealPHP's win is native routes / coroutine I/O / WebSocket / no separate web server. Added the "Mixed-mode = an FPM pool, minus the operations" section and the v0.3.0 built-in CGI worker pool roadmap.
- **`/performance`** — added "Legacy-file serving — Apache vs ZealPHP lifecycle modes" mirroring the `/vs-fpm` measured table (kept in lock-step via `SYNC:` comments in both files).
- **`/legacy-apps#dual-runtime`** — new section documenting the dual-runtime pattern, the compat shim, and *why it can't be a framework feature* (the Apache path has no autoloader).
- **`/case-studies/sna-labs`** — reframed the `$g` bridge from "a workaround" to the first-class, shipped, drift-guarded dual-runtime Apache-parity bridge.
- **`examples/lamp-scaffold/`** — bootstrap shim points at the canonical `compat/g.php`; README documents both portability styles (`$g->X` vs raw superglobals).

### Tests

- 395 unit (4 new compat-shim drift tests) + 156 integration tests pass. PHPStan level 10 clean.

## [0.2.27] - 2026-05-19

Restore v0.1.x "superglobals just work" behaviour that was silently dropped during the December 2024 declared-property refactor (commits `327e180` + `900c18a`). Under `App::superglobals(true)` the framework was populating `$g->get` / `$g->session` etc. but **NOT** `$_GET` / `$_SESSION` — making the flag's name misleading and forcing every dual-mode app (notably labs) to maintain a compat shim that v0.1.x didn't need. v0.2.27 closes the loop in two places: per-request superglobal population in the request handler, and a `$g->session ↔ $_SESSION` alias via `__get`/`__set` proxy so direct `$_SESSION['k']=v` writes are visible through `$g->session` immediately (the v0.2.22 "mirror at call-points" approach couldn't catch writes between `session_*()` calls because typed declared properties bypass magic methods).

### Fixed

- **`$_GET` / `$_POST` / `$_COOKIE` / `$_FILES` / `$_SERVER` / `$_REQUEST` populated per request in `superglobals(true)` mode** (`src/App.php:3565+`). The OpenSwoole HTTP server doesn't auto-populate these (only CGI/SAPI does); v0.1.x's `G::init()` aliased them via `$_GET = &$context['get']` but the December 2024 refactor to declared properties on `RequestContext` dropped the bridge. The new block writes `$GLOBALS['_GET']`, `$GLOBALS['_POST']`, etc. from the same source data the framework already populates on `$g->get`. Race-safe under the documented `superglobals(true) + enableCoroutine(false)` pairing; the unsafe combination warning at boot already covers `superglobals + coroutines`.
- **`$g->session` and `$_SESSION` are now the same array** in `superglobals(true)` mode. `SessionManager::__invoke()` calls `unset($g->session)` after `session_start()`, making the declared typed property "uninitialised" so reads/writes route through `RequestContext::__get()` / `__set()` which proxy to `$GLOBALS['_SESSION']`. Mutations through either name are visible through the other immediately — no more "one request behind" drift from v0.2.22's mirror-at-call-points approach. Reference assignment (`$g->session = &$_SESSION`) doesn't work on overloaded objects in PHP; the `unset()` + magic-method approach is the workaround.
- **`RequestContext::__set` symmetric superglobal-key mapping** (`src/RequestContext.php:155-170`). The pre-v0.2.27 `__set` wrote `$g->session = $newArray` to `$GLOBALS['session']` (creating a useless top-level global) while `__get` correctly returned `$GLOBALS['_SESSION']`. Now both directions map the same seven names (`get`, `post`, `cookie`, `files`, `server`, `request`, `env`, `session`) → `$GLOBALS['_' . strtoupper($key)]`.
- **`RequestContext::__get` empty-array fallback** (`src/RequestContext.php:113-122`). Initialised missing superglobal slots to `null` — meant `$g->session['x'] = 'y'` would fatal-error on null array access if called before any `session_*()`. Now initialises to `[]`, matching Apache mod_php behaviour where superglobals are always arrays once populated.

### Added

- **`examples/lamp-scaffold/`** — the new home for the "vanilla PHP runs on both servers" pattern (`bootstrap/g.php` compat shim, `apache/vhost.conf.example`, README walking through both portability styles).
- **`examples/lamp-scaffold/public/classic-php.php`** — demonstrates pure `$_GET` / `$_SESSION` / `$_SERVER` use, no `$g`, no bootstrap. Runs unchanged on Apache mod_php AND ZealPHP Mixed-mode (`superglobals(true) + processIsolation(false)`) thanks to this release.
- **`tests/Integration/SuperglobalsParityTest.php`** + **`tests/fixtures/mixed_mode_server.php`** — 9 integration tests pinning the Mixed-mode contract: `$_GET` populated from query string, `$_SERVER` keys present, `$_REQUEST = $_GET + $_POST`, `$g->get == $_GET`, `$_SESSION ↔ $g->session` cross-writes, session counter persists across requests, POST body parsing, `session_destroy` clears both names, `session_unset` clears data but keeps id. First test in the codebase that spawns its own dedicated mixed-mode server via `proc_open` array form — pattern reusable for future lifecycle-mode coverage.

### Documentation

- **`/vs-fpm`** — corrects the misleading "CGI bridge cost is same order of magnitude as FPM" framing. Honest accounting: FPM is ~1–3 ms (FastCGI handshake to a long-lived warm worker), Apache mod_php is ~0 ms (PHP loaded in-process), our current CGI bridge is ~30–50 ms (`proc_open` spawns a fresh interpreter per request). Documents the **v0.3.0 roadmap fix**: a built-in persistent CGI worker pool that holds PHP interpreters warm between requests and recycles them after N requests (the FPM `pm.max_requests` trick) — expected to bring legacy-mode performance to FPM parity.

### Changed (breaking)

- **Unsafe lifecycle combinations now throw at `App::run()` boot instead of emitting a warning.** Two configurations race `$_GET`/`$_POST`/`$_SESSION` across coroutines and have no legitimate use case:
  - `App::superglobals(true) + App::enableCoroutine(true)` — concurrent coroutines clobber process-wide superglobals.
  - `App::superglobals(true) + App::hookAll(non-zero)` — hooked I/O can yield mid-request, exposing process-wide superglobal mutations to other coroutines.
  Pre-v0.2.27 these emitted a `[lifecycle]` warning to `debug.log` but didn't refuse; in practice the warning was invisible to anyone not actively reading the debug log. v0.2.27 fails loud at boot with a `RuntimeException` pointing to `/coroutines#lifecycle-modes`. The supported lifecycle matrix is unchanged — only the enforcement got stricter.

### Backwards compatibility

- **`superglobals(false)` (coroutine mode):** unchanged. Superglobals are intentionally not populated (process-wide writes would race across coroutines). All existing coroutine-mode code keeps working.
- **`superglobals(true)`:** newly populated PHP superglobals are an addition, not a removal. Code that read `$g->get` keeps working; code that read `$_GET` (previously empty) now works too. The `$g->session` alias is a fix to an existing drift bug, not a behaviour change for any code that was already using one consistent name.
- **Mirror code in `zeal_session_*`** (v0.2.22) is now technically redundant in superglobals mode (both names point at the same array) but kept in place as defense-in-depth and to preserve the existing API contract.
- **Apps deliberately running an unsafe lifecycle combination (e.g., for security audits)** will now refuse to boot. The supported mode matrix at `/coroutines#lifecycle-modes` covers every safe configuration. Audit tooling that needs the unsafe path can fork and remove the throw temporarily.

### Tests

- 9 new integration tests in `SuperglobalsParityTest` + 6 new unit tests in `AppConfigurablesTest` pinning the lifecycle-refusal contract (3 unsafe-combo throws + 3 safe-combo non-throws).
- Full suite: 391 unit + 156 integration tests pass. PHPStan level 10 clean.

### Known issues

- **Symfony sessions under `superglobals(false)` coroutine mode are not concurrency-safe (zealphp-symfony bridge).** Sessions round-trip correctly request-to-request in sequential / low-concurrency operation, but concurrent requests carrying *different* `PHPSESSID`s can cross-contaminate (request A observing request B's session). Root cause is architectural, not session-specific: Symfony's container services (`AbstractSessionListener`, security token storage, etc.) are per-worker singletons booted once per worker, and they are not coroutine-aware — when OpenSwoole interleaves coroutines on one worker, those shared singletons race. ZealPHP's own per-coroutine `RequestContext` (`$g`) isolation is correct; the leak is in Symfony's shared service state. **Mitigation until a coroutine-aware container lands:** run the bridge with `App::enableCoroutine(false)` (one request at a time per worker; scale via worker count, FPM-style) — or use Mixed-mode `superglobals(true) + processIsolation(false)` (this release), where native `$_SESSION` is the canonical store and the same per-worker-serialisation applies. Coroutine-per-request concurrency for stateful Symfony apps is tracked for a future release.

## [0.2.26] - 2026-05-19

Closes [issue #15](https://github.com/sibidharan/zealphp/issues/15): v0.2.25's blanket `allowed_classes => false` on session-unserialize converted any `stdClass` (the default `json_decode()` shape) into `__PHP_Incomplete_Class`, breaking real apps that stash OAuth token responses or API profile payloads in `$_SESSION`. The hardening was too tight.

### Fixed

- **Narrowly whitelist `stdClass` in all session `unserialize()` calls** in `src/Session/utils.php` — 4 sites (`php_session_decode_to_array()` array-format branch, `php_session_decode_to_array()` pipe-format branch, `zeal_session_abort()`, `zeal_session_decode()`). `['allowed_classes' => false]` → `['allowed_classes' => ['stdClass']]`. The c43da63 object-injection hardening is preserved for every other class — `stdClass` has zero methods (no `__wakeup`, no `__destruct`, no `__get`/`__set`/`__call`), so there is no gadget to chain. `DateTime` and other classes with magic methods on unserialize remain deliberately excluded; adding any class to the whitelist requires a per-class security review per the docblock at `php_session_decode_to_array()`.

### Tests

- Two new tests pin `stdClass` round-trips through both decoder branches (top-level `serialize()` form and pipe-format `key|value;` form): `testStdClassRoundTripsInPhpSerializeBranch`, `testStdClassRoundTripsInPhpHandlerBranch`.
- Two existing tests rewritten to use a custom non-whitelisted fixture class (`PhpSessionDecodeTestNonWhitelistedFake`) and pin the security property as "no live instance of a non-whitelisted class": `testNonWhitelistedClassIsBlockedInPhpSerializeBranch`, `testNonWhitelistedClassIsBlockedInPhpHandlerBranch`.

### Backwards compatibility

- Apps that don't store objects in sessions: identical behaviour to v0.2.25.
- Apps that stored `stdClass` (issue #15): now round-trip correctly (was broken in v0.2.25).
- Apps that relied on `__PHP_Incomplete_Class` placeholders for non-`stdClass` objects: unchanged — those classes are still refused.

PHPStan level 10 clean. 385 unit + 147 integration tests pass.

## [0.2.25] - 2026-05-19

Closes [issue #13](https://github.com/sibidharan/zealphp/issues/13) with two complementary fixes — one at the symptom layer (`ZealAPI::isAuthenticated()` hardcoded to `false`), one at the underlying-cause layer (session data loss from missing handler-side persistence + concurrent-write races).

### Added — auth hooks

- **`App::authChecker(?callable)`** + backing `App::$auth_checker` static. Consulted by `ZealAPI::isAuthenticated()`. Signature: `fn(): bool`. Apps register a closure that decides whether the current request is authenticated by reading `$_SESSION`, `$g->session`, or their own auth state. Default `null` → `ZealAPI::isAuthenticated()` returns `false` (safe fail-closed).
- **`App::adminChecker(?callable)`** + backing `App::$admin_checker`. Same shape; consulted by `ZealAPI::isAdmin()`.
- **`App::usernameProvider(?callable)`** + backing `App::$username_provider`. Signature: `fn(): ?string`; consulted by `ZealAPI::getUsername()`.

Closes the `return false;` stub gap from PR #10 that broke every endpoint guarded by `requirePostAuth()` — even for logged-in users. New `tests/Unit/ZealApiAuthHooksTest.php` pins 15 cases: defaults, callback round-trips, type coercion edge cases, independence of the three hooks, setter introspection.

### Fixed — session handler write/destroy + concurrent merge (PR #14)

- **`zeal_session_write_close()` and `zeal_session_destroy()` now delegate to `\SessionHandlerInterface`** when one is registered in `$g->session_params['handler']`. Previously hardcoded `file_put_contents` / `unlink`, so Redis-backed sessions (added in PR #10) could be READ but never persisted or cleaned up.
- **Concurrent-write race**: ZealPHP handles requests concurrently (Apache serialises via file lock; we don't). Two requests both reading and writing back the same session used to drop one writer's data. The handler-write path now reads-then-merges via `array_merge` before writing, preserving divergent top-level keys (OAuth state, code_verifier, flash messages, etc.). **Documented limitation**: shallow merge — both requests pushing to the same nested array still last-write-wins for that nested key. Use a locking handler (Redis WATCH/MULTI) or a database hash for stronger guarantees.

New `tests/Unit/SessionHandlerWriteTest.php` (6 tests): handler-write payload correctness, no-handler file-fallback, concurrent merge with divergent keys, top-level collision resolution semantics, handler-destroy delegation, no-handler unlink fallback.

### Backwards compatibility

- 100% — existing apps that don't call `App::authChecker()` see `isAuthenticated()` continue to return `false` (same as before). Existing apps that don't register a session handler see file-based session storage continue to work (same as before). The two fixes only change behaviour when their respective opt-ins are used.

PHPStan level 10 clean. 383 unit + 147 integration tests pass (+15 auth-hook + 6 session-handler-write, 6 cleanly skipped for ext-redis absence).

## [0.2.24] - 2026-05-19

Two features land together: a real session-cookie bug fix for OAuth/redirect flows ([PR #12](https://github.com/sibidharan/zealphp/pull/12)) and the new template-fragment helper for htmx-style single-file partial rendering.

### Added

- **`App::fragment(string $name, callable $fn): void`** — the [htmx-essay template-fragment pattern](https://htmx.org/essays/template-fragments/) without separate partial files. Mark named regions inline inside any template; the same `App::render('page', $args)` call serves the full page (no fragment selector → every `App::fragment()` runs inline), or returns just one region's HTML when called with `['fragment' => 'name']` (matched region's buffer is cleared, only that closure runs, the rest of the template short-circuits via `HaltException`). Missing fragment → HTTP 404 per the universal return contract. The fragment closure rides the full universal return contract — `return 404;` / `return ['k'=>'v'];` / `return (fn(){ yield ...; })();` all propagate exactly like in a route handler. Lives next to `App::render() / renderToString() / renderStream() / include()` as the fifth member of the file-execution family. See [/learn/htmx#fragments](https://php.zeal.ninja/learn/htmx#fragments) for the lesson, [/demo/fragments/contacts](https://php.zeal.ninja/demo/fragments/contacts) for the live demo.
- New tests: `tests/Unit/FragmentTest.php` (12 tests) pinning full-page rendering, fragment extraction, return-shape propagation (int / array / Generator / string / echo-only), 404-on-missing, nested-render scope isolation, special characters in fragment names, and first-match-wins semantics on repeated names.

### Fixed

- **`session_start()` now auto-emits `Set-Cookie` on first-time visitors** ([PR #12](https://github.com/sibidharan/zealphp/pull/12)). Previously a handler that did `session_start();` + `$_SESSION['x'] = ...;` + `header('Location: …');` on a request with no incoming `PHPSESSID` would 302-redirect *without* a `Set-Cookie` header — the next request started a fresh session and the just-stored data was lost. Broke OAuth flows (state token gone on callback) and any pre-auth redirect pattern. The auto-emit is idempotent (only fires when no inbound `PHPSESSID`), respects `session.use_cookies = 0`, and skips if the response is already flushed. Regression test pinned in `tests/Integration/HttpFeaturesTest::testSessionCookieEmittedOnRedirect`.
- **`HaltException` no longer discards buffered output** when the caller didn't set a fragment result. The PR #10 path was supposed to preserve `echo "html"; throw new HaltException;` as the response body, but the buffered output was dropped at the `return $result` (null) fall-through. Now treated identically to PHP's `include`-returned-1 case → buffered echo becomes the body. Surfaced by FragmentTest; fix wired into `executeFile()`'s HaltException catch.

### Changed

- `template/pages/learn/htmx.php` extended with a new "Template fragments — one file, two responses" section covering the partial-vs-fragment trade-off, the universal-return-contract integration, and a live demo link.
- `template/pages/learn/sessions.php` extended with a "First-visit cookie: redirects work after session_start() too" section documenting PR #12's behaviour with the OAuth-handoff example.

### Backwards compatibility

- 100% — existing apps that don't call `App::fragment()` see no behaviour change. The HaltException-buffer-preservation fix only affects code that already throws HaltException; that path was strictly broken before and now works as documented.

PHPStan level 10 clean. 362 unit + 147 integration tests pass (+12 new FragmentTest cases + 1 regression test for PR #12, 6 cleanly skipped for ext-redis absence).

## [0.2.23] - 2026-05-17

Decouples the four lifecycle decisions that `App::superglobals()` used to bundle into one call. Each is now its own fluent setter, and they default to `null` which resolves to "follow `App::$superglobals`" — so apps that don't touch the new knobs see no behaviour change. Enables the **Mixed-mode / Symfony lifecycle** (`superglobals(true) + processIsolation(false)`): real `$_SESSION` semantics for Symfony's `NativeSessionStorage`, but without the ~30-50 ms `proc_open` + PHP startup + autoloader cost of forking a CGI subprocess on every `App::include()` call.

### Added

- **`App::processIsolation(?bool)`** and backing `App::$process_isolation`. Controls whether `App::include()` dispatches each .php file through `cgi_worker.php` via `proc_open()` (Apache mod_php-style fresh process per file — required for unmodified WordPress / Drupal) or runs in-process via `executeFile()` (much faster, but every include shares the worker's PHP arena). Default `null` follows `App::$superglobals`.
- **`App::enableCoroutine(?bool)`** and backing `App::$enable_coroutine_override`. Controls OpenSwoole's `enable_coroutine` server setting — whether each inbound HTTP request is auto-wrapped in its own coroutine. Default `null` follows `!App::$superglobals`. Setting `true` while `superglobals(true)` is **unsafe** (process-wide `$_GET`/`$_POST`/`$_SESSION` race across concurrent coroutines); `App::run()` emits a `[lifecycle]` warning.
- **`App::hookAll(bool|int|null)`** and backing `App::$hook_all_override`. Controls `OpenSwoole\Runtime::enableCoroutine($flags)` — process-wide PHP I/O hooks (curl, fopen, mysqli). PDO is intentionally NOT hooked in OpenSwoole 22.1 / 26.2 regardless. Accepts `null` (follow `!$superglobals`), `true` (HOOK_ALL), `false` (0), or an explicit int bitmask. Setting non-zero in `superglobals(true)` mode is unsafe and warned.
- **`App::validateLifecycleCombination()`** internal helper — emits `[lifecycle]` warnings to the debug log for unsafe combinations rather than refusing them (users may have niche reasons).
- **Lifecycle mode matrix** in `.claude/CLAUDE.md` documents all six supported combinations: Legacy CGI / Coroutine / Mixed-mode / In-process+sync / Coroutine-no-HOOK_ALL / weird-CGI+coroutine.

### Changed

- `App::run()` resolves the four lifecycle decisions through the new setters instead of hard-coding `App::$superglobals` at three sites ([src/App.php:2841-2845, 2868, 2918](src/App.php#L2841-L2918)). User-passed `enable_coroutine` in `$app->run($settings)` is now re-asserted after settings merge with a comment explaining why (otherwise stray user values silently override the App::enableCoroutine() decision and the lifecycle warnings would be a lie).
- Internal `OpenSwoole\Runtime::enableCoroutine($flags)` call now uses the canonical two-arg form `enableCoroutine(true, $flags)` so PHPStan level 10 accepts it against the IDE stub's `bool` first-arg declaration.

### Backwards compatibility

- 100% — every existing app that doesn't touch the new knobs sees identical behaviour. PHPUnit: 321 unit + 146 integration tests pass. PHPStan level 10: clean.

## [0.2.22] - 2026-05-17

A focused session-interop release. Two coupled bugs were preventing frameworks that drive sessions through PHP's native `session_*()` API (Symfony, Laravel, vanilla PHP) from working under ZealPHP's superglobals mode — silent data loss on every `session_write_close()`, plus a competing `PHPSESSID` cookie from ZealPHP's own SessionManager that would invalidate the framework's cookie.

### Fixed

- **`$_SESSION` ↔ `$g->session` bridge in superglobals mode** — `RequestContext::$session` is a declared typed public property, and PHP resolves declared properties directly without entering the `__get`/`__set` proxy. So `$g->session` and `$_SESSION` were in fact **separate storage**: any code writing to `$_SESSION` (Symfony, legacy PHP) never reached `$g->session`, and `zeal_session_write_close()` serialised the empty `$g->session` while the actual session data was lost. The full `zeal_session_*` family (`start`, `write_close`, `status`, `destroy`, `unset`, `abort`, `encode`, `decode`) now reads/writes the canonical store for the current mode (`$_SESSION` under `superglobals(true)`, `$g->session` under coroutine mode) and mirrors writes to keep both in sync where safe.
- **`zeal_session_status()` false-positive** — used to read `isset($g->session)`, but in superglobals mode the typed property is always initialised to `[]`, so it would always return `PHP_SESSION_ACTIVE` and trip Symfony's `NativeSessionStorage::start()` ("Failed to start the session: already started by PHP."). Now mode-aware.

### Added

- **`App::sessionLifecycle(?bool $on = null): bool`** and the backing `App::$session_lifecycle` static (default `true`). When set to `false`, ZealPHP's `SessionManager` / `CoSessionManager` wrappers skip session_start / cookie emission / write_close so an external framework (Symfony's `NativeSessionStorage`, Laravel, etc.) can own the session lifecycle without ZealPHP racing it for the `PHPSESSID` cookie. Request-context init (`openswoole_request`, `zealphp_response`, error-stack reset) still runs unconditionally; the `zeal_session_*` uopz overrides stay installed and callable from user code regardless. Used by the new [zealphp-symfony](https://github.com/sibidharan/zealphp-symfony) bridge to deliver Symfony-on-ZealPHP with one PHPSESSID across both layers.

## [0.2.21] - 2026-05-17

The full-parity push. Every ⚠ middleware row on the v0.2.20 Apache + nginx coverage matrices now ships as a built-in. Every server-level configurability gap surfaced in the v0.2.20 plan's §10 (`App::$server_admin`, `$canonical_name`, `$hostname_lookups`, `$trusted_proxies` + `App::clientIp()`, `$access_log_format`, `LimitRequestFields` family) is now wired through `src/App.php` with fluent getter/setter methods matching the `App::superglobals()` precedent.

### Added — middleware (12 new entries in `src/Middleware/`)

- **`CharsetMiddleware`** — auto-appends `; charset=utf-8` (or `App::$default_charset`) to text-ish response `Content-Type` values that don't already declare a charset. Apache `AddDefaultCharset` / `AddCharset` parity.
- **`CacheControlMiddleware`** — extension-keyed `Cache-Control: max-age=N, public` (with `immutable` flag for fingerprinted assets) for static-asset responses. Apache `<FilesMatch ".(css|jpg)$"> Header set Cache-Control "max-age=N"` parity.
- **`ExpiresMiddleware`** — adds legacy HTTP/1.0 `Expires:` header by content type. Pairs with `CacheControlMiddleware` for full Apache `mod_expires` (`ExpiresActive`, `ExpiresByType`, `ExpiresDefault`) parity; nginx `expires 30d` parity.
- **`HeaderMiddleware`** — declarative response-header `set(name, value)`, `add(name, value)` (append), `unset(name)` with conditional variants (by status code / content type). Apache `mod_headers` (`Header set / append / unset / add / merge`) parity — the most-requested missing piece given how many `.htaccess` files have a stack of `Header set X-Foo bar` lines.
- **`BasicAuthMiddleware`** — HTTP Basic Auth with htpasswd-style file OR callback verifier (`fn($user, $pass) => bool`). Returns `401 + WWW-Authenticate: Basic` on missing / invalid credentials; `pathPrefix` scopes auth to subtrees. Apache `AuthType Basic` + `AuthUserFile` + `Require`, nginx `auth_basic` parity.
- **`IpAccessMiddleware`** — CIDR-based allow / deny lists with allow-first or deny-first ordering (Apache legacy semantics). Returns `403` on deny. Apache `Allow from / Deny from / Order` + modern `Require ip` parity. Pairs with `App::clientIp()` to resolve the real client IP behind a trusted proxy.
- **`RateLimitMiddleware`** — sliding-window request rate limiter backed by `Store` for cross-worker shared state. Configurable `limit`, `window`, `keyBy` (callable, default IP); returns `429 Too Many Requests` + `Retry-After`. nginx `limit_req zone=one rate=10r/s burst=20` parity.
- **`ConcurrencyLimitMiddleware`** — in-flight concurrent-request cap backed by `OpenSwoole\Atomic` (`Counter`); increments on entry, decrements in `finally`. Returns `503` when the cap is reached. nginx `limit_conn zone=one 10` parity.
- **`BlockPhpExtMiddleware`** — refuses `*.php` URLs with `404` for apps that want extensionless URLs as the only public surface (so scrapers can't enumerate raw files by guessing `config.php` / `admin.php`). Apache `RewriteCond %{THE_REQUEST} \.php; RewriteRule . - [R=404,L]` parity.
- **`MimeTypeMiddleware`** — sets / overrides `Content-Type` on non-static responses by URL extension or pattern (custom types like `.woff2`, `.glb`, `.wasm`). Static files are still MIME-typed by OpenSwoole's static handler. Apache `AddType` / `ForceType` parity.
- **`BodyRewriteMiddleware`** — single-line regex substitution on response body, scoped by `contentTypes` (default text/html). Useful for late-stage URL rewriting (CDN versioning) or hot-patching templates. Apache `mod_substitute` parity; multi-line / streaming variants remain on the roadmap.
- **`HostRouterMiddleware`** — dispatches per-host middleware chains inside one ZealPHP instance based on the `Host` header (with a `__default` fallback). nginx `server_name a.com b.com` ergonomic parity; for true isolation prefer one process per host behind a real proxy.

### Added — server-level configurability (8 new entries in `src/App.php`)

All follow the existing `App::superglobals()` precedent — public static property + fluent getter/setter (no-arg call returns the current value, one-arg call sets it). Backing properties stay public for BC.

- **`App::$server_admin` + `App::serverAdmin()`** — Apache `ServerAdmin` equivalent. Surfaced on the built-in 500 error page templates.
- **`App::$canonical_name` + `App::$use_canonical_name` + `App::canonicalHost()`** — Apache `ServerName` + `UseCanonicalName`. Controls the host source used when building absolute redirect URLs (client `Host` header vs. canonical configured name).
- **`App::$hostname_lookups`** (default `false`) — Apache `HostnameLookups`. When `true`, populates `$g->server['REMOTE_HOST']` via reverse DNS. Off by default — non-trivial perf cost.
- **`App::$trusted_proxies` (CIDR list) + `App::clientIp()` helper** — walks `X-Forwarded-For` only if `REMOTE_ADDR` is in the trusted-proxy CIDR list. **Critical for production deploys behind Traefik / Caddy / nginx for TLS termination.** All client-IP-sensitive built-ins (rate limiter, IP access, access log) consult this helper, so untrusted spoofing of `X-Forwarded-For` is rejected by default.
- **`App::$access_log_format`** — Apache `LogFormat` / `CustomLog` / nginx `log_format` equivalent. Supported tokens: `%h` (client IP), `%l` (ident, always `-`), `%u` (remote user from BasicAuth), `%t` (request time), `%r` (request line), `%>s` (final status), `%b` (response bytes), `%{HEADER}i` (request header), `%{HEADER}o` (response header), `%D` (request duration in µs). `access_log()` in `src/utils.php` parses the format string once at boot and emits per-request lines via the existing async coroutine-channel logging path.
- **`App::$limit_request_fields` / `App::$limit_request_field_size` / `App::$limit_request_line`** — Apache `LimitRequestFields` family. Hard caps on inbound request shape; threaded through to OpenSwoole's `'http_header_buffer_size'` + per-request validation that returns `400 Bad Request` for over-limit requests. Defends against header-bomb DoS patterns.
- **`App::$strip_trailing_slash` + `App::stripTrailingSlash()`** — companion to existing `App::$directory_slash`. Off by default. When on, non-directory URIs ending in `/` get a `301` to the no-slash form. Apache `RewriteCond %{REQUEST_FILENAME} !-d; RewriteRule ^([^/]+)/$ /$1 [R=301,L]` parity.
- **`App::tryInclude($publicPath, $args = [])`** — variant of `App::include()` that returns `null` when the file doesn't exist (vs. `App::include()`'s `403` for security violations). Lets users chain extension-resolver patterns (`return App::tryInclude("/$path.php") ?? App::tryInclude("/$path/index.php") ?? 404;`) without conflating "not found" with "blocked outside DocumentRoot".

### Documentation

- **`template/pages/middleware.php`** — full rewrite. Top-level coverage table now lists every built-in middleware (17 total) with its Apache / nginx parity directive in one column and a one-line behaviour summary in another. New per-middleware reference sections (one per built-in) with a 3-line description + idiomatic `$app->addMiddleware(new XMiddleware(...))` example.
- **`template/pages/legacy-apps.php`** — the Apache `AllowOverride` coverage matrix now shows ✅ + a link to the middleware reference for `BasicAuth`, `Header`, `Charset`, `MimeType`, `Substitute`/`BodyRewrite`, `ForceType`/`MimeType`, `ExpiresActive`, `Allow from`/`IpAccess`. The nginx matrix shows ✅ for `auth_basic`, `limit_req`/`limit_conn`, `expires`, `server_name` (via `HostRouterMiddleware`), `log_format` (via `App::$access_log_format`), `client_max_body_size` / header-limit family (via `App::$limit_request_*`). Worked-example real-world `.htaccess` migration table — every previously-⚠ row now shows ✅ with a link to the matching middleware. Known limitations section trimmed: now lists only genuinely-unsupported features (SSI, mod_speling, mod_imagemap, mod_dav, LDAP / Digest auth, autoindex full customisation surface, nginx `X-Accel-Redirect`, HTTP/3, `proxy_pass`).
- **`.claude/CLAUDE.md`** — Built-in middleware section extended with all 12 new entries (one-line summaries). New "Server-level configurability" section lists every Apache `httpd.conf`-style directive that's now exposed as a fluent `App::$*` setter. Source Layout table extended with all 12 new `Middleware/*.php` rows.
- **`ROADMAP.md`** — every now-shipped item removed from the "Apache + nginx parity middlewares" cluster and the "v0.2.20 follow-ups" cluster. What remains: autoindex (§11), `ProxyMiddleware` (deferred — front-proxy recommendation stays the supported pattern), `BodyRewriteMiddleware` multi-line / streaming variants, HTTP/3 (upstream OpenSwoole).
- **`README.md`** — short paragraph noting the v0.2.21 Apache / nginx parity push under Features, with a pointer to the middleware reference and the legacy-apps coverage matrix.

### Notes

- The 22 follow-ups discovered during the v0.2.20 planning pass have now collapsed to ~3 genuinely-future items. ZealPHP's `.htaccess` / `nginx.conf` coverage story is no longer "most of it ships, the rest is on the roadmap" — it's "all the common stuff ships, here's what we explicitly won't do and why."
- The middleware-builder, configurables-builder, and converter-updater agents worked in parallel against the same fixed spec. The full-parity-middlewares branch bundles all three streams. The AI Config Converter agent's system prompt now knows about every new middleware + every new configurable, so generated `app.php` files use the new built-ins instead of emitting inline custom middleware.

## [0.2.20] - 2026-05-17

### Added
- **`App::include($publicPath, $args = [])`** — fourth member of the file-execution family alongside `render() / renderToString() / renderStream()`. Takes a path relative to `public/` (Apache document-root convention; leading slash optional), auto-populates `$_SERVER['PHP_SELF']` / `SCRIPT_NAME` / `SCRIPT_FILENAME` for the included file (mod_php parity), and applies `includeCheck()` so traversal outside `public/` is refused via the universal return contract (returns `403`). Honours the full route return contract (`int` / `array` / `string` / `Generator` / `Closure` / `void+echo`) in **both** `superglobals(true)` and `superglobals(false)` modes.
- **Configurable static properties + fluent accessor methods** (matching the existing `App::superglobals()` precedent):
  - `App::$document_root` (default `'public'`) + `App::documentRoot(?string $path = null)` — overrides the hardcoded `public/` convention. Apache `DocumentRoot` equivalent.
  - `App::$trace_enabled` (default `false`, security-first) + `App::traceEnabled(?bool $on = null)` — HTTP TRACE method is refused with `405 Method Not Allowed` unless explicitly opted in. Apache `TraceEnable Off` equivalent.
  - `App::$default_charset` (default `'utf-8'`) + `App::defaultCharset(?string $charset = null)` — Apache `AddDefaultCharset` equivalent. Consumed by future `CharsetMiddleware`.
- Wrapper methods for existing configurable static properties: `App::ignorePhpExt()`, `App::directorySlash()`, `App::directoryIndex()`, `App::pathInfo()`, `App::staticHandlerLocations()`, `App::blockDotfiles()`, `App::displayErrors()`. Existing direct property access (`App::$X = Y`) keeps working for BC.
- **Universal return contract — canonical home at `/responses#return-contract`** with deep-linkable anchor. Every other website page that previously restated fragments of the contract now links here. `.claude/CLAUDE.md` mirrors the table verbatim under a lock-step note.
- **`$g` vs `$_*` parity rule — canonical home at `/coroutines#state-parity`**. Documents when superglobals are safe (bridged in `superglobals(true)`, NOT populated per request in coroutine mode), and the one-line decision rule: "Use `$g->X`. It works in both modes."
- **Apache rewrite recipes — 12 worked examples (A through L)** on the legacy-apps page, each side-by-side Apache → ZealPHP. Covers extension strip, pretty URL → `.php`, front controller, API prefix, specific file mapping, blocking direct access, HTTPS/canonical host, maintenance mode, ErrorDocument, SEO redirects, trailing slash.
- **Full Apache `AllowOverride` coverage matrix** + **full nginx `ngx_http_core_module` / `ngx_http_rewrite_module` coverage matrix** on the legacy-apps page — every practical directive mapped to its ZealPHP equivalent (✅ built-in / ⚠ small middleware on the roadmap / 💡 PHP-level / ❌ unsupported with reason).
- **Real-world full-`.htaccess` worked example** — a Q&A platform with ~30 RewriteRules, headers, charsets, caching — ported row-by-row with per-row coverage classification.
- **"Known limitations" section at the top of legacy-apps** — 30-second dealbreaker scan organised by Apache / nginx / ZealPHP-internal categories, so porters can check for blockers before committing.

### Changed
- **`App::render()` now returns `mixed`** instead of `void` and honours the full route return contract for explicit returns from templates. **BC preserved**: templates with no explicit `return` (the existing pattern in every `public/*.php`) continue to echo their captured output — every existing `App::render('_master', …)` call site keeps working unchanged. Templates that explicitly `return` a status / array / Generator / Closure now flow that value back to the caller.
- **`App::includeFile()` renamed to `App::include()`**. Old name retained as a deprecated alias with no runtime warning — no behaviour change for existing callers (WordPress showcase, scaffolds, learn-mode tutorials). The deprecation is documentation-only this cycle and will survive at least through v0.2.x.
- **Internal refactor — `render() / renderToString() / renderStream() / include()` now share a single private core (`executeFile()`)** that runs the file, captures output, and applies the return contract. Public methods are thin wrappers that differ on path resolution and result coercion only. Closure-param-injection reflection cache is shared too.
- **Configurable options now have fluent getter/setter methods** uniformly. No-argument call returns the current value; one-argument call sets it. Backing static properties stay public for BC. The documented API, the converter bot, and the website example code all use the method form. Direct property access is not deprecated this cycle — that's a future minor-release announcement.
- **Internal implicit-route call sites (4 of them — `serveDirectory()`, implicit `/`, `/{file}`, `/{dir}/{uri}`)** simplified to one-line `return App::include('/...')` calls. The 3-line `$g->server[...]` preamble + manual absolute-path construction is now owned by `App::include()` itself.
- **AI Config Converter agent** (`examples/agents/config_converter.py`) — system prompt updated to teach the new `App::include()` form, the universal return contract, the `$g` vs `$_*` parity rule, the 12 Apache recipes, the Apache `AllowOverride` + nginx coverage matrices, and the known-limitations list. The bot now refuses unsupported directives explicitly (rather than silently emitting broken or no-op code).

### Fixed
- **In-process file execution no longer silently discards `int` / `array` / `string` return values** from the included file. Previously only `Generator`/`Closure` returns surfaced; everything else got dropped on the floor. Templates and included files can now `return 404;` to set the status, `return ['ok' => true];` for JSON, `return "explicit body";` for HTML.
- **Subprocess (`superglobals=true`) path now threads the included file's return value back** over the stderr metadata channel so the universal return contract works across the process boundary too. Closure returns can't carry param injection across the pipe — documented as the one footnote in the limitations section.

### Security
- **HTTP TRACE method refused with `405 Method Not Allowed` by default** (`App::$trace_enabled = false`). TRACE is a Cross-Site Tracing (XST) attack vector that can leak cookies and auth headers from clients that issue TRACE requests with credentials. Opt back in via `App::traceEnabled(true)` if you have a specific debugging need behind an internal-only network.

### Documentation
- **`template/pages/responses.php`** — promoted to the canonical home for the universal return contract (anchor `#return-contract`). Lock-step with `.claude/CLAUDE.md`.
- **`template/pages/coroutines.php`** — new section "`$g` vs `$_*` — request state in both modes" (anchor `#state-parity`) as the canonical home for the parity rule.
- **`template/pages/legacy-apps.php`** — full rewrite per the new shape. Known limitations dealbreaker scan at the top; migration ergonomics before/after; 12 Apache rewrite recipes with anchor ids; recipe summary; real-world `.htaccess` worked example with per-row coverage; full Apache `AllowOverride` matrix; full nginx coverage matrix; WordPress example bumped to one-liner; CGI architecture diagram updated to show the return-value channel.
- **`template/pages/templates.php`** — three-render-methods table replaced with the canonical four-method file-execution family (anchor `#file-execution-family`); each row links to `/responses#return-contract` for the shared semantics.
- **Cross-page link-update sweep** — every page that previously restated a fragment of the return-shape table or the `$g`-vs-`$_*` rule now links to `/responses#return-contract` or `/coroutines#state-parity`: `routing.php`, `streaming.php`, `api.php`, `middleware.php`, `home.php`, `sessions.php`, `migration.php`, `design-tradeoffs.php`. (The `learn/*` lesson area is owned by a separate in-flight work stream and will be propagated there in a follow-up pass.)
- **`README.md`** — migration example uses `App::include('/index.php')`; new paragraph introduces the file-execution family.
- **`.claude/CLAUDE.md`** — replaces "Return value conventions" with the canonical 9-row contract table (verbatim mirror with the lock-step note); adds "File-execution family" subsection; adds "Lifecycle: static config → `init()` → instance routing → `run()`" architectural section with diagram; updates "Legacy App Support (CGI Worker)" + "G Class — Dual-Mode Global State" + Source Layout sections.
- **`ROADMAP.md`** — adds the "Apache + nginx parity middlewares" follow-up cluster surfaced by the legacy-apps coverage matrices (CharsetMiddleware, CacheControlMiddleware, ExpiresMiddleware, HeaderMiddleware, BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware, ConcurrencyLimitMiddleware, BlockPhpExtMiddleware) and the basic-directory-autoindex item.

### Notes
- 5 of the Discovered follow-up items (`App::$server_admin`, `App::$canonical_name` + `App::$use_canonical_name`, `App::$hostname_lookups`, `App::$trusted_proxies` + `App::clientIp()`, `App::$access_log_format` + custom access-log support) are intentionally deferred to a follow-up so this changeset stays focused on `App::include()` + the return contract.
- The Apache `AllowOverride` matrix on the legacy-apps page is the single most-asked-for missing piece this release closes — porters can now scan ONE table and know exactly which `.htaccess` directives are ✅ ⚠ 💡 ❌ before they commit to a migration.

## [0.2.19] - 2026-05-16

### Added — security / quality CI rollup (Tier 1 from CRITIC.md plan)
- **`composer audit` in CI** (`validate` job) — runs after `composer validate --strict`. Built-in to Composer 2.4+, free, calls the Packagist advisory API on every installed dependency in `composer.lock`. Catches CVEs in transitive deps that PHPStan can't see (e.g., openswoole, psr/*). `--abandoned=report` keeps abandoned-package warnings informational; only actual CVEs fail CI.
- **Dependabot config** (`.github/dependabot.yml`) — weekly Monday PRs for `composer` + `github-actions` ecosystems. Security advisories pushed immediately regardless of schedule. Minor/patch updates are grouped into a single PR per ecosystem; majors stay individual for review.
- **CodeQL workflow** (`.github/workflows/codeql.yml`) — GitHub's free SAST. Currently configured for `actions` and `javascript-typescript` languages (catches workflow-injection patterns + inline `<script>` blocks in `template/pages/*`). PHP is experimental in CodeQL; matrix is structured to add `php` immediately when it goes GA. Runs on every push, every PR, and weekly to pick up new CodeQL queries against unchanged code. Uses the `security-and-quality` query pack.
- **gitleaks workflow** (`.github/workflows/gitleaks.yml`) — secret scanner. Full-history scan (`fetch-depth: 0`) on every push and PR. Free OSS scan path, no Gitleaks license needed. Catches the class of accidents where a quick test commits a real `OPENAI_API_KEY` (especially given the `examples/agents/notes_agent.py` flow).

### Changed (badges)
- **Packagist badges swapped from `poser.pugx.org` → `shields.io`.** poser caches aggressively and was still showing v0.2.10 several hours after v0.2.18 shipped to Packagist. shields.io reads the Packagist API directly with minimal cache, and matches the style (`flat-square`) of every other badge in the README.
- README badge row reorganized: CI / CodeQL / gitleaks / Coverage / PHPStan in that order. The three new security-CI badges (CodeQL, gitleaks, and the composer-audit signal embedded in CI) give a one-line credibility scan from the README.

### Notes
- This release adds zero `/src/` changes — entirely CI hygiene + badges + docs. Tests: 204 unit + 113 integration, all green. PHPStan: 0 errors at level 10.
- Tier 2 work (PHP-CS-Fixer, Roave Security Advisories, PHP-Compatibility checker) and Tier 3 (Infection mutation testing, PHPBench) tracked for future releases — see [CRITIC.md](CRITIC.md) for the ROI ranking.

## [0.2.18] - 2026-05-16

### Fixed
- **composer.lock out of sync with composer.json** in v0.2.17 — the v0.2.17 commit bumped `phpstan/phpstan` constraint to `^2.1` in composer.json, but my local "restore composer.lock from HEAD" command (during the same release sequence) inadvertently reverted the lock back to the pre-upgrade PHPStan 1.12.33 entry. Result: `composer validate --strict` failed CI on v0.2.17 (caught immediately on next push to master), and anyone running `composer install` against the v0.2.17 tag would have resolved PHPStan to 1.x despite composer.json declaring 2.x.
- composer.lock now correctly pins `phpstan/phpstan` at `2.1.54`, matching the constraint. `composer validate --strict` passes. PHPStan 2.x level 10 still reports 0 errors. 204 unit + 113 integration tests still green.
- No behavior changes outside the dependency-resolution surface. Everyone on v0.2.17 should upgrade to v0.2.18.

## [0.2.17] - 2026-05-16

### Changed
- **PHPStan upgraded from `^1.12` → `^2.1`** + **baseline raised from level 9 (1.x) → level 10 (2.x)**. Level 10 is the strictest PHPStan tier and is what Symfony 8+, Laravel 12+, and Mezzio score at. 252 errors at level 10 on the bare upgrade → **0 errors after this release.** ZealPHP joins the level-10 club while still running unmodified PHP-FPM-era code via uopz, `__call` proxies, and reflection-injected handler params.
- **Inline `@phpstan-ignore-next-line` count**: 74 → 75. Net delta is mostly category churn — some 1.x identifiers were renamed in 2.x (e.g., `nullCoalesce.property`, `isset.property` as new identifiers), and seven new sites surfaced after 2.x's stricter mixed-type rules.
- **`vendor/` removed from git** in both this repo and the scaffold. Previously committed through v0.2.16, dropping ~4300 file-changes per release and aligning with standard PHP library practice (Symfony / Laravel / Mezzio all gitignore `vendor/`). `composer create-project` users see no UX change — Composer runs `composer install` automatically after extraction. `composer.lock` IS kept tracked for CI reproducibility (especially PHPStan, where a minor version bump can change static-analysis output).

### Added
- **PHPStan badge auto-sync**: README badge now reads from `.github/badges/phpstan.json` via shields.io's `endpoint` API. CI's `validate` job verifies the JSON's `level` matches `phpstan.neon`'s `level:` setting; out-of-sync states fail CI loudly instead of silently misrepresenting the project's static-analysis posture. Release flow drops "manually bump README badge level" — CI now enforces consistency.
- **PHP 8.5 in CI matrix** (experimental, `continue-on-error: true`). 8.5 is pre-GA at time of writing; OpenSwoole/uopz binaries via shivammathur/setup-php may not be available yet. Result: status visible in CI, failures don't block master.
- **Tier-1 CI hygiene** prep: workflow restructured for explicit per-PHP-version coverage / experimental flags (`matrix.include` with named keys instead of bare version list).

### Fixed
- **PHP 8.4 CI flake** (`test_chat_consecutive_requests_work` returning `0` instead of `200`). Root cause: Xdebug coverage instrumentation slows PHP enough that curl's default timeout fires on consecutive chat requests, returning curl failure code `0`. Fix: drop Xdebug from the 8.4 matrix entry (`coverage: 'none'`). 8.3 remains the only Codecov uploader, so no signal is lost. 8.4 still runs the full test suite. This closes the [ROADMAP.md](ROADMAP.md) "PHP 8.4 CI flake fix" item.
- **`Range`-middleware chained `->withHeader()->withHeader()`** previously produced an L10 error because OpenSwoole's `withHeader()` has no return type. Split into two statements with intermediate `assert($resp instanceof ResponseInterface)` (annotation-only, no behavior change).
- **`LazyServerRequest`** PSR-7 getter return types were widened by OpenSwoole's mixed-typed `Request::$server`/`$header`/`$cookie` properties. Added per-getter `assert(is_array(...))` + value-by-value scalar coercion so PSR-7's declared return types are honored without runtime cost.

### Notes on Codecov integration
- Swapped the upload step from `use_oidc: true` to `token: ${{ secrets.CODECOV_TOKEN }}` per Codecov's standard onboarding flow. Both methods work once a repo is enabled at Codecov; token-based is the official documented path. Dropped `id-token: write` permission since OIDC is no longer used. CI's coverage upload now succeeds (was failing silently with "Repository not found" before the token was added).

### CRITIC.md
- "PHPStan level 1 ceiling" entry extended with a v0.2.17 update: **level 10 reached on PHPStan 2.x.** The original "deliberate trade-off" framing was 90% overstated; only ~57 of the original 572 level-9 errors were genuine design tax. 75 ignore-with-reason sites now individually document each.

## [0.2.16] - 2026-05-16

### Changed
- **PHPStan baseline raised from level 6 → level 9.** Final climb of the three-release series (v0.2.14 → v0.2.15 → v0.2.16). The framework now passes the strictest PHPStan level Symfony/Laravel/Mezzio score at, while still running unmodified PHP-FPM-era code via uopz / `__call` / reflection.
- **Total ignore-with-reason sites: 74** inline `@phpstan-ignore-next-line` annotations across `src/`, each with a one-line reason. Verifiable via `grep -rn '@phpstan-ignore' src/ | wc -l`. The CRITIC.md framing flipped: ~57 of the original 572 level-9 errors were the genuine design-tax sites listed in the "PHPStan level 1 ceiling" entry; the other ~515 were just missing annotations the framework had never done. Three patch releases closed the gap in 12 hours.

### Fixed (real bugs surfaced during the null-safety pass)
- **`Learn\Auth::login()`** — returned a "logged in" int when `PDOStatement::fetch()` returned `false` (no matching user), because `false['id']` casts to `0`. Now requires `is_array($user)` before access. Affects v0.2.x line. Severity: low (only affected the /learn demo, but a real auth bypass class if anyone copy-pasted the pattern).
- **`Learn\Auth::currentUser()`** — same pattern; stale session ID with no matching DB row returned the previous shape instead of clearing the session. Fixed.
- **`Learn\Auth::rateLimit()`** — compared `$existing['reset']` after `Store::get()` returned `false` for a missing key; the boolean would short-circuit the rate-limit check incorrectly. Now guarded with `is_array($existing)`.
- **`Cache::get()` / `Cache::has()` / `Cache::gcMemory()`** — treated `Store` misses (`false`) the same as actual array rows, leading to subtle "key has no expiry" false-positives. Now strict `is_array($row)` guards.
- **`ZealAPI::processApi(string $module, ?string $request)`** — crashed on `basename($request)` when `$request === null` (which is the documented two-segment `/api/{module}` shape with a missing tail). Now guarded with `?? ''`.

### Added (PHPDoc + type narrowing)
- **`RequestContext` typed payload properties** — `$zealphp_request` / `$zealphp_response` / `$openswoole_request` / `$openswoole_response` tightened from raw `mixed` to nullable concrete types (`?\ZealPHP\HTTP\Request` / `?\ZealPHP\HTTP\Response` / `?\OpenSwoole\Http\Request` / `?\OpenSwoole\Http\Response`). PHPStan can now see the runtime shape, eliminating dozens of mixed-type errors at level 9 in one shot.
- **Class-level `@method` PHPDoc on `HTTP/Request.php` and `HTTP/Response.php`** — declares the forwarded OpenSwoole methods (`isWritable`, `write`, `sendfile`, `getContent`, `status`, `header`, `cookie`, `redirect`, `end`, etc.) so the `__call` proxy is statically typed at every call site. The proxy still works for any other method via the fallback path.
- **`assert(is_callable($handler))` / `assert(is_array($options))`** after the route-registration overload swap blocks (`route()`, `nsRoute()`, `nsPathRoute()`, `patternRoute()`). PHPStan can't narrow `array<string, mixed>|callable` after a runtime is_callable swap; the assert tells it the new state.
- **`\Closure::fromCallable($handler)`** at the `\ReflectionFunction(...)` call site (`Closure|string` is the constructor's accepted union; `callable` was too loose).
- **Null-safety guards** added at `file_get_contents()`, `realpath()`, `glob()`, `parse_url()`, `filemtime/filesize`, `json_encode`, `preg_split`, `curl_exec` call sites across the framework. Most surfaced fail-quietly paths where a `false` would silently propagate as `0` or `''`.
- **`phpstan.neon`** — `level: 6` → `level: 9`. Added two ignoreErrors patterns: `App::tick()`/`after()` should-return-int-but-returns-bool|int (OpenSwoole Timer stub mismatch — real ext returns int timer id) and `Counter::increment()`/`decrement()` same issue (OpenSwoole Atomic stub mismatch).

### Notes
- **No behavior changes shipped.** This release is annotation, type-narrowing assertions, inline ignores, and the 6 real bug fixes. The 6 fixes are in demo / cache / API edge paths; production code paths were unaffected by the bugs. Tests verify no regression (204 unit + 113 integration, all green).
- **CRITIC.md updated** — the "PHPStan level 1 ceiling" entry now has both the original framing (struck through) and the v0.2.16 reality: ~57 sites are genuine design-tax (and now individually documented with inline ignore-with-reason annotations), the other ~515 errors at the original level-9 baseline were just unwritten annotations.

## [0.2.15] - 2026-05-16

### Changed
- **PHPStan baseline raised from level 5 → level 6.** Second of three planned releases climbing to level 9. **The annotation cliff is now complete: 369 missing-type errors are fixed across the entire `src/` tree.**
- Pure annotation pass. **No behavior changes anywhere** — `@param`/`@return` PHPDoc and `array<K, V>` generic specs added, plus real type hints where safe.

### Added (PHPDoc / typed properties)
- **`src/App.php`** — 75+ method annotations: constructor + setters got real `string`/`int`/`bool` hints; route registration methods (`route`/`nsRoute`/`nsPathRoute`/`patternRoute`) got `@param array<string, mixed>|callable $options`; iterables returned by `routes()`/`routesByMethod()`/`routesByExactMethod()`/`wsRoutes()`/`parseCss()`/`getFallback()`/`getErrorHandler()`/`parseCliArgs()`/`buildParamMap()` got `array<K, V>` generics; `$error_handlers` property typed with a detailed shape array; `TemplateUnavailableException` properties got `@var` (native type override would error); `LocationHeaderMiddleware::$correctPort` typed `int`.
- **`src/utils.php`** — annotated 50+ free functions including the uopz override targets (`header`, `setcookie`, `setrawcookie`, `http_response_code`, `headers_list`, `headers_sent`, `header_remove`, `flush`, `ob_*`, `apache_*`, `is_uploaded_file`, `move_uploaded_file`, `set_error_handler`, `set_exception_handler`, `register_shutdown_function`, `error_reporting`). Signatures match PHP native exactly.
- **`src/Session/utils.php`** — annotated all 19 `zeal_session_*` shims. PHPDoc matches the PHP native `session_*` signatures.
- **`src/Session/{Co,}SessionManager.php`** — `__invoke(): void`, `$g` typed `\ZealPHP\RequestContext`.
- **`src/IOStreamWrapper.php`** — typed all 17 stream wrapper methods (PHP's `streamWrapper` contract); `$context` PHPDoc'd as `resource|object|null`.
- **`src/RequestContext.php`** — typed `$instance` as `?self`; `array<string, mixed>` generics on all bag/session/memo arrays; shape annotations on handler stacks; `mixed` PHPDoc on `__get`/`__set`/`get`/`set`.
- **`src/REST.php`** — typed all 6 properties; `mixed` on `_response`/`_request`; PHPDoc + real return types throughout.
- **`src/ZealAPI.php`** — typed `$data: string`, `$reflectionCache: array<string, array<int, \ReflectionParameter>>`, `$api_rpc: \Closure|null`, `$_undefinedMethodError: array<string, mixed>|null`; PHPDoc on `__construct`, `processApi`, `paramsExists`, `die`, `__call`, `json`.
- **`src/Cache.php`** — `@return array{...}` shape for `stats()`.
- **`src/StringUtils.php`** — typed all 4 static methods as `(string, string): bool|string`.
- **`src/Counter.php` / `src/Store.php`** — `array<string, array{0: int, 1: int}>` generics on `Store::make()` columns, `array<string, mixed>` on `set()` rows, `list<string>` return on `names()`.
- **`src/apache_shims.php`** — `array<string, string>` return on header functions; `string|false` on `apache_getenv`.
- **`src/Learn/*`** — `array<string, mixed>` generics on `mock`/`real` `$user`; `array<int, array<string, mixed>>` returns on `Notes::list`/`search`, `ChatHistory::forThread`/`threads`; `array{user_id: int, username: string}` shape on `Auth::currentUser`; `array<string, \PDO>` on `DB::$cache`; `array<string, mixed>` on `WS::broadcast` payload.
- **`src/HTTP/Request.php` / `src/HTTP/Response.php`** — PHPDoc on `__call`/`__get`/`__set` proxy magic methods; `array<int, array{0: string, 1: string}>` shape on `Response::$headersList`; `array<int, array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool, 6: bool, 7: string, 8: string}>` on cookies lists.
- **`src/HTTP/LazyServerRequest.php`** — `array<string, mixed>` generics on PSR-7 `getServerParams`/`getQueryParams`/`getAttributes`/`with*` methods.
- **`src/Middleware/CorsMiddleware.php`** — `array<int, string>` on all three list properties + constructor params; `resolveOriginsList()` PHPDoc'd.
- **`src/Middleware/RangeMiddleware.php`** — `array{0: int, 1: int}` shape on `singleRange` ranges; `array<int, array{0: int, 1: int}>` on `multiRange`.
- **`src/Legacy/ApacheContext.php`** — `array<string, string>` on `$env` and `$notes`.
- **`src/Log/Logger.php`** — `array<string, mixed>` on `interpolate()` `$context`.
- **`src/Cache/SimpleCacheAdapter.php`** — `iterable<string, mixed>` on `setMultiple()` `$values`.
- **`src/HTTP/Client.php`** — `array{timeout?: int, verify_ssl?: bool, max_redirects?: int}` on `__construct()` `$options`.
- **`src/HTTP/Factory/ServerRequestFactory.php`** — `array<string, mixed>` on `createServerRequest()` `$serverParams`.
- **`src/Session/Handler/CoroutineMemorySessionHandler.php`** — `array<int, array<string, array{data: string, last_access: int}>>` shape on `$sessions`.

### Notes
- v0.2.16 will tackle the remaining ~155 errors at levels 7–9 (null safety + the design-tax sites for `__call` proxies, uopz overrides, and reflection-injected handler params). That release flips the [CRITIC.md](CRITIC.md):128-136 entry "PHPStan level 1 is a deliberate trade-off."

## [0.2.14] - 2026-05-16

### Changed
- **PHPStan baseline raised from level 1 → level 5.** First of three planned releases (v0.2.14 / v0.2.15 / v0.2.16) climbing to level 9. Reframes the "PHPStan level 1 is a deliberate trade-off" framing from [CRITIC.md](CRITIC.md):128-136 — investigation showed most of the gap was missing annotations, not architectural limits. Level 5 = full parameter-type checking with 0 errors.

### Fixed
- **`src/StringUtils.php::get_string_between()`** — three `(int)` casts on string delimiters made the function return wrong results for any non-numeric delimiter. The docblock said "Integer" but the implementation needs string delimiters (e.g., finding text between `[start]` and `[end]` tags). Casts removed. Method is currently unreferenced in framework code but is part of the public `ZealPHP\StringUtils` API.
- **`microtime()` float-arithmetic idiom** in `src/Session/CoSessionManager.php`, `src/Session/SessionManager.php`, and `src/utils.php::get_current_render_time()` — the classic `$t = microtime(); $t = explode(' ', $t); $time = $t[1] + $t[0];` pattern computes a float via PHP's string-to-float coercion. Replaced with `microtime(true)` (which returns float directly) at all three sites. No behavior change; cleaner and PHPStan-correct.
- **`src/utils.php::response_set_status(int $status)`** — `is_int($status)` check after a typed `int` parameter was dead code. Simplified.
- **`src/ZealAPI.php::processApi()`** — `is_array($handler)` branch on a `Closure`-only field was dead code (the upstream assignment is `Closure::bind(...)` exclusively). Branch removed; reflection now goes directly to `\ReflectionFunction`.
- **`src/utils.php::resolve_log_dir()` + `resolve_log_path()`** — defensive `$candidate === ''` and `$path === null` guards on values that PHPStan (and runtime) can confirm are never empty/null. Removed.

### Removed
- **Deleted `src/Session.php`** — confirmed dead code by static analysis and explore-agent investigation. The class referenced a `\ZealPHP\UserSession` type that **does not exist anywhere in the codebase**, was imported by `src/App.php` but never called, and was distinct from the real session managers in `src/Session/SessionManager.php` and `src/Session/CoSessionManager.php`. Removed entirely. Removed `use ZealPHP\Session;` from `src/App.php`.
- **`src/ZealAPI.php::$auth` property** — declared but never read, only written. Dead.
- **`src/REST.php::get_status_message()`** — private method, no internal callers. Dead.

### Documentation
- `phpstan.neon` now has documented `ignoreErrors` patterns covering the OpenSwoole / posix / PHP-version stub mismatches (each with a one-line `# reason` comment explaining what the stub got wrong vs runtime behavior).

### Notes on architectural improvements driven by analysis
- `RequestContext::instance(): self` — added explicit return type. PHPStan now correctly narrows `$g = RequestContext::instance()` to `RequestContext`, which alone removed 5+ "access to undefined property `object::$X`" errors at level 2 across `src/Session/utils.php` and `src/utils.php`. Same change improves IDE autocomplete for any code calling `instance()`.
- `RequestContext::instance()` now `assert($instance instanceof self)` on the coroutine-context retrieval, replacing the implicit `mixed` return.
- `\ZealPHP\HTTP\Request::$parent` made public (was private, exposed through `__get` magic anyway — the `private` was illusory and forced consumers through the slower magic path).
- `IOStreamWrapper::$position` and `$input` typed as `int` and `string` respectively.

## [0.2.13] - 2026-05-16

### Fixed (framework)
- **`static_handler_locations` prefix-collision bug.** OpenSwoole's built-in static handler does raw string-prefix matching, not segment-boundary matching. The default whitelist `['/css', '/js', '/img', '/images', '/fonts', '/assets', '/static']` meant `/json` (and any user route starting with `js`) was silently intercepted by the static handler before reaching the framework — OpenSwoole returned its default 404 (no middleware headers, no framework routing). Any route starting with `/css*`, `/js*`, `/img*`, `/fonts*`, `/assets*`, `/static*` was affected.
  - **Affected versions: all v0.2.x through v0.2.12.**
  - Fix: directory entries in the default whitelist now have trailing slashes (`/css/`, `/js/`, `/img/`, ...). Trailing slash forces segment-boundary matching at the C level. Exact-file entries (`/favicon.ico`, `/robots.txt`) keep their bare form. ([src/App.php:1497-1505](src/App.php#L1497-L1505))
  - Found while testing the demo cleanup after [pastebin app.php review](CRITIC.md) — `/json` returned OpenSwoole's default 404 instead of the framework's, and the trace led back to `/js` matching `/json` as a prefix.

### Changed (framework)
- **`CorsMiddleware` default origin behavior.** Constructor signature changed from `array $origins = ['*']` to `?array $origins = null` (backward-compatible — existing `new CorsMiddleware()` calls still work). Origin resolution order is now:
  1. Explicit `origins` constructor argument
  2. `ZEALPHP_CORS_ORIGINS` env var (comma-separated)
  3. Falls back to `['*']` with a one-time `elog()` warning per worker
  - Rationale: `*` is the lowest-friction default but unsafe for any API serving credentials or user-scoped data. v0.2.x's no-breaking-change policy rules out a hard "require origins" — but a silent wildcard default also can't ship. The warning surfaces the risk in production logs without breaking any existing app.
  - Triggered by [pastebin app.php review](CRITIC.md) — reviewer flagged the `*` default as a "security risk."

### Changed (main repo demo / OSS website)
- **`app.php` rewrite** in response to a public line-by-line review (371 → 187 lines):
  - Use statements moved to top of file (PSR-12 compliance)
  - Added `declare(strict_types=1)`
  - Removed unused `zlog` import
  - Removed hardcoded `date_default_timezone_set('Asia/Kolkata')` — now reads `ZEALPHP_TZ` env or php.ini's `date.timezone`
  - Removed backtick `git describe` for asset versioning (broken in `composer create-project` deployments where `.git` doesn't exist) — replaced with `filemtime(public/css/zealphp.css)` so cache-bust tracks actual style changes
  - Inline `AuthenticationMiddleware` / `ValidationMiddleware` classes (which authenticated and validated nothing) moved to `examples/demo_middleware.php` with honest names: `RequestLogMiddleware`, `QueryDumpMiddleware`. Still gated behind `ZEALPHP_DEMO_MIDDLEWARE=1`.
  - Removed 9 junk demo routes: `/exittest` (called `exit()` — kills the OpenSwoole worker), `/co`, `/quiz/{page}` (both forms), `/sessleak` (empty stub), `/suglobal/{name}`, `/header`, `/coglobal/set/session`, `/coglobal/get/{name}`, `/stream_test`, `/user/{id}/post/{postId}`, `nsRoute('watch', ...)`, `patternRoute('/raw/(.*)', ...)`. None were referenced from tests, docs (as live links), bench scripts, or website templates.
  - `/json` body changed from `RequestContext::instance()->session` (which leaked session data!) to `['ok' => true, 'service' => 'zealphp']`. Same full PSR-15 stack + auto-JSON serialization path; no data leak. The route remains the documented `PERF.md` benchmark endpoint.
  - Env-parsing consolidation pass — `worker_num` joined the `foreach` loop with the other integer env keys instead of having its own duplicated block.

### Changed (scaffold sync)
- The `sibidharan/zealphp-project` scaffold's `app.php` now demonstrates the correct CORS pattern: explicit `origins`, plus a comment block explaining that wildcard is unsafe in production and that `ZEALPHP_CORS_ORIGINS` env override is the alternative. Imports follow PSR-12; `declare(strict_types=1)` added.

### Documentation
- **CRITIC.md** — new section for the app.php pastebin review round, including the demo-app critiques accepted, the static-handler prefix-collision bug discovered during testing, the CorsMiddleware default change, and the pushbacks (`#` vs `//` is bikeshed; `$envInt` as closure is correct; framework middleware does *not* use the G singleton).

## [0.2.12] - 2026-05-16

### Security / Stability
- **Worker-crash TypeError on corrupted session files** (severity: high; DoS for any affected session ID). After v0.2.6 declared `RequestContext::$session` as typed `array`, three sites in `src/Session/utils.php` did `unserialize(file_get_contents(...))` and assigned the result directly to `$g->session`. `unserialize()` returns `false` on empty/corrupted/truncated payloads or on any non-array serialized value, triggering `TypeError: Cannot assign false to property RequestContext::$session of type array`. The worker aborts with `status=255`, every subsequent request that touches the affected session ID 500s until the worker recycles.

  Trip surfaces:
  - Empty session file (interrupted write / partial flush)
  - Truncated or corrupted serialized data (e.g., server killed mid-write)
  - File became unreadable between `file_exists()` and `file_get_contents()` (TOCTOU race)
  - Any non-array serialized value (string, int, null)
  - `session_decode()` called with malformed user-supplied input

  **All v0.2.6 through v0.2.11 are affected. Upgrade strongly recommended for any production deployment.**

  Fix:
  - `zeal_session_start()` — defensive read+decode with `is_string($contents)` + `is_array($decoded)` guards; falls back to `[]` on any failure
  - `zeal_session_reset()` — same defensive handling; replaced the unsafe `unset($g->session)` with `$g->session = []` (matches the declared default; `unset` on a typed property leaves it uninitialized)
  - `zeal_session_decode($data)` — now returns `bool` (matches PHP native `session_decode` signature). Returns `false` for non-string input, empty string, malformed serialized data, or valid serialized non-array. Only `is_array($decoded)` results assign to the session.

  11 new regression tests in `tests/Unit/SessionFileCorruptionTest.php` covering all four trip surfaces plus the success path.

### Documentation
- **ROADMAP.md restructured** — explicit versioning policy stated at the top: the v0.2.x line is the security + hardening + migration series; new runtime features target v0.3 and beyond. "v0.2 — Security & Migration" section now lists every shipped release (v0.2.4 → v0.2.12) with trigger and outcome, plus the remaining v0.2.x items (connection pool, integration test isolation, PHP 8.4 CI flake). Connection pooling moved from v0.3 to v0.2.x — it's a production-trust item, not an observability feature.
- **CRITIC.md outstanding section** reframed from "v0.3 sprint" to "remaining v0.2.x items" — discipline-contract sprint items struck through (shipped in v0.2.10), pool work + test isolation listed as remaining hardening.

## [0.2.11] - 2026-05-16

### Security
- **Open-redirect bypass via leading whitespace + `javascript:` scheme.** v0.2.5's redirect guard used `preg_match('#^(javascript|data|vbscript):#i', $url)` — the `^` anchor failed to match when the URL had leading whitespace. Browsers strip leading whitespace from `Location` header values before parsing, so a URL like `   javascript:alert(document.cookie)` slipped past the scheme check and executed in the browser. Application code passing user input directly to `$response->redirect()` (e.g., `?next=` post-login redirects) was exploitable. **All v0.2.5 – v0.2.10 are affected.**
  - Fix: `Response::redirect()` now rejects any URL with leading/trailing whitespace.
  - Belt-and-suspenders: any backslash anywhere in the URL is also rejected (`/\evil.com` and `\\evil.com` are parsed as protocol-relative redirects by many browsers, same effective bypass as `//evil.com` which our cross-origin warning already catches).
  - 7 new regression tests in `tests/Unit/SecurityTest.php` covering the variants.

### Added
- **17 regression tests** in `tests/Unit/RequestContextInvariantsTest.php` pinning the v0.2.6 architectural contracts: `G` ↔ `RequestContext` class_alias identity, strict `__set` rejecting undeclared writes in coroutine mode, response state location (on `Response`, not on `RequestContext`), `ApacheContext` lazy allocation, `#[AllowDynamicProperties]` removed, declared property defaults. Catches future drift in any of these.

### Changed (documentation)
- **`template/pages/deployment.php` env var table rewritten** — 20 variables in 4 groups (Server / Logging / Middleware & sessions / Site). Adds `ZEALPHP_INI_ISOLATE`, `ZEALPHP_RECYCLE_LOG`, `ZEALPHP_DEBUG_LOG`, `ZEALPHP_LOG_DIR`, `ZEALPHP_LOG_FILE`, `ZEALPHP_LOG_ASYNC`, `ZEALPHP_BENCH_MODE`, `ZEALPHP_MAX_CONN`, `ZEALPHP_MAX_COROUTINE`, `ZEALPHP_BACKLOG`, `ZEALPHP_REACTOR_NUM`, `ZEALPHP_DEMO_MIDDLEWARE`, `ZEALPHP_DAEMONIZE`, `ZEALPHP_PID_FILE`, `ZEALPHP_SITE_HOST`, and the per-stream log file variants. Fixes the wrong `ZEALPHP_TASK_WORKERS` default (was documented as `0`, actual default is `8`). Fixes the `ZEALPHP_DEBUG=0` typo to `ZEALPHP_DEBUG_LOG=0` in the production checklist. Bumps the Docker image tag in the compose example to current version.
- **`template/pages/migration.php`** — replaces `G->response_headers_list` reference with `$response->headersList` (the v0.2.6 move from `RequestContext` to `Response`). Notes the v0.2.5 CRLF/NUL rejection and v0.2.7 cookie char-class behavior. Updates the rung-4 description to mention `RequestContext::instance()` as canonical.
- **`template/pages/sessions.php`** — notes the v0.2.6 `G` → `RequestContext` rename (with `class_alias` for backward compat). New "What else gets reset per request" section covering handler-stack reset (coroutine mode automatic, superglobals mode fixed in v0.2.10).
- **`template/pages/middleware.php`** — `SessionStartMiddleware` and `IniIsolationMiddleware` added to the built-in middleware table (both were missing from the docs).
- **`README.md`** — removed reference to deleted `prefork_request_handler()`, updated to reference `RequestContext` as canonical with `G` alias note.

## [0.2.10] - 2026-05-16

The discipline-contract sprint. Triggered by a Reddit comment articulating that per-coroutine isolation only covers framework-managed state — user-level `static $x` lives in worker process memory and survives every coroutine boundary. The trust story for long-running PHP is **isolation + recycling, not either alone** (Hyperf and RoadRunner ship the same pattern). v0.2.10 closes the *visibility* gap on this contract and adds first-class tools so users don't have to reach for `static $cache` in the first place.

See [CRITIC.md](CRITIC.md) for the full retrospective of the public review that drove v0.2.4 → v0.2.10.

### Added
- **`RequestContext::once($key, $fn)` / `has($key)` / `forget($key)`** — request-scoped memoization helper. Computes `$fn()` once per request, caches on the per-coroutine `RequestContext`, returns the cached value on subsequent calls. Mirrors Laravel 11's `once()` helper. Use this anywhere you'd reach for `static $cache = []` for request-scoped data — gives you the same shape without leaking into worker process memory. The cache is freed automatically when the coroutine ends.
- **Worker-recycle access log** — when a worker exits (for any reason: `max_request` hit, graceful shutdown, admin reload, OOM), the server now logs `[recycle] worker N exited after K requests, peak RSS X MB, uptime Ys`. Makes the `max_request` backstop *visible* in production logs. Silence with `ZEALPHP_RECYCLE_LOG=0`.
- **`IniIsolationMiddleware`** (opt-in) — snapshots a curated list of common per-request mutation targets (`date.timezone`, `error_reporting`, `display_errors`, `memory_limit`, etc.) at request start and restores changed values at the end. Long-running PHP doesn't reset `ini_set()` between requests; this middleware does. Enable via `ZEALPHP_INI_ISOLATE=1` env var, or register explicitly: `$app->addMiddleware(new IniIsolationMiddleware())`. Custom key list supported via constructor argument.
- **Coroutine safety matrix + discipline contract docs** — substantial new section on `/coroutines` documenting what's isolated per coroutine (typed `RequestContext` fields), what isn't (`static` in user code, class statics, `Store`/`Counter`, captured closures in `App::tick`/`onWorkerStart`, `ini_set` mutations), the discipline contract, and the worker recycling backstop. Per-mode safety table comparing coroutine vs superglobals modes.
- **`Store` consistency semantics docs** — new section on `/store` documenting what's atomic (single `set()` calls, `incr`/`decr`, `compareAndSet`), what isn't (multi-`set()` updates to the same row), and the SIGKILL hazard (worker hard-kill mid-write may leave a row spinlock held; graceful shutdown including `max_request` recycle releases cleanly). "Best-effort cache, not a database."
- **Production OPcache tuning section** in `docs/deployment.md` — concrete `php.ini` recommendations for long-running workers (`opcache.validate_timestamps=0` + restart-on-deploy), with the rationale and the failure mode (stale bytecode after deploys looks like a logic bug).
- **CRITIC.md** — retrospective log of every public technical review and what we shipped in response across v0.2.4–v0.2.10. Internal learning document.

### Fixed
- **Error/exception/shutdown handler stacks accumulated across requests in superglobals mode** — `$g->error_handlers_stack`, `$g->exception_handlers_stack`, `$g->shutdown_functions` live on the process-wide singleton in superglobals mode. Legacy code that calls `set_error_handler()` per request without `restore_error_handler()` would grow the handler chain until the worker recycled. `SessionManager::__invoke` now resets these stacks at request entry (matching `CoSessionManager`'s coroutine-mode-by-default behavior). Coroutine mode was already safe — these stacks live on the per-coroutine `RequestContext` and die with the coroutine.

## [0.2.8] - 2026-05-15

### Fixed
- **PHPStan static-analysis CI failure** — after the v0.2.6 rename of `G` → `RequestContext` (with `class_alias` for runtime backward compat), PHPStan reported 90 "Call to static method instance() on an unknown class ZealPHP\G" errors because static analysis doesn't follow runtime `class_alias`. Framework-internal references are now migrated from `G::` to `RequestContext::` across `src/` (97 call sites across 18 files). The `class_alias(RequestContext::class, 'ZealPHP\\G')` registration remains untouched — user code referencing `\ZealPHP\G` or `use ZealPHP\G;` continues to work exactly as before. CI is green again at level 1 with 0 errors.

## [0.2.7] - 2026-05-15

### Fixed
- **`setrawcookie()` was over-strict** — v0.2.5's CRLF/NUL injection guard incorrectly rejected `,`, `;`, ` `, `\t`, `\013`, `\014` in raw cookie values. PHP native `setrawcookie` only rejects `\r\n\0` in the value (the response-splitting vector); the rest are legal cookie-octets that callers explicitly use the "raw" variant to pass through unchanged. The filter is now relaxed to match PHP's actual behavior. Caught by the existing `tests/Integration/ApacheParityTest::testSetRawCookieDoesNotUrlEncode` regression test (which was failing under v0.2.5/v0.2.6).

## [0.2.6] - 2026-05-15

### Changed
- **`G` renamed to `RequestContext`** — `\ZealPHP\RequestContext` is now the canonical name for what was previously `\ZealPHP\G`. The old name `\ZealPHP\G` remains available via `class_alias` for backward compatibility; existing code that references `G::instance()` or types against `\ZealPHP\G` keeps working unchanged. Source-level rename addresses the long-standing critique that the single-letter name signaled nothing about purpose.
- **Response state moved off `G` onto `Response`.** `$g->response_headers_list`, `$g->response_cookies_list`, and `$g->response_rawcookies_list` no longer exist on `G`. They live on the Response object as `$response->headersList`, `$response->cookiesList`, and `$response->rawCookiesList`. Framework internals updated. **External code that read these properties directly must migrate to `$g->zealphp_response->headersList` etc.** — the uopz `header()` / `setcookie()` overrides and the `header_remove()` / `response_headers_list()` / `apache_response_headers()` helpers continue to work unchanged.
- **Legacy Apache shim state moved off `G` onto `ZealPHP\Legacy\ApacheContext`.** `$g->apache_env` and `$g->apache_notes` no longer exist on `G`. The `apache_setenv()` / `apache_getenv()` / `apache_note()` shim functions now lazy-allocate `$g->apacheContext` (a `ZealPHP\Legacy\ApacheContext` instance) and read/write its `env` and `notes` arrays. Only matters for legacy code running through the CGI bridge.

### Removed
- **`#[AllowDynamicProperties]` attribute on `RequestContext`** — the three previously-dynamic properties (`cache_expire`, `cache_limiter`, `session_module_name`) are now declared as typed properties. Undeclared writes in coroutine mode now throw `BadMethodCallException` (catches typos like `$g->zealphp_reqeust = ...` that previously silently created a dynamic property). Superglobals mode keeps the `$GLOBALS[$key]` bridge for legacy compatibility.
- **`prefork_request_handler()` deleted** — predecessor to the CGI bridge (`App::includeFile()` / `src/cgi_worker.php`), unused since the bridge landed. Zero callers in framework, scaffold, or any documented user code. The CGI bridge is now the sole "run unmodified legacy PHP in a child process" path.

### Fixed
- **Return-by-reference autovivification on coroutine-mode `__get`.** `&$g->nonexistent` used to create a dynamic property on first read; now returns a reference to a local null without mutating state. Bounded blast (per-coroutine context) but the behavior was a footgun.
- **`debug_backtrace()` removed from `RequestContext::instance()`.** Was firing on first-instance creation per worker in superglobals mode, emitting an `elog` line with the call site. Cosmetic dev tracing, not a hot path, but unnecessary in production.
- **Redundant `isset($g->session)` check in `CoSessionManager`.** `session` is a declared typed property with default `[]` — always set. The outer `isset` was always true; only the inner `isset($g->session['__start_time'])` carried information.

## [0.2.5] - 2026-05-15

### Security
- **HTTP response splitting via `header()` override (high severity).** The uopz `header()` override did not reject `\r\n` / `\0` in header values, breaking the protection PHP native `header()` has provided since 4.4.2. Application code using `header("X-Foo: " . $userInput)` with user input containing CRLF could smuggle additional response headers (including `Set-Cookie`), enabling session fixation and cache poisoning against affected apps. **All v0.2.x releases prior to 0.2.5 are affected. Upgrade is strongly recommended.**
  - Fix: CRLF/NUL injection guards added to `header()`, `Response::header()`, `Response::redirect()`, `setcookie()`, and `setrawcookie()`.
  - Validation: matches PHP native behavior — emits `E_USER_WARNING` and returns `false` (or throws `InvalidArgumentException` for `redirect()`).
  - Cookie name char-class rules now match PHP native `setcookie`: `=,; \t\r\n\013\014\0` rejected.
  - 9 new regression tests in `tests/Unit/SecurityTest.php` covering each entry point.

## [0.2.4] - 2026-05-15

### Added
- **`max_request=100000` default** — worker recycling now enabled out of the box, bounding memory growth from long-running PHP workers (static caches, closure captures, leaky extensions). After 100k requests a worker exits cleanly and is respawned with a fresh PHP arena. Override via `ZEALPHP_MAX_REQUEST` env var or `$app->run(['max_request' => N])`. Set `0` to disable.
- **`ZEALPHP_MAX_REQUEST` env var** — documented in both `docs/deployment.md` and `template/pages/deployment.php`.

### Changed
- **Scaffold (`sibidharan/zealphp-project`) defaults to coroutine mode** — `composer create-project` now ships `app.php` with `App::superglobals(false)` explicitly set. Aligns the scaffold's default with the documented "recommended for new projects" stance. Per-request state is isolated via `Coroutine::getContext()`, eliminating the worker-state-leak class of issues for greenfield apps. Framework default (`App::$superglobals = true`) is **unchanged** for backward compatibility with existing apps; flip to `App::superglobals(true)` only when migrating unmodified legacy code that needs `$_GET`/`$_POST`/`$_SESSION` access.

## [0.2.3] - 2026-05-15

### Added
- **`SessionStartMiddleware`** — new PSR-15 middleware that eagerly starts sessions for first-time visitors. Fixes session-dependent features (counters, flash messages) silently failing on first request.
- **Lesson 5: "React vs PHP"** — new lesson comparing React+Node stack vs ZealPHP+htmx with Mermaid diagrams, comparison table, and deep dives. Positions ZealPHP as frontend-agnostic.
- **Mermaid.js diagrams** — interactive architecture diagrams in lessons 1, 5, 9, 10, 11 with click-to-expand fullscreen viewer (pinch zoom, scroll pan, trackpad-friendly).
- **AI agent HTTP API architecture** — Python agent now calls ZealPHP's HTTP endpoints with session cookie auth instead of direct SQLite. Note mutations trigger WebSocket broadcasts for live cross-tab updates.
- **Notes API JSON content negotiation** — `Accept: application/json` returns JSON; default returns HTML for htmx. New routes: `GET /api/learn/notes/{id}`, `GET /api/learn/notes/search`.
- **Event log terminal** — always-visible dark terminal on AI Chat page showing SSE (blue) and WebSocket (purple) events in real time.
- **Note card animations** — green glow on create, green flash on update, fade-out on delete. WebSocket handler skips redundant list refresh when card already exists.
- **Concept check quizzes** — inline multiple-choice questions with letter circles (A, B, C) and htmx-powered verification.
- **Inline auth error feedback** — register/login forms show errors inline via htmx (wrong password, username taken, etc.) instead of raw JSON.
- **GitHub source links** — file references in lessons link to actual source on GitHub.

### Changed
- **14-lesson tutorial** (was 13) — new "React vs PHP" lesson inserted as L5, all subsequent renumbered.
- **Pedagogical redesign** — all lessons rewritten with problem-first framing, mental models, step-by-step building, key takeaways, and challenges.
- **Lesson reorder** — htmx moved from L7→L6, routing from L5→L12, WebSocket after AI Chat. Sessions split into two lessons (Sessions + User Accounts).
- **Sidebar restructured** — 4 groups (Hello World, Interactivity, Build the App, Under the Hood) replacing the old 3-group layout.
- **Notes user bar** — avatar circle with initial letter + username replacing plain "Logged in as" text.
- **Stream demo** — increased from 5 rows to 12 rows (1.8s) for more visible streaming effect.
- **Nav label** — "Start" renamed to "Getting Started" in top navigation.

### Fixed
- **learn.css not loading on hx-boost navigation** — CSS now loaded unconditionally in `_head.php`.
- **Register/login session conflicts** — removed redundant `session_start()` + `setcookie()` that conflicted with `SessionStartMiddleware`.
- **Agent `notes_changed` event never emitted** — tool_call item.id vs tool_call_output call_id mismatch fixed by storing tool names by both IDs.
- **DELETE endpoint returning `{"ok":true}`** — now returns empty for htmx, JSON only with `Accept: application/json`.
- **Chat double-scroll** — overrode zealphp.css `chat-messages` overflow that created a second scrollable area.
- **Subtitle `&mdash;` not rendering** — use literal em dash character since `htmlspecialchars()` double-escapes HTML entities.

## [0.2.2] - 2026-05-15

### Added
- **`/learn` tutorial section** — 13-lesson guided tutorial that builds a working Notes + AI Chat app. Covers routing, components, sessions, htmx, SQLite, SSE streaming, WebSocket, and async coroutines.
- **`src/Learn/` namespace** — 6 autoloaded classes (Auth, Chat, ChatHistory, DB, Notes, WS) demonstrating proper OOP architecture.
- **8 ZealAPI endpoint files** (`api/learn/`) — register, login, logout, notes, chat, chat_status, chat_history, page.
- **Python notes agent** (`examples/agents/notes_agent.py`) — OpenAI Agents SDK with 6 function tools, SQLite-backed, SSE-streamed through PHP.
- **htmx site-wide** — `hx-boost="true"` on `<body>` for instant navigation; htmx page swap for lesson sidebar.
- **WebSocket cross-tab sync** — `App::ws('/ws/learn')` with Store-backed fd→user_id mapping and broadcast helper.
- **Chat history persistence** — SQLite `chat_history` table with ZealAPI history endpoint using `App::renderToString`.
- **24 new tests** — 16 unit (auth, notes, chat history) + 8 integration (session persistence, CRUD, user isolation, SSE consecutive).
- **Coding standards** — PSR-2, separation of concerns, OOP rules codified in CLAUDE.md and docs.
- **Cache-busting asset URLs** — `?v=<git-describe>` on all local CSS/JS.

### Fixed
- **WebSocket session support** (`src/App.php`) — `onOpen` now populates `$g->session` from the upgrade request's PHPSESSID cookie.
- **ZealAPI SSE streaming** (`src/ZealAPI.php`) — skip `ob_get_clean()` + `new Response()` when handler already sent a streaming response (`$g->_streaming` check).
- **`$_SESSION` vs `$g->session`** — all learn code uses `$g->session` (coroutine-safe); documented the gotcha in CLAUDE.md.
- **Session write on first registration** — explicit `setcookie()` + `session_write_close()` for new sessions (CoSessionManager only auto-writes when the request already had a cookie).
- **Auth::currentUser() DB verification** — checks user still exists in SQLite (stale sessions after DB wipe no longer crash with FK violations).
- **Streaming HTML token rendering** — accumulate-and-re-render pattern for partial HTML tags from character-by-character model output.
- **highlight.js after htmx swap** — `htmx:afterSettle` instead of `htmx:afterSwap` (outerHTML replaces the target, old element is detached).

### Changed
- **htmx loaded globally** (was learn-only) — enables `hx-boost` site-wide.
- **`scroll-behavior: smooth` removed globally** — htmx boost makes navigation instant; smooth scroll caused jank on lesson swaps.
- **`scrollbar-gutter: stable`** on html — prevents layout shift when scrollbar appears/disappears.

## [0.2.1] - 2026-05-14

### Added
- **Apache + mod_php parity** — comprehensive PHP-FPM-equivalent behavior: uopz overrides for session/header/cookie semantics, `public/` file routing with `.htaccess`-style fallback, error handler stack isolation, content negotiation. Six new integration test suites lock it in: `ApacheParityTest`, `ContentNegotiationTest`, `ErrorHandlersIsolationTest`, `ErrorHandlingTest`, `FallbackTest`, `PublicRoutingTest`.
- **Dedicated `/migration` page** — 5-rung migration ladder (drop-in → LAMP-style → ZealAPI → framework routes → full coroutine mode), before/after stack collapse, dedicated framing of the migration story.
- **Dedicated `/performance` page** — full Ryzen 9 7900X benchmark detail, methodology, framework-efficiency comparison.
- **Dedicated `/responses` page** — return convention reference.
- **One-line install** — `bash <(curl /install.sh)` serves `setup.sh` directly from the framework, hardened for piped execution.
- **`SecurityTest` unit suite** + PHP 8.4 added to CI matrix.
- **★ N GitHub** live star count in the sitewide navbar (client-side fetch from `api.github.com`, silent fallback when rate-limited).
- **Electric hero wordmark** — bigger size, ⚡ glyph, one-time amber lightning sweep on load (pure CSS, respects `prefers-reduced-motion`).

### Changed
- **UX labels** — "Templates" → "Components", "ZealAPI" → "REST API" in nav and feature cards. URLs `/templates` and `/api` unchanged; class `ZealAPI` still referenced in body copy where it's the actual class name.
- **Nav structure** — REST API and Legacy Apps promoted to the top row; small vertical padding so the two-row nav breathes.
- **AI Config Converter** — mode-A delegation, framework detection, broader rewrite coverage (htaccess/nginx → `app.php`).
- **`/routing` on-ramp claim** — name the superglobals-mode trade-off honestly instead of asserting "no rewrite needed."
- **`/why-zealphp`** — clarified OpenSwoole 26 + Fibers compatibility (internal `zend_fiber` context backend ≠ AMPHP/Revolt library portability).
- **Homepage** — 11-badge block removed (duplicated the README), live config converter pulled off the homepage, narrative bridge added between code demo and benchmark numbers.
- **Alpha banner** — solid amber background with dark text, non-dismissable, DeepWiki CTA inline; sets honest "v0.2.x = alpha" expectations sitewide.
- **README "Why" section** — leads with the mission, not the problem.
- **Benchmark numbers updated** — fresh Ryzen 9 7900X isolated runs (117k req/s text, 106k JSON, 50k template, 0 failures at c=200 / `-k` / 4 workers) replace v0.2.0's mixed container+Ryzen numbers.

### Fixed
- **ZealAPI infinite loop on undefined method** — calling `$this->X()` on a non-existent method used to recurse on `__call` until stack overflow. Now returns 404 with a structured error and a `did_you_mean` hint computed via levenshtein.
- **ZealAPI route order, 308 redirects, CLI stop, pid-file handling.**
- **`php app.php restart`** — now prints `Restarted (pid X, port Y)` instead of finishing silently.
- **Buttons on `.section-dark` backgrounds** — `.btn-primary` was invisible because the section-dark anchor recolor was overriding the button text color. Fixed by scoping the recolor to `a:not(.btn)`.
- **`/performance` page** was unreadable on the default light theme.
- **Alpha banner** color combo (solid amber bg + dark text) for readability.
- **Code-label readability** — killed all-caps, darkened, switched to mono.
- **PHPStan baseline cleared** — real bugs fixed, stub mismatches suppressed cleanly.
- **AI streaming hero card** — gap between the card and the bench-method bar (was visually touching).

### Documentation
- **PERF.md reproduction recipes** — three documented recipes + variance reading guide.
- **Deployment, WebSocket, Streaming guides** added; macOS install path included.
- **HN-launch de-hype pass** — neutral copy, methodology disclosure, alpha banner sitewide.
- **ZealAPI error responses + live `undefined_method` demo** documented on `/api`.

## [0.2.0] - 2026-05-14

### Added
- **HTTP Range requests (RFC 7233)** — `RangeMiddleware` with single/multi-range support and `416 Range Not Satisfiable`; `If-Range` ETag validation.
- **`$response->sendFile()`** — zero-copy file serving with Range support.
- **PSR-3 Logger** implementation (`ZealPHP\Logger`) with `TestableLogger` helper.
- **PSR-16 SimpleCache adapter** (`SimpleCacheAdapter`) over the tiered `Cache`.
- **PSR-17 HTTP factories** — Request, Response, Stream, Uri, ServerRequest, UploadedFile.
- **PSR-18 HTTP Client** (`ZealPHP\HTTP\Client`).
- **Tiered `Cache`** — memory tier (OpenSwoole `Table`) + file-tier spill; `Cache::stats()` for cross-worker hit/miss/spill counters.
- **`App::renderStream()`** — streaming templates with reflection-based param injection; `yield from` supported in public files and API handlers.
- **AI chat SSE demo** — `/ai/chat` endpoint with thread support and OpenAI Agents SDK integration.
- **AI config converter** — nginx/Apache → ZealPHP translation with split-view SSE streaming.
- **CGI worker SSE streaming**, `setrawcookie`/`header_remove`/`headers_sent` capture, `--help` output.
- **WordPress showcase repo** (`sibidharan/zealphp-wordpress`).
- **PHPStan static analysis** at level 1 (`phpstan.neon` + baseline) wired into CI.
- **OSS community files** — `CODE_OF_CONDUCT.md` (Contributor Covenant v2.1), `SUPPORT.md`, `.github/FUNDING.yml`, YAML issue templates.
- **Examples directory** — `hello-world`, `websocket-chat`, `streaming-sse` (each with `composer.json` + README).
- **Docker quickstart** in README + `docker compose up app` path.
- **ASCII architecture diagram** in README.
- Explicit `ext-openswoole` and `ext-uopz` Composer requirements.

### Changed
- **Composer PHP constraint** widened from `~8.3.0` to `^8.3` (PHP 8.4 and 8.5 now supported).
- **`openswoole/core`** constraint widened to `^22.1.5`.
- **G class** declares hot properties to bypass `__get`/`__set` magic (perf).
- **Sessions** are lazy-initialized; reflection cached per route at registration.
- **ETag middleware** switched to `xxh3` hash.
- **ResponseMiddleware** skips `ob_get_clean()` for typed returns (int, array, object, Generator).
- **Session cookies** default to `httponly: true`, `samesite: Lax`, with HTTPS auto-detection for `secure` (override via `ZEALPHP_SESSION_SECURE`).
- **Session ID regeneration** uses `bin2hex(random_bytes(32))` (was `uniqid('', true)`).
- **Session directory permissions** tightened from `0777` to `0700`.
- **CI workflow** split into parallel jobs: validate, static-analysis, phpunit.
- Homepage redesigned around AI runtime positioning, architecture comparison, and live chat demo.

### Fixed
- `unserialize()` calls in session and cache paths now pass `allowed_classes => false`; CGI worker uses an exception-class whitelist (prevents PHP object injection).
- **ZealAPI** validates module/request path components against a strict regex and uses `realpath()` containment (prevents path traversal).
- **`Response::redirect()`** throws on `javascript:`, `data:`, `vbscript:` schemes and warns on cross-origin and protocol-relative redirects.
- **CGI worker** filters child-process environment to an `HTTP_/REQUEST_/SERVER_/...` prefix whitelist instead of leaking the entire request server array.
- Navbar active pill no longer touches the navbar bottom border (symmetric `.nav-row-features` padding).
- RenderStream test warnings eliminated.

### Security
- Session, cache, and CGI deserialization paths are now safe by default against PHP object injection.
- File-based API dispatcher (ZealAPI) is no longer reachable via path-traversal URLs.
- Session cookies are `HttpOnly` and (on HTTPS) `Secure` by default.
- Session IDs use a CSPRNG.

## [0.1.1] - 2026-05-13

### Added
- Detached ZealPHP runner with PID-file management, background mode, status checks, and log tailing.
- Dedicated getting-started page and refreshed the homepage quick-start flow for the starter project and framework repo.

### Changed
- Moved request, debug, access, and server logs off the terminal and into `/tmp/zealphp` by default.
- Tightened the benchmark path so the release can report leaner OpenSwoole numbers without demo middleware noise.

## [0.1.0] - 2025-10-14

### Added
- OpenSwoole powered `App` runtime with configurable superglobal reconstruction and PSR-15 middleware support.
- File-based `ZealAPI` router that dynamically loads handlers from `api/` with automatic request, response, and app injection.
- `prefork_request_handler`, `coprocess`, and `coproc` helpers for isolating blocking work in worker processes while preserving response metadata.
- IO stream wrapper, session utilities, and examples that enable streaming HTML responses, implicit routing, and reusable application scaffolding.

### Changed
- Wrapped PHP's session, header, and cookie APIs with `uopz` so ZealPHP can virtualize global state for each OpenSwoole request.
