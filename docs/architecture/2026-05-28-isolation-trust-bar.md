# The Isolation Trust-Bar — running request-style PHP under coroutine concurrency

**Date:** 2026-05-28
**Mode under test:** `App::mode('coroutine-legacy')` = `superglobals(true)` + `isolation(coroutine)` + `silentRedeclare` + `includeIsolation` (Stage 7) + `coroutineGlobalsIsolation` + `defineIsolation`, on OpenSwoole 26.2.0 + ext-zealphp.

---

## The claim

> **Existing request-style PHP code can run with isolated superglobals, headers,
> cookies, sessions, response state, class statics, `$GLOBALS`, constants and
> `ini_set` under OpenSwoole concurrency — while a small set of process-level
> primitives (function-local `static`, `putenv`) remain the developer's
> responsibility.**

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
| **function-local `static $x`** | ❌ **process-level** | lives in the op_array, not snapshotted |
| **`putenv()` / `getenv()`** | ❌ **process-level** | process environment, not snapshotted |

---

## The two landmines (developer responsibility)

These persist across requests in any long-running PHP server and are **not**
isolated:

```php
function counter() { static $n = 0; return ++$n; }   // ← shared across coroutines
putenv("TENANT_ID=$tenant");                          // ← shared across coroutines
```

Guidance for migrating apps:
- Replace function-local `static` request-state with `$g->memo[...]` (per-coroutine)
  or a request-scoped service. Function `static` used as a *process-wide* cache
  (read-mostly, set-once) is fine.
- Replace `putenv()` request-state with `$_SERVER` / `$g->server` or a
  request-scoped value. `putenv()` for genuinely process-wide config at boot is fine.

Everything else on the list behaves as it does under PHP-FPM.

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
