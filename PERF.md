# ZealPHP Performance Benchmarks

Machine: 24-core Linux, PHP 8.3, OpenSwoole 22.1.5, Node.js 24.15.0  
Tool: Apache Bench (`ab`), 24 workers/cluster forks on both sides.

---

## ZealPHP (before optimisations) vs Node.js

| Test | ZealPHP before | Node.js | Notes |
|------|---------------|---------|-------|
| Simple route, c=10 | 12,690 req/s | 11,706 req/s | |
| Simple route, c=200 | 10,448 req/s | 15,377 req/s | |
| JSON + session, c=100 | 9,603 req/s | 16,692 req/s | Node has no session overhead |
| p50 latency | 5 ms | 2 ms | |
| p75 latency | 6 ms | 3 ms | |
| p90 latency | 8 ms | 3 ms | |
| Async IO `/co` (100 clients) | 6.009 s | 6.007 s | Dead heat — both event-loop |

---

## ZealPHP before vs after optimisations

| Test | Before | After | Delta |
|------|--------|-------|-------|
| Simple route, c=10 | 12,690 req/s | 14,927 req/s | **+17%** |
| Simple route, c=200 | 10,448 req/s | 9,593 req/s | ≈ same |
| JSON + session, c=100 | 9,603 req/s | 10,177 req/s | **+6%** |
| p50 latency | 5 ms | 3 ms | **−40%** |
| p75 latency | 6 ms | 3 ms | **−50%** |
| p90 latency | 8 ms | 4 ms | **−50%** |

---

## Optimisations applied (this commit)

### Correctness fix — G coroutine isolation (`src/G.php`)
`G::instance()` was a static singleton shared across all concurrent requests
in a worker. In coroutine mode, when coroutine A yielded during IO, coroutine B
could overwrite `$g->session`, `$g->get`, etc. Fixed by using
`Coroutine::getContext()` — each coroutine now gets its own isolated G,
automatically freed when the coroutine ends.

### Reflection cached at route registration (`src/App.php`)
`new ReflectionFunction($handler)` was being created on every request.
`buildParamMap()` now runs once at route registration and stores the parameter
list alongside the route. Per-request dispatch is a plain array loop — zero
reflection overhead.

### Method-indexed dispatch table (`src/App.php`)
Route matching was O(n) with an `in_array` method check on every route.
At the end of `run()`, routes are now grouped by HTTP method into
`$routes_by_method`. A GET request iterates only GET routes; the method
check is gone entirely.

### stream_wrapper moved to workerStart (`src/App.php`)
`stream_wrapper_unregister/register("php")` was called inside
`ResponseMiddleware::process()` — once per request. Moved to
`$server->on('workerStart')` so it runs once per worker process at startup.

### CoSessionManager uses fresh G per request (`src/Session/CoSessionManager.php`)
Constructor was caching `G::instance()` at server boot into `$this->g` and
reusing that stale reference for every subsequent request. `__invoke()` now
calls `G::instance()` directly, getting the correct per-coroutine instance.

### Session directory stat cached (`src/Session/utils.php`)
`is_dir($save_path)` was called on every session start — a filesystem stat
per request. Replaced with a `static $verified_paths` map; the check runs
once per worker lifetime per path.

---

## Running your own benchmarks

Requires `ab` (Apache Bench — ships with `apache2-utils` on Debian/Ubuntu):

```bash
# Install if missing
sudo apt install apache2-utils

# Start the server
php app.php &

# Simple route — adjust -n (total requests) and -c (concurrency) as needed
ab -n 10000 -c 100 http://localhost:8080/quiz/hello

# Session route
ab -n 5000 -c 50 http://localhost:8080/json

# Coroutine/async route (each request runs 5 parallel sleeps totalling 3 s)
time ab -n 50 -c 50 http://localhost:8080/co

# Latency percentiles (look for the "Percentage of requests served within a
# certain time" table at the bottom of ab output)
ab -n 5000 -c 50 http://localhost:8080/quiz/hello
```

Compare against a plain Node.js cluster server on the same machine:

```bash
node node_bench.js &
ab -n 10000 -c 100 http://localhost:3000/quiz/hello
ab -n 10000 -c 100 http://localhost:3000/json
time ab -n 50 -c 50 http://localhost:3000/co
```

Key metrics to read from `ab` output:

| Line | Meaning |
|------|---------|
| `Requests per second` | Throughput — higher is better |
| `Time per request (mean)` | Average latency at your concurrency level |
| `50%` / `90%` / `99%` in the percentile table | p50/p90/p99 latency in ms |
| `Failed requests` | Non-200 responses or connection errors — should be 0 |

---

### App switched to full coroutine mode (`app.php`)
`App::superglobals(true)` disabled coroutines entirely, making the server
behave like Apache (one blocking request per worker, OS-process-per-implicit-
route via `prefork_request_handler`). Changed to `App::superglobals(false)`.
`sleep()` calls in the `/co` demo changed to `co::sleep()`.
