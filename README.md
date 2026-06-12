# ZealPHP — A PHP HTTP Server (built on OpenSwoole)

ZealPHP runs PHP as the HTTP server itself — not a CGI worker behind one. Built on **OpenSwoole**, it ships HTTP, WebSocket, SSE, coroutines, shared memory, timers, and task workers as first-class primitives because the server stays alive between requests. New features go async without a separate Node or Go service.

The headline mode is **`coroutine-legacy`**: a compatibility runtime where traditional request-style PHP — `$_GET` / `$_POST` / `$_SESSION`, `session_start()`, `header()`, `setcookie()`, `exit`/`die`, `require_once` — runs unchanged **under coroutine concurrency**, with every request-state primitive (the seven superglobals, `$GLOBALS`, function/class statics, constants, `require_once` re-execution, locale/timezone/cwd/umask) isolated per coroutine by [ext-zealphp](https://github.com/sibidharan/ext-zealphp). "Old PHP just works, concurrently" — with one honest conditional: the app's class graph must be **warmed before concurrency hits it** (`App::preloadClassmap()` for Composer apps; pure-`require_once` apps with no autoloader belong in `legacy-cgi`). Alpha — see stability note below.

[![Packagist Version](https://img.shields.io/packagist/v/zealphp/zealphp?style=flat-square&color=orange&logo=packagist&logoColor=white)](https://packagist.org/packages/zealphp/zealphp) [![Packagist Downloads](https://img.shields.io/packagist/dt/zealphp/zealphp?style=flat-square&logo=packagist&logoColor=white)](https://packagist.org/packages/zealphp/zealphp) [![License](https://img.shields.io/packagist/l/zealphp/zealphp?style=flat-square)](https://packagist.org/packages/zealphp/zealphp)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/sibidharan/zealphp) [![GitHub stars](https://img.shields.io/github/stars/zealphp/zealphp?style=flat-square&logo=github&logoColor=white)](https://github.com/sibidharan/zealphp/stargazers) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/) [![PHP tested](https://img.shields.io/badge/tested-PHP%208.3%20%7C%208.4%20%7C%208.5--experimental-777bb4?style=flat-square&logo=php&logoColor=white)](https://github.com/sibidharan/zealphp/actions/workflows/tests.yml) [![Stability](https://img.shields.io/badge/stability-active%20alpha-orange?style=flat-square)](CHANGELOG.md)
[![CI](https://github.com/sibidharan/zealphp/actions/workflows/tests.yml/badge.svg)](https://github.com/sibidharan/zealphp/actions/workflows/tests.yml) [![CodeQL](https://github.com/sibidharan/zealphp/actions/workflows/codeql.yml/badge.svg)](https://github.com/sibidharan/zealphp/actions/workflows/codeql.yml) [![gitleaks](https://github.com/sibidharan/zealphp/actions/workflows/gitleaks.yml/badge.svg)](https://github.com/sibidharan/zealphp/actions/workflows/gitleaks.yml) [![Coverage](https://codecov.io/gh/sibidharan/zealphp/branch/master/graph/badge.svg)](https://codecov.io/gh/sibidharan/zealphp) [![PHPStan](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsibidharan%2Fzealphp%2Fmaster%2F.github%2Fbadges%2Fphpstan.json)](phpstan.neon) [![Mutation MSI](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fsibidharan%2Fzealphp%2Fmaster%2F.github%2Fbadges%2Fmutation.json)](https://github.com/sibidharan/zealphp/actions/workflows/mutation.yml) [![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/sibidharan/zealphp/badge)](https://securityscorecards.dev/viewer/?uri=github.com/sibidharan/zealphp) [![SBOM](https://img.shields.io/badge/SBOM-CycloneDX-blue?style=flat-square)](https://github.com/sibidharan/zealphp/actions/workflows/sbom.yml)
[![OpenSwoole](https://img.shields.io/badge/OpenSwoole-22%20%7C%2026-ff5722?style=flat-square)](https://openswoole.com/) [![Benchmarks](https://img.shields.io/badge/benchmarks-reproducible-success?style=flat-square)](https://github.com/sibidharan/zealphp/tree/master/scripts) [![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa?style=flat-square)](CODE_OF_CONDUCT.md) [![Sponsor](https://img.shields.io/github/sponsors/sibidharan?style=flat-square&logo=github&logoColor=white)](https://github.com/sponsors/sibidharan)

**Homepage:** [https://php.zeal.ninja](https://php.zeal.ninja)  
Running `php app.php` serves the same docs site locally. Set `ZEALPHP_SITE_URL` if you want the rendered example URLs to point somewhere else.
**Changelog:** [CHANGELOG.md](CHANGELOG.md) · **Design trade-offs:** [/design-tradeoffs](https://php.zeal.ninja/design-tradeoffs) · **Critique retrospective:** [CRITIC.md](CRITIC.md)
**Compatibility:** [50-app database across all 4 modes](docs/compatibility-database.md) · **Isolation internals:** [stage taxonomy (S1–S12)](docs/architecture/isolation-stages.md)

### PHP-FPM vs ZealPHP

| | PHP-FPM | ZealPHP |
|---|:---:|:---:|
| Process model | Request-per-process | Long-running server |
| WebSocket server | — | Built-in |
| Server-Sent Events | Workaround | Native |
| In-memory state across requests | — | Shared memory (`Store`) |
| Coroutines | — | OpenSwoole native |
| Background timers | — | `App::tick()` |
| Needs Node.js for realtime | Usually | No |

> **Try it:** [`examples/tic-tac-toe/`](examples/tic-tac-toe/) is a multiplayer game with rooms, spectators, and live board sync — [~130 lines of PHP](examples/tic-tac-toe/app.php), no database, no Node.js.

---

## Features

| Feature | Details |
|---------|---------|
| **Async coroutines** | `go()` + `Channel` — thousands of concurrent requests per worker |
| **SSR streaming** | Generator `yield`, `$response->stream()`, `$response->sse()` — like React's `renderToPipeableStream` |
| **WebSocket** | `App::ws($path, $onMessage, $onOpen, $onClose)` — rooms, auth, binary, heartbeat |
| **Pluggable Store/Counter** | `Store::defaultBackend(Store::BACKEND_REDIS)` (or `ZEALPHP_STORE_BACKEND=redis`) flips storage from local `OpenSwoole\Table`/`Atomic` to Redis/Valkey with zero handler changes — cross-node shared state + persistence with one line. Tracked + TTL modes, per-worker coroutine pool, Lua-backed `Counter::compareAndSet`. |
| **Cross-node messaging** | `Store::publish($ch, $payload)` + `App::subscribe($ch, $handler)` for fire-and-forget pub/sub (cross-worker AND cross-host). `Store::publishReliable($stream, $payload)` + `App::subscribeReliable($stream, $handler)` for Streams-backed at-least-once delivery via consumer groups. The cross-server WebSocket routing pattern (owner-of-fd pushes; Redis routes to owner) lights up end-to-end. **Driver choice:** Both phpredis (preferred when `ext-redis` is loaded) and predis SUBSCRIBE loops yield correctly under `OpenSwoole\Runtime::HOOK_ALL` — the production default in coroutine mode. phpredis is ~2× faster on hot CRUD; pick it when you can. One nuance: phpredis SUBSCRIBE blocks the worker WITHOUT HOOK_ALL — if you disabled HOOK_ALL explicitly, force `ZEALPHP_REDIS_PREFER=predis` for subscribers or re-enable HOOK_ALL. See [`/store#pubsub`](https://php.zeal.ninja/store#pubsub). |
| **Dynamic routing** | `route()`, `nsRoute()`, `nsPathRoute()`, `patternRoute()` with reflection-based parameter injection |
| **Middleware** | PSR-15 stack — 30+ built-ins (CORS, ETag, Range, Compression, SessionStart, IniIsolation, Charset, CacheControl, Expires, Header, BasicAuth, IpAccess, RateLimit, ConcurrencyLimit, BlockPhpExt, MimeType, BodyRewrite, HostRouter, BodySizeLimit, Csrf, Redirect, Scoped, MergeSlashes, Referer, RequestHeader, Return, SetEnvIf, ContentEncoding, ContentLanguage, HealthCheck) — full Apache `mod_rewrite` / `mod_headers` / `mod_expires` and nginx `limit_req` / `auth_basic` parity — see the [middleware reference](https://php.zeal.ninja/middleware) for the full list |
| **HTTP/1.1 compliance** | HEAD, OPTIONS, 301/302/307/308 redirects, Cookie SameSite, ETag, OpenSwoole compression |
| **Shared memory** | `Store` (OpenSwoole\Table) + `Counter` (OpenSwoole\Atomic) — cross-worker state |
| **Timers** | `App::tick()`, `App::after()`, `App::onWorkerStart()` — per-worker recurring tasks |
| **ZealAPI** | File-based REST: drop `api/device/list.php` → `/api/device/list` works automatically |
| **Templating** | Nested `App::render()` / `App::renderToString()` — single `_master.php`, component-based |
| **Sessions** | All `session_*()` functions overridden via ext-zealphp — coroutine-safe, per-request isolation |
| **Unit tests** | PHPUnit 11 — extensive unit and integration test suites, all green |
| **Benchmarks** | OpenSwoole-powered concurrency with a modular `scripts/bench.sh` runner for wrk/ab sweeps through c=1000 |

> **Performance:** 117k req/s text · 106k JSON · 50k templated with 4 HTTP workers under the full PSR-15 middleware stack, 0 failures across 150k requests. ZealPHP retains ~82% of OpenSwoole's raw throughput with the framework on top; numbers vary by workload, payload, and hardware.
>
> Reproduce in 60s: `./scripts/bench_vs_express.sh`. Full methodology, latency percentiles, concurrency sweep, and caveats: [PERF.md](PERF.md).
> **Stability:** Active alpha (v0.4.x). API may change between minor versions until v1.0. Pin to a specific version in production.

> **Common Apache + nginx behavior coverage.** ZealPHP ships built-in middlewares and server-level setters for the common `.htaccess` / `nginx.conf` patterns used by traditional PHP apps — rewrite-style routing, headers, expiry/cache rules, basic auth, IP access, rate limits, MIME types, request limits. This is coverage for migration use cases, not a byte-for-byte server replacement. The 30+ built-in middlewares (`HeaderMiddleware`, `BasicAuthMiddleware`, `RateLimitMiddleware`, `CharsetMiddleware`, `CacheControlMiddleware`, `ExpiresMiddleware`, `IpAccessMiddleware`, `ConcurrencyLimitMiddleware`, `BlockPhpExtMiddleware`, `MimeTypeMiddleware`, `BodyRewriteMiddleware`, `HostRouterMiddleware`, …) plus server-level configurables (`$server_admin`, `$canonical_name`, `$trusted_proxies` + `App::clientIp()`, `$access_log_format`, `LimitRequestFields` family, `$strip_trailing_slash`, `App::tryInclude()`, …) map onto the familiar Apache/nginx directives. See the [middleware reference](https://php.zeal.ninja/middleware) and the [legacy-apps coverage matrix](https://php.zeal.ninja/legacy-apps) for the full story.

---

## Why ZealPHP?

**The architectural shift: PHP becomes the HTTP server. The migration story is the on-ramp; the destination is "your existing PHP code, plus WebSockets/SSE/coroutines/shared memory/timers, all in one PHP application server."**

PHP powers ~71% of the web ([W3Techs](https://w3techs.com/technologies/details/pl-php)), but the default request-per-process model (PHP-FPM, mod_php) keeps the interpreter warm yet discards request-local state, and gives PHP no native way to hold a persistent connection — so WebSocket/SSE features land in separate Node/Go sidecar processes. ZealPHP runs on **OpenSwoole** — a long-lived PHP server with native coroutines — and adds a framework layer that:

1. **Accepts many traditional PHP patterns unchanged (compatibility runtime).** Drop `.php` files in `public/`. `session_start()`, `header()`, `$_GET`, `$_POST`, `setcookie()`, `echo`, `exit`/`die` all route through ext-zealphp overrides into per-coroutine request state. `coroutine-legacy` runs request-style PHP under coroutine concurrency; `legacy-cgi` (subprocess, Apache mod_php parity) is the floor for pure-`require_once` apps with no autoloader. The [50-app compatibility database](docs/compatibility-database.md) grades each path honestly — see also [zealphp-wordpress](https://github.com/sibidharan/zealphp-wordpress) for the WordPress showcase and documented limits. Compatibility is a migration on-ramp, not a guarantee that every PHP application is safe to drop in without an audit.
2. **Adds async primitives when you want them.** `go()`, `Channel`, WebSocket, SSE, shared memory (`Store` / `Counter`), timers, task workers — all framework-native, no extra services.
3. **Lets you migrate file by file.** Start with fallback routing on day one; opt into coroutine mode when you're ready. No big-bang rewrite.

### vs other ways to make PHP async

- **vs PHP-FPM / mod_php** — FPM keeps workers warm but discards request-local memory and treats PHP as a CGI worker, not the HTTP server. ZealPHP IS the HTTP server: caches survive across requests, and SSE/WebSocket connections are much cheaper to keep open than under request-per-process PHP (real capacity still depends on file descriptors, heartbeat policy, and OS tuning).
- **vs Laravel Octane** — Octane wraps Swoole inside a Laravel kernel. ZealPHP is framework-agnostic and exposes the runtime primitives directly. If you're on Laravel and want it faster, use Octane.
- **vs FrankenPHP / RoadRunner** — Go servers fronting PHP. ZealPHP runs native PHP coroutines on OpenSwoole — no Go process in between.
- **vs ReactPHP / AMPHP** — Library collections you wire together. ZealPHP is the integrated framework on top.
- **vs raw Swoole / OpenSwoole** — ZealPHP adds routing, PSR-15 middleware, templates, session overrides, and the legacy bridge so you don't write `onRequest` handlers by hand.
- **vs Node.js** — Different language and ecosystem; not the same trade-off space. If you're already in JS, stay in JS. ZealPHP exists for teams that want OpenSwoole-style concurrency without leaving PHP, or that need to bring a PHP codebase along.

[Full comparison →](https://php.zeal.ninja/why-zealphp)

---

## Quick Start

### Docker (fastest path — no system setup)

```bash
git clone https://github.com/sibidharan/zealphp.git
cd zealphp
docker compose up app
# → http://localhost:8080
```

### Composer (requires PHP 8.3+, OpenSwoole, ext-zealphp)

```bash
# New project
composer create-project zealphp/project:^0.4.8 my-project
cd my-project
php app.php
# → https://php.zeal.ninja
```

```php
<?php
// app.php
require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\G;

App::superglobals(false);  // full coroutine mode (recommended)
$app = App::init('0.0.0.0', 8080);

// Simple route — return array → JSON automatically
$app->route('/hello/{name}', function($name) {
    return ['hello' => $name, 'framework' => 'ZealPHP'];
});

// Parameter injection: $request, $response, $app auto-injected by name
$app->route('/greet/{id}', function($id, $request, $response) {
    $response->header('X-User-Id', $id);
    return ['id' => $id, 'method' => $request->server['REQUEST_METHOD']];
});

// Parallel coroutine fetch — 3 sources in ~1s not 3s
$app->route('/parallel', function() {
    $ch = new \OpenSwoole\Coroutine\Channel(3);
    go(fn() => [$ch->push(fetch('users')),  co::sleep(1)]);
    go(fn() => [$ch->push(fetch('orders')), co::sleep(1)]);
    go(fn() => [$ch->push(fetch('stats')),  co::sleep(1)]);
    $results = [];
    for ($i = 0; $i < 3; $i++) $results[] = $ch->pop();
    return $results;
});

// SSR streaming — browser gets HTML progressively
$app->route('/page', function() {
    return (function() {
        yield '<html><body><h1>Shell (instant)</h1>';
        co::sleep(1); yield '<div>Section 1</div>';
        co::sleep(1); yield '<div>Section 2</div>';
        yield '</body></html>';
    })();
});

// WebSocket
$app->ws('/ws/echo',
    onMessage: fn($server, $frame) => $server->push($frame->fd, 'echo: ' . $frame->data),
    onOpen:    fn($server, $req)   => $server->push($req->fd, json_encode(['event' => 'connected']))
);

$app->run();
```

---

## Architecture

```
                ┌──────────────────────────────────────────┐
   HTTP/WS ───▶ │  OpenSwoole Server (WebSocket\Server)    │
                └────────────────────┬─────────────────────┘
                                     │
                ┌────────────────────▼─────────────────────┐
                │  CoSessionManager (onRequest handler)    │
                │  · creates G singleton per coroutine     │
                │  · populates $g->get/post/cookie/server  │
                └────────────────────┬─────────────────────┘
                                     │
                ┌────────────────────▼─────────────────────┐
                │  PSR-15 Middleware Stack                 │
                │  CORS → ETag → Compression → Range → ... │
                └────────────────────┬─────────────────────┘
                                     │
                ┌────────────────────▼─────────────────────┐
                │  ResponseMiddleware (innermost)          │
                │  · matches route + injects params        │
                │  · invokes handler                       │
                │  · resolves int/array/string/Generator   │
                └────────────────────┬─────────────────────┘
                                     │
            ┌────────────────────────┼────────────────────────┐
            ▼                        ▼                        ▼
     Closure handler         ZealAPI (api/*.php)      Legacy fallback
                                                       (CGI worker)

  Cross-worker primitives: Store (OpenSwoole\Table) + Counter (Atomic) + Cache
  Per-request state:       G::instance() — coroutine-local context
  ext-zealphp overrides:    header() · session_start() · setcookie() · $_GET
```

The ext-zealphp function overrides are the framework's load-bearing trick: legacy PHP code calls `session_start()` or `header()` unchanged, but the calls route to per-coroutine state instead of mutating process globals. This lets many traditional PHP patterns — including unmodified WordPress in compatibility mode — run on OpenSwoole's coroutine runtime, with documented limits where the legacy bridge can't fully match Apache's request isolation.

More detail in [docs/runtime-architecture.md](docs/runtime-architecture.md).

---

## Migrate an Existing PHP App

ZealPHP can run your existing PHP codebase on a high-performance async runtime — `session_start()`, `header()`, `$_GET`, `$_POST` all work unchanged:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;

App::mode(App::MODE_COROUTINE_LEGACY);  // request-style PHP, concurrently
// (or App::MODE_LEGACY_CGI for pure require_once apps — subprocess, mod_php parity)
$app = App::init('0.0.0.0', 8080);

// Your existing PHP app becomes the fallback handler.
// App::include() takes a public/-relative path (Apache document-root
// convention) — leading slash optional. The framework auto-populates
// $_SERVER['PHP_SELF'] / SCRIPT_NAME / SCRIPT_FILENAME, mod_php-style.
$app->setFallback(fn() => App::include('/index.php'));

$app->run();
```

Now your WordPress, Drupal, or custom PHP app runs on OpenSwoole — persistent connections, no cold starts, WebSocket and streaming available when you're ready. ZealPHP's **file-execution family** — `App::render()` / `App::renderToString()` / `App::renderStream()` / `App::include()` — share a single core that runs the file, captures its output, and applies the [universal return contract](https://php.zeal.ninja/responses#return-contract). Need htmx-style swap targets without separate partial files? `App::fragment()` (v0.2.24) marks named regions inline so the same `App::render()` call serves either the full page or just one region — see [Template fragments](https://php.zeal.ninja/templates#fragments). `App::includeFile()` is the deprecated alias for `App::include()` and still works. See the [legacy apps page](https://php.zeal.ninja/legacy-apps) for the Apache rewrite recipes and the full `.htaccess` / `nginx.conf` coverage matrices.

---

## Background Run

Run ZealPHP detached from the terminal:

```bash
php app.php start -d        # daemonize
php app.php restart
php app.php status
php app.php logs
php app.php stop
```

PID file lives at `/tmp/zealphp/zealphp_{port}.pid` (one per port — multiple
apps on different ports are supported). Logs go to `/tmp/zealphp/`:
`server.log`, `access.log`, `debug.log`, `zlog.log`. `php app.php logs` tails
all four; add `--access`, `--debug`, `--server`, or `--zlog` to filter.

If a server is already running on the same port, `start` prints the existing
PID and exits cleanly instead of crashing. `restart` stops then starts using
the same defaults. Target a specific instance with `-p PORT` on
`stop`/`status`/`restart`.

`scripts/zealphp.sh` is an optional shell wrapper around the same commands.

---

## Docker Benchmark

Run the benchmark in Docker with PHP, OpenSwoole, ext-zealphp, Composer deps, and `wrk`
inside the image:

```bash
mkdir -p bench/results
docker compose run --rm --build bench
```

Results are written to `bench/results/` on the host.
On Docker Desktop for Mac, set Resources -> CPU limit to 16 if you want the
container to use all 16 cores.

For a quad-core ZealPHP vs Node.js comparison:

```bash
mkdir -p bench/results
docker compose run --rm --build compare
```

Set `ZEALPHP_BENCH_MODE=1` to skip the demo middleware and session file I/O on
the benchmark path. The sample auth/validation middleware is opt-in via
`ZEALPHP_DEMO_MIDDLEWARE=1`.
Set `ZEALPHP_LOG_DIR=/tmp/zealphp` to send `debug.log`, `access.log`, and
`zlog.log` there, and keep `ZEALPHP_LOG_ASYNC=1` so request logging is queued
off the hot path. Use `ZEALPHP_DEBUG_LOG=0` and `ZEALPHP_ACCESS_LOG=0` for
quiet runs.
If `/tmp/zealphp` is not writable, ZealPHP falls back to a writable local log
directory.

---

## Installation

> **Requirements:** PHP 8.3, 8.4, or 8.5. OpenSwoole 22.1+ covers PHP 8.3 / 8.4; OpenSwoole 26.2+ adds PHP 8.5. ext-zealphp is ZealPHP's own C extension (`uopz` is a legacy fallback for the function overrides). The fastest path is the one-line installer or `setup.sh` below — the manual steps are for fine-grained control.

### 1. Install OpenSwoole

```bash
sudo apt install gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential

sudo pecl install openswoole
# Answer yes to: coroutine sockets, openssl, http2, mysqlnd, curl, postgres
```

Add to `/etc/php/8.3/cli/conf.d/99-zealphp.ini`:
```ini
extension=openswoole.so
extension=zealphp.so
short_open_tag=on
```

### 2. Install ext-zealphp

```bash
# Via PIE (recommended):
pie install zealphp/ext

# Or from source (pin the version setup.sh defaults to):
git clone --depth 1 --branch v0.3.49 https://github.com/sibidharan/ext-zealphp.git /tmp/ext-zealphp
cd /tmp/ext-zealphp && phpize && ./configure && make && sudo make install
```

### 3. Verify

```bash
php -m | grep -E 'openswoole|zealphp'
# openswoole
# ext-zealphp
```

Or use the automated setup:
```bash
sudo bash setup.sh
```

---

## Testing

```bash
# Unit tests — no server needed
./vendor/bin/phpunit tests/Unit/ --testdox

# Integration tests — server must be running
php app.php &
./vendor/bin/phpunit tests/Integration/ --testdox

# All tests
./vendor/bin/phpunit --testdox
```

**Unit suites** (`tests/Unit/`): `StoreTest`, `CounterTest`, `BuildParamMapTest`, `RoutePatternTest`  
**Integration suites** (`tests/Integration/`): `RoutingTest`, `HttpFeaturesTest`, `MiddlewareTest`, `StreamingTest`

---

## Make targets

Common dev tasks are wrapped in a `Makefile` — run `make` (or `make help`) to list them. They're thin wrappers over the `composer` / `vendor/bin/*` / `php app.php` / `scripts/*.sh` commands documented above, so they never drift.

```bash
make help                 # list every target
make install              # composer install
make serve / restart / stop / status / logs    # the php app.php server CLI (PORT overridable)
make unit / integration / test                  # PHPUnit suites
make stan                 # PHPStan static analysis (level 10)
make check                # unit + stan — the pre-commit gate
make coverage / coverage-full / infection        # coverage + mutation testing (MSI)
make docs / docs-rebuild  # build / force-rebuild the API reference
make bench / perf-smoke   # benchmarks
```

---

## Core Concepts

### Parameter Injection

ZealPHP uses reflection (cached at route registration, zero overhead per request) to inject handler arguments by name:

```php
// URL param only
$app->route('/users/{id}', function($id) { return ['id' => $id]; });

// URL + $request
$app->route('/users/{id}', function($id, $request) {
    return ['id' => $id, 'method' => $request->server['REQUEST_METHOD']];
});

// $response for header/cookie control
$app->route('/users/{id}', function($id, $response) {
    $response->header('X-Id', $id);
    return ['id' => $id];
});

// Default values
$app->route('/posts/{slug}/{page?}', function($slug, $page = 1) {
    return ['slug' => $slug, 'page' => $page];
});
```

### Middleware

```php
// Built-in middleware
$app->addMiddleware(new \ZealPHP\Middleware\CorsMiddleware());
$app->addMiddleware(new \ZealPHP\Middleware\ETagMiddleware());
// HTTP compression is handled by OpenSwoole by default.

// Custom PSR-15 middleware
class TimingMiddleware implements MiddlewareInterface {
    public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface {
        $start = microtime(true);
        $response = $next->handle($req);
        response_add_header('X-Time', round((microtime(true)-$start)*1000, 2).'ms');
        return $response;
    }
}
```

### Store & Counter (cross-worker shared memory)

```php
// Create BEFORE $app->run() — shared across all forked workers
$clientTable = Store::make('clients', 4096, [
    'room' => [Store::TYPE_STRING, 64],
    'uid'  => [Store::TYPE_STRING, 128],
]);
$hitCounter = new Counter(0);

// In any route — every forked worker sees the same data
Store::set('clients', "$fd", ['room' => 'general', 'uid' => 'alice']);
$hitCounter->increment();
```

### Timers (per-worker)

```php
App::onWorkerStart(function($server, $workerId) use ($hitCounter) {
    App::tick(60000, fn() => elog("Hits/min: " . $hitCounter->get()));
    $hitCounter->reset();
});
```

---

## Design Principles

**Coroutine mode (recommended):** `App::superglobals(false)` enables `OpenSwoole\Runtime::HOOK_ALL` so all PHP I/O (file, curl, PDO, sleep) yields the event loop automatically. Each request runs in its own coroutine with isolated `RequestContext::instance()` state (`G` remains as a `class_alias` for `RequestContext` since v0.2.6 — both names resolve to the same class). This is the default for new scaffolds since v0.2.4.

**Superglobals mode (legacy compatibility):** `App::superglobals(true)` disables coroutines in the main thread — `$_GET`, `$_POST`, `$_SESSION` work safely because only one request runs at a time per worker. Use this when migrating existing apps incrementally. Implicit file routes for legacy code run through the CGI bridge (`App::include()`) via a **pre-spawned subprocess pool** (`cgiMode('pool')`, entry script `src/pool_worker.php`, ~1–3 ms warm, the default) so blocking PHP runs in a child process with its own arena. `cgiMode('proc')` opts into a fresh `proc_open` subprocess per request (~30–50 ms cold, entry script `src/cgi_worker.php`) for the rare case that demands fully fresh-process semantics.

**`coprocess` / `coproc`:** Available in superglobals mode — spawns a child process for background async work. Not needed in coroutine mode (use `go()` directly).

**Lifecycle modes — one-call presets:** `App::mode(string)` sets both the superglobals strategy and the isolation strategy in one call. Four presets:

| Preset | Use for |
|--------|---------|
| `App::mode(App::MODE_COROUTINE)` | Modern ZealPHP apps — recommended default |
| `App::mode(App::MODE_LEGACY_CGI)` | Unmodified WordPress/Drupal — pure `require_once` apps |
| `App::mode(App::MODE_COROUTINE_LEGACY)` | Legacy request-style PHP run **concurrently** — per-coroutine isolation of `$_GET/$_POST/$_SESSION`, `$GLOBALS`, function statics, `require_once`, silent re-declaration. `define()` isolation is opt-in via `App::defineIsolation(true)`, not part of the preset. **Requires ext-zealphp.** |
| `App::mode(App::MODE_MIXED)` | Symfony/Laravel bridge — real `$_SESSION`, sequential, no CGI fork cost |

The fine-grained setters (`App::superglobals()`, `App::isolation()`, `App::enableCoroutine()`, etc.) remain available for custom combinations. See the [lifecycle modes reference](https://php.zeal.ninja/coroutines#lifecycle-modes) for the full matrix.

**ext-zealphp overrides:** `header()`, `setcookie()`, all `session_*()` functions are permanently replaced at startup via `ext-zealphp` overrides. This makes existing PHP code work unchanged inside the long-running OpenSwoole process.

---

## Publishing Releases

`master` is branch-protected — releases land via a PR, then the tag is cut on the merged commit.

1. Update `CHANGELOG.md` with the new version and changes.
2. Run `composer validate`, `./vendor/bin/phpunit`, and `./vendor/bin/phpstan analyse --no-progress` (level 10, zero errors).
3. Open a release PR, wait for required checks to go green, and merge (rebase):
   ```bash
   git checkout -b release/v0.4.8
   git commit -am "chore: release v0.4.8"
   git push origin1 release/v0.4.8
   gh pr create --base master --head release/v0.4.8 --title "chore: release v0.4.8"
   ```
4. After merge, tag the merged commit and push to both remotes (tags aren't protected):
   ```bash
   git checkout master && git pull origin1 master --ff-only
   git tag -a v0.4.8 -m "Release v0.4.8"
   git push origin v0.4.8 && git push origin1 v0.4.8
   ```
5. Bump `zealphp/project` (the scaffold) to the same version and refresh its `llms.txt`. Packagist auto-syncs via webhook for both packages.

---

## Common Errors

**OpenSwoole not installed:**
```
PHP Fatal error: Class "OpenSwoole\HTTP\Server" not found
```
→ Install OpenSwoole via PECL and add `extension=openswoole.so` to php.ini.

**ext-zealphp not installed:**
```
Exception: ext-zealphp or uopz extension is required for ZealPHP to work
```
→ `pie install zealphp/ext` and add `extension=zealphp.so` to php.ini. Or build from source — see the install section above.

**IDE autocompletion:**  
Add to VS Code `settings.json`:
```json
"intelephense.environment.includePaths": ["vendor/openswoole/ide-helper"]
```

---

Any and all contributions are welcome ❤️
