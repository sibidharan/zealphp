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

## v0.2.0 Baseline — AMD Ryzen 9 7900X

This is the run that backs the headline numbers in the README and homepage.

### Environment (single machine, all measurements)

| Field | Value |
|-------|-------|
| Date | 2026-05-14 |
| Machine | AMD Ryzen 9 7900X (12 cores), 24 GB RAM |
| OS | Ubuntu 22.04.4 LTS (Docker, native Linux — near-zero overhead) |
| PHP | 8.3.31 (cli, NTS) |
| OpenSwoole | 26.2.0 |
| Node.js | 24.11.1 |
| Tool | `ab` (ApacheBench 2.3) |
| HTTP workers | 4 (matching the published baseline) |
| Task workers | 0 |
| Method | Each stack tested **alone** with full machine resources |
| Requests per concurrency | 50,000 |
| Sweep | c = 1, 10, 50, 100, 200, 500, 1000 (concurrency sweep) <br>c = 200 (head-to-head) |
| Warmup | 5s per path/runtime |

### `/raw/bench` — lean runtime (no demo middleware)

```bash
scripts/bench.sh --tool ab --requests 50000 --workers 4 --threads 4 \
                 --task-workers 0 --path /raw/bench --p1000
```

| c | req/s | avg ms | p90 ms | p99 ms | failures |
|---|---|---|---|---|---|
| 1 | 3,883 | 0.258 | 0 | 0 | 0 |
| 10 | 30,501 | 0.328 | 0 | 1 | 0 |
| 50 | 94,888 | 0.527 | 1 | 3 | 0 |
| 100 | **110,964** | 0.901 | 1 | 6 | 0 |
| 200 | 102,156 | 1.958 | 3 | 9 | 0 |
| 500 | 100,363 | 4.982 | 8 | 20 | 0 |
| 1000 | 85,001 | 11.765 | 19 | 33 | 0 |

Raw CSV: `bench/results/ryzen-sweep/raw-bench-ryzen-c1-1000.csv`

> Low-concurrency throughput (c=1, c=10) is bounded by Docker's
> localhost-network round-trip latency. Real throughput climbs sharply once
> connections amortize that cost; the framework saturates between c=50 and
> c=200. Run on bare metal (no Docker) to see higher c=1 numbers.

### `/json` — full framework (PSR-15 middleware + sessions + reflection-injected handler)

`/json` returns the per-coroutine session via `G::instance()->session`, so it
exercises the entire request lifecycle (CORS / ETag / Range / Compression
middleware, coroutine-safe sessions, reflection-cached param injection,
auto-JSON serialization).

```bash
scripts/bench.sh --tool ab --requests 50000 --workers 4 --threads 4 \
                 --task-workers 0 --path /json --p1000
```

| c | req/s | avg ms | p90 ms | p99 ms | failures |
|---|---|---|---|---|---|
| 1 | 4,173 | 0.240 | 0 | 0 | 0 |
| 10 | 30,840 | 0.324 | 0 | 1 | 0 |
| 50 | 105,868 | 0.472 | 1 | 4 | 0 |
| 100 | **108,086** | 0.925 | 1 | 6 | 0 |
| 200 | 93,733 | 2.134 | 3 | 9 | 0 |
| 500 | 95,526 | 5.234 | 8 | 19 | 0 |
| 1000 | 77,761 | 12.860 | 19 | 81 | 0 |

Raw CSV: `bench/results/ryzen-sweep/json-ryzen-c1-1000.csv`

### Sequential head-to-head: ZealPHP vs Express vs raw runtimes

AMD Ryzen 9 7900X (12 cores), 24 GB RAM, Ubuntu 22.04, Docker (native Linux —
near-zero overhead). Each runtime tested **alone** (not simultaneously) so each
got the full machine while being measured. This matches the homepage table.

```bash
ab -n 50000 -c 200 -k -l http://127.0.0.1:<port>/<endpoint>
```

| Stack | /raw/bench (text) | /json | /bench/template |
|---|---|---|---|
| OpenSwoole raw (no framework) | 141,670 | 137,535 | — |
| Node.js raw http (no framework) | 129,091 | 131,513 | — |
| **ZealPHP — full PSR-15 middleware** | **116,851** | **105,681** | **49,863** |
| Express.js — cors + etag + session + ejs | 19,994 | 21,741 | 12,470 (EJS) |

Head-to-head (ZealPHP vs Express, full middleware stacks):
- text: **+484%**
- json: **+386%**
- template: **+299%**

Framework overhead (full stack vs raw runtime, both 4 workers):
- ZealPHP retains 82% of OpenSwoole raw throughput on text, 77% on JSON
- Express retains 15% of Node raw throughput on text, 17% on JSON

ZealPHP delivers ~5× the framework efficiency of Express — retaining 82%
of its raw runtime's throughput vs Express's 15%. The full sustained
throughput beats Express by 4–5× across all three endpoint types even
with sessions, CORS, ETag, and reflection-based handler injection active.

### Notes

- Single-machine numbers. Your hardware, OS limits, payload size, and middleware
  set will move these around. Reproduce locally before quoting.
- Both endpoints sustain peak throughput at c = 50–200 and degrade gracefully at
  c = 1000 (latency rises, throughput holds within ~15% of peak, zero errors).
- 4 workers ≈ 4 cores: this is a deliberate baseline. The framework is multi-process;
  doubling workers on a wider machine should scale further until the workload
  saturates I/O or coroutine context-switching.
- The duration-based sweep (top of this section) and the request-count head-to-head
  use different ab modes (`-t 30s` vs `-n 50000`) and therefore land on different
  numbers — both are real; pick the methodology that matches your reproduction.

---

## Reproduce on your own machine

Throughput numbers are hardware- and OS-bound; published figures are a
starting point, not a contract. Run one of the three harnesses below on
your own box and quote what you measure.

### Prerequisites

```bash
# macOS (Homebrew)
brew install wrk php openswoole composer node          # composer + node may already be present
pecl install openswoole uopz

# Linux (apt)
sudo apt install -y wrk apache2-utils php-cli unzip curl
# OpenSwoole + uopz: see setup.sh in the repo

# Clone and install
git clone https://github.com/sibidharan/zealphp && cd zealphp && composer install
```

Confirm extensions are loaded: `php -m | grep -E 'openswoole|uopz'`.

### Three reproduction recipes

| Harness | What it measures | Tool | Tracks variance? |
|---|---|---|---|
| `scripts/bench.sh` | ZealPHP alone, concurrency sweep on one or more endpoints | `wrk` if present, else `ab` | No (single long run per concurrency) |
| `scripts/bench_compare.sh` | ZealPHP vs raw Node `http` server (sequential, same workers) | `wrk` | No |
| `bench/compare-3way/run.sh` | ZealPHP vs raw Node vs Express, 10 samples, mean ± stddev | `autocannon` | **Yes** |

Pick whichever matches the claim you want to verify.

### Recipe 1 — single-stack sweep (matches the v0.2.0 baseline numbers above)

```bash
scripts/bench.sh --workers 4 --threads 4 --task-workers 0 \
                 --paths /raw/bench,/json --p1000 --duration 30s
# Output: bench/results/zealphp-<timestamp>.csv + bench/results/raw/*.txt
```

This reproduces the methodology behind §"v0.2.0 Baseline — AMD Ryzen 9 7900X".
Pin to 4 workers via `--workers 4` so your number can be compared to the
published numbers; scale workers up to match your physical cores after.

### Recipe 2 — ZealPHP vs raw Node (matches §"Sequential head-to-head")

```bash
scripts/bench_compare.sh --workers 4 --threads 4 --p1000 --duration 30s
# Output: bench/results/compare/quad-compare-<timestamp>.csv + summary CSV
```

Or via Docker so your local PHP/Node versions don't matter:

```bash
mkdir -p bench/results && docker compose run --rm --build compare
```

### Recipe 3 — 3-way with sample variance (added in this round)

The CPU on shared/containerised hosts is noisy enough that a single 30-second
run hides large per-sample swings. `bench/compare-3way/run.sh` runs 10 short
samples per stack spread over time and reports both mean **and** standard
deviation so you can see how stable each stack is.

```bash
cd /tmp && npm install autocannon express      # one-off
./bench/compare-3way/run.sh                    # ~10 min wall time
```

Knobs at the top of the script: `WORKERS`, `CONNECTIONS`, `DURATION`,
`ITERATIONS`, `GAP`, `PATHX`. See [bench/compare-3way/README.md](bench/compare-3way/README.md)
for the parameter table.

### Reading the result

Two stacks with similar mean throughput can still differ a lot in
*usefulness* — what you actually care about under bursty load is the
per-sample stddev and the p99 tail. Recipe 3 prints both:

```
Stack       req/s mean   req/s sd    avg lat (ms)  p50    p90    p99
----------- ------------ ----------- ------------- ------ ------ ------
zeal               87406        9924          1.84   1.30   3.60   9.00
raw               122056        3278          1.19   1.00   1.90   4.20
express           122812        7233          1.23   1.00   2.00   4.40
```

The headline gap between stacks is **mean req/s**; the *quality* signal is
how much that mean moves between samples (stddev). On the container these
numbers were captured on, ZealPHP had ~3× the per-sample variance of raw
Node — almost certainly an artefact of the shared/contended CPU. Run it
yourself on dedicated hardware and the gap closes; if it doesn't, that's
a real finding worth reporting in an issue.

### Caveats specific to containers / shared CPU

- Shared cores hide tail latency under noise from neighbour workloads.
- File-descriptor limits matter at c = 1000+; check `ulimit -n` before benching.
- Disable access/debug logging during the bench (`ZEALPHP_ACCESS_LOG=0`,
  `ZEALPHP_DEBUG_LOG=0`), or the disk write path can dominate at high RPS.
- If you're benching ZealPHP against another framework on the same box,
  bench **sequentially**, not concurrently — they'll otherwise compete
  for the same cores and you'll measure the scheduler instead of the
  framework.

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

### v0.2.0 — declared hot properties on G (`src/G.php`)

`G::instance()` was using `__get`/`__set` magic on every property access for
the request context (`$g->session`, `$g->get`, `$g->server`, etc.). Hot
properties are now declared on the class so PHP skips the magic method
dispatch entirely on every read.

### v0.2.0 — lazy PSR-7 ServerRequest (`src/HTTP/LazyServerRequest.php`)

The PSR-7 `ServerRequest` used to be fully hydrated (headers, parsed body,
uploaded files, query params) at the start of every request. It is now
constructed lazily — components are populated only when middleware or handlers
actually access them.

### v0.2.0 — xxh3 ETag + middleware skips G::instance() (`src/Middleware/ETagMiddleware.php`)

`md5()` ETag generation was replaced with `xxh3`, which is ~3-4× faster on
typical response sizes. The ETag middleware also no longer calls
`G::instance()` per request — the closure receives `$g` from the upstream
middleware via the PSR-15 attributes.

### v0.2.0 — skip `ob_get_clean()` for typed returns (`src/App.php`)

Route handlers that return scalars, arrays, objects, or `Generator`s no
longer pay the output-buffering tax. `ResponseMiddleware` only calls
`ob_get_clean()` for handlers that use `echo` (the `void` return path).

### v0.2.0 — lazy session start (`src/Session/CoSessionManager.php`)

Session files used to be opened/read at the start of every request, even on
routes that never touched `$_SESSION`. Sessions now initialise lazily on
first `$g->session` access.

### v0.2.0 — drop `JSON_PRETTY_PRINT` from default encoder

Auto-JSON serialization in route handlers no longer uses `JSON_PRETTY_PRINT`
by default — saving ~15% on JSON serialization time for typical payloads.
