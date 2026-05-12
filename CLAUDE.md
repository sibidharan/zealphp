# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

ZealPHP is a PHP web framework library built on **OpenSwoole**. This repo is the framework itself — `app.php` and `api/`, `public/`, `route/`, `template/` are a built-in demo app that exercises the framework's features.

## Commands

```bash
# Install PHP dependencies
composer install

# Start the dev server (runs the demo app on :8080)
php app.php

# Install system dependencies (PHP 8.3, OpenSwoole, uopz) — requires root
sudo bash setup.sh

# Verify required extensions are loaded
php -m | grep -E 'openswoole|uopz'
```

There is no test suite in this repository.

---

## Architecture

### Request Lifecycle

Every inbound request flows through these layers (defined across multiple files):

1. **OpenSwoole HTTP server** (`src/App.php:run()`) receives the raw request.
2. **SessionManager or CoSessionManager** (`src/Session/SessionManager.php`, `src/Session/CoSessionManager.php`) is registered as the `onRequest` handler. It initialises the session, creates `ZealPHP\HTTP\Request`/`Response` wrappers, and stores them in `G::instance()`.
3. **G singleton** (`src/G.php`) is populated with `get`, `post`, `cookie`, `server`, `files`, `session`, `zealphp_request`, `zealphp_response`, etc.
4. **PSR-15 middleware stack** (`OpenSwoole\Core\Psr\Middleware\StackHandler`) is invoked via `App::middleware()->handle($serverRequest)`.
5. **ResponseMiddleware** (inner-most layer, bottom of `src/App.php`) matches the URI against the route table, resolves handler parameters by name via reflection, calls the handler, and wraps the return value as a PSR-7 response.

### G Class — Dual-Mode Global State

`G::instance()` (`src/G.php`) is the per-request global state container. Its behaviour depends on the mode:

| Mode | `G::instance()` returns |
|------|------------------------|
| Superglobals ON | A single process-wide singleton; `$g->session` proxies to `$_SESSION`, `$g->get` to `$_GET`, etc. via `$GLOBALS` |
| Superglobals OFF | A per-coroutine instance stored in `OpenSwoole\Coroutine::getContext()` — each coroutine has isolated state |

This is why you **cannot safely share `$_GET`/`$_SESSION` across coroutines in superglobals-OFF mode** — use `G::instance()` instead.

### uopz Function Overrides

At startup (`src/App.php:__construct()`), `uopz_set_return()` permanently replaces PHP built-ins:

- `header()`, `headers_list()`, `setcookie()`, `http_response_code()` → implementations in `src/utils.php` that write to `$g->zealphp_response`
- All `session_*()` functions → implementations in `src/Session/utils.php` that read/write `$g->session` and file-based session storage in `/var/lib/php/sessions`

This lets legacy PHP code call `header()` or `session_start()` unchanged while the framework routes those calls to the correct per-request objects.

### IOStreamWrapper

`src/IOStreamWrapper.php` replaces the `php://` stream wrapper (registered once per worker in `workerStart`). When code reads `php://input`, the wrapper instead returns `$g->zealphp_request->parent->getContent()`. Other `php://` streams are delegated to the original wrapper. This makes `file_get_contents('php://input')` and PSR streams work correctly inside OpenSwoole workers.

### Route Registration and Priority

Routes are registered in this order inside `App::run()` (earlier = higher priority for first-match):

1. Files from `route/*.php` (loaded at startup via `glob`)
2. Explicit routes defined in `app.php` before `$app->run()` is called
3. Implicit API routes: `nsPathRoute('api', ...)` → delegates to `ZealAPI::processApi()`
4. `.php` extension block (returns 403)
5. Implicit public file routes: `/` → `public/index.php`, `/{file}` → `public/{file}.php`, `/{dir}/{uri}` → `public/{dir}/{uri}.php`

**API handler naming rule**: `api/users/get.php` must define a variable `$get = function(...)`. The variable name must match `basename($file, '.php')`. ZealAPI binds it as a closure with `$this` set to the `ZealAPI` instance.

### Superglobals Mode vs Coroutine Mode

`App::superglobals(false)` must be called **before** `App::init()`. When disabled:
- `OpenSwoole\Runtime::HOOK_ALL` is enabled — all I/O (curl, PDO, file I/O, sleep) becomes coroutine-aware
- `CoSessionManager` is used (coroutine-safe session handling)
- `go()` / `co::sleep()` / `Channel` work directly in route handlers
- `coprocess()` throws an exception (use coroutines instead)

When superglobals are enabled, implicit routes run through `prefork_request_handler()` (`src/utils.php`), which forks a child process, captures `echo` output plus response headers/cookies via a POSIX message queue, and returns them to the parent. This enables blocking PHP code to run safely without corrupting shared server state.

### Middleware Stack Order

`addMiddleware()` appends to `$middleware_wait_stack`. In `run()`, that array is **reversed** before being added to `StackHandler`. Result: the last-added middleware executes first (outermost wrap), `ResponseMiddleware` always runs innermost.

### Task Workers

Task handlers live in `task/` (e.g., `task/backup.php`). Dispatch with:

```php
App::getServer()->task(['handler' => '/task/backup', 'args' => [...]]);
```

The `task` event handler in `App::run()` includes the file and calls the function named after `basename($handler)`.

### SSR Streaming

ZealPHP supports three streaming patterns via `src/HTTP/Response.php` and `ResponseMiddleware`:

| Pattern | How | When to use |
|---------|-----|-------------|
| **Generator `yield`** | Route handler returns a `\Generator`; each `yield $string` is written to the client immediately | SSR — stream HTML shell first, then yield sections as coroutines resolve |
| **`$response->stream($fn)`** | `$fn` receives a `$write(string)` closure; headers are flushed before `$fn` runs | Fine-grained streaming control inside a callback |
| **`$response->sse($fn)`** | `$fn` receives `$emit($data, $event='', $id='')` — formats SSE wire protocol automatically | Server-Sent Events for real-time browser push (JS `EventSource`) |

**`App::renderToString($template, $args)`** — captures `App::render()` into a string so it is safe to `yield` or `$write()` inside a streaming context (no active ob buffer exists there).

SSE vs SSR: SSR streaming delivers progressive HTML the browser paints directly; SSE delivers structured events consumed by JavaScript `EventSource`. Both use the same underlying `write()` mechanism.

---

## Examples (`examples/`)

**`examples/streaming/`** — ZealPHP API usage examples. These show how to use ZealPHP's own APIs and are the canonical reference for framework features. Routes are auto-loaded via `route/streaming_examples.php`.

| File | Route | Demonstrates |
|------|-------|-------------|
| `generator_ssr.php` | `GET /examples/generator-ssr` | Generator yield SSR — parallel Channel fetches, streams sections as they resolve |
| `stream_callback.php` | `GET /examples/stream` | `$response->stream()` — word-by-word streaming with parallel coroutines |
| `sse_events.php` | `GET /examples/sse` | `$response->sse()` — 10 tick events, 1 s apart |
| `sse_client.html` | `GET /examples/sse-client` | Browser `EventSource` page for the SSE demo |
| `render_to_string.php` | `GET /examples/render-to-string` | `App::renderToString()` with skeleton → stream pattern |

**`examples/*.php` (root level)** — OpenSwoole implementation reference. These are standalone scripts that explore raw OpenSwoole/PHP primitives (`co::run()`, `pcntl_fork()`, stream wrappers, etc.) and are **not** ZealPHP usage examples. Do not use these as patterns for application code.

---

## Source Layout (`src/`)

| File | Role |
|------|------|
| `App.php` | Framework core: singleton init, route registration, `run()`, `ResponseMiddleware`, `TemplateUnavailableException`; `render()` / `renderToString()` |
| `G.php` | Per-request global state; dual-mode — superglobals mode uses a static singleton, coroutine mode uses `Coroutine::getContext()` for per-coroutine isolation |
| `ZealAPI.php` | File-based API dispatcher; extends `REST.php` |
| `REST.php` | Base class with input cleaning and response helpers |
| `utils.php` | Global functions: `prefork_request_handler`, `coprocess`, `elog`, `zlog`, `access_log`, `response_add_header`, overridden `header`/`setcookie`/`http_response_code` |
| `Session/utils.php` | Overridden `session_*` functions (file-backed, coroutine-safe) |
| `Session/CoSessionManager.php` | Per-coroutine session lifecycle (superglobals OFF) |
| `Session/SessionManager.php` | Traditional session lifecycle (superglobals ON) |
| `IOStreamWrapper.php` | `php://` stream wrapper that redirects `php://input` to request body |
| `HTTP/Request.php` | Thin wrapper around `OpenSwoole\Http\Request` |
| `HTTP/Response.php` | Thin wrapper around `OpenSwoole\Http\Response`; adds `stream()`, `sse()`, `flush()` |
