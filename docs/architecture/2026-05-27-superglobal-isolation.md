# Superglobal Isolation in Coroutine Mode — How ext-zealphp Makes `$_GET`/`$_SESSION` Work

> The breakthrough that makes traditional PHP code run correctly on OpenSwoole coroutines.

## The Problem

PHP was designed for one-request-per-process. Superglobals (`$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `$_SERVER`, `$_FILES`) are populated once per request during RINIT (Request INITialization) by the SAPI layer (mod_php, php-fpm, etc.), and torn down during RSHUTDOWN.

OpenSwoole is a long-running process. One PHP process handles thousands of requests. With `enable_coroutine = true`, multiple requests run concurrently as coroutines in the same process. The superglobals are process-wide — writes from coroutine A are visible to coroutine B. This is the fundamental race condition that makes `superglobals(true) + enableCoroutine(true)` unsafe.

ext-zealphp solves this with a two-layer approach.

## Layer 1: Per-Coroutine EG(symbol_table) Snapshots

OpenSwoole's `PHPCoroutine` saves and restores `EG` (Executor Globals) on coroutine switches. Each coroutine has its own `EG(symbol_table)` — the hash table where PHP stores all global variables including `$_GET`, `$_SESSION`, etc.

ext-zealphp chains into OpenSwoole's coroutine callbacks:

```
on_yield(coroutine_A):
  1. zealphp_snapshot_save(A)   — deep-copy superglobals from EG(symbol_table)
  2. PHPCoroutine::on_yield(A)  — OpenSwoole swaps EG to next coroutine

on_resume(coroutine_A):
  1. PHPCoroutine::on_resume(A) — OpenSwoole restores EG for coroutine A
  2. zealphp_snapshot_restore(A) — restore superglobals into EG(symbol_table)
```

The chaining order matters: save BEFORE OpenSwoole swaps EG (so we read from the current EG), restore AFTER OpenSwoole restores EG (so we write to the correct EG). Getting this wrong was the v0.3.1 SIGSEGV bug — `set_on_yield` REPLACES the callback, so OpenSwoole's own EG/CG swap was lost.

**Key C technique — ZVAL_DUP for COW safety:**

```c
// zealphp_set_superglobal: in-place zval swap
zval *existing = zend_hash_str_find(&EG(symbol_table), name, name_len);
if (existing) {
    zval old;
    ZVAL_COPY_VALUE(&old, existing);  // save old (no refcount change)
    ZVAL_DUP(existing, value);        // deep copy with refcount=1
    zval_ptr_dtor(&old);              // free old AFTER overwrite
}
```

Why `ZVAL_DUP` not `ZVAL_COPY`: `ZVAL_COPY` shares the `zend_array` via refcount (refcount=2). When the included file modifies `$_SESSION['counter']++`, PHP's copy-on-write (COW) sees refcount > 1, separates the array, and the mutation goes to a COPY — the original in `EG(symbol_table)` keeps the old data. `ZVAL_DUP` creates a full copy with refcount=1, so the file's writes go directly into the `EG(symbol_table)` entry.

Why in-place (not `zend_hash_str_update`): `zend_hash_str_update` allocates a NEW hash bucket at a potentially different memory address. PHP's compiled variable (CV) cache stores a pointer to the zval in the hash bucket. After `zend_hash_str_update`, cached CVs in included files point to the OLD (freed) bucket — use-after-free. In-place swap via `ZVAL_COPY_VALUE` + `ZVAL_DUP` keeps the zval at the same memory address.

## Layer 2: PG(http_globals) — The SAPI Contract

Layer 1 handles coroutine isolation. But there's a deeper problem: PHP's auto-global mechanism.

### How PHP resolves `$_GET`

When a PHP file references `$_GET`, the engine doesn't just look it up in `EG(symbol_table)`. It goes through the **auto-global JIT** (Just-In-Time) mechanism:

```
1. COMPILE TIME: compiler recognizes $_GET as auto-global
   → marks the opcode with ZEND_FETCH_GLOBAL_LOCK

2. RUNTIME (first access in this compiled scope):
   → zend_is_auto_global("_GET") checks if armed=true
   → if armed: fires JIT callback (php_auto_globals_create_get)
     → callback reads PG(http_globals)[TRACK_VARS_GET]
     → stores result in EG(symbol_table)
     → sets armed=false
   → if not armed: finds entry in EG(symbol_table) directly

3. SUBSEQUENT ACCESSES: uses cached CV pointer
```

The JIT callback reads from `PG(http_globals)` — a process-wide array (not per-coroutine). In a normal SAPI (mod_php, php-fpm), `PG(http_globals)` is populated during RINIT from the SAPI request data. In CLI SAPI (which OpenSwoole uses), **PG(http_globals) is never populated** — the slots are `IS_UNDEF`.

This is why `$GLOBALS['_GET'] = $data` worked for the FIRST request but returned stale values on subsequent requests. The JIT fires on the first access, reads from `PG(http_globals)` (empty/stale), stores the result in `EG(symbol_table)`, and disarms. Our `$GLOBALS['_GET'] = $data` overwrites the entry. But on the next coroutine, the JIT fires again (new EG, auto-global may re-arm), reads from `PG(http_globals)` (still empty/stale), and overwrites our data.

### The Fix: Update PG(http_globals)

ext-zealphp's `zealphp_set_superglobal` now updates BOTH stores:

```c
// 1. Update EG(symbol_table) — per-coroutine executor globals
ZVAL_DUP(existing, value);

// 2. Update PG(http_globals) — process-wide SAPI globals
int idx = zealphp_track_vars_index(name);  // _GET → TRACK_VARS_GET(1)
if (idx >= 0 && existing) {
    if (Z_TYPE(PG(http_globals)[idx]) != IS_UNDEF) {
        zval_ptr_dtor(&PG(http_globals)[idx]);  // free old
    }
    ZVAL_COPY(&PG(http_globals)[idx], existing);  // point to our data
}
```

The `IS_UNDEF` guard is critical: CLI SAPI never initializes `PG(http_globals)` slots. Without the guard, `zval_ptr_dtor` on `IS_UNDEF` segfaults. This was the crash in our first attempt.

### Why Both Layers Are Needed

```
onRequest handler scope:
  zealphp_superglobals_set() → updates EG(symbol_table) + PG(http_globals)
  → $_GET in this scope: resolved from EG(symbol_table) ✓
  → $_GET in JIT callback: reads PG(http_globals) ✓

executeFile (include) scope:
  The included file has its OWN compiled opcodes with its OWN CV cache.
  First execution: resolves $_GET from EG(symbol_table) → finds our data ✓
  Second execution (same file, new coroutine):
    → CV cache from prior execution is invalidated (new execute_data)
    → resolves $_GET from EG(symbol_table) again
    → BUT: auto-global JIT may fire, reading PG(http_globals) → correct ✓
    → HOWEVER: parse_str($_SERVER['QUERY_STRING'], $_GET) in executeFile
      provides belt-and-suspenders safety for the include scope
```

The PHP-level `executeFile` refresh (`parse_str` for `$_GET`, direct assignment for `$_POST`/`$_COOKIE`/etc.) ensures the included file's scope sees current data even if the auto-global resolution takes a cached path. Both layers complement each other:

- **C driver** (`PG(http_globals)`): correct JIT source for NEW auto-global resolutions
- **PHP** (`executeFile` refresh): correct values for CACHED auto-global resolutions

## $_SESSION: The Reference Binding

`$_SESSION` has a unique challenge: it's not just READ per request — it's WRITTEN by the file and must be PERSISTED by `zeal_session_write_close()`. The write-back path must see the file's mutations.

### The Problem

```
zeal_session_start():
  $g->session = $session_data;     // typed property on per-coroutine $g
  $GLOBALS['_SESSION'] = $data;    // EG(symbol_table) entry

file.php:
  $_SESSION['counter']++;          // writes to... which array?

zeal_session_write_close():
  $data = $g->session;             // reads from typed property
  write_to_disk($data);            // persists
```

If the file writes to `$_SESSION` (the `EG(symbol_table)` entry) but write_close reads from `$g->session` (the typed property), the mutation is lost.

### The Fix: Reference Binding

```php
// In zeal_session_start(), for Mode 4:
$g->session = $session_data;
$_SESSION = &$g->session;  // make $_SESSION an alias for $g->session
```

Now writes to `$_SESSION['counter']` go directly to `$g->session['counter']`. write_close reads `$g->session` and sees the mutation.

**Critical detail**: `zealphp_superglobals_set` must pass `[]` (empty) for the `$_SESSION` parameter — if it passes `$g->session`, `ZVAL_DUP` overwrites the reference binding with a standalone copy.

### Session ID Storage

`zeal_session_id()` stores the SID in `$g->cookie['PHPSESSID']`. But `$g->cookie` after `unset($g->cookie)` proxies to `$_COOKIE`, which has auto-global caching. `zeal_session_write_close()` would read the WRONG SID.

Fix: store the SID in `$g->session_params['session_id']` (a typed property on per-coroutine `$g`, immune to auto-global caching) and read it back in write_close:

```php
// zeal_session_start() — store SID
$params = $g->session_params;
$params['session_id'] = $session_id;
$g->session_params = $params;  // explicit read-modify-write (PHP COW safe)

// zeal_session_write_close() — read SID
$session_id = $g->session_params['session_id'] ?? zeal_session_id();
```

Note the explicit read-modify-write for `session_params`: `$g->session_params['session_id'] = $id` (compound assignment on a typed property) silently fails in some coroutine contexts due to PHP's COW mechanics with typed properties.

## Per-Coroutine RequestContext

`RequestContext::instance()` returns per-coroutine instances when `App::$coroutine_isolated_superglobals` is true:

```php
public static function instance(): self
{
    if (!App::$superglobals || App::$coroutine_isolated_superglobals) {
        $cid = Coroutine::getCid();
        if ($cid >= 0) {
            $context = Coroutine::getContext($cid);
            // per-coroutine instance from coroutine context
        }
    }
    return self::$instance; // process-wide singleton fallback
}
```

This isolates framework state (`$g->zealphp_response`, `$g->session`, error handlers, etc.) per request. The `__get`/`__set` proxy to `$GLOBALS['_*']` still works because ext-zealphp isolates the superglobals per coroutine.

## Summary: The Two-Layer Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    PHP User Code                             │
│  $_GET['mode'], $_SESSION['counter']++, header('Location:')  │
├─────────────────────────────────────────────────────────────┤
│  PHP Layer (executeFile refresh)                             │
│  parse_str($qs, $_GET), $_POST = $req->post, etc.           │
│  $_SESSION = &$g->session (reference binding)                │
├─────────────────────────────────────────────────────────────┤
│  C Driver Layer (ext-zealphp)                                │
│  zealphp_set_superglobal:                                    │
│    EG(symbol_table) ← ZVAL_DUP (in-place, refcount=1)       │
│    PG(http_globals) ← ZVAL_COPY (JIT source)                │
│  zealphp_snapshot_save/restore on yield/resume               │
├─────────────────────────────────────────────────────────────┤
│  OpenSwoole Coroutine Scheduler                              │
│  PHPCoroutine::on_yield/on_resume (EG/CG context switch)     │
└─────────────────────────────────────────────────────────────┘
```

This architecture makes `superglobals(true) + enableCoroutine(true)` safe:
- Each coroutine has isolated `$_GET`/`$_POST`/`$_SESSION` (snapshot/restore)
- The PHP auto-global JIT resolves correctly (PG(http_globals) updated)
- `$_SESSION` writes persist to disk (reference binding + session_params SID)
- Framework state is per-coroutine (RequestContext per-coroutine instances)

Traditional PHP code (`$_GET['mode']`, `$_SESSION['counter']++`, `header()`, `exit()`) works unchanged.
