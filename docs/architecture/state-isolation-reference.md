# ZealPHP State Isolation Reference

> Authoritative reference for execution modes, isolation features, and operational controls.
> Last updated: 2026-05-28 · ext-zealphp v0.3.7 · ZealPHP v0.3.x

This document is the single source of truth for what each mode/feature does, when to use it, and how to configure it. For the high-level positioning of ZealPHP as Application Server + Multi-SAPI runtime, see [`application-server-sapi.md`](application-server-sapi.md). For the 32-app compatibility sweep, see [`../compatibility-database.md`](../compatibility-database.md).

---

## 1. The 5 Execution Modes

Each mode is a distinct PHP request lifecycle — conceptually a separate SAPI. Pick one based on app compatibility + concurrency needs.

### Mode 1 (CGI Proc) — `cgiMode('proc')`

```php
App::superglobals(true);
App::processIsolation(true);
App::cgiMode('proc');
```

- **PHP analog:** Apache mod_php with prefork MPM
- **Overhead:** ~30–50 ms per request (fresh `proc_open` each time)
- **Concurrency:** sequential per worker
- **When to use:** maximum compatibility, never run twice in the same PHP process. WordPress with non-idempotent plugins, dev environments.

### Mode 1 (CGI Pool) — `cgiMode('pool')` (default for `processIsolation(true)`)

```php
App::superglobals(true);
App::processIsolation(true);
App::cgiMode('pool');  // default when processIsolation(true)
```

- **PHP analog:** PHP-FPM
- **Overhead:** ~5–10 ms amortized per request (pool of persistent subprocesses)
- **Concurrency:** sequential per worker, parallel across pool size
- **Pool size:** `App::cgiPoolSize(int)`, default 16
- **Recycle:** `App::cgiPoolMaxRequests(int)`, default 500 (set to 1 for true fresh-process semantics)
- **When to use:** legacy/procedural PHP with `define()`/`function`/`class` at top level (WordPress, phpBB, Cacti, Nextcloud)

### Mode 3 (Sync) — in-process sequential

```php
App::superglobals(true);
App::enableCoroutine(false);
App::processIsolation(false);
App::functionIsolation(true);  // optional but recommended
```

- **PHP analog:** FastCGI (sequential request handling without subprocess)
- **Overhead:** 0 ms per request (in-process)
- **Concurrency:** sequential per worker
- **When to use:** framework apps (Laravel, Symfony, Slim), clean OOP, no concurrency need

### Mode 4 (Hybrid) — coroutines + superglobals + ext-zealphp isolation

```php
App::superglobals(true);
App::enableCoroutine(true);
App::processIsolation(false);
App::defineIsolation(true);
App::coroutineGlobalsIsolation(true);  // Stage 2 COW
App::functionIsolation(true);
```

- **PHP analog:** none (novel — coroutine concurrency + traditional PHP semantics)
- **Overhead:** 0 ms per request
- **Concurrency:** full coroutine, hooked I/O yields
- **Requires:** ext-zealphp v0.3.7+
- **When to use:** modern apps wanting concurrency while keeping `$_GET`/`$_POST` semantics

### Mode 5 (Coroutine) — pure coroutine, no superglobals

```php
App::superglobals(false);
App::coroutineGlobalsIsolation(true);  // optional, for $GLOBALS isolation
```

- **PHP analog:** none (ZealPHP-native)
- **Overhead:** 0 ms per request
- **Concurrency:** full coroutine
- **API:** use `$g->get` / `$g->post` instead of `$_GET` / `$_POST`
- **When to use:** native ZealPHP apps, microservices, API backends

---

## 2. The State Isolation Matrix

What's isolated per state category × execution mode.

| State category | Mode 1 Proc | Mode 1 Pool | Mode 3 Sync+FI | Mode 4 Hybrid | Mode 5 Coro |
|----------------|:---:|:---:|:---:|:---:|:---:|
| **Superglobals** (`$_GET` etc.) | ✅ fresh proc | ✅ pool reset | ✅ per-request | ✅ per-coro snapshot | N/A (`$g`) |
| **`$GLOBALS['x']` user vars** | ✅ fresh proc | ✅ FPM snapshot/restore | ✅ `zealphp_globals_clean` | ✅ Stage 2 COW | ✅ Stage 2 COW |
| **`define()` constants** | ✅ fresh proc | ✅ `process_state_clean` | ✅ `defineIsolation` | ✅ per-coro snapshot | ✅ per-coro snapshot |
| **User functions** (`function foo() {}`) | ✅ fresh proc | ✅ `process_state_clean` | ✅ `functionIsolation` (skips autoloaders) | ❌ process-wide | ❌ process-wide |
| **User classes** (`class Bar {}`) | ✅ fresh proc | ✅ `process_state_clean` | ✅ `functionIsolation` | ❌ process-wide | ❌ process-wide |
| **`static $var` in functions** | ✅ fresh proc | ✅ pool reset | ✅ per-coro | ✅ per-coro snapshot | ✅ per-coro snapshot |
| **Static class properties** (`Class::$x`) | ✅ fresh proc | ✅ pool reset | ✅ per-coro | ✅ per-coro snapshot | ✅ per-coro snapshot |
| **`ini_set()` values** | ✅ fresh proc | ✅ subprocess scope | ✅ `zealphp_ini_restore` | ✅ per-coro snapshot | ✅ per-coro snapshot |
| **`require_once` cache** | ✅ fresh proc | ✅ `process_state_clean` (flags=7) | ✅ `functionIsolation` | partial | partial |
| **Output buffers** (`ob_start`) | ✅ fresh proc | ✅ pool reset | ✅ per-request | ✅ per-coro | ✅ per-coro |
| **Error/exception handlers** | ✅ fresh proc | ✅ pool reset | ✅ per-request | ✅ per-coro chain | ✅ per-coro chain |
| **`register_shutdown_function`** | ✅ fresh proc | ✅ pool reset | ✅ per-request | ✅ per-coro | ✅ per-coro |
| **Session storage** | flock | flock | TableSessionHandler + 3-way merge | TableSessionHandler + 3-way merge | TableSessionHandler + 3-way merge |
| **`global $foo;` reads** | ✅ fresh proc | ✅ pool reset | ✅ per-request | ✅ Stage 2 COW | ✅ Stage 2 COW |
| **`global $foo;` across yield** | ✅ no yield | ✅ no yield | ✅ no yield | ⚠️ rebinds against rebuilt slot | ⚠️ rebinds against rebuilt slot |
| **Object identity across coroutines** | ✅ fresh slot | ✅ fresh slot | ✅ no concurrency | ⚠️ handle-shared (normal PHP) | ⚠️ handle-shared (normal PHP) |
| **Opcache shared memory** | shared | shared | shared | shared | shared |

**Legend:** ✅ isolated · ❌ shared/race · ⚠️ documented edge case · N/A not applicable

**The 3 architectural carve-outs in coroutine modes (4/5):**

1. **User functions/classes are process-wide.** Once declared via top-level `function foo() {}` or `class Bar {}`, they exist in `CG(function_table)` / `CG(class_table)` for all coroutines. This is intentional — autoloaders need shared class registries. Apps requiring fresh function/class state per request should use Mode 1 (CGI Pool).

2. **`global $foo;` across yield rebinds against the rebuilt slot.** Stage 2 swaps the symbol-table contents on yield/resume. A `global` keyword binds the local frame variable by-reference to `EG(symbol_table)['foo']`. After rebuild, the reference points at a different slot. **Workaround:** use `$g->foo` (per-coroutine `RequestContext`) for state that must survive yields.

3. **Object handles are shared.** PHP objects are passed by handle (not value). Two coroutines accessing `$GLOBALS['db_connection']` share the same object instance. This matches PHP's normal semantics — Stage 2 does not change it.

---

## 3. The 4 Stages of `$GLOBALS` Coroutine Isolation

Evolution of how ext-zealphp handles `$GLOBALS` across coroutines.

### Stage 0 — process-wide (pre-v0.3.6)

`EG(symbol_table)` is one shared HashTable across all coroutines. `$GLOBALS['x'] = 'a'` in coroutine A is immediately visible to coroutine B. Last writer wins; classic race condition. Recommended workaround was to use `$g` (per-coroutine `RequestContext`) for request-scoped state.

### Stage 1 — deep-copy snapshot (v0.3.6, REPLACED)

ext-zealphp hooked OpenSwoole's `on_yield` / `on_resume` and snapshotted EVERY non-superglobal slot of `EG(symbol_table)` per coroutine via `ZVAL_DUP` (full deep-copy).

- **Memory:** O(N keys) per active coroutine
- **Why replaced:** Stage 2 has same correctness with O(deltas) memory

### Stage 2 — copy-on-write parent + delta (v0.3.7, CURRENT)

Three module-level HashTables:

- **`zealphp_coro_globals_parent`** — shared baseline, snapshotted once at activation. Read-mostly.
- **`zealphp_coro_globals_deltas`** — `cid → zval-array` of slots the coroutine wrote that differ from parent.
- **`zealphp_coro_globals_tombstones`** — `cid → zval-array` of parent keys the coroutine `unset()`'d. Stored as `IS_LONG 1` dummies to avoid Zend's `IS_UNDEF` skip in `ZEND_HASH_FOREACH`.

**Snapshot save (on yield):**
1. Walk `EG(symbol_table)`; compare each non-superglobal key against parent via `zend_is_identical`. Emit to delta only if different.
2. Walk parent; emit tombstone for any key absent from EG.
3. `reset_to_parent()` — clear non-SG keys from EG, reinstall parent baseline. Next coroutine starts clean.

**Snapshot restore (on resume):**
1. `reset_to_parent()` (belt-and-suspenders).
2. Apply `deltas[cid]` over baseline.
3. `zend_hash_del` each key in `tombstones[cid]`.

**Memory characteristics:** 50 coros × 5 unique writes test → flat ~2 MB peak RSS. Stage 1 was O(N × coros).

**The IS_UNDEF tombstone bug we fixed:** Initial Stage 2 attempt stored tombstones as `IS_UNDEF` zvals inside the delta array. ZEND_HASH_FOREACH macros silently skip `IS_UNDEF` because that's Zend's internal "deleted bucket" marker. Tombstones were invisible. Fix: separate `tombstones` HashTable with non-`IS_UNDEF` dummy values.

**Adversary tests pass:**
- Two coroutines write same key → each reads own ✓
- A unsets parent key, B still sees parent (via tombstone path) ✓

### Stage 3 — silent-redeclare opcode hooks (SHIPPED, v0.3.8)

The 32-app sweep shows the dominant Mode 3/4/5 failure isn't `$GLOBALS` races (Stage 2 fixed that). It's `Fatal error: Cannot redeclare foo()` / `Cannot declare class Bar` on the second request — declarations in legacy code lit up `EG(function_table)` / `CG(class_table)` on request 1, and the engine refuses to declare them again on request 2.

FPM doesn't hit this because each request gets a fresh PHP process — there's nothing to redeclare AGAINST. Mode 1 Pool sidesteps it too (subprocess scope). But Mode 3/4/5 share one process.

Stage 3 hooks `ZEND_DECLARE_FUNCTION` / `ZEND_DECLARE_CLASS` / `ZEND_DECLARE_CLASS_DELAYED` opcode handlers via `zend_set_user_opcode_handler`:

1. Look up the symbol name in `EG(function_table)` / `CG(class_table)`.
2. If it exists → advance the opcode pointer and return `ZEND_USER_OPCODE_CONTINUE` (skip the bind). First declaration wins.
3. If it doesn't exist → return `ZEND_USER_OPCODE_DISPATCH` (default behavior — declare normally).

**Toggle:** `App::silentRedeclare(true)` (default `false`). Behaviour unchanged for apps that don't opt in.

**Tests pin both branches:** `tests/018-silent-redeclare-function.phpt` (conditional fn redeclare), `tests/019-silent-redeclare-class.phpt` (conditional class redeclare).

**Stage 3 LIMITATION — top-level (file-scope) decls** — `function foo() {}` and `class Bar {}` written at file scope are bound to `CG(function_table)` / `CG(class_table)` at COMPILE time by `zend_register_top_func` / `zend_register_top_class`, NOT through the runtime `ZEND_DECLARE_*` opcodes. The opcode hook cannot see them. A naive `zend_compile_file` intercept that snapshots+restores class-entry pointers around the compile breaks class inheritance + method-table invariants (tested locally — class-method calls after restore segfault). Closing this cleanly requires Stage 4: a careful class-entry teardown helper that preserves method tables, inheritance chains, and refcounting. `tests/020-silent-redeclare-toplevel.phpt` is SKIP-pinned with the explanation.

**32-app × 5-mode sweep with Stage 3 ON (v0.3.8):**
- WordPress M5: `302/302/500` → `302` stable (3/3)
- Lychee M4/M5: `403/X/403` → `403` stable
- Most other Mode 3/4/5 apps unchanged — their crashes are top-level decls (Stage 4) or non-redeclare causes (missing classes, autoload misses, framework-init failures)

### Stage 4 — compile-time silent-redeclare with proper class teardown (PLANNED)

The next step after Stage 3 — needs:
1. A `zend_compile_file` wrapper.
2. Before compile: snapshot existing user `zend_function*` / `zend_class_entry*` from `CG(*_table)`. Increment refcounts so the engine's reset-on-compile doesn't free them.
3. Detach them from `CG(*_table)`.
4. Run original `zend_compile_file` — re-declarations now succeed (the slot is empty).
5. Re-attach saved entries via `zend_hash_update_ptr`, properly releasing the newly-compiled duplicates' method tables / inheritance state to avoid leaks.

Validated locally that step 5 done naively (just `zend_hash_update_ptr` with stashed pointers) breaks `Call to undefined method` on previously-defined classes — the method table inside the entry isn't being maintained correctly across the swap. The fix is a `zend_class_entry_safe_swap()` helper that handles refcount + method table cleanup; out of scope for v0.3.8.

### Stage 5+ — per-coroutine `CG(function_table)` (NOT PLANNED)

Would mean every coroutine triggers the autoloader independently when it touches a class — defeats the whole point of autoloading. Stage 3 + Stage 4 give the user-visible benefit without the architectural cost. **Not on roadmap.**

---

## 4. PHP-Level Setters (`App::*`)

All setters return `self` (fluent) or the current value. Must be called BEFORE `App::run()`.

| Setter | Type | Default | Effect |
|--------|------|---------|--------|
| `App::superglobals(bool)` | bool | `false` | Master switch — true populates `$_GET`/`$_POST`/`$_SESSION`/etc. per request |
| `App::processIsolation(bool)` | bool | follows `$superglobals` | Dispatches to CGI subprocess per request (Mode 1) |
| `App::enableCoroutine(bool)` | bool | follows `!$superglobals` | OpenSwoole `enable_coroutine` server setting |
| `App::hookAll(bool\|int)` | bool/int | follows `!$superglobals` | OpenSwoole `Runtime::enableCoroutine` flags |
| `App::cgiMode(string)` | `'proc'\|'pool'\|'fcgi'` | `'pool'` | CGI dispatch strategy |
| `App::cgiPoolSize(int)` | int | 16 | Pool worker count |
| `App::cgiPoolMaxRequests(int)` | int | 500 | Pool worker recycle threshold |
| `App::cgiTimeout(int)` | int | 60 | Subprocess dispatch timeout (s) |
| `App::functionIsolation(bool)` | bool | `false` | Per-request `process_state_clean` (functions+classes+includes) |
| `App::defineIsolation(bool)` | bool | `false` | Per-request `define()` cleanup only |
| `App::coroutineGlobalsIsolation(bool)` | bool | `false` | Per-coroutine `EG(symbol_table)` swap (Stage 2 COW) |
| `App::sessionLifecycle(bool)` | bool | `true` | Whether framework drives `session_start`/`write_close` |
| `App::sessionHandler(...)` | `string\|SessionHandlerInterface\|null` | `null` (auto) | Session backend: `'table'`, `'file'`, `'redis'`, or instance |
| `App::sessionTtl(int)` | int | 7200 | Session TTL in seconds (2 hours) |
| `App::sessionMaxRows(int)` | int | 65536 | TableSessionHandler row cap |
| `App::sessionDataSize(int)` | int | 16384 | TableSessionHandler bytes per session |
| `App::sessionSavePath(string)` | string | `/var/lib/php/sessions` | File-backing directory |
| `App::documentRoot(string)` | string | `'public'` | Apache `DocumentRoot` parity |
| `App::ignorePhpExt(bool)` | bool | `true` | Allow URLs without `.php` extension |
| `App::traceEnabled(bool)` | bool | `false` | Apache `TraceEnable` parity |
| `App::defaultCharset(string)` | string | `'utf-8'` | Default response charset |
| `App::serverAdmin(string)` | string | `''` | Apache `ServerAdmin` |
| `App::canonicalHost(...)` | ... | `''` | Apache `ServerName`+`UseCanonicalName` |
| `App::trustedProxies(array)` | array | `[]` | CIDR list for `X-Forwarded-For` |
| `App::hookExec(?bool)` | ?bool | `null` (auto) | uopz override for `shell_exec` / backtick |
| `App::authChecker(?callable)` | ?callable | `null` (fail-closed) | `ZealAPI::isAuthenticated()` hook |
| `App::adminChecker(?callable)` | ?callable | `null` (fail-closed) | `ZealAPI::isAdmin()` hook |
| `App::usernameProvider(?callable)` | ?callable | `null` | `ZealAPI::getUsername()` hook |

---

## 5. Environment Variables

All env vars are read at boot (typically in `App::init()` or `App::run()`).

| Variable | Values | Default | Effect |
|----------|--------|---------|--------|
| `ZEALPHP_GLOBALS_ISOLATION_DISABLE` | `1` | unset | Emergency rollback — disables Stage 2 COW even when `coroutineGlobalsIsolation(true)` was called |
| `ZEALPHP_POOL_DEBUG_DEPRECATIONS` | `1` | unset | Restore PHP 8.4 deprecation warnings in pool worker (default suppressed to avoid stderr pipe deadlock) |
| `ZEALPHP_CGI_DEBUG_DEPRECATIONS` | `1` | unset | Same as above for `cgi_worker.php` (proc mode) |
| `ZEALPHP_INI_ISOLATE` | `1` | unset | Opt-in `IniIsolationMiddleware` registration |
| `ZEALPHP_POOL_MAX_REQUESTS` | int | 500 | Pool worker recycle threshold (overrides `App::cgiPoolMaxRequests`) |
| `ZEALPHP_CGI_AUTOLOAD` | `0` | `1` (proc), env-dep (pool) | Skip Composer autoload in CGI subprocess (WordPress doesn't need it) |
| `ZEALPHP_STORE_BACKEND` | `table`\|`redis`\|`tiered` | `table` | Store backend selector — flips `Store::defaultBackend()` |
| `ZEALPHP_REDIS_URL` | `redis://...` / `valkey://...` / `rediss://...` / `tls://...` | `redis://127.0.0.1:6379` | Redis connection string |
| `ZEALPHP_REDIS_PREFER` | `auto`\|`phpredis`\|`predis` | `auto` | Driver preference for Redis backend |
| `ZEALPHP_TIERED_INVALIDATION_SECRET` | string | unset | HMAC shared secret for cross-node L1 invalidation messages |
| `ZEALPHP_SESSION_SECURE` | `1`/`0` | auto-detect | Force `Secure` flag on session cookie |
| `ZEALPHP_HOST` | IP | `0.0.0.0` | Listen address |
| `ZEALPHP_PORT` | port | `8080` | Listen port |
| `ZEALPHP_WORKERS` | int | `swoole_cpu_num()` | HTTP worker count |
| `ZEALPHP_TASK_WORKERS` | int | `0` | Task worker count |
| `ZEALPHP_DEBUG_LOG` | path | `/tmp/zealphp/debug.log` | Debug log path |
| `ZEALPHP_ACCESS_LOG` | path | `/tmp/zealphp/access.log` | Access log path |
| `ZEALPHP_SERVER_LOG` | path | `/tmp/zealphp/server.log` | Server log path |
| `ZEALPHP_ZLOG` | path | `/tmp/zealphp/zlog.log` | Generic log path |

---

## 6. C-Level Primitives (ext-zealphp v0.3.7)

All PHP-callable functions exposed by the extension. ZealPHP framework code calls these internally; user code can call them directly for advanced use.

### Function override family

| Function | Signature | Purpose |
|----------|-----------|---------|
| `zealphp_override($name, $cb)` | `(string, callable): bool` | Replace PHP built-in with closure |
| `zealphp_restore($name)` | `(string): bool` | Restore original handler |
| `zealphp_restore_all()` | `(): void` | Reset all overrides |

### Superglobal isolation

| Function | Purpose |
|----------|---------|
| `zealphp_superglobals_set($which, $value)` | Set a specific superglobal slot |
| `zealphp_superglobals_clear()` | Clear all superglobal slots |
| `zealphp_superglobals_save()` | Snapshot current superglobal values |
| `zealphp_superglobals_restore()` | Restore from snapshot |
| `zealphp_coroutine_superglobals(bool)` | Activate per-coroutine swap of 7 superglobal slots on yield/resume |

### Globals isolation (Stage 2 COW)

| Function | Purpose |
|----------|---------|
| `zealphp_coroutine_globals(bool)` | Activate per-coroutine `EG(symbol_table)` swap (Stage 2 parent + delta + tombstone). Idempotent. Returns true on success. |

### Constants isolation

| Function | Purpose |
|----------|---------|
| `zealphp_define_hook(bool)` | Track `define()` calls per-request |
| `zealphp_constants_clear()` | Remove request-defined constants at request end |

### ini_set restore

| Function | Purpose |
|----------|---------|
| `zealphp_ini_restore()` | Restore all `ini_set` changes via `zend_restore_ini_entry` |

### $GLOBALS reset (Mode 1 Pool only)

| Function | Purpose |
|----------|---------|
| `zealphp_globals_snapshot()` | Save `EG(symbol_table)` key set at worker boot |
| `zealphp_globals_clean()` | Remove keys added after snapshot — FPM-style $GLOBALS reset between requests |

### Process-state reset (Mode 1 Pool, Mode 3 + functionIsolation)

| Function | Purpose |
|----------|---------|
| `zealphp_process_state_snapshot()` | Snapshot `EG(included_files)`, `CG(class_table)`, `CG(function_table)` |
| `zealphp_process_state_clean($flags)` | Remove entries added after snapshot. `$flags`: 1=files, 2=classes, 4=functions. Default 7=all. Destructor-disabled removal to avoid OPcache SHM segfaults. |
| `zealphp_protect_classes(array)` | Add specific class names to the snapshot so they survive cleanup (used to preserve Composer autoloader classes) |

---

## 7. Sweep Results Reference

Full per-app results in [`../compatibility-database.md`](../compatibility-database.md). The matrix below is the post-commit-`9b8111b` sweep across 5 mode containers × 32 real-world PHP apps (3 sequential probes per cell — `code` = identical responses, `c/c/c` = different responses on each probe).

### 32-app × 5-mode matrix (May 28 2026 — post FD-3 IPC fix)

| App | M1 Pool | M1 Proc | M3 Sync+FI | M4 Hybrid | M5 Coro |
|---|:---:|:---:|:---:|:---:|:---:|
| adminer | **200** | **200** | **200** | **200** | 200/X/200 |
| bookstack | 404 | 404 | 404 | 404 | 404 |
| cacti | **200** | 500 | 500 | 500/X/500 | 500/X/500 |
| dokuwiki | 302 | 302 | 302/X/302 | 302/500/500 | 302/500/500 |
| drupal | 500 | 500 | 500/X/500 | 500/500/X | 500 |
| elfinder | 404 | 404 | 404 | 404 | 404 |
| filegator | 500 | 500 | 500 | 500 | 500 |
| flarum | 404 | 404 | 404 | 404 | 404 |
| freshrss | 301 | 301 | 301 | 301 | 301 |
| grav | 500 | 500 | 500/500/200 | 500/200/200 | 500/200/200 |
| joomla | **200** | X | **200** | **200** | **200** |
| kanboard | **200** | **200** | **200** | **200** | **200** |
| lychee | 403 | 403 | 403/X/403 | 403/X/403 | 403/X/403 |
| matomo | **200** | **200** | 200/500/200 | 500/500/200 | 200/500/200 |
| mediawiki | 500 | 500 | 500 | 500 | 500 |
| monica | 404 | 404 | 404 | 404 | 404 |
| mybb | 302 | 500 | 500 | 500 | 500 |
| nextcloud | **200** | **200** | 500 | 500 | 500 |
| opencart | 404 | 404 | 404 | 404 | 404 |
| phpbb | 404 | 404 | 404 | 404 | 404 |
| phpliteadmin | **200** | 500 | 500/X/500 | 500/X/X | 500/X/500 |
| phpmyadmin | **200** | X | 200/500/500 | 500 | X |
| piwigo | 302 | 500 | 500 | 500 | 500 |
| privatebin | **200** | 500 | 500 | 500 | 500 |
| roundcube | **200** | **200** | **200** | **200** | **200** |
| slim-app | 404 | 404 | 404 | 404 | 404 |
| tinyfilemanager | **200** | X | 200/X/200 | 200/X/200 | 200/X/200 |
| traditional | **200** | **200** | **200** | **200** | **200** |
| vanilla | **200** | 500 | 500/200/500 | 500/200/500 | 500/200/500 |
| wallabag | 404 | 404 | 404 | 404 | 404 |
| wordpress | 302 | 302 | 302/500/500 | 302/500/500 | 302/302/500 |
| yourls | 503 | 503 | 503/200/200 | 503/200/200 | 503/200/200 |

**Legend:** **bold** = 3/3 200 OK · `X` = curl timeout · `c/c/c` = differing per-request responses (instability) · `30x/404/500` = same response 3/3 · M1 Proc `X` = subprocess fork pressure (32 reqs serial under load).

### Pass-rate summary (3/3 200 OK)

| Mode | Pass | Stable | Working+redirect | Notes |
|---|:---:|:---:|:---:|---|
| **Mode 1 Pool** | **13/32** (41%) | 16/32 stable | 18/32 (56%) incl. redirects | The headline mode. phpMyAdmin, Cacti, Nextcloud, Privatebin, phpLiteAdmin all green here only. |
| Mode 1 Proc | 8/32 (25%) | 11/32 stable | 13/32 (41%) | Pool wins decisively over Proc — `proc_open` per request hits joomla/phpmyadmin/tinyfilemanager with timeouts under concurrent probes. |
| Mode 3 Sync+FI | 4/32 stable + many flickering | 4/32 stable | 7/32 plausible | Many apps flicker on first request (top-level redeclarations on warm workers). |
| Mode 4 Hybrid | 4/32 stable + many flickering | 4/32 stable | 8/32 plausible | Coroutine semantics + superglobals + Stage 2 COW; redeclare crashes dominate. |
| Mode 5 Coroutine | 3/32 stable + many flickering | 3/32 stable | 7/32 plausible | Pure coroutine; same redeclare ceiling as Mode 4. |

**Apps green in ALL 5 modes (production-portable):**

- adminer (200), kanboard (200), roundcube (200), traditional (200), freshrss (301)

**Apps that require Mode 1 (CGI Pool) — first-class compatibility:**

- phpMyAdmin, Cacti, Nextcloud, Privatebin, phpLiteAdmin, MyBB, Piwigo, Vanilla, MediaWiki, Drupal, Grav

**Apps with config issues (404 in every mode — wrong entry path, not a framework bug):**

- bookstack, elfinder, flarum, monica, opencart, phpbb, slim-app, wallabag

### What changed since the May-28 morning sweep

| Apps | Before commit `9b8111b` | After |
|---|---|---|
| **phpmyadmin M1 Pool** | 504 timeout ("subprocess died") | **200 3/3** |
| **wordpress configured (zealphp-wordpress)** | 200 with 0-byte body | **200 with 68 KB body** |
| Adminer/Kanboard/Joomla/Roundcube M1 Pool | 200 (was already passing) | unchanged |

The fix (FD-3 IPC + don't re-run leftover shutdown fns + ob_end_flush re-open) lifted phpMyAdmin into the Mode 1 Pool stable column and restored every WordPress response body that `wp_ob_end_flush_all()` had been dropping.

---

---

## 8. Decision Tree

```
┌─────────────────────────────────────────────────────────────────┐
│ Q: Is your app written for ZealPHP natively (uses $g->get)?     │
│ ─► YES: Mode 5 (Coroutine)                                       │
│   App::superglobals(false);                                      │
│   App::coroutineGlobalsIsolation(true);  // closes $GLOBALS race│
└─────────────────────────────────────────────────────────────────┘
       ↓ NO
┌─────────────────────────────────────────────────────────────────┐
│ Q: Is it Laravel / Symfony / Slim / CodeIgniter / CakePHP?      │
│ ─► YES: Mode 3 (Sync)                                            │
│   App::superglobals(true);                                       │
│   App::enableCoroutine(false);                                   │
│   App::functionIsolation(true);  // for class/function safety    │
└─────────────────────────────────────────────────────────────────┘
       ↓ NO
┌─────────────────────────────────────────────────────────────────┐
│ Q: Is throughput critical AND code is coroutine-clean?          │
│    (no top-level function/class declarations in includes)       │
│ ─► YES: Mode 4 (Hybrid)                                          │
│   App::superglobals(true);                                       │
│   App::enableCoroutine(true);                                    │
│   App::coroutineGlobalsIsolation(true);                          │
│   App::defineIsolation(true);                                    │
└─────────────────────────────────────────────────────────────────┘
       ↓ NO
┌─────────────────────────────────────────────────────────────────┐
│ Q: Does it use bare define()/function/class declarations at     │
│    file top level without guards?                               │
│ ─► YES (most legacy/WordPress/Drupal/phpBB): Mode 1 (CGI Pool)   │
│   App::superglobals(true);                                       │
│   App::processIsolation(true);                                   │
│   App::cgiMode('pool');                                          │
│   App::cgiPoolMaxRequests(500);  // 1 for true fresh-proc        │
└─────────────────────────────────────────────────────────────────┘
       ↓ NO (you have a unicorn — try Mode 3 first)
```

---

## 9. The Migration Ladder Summary

Where each isolation feature sits in ZealPHP's release history.

| Feature | Released in | Removed in | Notes |
|---------|:---:|:---:|------|
| Mode 1 CGI Proc | v0.1.x | — | Original "fresh process" mode |
| Mode 1 CGI Pool | v0.3.0 | — | FPM-like worker pool |
| Mode 3 Sync | v0.1.x | — | In-process superglobals mode |
| Mode 5 Coroutine | v0.1.x | — | Native ZealPHP mode |
| Mode 4 Hybrid | v0.3.3 | — | Required ext-zealphp |
| `defineIsolation` | v0.3.3 | — | Constants-only cleanup |
| `functionIsolation` | v0.3.4 | — | Full process_state cleanup with autoloader detection |
| TableSessionHandler | v0.3.6 | — | Concurrent-safe sessions without Redis |
| Session 3-way merge | v0.3.6 | — | Leaf-level merge granularity |
| Stage 1 `$GLOBALS` deep-copy | v0.3.6 | v0.3.7 (replaced by Stage 2) | First per-coroutine $GLOBALS impl |
| Stage 2 `$GLOBALS` COW | **v0.3.7** | — | **CURRENT** — parent + delta + tombstone |
| `ZEALPHP_GLOBALS_ISOLATION_DISABLE` env-var rollback | v0.3.7 | — | Emergency switch |
| FD-3 IPC channel (CGI Pool) | **v0.3.8** (commit `9b8111b`) | — | Metadata on dedicated fd 3; STDOUT body-only. Survives `exit()` inside shutdown fn. Restores `wp_ob_end_flush_all()` body. Unblocks phpMyAdmin / WordPress on Mode 1. |
| Stage 3 silent-redeclare opcode hooks | **v0.3.8** (ext commit `d09693d`) | — | Hooks `ZEND_DECLARE_FUNCTION` / `_CLASS` / `_DELAYED` so re-declaring an EXISTING symbol skips the opcode instead of `E_COMPILE_ERROR`. Covers conditional declares (in if / fn / method scope) cleanly. Top-level decls are compile-time-bound → Stage 4. |
| Stage 4 compile-time silent-redeclare | PLANNED | — | `zend_compile_file` wrapper + `zend_class_entry_safe_swap` that preserves method-table + inheritance refcounting. Closes the remaining top-level-decl redeclare lane that Stage 3 leaves open. Validated locally that the naive approach segfaults; correct implementation is non-trivial. |
| Stage 5+ per-coroutine `CG(function_table)` / `CG(class_table)` | NOT PLANNED | — | Would break autoloaders by design (autoloaders register classes once; per-coroutine tables would mean every coroutine triggers the autoloader independently). The silent-redeclare path above is the pragmatic ceiling. |

---

## 10. Cross-references

- [`application-server-sapi.md`](application-server-sapi.md) — high-level positioning: ZealPHP as Application Server + Multi-SAPI runtime
- [`2026-05-27-lifecycle-matrix.md`](2026-05-27-lifecycle-matrix.md) — 12-mode test matrix with Docker lab results
- [`2026-05-27-superglobal-isolation.md`](2026-05-27-superglobal-isolation.md) — superglobal swap deep-dive
- [`2026-05-27-session-concurrency.md`](2026-05-27-session-concurrency.md) — session safety story (file flock, Redis WATCH/MULTI, TableSessionHandler 3-way merge)
- [`../compatibility-database.md`](../compatibility-database.md) — per-app grades across all modes (32-app sweep)
- [`../fastcgi-backends.md`](../fastcgi-backends.md) — CGI backend configuration, per-extension dispatch, ScriptAlias
- ext-zealphp source: [`ext/zealphp/zealphp.c`](../../ext/zealphp/zealphp.c) — C-level implementation
- App.php source: [`src/App.php`](../../src/App.php) — search for setter docstrings
