# ZealPHP Documentation

Welcome to the official documentation set for ZealPHP, a coroutine-aware PHP framework built on top of OpenSwoole. These guides expand on the repository README and the hosted reference site to give new contributors and product teams a structured, end-to-end view of how to work inside the framework.

## Index

### Getting started
- [getting-started.md](./getting-started.md) — install PHP 8.3+, OpenSwoole, uopz, and Composer; boot the development server.
- [directory-structure.md](./directory-structure.md) — what lives in `src/`, `route/`, `api/`, `template/`, `public/`, `task/`, and friends.
- [runtime-architecture.md](./runtime-architecture.md) — request lifecycle, superglobals vs coroutine mode, lifecycle setters (`processIsolation`, `enableCoroutine`, `hookAll`, `cgiMode`), and the unsafe-combination boot refusal.

### Routing & responses
- [routing.md](./routing.md) — `App::route()`, `nsRoute`, `nsPathRoute`, `patternRoute`, parameter injection, and the universal return contract.
- [api-layer.md](./api-layer.md) — file-based ZealAPI dispatcher, REST helpers, and the `App::authChecker()` / `adminChecker()` / `usernameProvider()` hooks.
- [error-handling.md](./error-handling.md) — `ErrorDocument`-style fallbacks, `set_error_handler`/`register_shutdown_function` per-request semantics, content negotiation for error payloads.
- [templates-and-rendering.md](./templates-and-rendering.md) — `App::render()`, `renderToString()`, `renderStream()`, `App::include()`, and the `App::fragment()` template-fragment pattern.

### Surfaces
- [streaming.md](./streaming.md) — generator-based SSR, `$response->stream()`, and Server-Sent Events via `$response->sse()`.
- [websocket.md](./websocket.md) — `App::ws()`, the per-worker fd map, frame handling, and CLOSE 1001 shutdown.
- [tasks-and-concurrency.md](./tasks-and-concurrency.md) — coroutines, `prefork_request_handler()`, `coproc()`, OpenSwoole task workers, and the safe-mode contract that governs each.
- [middleware-and-authentication.md](./middleware-and-authentication.md) — built-in middleware (CORS, ETag, Range, BasicAuth, IpAccess, RateLimit, ...) and how to compose them.

### Operations
- [deployment.md](./deployment.md) — systemd unit, Docker image, reverse-proxy guidance, log rotation, and trusted-proxy / `clientIp()` setup.
- [fuzzing.md](./fuzzing.md) — Radamsa parser fuzzing, Gabbi contract fixtures, slowhttptest reactor checks, and the http-garden differential roadmap.

### Background
- [apache-parity.md](./apache-parity.md) — uopz function overrides, per-coroutine isolation, and the contract that lets unmodified WordPress / Drupal run on ZealPHP.
- [competitive-analysis.md](./competitive-analysis.md) — where ZealPHP sits versus PHP-FPM, Laravel Octane, RoadRunner, and Node.js / Express.
- [standards-and-roadmap.md](./standards-and-roadmap.md) — coding standards, PSR cross-reference to `STANDARDS.md`, and shipped + planned roadmap items.

## How to use these guides

- **Start with the essentials**: follow `getting-started.md` to set up the toolchain, enable required extensions, and boot the runtime.
- **Understand the shape of a project**: `directory-structure.md` and `runtime-architecture.md` describe what ships with the framework, how the request lifecycle works, and how state is managed safely.
- **Build product features**: use `routing.md`, `api-layer.md`, `templates-and-rendering.md`, and `middleware-and-authentication.md` to implement routes, API contracts, HTML streaming, and cross-cutting policies such as authentication.
- **Adopt concurrency patterns safely**: `tasks-and-concurrency.md` covers coroutines, prefork helpers, task workers, and the rules around superglobal emulation.
- **Align on standards and roadmap**: `standards-and-roadmap.md` documents the PSR interfaces we implement today, the conventions the core team expects, and upcoming changes that may affect compatibility.

Each topic is self-contained and written from the perspective of a senior engineer onboarding a new product team. Code snippets use the same primitives that ship in `examples/`, `api/`, and `route/` so you can copy them directly into production code.

## Quick reference

- Minimum PHP: 8.3 with `openswoole` 22.1+ (26.2+ for PHP 8.5) and `uopz`
- Entrypoint: `app.php` boots the HTTP server and wires middleware, implicit routes, and session managers
- Framework namespacing: all core symbols live under `ZealPHP\*` and follow PSR-4 autoloading via Composer
- Default lifecycle: `App::superglobals(false)` — per-coroutine `$g` state. Use `App::superglobals(true)` to opt into legacy `$_GET` / `$_POST` / `$_SESSION` style (with the unsafe-combination boot refusal documented in `runtime-architecture.md`).

> **Tip:** Every Markdown file in this directory is intended to be published as part of the canonical ZealPHP documentation set. Keep phrasing factual and vendor-neutral so that it scales beyond the core repository.
