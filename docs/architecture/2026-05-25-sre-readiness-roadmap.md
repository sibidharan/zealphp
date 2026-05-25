# ZealPHP SRE-Readiness Roadmap

**Date:** 2026-05-25
**Status:** Decided after external SRE audit (20-point review post-HN-prep).
**Intent:** Move ZealPHP from *"fast, clever runtime with deploy docs"* to *"observable, debuggable, recoverable production system with predictable failure behavior."*
**Sister doc:** [`2026-05-23-v0.3.0-roadmap.md`](2026-05-23-v0.3.0-roadmap.md) — feature-completeness. This doc is operability.

The framework already has the right shape (long-lived HTTP server, SSE/WS/coroutines/shared memory/task workers, reverse-proxy deployment, benchmarks, explicit alpha status). The missing layer is **operational contract**.

This roadmap classifies every audit item as:

- **F** (framework code): new middleware, new env vars, new exposition format, new test harness — lands in `src/`.
- **D** (docs only): production playbook, runbook, security checklist, compatibility matrix — lands in `template/pages/` or `docs/`.
- **U** (userland w/ framework primitive): we ship the building block, app authors compose. E.g. "configure timeout" — framework exposes the knob, app sets the value.

Cross-references existing v0.3.0 roadmap items (`v0.3.0-P1.X`) where they overlap.

---

## Phase 1 — Minimum SRE Trust (v0.3.0 ship target)

The brutally simple "5 things first" from the audit. Every item here is framework code or framework-shipped middleware.

### SRE.1.1 — `/healthz`, `/readyz`, `/diagz` middleware ✅ PART-SHIPPED → F

**State today:** `App::stats()` aggregator exists (commit `bfab6f4`). No HTTP exposition.

**Three separate semantics**, not one endpoint:

- `/healthz` — *"is this process alive?"* — never checks dependencies (k8s/systemd/Traefik kill healthy apps during dependency outages otherwise). Shape: `{status, pid, role, uptime_seconds}`.
- `/readyz` — *"can this worker accept production traffic?"* — checks routes-loaded, config-valid, Store reachable, task workers reachable, required extensions loaded. Returns `503` if any check fails.
- `/diagz` — *"give me the full SRE goldmine"* — auth-protected. Returns version, PHP/OpenSwoole versions, worker count + ID, active connections + coroutines, WS clients, SSE streams, RSS/peak memory, requests-handled, requests-until-recycle, Store backend, task queue depth.

**Implementation:**
```php
$app->addMiddleware(new HealthCheckMiddleware([
    '/healthz' => HealthCheckMiddleware::liveness(),
    '/readyz'  => HealthCheckMiddleware::readiness([
        'store'        => fn() => Store::ping(),
        'task_workers' => fn() => App::stats()['task_workers']['running'] > 0,
    ]),
    '/diagz'   => HealthCheckMiddleware::diagnostics(['auth' => 'basic:admin']),
]));
```

Cross-ref: `v0.3.0-P1.10` (partial).
**Scope:** ~250 LOC + 12 tests. Lands as `src/Middleware/HealthCheckMiddleware.php`.

### SRE.1.2 — Prometheus `/metrics` exposition → F

**State today:** Per-worker `Store::stats()` exists. No HTTP exposition.

**Mandatory metric set** (every one is collectable from existing framework hooks):

| Metric | Source |
|---|---|
| `zealphp_http_requests_total` | counter wrap on every request |
| `zealphp_http_request_duration_seconds_bucket` | histogram in ResponseMiddleware |
| `zealphp_http_response_status_total{status}` | counter on response emit |
| `zealphp_http_in_flight_requests` | gauge on enter/exit |
| `zealphp_worker_memory_bytes` | `memory_get_usage()` per scrape |
| `zealphp_worker_restarts_total` | counter on `onWorkerStop` |
| `zealphp_worker_requests_total{worker_id}` | counter per request |
| `zealphp_active_coroutines` | `Coroutine::stats()['coroutine_num']` |
| `zealphp_active_connections` | `getServer()->stats()['connection_num']` |
| `zealphp_sse_streams_active` | gauge on `Response::sse()` enter/exit |
| `zealphp_websocket_connections_active` | gauge on `onOpen` / `onClose` |
| `zealphp_task_queue_depth` | `getServer()->stats()['tasking_num']` |
| `zealphp_store_operations_total{op,backend}` | from `Store::stats()` |
| `zealphp_store_errors_total{op,backend}` | from `Store::stats()` |
| `zealphp_pubsub_messages_total{channel}` | from `RedisPubSub::stats()` |
| `zealphp_pubsub_errors_total` | from `RedisPubSub::stats()` |
| `zealphp_exceptions_total{class}` | counter in framework exception handler |

**ZealPHP-specific (the spicy ones):**

| Metric | Why |
|---|---|
| `zealphp_request_context_leaks_total` | proves per-coroutine isolation is working |
| `zealphp_superglobal_reset_failures_total` | catches uopz-override edge cases |
| `zealphp_uopz_override_failures_total` | catches `uopz_set_return` rejections |
| `zealphp_blocking_operation_warnings_total` | warns when sync I/O happens in coroutine context (huge for long-running PHP) |

**Implementation:**
```php
$app->addMiddleware(new MetricsMiddleware([
    'path' => '/metrics',
    'auth' => 'basic:metrics',
    'include_zealphp_internal' => true,
]));
```

Cross-ref: `v0.3.0-P1.10`.
**Scope:** ~400 LOC + 20 tests + Prometheus exposition-format harness. Lands as `src/Middleware/MetricsMiddleware.php` + `src/Metrics/Registry.php` (counter / gauge / histogram primitives, in-process — no exporter dependency).

### SRE.1.3 — Request-ID middleware + propagation → F

**State today:** None. No `X-Request-ID` plumbing anywhere.

**Rules:**
- If `X-Request-ID` header present, preserve it (with sanity validation — alphanumeric + dashes, ≤128 chars).
- Else generate a ULID-shape `req_01HX...`.
- Return it on every response.
- Attach to `$g->request_id` so handlers can read it.
- Propagate through:
  - `elog()`/`zlog()`/`access_log()` (the existing log helpers in `src/utils.php`).
  - Exceptions (annotation via `Throwable::$traceAsString` is read-only; emit a structured log line with request_id + exception).
  - Task dispatch (`App::dispatchTaskCallback` injects request_id into the task payload).
  - Pub/sub messages (`Store::publish` accepts opt request_id parameter; subscriber sees it via the message envelope).
  - SSE/WS: outbound frames optionally carry it as a comment/system message.

**Implementation:**
```php
$app->addMiddleware(new RequestIdMiddleware([
    'header'   => 'X-Request-ID',
    'generate' => fn(): string => 'req_' . substr(bin2hex(random_bytes(16)), 0, 24),
    'propagate_to' => ['logs', 'tasks', 'pubsub'],
]));
```

**Scope:** ~150 LOC + 8 tests. Single file `src/Middleware/RequestIdMiddleware.php`. The propagation hooks land as small one-line additions in `dispatchTaskCallback`, `Store::publish`, and the existing log helpers (already accept `$context` arrays).

### SRE.1.4 — Structured JSON request logs → F

**State today:** `access_log()` writes Apache combined-log format. No structured field option.

**Target:** A JSON-mode in `App::accessLogFormat()` setter — keeps the Apache-format default for BC; opt-in via `App::accessLogFormat('json')` or `ZEALPHP_ACCESS_LOG_FORMAT=json`.

Per-request JSON shape:
```json
{
  "ts": "2026-05-25T12:40:30+00:00",
  "level": "info",
  "event": "http_request",
  "request_id": "req_01HX...",
  "method": "POST",
  "path": "/api/chat",
  "route": "/api/chat",
  "status": 200,
  "duration_ms": 182.41,
  "bytes_in": 812,
  "bytes_out": 9021,
  "worker_id": 4,
  "coroutine_id": 10928,
  "memory_peak_mb": 88,
  "client_ip": "10.0.0.21",
  "user_agent": "Mozilla/5.0"
}
```

Exception JSON shape — emitted by the framework's existing exception handler:
```json
{
  "ts": "...",
  "level": "error",
  "event": "exception",
  "request_id": "req_01HX...",
  "exception": "MongoDB\\Driver\\Exception\\ConnectionTimeoutException",
  "message": "server selection timeout",
  "file": "/app/src/UserRepo.php",
  "line": 91,
  "worker_id": 4
}
```

Lifecycle events (also JSON when `format=json`): `worker_started`, `worker_stopping`, `worker_recycled`, `worker_memory_threshold_crossed`, `task_worker_started`, `task_worker_failed`, `websocket_open`, `websocket_close`, `sse_open`, `sse_close`, `redis_disconnected`, `redis_reconnected`.

**Scope:** ~200 LOC + 6 tests. Lands in `src/Logging/JsonFormatter.php` + hooks into the existing `access_log` / `elog` / `zlog` helpers + `App::run()` lifecycle emission points.

### SRE.1.5 — Worker recycle by RSS memory → F

**State today:** Worker recycle by request count exists (`ZEALPHP_MAX_REQUEST`, default 100k). No memory-based recycle, no idle recycle, no jitter.

**Target:** Expand the recycle policy:

```php
App::workerRecyclePolicy([
    'max_requests'      => 10000,
    'max_rss_mb'        => 512,
    'memory_grace_mb'   => 64,
    'recycle_jitter'    => 20,
    'recycle_on_fatal'  => true,
]);
```

Env-var equivalents: `ZEALPHP_MAX_WORKER_RSS_MB`, `ZEALPHP_WORKER_MEMORY_GRACE_MB`, `ZEALPHP_WORKER_RECYCLE_JITTER`.

The check runs in the `onRequest` post-handler tail or via `App::tick(30000)`. Emits a structured log line + metric (`zealphp_worker_recycle_reason_total{reason="rss_exceeded"}`).

Jitter matters: without it, all workers may recycle together like a distributed clown parade.

Also adds per-route memory-delta metrics so leak hunting is data-driven:
```
zealphp_route_memory_delta_bytes{route="/api/chat"} 1048576
```

**Scope:** ~180 LOC + 8 tests (incl. uopz-overridden `memory_get_usage` for deterministic recycle). Lands in `src/App.php` worker lifecycle block + `src/Lifecycle/RecyclePolicy.php`.

### SRE.1.6 — Coroutine-safety stress test suite → F

**State today:** Some isolation tests exist (`SuperglobalsParityTest`). No concurrent stress harness proving 1000-coroutines isolation under realistic load.

**Target:** A test suite that proves the uopz override + per-coroutine RequestContext model holds up under concurrency. Lives in `tests/Integration/CoroutineIsolationStressTest.php`. Patterns from the audit:

```php
$app->route('/isolation/{id}', function($id) {
    header("X-Test-ID: $id");
    $_SESSION['id'] = $id;
    usleep(random_int(1000, 50000));
    return [
        'id' => $id,
        'session' => $_SESSION['id'],
        'header' => "X-Test-ID: $id"
    ];
});
```

Test fires 1000 concurrent requests against `/isolation/{id}` with distinct IDs, asserts no cross-contamination on any of: response header, session value, body, exception context, output buffer. Re-runs with cookies, with `$_GET`/`$_POST` writes, with `http_response_code()` calls, with deliberate throws.

The metric `zealphp_request_context_leaks_total` (from SRE.1.2) MUST stay at 0 across the full suite. CI gate.

**Scope:** ~400 LOC test harness + a small `bin/coroutine-isolation-bench.php` runner that ships with the framework for users to verify in their own deploys.

---

## Phase 2 — Long-running Runtime Safety (v0.3.1 ship target)

### SRE.2.1 — Graceful shutdown contract → F + D

**State today:** `onWorkerStop` hook exists (commit history shows it sweeps WSRouter, etc.). No documented timeout contract, no SSE drain, no body-in-flight wait.

**Target — config:**
```bash
ZEALPHP_GRACEFUL_SHUTDOWN=true
ZEALPHP_SHUTDOWN_TIMEOUT=30
ZEALPHP_SSE_DRAIN_TIMEOUT=20
ZEALPHP_WS_DRAIN_TIMEOUT=10
ZEALPHP_TASK_DRAIN_TIMEOUT=60
```

**Target — behavior** (documented in `template/pages/deployment.php`):
1. On SIGTERM: stop accepting new HTTP requests.
2. Existing short HTTP requests finish until `SHUTDOWN_TIMEOUT`.
3. SSE streams get `SSE_DRAIN_TIMEOUT` to finish naturally; force-close after.
4. WebSocket connections receive close-1001 (Going Away) with `WS_DRAIN_TIMEOUT` grace.
5. Task workers finish in-flight tasks (or mark unfinished as retryable via `Queue::nack`).
6. Store / pub/sub clients close cleanly.
7. Exit code 0 on graceful, 137 if `SHUTDOWN_TIMEOUT` exceeded (operators alert on that).

**Doc honesty:** "During deploy, active WebSocket/SSE clients may be disconnected after N seconds. Clients must reconnect. Use Redis-backed rooms/pubsub for multi-node reconnect continuity." This is honest about a real constraint, not weakness.

**Scope:** ~300 LOC + 5 tests + new `template/pages/learn/graceful-shutdown.php` lesson. Cross-refs the existing `App::onSignal()` shipped early in v0.3.0.

### SRE.2.2 — Backpressure & overload limits → F

**State today:** `ConcurrencyLimitMiddleware`, `RateLimitMiddleware`, `BodySizeLimitMiddleware` exist. No connection-count or coroutine-count limits at the framework level. No slow-client timeout.

**Target — new env vars + middleware:**
```bash
ZEALPHP_MAX_CONNECTIONS=10000
ZEALPHP_MAX_COROUTINES=50000
ZEALPHP_MAX_SSE_STREAMS=2000
ZEALPHP_MAX_WS_CONNECTIONS=5000
ZEALPHP_REQUEST_TIMEOUT=30
ZEALPHP_SSE_IDLE_TIMEOUT=60
ZEALPHP_WS_IDLE_TIMEOUT=300
ZEALPHP_SLOW_CLIENT_TIMEOUT=15
ZEALPHP_TASK_QUEUE_MAX=10000
```

**Predictable failure responses:**
- 503 Service Unavailable when `MAX_CONNECTIONS` / `MAX_COROUTINES` exceeded
- 429 Too Many Requests when rate-limited (existing — confirms parity)
- 413 Payload Too Large when body size exceeded (existing)
- 504 Gateway Timeout when upstream call exceeds its timeout

**Logging:** every rejection emits structured event:
```json
{ "event": "request_rejected", "reason": "max_coroutines_exceeded", "status": 503 }
```

Metrics: `zealphp_requests_rejected_total{reason}` — exposed via SRE.1.2.

**Scope:** ~250 LOC new middleware (`OverloadProtectionMiddleware`) + 8 tests + a `learn/backpressure` lesson.

### SRE.2.3 — Blocking-operation detection → F

**State today:** None. App authors who accidentally call sync I/O from a coroutine context get silent worker stalls.

**Target:** A development/staging-mode hook that wraps known blocking calls and increments `zealphp_blocking_operation_warnings_total`. Uopz overrides selected functions (`file_get_contents` without HTTP, `sleep`, blocking `curl_*`, `mysqli_query` on non-async handle) — when called inside a coroutine, log a warning with the call site (`debug_backtrace`).

Off by default in production (cost of the wrap). Enable via `ZEALPHP_DETECT_BLOCKING=1`.

**Scope:** ~200 LOC + 6 tests. Lands in `src/Diagnostics/BlockingDetector.php`.

### SRE.2.4 — Memory-leak hunter docs → D

A lesson at `template/pages/learn/memory-leak-hunting.php`:
- Read the `zealphp_route_memory_delta_bytes` metric from SRE.1.5
- Top growing routes
- Per-coroutine memory tracking
- When a leak is in user code vs framework vs extension
- How to bisect with worker recycling forced to N=1
- Heap snapshot capture via `memory_get_usage(true)` + `gc_collect_cycles()` cadence

**Scope:** Docs only. ~600 lines of prose + code recipes.

---

## Phase 3 — Production Real-Time Runtime (v0.3.2 ship target)

### SRE.3.1 — SSE production behavior contract → F + D

**State today:** `Response::sse()` works. No documented heartbeat, no documented reconnect, no proxy-buffering-off guidance, no idle-timeout contract.

**Target — framework changes:**
- `App::sseHeartbeatInterval(int $sec = 15)` — auto-emit comment frame every N seconds.
- `App::sseRetryHint(int $ms = 3000)` — sets the `retry:` field in the stream prelude.
- `App::sseIdleTimeout(int $sec = 60)` — close streams that haven't seen data flow for N seconds.

**Target — docs:**
- nginx config recipe (`proxy_buffering off`, `proxy_read_timeout 3600`).
- Cloudflare/proxy timeout warning (default 100s — kill SSE; need `cf-no-buffer` etc).
- Client reconnect strategy (browser-native `EventSource` auto-reconnects on connection drop).
- Slow-client backpressure: how `Response::sse()` blocks when the kernel send-buffer fills.

**Scope:** ~180 LOC + 4 tests + a `template/pages/learn/sse-production.php` lesson.

### SRE.3.2 — WebSocket production behavior contract → F + D

**State today:** WS routes work. Ping/pong frames silently dropped per the docs. Close codes used in `WSRouter` (close-1001 Going Away). No documented heartbeat policy.

**Target — framework changes:**
- `App::wsPingInterval(int $sec = 30)` — framework sends PING; client must PONG within `wsPongTimeout`.
- `App::wsPongTimeout(int $sec = 10)` — close + free fd if no PONG.
- `App::wsMaxMessageSize(int $bytes = 1_048_576)` — reject oversize frames with close-1009.
- `App::wsCloseCodes()` — already-shipped constants (WS-5); confirm they're documented.

**Target — docs:**
- Reverse-proxy config for WS (Upgrade headers).
- Client-side reconnect token pattern (re-establish auth on reconnect without full login flow).
- Sticky-session decision tree: with `WSRouter::room()` cluster-wide membership shipped (P1.1), apps usually DON'T need sticky sessions.
- Room cleanup on disconnect — federated GC docs cross-ref to issue #99.

**Scope:** ~250 LOC + 6 tests + `template/pages/learn/websocket-production.php` lesson.

### SRE.3.3 — Circuit breakers & dependency timeouts → F (partially shipped)

**State today:** `CircuitBreakerBackend` shipped for Store/Redis (H4 in v0.2.41 hardening). No HTTP-client circuit breaker, no MongoDB hook.

**Target — extend the existing circuit breaker:**
```php
HTTP::circuitBreaker([
    'failure_threshold' => 5,
    'recovery_timeout'  => 30,
    'half_open_max'     => 2,
])->get($url);
```

Apply the same pattern to outbound `App::exec` calls and the planned MongoDB wrapper.

**Scope:** ~200 LOC + 6 tests. Cross-refs the existing `CircuitBreakerBackend`.

---

## Phase 4 — Enterprise/Institution Trust (v0.4.x ship target)

### SRE.4.1 — Security hardening guide → D

`template/pages/learn/production-security.php`:
- Run as non-root (systemd `User=`)
- Reverse proxy only; bind to 127.0.0.1 or private network
- TLS at the proxy (Caddy/Traefik auto-cert; nginx + certbot recipe)
- Debug mode off; stack traces hidden
- Body / upload limits
- CORS config
- Trusted proxy config (`App::trustedProxies()` — shipped)
- Secure cookies, session cookie flags
- CSRF guidance (cross-ref `v0.3.0-P1.6`)
- Rate limiting & IP allow-list middleware (shipped)
- Admin / `/diagz` / `/metrics` endpoint protection
- Secret loading + env-var safety
- Log redaction (PII patterns)
- ZealPHP-specific: uopz override safety model, what functions are overridden, how to verify overrides are active, security impact of legacy fallback mode, CGI / WordPress bridge threat model.

**Scope:** Docs only. ~1500 lines of prose + worked examples.

### SRE.4.2 — Compatibility matrix → D

`template/pages/production-matrix.php`:

| Component | Supported | Tested in CI | Notes |
|---|---|---|---|
| PHP 8.3 | yes | yes (every PR) | preferred |
| PHP 8.4 | yes | yes (every PR) | stable |
| PHP 8.5 | experimental | yes (advisory) | new tag |
| OpenSwoole 22.1 | yes | yes | minimum |
| OpenSwoole 26.2 | yes | yes | recommended |
| uopz ≥ 7.1 | yes | yes | required |
| Ubuntu 22.04 LTS | yes | yes | production-ready |
| Ubuntu 24.04 LTS | yes | yes | production-ready |
| Debian 12 (bookworm) | yes | yes | Docker default |
| Alpine + musl | no | no | musl-libc warning |
| macOS (dev only) | yes | partial | not production |

Plus: known-good Composer packages, known-risk packages, known-bad extensions, coroutine-safe vs blocking extension list.

**Scope:** Docs only. ~800 lines.

### SRE.4.3 — OpenTelemetry integration → F

**State today:** None.

**Target:** First-class OpenTelemetry tracing for: HTTP request, middleware chain, route handler, template render, SSE stream lifecycle, WebSocket message handler, task dispatch, task execution, Store get/set/delete, Redis call, MongoDB call, external HTTP call, pub/sub publish, pub/sub receive.

```php
App::otel([
    'exporter' => 'otlp-grpc',
    'endpoint' => 'http://localhost:4317',
    'service'  => 'zealphp-app',
    'sample_ratio' => 0.1,
]);
```

`Traceparent` header propagation in/out — already in scope from SRE.1.3.

**Scope:** ~500 LOC + 12 tests + dep on `open-telemetry/exporter-otlp`. New module `src/Tracing/OpenTelemetryBridge.php`.

### SRE.4.4 — Reverse-proxy recipe set → D

`template/pages/deployment.php` already has nginx/Caddy/Docker — extend to:
- HAProxy
- Traefik (full WS + SSE + h2c)
- Apache `mod_proxy_fcgi` + `mod_proxy_wstunnel`
- Kubernetes Ingress (nginx-ingress with annotations)
- Docker Swarm
- systemd unit (already shipped in `deploy/zealphp.service` — link from docs)

**Scope:** Docs only. ~1200 lines incl. tested `docker-compose.yml` snippets.

### SRE.4.5 — Operator runbooks → D

`docs/ops/runbooks/` — Markdown set, one per failure mode:
- `high-latency.md`
- `high-memory.md`
- `worker-crash-loop.md`
- `redis-outage.md`
- `mongodb-outage.md`
- `task-queue-stuck.md`
- `websocket-clients-not-receiving.md`
- `sse-stream-closes-early.md`
- `502-from-proxy.md`, `504-from-proxy.md`
- `uopz-extension-missing.md`
- `openswoole-extension-mismatch.md`
- `worker-not-recycling.md`
- `cpu-saturated.md`
- `too-many-open-files.md`, `fd-exhaustion.md`
- `port-already-in-use.md`
- `deployment-rollback.md`

Each runbook: Symptoms → Likely causes → Metrics to check → Logs to check → Immediate mitigation → Permanent fix → Commands.

**Scope:** Docs only. ~150-300 lines per runbook × 17 runbooks ≈ 3500 lines total. Could ship incrementally.

### SRE.4.6 — SLO template → D

`template/pages/learn/slos.php`:
- Availability: 99.9%
- p95 HTTP latency < 200ms
- p99 < 1s
- SSE first-token latency < 2s (excluding upstream AI)
- WS message fanout p95 < 100ms
- Error rate < 0.1%
- Worker restart rate < N/hour

Mapped to Prometheus alerts (`PrometheusRule` recipes for AlertManager).

**Scope:** Docs only. ~600 lines + sample `.yaml` rule files.

### SRE.4.7 — Benchmark tiers (T1-T4) + 24h soak report → D + bench scripts

**State today:** Tier 1 benchmarks shipped (hello-text 117k req/s, JSON 106k, templated 50k). Reproduction scripts in `scripts/bench.sh`.

**Target — extend to four tiers:**
- **T1: Framework overhead** (shipped) — hello / JSON / template / routing / middleware
- **T2: Real app** — session + cookie + MongoDB query + Redis cache + template + auth middleware
- **T3: Long-lived** — 1000 SSE clients, 5000 WS clients, broadcast fanout, slow-client storms, disconnect/reconnect storms
- **T4: Soak** — 24-hour run, constant traffic, memory trend, worker recycle trend, error trend, latency trend

The T4 soak silences the "but long-running PHP leaks" objection definitively.

**Scope:** ~800 LOC across `bench/{t2,t3,t4}/*.php` + a `bench/soak-report-template.md` for the publishable artifact.

### SRE.4.8 — Production readiness scorecard → D

`template/pages/production-readiness.php` — public scorecard with honest current status:

| Capability | Status |
|---|---|
| Reverse-proxy deployment | Ready |
| systemd / Docker / Kubernetes | Ready |
| Health / readiness / diagnostics | Partial → Ready in v0.3.0 (SRE.1.1) |
| Prometheus metrics | Planned → Ready in v0.3.0 (SRE.1.2) |
| JSON request logs | Planned → Ready in v0.3.0 (SRE.1.4) |
| Request ID propagation | Planned → Ready in v0.3.0 (SRE.1.3) |
| Graceful shutdown contract | Partial → Ready in v0.3.1 (SRE.2.1) |
| Worker recycling | Ready (count) / Planned (RSS) |
| WS / SSE production docs | Partial → Ready in v0.3.2 (SRE.3.1/3.2) |
| Security hardening guide | Partial → Ready in v0.4.x (SRE.4.1) |
| Compatibility matrix | Partial → Ready in v0.4.x (SRE.4.2) |
| OpenTelemetry | Planned → v0.4.x (SRE.4.3) |
| Runbooks | Planned → v0.4.x (SRE.4.5) |
| 24h soak benchmark report | Planned → v0.4.x (SRE.4.7) |
| Chaos tests | Planned → post-v1.0 |

**Honesty matters.** Mark items partial / planned where they are. SREs trust honest scorecards more than aspirational ones.

**Scope:** Docs only. ~300 lines + ongoing maintenance as items ship.

---

## Items deferred to post-v0.4.x (or beyond scope)

- **Chaos testing harness** — `bin/chaos.php` that kills random workers, blocks random Redis ops, etc. Useful but not blocking on SRE-readiness.
- **Distributed tracing UI bundle** — Jaeger/Tempo dashboards. Documented as recommended, not shipped.
- **Auto-instrumentation profiler integration** — XHProf, Tideways. Document the recipe, don't ship the dep.

---

## Cross-references to v0.3.0 feature roadmap

These v0.3.0 items overlap with SRE-readiness; treat them as the same work:

| v0.3.0 item | SRE-readiness item |
|---|---|
| P1.6 — CSRF middleware | SRE.4.1 (Security hardening guide cross-refs) |
| P1.10 — `App::stats()` + `/healthz` | SRE.1.1 (`/healthz`, `/readyz`, `/diagz`) + SRE.1.2 (`/metrics`) |
| P1.12 — `App::onSignal()` (shipped) | SRE.2.1 (Graceful shutdown contract — relies on it) |
| Issue #99 — WSRouter heartbeat depth | SRE.3.2 (WebSocket production behavior) |

---

## Brutally simple sequencing — the "5 first" the audit closed with

If we could only do five things to earn SRE trust, these are them:

1. **Metrics** — SRE.1.2 (`/metrics` Prometheus)
2. **Structured logs** — SRE.1.4 (JSON request logs + request-ID propagation)
3. **Health endpoints** — SRE.1.1 (`/healthz`, `/readyz`, `/diagz`)
4. **Graceful shutdown + overload behavior** — SRE.2.1 + SRE.2.2
5. **Runbooks** — SRE.4.5 (start with top 5: high-latency, high-memory, worker-crash-loop, redis-outage, task-queue-stuck)

Everything else is maturity. Ship these five and ZealPHP looks like infrastructure rather than a clever runtime.

---

## Ship-order summary

| Version | SRE items |
|---|---|
| **v0.3.0** | SRE.1.1, 1.2, 1.3, 1.4, 1.5, 1.6 (the "minimum SRE trust" set) |
| **v0.3.1** | SRE.2.1, 2.2, 2.3, 2.4 (long-running safety) |
| **v0.3.2** | SRE.3.1, 3.2, 3.3 (real-time runtime polish) |
| **v0.4.x** | SRE.4.1–4.8 (institution trust — docs-heavy, parallel-shippable) |

**Total estimated framework code** (Phases 1–3, excl. docs): ~3000 LOC + ~95 tests across 12 new files. Spread across 3 minor versions, this is genuinely shippable in 4–6 weeks of focused work without slipping the v0.3.0 feature roadmap.
