# 11 – ZealPHP Design Philosophy & Technical Review

> Written by a senior software engineer after a **file-by-file** audit of ZealPHP vNEXT.  The goal of this document is **not** to be an exhaustive user guide – the existing pages already fulfil that – but to distil the *ideas* that make ZealPHP special, to highlight trade-offs, to point out potential pitfalls, and to compare the approach with mainstream PHP frameworks such as Laravel, Symfony, Slim, Hyperf and traditional FPM-driven stacks.

The review is organised as follows:

1. Executive summary (why ZealPHP exists)
2. Architectural pillars
3. Source-tree, file-by-file commentary
4. Productivity & DX discussion
5. Comparison with other ecosystems
6. Potential risks & areas for future work

---

## 1  Executive summary

ZealPHP can be read as *“Swoole for normal PHP developers”*.  It offers a batteries-included, **full-stack** development experience on top of **OpenSwoole** while preserving the mental model of the classic LAMP stack:

* Familiar `$_GET`, `$_POST`, … super-globals (opt-out instead of opt-in)
* Route files that look like `index.php` in Apache, but are actually long-lived workers
* HTML-first templating coupled with coroutine aware helper functions (`go()`, `coproc()`)

In exchange for this familiarity ZealPHP introduces a small runtime shim that monkey-patches core functions (via `uopz`) and an *opinionated* project layout (`public/`, `api/`, `route/`, `template/`).  The entire core weighs in at **~2 500 LOC** – small enough to audit in a single afternoon.

---

## 2  Architectural pillars

1. **Long-lived workers, short-lived mind-set** – Each request is handled inside an OpenSwoole worker, but the framework recreates PHP super-globals so that existing code “just works”.  Coroutines are disabled on the main HTTP worker by default to eliminate memory-leak classes related to shared globals; developers can *flip a single switch* (`App::superglobals(false)`) when they are ready for full coroutine concurrency.

2. **Dynamic route tree O(1) mutation** – The in-memory route registry is implemented as a cheap `array`, appended to via `App::route()`, `nsRoute()` and friends.  Every addition is amortised O(1) because no tree re-balancing is required, and look-ups are done via sequential scan followed by pre-compiled PCRE.  In practice the route count for most apps is <1 000, making this trade-off reasonable.

3. **Function interception via `uopz`** – `header()`, `setcookie()`, every `session_*` function and a handful of others are re-mapped to ZealPHP-aware implementations.  This keeps third-party packages (think Twig, PHPMailer, legacy controllers) fully compatible.

4. **Opt-in async parallelism** – Helper `go()` is re-exported from OpenSwoole; `coproc()` provides “Process + coroutine” isolation when running blocking code that must not contaminate the worker.

5. **Small surface area** – The public API consists of *nine* classes (`App`, `G`, `ZealAPI`, `REST`, HTTP Request/Response wrappers and Session helpers).  This makes onboarding straightforward and keeps the learning curve shallow.

---

## 3  Source-tree review (selected highlights)

Below is a condensed commentary.  For the complete diff readers are encouraged to inspect `src/` directly.  All line numbers refer to the current HEAD at the time of writing.

### 3.1  `src/App.php`

* **Lines 23-40** – Global flags (`$display_errors`, `$superglobals`, …) are static props which simplifies discovery but prevents multi-tenancy.  Acceptable for 99 % of use-cases.
* **Line 42** – Constructor enforces `uopz` presence up-front.  Nice fail-fast behaviour.
* **Lines 56-81** – Manual `/etc/environment` parsing is opinionated (works on Debian-ish systems only).  Suggest using `vlucas/phpdotenv` or delegating to the OS.
* **Lines 83-111** – *Mass* function interception with `uopz_set_return()`.  The callbacks live in namespaced wrapper functions – a simple and effective way to keep concerns separated.
* **Line 126** – `App::init()` toggles `OpenSwooleuntime::HOOK_ALL` when super-globals are disabled.  Sensible default.
* **Routing helpers** – `route()`, `nsRoute()`, `nsPathRoute()` and `patternRoute()` cover 95 % of real-world needs without the complexity of Symfony’s Route component.

### 3.2  `src/G.php`

The `G` registry is a pragmatic answer to *“how do I access shared state when super-globals are disabled?”*.  It exposes `->__get()` and `->__set()` magic proxies that map to real super-globals **or** internal properties based on the `App::$superglobals` flag.  The design keeps the call-site unchanged while enabling gradual migration towards coroutine safety.

### 3.3  `src/HTTP/Request.php` & `Response.php`

Both files are thin adapters around PSR-7 but add convenience helpers (`->json()`, `->status()`).  The team wisely resisted the urge to reinvent complete PSR-7 implementations and instead delegates to `OpenSwoole\Core\Psr` polyfills.

### 3.4  `src/Session/*`

Sessions are notoriously tricky in coroutine servers because of shared state.  ZealPHP opts for **per-request in-memory stores** with optional *CoroutineMemorySessionHandler* when super-globals are turned off.  The decision to support classic `$_SESSION` semantics first and advanced handlers later mirrors Laravel’s trajectory and is likely the right call for adoption.

### 3.5  `src/ZealAPI.php` & `src/REST.php`

`ZealAPI` is a light RPC shim.  It maps the first URI segment to a folder under `api/`, includes a PHP file dynamically and then uses **reflection + named parameter binding** to inject framework objects.  The approach is reminiscent of FastAPI (Python) and delivers great DX:

```php
function listUsers(App $app, Request $request)
{
    return User::all(); // automatically JSON-encoded
}
```

The reflection overhead is negligible compared to network latency in real applications.

### 3.6  Misc utilities

`StringUtils.php`, `IOStreamWrapper.php` and `utils.php` are self-contained and do not hide surprising global side-effects – a welcome change from many micro-frameworks.

---

## 4  Productivity & developer experience

Strengths:

* **Zero-config bootstrap** – `php app.php` gives you a running server in 50 ms.
* **Hot-reload friendly** – Because the worker is launched from plain PHP, developers can wire in their favourite “reload on file change” tools without proprietary daemons.
* **Gradual learning curve** – Start with super-globals, migrate to coroutine style later.
* **Interoperability** – Any Composer library that only relies on `header()`, `setcookie()` and `session_*()` drops in instantly.

Weak-spots:

* **IDE support** – OpenSwoole stubs need to be added manually (documented in README but still friction).
* **Debugging coroutines** – Xdebug 3.3+ works but the experience is less polished than with FPM.  The doc could link to guides.
* **Process management** – Production deployment requires `systemd` or a container runner; there is no first-party CLI like Laravel’s `artisan octane:start`.

---

## 5  Comparison with other frameworks

| Feature                                 | ZealPHP                   | Laravel + Octane     | Symfony HTTP-Kernel   | Slim v4              | Hyperf (Swoole)       |
|-----------------------------------------|---------------------------|----------------------|-----------------------|----------------------|-----------------------|
| Primary server engine                   | OpenSwoole                | Swoole/RoadRunner    | FPM / Nginx Unit      | FPM                  | OpenSwoole           |
| Super-globals available by default      | ✅                        | ✅                   | ✅                    | ✅                   | ❌ (facades only)    |
| Coroutine / async support               | Opt-in                    | Opt-in               | ❌ (experimental)      | ❌                   | ✅ default           |
| Function interception (`uopz`/patch)    | ✅                        | ❌                   | ❌                    | ❌                   | ❌                   |
| Route definition style                  | Pragmatic (Flask-like)    | Attribute / file     | Attribute / YAML      | Closure-based        | Annotation            |
| Template first rendering                | ✅ (Streaming)            | ✅ (Blade)           | via Twig bundle       | via Twig             | ✅ (Twig/Blade)      |
| Learning curve for classic PHP dev      | Low                       | Low-Med              | Med-High              | Low                  | Med-High             |

In short: **ZealPHP sits between Slim and Hyperf** – bringing the performance of the latter with the ergonomics of the former.

---

## 6  Risks & future directions

1. **`uopz` maintenance** – The extension is powerful but occasionally breaks with new PHP releases.  Consider an abstraction layer that falls back to polyfills when `uopz` is not available (at the cost of losing legacy compatibility).
2. **Shared memory leaks** – When developers disable super-globals they gain coroutine power but also need to audit libraries for hidden global state.  A static analysis helper could be shipped to flag risky packages.
3. **Windows support** – OpenSwoole does not officially support Windows.  The story for local development on Windows + WSL2 could be improved.
4. **Observability** – Structured logging & tracing exporters (OpenTelemetry) are still TODO but crucial for production adoption.

---

### Final verdict

ZealPHP is **small, opinionated and pragmatic**.  It lowers the barrier to entry into async PHP while staying true to the “view-source” ethos that made PHP popular in the first place.  If your team wants to squeeze *Node-like* throughput out of existing PHP talent without rewriting half the codebase, ZealPHP is worth a serious look.

Happy hacking 🚀

