# Directory Structure

ZealPHP projects follow a predictable layout so that the runtime can discover routes, APIs, templates, and task handlers without additional configuration. This guide explains what each top-level directory does, when it is loaded, and how it participates in the server lifecycle.

```text
zealphp/
├── app.php
├── api/
├── public/
├── route/
├── template/
├── task/
├── src/
├── examples/
├── vendor/
├── docs/
└── ...
```

## Entrypoint

- **`app.php`** – Boots ZealPHP by calling `App::init()`, wiring middleware, and invoking `App::run()`. It is the only script that should start the OpenSwoole HTTP server. Keep orchestration logic here; do not dispatch routes inline.

## HTTP Surface

- **`public/`** – Contains PHP scripts and assets served via implicit routing. A request to `/foo/bar` resolves to `public/foo/bar.php` (without requiring `.php` in the URL). Index files are handled automatically (`public/index.php`, `public/foo/index.php`). Use this directory for traditional PHP pages, SPA bootstraps, or static fallbacks.
- **`route/`** – Optional route injection point. All files in this directory are included before implicit routes are registered, allowing teams to register additional explicit routes without editing `app.php`. Each file typically calls `$app = App::instance();` followed by `$app->route(...)`, `nsRoute(...)`, or `patternRoute(...)`.
- **`api/`** – Implicit API router. Files inside `api/` become callable via `/api/<path>`. Subdirectories map to namespaces: `api/device/list.php` is accessible as `/api/device/list`. Each file returns a closure stored in a variable whose name matches the file base name; `ZealAPI` binds that closure into the API context at runtime.
- **`template/`** – Houses reusable view fragments loaded via `App::render()`. The default template root is `template/home/` with `_master.php`, `_head.php`, and `content.php`. Keep presentation-only PHP here to support dynamic HTML streaming.

## Background Execution

- **`task/`** – Contains task worker handlers triggered via `$server->task()` or helper functions such as `prefork_request_handler()` and `coproc()`. Each file exposes a closure identified by file name (e.g., `task/backup.php` defines `$backup`).
- **`examples/`** – Not loaded automatically, but offers reference scripts for coroutines, prefork processing, and stream wrappers. Use these as templates when experimenting with concurrency features.

## Framework Core

- **`src/`** – Framework source code. Important highlights:
  - `App.php` – The main server class responsible for routing, middleware orchestration, template rendering, and OpenSwoole integration.
  - `G.php` – Lightweight container that virtualizes PHP superglobals per request.
  - `HTTP/` – Request and response wrappers that provide durable hooks for ZealPHP features while exposing the underlying OpenSwoole objects when needed.
  - `Session/` – Session managers that bridge traditional PHP session semantics with OpenSwoole’s coroutine context.
  - `utils.php` – Helper functions such as `prefork_request_handler()`, `coproc()`, logging utilities, and stack trace helpers.

- **`vendor/`** – Composer-managed dependencies and autoload metadata. Do not edit manually.

## Documentation and Meta

- **`docs/`** – The documentation set you are currently reading. Treat these Markdown files as publishable artifacts.
- **`README.md`, `CHANGELOG.md`, `TODO.md`** – Repository-level guidance, release history, and future work items.
- **`setup.sh`** – Bootstrap script that installs OpenSwoole, uopz, and Composer dependencies. Useful for setting up CI or fresh workstations.

## What Gets Loaded at Runtime?

1. `app.php` initializes `ZealPHP\App`.
2. All files under `route/` are included so they can register explicit routes.
3. Implicit routes are registered for:
   - `/` and public directory traversal
   - `/api/*` endpoints
   - `.php` pattern guards (403 when direct PHP execution is disabled)
4. Middleware and session managers are wired.
5. OpenSwoole server starts listening and the request lifecycle begins.

Understanding this flow makes it easy to decide where a new feature belongs:

- Need a new API? Add `api/<module>/<action>.php`.
- Need to override routing or apply authentication? Drop a file in `route/`.
- Need a view fragment? Create it under `template/`.
- Need an async job or background worker? Add a closure in `task/` and dispatch via `$server->task()` or `coproc()`.
