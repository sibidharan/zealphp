# The Isolation Trust-Bar — running request-style PHP under coroutine concurrency

**Date:** 2026-05-28
**Mode under test:** `App::mode('coroutine-legacy')` = `superglobals(true)` + `isolation(coroutine)` + `silentRedeclare` + `includeIsolation` (Stage 7) + `coroutineGlobalsIsolation` + `defineIsolation`, on OpenSwoole 26.2.0 + ext-zealphp.

---

## The claim

> **Existing request-style PHP code can run with isolated superglobals, headers,
> cookies, sessions, response state, class statics, `$GLOBALS`, constants,
> `ini_set` and `putenv`/`getenv` under OpenSwoole concurrency — while one
> process-level primitive (function-local `static`) remains the developer's
> responsibility (pending the per-coroutine `map_ptr` "Stage 5" work).**

Old PHP brain, new PHP engine: the PHP-FPM per-request mental model on a
long-running concurrent server. Not "all old PHP just works" — **most
traditional request-style code migrates without changing its mental model.**

---

## The matrix (verified: 40 concurrent interleaved coroutines, each yields mid-request)

`tests/Integration/TrustBarIsolationTest.php` sets each primitive to a
per-request unique value, forces a coroutine yield, then re-reads. Raw
OpenSwoole leaks **39/40** for every one of these; ZealPHP result:

| Primitive | Isolated per coroutine? | Mechanism |
|---|---|---|
| `$_GET` `$_POST` `$_REQUEST` `$_COOKIE` `$_FILES` `$_SERVER` `$_SESSION` | ✅ | ext `on_yield`/`on_resume` superglobal snapshot (IS_REFERENCE-aware) |
| `header()` `setcookie()` `http_response_code()` | ✅ | uopz overrides → per-request `$g->zealphp_response` |
| class statics (`Auth::$user`) | ✅ | ext static-members snapshot (`default_static_members`) |
| `$GLOBALS` / `global $x` | ✅ | ext per-coroutine `$GLOBALS` delta snapshot (`coroutineGlobalsIsolation`) |
| `define()` constants | ✅ | ext per-coroutine constants snapshot |
| `ini_set()` | ✅ | ext per-coroutine ini snapshot |
| bootstrap globals (`$wp`, `$wpdb`) | ✅ visible in every coroutine | post-bootstrap parent-baseline re-capture |
| `require_once` / `include_once` per-request logic | ✅ re-executes | Stage 7 `ZEND_INCLUDE_OR_EVAL` hook |
| `exit` / `die` | ✅ worker survives | `HaltException` (per-request) |
| `putenv()` / `getenv()` | ✅ | framework override → request-scoped `$g` store, falling back to `App::$boot_env` |
| **function-local `static $x`** | ❌ **process-level** | lives in the op_array (`map_ptr`), not snapshotted — see "Stage 5" below |

---

## The one remaining landmine (developer responsibility)

Function-local `static` persists across requests in any long-running PHP server
and is **not** yet isolated:

```php
function counter() { static $n = 0; return ++$n; }   // ← shared across coroutines
```

Guidance: replace function-local `static` request-state with `$g->memo[...]`
(per-coroutine) or a request-scoped service. Function `static` used as a
*process-wide* cache (read-mostly, set-once) is fine. Everything else on the
list behaves as it does under PHP-FPM.

### Stage 5 — per-coroutine `map_ptr` (the path to isolating function statics)

PHP 8 resolves a function's runtime statics via
`ZEND_MAP_PTR_GET(op_array->static_variables_ptr)`, an offset into
`CG(map_ptr_base)` (in opcache/preload offset-mode). The idea: give each
coroutine its own `map_ptr` area and swap `CG(map_ptr_base)` on yield/resume,
so function statics (and `run_time_cache`) become per-coroutine and lazily
re-init from the op_array template — i.e. FPM-fresh. The `map_ptr` swap touches
only the run-time data area, not the symbol tables, so it sidesteps the reason
the original Stage 5 (which swapped `EG(function_table)`/`class_table`) broke
internal symbol resolution.

**Prototype result (2026-05-28): mechanism sound, but blocked by hook timing.**
A flag-gated prototype (`zealphp_coroutine_statics(true)`, default-off) captured
the worker's `CG(map_ptr_*)` and swapped a per-coroutine `pemalloc`'d area in on
`on_resume` / out on `on_yield`. Two area-init models were tried, both with
opcache enabled (offset-mode is required for the swap to mean anything):

1. **Copy** the worker baseline into the per-coroutine area (`memcpy`) — crashes
   with `TypeError: Argument #1 ($request) must be of type
   OpenSwoole\Http\Request, OpenSwoole\Http\Request given`. The copied
   `run_time_cache` slots still point at the *worker's* cache buffers, which the
   coroutines then share and corrupt concurrently. Statics aren't isolated
   either (slots hold shared pointers).
2. **Zero** the per-coroutine area (`memset 0`, the FPM-fresh model that should
   force lazy re-init) — same `TypeError`.

**The definitive wall:** `on_yield` / `on_resume` fire **mid-execution**. When a
request handler yields at an I/O point (e.g. a channel pop inside
`CoSessionManager::__invoke`), that frame — and every frame below it — is
suspended on the PHP call stack, and its opcodes resolve `run_time_cache` via
`ZEND_MAP_PTR_GET` on *every* access. Swapping or zeroing `CG(map_ptr_base)`
underneath a suspended frame invalidates that frame's already-resolved
type-check / method caches → the "X, X given" corruption. A per-coroutine
`map_ptr` area is only safe if it is established **at coroutine creation, before
the first opcode runs in it** — exactly how OpenSwoole already allocates the
per-coroutine `EG` context. The yield/resume/close hooks the ext can reach all
fire too late.

**Verdict:** isolating function statics needs OpenSwoole to allocate (or let an
extension allocate) a per-coroutine `map_ptr` base at coroutine-create time —
an OpenSwoole/engine change, not an ext-side hook. This is the genuine reason
Stage 5 is hard; it stays the open isolation item. The prototype was reverted
(the shipped ext build does not carry it); this section preserves the approach
and the wall for a future dedicated effort.

`putenv`/`getenv` were the other landmine and are now isolated (framework
override → per-coroutine `$g` store, boot-env fallback).

---

## How to verify

```bash
# 1. The isolation contract (request state isolated per coroutine):
./vendor/bin/phpunit tests/Integration/CoroutineIsolationContractTest.php

# 2. The full trust-bar matrix (every primitive above):
./vendor/bin/phpunit tests/Integration/TrustBarIsolationTest.php

# 3. Cross-mode standalone harness (all modes, ad-hoc):
bash scripts/isolation/run-matrix.sh
```

Both PHPUnit tests spawn a real `coroutine-legacy` OpenSwoole server, fire 40
concurrent interleaved coroutines, and assert zero leakage of the contract set.
They skip cleanly when the native stack (OpenSwoole + the local ext build) is
absent, so they are safe in any CI.

---

## Why this matters

If unmodified request-style PHP runs with isolated request state on a
long-running concurrent server, ZealPHP stops being "another framework" and
becomes a **compatibility runtime**: a path for traditional PHP apps to enter
async/realtime PHP without a rewrite. The trust-bar above is the evidence that
makes that claim credible — and honest about exactly where it stops.
