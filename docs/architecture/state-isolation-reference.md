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

### Stage 3 — VM opcode handlers (THEORETICAL, NOT PLANNED)

Would achieve:
- True transparent isolation of `global $foo;` across yield (reference promotion)
- Engine-level reference tracking

Cost: ~6000 lines of brittle C, opcache JIT integration, PHP version coupling (8.2/8.3/8.4/8.5+), 6–10 weeks dedicated work, fuzz testing harness, threat-modeling pass.

**Not on roadmap** unless production field data shows the `global`-across-yield edge case matters enough to justify the engineering cost.

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

Full results in [`../compatibility-database.md`](../compatibility-database.md). Summary:

| Mode | Apps passing 3/3 (out of 20-32 tested) |
|------|:---:|
| Mode 1 Pool | 18/21 — WordPress, Joomla, Roundcube, OpenCart, Adminer, Cacti, Matomo, Nextcloud, Kanboard, TinyFileManager, FreshRSS, MyBB, phpBB, phpLiteAdmin, Piwigo, PrivateBin, Vanilla, traditional |
| Mode 3 Sync+FI | 11/20 — Adminer, TinyFileManager, FreshRSS, Kanboard, Joomla, Roundcube, OpenCart, Matomo (partial), traditional |
| Mode 4 Hybrid | 8/20 — Kanboard, Joomla, Roundcube, OpenCart, traditional, Adminer (worker-rotation luck), Matomo (partial) |
| Mode 5 Coroutine | 7/20 — Kanboard, Joomla, Roundcube, OpenCart, traditional + clean OOP only |

**Apps that pass ALL 4 modes:** Kanboard, Joomla, Roundcube, OpenCart, traditional (5 apps).

**Apps requiring Mode 1 (CGI Pool):** WordPress, Cacti, MediaWiki, phpBB, MyBB, Piwigo, Nextcloud — any app with bare `define()`/`function`/`class` declarations at top level + process state expectations.

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
| Stage 3 VM opcode handlers | NOT PLANNED | — | Theoretical only |

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
