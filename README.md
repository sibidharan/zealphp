# ZealPHP — Coroutine-Native PHP Framework on OpenSwoole

A coroutine-native PHP framework built on **OpenSwoole** for high-concurrency HTTP, WebSocket, streaming, and real-time applications. Start serving existing PHP apps on OpenSwoole today — migrate to full async at your own pace.

[![Latest Stable Version](https://poser.pugx.org/sibidharan/zealphp/v)](https://packagist.org/packages/sibidharan/zealphp) [![Total Downloads](https://poser.pugx.org/sibidharan/zealphp/downloads)](https://packagist.org/packages/sibidharan/zealphp) [![License](https://poser.pugx.org/sibidharan/zealphp/license)](https://packagist.org/packages/sibidharan/zealphp)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/sibidharan/zealphp) [![GitHub stars](https://img.shields.io/github/stars/sibidharan/zealphp?style=flat-square&logo=github&logoColor=white)](https://github.com/sibidharan/zealphp/stargazers) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![CI](https://github.com/sibidharan/zealphp/actions/workflows/tests.yml/badge.svg)](https://github.com/sibidharan/zealphp/actions/workflows/tests.yml) [![Coverage](https://codecov.io/gh/sibidharan/zealphp/branch/master/graph/badge.svg)](https://codecov.io/gh/sibidharan/zealphp) [![PHPStan](https://img.shields.io/badge/PHPStan-level%201-brightgreen?style=flat-square&logo=php&logoColor=white)](phpstan.neon)
[![OpenSwoole](https://img.shields.io/badge/OpenSwoole-22%2B-ff5722?style=flat-square)](https://openswoole.com/) [![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa?style=flat-square)](CODE_OF_CONDUCT.md) [![Sponsor](https://img.shields.io/github/sponsors/sibidharan?style=flat-square&logo=github&logoColor=white)](https://github.com/sponsors/sibidharan)

**Homepage:** [https://php.zeal.ninja](https://php.zeal.ninja)  
Running `php app.php` serves the same docs site locally. Set `ZEALPHP_SITE_URL` if you want the rendered example URLs to point somewhere else.
**Changelog:** [CHANGELOG.md](CHANGELOG.md)

---

## Features

| Feature | Details |
|---------|---------|
| **Async coroutines** | `go()` + `Channel` — thousands of concurrent requests per worker |
| **SSR streaming** | Generator `yield`, `$response->stream()`, `$response->sse()` — like React's `renderToPipeableStream` |
| **WebSocket** | `App::ws($path, $onMessage, $onOpen, $onClose)` — rooms, auth, binary, heartbeat |
| **Dynamic routing** | `route()`, `nsRoute()`, `nsPathRoute()`, `patternRoute()` with reflection-based parameter injection |
| **Middleware** | PSR-15 stack — CORS, ETag/304, and custom middleware in any order |
| **HTTP/1.1 compliance** | HEAD, OPTIONS, 301/302/307/308 redirects, Cookie SameSite, ETag, OpenSwoole compression |
| **Shared memory** | `Store` (OpenSwoole\Table) + `Counter` (OpenSwoole\Atomic) — cross-worker state |
| **Timers** | `App::tick()`, `App::after()`, `App::onWorkerStart()` — per-worker recurring tasks |
| **ZealAPI** | File-based REST: drop `api/users/get.php` → `/api/users/get` works automatically |
| **Templating** | Nested `App::render()` / `App::renderToString()` — single `_master.php`, component-based |
| **Sessions** | All `session_*()` functions overridden via uopz — coroutine-safe, per-request isolation |
| **Unit tests** | PHPUnit 11 — 42 unit tests + 38 integration tests, all green |
| **Benchmarks** | OpenSwoole-powered concurrency with a modular `scripts/bench.sh` runner for wrk/ab sweeps through c=1000 |

> **Performance:** 80K+ req/s with full PSR-15 middleware stack on a 16-core machine. See [PERF.md](PERF.md) for the methodology.
> **Stability:** Alpha (v0.1.x). API may change between minor versions. Pin to a specific version in production.

---

## Why ZealPHP?

PHP powers 77% of the web, but its request-per-process model makes real-time, streaming, and high-concurrency apps awkward. ZealPHP is **not** another abstraction layer — it's a full-stack coroutine framework:

- **vs ReactPHP / AMPHP** — ZealPHP is an integrated framework (routing, middleware, templates, shared memory), not a library collection. Write `$app->route()` and ship.
- **vs FrankenPHP / RoadRunner** — Those are Go-based servers. ZealPHP runs native PHP coroutines — `go()`, `Channel`, shared memory via `OpenSwoole\Table` — no Go process in between.
- **vs Laravel Octane** — Octane wraps Swoole inside Laravel. ZealPHP is framework-agnostic and exposes the full coroutine runtime: SSE, WebSocket, streaming, task workers.
- **vs raw Swoole/OpenSwoole** — ZealPHP adds routing, PSR-15 middleware, templating, session overrides, and a legacy PHP bridge so you don't wire up `onRequest` handlers manually.

**Legacy PHP bridge:** `session_start()`, `header()`, `$_GET` all work unchanged via uopz overrides. WordPress runs unmodified through the CGI worker.

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

### Composer (requires PHP 8.3+, OpenSwoole, uopz)

```bash
# New project
composer create-project sibidharan/zealphp-project:^0.2.0 my-project
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
  uopz overrides:          header() · session_start() · setcookie() · $_GET
```

The uopz function overrides are the framework's load-bearing trick: legacy PHP code calls `session_start()` or `header()` unchanged, but the calls route to per-coroutine state instead of mutating process globals. This lets unmodified WordPress and other legacy apps run on OpenSwoole's coroutine runtime.

More detail in [docs/runtime-architecture.md](docs/runtime-architecture.md).

---

## Migrate an Existing PHP App

ZealPHP can run your existing PHP codebase on a high-performance async runtime — `session_start()`, `header()`, `$_GET`, `$_POST` all work unchanged:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;

App::superglobals(true);  // legacy mode — $_GET, $_POST, $_SESSION work
$app = App::init('0.0.0.0', 8080);

// Your existing PHP app becomes the fallback handler
$app->setFallback(fn() => App::includeFile('index.php'));

$app->run();
```

Now your WordPress, Drupal, or custom PHP app runs on OpenSwoole — persistent connections, no cold starts, WebSocket and streaming available when you're ready.

---

## Background Run

Use the helper when you want ZealPHP detached from the terminal:

```bash
scripts/zealphp.sh start
scripts/zealphp.sh restart
scripts/zealphp.sh status
scripts/zealphp.sh logs
scripts/zealphp.sh stop
```

It writes the PID file to `/tmp/zealphp/zealphp.pid` by default, stores the
server log in `/tmp/zealphp/server.log`, and tails `server.log`,
`access.log`, `debug.log`, and `zlog.log` from the same directory. If a server
is already running, the start command asks before killing and restarting it.
`restart` stops the whole live server group, then starts it again with the same
defaults without prompting.

Use `scripts/zealphp.sh foreground` if you want the attached mode back.

---

## Docker Benchmark

Run the benchmark in Docker with PHP, OpenSwoole, uopz, Composer deps, and `wrk`
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

### 1. Install OpenSwoole

```bash
sudo apt install gcc php-dev openssl libssl-dev curl libcurl4-openssl-dev libpcre3-dev build-essential

sudo pecl install openswoole-22.1.2
# Answer yes to: coroutine sockets, openssl, http2, mysqlnd, curl, postgres
```

Add to `/etc/php/8.3/cli/conf.d/99-zealphp.ini`:
```ini
extension=openswoole.so
extension=uopz.so
short_open_tag=on
```

### 2. Install uopz

```bash
sudo pecl install uopz
```

### 3. Verify

```bash
php -m | grep -E 'openswoole|uopz'
# openswoole
# uopz
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
    'room' => [\OpenSwoole\Table::TYPE_STRING, 64],
    'uid'  => [\OpenSwoole\Table::TYPE_STRING, 128],
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

**Coroutine mode (recommended):** `App::superglobals(false)` enables `OpenSwoole\Runtime::HOOK_ALL` so all PHP I/O (file, curl, PDO, sleep) yields the event loop automatically. Each request runs in its own coroutine with isolated `G::instance()` state. This is the default in the demo app.

**Superglobals mode (legacy compatibility):** `App::superglobals(true)` disables coroutines in the main thread — `$_GET`, `$_POST`, `$_SESSION` work safely because only one request runs at a time per worker. Implicit file routes use `prefork_request_handler()` (forks a child process) to run blocking PHP safely. Use this when migrating existing apps incrementally.

**`coprocess` / `coproc`:** Available in superglobals mode — spawns a child process with coroutine context for background async work. Not needed in coroutine mode (use `go()` directly).

**uopz overrides:** `header()`, `setcookie()`, all `session_*()` functions are permanently replaced at startup via `uopz_set_return()`. This makes existing PHP code work unchanged inside the long-running OpenSwoole process.

---

## Publishing Releases

1. Update `CHANGELOG.md` with the new version and changes.
2. Run `composer validate` and confirm tests pass.
3. Tag both `zealphp` and `zealphp-project` with the same version:
   ```bash
   git tag -a v0.2.0 -m "Release v0.2.0"
   git push origin master && git push origin v0.2.0
   ```
4. Trigger Packagist webhook for both packages.

---

## Common Errors

**OpenSwoole not installed:**
```
PHP Fatal error: Class "OpenSwoole\HTTP\Server" not found
```
→ Install OpenSwoole via PECL and add `extension=openswoole.so` to php.ini.

**uopz not installed:**
```
Exception: uopz extension is required for ZealPHP to work
```
→ `sudo pecl install uopz` and add `extension=uopz.so` to php.ini.

**IDE autocompletion:**  
Add to VS Code `settings.json`:
```json
"intelephense.environment.includePaths": ["vendor/openswoole/ide-helper"]
```

---

Any and all contributions are welcome ❤️
