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
| 4 | T | T | F | — | CoSessionManager | Per-coroutine | **Experimental** |
| 5 | T | F | T | pool | SessionManager | Singleton | **Production** |
| 6 | T | T | T | pool | CoSessionManager | Per-coroutine | **Broken** |
| 9 | T | F | T | proc | SessionManager | Singleton | **Production** |
| 10 | T | T | T | proc | CoSessionManager | Per-coroutine | **Broken** |
| 11 | F | T | T | pool | CoSessionManager | Per-coroutine | **Experimental** |
| 12 | F | T | T | proc | CoSessionManager | Per-coroutine | **Experimental** |

Per-coroutine `$g` via `App::$coroutine_isolated_superglobals` when ext-zealphp is loaded (Mode 4).

## Test Results

```
                    Echo  Session  Cookies  Exit/Die  Headers  CSRF
Mode 1  (default)    P      P        P       N/A*      N/A*   N/A*
Mode 2  (reject)     — REJECTED AT BOOT (RuntimeException) —
Mode 3  (sync)       P      P        P        P         P      P
Mode 4  (mode4)      P      F**      P        P         P      F**
Mode 5  (pool)       P      P        P        P         P      P
Mode 6  (ec+pool)    F      F        F        F         F      F
Mode 9  (proc)       P      P        -***     P         P      P
Mode 10 (ec+proc)    F      F        F        F         F      F
Mode 11 (F+pool)     P      F****    P        P         P      F
Mode 12 (F+proc)     P      F****    -***     P         P      F
```

P = Pass, F = Fail, - = Partial

### Notes

*Mode 1 (sg=false): `$_GET`/`$_SERVER` not populated by design. Use `$g->get`, `$g->server`.
Traditional PHP patterns that read `$_GET` directly need sg=true.

**Mode 4: PHP auto-global CV caching. `$_SESSION` zend_array pointer is cached per compiled
scope in OpenSwoole coroutines. `$_GET` works via `parse_str` workaround in `executeFile()`.
Session fix requires ext-zealphp C-level `zealphp_rearm_autoglobals()`.

***proc mode: `Set-Cookie` headers from CGI subprocess not always forwarded (cookie
round-trip gap in `cgiSubprocess` response builder).

****sg=false + pi=true: session persistence across subprocesses broken. The subprocess
runs in-process session via `zeal_session_start()`, but the session cookie lifecycle
does not bridge correctly when `$g->session` is per-coroutine and the subprocess returns
session data via IPC.

## Recommended Modes

### For new ZealPHP apps

**Mode 1** — `App::superglobals(false)` (the default)

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

### Why Modes 6 and 10 are Broken

`ec=true + pi=true` (coroutines + process isolation): the CGI subprocess dispatch
(`WorkerPool::dispatch` / `cgiSubprocess`) uses blocking `proc_open` + `fread` on
pipes. With `hookAll=HOOK_ALL`, OpenSwoole hooks these I/O calls and yields the
coroutine. But the pool worker subprocess is a separate process — it does not
participate in the coroutine scheduler. The hooked `fread` on the IPC pipe yields,
but the data arrives on the OS pipe (not via OpenSwoole's event loop), causing
deadlocks and timeouts.

**Fix (future)**: use `Coroutine\System::exec()` or coroutine-aware pipe reads for
CGI dispatch when `hookAll` is active. Or document that `ec=true + pi=true`
requires `hookAll(false)` (explicit opt-out).

### Mode 4 Auto-Global Caching (Deep Dive)

In OpenSwoole coroutine mode, PHP's auto-global mechanism caches the `zend_array*`
pointer per compiled scope. When `$_GET` is first accessed in an included file, PHP
resolves it from `EG(symbol_table)` and caches the pointer in the compiled variable
(CV) table. On subsequent coroutines reusing the same compiled opcodes (via opcache),
the CV still points to the OLD `zend_array*`.

**What works**: `parse_str($_SERVER['QUERY_STRING'], $_GET)` modifies the existing
`zend_array` in-place, preserving the pointer. This is why `$_GET` works in Mode 4
but `$_SESSION` does not — there is no `parse_str` equivalent for `$_SESSION`.

**What's needed**: ext-zealphp C-level function `zealphp_rearm_autoglobals()` that
iterates `CG(auto_globals)` and sets `armed = true` on each entry, forcing PHP to
re-resolve from `EG(symbol_table)` on next access. Called at the start of each
request in Mode 4.

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
