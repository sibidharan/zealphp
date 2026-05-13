# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

ZealPHP is a PHP web framework library built on **OpenSwoole**. This repo is the framework itself — `app.php` and `api/`, `public/`, `route/`, `template/` are the built-in demo app / OSS website that exercises every framework feature.

## Commands

```bash
# Install PHP dependencies (including PHPUnit dev dep)
composer install

# Start the dev server — serves the OSS website on :8080
php app.php

# Start with explicit HTTP worker/task worker counts
ZEALPHP_WORKERS=16 ZEALPHP_TASK_WORKERS=0 php app.php

# Unit tests (no server needed)
./vendor/bin/phpunit tests/Unit/ --testdox

# Integration tests (server must be running on :8080)
php app.php &
./vendor/bin/phpunit tests/Integration/ --testdox

# All tests
./vendor/bin/phpunit --testdox

# Install system dependencies (PHP 8.3, OpenSwoole, uopz) — requires root
sudo bash setup.sh

# Verify required extensions are loaded
php -m | grep -E 'openswoole|uopz'

# Local performance sweep (defaults to 16 workers and c=1000)
scripts/bench.sh --p1000

# Dockerized benchmark sweep
mkdir -p bench/results && docker compose run --rm --build bench

# Dockerized quad-core ZealPHP vs Node.js comparison
mkdir -p bench/results && docker compose run --rm --build compare
```

---

## Testing

PHPUnit 11 test suite lives in `tests/`. `ZEALPHP_TEST_PORT` env var sets the server port (defaults to `8080`).

### Unit tests (`tests/Unit/`) — no server needed
| File | What it tests |
|------|--------------|
| `StoreTest.php` | `Store::make`, `set/get/del`, `exists`, `incr/decr`, `count`, `iterate` |
| `CounterTest.php` | `increment/decrement/byN`, `CAS`, `reset`, `raw()` |
| `BuildParamMapTest.php` | Every parameter injection case via reflection |
| `RoutePatternTest.php` | `{param}` → regex, namespace prefix, method casing |
| `CompressionMiddlewareTest.php` | Reference compression middleware gzip/proxy-skip behavior |

### Integration tests (`tests/Integration/`) — requires `php app.php`
| File | What it tests |
|------|--------------|
| `RoutingTest.php` | All 7 injection cases + route types + 404 |
| `HttpFeaturesTest.php` | 301/302/307, HEAD, OPTIONS, cookies, CORS |
| `MiddlewareTest.php` | CORS preflight, ETag + 304, OpenSwoole gzip |
| `StreamingTest.php` | Generator SSR, `stream()`, SSE events |

`tests/TestCase.php` — base class with `http()`, `get()`, `post()`, `assertStatus()`, `assertHeader()`, `assertJsonResponse()` helpers. HEAD requests use `CURLOPT_NOBODY` for correct header parsing.

---

## Architecture

### Request Lifecycle

Every inbound request flows through these layers (defined across multiple files):

1. **OpenSwoole WebSocket\Server** (`src/App.php:run()`) receives the raw request. (WebSocket\Server extends HTTP\Server — all HTTP routes still work.)
2. **CoSessionManager** (`src/Session/CoSessionManager.php`) is registered as the `onRequest` handler in coroutine mode. It initialises the session, creates `ZealPHP\HTTP\Request`/`Response` wrappers, and stores them in `G::instance()`.
3. **G singleton** (`src/G.php`) is populated with `get`, `post`, `cookie`, `server`, `files`, `session`, `zealphp_request`, `zealphp_response`, etc.
4. **PSR-15 middleware stack** (`OpenSwoole\Core\Psr\Middleware\StackHandler`) is invoked via `App::middleware()->handle($serverRequest)`.
5. **ResponseMiddleware** (inner-most layer, bottom of `src/App.php`) matches the URI against the route table, resolves handler parameters by name via reflection, calls the handler, and wraps the return value as a PSR-7 response.

### G Class — Dual-Mode Global State

`G::instance()` (`src/G.php`) is the per-request global state container. Its behaviour depends on the mode:

| Mode | `G::instance()` returns |
|------|------------------------|
| Superglobals ON | A single process-wide singleton; `$g->session` proxies to `$_SESSION`, `$g->get` to `$_GET`, etc. via `$GLOBALS` |
| Superglobals OFF | A per-coroutine instance stored in `OpenSwoole\Coroutine::getContext()` — each coroutine has isolated state |

The **demo app uses `superglobals(false)` (coroutine mode)**. This is now the recommended default for new projects.

### uopz Function Overrides

At startup (`src/App.php:__construct()`), `uopz_set_return()` permanently replaces PHP built-ins:

- `header()`, `headers_list()`, `setcookie()` (+ `$samesite` param), `http_response_code()` → implementations in `src/utils.php` that write to `$g->zealphp_response`
- All `session_*()` functions → implementations in `src/Session/utils.php` that read/write `$g->session` and file-based session storage in `/var/lib/php/sessions`

This lets legacy PHP code call `header()` or `session_start()` unchanged while the framework routes those calls to the correct per-request objects.

### IOStreamWrapper

`src/IOStreamWrapper.php` replaces the `php://` stream wrapper (registered once per worker in `workerStart`). When code reads `php://input`, the wrapper instead returns `$g->zealphp_request->parent->getContent()`. Other `php://` streams are delegated to the original wrapper.

### Route Registration and Priority

Routes are registered in this order inside `App::run()` (earlier = higher priority):

1. Files from `route/*.php` (loaded at startup via `glob`)
2. Explicit routes defined in `app.php` before `$app->run()` is called
3. Implicit API routes: `nsPathRoute('api', ...)` → delegates to `ZealAPI::processApi()`
4. `.php` extension block (returns 403)
5. Implicit public file routes: `/` → `public/index.php`, `/{file}` → `public/{file}.php`, `/{dir}/{uri}` → `public/{dir}/{uri}.php`

**API handler naming rule**: `api/users/get.php` must define `$get = function(...)`. The variable name must match `basename($file, '.php')`. ZealAPI binds it as a closure with `$this` set to the `ZealAPI` instance.

### Parameter Injection

`ResponseMiddleware` uses reflection (cached at route registration via `buildParamMap()`) to inject handler arguments by name:

| Parameter name | Injected value |
|---------------|---------------|
| `$request` | `ZealPHP\HTTP\Request` wrapper |
| `$response` | `ZealPHP\HTTP\Response` wrapper |
| `$app` | `ResponseMiddleware` instance |
| `{param}` names | Matched URL segments |
| Any other name with default | PHP default value |

Reflection is cached per route at registration time — zero reflection overhead per request.

### Middleware Stack Order

`addMiddleware()` appends to `$middleware_wait_stack`. In `run()`, that array is **reversed** before being added to `StackHandler`. Result: the last-added middleware executes first (outermost wrap), `ResponseMiddleware` always runs innermost.

**Built-in middleware** (all in `src/Middleware/`):
- `CorsMiddleware` — CORS preflight (OPTIONS + Origin) + `Access-Control-*` headers on every response
- `ETagMiddleware` — `W/"md5"` ETag on GET, returns 304 on `If-None-Match` match
- `CompressionMiddleware` — reference gzip/deflate implementation for apps that disable OpenSwoole `http_compression`; the demo app does not register it

### HTTP Protocol Features

| Feature | How |
|---------|-----|
| HEAD method | Auto-mapped to GET in `ResponseMiddleware`; body stripped, `Content-Length` preserved |
| OPTIONS method | Returns 204 + `Allow:` header listing all methods for that URI |
| Redirects 301/307/308 | `$response->redirect($url, $status)` |
| Cookie SameSite | `setcookie()` override accepts `$samesite` param |
| Gzip compression | OpenSwoole `http_compression` is enabled by default in `App::run()`; do not also register `CompressionMiddleware` |
| HTTP/2 | Pass `'enable_http2' => true` to `$app->run()` (requires TLS) |

### SSR Streaming

Three streaming patterns via `src/HTTP/Response.php` and `ResponseMiddleware`:

| Pattern | How | When to use |
|---------|-----|-------------|
| **Generator `yield`** | Return `\Generator`; each `yield $string` sent immediately | SSR — stream HTML shell, yield sections as coroutines resolve |
| **`$response->stream($fn)`** | `$fn` receives `$write(string)` closure; headers flushed before `$fn` runs | Fine-grained streaming control |
| **`$response->sse($fn)`** | `$fn` receives `$emit($data, $event, $id)` — formats SSE wire protocol | Server-Sent Events for JS `EventSource` |

`App::renderToString($template, $args)` — captures `App::render()` into a string safe to `yield` or `$write()` inside a streaming context.

`$g->_streaming = true` is set by `stream()`/`sse()` so `ResponseMiddleware` knows to skip `ob_get_clean()`.

### WebSocket

`App::ws($path, $onMessage, $onOpen, $onClose)` registers a WebSocket endpoint.

- Server switched from `HTTP\Server` to `WebSocket\Server` (backward-compatible; all HTTP routes still work)
- Per-worker `$wsFdMap` tracks `fd → path`; cleaned up in `onClose`
- `onMessage` handler **silently drops PING (9), PONG (10), CONTINUATION (0)** frames — only TEXT (1) and BINARY (2) reach route handlers
- `onShutdown` sends WebSocket CLOSE frame 1001 (Going Away) to all connections
- `App::onWorkerStart(callable $fn)` — register per-worker startup hook (timers, warmup, etc.)
- `getClientList` must be paginated in chunks of 100 (OpenSwoole hard limit)

### OpenSwoole Adapters

**`Store` (`src/Store.php`)** — `OpenSwoole\Table` wrapper for cross-worker shared memory.
- Must be created **before** `$app->run()` (master process, shared on fork)
- `Store::make($name, $maxRows, $columns)` — column types: `TYPE_INT`, `TYPE_FLOAT`, `TYPE_STRING`
- `Store::set/get/del/exists/incr/decr/count/table/names()`

**`Counter` (`src/Counter.php`)** — `OpenSwoole\Atomic` wrapper for lock-free cross-worker integer.
- Must be created before `$app->run()`
- `increment($by=1)`, `decrement($by=1)`, `get()`, `set()`, `reset()`, `compareAndSet($expected, $new)`

**Timers** (via `App::tick/after/clearTimer`):
- `App::tick(int $ms, callable $fn)` — recurring per-worker timer
- `App::after(int $ms, callable $fn)` — one-shot timer
- Must be called inside a coroutine context (`onWorkerStart` or request handler)

### Task Workers

Task handlers live in `task/` (e.g., `task/backup.php`). Dispatch with:

```php
App::getServer()->task(['handler' => '/task/backup', 'args' => [...]]);
```

Task workers run in coroutine mode (`task_enable_coroutine => true` is set by default).

---

## OSS Website

The demo app IS the ZealPHP documentation website. Run `php app.php` and browse `http://localhost:8080`.

### Template System

Single `template/_master.php` used by every page. Every `public/X.php` is 3 lines:

```php
<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP · Routing', 'page' => 'routing', 'active' => 'routing']);
```

`_master.php` reads `$page` and renders `template/pages/$page.php`.

Template structure:
```
template/
  _master.php          — Universal layout (nav + content + footer)
  _head.php            — <head> with CSS/JS links
  _nav.php             — Top navigation
  _footer.php          — Footer
  components/
    _code.php          — Syntax-highlighted code block
    _card.php          — Feature card
    _demo.php          — Split code + live output panel
  pages/               — One file per website section
    home.php, routing.php, responses.php, coroutines.php,
    middleware.php, sessions.php, streaming.php, websocket.php,
    store.php, timers.php, http.php, api.php
```

CSS: `public/css/zealphp.css` — single file, CSS variables, indigo accent, no inline styles.

### Demo API Endpoints

`route/demo.php` — 25 live endpoints used by the website's "LIVE OUTPUT" panels:

- `/demo/inject/{case}` — every parameter injection pattern
- `/demo/route/{type}` — nsRoute, nsPathRoute, patternRoute
- `/demo/response/{method}` — json, redirect, headers, cookie
- `/demo/coroutine/{pattern}` — parallel, channel
- `/demo/store/` and `/demo/counter/` — Store + Counter demos
- `/demo/session/` — write + read session
- `/demo/middleware/` — CORS, ETag, OpenSwoole compression

---

## Examples (`examples/`)

**`examples/*.php` (root level)** — OpenSwoole implementation reference scripts (standalone, not ZealPHP API usage). Do not use as application patterns.

All ZealPHP usage examples live as first-class project files:
- Routes: `route/streaming.php`, `route/ws.php`, `route/timers.php`, `route/http_features.php`, `route/demo.php`
- Public pages: `public/*.php` (website pages)
- APIs: `api/` directory (ZealAPI pattern)
- Templates: `template/pages/*.php`

---

## Source Layout (`src/`)

| File | Role |
|------|------|
| `App.php` | Framework core: init, route registration, `run()`, `ResponseMiddleware`, `render()`/`renderToString()`, `tick()`/`after()`/`onWorkerStart()` |
| `G.php` | Per-request global state; superglobals mode uses static singleton, coroutine mode uses `Coroutine::getContext()` |
| `Store.php` | `OpenSwoole\Table` adapter — cross-worker shared-memory key-value store |
| `Counter.php` | `OpenSwoole\Atomic` adapter — lock-free cross-worker integer counter |
| `ZealAPI.php` | File-based API dispatcher; extends `REST.php` |
| `REST.php` | Base class with input cleaning and response helpers |
| `utils.php` | Global functions: `prefork_request_handler`, `coprocess`, `elog`, `zlog`, `access_log`, `response_add_header`, overridden `header`/`setcookie`/`http_response_code` |
| `Session/utils.php` | Overridden `session_*` functions (file-backed, coroutine-safe) |
| `Session/CoSessionManager.php` | Per-coroutine session lifecycle (superglobals OFF) |
| `Session/SessionManager.php` | Traditional session lifecycle (superglobals ON) |
| `IOStreamWrapper.php` | `php://` stream wrapper that redirects `php://input` to request body |
| `HTTP/Request.php` | Thin wrapper around `OpenSwoole\Http\Request` |
| `HTTP/Response.php` | Thin wrapper around `OpenSwoole\Http\Response`; adds `stream()`, `sse()`, `redirect()`, `flush()` |
| `Middleware/CorsMiddleware.php` | CORS preflight + `Access-Control-*` headers |
| `Middleware/ETagMiddleware.php` | ETag generation + 304 Not Modified |
| `Middleware/CompressionMiddleware.php` | Reference gzip/deflate middleware; only use when OpenSwoole `http_compression` is disabled |
