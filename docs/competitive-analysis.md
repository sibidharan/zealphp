# Competitive Analysis: PHP Async Landscape

This document compares ZealPHP with existing solutions for async/concurrent PHP. The goal is to clarify ZealPHP's unique position, not to disparage alternatives — each project serves different needs.

## Overview

| Project | Model | Routing | WebSocket | Streaming | Shared Memory | Middleware | Legacy PHP |
|---------|-------|---------|-----------|-----------|---------------|------------|------------|
| **ZealPHP** | Coroutine (OpenSwoole) | Built-in | Built-in | yield/SSE/stream() | Store (Table) + Counter (Atomic) | PSR-15 | coroutine-legacy runtime + CGI pool (pool/proc/fcgi) |
| ReactPHP | Event loop | Manual | Via packages | Manual | No | No standard | No |
| AMPHP | Coroutine (Fiber) | Manual | Via packages | Manual | No | No standard | No |
| FrankenPHP | Go worker | Via framework | Via framework | Via framework | No | Via framework | Partial |
| RoadRunner | Go worker | Via framework | Plugin | Via framework | No | Via framework | No |
| Laravel Octane | Swoole/RoadRunner | Laravel | Via packages | Limited | Limited | Laravel | No |
| Raw Swoole/OpenSwoole | Coroutine | Manual | Manual | Manual | Table/Atomic | Manual | No |

## Detailed Comparisons

### ReactPHP
**What it is:** Event-loop based async I/O library for PHP. Uses callbacks and promises.

**Where it differs from ZealPHP:**
- ReactPHP is a library, not a framework — no routing, no middleware stack, no templating
- Event-loop model (callbacks) vs. ZealPHP's coroutine model (synchronous-looking code)
- No built-in shared memory primitives — inter-worker communication requires external tools
- No WebSocket server included — requires separate packages
- No legacy PHP compatibility layer

**When to use ReactPHP:** You want maximum control over the event loop, are building a custom protocol server, or need a minimal async foundation.

### AMPHP
**What it is:** Coroutine-based async framework using PHP Fibers. Lower-level than ZealPHP.

**Where it differs from ZealPHP:**
- AMPHP is a collection of libraries, not an integrated framework
- No built-in HTTP routing, middleware stack, or template engine
- Uses native PHP Fibers — more portable but fewer performance primitives than OpenSwoole
- No cross-worker shared memory (no equivalent to OpenSwoole\Table)
- No legacy PHP migration path

**When to use AMPHP:** You want Fiber-based async without an OpenSwoole dependency, or need specific async building blocks (DNS, sockets, parallel).

### FrankenPHP
**What it is:** A modern PHP application server written in Go, designed as a drop-in replacement for PHP-FPM.

**Where it differs from ZealPHP:**
- Go-based runtime — PHP runs as a worker inside a Go process
- No PHP-native coroutines — concurrency is managed by the Go runtime
- Worker mode keeps PHP processes alive but doesn't expose coroutine primitives
- No shared memory between PHP workers (Go manages state)
- Early-hints and HTTP/3 support via Caddy integration

**When to use FrankenPHP:** You want a better PHP-FPM with zero code changes, HTTP/3 support, or Caddy integration.

### RoadRunner
**What it is:** High-performance PHP application server written in Go with a plugin architecture.

**Where it differs from ZealPHP:**
- Go-based worker model — PHP processes are long-lived but communicate with Go via pipes
- No native PHP coroutines — each request runs in a separate PHP worker
- Rich plugin ecosystem (queues, KV store, metrics) but all Go-managed
- WebSocket support via a Go plugin, not native PHP
- No override layer for legacy compatibility (no `session_start()`, `header()`, `$_GET` support)

**When to use RoadRunner:** You want Go-level infrastructure with PHP business logic, need the plugin ecosystem (gRPC, temporal, queues), or prefer process-per-request isolation.

### Laravel Octane
**What it is:** Laravel's official high-performance server package, wrapping Swoole or RoadRunner.

**Where it differs from ZealPHP:**
- Laravel-only — tightly coupled to Laravel's request lifecycle
- Abstraction layer over Swoole/RoadRunner — doesn't expose raw coroutine primitives
- Limited streaming support — no native SSE or generator-based streaming
- Shared state via Octane's cache/table facades — less direct than ZealPHP's Store
- No legacy PHP migration (requires Laravel application structure)

**When to use Laravel Octane:** You already have a Laravel application and want better performance without switching frameworks.

### Raw Swoole / OpenSwoole
**What it is:** The underlying C extension that ZealPHP builds on.

**Where it differs from ZealPHP:**
- Raw API — no routing, no middleware pipeline, no template engine
- Manual request handling — you wire up `onRequest`, `onOpen`, `onMessage` yourself
- Full power but significant boilerplate for web applications
- No override layer — `session_start()`, `header()`, `$_GET` don't work in a coroutine worker
- No legacy PHP compatibility

**When to use raw Swoole/OpenSwoole:** You need maximum control, are building a custom protocol, or ZealPHP's abstractions don't fit your use case.

## ZealPHP's Unique Position

ZealPHP occupies a specific niche that no other project covers:

1. **Full-stack coroutine framework** — not a library (ReactPHP, AMPHP) or a server (FrankenPHP, RoadRunner), but an integrated framework with routing, middleware, templates, and shared memory
2. **Legacy PHP compatibility runtime** — `App::mode(App::MODE_COROUTINE_LEGACY)` runs traditional request-style PHP under coroutine concurrency with per-coroutine isolation of all 7 superglobals, `$GLOBALS` (including object-valued), function-local statics, and `require_once` re-execution state (`App::defineIsolation(true)` adds per-request `define()` constants as a separate opt-in) (for apps whose class graph is Composer-autoloaded or preloaded; unmodified pure-`require_once` apps such as classic WordPress run there sequentially and use `legacy-cgi` for concurrent deployment). Built-in functions (`session_start()`, `header()`, `$_GET`) are overridden via ext-zealphp's `zealphp_override()` (uopz is the fallback). Few frameworks offer request-style legacy PHP running concurrently under coroutines; competitors offer either process-per-request isolation (FrankenPHP/RoadRunner) or coroutines without legacy compat. See [Lifecycle modes](/coroutines#lifecycle-modes).
3. **Streaming-first** — `yield` as a first-class streaming primitive, with SSE, SSR streaming, and `stream()` built into the response object
4. **Cross-worker shared state** — `Store` and `Counter` provide shared memory without external dependencies (no Redis required)
5. **Single-process deployment** — HTTP, WebSocket, timers, task workers, and sessions in one `php app.php` process
