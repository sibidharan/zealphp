# ZealPHP Performance Benchmarks

Benchmarks are machine-specific. This repo should not publish one global
requests/sec number without the machine, OS, PHP/OpenSwoole versions, worker
count, endpoint, command, CSV, and raw tool output beside it.

Use the modular runner below, then update this file with the measured result.

---

## Public Claim Guidance

Safe claims:

| Claim | Why |
|-------|-----|
| ZealPHP is built on OpenSwoole's event-driven, coroutine-based HTTP/WebSocket server. | This describes the runtime ZealPHP uses. |
| ZealPHP is designed for high-concurrency PHP services. | OpenSwoole documents a multi-process, event-driven, asynchronous model for large-scale concurrency. |
| ZealPHP includes reproducible benchmark scripts through c=1000. | This repo ships `scripts/bench.sh --p1000`. |
| OpenSwoole benchmark examples show high raw HTTP throughput on stated machines. | Attribute these to OpenSwoole, not ZealPHP, unless reproduced through ZealPHP. |

Avoid claims like "ZealPHP handles 1M concurrent connections" until a ZealPHP
benchmark proves it with the route, worker settings, OS limits, machine specs,
CSV, and raw logs included. One million concurrent connections depends on file
descriptor limits, `max_conn`, memory, networking, benchmark clients, route
logic, middleware, and payload size.

Useful OpenSwoole references:

- [OpenSwoole docs](https://openswoole.com/docs) describe it as an event-driven,
  asynchronous, non-blocking coroutine network framework for PHP.
- [How OpenSwoole works](https://openswoole.com/how-it-works) explains the
  multi-process/event-driven model, worker processes, and coroutine concurrency.
- [OpenSwoole HTTP server docs](https://openswoole.com/docs/modules/swoole-http-server-doc)
  include official HTTP performance examples.
- [OpenSwoole server configuration](https://openswoole.com/docs/modules/swoole-server/configuration)
  documents `worker_num`, `max_conn`, and `max_coroutine` limits.

---

## 16-Core Mac Stress Run

Install `wrk` if it is missing:

```bash
brew install wrk
```

Run the default c=1000 sweep with 16 HTTP workers:

```bash
scripts/bench.sh --workers 16 --threads 16 --task-workers 0 --p1000 --duration 30s
```

Or run the same profile in Docker:

```bash
mkdir -p bench/results
docker compose run --rm --build bench
```

On Docker Desktop for Mac, set Resources -> CPU limit to 16 before comparing
results. Docker results are still container results; label them separately from
bare-metal macOS runs.

For a controlled quad-core ZealPHP vs Node.js comparison:

```bash
mkdir -p bench/results
docker compose run --rm --build compare
```

This runs `scripts/bench_compare.sh` with 4 ZealPHP workers, 4 Node.js cluster
workers, and 4 wrk threads. It writes:

| File | Contents |
|------|----------|
| `bench/results/compare/quad-compare-*.csv` | Per-runtime totals: requests, elapsed time, req/s, p50/p90/p99, failures |
| `bench/results/compare/quad-compare-summary-*.csv` | ZealPHP vs Node side-by-side ratios per path/concurrency |
| `bench/results/compare/raw/*.txt` | Raw wrk output for audit/debugging |

For quieter runs, set `ZEALPHP_BENCH_MODE=1` to skip the demo middleware and
session file I/O on the benchmark path. The sample auth/validation middleware
is opt-in via `ZEALPHP_DEMO_MIDDLEWARE=1`.
Set `ZEALPHP_LOG_DIR=/tmp/zealphp` to write `debug.log`, `access.log`, and
`zlog.log` there, and keep `ZEALPHP_LOG_ASYNC=1` so logging is queued off the
request path. Also set `ZEALPHP_DEBUG_LOG=0` and `ZEALPHP_ACCESS_LOG=0` for
quiet runs.
If `/tmp/zealphp` is not writable, ZealPHP falls back to a writable local log
directory.

`--p1000` is only a project shorthand for a concurrency sweep up to `c=1000`.
Latency percentiles are still reported as p50, p90, and p99.

The script:

| Area | Default |
|------|---------|
| Server | Launches `php app.php` unless `--no-start` is passed |
| HTTP workers | `ZEALPHP_WORKERS=16` |
| Task workers | `ZEALPHP_TASK_WORKERS=0` for plain HTTP benchmarking |
| Advanced limits | `--max-conn`, `--max-coroutine`, `--backlog`, `--reactor-num` when needed |
| Tool | `wrk` if available, otherwise `ab` |
| Endpoint | `/raw/bench` |
| Bench mode | `ZEALPHP_BENCH_MODE=1` for the lean benchmark profile |
| Demo middleware | `ZEALPHP_DEMO_MIDDLEWARE=1` to enable the sample auth/validation layer |
| Logs | `ZEALPHP_LOG_DIR=/tmp/zealphp`, `ZEALPHP_LOG_ASYNC=1` |
| Sweep | `1,10,50,100,200,500,1000` concurrency |
| Output | `bench/results/zealphp-*.csv` plus raw logs |

Additional useful profiles:

```bash
# Middleware + coroutine-safe session path
scripts/bench.sh --workers 16 --threads 16 --task-workers 0 --path /json --p1000

# Compare multiple endpoints in one run
scripts/bench.sh --workers 16 --threads 16 --task-workers 0 \
  --paths /raw/bench,/json,/co --concurrency 10,100,500,1000

# Test an already-running server
ZEALPHP_BENCH_URL=${ZEALPHP_BENCH_URL:-http://127.0.0.1:8080} \
  scripts/bench.sh --no-start --base-url "$ZEALPHP_BENCH_URL" --path /raw/bench --p1000

# Explicit OpenSwoole limits for larger connection tests
scripts/bench.sh --workers 16 --threads 16 --p1000 \
  --max-conn 65535 --max-coroutine 100000 --backlog 8192
```

If a route uses `App::getServer()->task()`, run with task workers enabled:

```bash
scripts/bench.sh --workers 16 --task-workers 4 --path /your-task-route
```

---

## Result Template

When publishing results, include this block:

| Field | Value |
|-------|-------|
| Machine | TBD |
| OS | TBD |
| PHP | TBD |
| OpenSwoole | TBD |
| Command | `scripts/bench.sh ...` |
| Endpoint | TBD |
| HTTP workers | TBD |
| Task workers | TBD |
| Tool | `wrk` or `ab` |
| CSV | `bench/results/...csv` |
| Raw logs | `bench/results/raw/...txt` |

Summary table:

| Endpoint | c | req/s | avg ms | p50 ms | p90 ms | p99 ms | failures |
|----------|---|-------|--------|--------|--------|--------|----------|
| TBD | TBD | TBD | TBD | TBD | TBD | TBD | TBD |

---

## Metrics

| Metric | Meaning |
|--------|---------|
| `req/s` | Throughput at that concurrency level; higher is better |
| `avg_ms` | Mean latency reported by the benchmark tool |
| `p50_ms` | Median latency |
| `p90_ms` | 90th percentile latency |
| `p99_ms` | Tail latency; watch this during high-concurrency sweeps |
| `failures` | Socket errors, non-2xx/3xx responses, or failed ab requests |

Keep raw logs. A single CSV row is not enough to debug saturation, socket
errors, or tool-level warnings.

---

## Historical Optimisations Already Applied

### G coroutine isolation (`src/G.php`)

`G::instance()` was a static singleton shared across concurrent requests in a
worker. In coroutine mode, when coroutine A yielded during IO, coroutine B
could overwrite `$g->session`, `$g->get`, and other request state. It now uses
`Coroutine::getContext()` so each coroutine gets isolated request state.

### Reflection cached at route registration (`src/App.php`)

`new ReflectionFunction($handler)` used to run on every request. `buildParamMap()`
now runs at route registration and stores the parameter list with the route.
Per-request dispatch is a plain array loop.

### Method-indexed dispatch table (`src/App.php`)

Route matching was O(n) with an `in_array` method check on every route. Routes
are now grouped by HTTP method before request handling.

### stream_wrapper moved to workerStart (`src/App.php`)

`stream_wrapper_unregister/register("php")` used to run inside
`ResponseMiddleware::process()` on every request. It now runs once per worker at
startup.

### CoSessionManager uses fresh G per request (`src/Session/CoSessionManager.php`)

The session manager no longer caches a stale `G::instance()` from server boot.
It resolves the current per-coroutine instance for every request.

### Session directory stat cached (`src/Session/utils.php`)

The session save path check now runs once per worker lifetime per path instead
of performing a filesystem stat on every session start.

### App runs in coroutine mode (`app.php`)

The demo app uses `App::superglobals(false)`, enabling coroutine mode and
OpenSwoole hook integration for concurrent IO.
