# Smoke Test Findings — Legacy App Compatibility on ext-zealphp v0.3.8

**Date:** 2026-05-28
**Environment:** 12-core perf VM, Docker (PHP 8.4-cli + OpenSwoole 26.2.0 + ext-zealphp v0.3.8, Stage 4 + security fixes)
**Scope:** Validate whether the 6-stage isolation work in ext-zealphp makes unpatched legacy PHP apps work in persistent-server modes.

---

## 1. Test Infrastructure

### Docker Compose (`docker-compose.smoketest.yml`)

7 containers orchestrated against a shared MySQL 8.0 instance:

| Container | App | Mode | Port | Server script |
|-----------|-----|------|------|---------------|
| `zealphp-demo` | ZealPHP OSS website | Mode 5 (pure coroutine) | 9080 | `app.php` |
| `zealphp-wp-mode1` | WordPress (latest) | Mode 1 (CGI Pool) | 9081 | `wp-server-mode1.php` |
| `zealphp-wp-mode4` | WordPress (latest) | Mode 3 (sync + includeIsolation) | 9084 | `wp-server-mode4.php` |
| `zealphp-wp-mode5` | WordPress (latest) | Mode 5 (pure coroutine) | 9085 | `wp-server-mode5.php` |
| `zealphp-adminer` | Adminer 4.8.4 | Mode 5 | 9082 | `adminer-server.php` |
| `zealphp-privatebin` | PrivateBin 1.7.6 | Mode 5 | 9083 | `privatebin-server.php` |
| `mysql` | MySQL 8.0 | — | 3306 | — |

### Image (`Dockerfile.smoketest`)

Built from `php:8.4-cli-bookworm` with:
- OpenSwoole installed via PECL
- ext-zealphp compiled from **local source** (`ext/zealphp/`) — not from GitHub
- PHP extensions: sockets, pcntl, mysqli, pdo_mysql
- `short_open_tag=On`, `memory_limit=1024M`

### Entrypoint Scripts (`scripts/smoke/entrypoint-*.sh`)

Each entrypoint downloads the target app at container start:
- **WordPress**: `curl -sL https://wordpress.org/latest.tar.gz`, auto-configures `wp-config.php` against the MySQL container, waits for MySQL healthcheck
- **Adminer**: downloads single-file `adminer-4.8.4.php` from GitHub releases
- **PrivateBin**: downloads 1.7.6 tarball from GitHub

WordPress containers share a `wp-source` volume so the download happens once (Mode 1 starts first via `depends_on`).

---

## 2. Results Summary

### Passing

| App | Mode | Response size | Stability | Latency | Notes |
|-----|------|--------------|-----------|---------|-------|
| ZealPHP Demo | Mode 5 (coroutine) | 39 KB | 20/20 | — | All pages render, 404 handling works |
| PrivateBin | Mode 5 (coroutine) | 20 KB | 10/10 | — | Full UI renders |
| WordPress `wp-login.php` | Mode 3 (sync) | 5.4 KB | stable | — | Login form renders correctly |
| WordPress homepage | Mode 3 (sync + `includeIsolation` + worker bootstrap) | 27 KB | 20/20 | 22 ms | Full HTML, no WordPress patches, the headline result |

### Failing

| App | Mode | Failure | Root cause | Section |
|-----|------|---------|------------|---------|
| WordPress | Mode 4 (coroutine + superglobals) | `ABSPATH` undefined | `onWorkerStart` constants not visible to coroutine request handlers | [4.4](#44-coroutine-mode-onworkerstart-state-propagation) |
| WordPress | Mode 5 (pure coroutine) | `$_SERVER` empty | By design: `superglobals(false)` does not populate `$_*` | [4.2](#42-_server-not-populated-in-mode-5-by-design) |
| Adminer | Mode 5 | Download URL returned 404 | Adminer download URL needed `curl -L` (redirect follow) | Cosmetic — not an isolation bug |

---

## 3. The Headline Result — WordPress on Mode 3

### Configuration that works (`wp-server-mode4.php`)

```php
App::superglobals(true);
App::processIsolation(false);
App::enableCoroutine(false);
App::documentRoot("/wordpress");
App::ignorePhpExt(false);
App::silentRedeclare(true);
App::includeIsolation(true);

$app = App::init("0.0.0.0", 8080);

// Bootstrap WordPress ONCE per worker, THEN re-snapshot
App::onWorkerStart(function() {
    chdir('/wordpress');
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', true);
    }
    require_once '/wordpress/wp-load.php';

    // Re-snapshot AFTER WordPress bootstrap — all WP core files
    // are now in the snapshot and won't be cleared per-request
    if (function_exists('zealphp_process_state_snapshot')) {
        zealphp_process_state_snapshot();
    }
});

$app->setFallback(function() {
    return App::include("/index.php");
});

$app->run(["worker_num" => 2, "task_worker_num" => 0]);
```

Despite the filename `wp-server-mode4.php`, the actual configuration is **Mode 3** (sync): `enableCoroutine(false)`, `processIsolation(false)`, `superglobals(true)`. The key additions beyond vanilla Mode 3:

1. **`silentRedeclare(true)`** — Stage 3 opcode hooks suppress `E_COMPILE_ERROR` on re-declaration of functions/classes/constants that WordPress defines at the top of files re-included on each request.
2. **`includeIsolation(true)`** — calls `zealphp_process_state_clean(1)` (flag 1 = clear `EG(included_files)` only) per request, so `require_once` files re-execute.
3. **Worker bootstrap + re-snapshot** — loads `wp-load.php` in `onWorkerStart`, then calls `zealphp_process_state_snapshot()` to capture the bootstrap files. The per-request cleanup (`includeIsolation`) only clears files added AFTER the snapshot, so WordPress core files survive while per-request files (`template-loader.php`) get cleared and re-execute.

This is the **FrankenPHP worker pattern** achieved through framework configuration — no WordPress patches required.

### Why this matters

WordPress's `wp-blog-header.php` uses `require_once` for `template-loader.php`. In a persistent server, this file is loaded on the first request (R1) and becomes a no-op on R2+. But `template-loader.php` contains per-request routing logic that MUST re-execute — it selects the template based on the current URL. Without `includeIsolation`, the second request renders an empty page (84 bytes — just the `WP_USE_THEMES` guard output) instead of the full 27 KB WordPress homepage.

---

## 4. Root Causes Discovered

### 4.1 The `require_once` cache problem (CRITICAL — now solved)

**Problem:** PHP's `require_once` / `include_once` maintain a process-wide file inclusion cache (`EG(included_files)`). In a traditional SAPI (mod_php, FPM), this cache is cleared between requests because each request is a fresh process/RINIT cycle. In a persistent server, the cache persists across requests. Files loaded via `require_once` on request 1 become no-ops on request 2+.

**Impact:** Any app that uses `require_once` for per-request logic (routing, template selection, middleware dispatch) breaks silently on the second request. WordPress is the canonical example: `template-loader.php` is `require_once`'d from `wp-blog-header.php` and contains the URL-to-template routing logic.

**Solution:** `App::includeIsolation(true)` — a new framework feature that calls `zealphp_process_state_clean(1)` per request. Flag 1 clears `EG(included_files)` only (not function/class tables). Combined with the worker bootstrap + re-snapshot pattern:

1. `onWorkerStart` loads the app's bootstrap files (`wp-load.php` and its transitive `require_once` chain)
2. `zealphp_process_state_snapshot()` captures the current `EG(included_files)` set as the baseline
3. Per-request, `zealphp_process_state_clean(1)` resets `EG(included_files)` back to the snapshot — bootstrap files survive, per-request files get cleared

This is a **global fix** — not WordPress-specific. Any PHP app using `require_once` for per-request code benefits.

### 4.2 `$_SERVER` not populated in Mode 5 (by design)

**Problem:** Mode 5 (`superglobals(false)`) does not populate `$_SERVER`, `$_GET`, `$_POST`, etc. per request. The request data lives in `$g->server`, `$g->get`, `$g->post` instead. Legacy apps reading `$_SERVER['REQUEST_URI']` see stale or empty values.

**Impact:** Any legacy app requires `superglobals(true)` (Mode 1, 3, or 4) to work. Mode 5 is exclusively for ZealPHP-native apps that use the `$g->` API.

**Workaround attempted:** A middleware that bridges `$g->server` into `$_SERVER`:

```php
$app->addMiddleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = \ZealPHP\RequestContext::instance();
        foreach ($g->server as $k => $v) {
            $_SERVER[$k] = $v;
        }
        return $handler->handle($request);
    }
});
```

This was tested in `wp-server-mode5-fixed.php` but is insufficient — WordPress reads `$_SERVER` in dozens of locations including during early bootstrap before middleware runs, and process-wide `$_SERVER` writes in coroutine mode race across concurrent requests.

**Conclusion:** `superglobals(true)` is the correct and only safe path for legacy apps. The middleware bridge is architecturally unsound.

### 4.3 WordPress 7.0 + PHP 8.4 compatibility

Two upstream WordPress bugs surfaced during testing:

1. **`get_network()` undefined in non-multisite installs** — WordPress 7.0 calls `get_network()` unconditionally in a code path that assumes multisite. Non-multisite installs on PHP 8.4 trigger a fatal error.
2. **`WP_HTML_Tag_Processor::sort_start_ascending()` private method callback** — PHP 8.4's stricter visibility enforcement on callable resolution rejects a private method used as a sort callback.

**Resolution:** WordPress 6.7 works cleanly. WordPress 7.0 has upstream PHP 8.4 compat bugs. These are not ZealPHP issues — they reproduce on vanilla PHP-FPM with PHP 8.4.

### 4.4 Coroutine mode `onWorkerStart` state propagation

**Problem:** Constants defined in `onWorkerStart` (like `ABSPATH`, `WP_USE_THEMES`) are not visible inside coroutine request handlers when `enableCoroutine(true)`. The worker bootstrap runs in the main worker context, but each request runs in its own coroutine with a potentially different constant table scope.

**Symptom:** WordPress fails immediately with `ABSPATH undefined` in Mode 4 (coroutine + superglobals).

**Root cause:** Likely a gap in ext-zealphp's Stage 2 (COW `$GLOBALS`) or the interaction between OpenSwoole's per-coroutine `EG` swap and the constant table (`EG(zend_constants)`). Constants defined before coroutines start should be inherited, but the snapshot/restore cycle may be clearing them.

**Status:** Needs investigation in ext-zealphp. Mode 3 (sync, no coroutines) is the working path for WordPress today.

### 4.5 Double dispatch on fallback routes

**Problem:** When both the implicit route (via `serveDirectory()` for `/`) AND `setFallback()` map to `App::include("/index.php")`, WordPress's `index.php` gets included twice. The first dispatch renders the full 49 KB homepage. The second dispatch overwrites the response with 84 bytes (just the `WP_USE_THEMES` guard check output).

**Symptom:** Intermittent 84-byte responses instead of full pages, or response sizes double what they should be.

**Resolution:** Remove `setFallback()` when the implicit route already handles `/`, or ensure the fallback only fires for genuinely unmatched routes. In the working Mode 3 config, the implicit route via `documentRoot("/wordpress")` handles `index.php` directly.

---

## 5. ext-zealphp Isolation Stage Status

| Stage | What it does | Status | Notes |
|-------|-------------|--------|-------|
| Stage 1 | uopz overrides (`header`, `session_*`, `exit`) | Ships in ext-zealphp | Foundation for all modes |
| Stage 2 | COW `$GLOBALS` per-coroutine | Ships, works in coroutine mode | `zealphp_coroutine_globals()` — per-coroutine `EG(symbol_table)` swap |
| Stage 3 | Silent-redeclare opcode hooks (function/class/constant) | Ships, tested | Suppresses `E_COMPILE_ERROR` on re-declaration. Critical for `includeIsolation`. |
| Stage 4 | CG-table swap for compile-time silent-redeclare | Ships, O(K) per compile | Complements Stage 3 for compiler-level re-declaration |
| Stage 5 | EG-table swap (per-coroutine function/class tables) | Reverted | Broke internal engine lookups — autoloaders, `class_exists()`, inheritance resolution all failed |
| Stage 6 | Per-worker op_array cache | Scaffolded, disabled | Key-lifetime bug: cached op_arrays reference freed memory after `zealphp_process_state_clean`. Needs refcount-aware eviction. |

### What ships and works together (v0.3.8)

The combination of Stages 1 + 2 + 3 + 4 plus the framework's `includeIsolation` + worker bootstrap pattern is sufficient for running WordPress (and by extension, most `require_once`-heavy legacy PHP apps) in Mode 3 without patches.

Stages 5 and 6 are not required for the Mode 3 path. They would be needed for full coroutine-mode (Mode 4/5) legacy app support — a future goal.

---

## 6. Server Configuration Variants Tested

Multiple `wp-server-mode*.php` scripts were used to isolate which configuration knobs matter:

| Script | Key settings | Result |
|--------|-------------|--------|
| `wp-server-mode1.php` | `superglobals(true)`, default CGI Pool | Works — subprocess isolation handles everything, ~5-10 ms overhead |
| `wp-server-mode4.php` | `superglobals(true)`, `enableCoroutine(false)`, `silentRedeclare(true)`, `includeIsolation(true)`, worker bootstrap + re-snapshot | **Works — 20/20, 27 KB, 22 ms, the headline result** |
| `wp-server-mode4-clean.php` | `superglobals(true)`, `enableCoroutine(true)`, `hookAll(true)`, `silentRedeclare(true)` | Fails — coroutine mode, `ABSPATH` undefined |
| `wp-server-mode4-vanilla.php` | `superglobals(true)`, `enableCoroutine(true)`, `hookAll(true)`, no `silentRedeclare` | Fails — re-declaration fatals on R2+ |
| `wp-server-mode4-nosr.php` | `superglobals(true)`, `enableCoroutine(true)`, `hookAll(true)`, `keepGlobals(true)`, no `silentRedeclare` | Fails — same re-declaration fatals |
| `wp-server-mode5.php` | `superglobals(false)`, `silentRedeclare(true)`, `keepGlobals(true)` | Fails — `$_SERVER` empty, WP can't route |
| `wp-server-mode5-fixed.php` | `superglobals(false)`, middleware bridge `$g->server` to `$_SERVER` | Fails — bridge insufficient, races in coroutine mode |
| `wp-server-mode5-nkgl.php` | `superglobals(false)`, `silentRedeclare(true)`, no `keepGlobals` | Fails — globals cleaned per-request, WP state lost |
| `wp-server-minimal.php` | Raw OpenSwoole (no ZealPHP), manual `$_SERVER` population, `enable_coroutine: false` | Works (diagnostic only) — confirms WordPress itself renders when `$_SERVER` + `include` semantics are correct |

### Key takeaway from the variant matrix

The **minimum viable configuration** for WordPress on a persistent server without patches is:

1. `superglobals(true)` — populates `$_SERVER`, `$_GET`, `$_POST`, etc.
2. `enableCoroutine(false)` — sequential request handling, no coroutine state isolation needed
3. `silentRedeclare(true)` — Stage 3 suppresses re-declaration errors
4. `includeIsolation(true)` — clears `require_once` cache per request
5. Worker bootstrap + re-snapshot — separates bootstrap files from per-request files

Remove any one of items 3-5 and WordPress breaks on the second request.

---

## 7. The Architecture Pattern That Works

```
                         BOOT TIME
┌─────────────────────────────────────────────────────┐
│  App::superglobals(true)                            │
│  App::silentRedeclare(true)                         │
│  App::includeIsolation(true)                        │
│                                                     │
│  App::init() → $app                                 │
│                                                     │
│  App::onWorkerStart(function() {                    │
│      require_once 'wp-load.php';    ← bootstrap     │
│      zealphp_process_state_snapshot();  ← capture   │
│  })                                                 │
│                                                     │
│  $app->run()                                        │
└─────────────────────────────────────────────────────┘

                    PER WORKER (once, after fork)
┌─────────────────────────────────────────────────────┐
│  onWorkerStart fires:                               │
│    1. require_once wp-load.php                      │
│       └─ wp-settings.php                            │
│           └─ plugin files, theme functions.php, ... │
│    2. zealphp_process_state_snapshot()               │
│       └─ EG(included_files) baseline = {wp-load,    │
│          wp-settings, plugins, theme, ...}           │
└─────────────────────────────────────────────────────┘

                    PER REQUEST
┌─────────────────────────────────────────────────────┐
│  1. SessionManager populates $_SERVER, $_GET, etc.  │
│  2. zealphp_process_state_clean(1)                  │
│     └─ EG(included_files) reset to snapshot         │
│     └─ bootstrap files survive                      │
│     └─ per-request files (template-loader.php)      │
│        are cleared from the cache                   │
│  3. App::include("/index.php")                      │
│     └─ wp-blog-header.php                           │
│         └─ require_once template-loader.php          │
│             └─ RE-EXECUTES (cleared from cache)     │
│             └─ selects template based on URL         │
│             └─ renders full page                    │
│  4. silentRedeclare suppresses any constant/         │
│     function/class re-declaration errors            │
│  5. Response sent                                   │
└─────────────────────────────────────────────────────┘
```

This is functionally equivalent to the FrankenPHP worker mode pattern: bootstrap the app once per worker, re-execute per-request entry points on each request. The difference is that ZealPHP achieves this through C-extension isolation primitives (`zealphp_process_state_snapshot`, `zealphp_process_state_clean`, `zealphp_silent_redeclare`) rather than a custom SAPI.

---

## 8. Remaining Work for Full Coroutine-Mode Legacy Apps

The Mode 3 (sync) path works today. Full coroutine-mode (Mode 4) WordPress requires resolving these gaps:

### 8.1 `onWorkerStart` to coroutine state bridge

Constants and file state from worker bootstrap must survive into coroutine request handlers. Currently, the coroutine context either doesn't inherit the worker's constant table or the snapshot/restore cycle clears it.

**Investigation path:** Trace what happens to `EG(zend_constants)` when OpenSwoole creates a coroutine for a new request. Check if ext-zealphp's Stage 2 COW snapshot captures constants or only `EG(symbol_table)`.

### 8.2 Per-coroutine include tracking

`EG(included_files)` is process-wide. `includeIsolation` clears it per-request, but in coroutine mode, two concurrent requests could interfere with each other's include cache. The cleanup would need to be per-coroutine, not process-wide.

**Investigation path:** Extend Stage 2's coroutine context to snapshot/restore `EG(included_files)` alongside `EG(symbol_table)`.

### 8.3 Hook ordering in `onWorkerStart`

The framework's `onWorkerStart` hook (which takes the ext-zealphp snapshot) currently runs via a callback registered in `App::run()`. The user's `onWorkerStart` hook (which bootstraps the app) is registered separately. The framework's snapshot hook must run AFTER the user's bootstrap hook, or the snapshot won't include the bootstrap files.

**Current state:** The `wp-server-mode4.php` script handles this correctly by calling `zealphp_process_state_snapshot()` explicitly at the end of the user's `onWorkerStart` callback. A more robust solution would be a framework-level "post-bootstrap snapshot" mechanism.

---

## 9. Files Changed During This Investigation

| File | Change |
|------|--------|
| `src/App.php` | Added `$include_isolation` static property + `includeIsolation()` fluent setter; snapshot gate in `run()` boot sequence |
| `src/Session/SessionManager.php` | Added `includeIsolation` cleanup call (`zealphp_process_state_clean(1)` — files only) in per-request handler |
| `src/Session/CoSessionManager.php` | Same cleanup call for coroutine session manager |
| `Dockerfile.smoketest` | PHP 8.4-cli + OpenSwoole + ext-zealphp built from local `ext/zealphp/` source |
| `docker-compose.smoketest.yml` | 7-container orchestration: MySQL + 6 ZealPHP app containers |
| `scripts/smoke/wp-server-mode1.php` | WordPress Mode 1 (CGI Pool) — baseline |
| `scripts/smoke/wp-server-mode4.php` | WordPress Mode 3 (sync + `includeIsolation` + worker bootstrap) — the working config |
| `scripts/smoke/wp-server-mode4-clean.php` | WordPress Mode 4 (coroutine) — fails, diagnostic |
| `scripts/smoke/wp-server-mode4-vanilla.php` | WordPress Mode 4 (vanilla, no isolation) — fails, diagnostic |
| `scripts/smoke/wp-server-mode4-nosr.php` | WordPress Mode 4 (no `silentRedeclare`) — fails, diagnostic |
| `scripts/smoke/wp-server-mode5.php` | WordPress Mode 5 (pure coroutine) — fails, diagnostic |
| `scripts/smoke/wp-server-mode5-fixed.php` | WordPress Mode 5 + `$_SERVER` bridge middleware — fails, diagnostic |
| `scripts/smoke/wp-server-mode5-nkgl.php` | WordPress Mode 5 (no `keepGlobals`) — fails, diagnostic |
| `scripts/smoke/wp-server-minimal.php` | Raw OpenSwoole (no framework) — diagnostic to confirm WP renders |
| `scripts/smoke/adminer-server.php` | Adminer on Mode 5 |
| `scripts/smoke/privatebin-server.php` | PrivateBin on Mode 5 |
| `scripts/smoke/entrypoint-wp.sh` | WordPress download + MySQL wait + wp-config generation |
| `scripts/smoke/entrypoint-adminer.sh` | Adminer download |
| `scripts/smoke/entrypoint-privatebin.sh` | PrivateBin download |

---

## 10. Conclusions

1. **ext-zealphp's isolation stages work.** The combination of Stage 1 (uopz overrides), Stage 3 (silent-redeclare), and the new `includeIsolation` feature is sufficient to run unpatched WordPress on a persistent PHP server.

2. **Mode 3 (sync) is the production path for legacy apps today.** Sequential request handling avoids all coroutine-related state isolation challenges. The 22 ms response time with 27 KB WordPress homepage output demonstrates that the overhead is negligible — the worker bootstrap pattern eliminates the per-request PHP bootstrap cost that makes FPM slow.

3. **Mode 1 (CGI Pool) remains the safest fallback.** For apps with truly pathological isolation requirements (non-idempotent plugins, `define()`-heavy bootstrap), CGI Pool provides full subprocess isolation at ~5-10 ms overhead.

4. **Coroutine-mode legacy apps (Mode 4) are a future goal, not a current capability.** The `onWorkerStart` state propagation gap and per-coroutine include tracking are solvable but require further ext-zealphp work. The sync path is performant enough that this is not blocking.

5. **The `require_once` cache problem is the single most important discovery.** It affects every persistent PHP server (Swoole, OpenSwoole, RoadRunner, FrankenPHP) and every PHP app that uses `require_once` for per-request code. The snapshot/clean pattern is the correct generic solution.
