# ZealPHP Lifecycle Matrix — Runtime Architecture

> Tested 2026-05-27 on Docker lab (PHP 8.4.21 + OpenSwoole 26.2.0 + ext-zealphp 0.3.2)

## Configuration Knobs

| Knob | Setter | Default | Controls |
|------|--------|---------|----------|
| `superglobals` | `App::superglobals(bool)` | `true` | `$g` storage: process-wide singleton (true) vs per-coroutine (false). `$_GET`/`$_SESSION` populated (true) vs use `$g->get` only (false). |
| `enableCoroutine` | `App::enableCoroutine(bool)` | `!superglobals` | OpenSwoole `enable_coroutine` — auto-coroutine per request. |
| `processIsolation` | `App::processIsolation(bool)` | `superglobals` | `App::include()` dispatch: subprocess (true) vs in-process (false). |
| `hookAll` | `App::hookAll(bool\|int)` | `!superglobals` | `Runtime::enableCoroutine(HOOK_ALL)` — coroutine-aware I/O hooks. |
| `cgiMode` | `App::cgiMode('pool'\|'proc')` | `'pool'` | Subprocess strategy: persistent pool (pool) vs fork-per-request (proc). |

## Full Matrix

| # | sg | ec | pi | cgi | Session Manager | Request Context | Status |
|---|----|----|----|----|-----------------|-----------------|--------|
| 1 | F | T | F | — | CoSessionManager | Per-coroutine | **Production** |
| 2 | F | F | — | — | — | — | **Rejected** |
| 3 | T | F | F | — | SessionManager | Singleton | **Production** |
| 4 | T | T | F | — | CoSessionManager | Per-coroutine | **Production** |
| 5 | T | F | T | pool | SessionManager | Singleton | **Production** |
| 6 | T | T→F | T | pool | SessionManager | Singleton | **Fallback→5** |
| 9 | T | F | T | proc | SessionManager | Singleton | **Production** |
| 10 | T | T→F | T | proc | SessionManager | Singleton | **Fallback→9** |
| 11 | F | T | T→F | pool | CoSessionManager | Per-coroutine | **Fallback→1** |
| 12 | F | T | T→F | proc | CoSessionManager | Per-coroutine | **Fallback→1** |

Per-coroutine `$g` via `App::$coroutine_isolated_superglobals` when ext-zealphp is loaded (Mode 4).

**Automatic fallbacks** (applied at `App::run()` boot):
- `pi=T + ec=T + sg=T` → force `ec=false + hookAll=0` (→ Mode 5 or 9)
- `pi=T + ec=T + sg=F` → force `pi=false` (→ Mode 1, since sg=F requires ec=T)

Reason: CGI subprocess dispatch uses blocking pipe I/O (`proc_open` + `fread`)
which is incompatible with coroutine scheduling.

## Test Results

```
                    Echo  Session  Cookies  Exit/Die  Headers  CSRF
Mode 1  (default)    P      P        P       N/A*      N/A*   N/A*
Mode 2  (reject)     — REJECTED AT BOOT (RuntimeException) —
Mode 3  (sync)       P      P        P        P         P      P
Mode 4  (mode4)      P      P        P        P         P      P
Mode 5  (pool)       P      P        P        P         P      P
Mode 6  (→5)         P      P        P        P         P      P
Mode 9  (proc)       P      P        P        P         P      P
Mode 10 (→9)         P      P        P        P         P      P
Mode 11 (→1)         P      P        P       N/A*      N/A*   N/A*
Mode 12 (→1)         P      P        P       N/A*      N/A*   N/A*
```

P = Pass, F = Fail, N/A = not applicable for this mode

### Notes

*Mode 1/11/12 (sg=false): `$_GET`/`$_SERVER` not populated by design. Use `$g->get`, `$g->server`.
Traditional PHP patterns that read `$_GET` directly need sg=true.

**Mode 4 (UPDATE — fixed on ext-zealphp ≥ 0.3.x, #426/#379): `$_SESSION` now works.** The
ext snapshots/restores all 7 superglobals (incl. `$_SESSION`) per coroutine on `on_yield`/
`on_resume` (`zealphp_superglobals_save`/`_restore`/`_set`, IS_REFERENCE-aware), so a
`$_SESSION` write before a yield survives and the post-yield read sees the request's own
value. Pinned by ext phpt 046/047 and #379 (closed). The previously-proposed
`zealphp_rearm_autoglobals()` was never implemented — the snapshot/restore family is the
mechanism instead. (Historical note: the original limitation was PHP auto-global CV caching
of the `$_SESSION` zend_array pointer per compiled scope.)

***proc mode: `Set-Cookie` headers from CGI subprocess not always forwarded (cookie
round-trip gap in `cgiSubprocess` response builder).

****sg=false + pi=true: session persistence across subprocesses broken. The subprocess
runs in-process session via `zeal_session_start()`, but the session cookie lifecycle
does not bridge correctly when `$g->session` is per-coroutine and the subprocess returns
session data via IPC.

## Recommended Modes

### For new ZealPHP apps

**Mode 1** — `App::superglobals(false)` (the recommended default for new apps / the scaffold; the framework property default is `superglobals(true)`)

```php
App::superglobals(false);
$app = App::init('0.0.0.0', 8080);
$app->route('/users/{id}', function($id, $request, $response) {
    $g = G::instance();
    // Use $g->get, $g->session, $g->server — NOT $_GET/$_SESSION
});
$app->run();
```

### For traditional PHP apps (in-process)

**Mode 3** — `App::superglobals(true)` + `App::enableCoroutine(false)`

```php
App::superglobals(true);
App::enableCoroutine(false);
App::processIsolation(false);
$app = App::init('0.0.0.0', 8080);
$app->run();
```

Full `$_GET`/`$_POST`/`$_SESSION`/`$_SERVER` support. `header()`, `setcookie()`,
`http_response_code()`, `exit()`/`die()` all work. One request at a time per worker
(sequential).

### For legacy apps (WordPress, Drupal)

**Mode 5** — `App::superglobals(true)` + `App::processIsolation(true)` + `App::cgiMode('pool')`

```php
App::superglobals(true);
App::processIsolation(true);
App::cgiMode('pool');
$app = App::init('0.0.0.0', 8080);
$app->run();
```

Each PHP file runs in a persistent subprocess pool (FPM-style). Full global-scope
isolation. `exit()`/`die()` captured by shutdown handler — worker respawns automatically.

## Architecture Details

### Why Mode 2 is Rejected

`sg=false + ec=false`: CoSessionManager requires coroutine context
(`Coroutine::getContext()`) for per-request `$g` isolation. Without coroutines,
all requests share the process-wide singleton `$g`, but without superglobals
the framework expects per-request isolation. Throws `RuntimeException` at boot.

### Modes 6, 10, 11, 12 — Automatic Fallback

`ec=true + pi=true` (coroutines + process isolation): the CGI subprocess dispatch
(`WorkerPool::dispatch` / `cgiSubprocess`) uses blocking `proc_open` + `fread` on
pipes, which is incompatible with coroutine scheduling regardless of `hookAll`.

`App::run()` detects this at boot and applies automatic fallbacks:
- **sg=T** (modes 6/10): force `ec=false + hookAll=0` → falls back to Mode 5/9
  (sync CGI pool/proc). All tests pass identically.
- **sg=F** (modes 11/12): force `pi=false` → falls back to Mode 1 (in-process
  coroutine). Can't force `ec=false` because `sg=F+ec=F` is rejected (coroutines
  required for per-request `$g` isolation).

A warning is logged via `elog()` so the configuration mismatch is visible.

### Mode 4 Auto-Global Caching (Deep Dive) — RESOLVED (#426/#379)

> **This section is historical.** `$_SESSION` works in Mode 4 (coroutine-legacy) on the
> shipped ext-zealphp. The fix was the per-coroutine superglobal snapshot/restore family
> (`zealphp_superglobals_save`/`_restore`/`_set`), NOT the `zealphp_rearm_autoglobals()`
> function proposed below (which was never implemented). Pinned by ext phpt 046/047 and
> the closed #379; verified 0/120 lost across a 40-concurrent × 3-round burst, including
> the opcache-compiled-included-file path the "CV caching" concern below is about. The
> account below is kept only as the record of the original investigation.

The original limitation: in OpenSwoole coroutine mode, PHP's auto-global mechanism caches
the `zend_array*` pointer per compiled scope. When `$_GET` is first accessed in an included
file, PHP resolves it from `EG(symbol_table)` and caches the pointer in the compiled
variable (CV) table. On subsequent coroutines reusing the same compiled opcodes (via
opcache), the CV still pointed to the OLD `zend_array*`.

**What worked then**: `parse_str($_SERVER['QUERY_STRING'], $_GET)` modifies the existing
`zend_array` in-place, preserving the pointer — which is why `$_GET` worked early while
`$_SESSION` lagged.

**What was proposed (NOT shipped)**: a `zealphp_rearm_autoglobals()` that iterates
`CG(auto_globals)` and re-arms each entry. Superseded by the snapshot/restore family above.

**In-place zend_hash update attempt**: replacing `ZVAL_COPY` with `zend_hash_clean()`
+ entry-by-entry copy (preserving the `zend_array*` pointer) caused alternating
failures — the `zend_hash_clean` invalidated internal HashTable state across
coroutine boundaries. Reverted to v0.3.2.

### Pool Worker exit()/die() Survival

PHP 8.4 has no `ExitException` — `exit()` terminates the pool worker subprocess
immediately. The framework registers a real PHP `register_shutdown_function`
(before the uopz override replaces it) that:

1. Flushes the session to disk (`session_write_close()`)
2. Runs app-registered shutdown functions
3. Captures output buffers
4. Sends the IPC response frame before the process exits

The response frame carries `_exit: true` so `WorkerPool` always respawns the
worker, avoiding a race where `isAlive()` returns true while the process is
still in its shutdown sequence (zombie worker dispatched to dying process).

### Per-Coroutine RequestContext (Mode 4)

When `App::$coroutine_isolated_superglobals` is true (ext-zealphp loaded +
sg=T + ec=T), `RequestContext::instance()` returns per-coroutine instances
even in superglobals mode. This isolates framework state
(`$g->zealphp_response`, `$g->session`, error handlers, etc.) per request.
The `__get`/`__set` proxy to `$GLOBALS['_*']` still works because ext-zealphp
isolates the superglobals per coroutine via snapshot/restore on yield/resume.

### Session Early-Return Scoping

`zeal_session_start()` returns immediately when `$g->_session_started` is true
AND `coroutine_isolated_superglobals` is active (Mode 4 only). In sync mode
(process-wide `$g`), `SessionManager` resets `_session_started = false` per
request so the full `zeal_session_start` logic runs each time — preventing
stale session data from leaking across sequential requests.
