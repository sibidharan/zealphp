# The Isolation Trust-Bar — running request-style PHP under coroutine concurrency

**Date:** 2026-05-28
**Mode under test:** `App::mode('coroutine-legacy')` = `superglobals(true)` + `isolation(coroutine)` + `silentRedeclare` + `includeIsolation` (Stage 7) + `coroutineGlobalsIsolation` + `defineIsolation`, on OpenSwoole 26.2.0 + ext-zealphp.

---

## The claim

> **Existing request-style PHP code can run with EVERY request-state primitive
> isolated per coroutine under OpenSwoole concurrency — superglobals, headers,
> cookies, sessions, response state, class statics, `$GLOBALS`, constants,
> `ini_set`, `putenv`/`getenv`, AND function-local `static $x` (Stage 5, opt-in).**

Old PHP brain, new PHP engine: the PHP-FPM per-request mental model on a
long-running concurrent server. With Stage 5 enabled
(`App::coroutineStaticsIsolation(true)`), there is **no request-state primitive
left leaking across coroutines** — the trust-bar is 100% green. The one
remaining caveat is a *performance* tradeoff (the function-static snapshot walk
scales with declared-function count), not a correctness gap — which is why it is
opt-in rather than default. Process-*wide* state that is supposed to persist
(set-once caches, connection pools) is unaffected and behaves as under FPM with
opcache.

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
| **function-local `static $x`** | ✅ **opt-in** (Stage 5) | ext per-coroutine snapshot of each instantiated function/method's live static table on yield/resume — `App::coroutineStaticsIsolation(true)`. See "Stage 5" below |

---

## Stage 5 — function-local `static $x` isolation (SOLVED, opt-in)

```php
function counter() { static $n = 0; return ++$n; }   // isolated per coroutine with Stage 5 on
```

Function-local `static` persists across requests in any long-running PHP server.
Stage 5 isolates it per coroutine using the **same snapshot/restore model that
already isolates class statics, constants and ini** — not a `map_ptr` swap (see
the dead-end below). Enable with `App::coroutineStaticsIsolation(true)` before
`App::run()`.

### How it works

PHP 8 stores a function's runtime statics in a per-process `HashTable` resolved
via `ZEND_MAP_PTR_GET(op_array->static_variables_ptr)`; `op_array->static_variables`
is the immutable *template*. On `on_yield` the ext walks every user
function/method whose statics have been instantiated (live table exists and
differs from the template) and snapshots each live table per coroutine, keyed by
the stable template pointer. On `on_resume` it restores THIS coroutine's values
back into the live tables. Static vars are stored as `IS_REFERENCE` once
`ZEND_BIND_STATIC` binds them to a CV, so the snapshot derefs on save and writes
*through* the reference on restore — same discipline as the superglobal
snapshot, so the executing frame's CV stays coherent.

**Why it's correct under concurrency:** coroutines are cooperative — only one
runs at a time. Each coroutine writes its statics *after* its own resume-restore
and reads them *before* its next yield, so values never bleed. Verified at
**0 leaks across 240 requests, peak-40 concurrency, opcache on AND off, no
crash** (`tests/Integration/TrustBarIsolationTest.php` enables the flag and
moves `fn_static` into the hard contract — the matrix is now 100% green).

**Why it touches nothing dangerous:** it only reads/writes the per-process live
static `HashTable`, never `run_time_cache` and never `CG(map_ptr_base)`. So it is
opcache-mode-agnostic and crash-safe, and the walk re-derives from the live
function/class tables every time — safe even when `silentRedeclare` /
`includeIsolation` redeclare functions.

### The performance tradeoff (why it's opt-in, not default)

The snapshot walk visits every user function + method on each yield, so cost
scales with declared-function count. Measured: a once-yielding request at **1200
declared functions runs ~0.35 ms vs ~0.19 ms with the flag off** — roughly
halved throughput. Negligible for small apps; a real regression at WordPress
scale (thousands of functions + methods). So it is **off by default even in
`coroutine-legacy`** — enable it only for apps that depend on per-request
function statics for correctness. The follow-up that would make default-on
viable is a cached touched-set registry (snapshot only functions that actually
instantiated statics, refreshed when the function/class tables grow) instead of
re-walking every table each yield.

### Dead-end: the `map_ptr` base swap (rejected)

The first attempt swapped `CG(map_ptr_base)` to a per-coroutine area on
yield/resume (the idea being function statics + `run_time_cache` would become
per-coroutine and lazily re-init FPM-fresh). It crashed with
`TypeError: must be of type X, X given` under both copy and zero area-init
models. Root cause confirmed from the engine headers: `run_time_cache` is a
**per-frame cached pointer** (`EX(run_time_cache)`, set at frame entry), so
swapping the base doesn't isolate already-entered frames AND new frames entered
against a stale/freed base read a garbage CE → class-identity mismatch. The swap
also needs a base established *at coroutine creation, before the first opcode* —
a hook OpenSwoole's yield/resume/close don't provide. The snapshot model above
sidesteps all of this: no base swap, no `run_time_cache`, no create hook needed.

`putenv`/`getenv` were the other former landmine and are now isolated (framework
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
makes that claim credible — every request-state primitive isolates per
coroutine, and the only remaining knob is a performance tradeoff (Stage 5's
opt-in walk), not a correctness gap.
