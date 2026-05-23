# ZealPHP — Everything Shipped Since v0.2.38

This document is the **single try-it-all guide** for the work that landed
on `release/v0.2.39` after `v0.2.38` was tagged. Use it as both a
catalogue of new capabilities and a step-by-step recipe to exercise each
feature locally.

If you only have time to read one thing in this branch, read this file.

---

## Where everything is documented

| Document | Lives at | What it covers |
|---|---|---|
| **This file** | `docs/CHANGES-SINCE-v0.2.38.md` | Single-page try-it-all guide (you are here) |
| Architecture review | `docs/architecture/2026-05-23-redis-backend-review.md` | Senior-eng review of the Redis backend surface — 13 risks identified, all closed |
| v0.3.0 roadmap | `docs/architecture/2026-05-23-v0.3.0-roadmap.md` | What's NEXT — Phase 1 + Phase 2 + intentionally-deferred items |
| Hardening plan | `docs/superpowers/plans/2026-05-23-redis-backend-hardening.md` | The 13-item C+H plan that drove the hardening pass |
| Pub/sub impl spec | `docs/superpowers/plans/2026-05-23-store-pubsub-impl.md` | Phase 3 design for cross-node pub/sub |
| Redis Phase 1 plan | `docs/superpowers/plans/2026-05-23-store-redis-phase-1.md` | The original v0.2.39 backend-pluggable plan |
| Learn-chain audit | `docs/superpowers/plans/2026-05-23-learn-chain-audit.md` | Lesson cross-reference correctness check |
| Cross-server WS lesson plan | `docs/superpowers/plans/2026-05-23-learn-cross-server-chat.md` | Plan that became `learn/cross-server-chat` |
| User-facing portfolio | `template/pages/store.php#production-hardening` | What the deployed site shows users |
| Internal AI reference | `.claude/CLAUDE.md` | What AI assistants see when working on this branch |
| The lesson chain | `template/pages/learn/*.php` | 25 lessons; the new one is `learn/cross-server-chat` |

---

## Quick start — try every new feature in 5 minutes

```bash
# 1. Boot a Valkey on :16379 (or skip if you already have Redis)
valkey-server --bind 127.0.0.1 --port 16379 --daemonize yes \
              --dir /tmp/valkey-tryout --pidfile /tmp/valkey-tryout.pid \
              --protected-mode no &
mkdir -p /tmp/valkey-tryout

# 2. Boot ZealPHP with Redis backend
cd /var/labsstorage/home/sibidharan/zealphp
ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://127.0.0.1:16379 \
  php app.php start -p 8090 -d

# 3. Hit the demo endpoints (everything below renders + works)
curl http://localhost:8090/store              # Backend triangle docs + tiered + cache asymmetry
curl http://localhost:8090/pubsub             # Cross-node pub/sub portfolio page
curl http://localhost:8090/ws                 # WS routing under load
curl http://localhost:8090/learn/cross-server-chat  # The new lesson

# 4. Cross-host? Mount the same code on a second machine + point at this Valkey
ssh other-host 'cd ~/zealphp; ZEALPHP_STORE_BACKEND=redis \
  ZEALPHP_REDIS_URL=redis://YOUR_HOST_IP:16379 \
  php app.php start -p 8090 -d'
# Now publish from one side, watch the other receive:
curl 'http://localhost:8090/demo/pubsub/publish?channel=demo:pubsub&msg=hi'
curl 'http://other-host:8090/demo/pubsub/log'
```

---

## v0.2.39 — Pluggable Store/Counter backends

Default behaviour is unchanged. Apps that don't touch the backend stay on `OpenSwoole\Table` / `Atomic`. Flip the backend with one line OR an env var.

### Try it — Table → Redis swap

```php
use ZealPHP\Store;
use ZealPHP\Store\StoreBackendKind;

// In app.php — BEFORE App::init()
Store::defaultBackend(StoreBackendKind::Redis, 'redis://cache:6379/0');

// OR via env (no code change):
ZEALPHP_STORE_BACKEND=redis php app.php
ZEALPHP_REDIS_URL=redis://cache:6379/0 php app.php
```

Every existing `Store::set/get/incr/count/iterate/mget/mset` works unchanged.

### Code paths to read
- Facade: `src/Store.php`, `src/Counter.php`
- Backend interface: `src/Store/StoreBackend.php`
- Implementations: `src/Store/TableBackend.php`, `src/Store/RedisBackend.php`
- Client lib: `src/Store/RedisClient.php` (phpredis preferred, predis fallback)
- Connection pool: `src/Store/RedisConnectionPool.php` (per-worker, channel-based)

### Tests to run
```bash
./vendor/bin/phpunit tests/Unit/StoreTest.php tests/Unit/CounterTest.php tests/Unit/Store/
ZEALPHP_REDIS_URL=redis://127.0.0.1:16379 ./vendor/bin/phpunit tests/Integration/StoreBackendIntegrationTest.php
```

---

## v0.2.40 — Pub/sub + Streams + Tiered + WSRouter

The cross-node primitives + `TieredBackend` (L1+L2) + `WSRouter` (cross-server WS routing).

### Try it — fire-and-forget pub/sub

```php
// app.php boot
App::onPubSub('alerts:critical', function (string $payload, string $channel) {
    error_log("ALERT on $channel: $payload");
});

// In a route handler:
$count = Store::publish('alerts:critical', json_encode(['err' => 'db-down']));
// Returns the receivers Redis delivered to (1 per subscribed worker × cluster).
```

### Try it — reliable Streams (at-least-once)

```php
App::onReliableMessage('orders', function (string $payload, string $id, string $stream): bool {
    $ok = processOrder(json_decode($payload, true));
    return $ok;  // true → XACK; false/throw → leave pending, retried
});

// Publish — durable, AOF-backed:
$msgId = Store::publishReliable('orders', json_encode($order));
```

### Try it — Tiered backend (L1 Table + L2 Redis)

```php
Store::defaultBackend(StoreBackendKind::Tiered, [
    'url'                 => 'redis://cache:6379',
    'l1_ttl'              => 5,                              // L1 freshness window (seconds)
    'invalidation_secret' => getenv('ZEALPHP_TIERED_INVALIDATION_SECRET') ?: null,
]);
// Hot keys: ns reads from L1. Cold keys: ms reads from L2 + populate L1.
// Writes write-through L2; HMAC-signed cross-node L1 invalidation.
```

### Try it — cross-server WebSocket routing

```php
use ZealPHP\WSRouter;

// app.php
Store::defaultBackend(StoreBackendKind::Redis);
WSRouter::init('node-A');  // or auto: hostname:pid

$app->ws('/chat',
    onMessage: function ($server, $frame) { /* … */ },
    onOpen:    fn($server, $request) => WSRouter::own($request->get['user'], $request->fd),
    onClose:   fn($server, $fd, $reactorId) => WSRouter::release($_GET['user'] ?? ''),
);

// Anywhere, regardless of which server holds the client:
WSRouter::sendToClient('alice', json_encode(['hi' => 'world']));

// Or fan-out to a channel:
WSRouter::broadcast('chat:room:42', json_encode($msg));
```

### Code paths to read
- Pub/sub runner: `src/Store/RedisPubSub.php`
- Streams runner: `src/Store/RedisStreams.php`
- Tiered: `src/Store/TieredBackend.php`
- WSRouter: `src/WSRouter.php`
- App-level facade: `App::onPubSub()`, `App::onReliableMessage()` in `src/App.php`

### Demo endpoints
- `GET /demo/pubsub/publish?channel=demo:pubsub&msg=hi` — fire a publish
- `GET /demo/pubsub/publish-reliable?stream=orders&msg=...` — Streams variant
- `GET /demo/pubsub/log` — see what handlers received

### Tests to run
```bash
./vendor/bin/phpunit tests/Unit/Store/RedisPubSubTest.php tests/Unit/Store/RedisStreamsTest.php
./vendor/bin/phpunit tests/Unit/Store/TieredBackendTest.php tests/Unit/WSRouterTest.php
```

---

## v0.2.41 — Hardening pass (3 critical + 10 medium)

The senior-eng review at `docs/architecture/2026-05-23-redis-backend-review.md` identified 13 risks; all closed in this branch. Default behaviour preserved across every fix (no BC break).

### Critical fixes

| ID | What | Try it |
|---|---|---|
| **C1** | `WSRouter` FD-reuse race — `conn_id` nonce | `WSRouter::own()` now returns a 16-byte hex `conn_id`. Subscriber sink verifies the nonce before pushing — prevents cross-tenant message leakage when a client disconnects ungracefully + fd is reused. |
| **C2** | HMAC-signed L1 invalidation in `TieredBackend` | `new TieredBackend($l1, $l2, invalidationSecret: getenv('ZEALPHP_TIERED_INVALIDATION_SECRET'))`. Same secret on every node; peers verify before evicting. |
| **C3** | TLS via `rediss://` | `Store::defaultBackend(StoreBackendKind::Redis, 'rediss://cache:6380/0')` — `verify_peer=true` by default. |

### Medium fixes

| ID | What | Try it |
|---|---|---|
| **H1** | `tracked + ttl>0` throws at `make()` | `Store::make('t', 1024, [...], ['mode'=>'tracked','ttl'=>60])` now throws clearly instead of silently dropping TTL. |
| **H2** | `Store::getStrict()` — null on miss | `$row = Store::getStrict('users', $id) ?? $default;` — works correctly with stored falsy values (0, '', false). |
| **H3** | Pipelined `mget`/`mset` + `UNLINK` clear | `Store::mget('cache', $keys)` is now 1 round-trip instead of N. `Store::clear()` uses UNLINK. Just call as before. |
| **H4** | Opt-in circuit breaker | `Store::defaultBackend(StoreBackendKind::Redis, ['url'=>'...', 'on_error'=>'fallback_table'])` — when Redis is down, reads degrade to a Table cache; writes throw. |
| **H5** | `Store::stats()` per-worker counters | `curl /some-route` that returns `Store::stats()` — see pool acquires, timeouts, clients created. |
| **H6** | Boot-time Redis ping advisory | Just boot with `ZEALPHP_STORE_BACKEND=redis`. If Redis is unreachable, you'll see a loud warning in master logs before workers fork. |
| **H7** | HOOK_ALL + phpredis subscriber warning | If you disable `HOOK_ALL` explicitly AND have phpredis as the resolved driver AND have pubsub handlers registered, boot warns you to either re-enable or `ZEALPHP_REDIS_PREFER=predis`. |
| **H8** | `TieredBackend::existsCached()` | `$b->existsCached('users', $id)` — returns true from L1 fast path; falls through to L2 only when L1 is stale. |
| **H9** | `PhpredisDriver::close()` debug logging | When close errors happen, they route through `elog('debug')` instead of being swallowed. Set `ZEALPHP_DEBUG=1` to see. |
| **H10** | `RedisPubSub` configurable max-retries | `new RedisPubSub($url, $prefix, maxAttempts: 5)` — gives up loudly after 5 reconnect cycles. Default 0 = unlimited. |

### Tests to run for the hardening pass
```bash
./vendor/bin/phpunit \
  tests/Unit/Store/RedisBackendMakeValidationTest.php \
  tests/Unit/Store/RedisUrlParsingTest.php \
  tests/Unit/Store/TieredBackendExistsCachedTest.php \
  tests/Unit/Store/TieredBackendHmacTest.php \
  tests/Unit/Store/RedisPubSubMaxAttemptsTest.php \
  tests/Unit/Store/CircuitBreakerBackendTest.php \
  tests/Unit/Store/StatsTest.php \
  tests/Unit/StoreGetStrictTest.php \
  tests/Unit/StoreOnErrorOptTest.php \
  tests/Unit/AppRedisBootChecksTest.php
```

---

## v0.2.41 — Three-backend triangle + Cache helpers

### Try it — Tiered facade via `Store::defaultBackend()`

```php
Store::defaultBackend(StoreBackendKind::Tiered, [
    'url' => 'redis://cache:6379',
    'l1_ttl' => 10,
    'invalidation_secret' => 'shared-cluster-secret',
]);
```

Equivalent to the manual `new TieredBackend(new TableBackend(), new RedisBackend(...))` shape.

### Try it — `Cache::getOrCompute()` read-through helper

```php
// Before — 3 lines of boilerplate:
$users = Cache::get('users:active');
if ($users === null) { $users = DB::select(...); Cache::set('users:active', $users, ttl: 60); }

// After:
$users = Cache::getOrCompute('users:active', fn() => DB::select(...), ttl: 60);
```

Null is cached as a valid value (sentinel-based miss detection).

### Try it — `Cache::init(... ttlSeconds: ...)` for Redis-mode TTL

```php
Cache::init(maxRows: 4096, ttlSeconds: 3600);
// On Table backend: maxRows is hard cap (as before)
// On Redis backend: Store table is created with mode='ttl' for auto-expiry
// — solves the "maxRows doesn't translate to Redis" chokepoint
```

If you pass non-default `maxRows` to Redis without `ttlSeconds`, a one-line warning fires at boot telling you what to do.

### Code paths
- Cache: `src/Cache.php`
- Tiered backend wire: `src/Store.php::buildTieredBackend()`
- Enum: `src/Store/StoreBackendKind.php` (`Table`, `Redis`, `Tiered`)
- Boot-order fix: `src/App.php::init()` (env-var resolves BEFORE `app.php`'s `Store::make`)

### Tests
```bash
./vendor/bin/phpunit \
  tests/Unit/Store/StoreTieredFacadeTest.php \
  tests/Unit/CacheGetOrComputeTest.php \
  tests/Unit/CacheRedisAsymmetryTest.php \
  tests/Unit/AppBackendBootOrderTest.php
```

---

## v0.3.0 early — 5 framework helpers (no engineering debt left)

These are conceptually Phase 1 of the v0.3.0 roadmap but landed in `release/v0.2.39` because the half-cooked surface wouldn't go away on its own.

### Try it — `App::parallel` (fork-join)

```php
[$users, $posts, $stats] = App::parallel([
    fn() => DB::query('users'),
    fn() => DB::query('posts'),
    fn() => fetchStats(),
]);
// Each task runs in its own coroutine; results in input order.
// Exceptions propagate (first error wins).
// Sync-mode callers (CLI, tests) auto-wrap in Coroutine::run.
```

### Try it — `App::parallelLimit` (bounded fan-out)

```php
$results = App::parallelLimit(
    $userIds,
    fn(int $id) => Http::get("https://api.example.com/users/$id")->json(),
    concurrency: 10,
);
// At most 10 in-flight requests; rest queue. Keys preserved.
```

### Try it — `App::onSignal`

```php
App::onSignal(SIGHUP, function () {
    Config::reload();
    error_log("config reloaded");
});

App::onSignal(SIGUSR1, function () {
    file_put_contents('/tmp/zealphp-stats.json', json_encode(App::stats()));
}, workerOnly: false);

// Send: kill -HUP $masterPid  →  handler fires in master
```

### Try it — `App::stats()`

```php
$app->route('/healthz', fn() => App::stats());
// Returns: {workers: {...}, store: {...}, memory: {...}, uptime_sec: N, php: '8.3.6'}
```

### Try it — `Http::get/post/all`

```php
$r = Http::get('https://api.example.com/users', ['Authorization' => 'Bearer ...']);
if ($r->ok()) { return $r->json(); }
if ($r->failed()) { error_log("transport error: " . $r->error->getMessage()); }

// JSON body auto-encoded:
$r = Http::post('https://hooks.slack.com/...', body: ['text' => 'hi']);

// Concurrent fan-out:
$results = Http::all([
    fn() => Http::get('https://a.example.com/'),
    fn() => Http::get('https://b.example.com/'),
    fn() => Http::get('https://c.example.com/'),
]);
```

### Try it — `App::onProcess` (sidecar processes)

```php
// app.php — BEFORE App::init()
App::onProcess('log-shipper', function (\OpenSwoole\Process $p): void {
    while (true) {
        flushLogsToS3();
        usleep(60_000_000);  // 60s
    }
}, workers: 1, coroutine: true);

// Sidecar runs alongside HTTP workers; managed by OpenSwoole master.
// Respawns on graceful reload; killed on shutdown.
// `cli_set_process_title("zealphp:log-shipper")` set automatically.
// ps aux | grep zealphp → you'll see the sidecar as a separate process.
```

### Smoke-test recipe — exercise all 5 in 30 seconds

```bash
mkdir -p /tmp/zealphp-smoke/public
cat > /tmp/zealphp-smoke/app.php <<'PHP'
<?php require '/var/labsstorage/home/sibidharan/zealphp/vendor/autoload.php';
use ZealPHP\App; use ZealPHP\Http;
App::superglobals(false);
App::onProcess('heartbeat', function() {
    while (true) { file_put_contents('/tmp/zealphp-smoke/heartbeat.log', date('c')."\n", FILE_APPEND); usleep(500000); }
});
App::onSignal(SIGHUP, fn() => file_put_contents('/tmp/zealphp-smoke/sighup.log', "fired\n", FILE_APPEND));
$app = App::init('0.0.0.0', 8095);
$app->route('/p',  fn() => App::parallel([fn()=>usleep(100000)?:'a', fn()=>usleep(100000)?:'b', fn()=>usleep(100000)?:'c']));
$app->route('/s',  fn() => App::stats());
$app->route('/h',  fn() => Http::get('http://127.0.0.1:8095/s')->json());
$app->run();
PHP
php /tmp/zealphp-smoke/app.php &
sleep 3
curl http://127.0.0.1:8095/s     # App::stats
curl http://127.0.0.1:8095/p     # App::parallel
curl http://127.0.0.1:8095/h     # Http::get (self-fetch)
cat /tmp/zealphp-smoke/heartbeat.log    # App::onProcess sidecar
kill -HUP $!                              # App::onSignal
sleep 1
cat /tmp/zealphp-smoke/sighup.log
kill $!
```

### Tests
```bash
./vendor/bin/phpunit \
  tests/Unit/AppParallelTest.php \
  tests/Unit/AppOnProcessTest.php \
  tests/Unit/HttpTest.php
```

---

## v0.2.41 — New learn lesson + portfolio updates

- New lesson **`learn/cross-server-chat`** (Lesson 22) — walks from single-server WS to N-server WS routing.
- `/store#production-hardening` — 11 subsection anchors documenting the C+H hardening surface.
- `/store#backends` — three-backend triangle (Table / Redis / Tiered).
- `/store#backend-scope` — what "single process" really means.
- `/store#backend-memory` — empirical RAM math for Table sizing.
- `/store#backend-cache-asymmetry` — `Cache::init` chokepoint + `getOrCompute` recipe.
- `/learn/store` — updated to enum-first; added Tiered + getOrCompute sections.

---

## What's STILL pending

Pointed to the canonical roadmap:
[`docs/architecture/2026-05-23-v0.3.0-roadmap.md`](architecture/2026-05-23-v0.3.0-roadmap.md)

### Phase 1 (v0.3.0) — close the rest of the half-cooked surface (~7 items)

| ID | Item | Why it matters |
|---|---|---|
| **P1.1** | WebSocket rooms / channels (Phoenix-style) | First-class membership tracking, presence, room broadcasts — currently `WSRouter::onRoom` is just a thin pub/sub helper |
| **P1.2** | Reliable queues with retry/DLQ/scheduled-enqueue | Current `Store::publishReliable` is at-least-once but no consumer-side retry counts, dead-letter queue, scheduled-delay, or in-flight visibility |
| **P1.3** | Proper auth providers (OAuth, JWT, cookie-session) | Today: just framework hooks (`App::authChecker`). Need baked OAuth2 + JWT + cookie-session providers under one `Auth::current()` API |
| **P1.5** | Cluster-wide cron scheduler | `App::tick()` fires on EVERY worker; need leader-election-backed `App::onSchedule('0 3 * * *', ...)` that runs ONCE per cluster |
| **P1.6** | CSRF middleware + form helpers | No built-in CSRF; users roll their own |
| **P1.7** | Memcached handler (Cache + Session + Store) | Cache+Store pluggable backends are Table + Redis only; add Memcached for installs that don't want to bring up Redis |
| **P1.8** | `Cache::pipeline()` + `Store::pipeline()` user-facing | Store internal pipelining is done (H3); needs a public Cache/Store pipeline API for explicit batching |
| **P1.9** | Native FastCGI client migration | Current `src/Legacy/FastCgiClient.php` is hand-rolled (~260 LOC); OpenSwoole ships a native client we can swap to |
| **P1.10** | `/healthz` middleware + Prometheus exposition | `App::stats()` data is shipped; need a middleware that exposes it as `/healthz` + Prometheus format |

### Phase 2 (v0.4.0+) — bigger pieces

| ID | Item |
|---|---|
| **P2.2** | gRPC service helper on top of OpenSwoole HTTP/2 |
| **P2.3** | HTTP/2 server-push helper (`Response::pushPromise`) |
| **P2.4** | Native `Mail::send()` wrapper |
| **P2.5** | GraphQL integration (thin webonyx layer) |

### Intentionally deferred / out of scope

| Area | Why |
|---|---|
| **PDO / ORM / DB layer** | Three-way design discussion pending: ship an ORM (which?), thin helper, or punt to userland. Plus OpenSwoole 22.x doesn't hook PDO. Wait for OpenSwoole 25.x PDO hooks OR commit to an opinionated DB story. |
| **TCP/UDP non-HTTP listeners** | Niche use case (MQTT brokers, syslog receivers). Users wanting these use `OpenSwoole\Server` directly. |
| **`OpenSwoole\Coroutine\MySQL/PostgreSQL`** | Driver coverage is spotty. PDO-via-hook is the better path when it lands. |
| **`OpenSwoole\Redis\Server`** | OpenSwoole 22.x doesn't ship it. Doc page at `openswoole.com/docs/modules/swoole-redis-server` is stale. Not relevant — we're a Redis CLIENT, not server. |
| **Warm CGI worker pool** | Superseded by P1.9 (native FastCGI client). The `cgiMode('fcgi')` path is the better answer for warm-pool semantics. |

---

## How to push when ready

The branch is currently 60+ commits ahead of master, all local. To push:

```bash
git push -u origin release/v0.2.39
gh pr create --base master --head release/v0.2.39 \
    --title "release: v0.2.39 + v0.2.40 + v0.2.41 hardening + 5 v0.3.0 helpers" \
    --body "$(cat docs/CHANGES-SINCE-v0.2.38.md | head -50)"
```

Per the project's release protocol in `.claude/CLAUDE.md`, `master` is branch-protected so the PR + status checks are mandatory. After merge:

```bash
git tag -a v0.2.41 -m "Release v0.2.41 — hardening pass + Tiered + Cache helpers + 5 v0.3.0 early helpers"
git push origin v0.2.41
git push origin1 v0.2.41
```
