# ZealPHP 50-App Compatibility Database

> Last updated: 2026-05-31  
> Test environment: PHP 8.4.21 + OpenSwoole 26.2.0 + ext-zealphp 0.3.3–0.3.24 (grades vary by section — see per-section notes)  
> Docker lab sweep — actual boot + first-request tests where marked as tested

> ### 2026-06-10 real-work re-validation (ext-zealphp 0.3.46, coroutine-legacy)
> A focused real-work pass (beyond boot/first-request) on the post-0.3.46 stack — the session that shipped the child-coroutine superglobal lane (0.3.43), tz/mb/libxml isolation (0.3.45), and the **mysqlnd vio orig_path allocator-consistency shim (0.3.46)**:
>
> | App | Real work performed | Result |
> |---|---|---|
> | **WordPress** (public) | homepage render, login (auth cookie set), `ab -c10/-c20` concurrency | 200 (real 49 KB page); **0 crashes in steady state** (mysqlnd shim holds — pre-shim this crash-looped 11–14 worker deaths/run) |
> | **WordPress** (wp-admin) | admin password reset → authenticated dashboard + post-list | **200** (Dashboard 115 KB "At a Glance/Howdy"; edit.php 97 KB real post list) **with `App::globalScopeInclude(true)`** — closes the `$_wp_submenu_nopriv` `array_keys(null)` 500 |
> | **File upload** (`$_FILES` + `move_uploaded_file`) | 24 concurrent multipart uploads, unique random payloads | **24/24 landed with their own SHA**, 0 cross-coroutine `tmp_name`/`$_FILES` mixing, 0 crashes |
> | **Adminer 4.8.1** | boot, login form, DB-login POST to MariaDB | 200 boot; login processed (302); deep schema/SQL gated by Adminer's CSRF token (app-auth, not framework); 0 crashes |
> | **TinyFileManager** | boot, login screen render | 200; login needs `SessionStartMiddleware` for first-visitor sessions (app-config, not framework); 0 crashes |
>
> **Cross-app finding:** every app **boots + renders + processes its entry/auth flow under coroutine-legacy with ZERO crashes** on 0.3.46. Cold-concurrent first-wave edges (HAZARD-2 cold-autoload + the residual cold-boot mysqlnd, ext#44) are self-healing and mitigated by `App::preloadClassmap()` (which these unconfigured harnesses don't call); steady state is clean. Deep multi-step authed flows hit each app's own CSRF/nonce/session tokens — a test-harness fidelity matter, not a framework defect.
>
> **New issue surfaced:** persistent **userland** streams (`pfsockopen` / `stream_socket_client(STREAM_CLIENT_PERSISTENT)`) hit the same orig_path allocator mismatch as ext#44 on the generic xport path the vio shim doesn't cover (ext#45) — niche (persistent sockets are semantically wrong under coroutines anyway), no real app in this pass tripped it.

This is the reference for which PHP applications work with ZealPHP and in which modes. Use the [Mode Selection Guide](#mode-selection-guide) to quickly find your configuration.

---

## Modes Reference

> **Canonical API:** use `App::mode(string)` to set both axes in one call. The "Mode N" labels below are shorthand used throughout this document; they map to the presets shown. See [/coroutines#lifecycle-modes](/coroutines#lifecycle-modes) for the full matrix.

| Mode | Canonical preset | Config (fine-grained equivalent) | Description | Overhead | Concurrency |
|------|-----------------|----------------------------------|-------------|----------|-------------|
| **Mode 1 (CGI Pool)** | `App::mode(App::MODE_LEGACY_CGI)` | `superglobals(true) + isolation(CgiPool)` | Each request runs in a pre-spawned pool worker subprocess. Like Apache mod_php. Fresh globals per request. | **~1–3 ms warm** (pool); opt-in `cgiMode('proc')` = ~30–50 ms cold per-process | Sequential per worker |
| **Mode 3 (Sync)** | `App::mode(App::MODE_MIXED)` | `superglobals(true) + isolation(None)` | In-process, sequential. Superglobals populated per request. No subprocess overhead. | ~0 ms | Sequential per worker |
| **Mode 4 (coroutine-legacy)** | `App::mode(App::MODE_COROUTINE_LEGACY)` | `superglobals(true) + isolation(Coroutine)` + silentRedeclare + includeIsolation + coroutineGlobalsIsolation + coroutineStaticsIsolation | Requires ext-zealphp. Full coroutine concurrency with per-coroutine isolation of superglobals, `$GLOBALS`, function statics, and `require_once` re-execution. (`define()` isolation is a separate opt-in: `App::defineIsolation(true)`.) | ~5 ms | Full coroutine |
| **Mode 5 (Coroutine)** | `App::mode(App::MODE_COROUTINE)` | `superglobals(false) + isolation(Coroutine)` | Native ZealPHP mode. Uses `$g->get`/`$g->post` instead of `$_GET`/`$_POST`. Highest performance. | ~0 ms | Full coroutine |

> **⚠️ The Mode 4 grades below predate the full `coroutine-legacy` preset (root-caused 2026-05-30).** "Mode 4" as tested here (`superglobals + enableCoroutine + defineIsolation`, ext-zealphp 0.3.3–0.3.8) does NOT include Stage 7 `includeIsolation` + `silentRedeclare`, which `App::mode(App::MODE_COROUTINE_LEGACY)` now auto-enables. Under the full preset there is a hard rule: an inherited class (`extends`/`implements`) declared in a `require_once`'d file that Stage 7 re-executes per request corrupts its `default_properties_table` → hard SIGSEGV — fixed in ext-zealphp 0.3.24 (Stage 4 orphans inherited losers instead of destroying them). **Net effect, verified on real apps (PHP 8.4, ASAN, ext-zealphp as the sole override engine):** Composer/PSR-4 **autoload** apps are SAFE in coroutine-legacy (Adminer 5.4.2 ✓, CommonMark 2.x with 224 inherited classes ✓); legacy **`require_once`-bootstrap** apps (WordPress) also trip a separate cold-boot `mysqlnd`/`libtasn1` connection-teardown heap overflow and must use **Mode 1 / `legacy-cgi`** until that second layer lands. Re-graded against ext-zealphp 0.3.25 (this release): a 12-app coroutine-legacy sweep (PHP 8.4 + ASAN) upgraded **8 apps from Mode-1-only** — Adminer, TinyFileManager, FreshRSS, YOURLS, Grav, phpBB, MyBB, Piwigo — plus **Drupal** (via the per-request class-static reset) now run in coroutine-legacy **sequentially** (worker-stable, ASAN-clean, zero redeclaration crashes); MediaWiki boots. WordPress serves its public site + login auth + comment writes end-to-end sequentially. **True concurrency of pure-`require_once` apps (classic WordPress) remains the home of `legacy-cgi`.** The per-app Mode-4 grades in the tables below predate this sweep and skew pessimistic for those 8 apps.

---

## Grading Scale

| Grade | Meaning |
|-------|---------|
| **A** | Works out of the box, all requests pass |
| **B** | Works with minor config (DB setup, config file, composer install) |
| **C** | Works in specific mode only, or needs significant configuration |
| **D** | Needs code patches or has significant limitations |
| **F** | Fundamentally incompatible without major refactoring |
| **NT** | Not tested — prediction based on architecture analysis |

---

## Summary Table

All 50 apps sorted by category, with grades per mode.

| # | App | Category | Stars | Framework | Mode 1 (CGI) | Mode 3 (Sync) | Mode 4 (Hybrid) | Mode 5 (Coroutine) | Best Mode | Key Issue |
|---|-----|----------|-------|-----------|:---:|:---:|:---:|:---:|-----------|-----------|
| 1 | WordPress | CMS | 19k | Custom | **A** | C | B | F | 1 | Mode 1 verified end-to-end incl. wp-admin + block editor (issue #167). `define()` everywhere, plugin ecosystem |
| 2 | Drupal | CMS | 4.3k | Custom | **A** | C | **B** | F | 1 or 4 | Static registry, `drupal_bootstrap()`; Mode 4 worker-stable (sequential) via class-static reset in 0.3.25 sweep |
| 3 | Joomla | CMS | 4.7k | Custom | **A** | **A** | **A** | **A** | Any | **TESTED: all 4 modes pass (200, 14ms)** |
| 4 | TYPO3 | CMS | 1.0k | Symfony | B | **A** | A | NT | 3 | Symfony-based, clean OOP |
| 5 | Concrete CMS | CMS | 768 | Custom | **A** | C | B | F | 1 | Legacy OOP, superglobal-heavy |
| 6 | October CMS | CMS | 11k | Laravel | C | **A** | A | NT | 3 | Laravel-based; Octane-aware |
| 7 | Craft CMS | CMS | 3.1k | Yii-based | C | **A** | A | NT | 3 | Yii2 internals, clean OOP |
| 8 | Grav | CMS | 14k | Custom | **B** | F | **B** | F | 1 or 4 | Mode 1 works after init; Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) worker-stable (sequential) — see sweep note |
| 9 | Kirby | CMS | 7.5k | Custom | B | **A** | A | NT | 3 | Modern OOP, no legacy cruft |
| 10 | Statamic | CMS | 3.9k | Laravel | C | **A** | A | NT | 3 | Laravel-based |
| 11 | Bagisto | E-comm | 15k | Laravel | C | **A** | A | NT | 3 | Laravel + Vue; clean |
| 12 | Magento 2 | E-comm | 11k | Custom | **A** | D | C | F | 1 | Massive static state, DI container |
| 13 | WooCommerce | E-comm | 9.6k | WordPress | **A** | F | C | F | 1 | WordPress plugin — same constraints |
| 14 | PrestaShop | E-comm | 7.8k | Custom | **A** | C | B | F | 1 | Global objects, legacy OOP |
| 15 | OpenCart | E-comm | 7.3k | Custom | **A** | **A** | **A** | **A** | Any | **TESTED: all 4 modes pass (302, 20ms)** |
| 16 | Sylius | E-comm | 7.7k | Symfony | C | **A** | A | NT | 3 | Symfony-based, clean |
| 17 | Flarum | Forums | 15k | Custom | B | **A** | A | NT | 3 | PSR-7, Laravel-Eloquent, clean OOP |
| 18 | phpBB | Forums | 1.8k | Custom | **A** | D | **B** | F | 1 or 4 | Legacy procedural, global state; Mode 4 worker-stable (sequential) in 0.3.25 sweep |
| 19 | MyBB | Forums | 2.9k | Custom | **A** | D | **B** | F | 1 or 4 | Procedural, superglobal-heavy; Mode 4 worker-stable (sequential) in 0.3.25 sweep |
| 20 | Vanilla Forums | Forums | 2.9k | Custom | **A** | C | B | F | 1 | Hybrid OOP/procedural |
| 21 | Laravel | Framework | 79k | Self | C | **A** | A | NT | 3 | Static facades, IOC container |
| 22 | Symfony | Framework | 30k | Self | C | **A** | A | NT | 3 | PSR-15, kernel.terminate lifecycle |
| 23 | CodeIgniter 4 | Framework | 5.3k | Self | B | **A** | A | NT | 3 | Clean OOP, minimal static state |
| 24 | CakePHP | Framework | 8.7k | Self | B | **A** | A | NT | 3 | ORM, OOP, manageable state |
| 25 | Slim | Framework | 12k | Self | B | **A** | A | B | 3 | **TESTED: framework routing works (405 = correct)** |
| 26 | Yii 2 | Framework | 14k | Self | B | **A** | A | NT | 3 | Component model, OOP |
| 27 | Laminas | Framework | 5.1k | Self | B | **A** | A | NT | 3 | PSR-7/15, Zend successor |
| 28 | phpMyAdmin | Admin | 7.2k | Custom | C | **A** | A | A | 3 | Vendor deps needed; CGI crashes |
| 29 | Adminer | Admin | 6.1k | Custom | **A** | F | A | F | 1 or 4 | Function redeclaration on 2nd req |
| 30 | TinyFileManager | Admin | 6.2k | Custom | **A** | F | **B** | F | 1 or 4 | Function/constant redeclaration; Mode 4 worker-stable (sequential) in 0.3.25 sweep |
| 31 | Roundcube | Admin | 6.0k | Custom | **A** | **A** | **A** | **A** | Any | **TESTED: all 4 modes pass** |
| 32 | FileGator | Admin | 1.8k | Vue+PHP | B | **A** | A | NT | 3 | PHP API layer is clean |
| 33 | elFinder | Admin | 3.0k | Custom | **A** | C | B | F | 1 | Procedural file manager |
| 34 | MediaWiki | Wiki | 3.7k | Custom | **A** | D | C | F | 1 | Massive global state, `$wgUser` |
| 35 | DokuWiki | Wiki | 4.1k | Custom | F | **A** | A | A | 3 or 4 | CGI subprocess crash; in-process fine |
| 36 | BookStack | Wiki | 16k | Laravel | C | **A** | A | NT | 3 | Laravel-based, clean |
| 37 | Kanboard | Business | 8.4k | Custom | **A** | **A** | **A** | **A** | Any | All 4 modes pass — cleanest app tested |
| 38 | Invoice Ninja | Business | 8.3k | Laravel | C | **A** | A | NT | 3 | Laravel + React frontend |
| 39 | Leantime | Business | 4.1k | Custom | B | **A** | A | NT | 3 | PSR-based, modern OOP |
| 40 | Monica CRM | Business | 22k | Laravel | C | **A** | A | NT | 3 | Laravel, clean OOP |
| 41 | Crater | Business | 8.2k | Laravel | C | **A** | A | NT | 3 | Laravel |
| 42 | Matomo | Analytics | 19k | Custom | **A** | F | D | F | 1 | **Bundled php-di violates PSR `ContainerInterface` under PHP 8.4 LSP — fatals on vanilla PHP 8.4 too (app/vendor issue, not ZealPHP)** |
| 43 | Cacti | Analytics | 1.5k | Custom | **A** | D | C | F | 1 | Old procedural, `exit()` calls |
| 44 | LibreNMS | Analytics | 3.9k | Laravel | C | **A** | A | NT | 3 | Laravel-based |
| 45 | FreshRSS | Content | 10k | Custom | **A** | F | **B** | F | 1 or 4 | Function redeclaration on 2nd req; Mode 4 worker-stable (sequential) in 0.3.25 sweep |
| 46 | Piwigo | Content | 3.1k | Custom | **A** | C | **B** | F | 1 or 4 | Procedural, global variables; Mode 4 worker-stable (sequential) in 0.3.25 sweep |
| 47 | Lychee | Content | 13k | Laravel | C | **A** | A | NT | 3 | Laravel, clean |
| 48 | Wallabag | Content | 10k | Symfony | C | **A** | A | NT | 3 | Symfony-based |
| 49 | Nextcloud | Utility | 27k | Custom | **A** | D | C | F | 1 | **TESTED: Mode 1 PASS (200, 46ms), others crash** |
| 50 | YOURLS | Utility | 10k | Custom | **A** | C | **B** | F | 1 or 4 | `define()`-heavy, procedural; Mode 4 worker-stable (sequential) in 0.3.25 sweep |

---

## Coroutine-Mode Ranking (v0.3.8 + silent-define-redeclare, 2026-05-28)

> **The goal**: run every PHP app in Mode 5 (Coroutine, no superglobals). When Mode 5 doesn't fit, fall to Mode 4 (Hybrid: coroutine + superglobals). Mode 1 (Pool/Proc) is the **compatibility floor** — we pair it with FPM-equivalent semantics for apps that fundamentally need fresh-process state. The goal is to move every app UP this ranking.

### Tier S — Renders full UI in Mode 5 Coroutine (the real win)

These apps boot cleanly under `superglobals(false)`/coroutine mode with Stage 3+4 silent-redeclare + silent-define-redeclare. They serve real content (>500 bytes of app-specific HTML), not framework error stubs.

| App | M5 body size | M5 content |
|---|---:|---|
| **adminer** | 2995 B | "Login - Adminer" — full login form |
| **joomla** | 23853 B | "Joomla: Environment Setup Incomplete" — install wizard |
| **mediawiki** | 5855 B | "MediaWiki 1.47.0-alpha" — setup landing |
| **nextcloud** | 2644 B | Full Nextcloud HTML (error page about GD ext — Nextcloud's own UI) |
| **yourls** | 3325 B | "YOURLS — Your Own URL Shortener" — full landing |
| **lychee** | 1961 B | "ROOT" — auth gate (working) |
| **matomo** | 1155 B | Install wizard redirect |
| **traditional** | 717 B | ZealPHP demo |

Plus the 30x redirects that are working correctly: **freshrss** (301), **dokuwiki** (302 to install).

**= 10 apps run in Mode 5 today.** This is what changed from earlier reports — silent-define-redeclare unlocked the const-heavy apps.

### Tier A — Renders APP-LEVEL error in Mode 5 (framework served, app needs config)

The framework reached the app, the app responded with its own error UI. Not a framework bug:

| App | M5 body | What the app says |
|---|---:|---|
| **kanboard** | 98 B | `Internal Error: The directory "/app/user/data" must be writable` — chmod fix |
| **wordpress** | 99 B | `Warning: Undefined array key "HTTP_HOST"` — WP needs `$_SERVER`; **WORKS in M4 Hybrid** |
| **tinyfilemanager** | 419 B | Same HTTP_HOST issue — needs M4 Hybrid |
| **roundcube** | 115 B | "Configure HTTP server to point to /public_html" — entry-path issue |

### Tier B — Framework served, app boot fails internally (config / composer / dependencies)

`/src/App.php` debug.log captured the actual app-level error:

| App | Real error |
|---|---|
| **drupal** | `Failed opening required vendor/autoload_runtime.php` — `composer install` needed |
| **privatebin** | `Class "PrivateBin\Controller" not found` — autoloader path issue |
| **cacti** | `Call to undefined function check_status()` — Cacti's own dependency wiring |
| **yourls** | `Failed opening required '/app/conf/constants.php'` — wrong include path |
| **mybb, piwigo, vanilla, grav, filegator** | various boot-time class/file misses |
| **phpliteadmin** | PHP 8.x compatibility bug (`array_merge(null, ...)`) — broken on vanilla PHP 8.4, not a ZealPHP issue |

### Tier C — Crash with worker death in Mode 5 (true framework gaps)

| App | What happens |
|---|---|
| **dokuwiki** | Worker crash on second request (the docs-only `flush()` / `ob_end_flush()` pattern doesn't translate to coroutine state cleanly; FD-3 IPC needed) |
| **phpmyadmin** | Worker timeout (X) — works in M1 Pool only |

### Tier D — 404 in every mode (path config — NOT app failure)

These apps use Laravel/Symfony's `public/` entry pattern; the sweep probes `/<app>/` which doesn't exist. Probed correctly at `/<app>/public/` → real 500s (composer dep mismatches):

bookstack, flarum, monica, slim-app, drupal, filegator, phpbb, opencart, wallabag

### Mode comparison — same 32 apps

| Mode | Tier S (full render) | Tier A (app error rendered) | Tier B (config issue) | Tier C (worker crash) |
|---|---:|---:|---:|---:|
| **Mode 5 (Coroutine)** | **10** | 4 | 8 | 2 |
| Mode 4 (Hybrid superglobals+coro) | 11 (+ WP, tinyfile) | 2 | 7 | 2 |
| Mode 1 (Pool) | 13 + 5 redirect | 4 | 6 | 1 |

**Mode 5 today serves 10 apps end-to-end + 4 with app-level errors that are app fixes.** That's 14/32 (44%) of the matrix doing real work in pure coroutine mode. The remaining gap is dominated by per-app config (composer, paths, system extensions) — NOT framework limitations.

### The real coroutine-mode gap — diagnosed (2026-05-28)

The question "why does WordPress fail in coroutine mode if we capture all global state?" has a precise answer. **All five isolation layers are working**:

| Layer | Mechanism | Status |
|---|---|---|
| `$GLOBALS` user vars | Stage 2 COW (parent + per-coroutine delta) | ✅ Per-coroutine |
| Superglobals `$_GET/$_POST/$_SESSION` | OpenSwoole `on_yield` / `on_resume` snapshot | ✅ Per-coroutine |
| Runtime `function foo()` (in if/method scope) | Stage 3 opcode hook on `ZEND_DECLARE_FUNCTION` | ✅ |
| Top-level `function foo()` at file scope | Stage 4 `CG(function_table)` pointer swap in `zend_compile_file` | ✅ on cold compile |
| `define()` constants | silent-define-redeclare intercept on `define()` | ✅ |

**The one remaining gap — opcache hot path.**

`zend_compile_file` is NOT called when opcache has the file cached. Instead, opcache's `zend_accel_load_script` (in `ext/opcache.so`, a separate shared object) replays the cached op_array and calls `do_bind_function` / `do_bind_class` directly to install the top-level declarations into `EG(function_table)`. On the second request, the bind fails with "Cannot redeclare" — and opcache itself calls `zend_accel_error_noreturn` which `zend_bailout`s out of the request.

Stage 4's CG-table swap is on `zend_compile_file`. **Not invoked on opcache hits.** Stage 3's runtime opcode hook only catches conditional declarations, not top-level ones bound by opcache replay.

**Why WordPress homepage `/wordpress/` works:** `wp-blog-header.php` and `wp-load.php` are loaded ONCE on the worker's first request. Their top-level functions land in the process-wide function table. Subsequent requests reuse the same functions — no re-bind happens because opcache sees they're already there.

**Why `wp-login.php` fails:** it declares `login_header()`, `login_footer()`, `wp_login_form()`, `retrieve_password()` at file scope — files only loaded when someone visits the login URL. Worker request 1 → opcache caches the file with these function declarations. Worker request 2 → opcache replays the cached op_array → `do_bind_function('login_header', ...)` fails → bailout. Worker dies.

**Three fix paths (ranked):**

1. **M1 Pool for the specific endpoint** (current state). `App::registerCgiBackend()` lets you route `/wp-login.php`, `/wp-admin/install.php`, `/wp-admin/post.php` to a subprocess while serving `/` from M4 Hybrid. This is the FPM pair-up.
2. **Stage 5 (planned, v0.4.0)**: hook `do_bind_function` / `do_bind_class` at the engine level so silent-redeclare catches opcache replays. Requires LD_PRELOAD or a small PHP-engine patch — not a clean ext-zealphp addition.
3. **opcache configuration**: `opcache.blacklist_filename` with a list of WordPress files that have top-level declarations. Brittle (per-app maintenance) but works without engine changes.

The doc summary table above reflects this honestly — Tier B apps (drupal/privatebin/cacti/etc.) often have similar patterns. Stage 4 closes a big slice of the redeclare crashes; opcache hot path is the residual 15–20%.

### What we verified end-to-end on the 12-core perf VM (2026-05-28)

Setting up real DB + real WordPress + real auth via wp-cli on `labs@172.30.0.3` with ZealPHP Mode 4 Hybrid (`superglobals(true) + enableCoroutine(true)` + ext-zealphp v0.3.8 with Stage 3+4+silent-define-redeclare):

| Endpoint | First request | Subsequent requests | Note |
|---|---|---|---|
| `GET /wordpress/` (homepage) | **200, 68,684 B, 146 ms** | flickers — works on cold workers, 500 on warm | Mode 4 boots full WP including theme + DB queries |
| `GET /wordpress/wp-admin/` | 302 -> wp-login.php (logged out); **200 Dashboard logged in** (M1, this release) | M4: same | M1 now renders the full admin incl. block editor (issue #167) |
| `GET /wordpress/wp-login.php` | **200** (M1, this release) | M4: 500 | M1 global-scope include + recycle=1 fixed the redeclare; M4 still trips it |
| `GET /wordpress/<anything>.php` (simple file) | 200 | 200 | `_test.php` returning "hello-php" works fine — limitation is WP-specific, not generic `.php` URL handling |

**The honest finding for WordPress** (updated this release, issue #167): the table row above predates two fixes. **M1 Pool now serves wp-admin and the Gutenberg block editor end-to-end** — two changes landed it: (1) the CGI worker runs the request `include` at **true global scope**, so WordPress's top-level `$menu`/`$submenu` become real `$GLOBALS` and `wp-admin`'s `uksort($menu)` no longer fatals with `null given`; (2) `legacy-cgi` now defaults to `cgiPoolMaxRequests(1)`, so a reused subprocess never re-declares WordPress's unguarded top-level classes (`Cannot redeclare class …`). The **M4 Hybrid** (`coroutine-legacy`) admin flow is a separate story — it still trips the cold-boot `mysqlnd`/`libtasn1` teardown layer for pure-`require_once` WordPress (see the Mode-4 caveat at the top of this doc).

- **WordPress on M1 Pool**: serves public site + login + **wp-admin + block editor** + content management correctly, ~200 ms per req (the FPM-equivalent cost). **This is the recommended mode for unmodified WordPress.**
- **WordPress on M4 Hybrid**: serves the public-facing site, login auth, and comment writes sequentially; full **concurrent** wp-admin for pure-`require_once` WordPress remains M1's domain. (No Apache-throughput comparison is benchmarked — don't cite a multiplier.)

The correct production deployment pairs both:
- Public-facing requests → M4 Hybrid coroutine (high throughput)
- Admin routes (`/wp-admin/`, `/wp-login.php`) → M1 Pool subprocess (compatibility)

This is the FPM pair-up the doc references. v0.4.0's FastCGI backend register will make this routable per-URL via `App::registerCgiBackend()`.

### Where the gap is (paired with FPM)

When ZealPHP can't serve an app in Mode 5 today, it's almost always one of:

1. **App relies on `$_SERVER['HTTP_HOST']` etc. without abstraction** — Mode 4 Hybrid fixes this.
2. **App's vendor/ not installed** — `composer install --ignore-platform-reqs` fixes this.
3. **App uses native PHP extensions the container lacks** — `ext-gd`, `ext-zip` etc. need installation.
4. **App boot has redeclare patterns Stage 4 doesn't catch yet** — top-level functions in non-opcache flows.
5. **App's own bugs on PHP 8.4** — phpLiteAdmin's `array_merge(null, ...)`.

**For category 4 specifically**, the FPM pair-up makes sense: route those apps to a sidecar FPM via fastcgi backend (which ZealPHP supports — `App::cgiMode('fcgi')` / `App::registerCgiBackend('.py', ...)`). The framework doesn't have to RUN the legacy app in coroutine; it just has to PROXY to FPM for the niche cases. Today Mode 1 Pool does this in-process. v0.4.0 brings full FastCGI fallback for these.

---

## Real App-Render Status (Deep-Dive Pass, 2026-05-28)

The HTTP-200 sweep below tells you "the app didn't crash the worker" — but **200 OK doesn't mean the app is actually usable**. A deeper pass via Chrome DevTools + body-size + title inspection on Mode 1 Pool revealed four distinct states:

### Verified rendering — full UI loads (production-ready)

| App | Body size | Title | Notes |
|---|---:|---|---|
| **adminer** | 3 KB | Login - Adminer | Login screen renders; needs DB endpoint to actually log in |
| **phpmyadmin** | 18 KB | phpMyAdmin | Login screen renders; needs DB |
| **privatebin** | 23 KB | PrivateBin | Full paste-bin UI |
| **joomla** | 24 KB | Joomla: Environment Setup Incomplete | Full setup wizard renders; reports missing PHP/system deps |
| **traditional** | 717 B | Traditional PHP Test | ZealPHP demo page |
| **lychee** | 2 KB | ROOT | Photo gallery shell |
| **grav** | 16 KB | Grav Problems | Renders but reports config errors |

### Stub / redirect responses (HTTP 200/30x with tiny body)

| App | Body size | What's happening |
|---|---:|---|
| matomo | 627 B | Redirect to install path |
| freshrss | 307 B | "Redirection" page (working — install wizard at next URL) |
| roundcube | 115 B | Likely auth/install redirect |
| cacti | 185 B | Install redirect |
| tinyfilemanager | 53 B | Login prompt (auth required) |
| nextcloud | 190 B | Install redirect |
| vanilla | 92 B | Install redirect |
| dokuwiki | 0 B | 302 to install (correct) |
| wordpress | 0 B | 302 to wp-admin/install.php (correct) |
| mybb | 0 B | Install redirect |
| piwigo | 0 B | Install redirect |

### In-body errors (200 OK but the body says "broken")

| App | What the body says | Root cause |
|---|---|---|
| **kanboard** | `Internal Error: This PHP extension is required: "gd"` | **System dependency**, NOT framework — kanboard needs ext-gd installed in the container |
| **mediawiki** | Setup/error page | Needs DB config |
| **yourls** | `Fatal error` | Needs `user/config.php` setup |

### 404 in every mode (app-config issue, NOT framework)

bookstack, flarum, monica, slim-app use Laravel/Symfony's `public/` entry pattern → sweep tested `/<app>/` which doesn't have an `index.php`. Probed correctly at `/<app>/public/` they all return **HTTP 500** because their `vendor/` isn't installed. Composer install required:

| App | Composer status | After install |
|---|---|---|
| bookstack | `composer.lock` mismatch (needs `composer update`) | Untested |
| flarum | ✅ installed cleanly | Untested |
| monica | `composer.lock` mismatch | Untested |
| slim-app | ✅ installed cleanly | Untested |
| drupal | `composer.lock` mismatch | Untested |
| filegator | `composer.lock` mismatch | Untested |
| phpbb | No composer.json (uses git submodule pattern) | Untested |
| opencart | Path: `/opencart/upload/` returns **302 to install** | Working — entry path mistake in sweep |
| wallabag | Needs symfony console + `php bin/console` setup | Untested |
| elfinder | Pure JS — needs HTML integration page | N/A |

### To-revisit list (this session focused on framework correctness; per-app config left for follow-up)

These need real setup work — DB credentials wired into config files, `composer install/update`, system extensions installed, install-wizard walkthroughs:

- **Database wiring** for: wordpress, drupal, joomla, mediawiki, bookstack, monica, flarum, vanilla, mybb, piwigo, opencart, matomo, cacti, lychee, roundcube
- **System extension installs** for: kanboard (gd), possibly others (mbstring, intl, curl checks)
- **Composer update** for: bookstack, monica, drupal, filegator (lock-file mismatches)
- **App-side config** for: yourls, mediawiki, grav

**Dummy DB infrastructure already provisioned** in the lab (sweep-mysql at 172.20.0.2, user `testuser`/`testpass`, all per-app databases created). Wiring each app's config file is the remaining task.

---

## Latest Sweep (v0.3.8 — commit `9b8111b`, 2026-05-28)

Full 32-app × 5-mode sweep after the FD-3 IPC fix. Each cell is **3 sequential GET probes** to `/<app>/`: a single code (e.g. `200`) = identical on all 3 probes; slashed (e.g. `302/500/500`) = differing per-probe responses (flicker / first-time install pages).

> The Stage 2 COW `$GLOBALS` isolation is enabled by default in v0.3.7+. M3/M4/M5 flicker is now overwhelmingly from `Cannot redeclare function/class …` errors — Stage 3 silent-redeclare (see [state-isolation-reference.md §3](./architecture/state-isolation-reference.md#3-the-4-stages-of-globals-coroutine-isolation)) is the next mitigation.

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
| phpmyadmin | **200** † | X | 200/500/500 | 500 | X |
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

`†` = NEW pass in v0.3.8 (was 504 timeout pre-`9b8111b`). The configured `zealphp-wordpress` container (separate from the sweep matrix above) ALSO restored from 0-byte body to full 68 KB body on `/` after the same fix.

### Pass-rate summary

| Mode | 3/3 200 OK | + Stable 30x redirects | Notes |
|---|:---:|:---:|---|
| **Mode 1 Pool** | **13/32 (41%)** | **18/32 (56%)** | The headline mode. phpMyAdmin, Cacti, Nextcloud, Privatebin, phpLiteAdmin all green here only. |
| Mode 1 Proc | 8/32 (25%) | 13/32 (41%) | Pool wins decisively. `proc_open` per request hits joomla/phpmyadmin/tinyfilemanager with timeouts under serial load. |
| Mode 3 Sync+FI | 4/32 stable | ~7/32 plausible | Many apps flicker — top-level redeclarations on warm workers. |
| Mode 4 Hybrid | 4/32 stable | ~8/32 plausible | Stage 2 COW closes `$GLOBALS`; redeclare crashes still dominate. |
| Mode 5 Coroutine | 3/32 stable | ~7/32 plausible | Pure coroutine; same redeclare ceiling as Mode 4. |

**Green in ALL 5 modes (production-portable):** adminer, kanboard, roundcube, traditional, freshrss

**Require Mode 1 (CGI Pool):** phpMyAdmin, Cacti, Nextcloud, Privatebin, phpLiteAdmin, MyBB, Piwigo, Vanilla, MediaWiki, Drupal, Grav, WordPress

**Config-only failures (404 in every mode — wrong entry path, NOT a framework bug):** bookstack, elfinder, flarum, monica, opencart, phpbb, slim-app, wallabag

---

## Actual Test Results (Docker Lab, 2026-05-28)

These apps were deployed and boot-tested on PHP 8.4 + OpenSwoole 26.2 + ext-zealphp 0.3.3.

### Kanboard (Project Management)

- **GitHub:** https://github.com/kanboard/kanboard — 8.4k stars
- **Mode 5:** PASS (302, 234 ms) | **Mode 1:** PASS (302, 51 ms) | **Mode 4:** PASS (302, 66 ms) | **Mode 3:** PASS (302, 45 ms)
- **ALL 4 MODES PASS** — clean micro-framework architecture, proper autoloading, guarded constants
- **Grade: A+ (all modes)**
- Why it works: no unguarded `define()`, no naked function declarations in included files, no process-level singleton state that leaks between requests, proper use of `function_exists()` throughout

### DokuWiki (Flat-file Wiki)

- **GitHub:** https://github.com/dokuwiki/dokuwiki — 4.1k stars
- **Mode 5:** PASS (200, 27 ms) | **Mode 1:** FAIL_500 (subprocess crash) | **Mode 4:** PASS (200, 16 ms) | **Mode 3:** PASS (200, 17 ms)
- 3 of 4 modes pass. CGI subprocess crash — DokuWiki's procedural bootstrap triggers a pool worker death
- **Grade: A (in-process modes), C (Mode 1)**
- Recommended: Mode 3 or Mode 4

### Adminer (DB Admin)

- **GitHub:** https://github.com/vrana/adminer — 6.1k stars
- **Mode 5:** PARTIAL (crash on 2nd request) | **Mode 1:** PASS (200, 147 ms) | **Mode 4:** PASS (200, 27 ms) | **Mode 3:** PARTIAL (crash on 2nd request)
- **Failure:** `Cannot redeclare function Adminer\connection()` — namespaced functions defined at top of `index.php` without `function_exists()` guard
- **Grade: A (Mode 1/4), F (Mode 3/5 — crashes worker on 2nd request)**
- Recommended: Mode 1 (simplest) or Mode 4 (better performance, requires ext-zealphp)

### TinyFileManager (File Manager)

- **GitHub:** https://github.com/prasathmani/tinyfilemanager — 6.2k stars
- **Mode 5:** PARTIAL (crash on 2nd request) | **Mode 1:** PASS (200, 42 ms) | **Mode 4:** PASS (worker-stable, sequential, ext-zealphp 0.3.25) | **Mode 3:** PARTIAL (crash on 2nd request)
- Same function redeclaration issue as Adminer; fixed for sequential Mode 4 by the per-request state reset (ext-zealphp 0.3.25). True concurrent Mode 4 remains Mode 1's domain.
- **Grade: A (Mode 1); B (Mode 4 sequential)**
- Recommended: Mode 1 (simplest) or Mode 4 sequential

### FreshRSS (RSS Reader)

- **GitHub:** https://github.com/FreshRSS/FreshRSS — 10k stars
- **Mode 5:** PARTIAL (crash on 2nd) | **Mode 1:** PASS (302, 42 ms) | **Mode 4:** PASS (worker-stable, sequential, ext-zealphp 0.3.25) | **Mode 3:** PARTIAL
- Same redeclaration pattern — fixed for sequential Mode 4 by the per-request state reset (ext-zealphp 0.3.25). True concurrent Mode 4 remains Mode 1's domain.
- **Grade: A (Mode 1); B (Mode 4 sequential)**
- Recommended: Mode 1 (simplest) or Mode 4 sequential (better throughput at low concurrency)

### phpLiteAdmin (SQLite Admin)

- **GitHub:** https://github.com/sighook/phpLiteAdmin
- **ALL MODES: FAIL_500**
- PHP 8.x compatibility bug: `array_merge(null, ...)` at line 94. Not a ZealPHP issue — broken on vanilla PHP 8.4.
- **Grade: N/A (broken on PHP 8.4)**

### phpMyAdmin (DB Admin)

- **GitHub:** https://github.com/phpmyadmin/phpmyadmin — 7.2k stars
- **Mode 5:** 200 | **Mode 1:** 500 (CGI crash) | **Mode 4:** 200 | **Mode 3:** 200
- Vendor deps installed. Works in all in-process modes. CGI pool crashes.
- **Grade: A (Mode 3/4/5), C (Mode 1)**
- Recommended: Mode 3

### Roundcube (Webmail)

- **GitHub:** https://github.com/roundcube/roundcubemail — 6.0k stars
- **Mode 5:** PASS (200, 19 ms) | **Mode 1:** PASS (200, 42 ms) | **Mode 4:** PASS (200, 19 ms) | **Mode 3:** PASS (200, 22 ms)
- **ALL 4 MODES PASS** — clean architecture with proper autoloading and no unguarded redeclarations
- **Grade: A+ (all modes)**
- Why it works: Roundcube uses Composer autoloading, no naked function declarations in request scope, singleton accessed but not leaked

### Matomo (Analytics)

- **GitHub:** https://github.com/matomo-org/matomo — 19k stars
- **Mode 5:** PARTIAL (crash on 2nd) | **Mode 1:** PASS (200, 51 ms) | **Mode 4:** PARTIAL (crash on 2nd) | **Mode 3:** PARTIAL (crash on 2nd)
- First request renders the install page. Matomo's bundled php-di violates PSR `ContainerInterface` under PHP 8.4's stricter LSP enforcement — it fatals on vanilla PHP 8.4 too, so this is an app/vendor incompatibility, NOT a ZealPHP issue (same category as phpLiteAdmin). Mode 1's per-request subprocess masks it; the in-process modes surface it on the 2nd request.
- **Grade: A (Mode 1 only)**
- Recommended: Mode 1

### Grav (Flat-file CMS)

- **GitHub:** https://github.com/getgrav/grav — 14k stars
- **Mode 5:** FAIL | **Mode 1:** PASS (200, after first-request init) | **Mode 4:** FAIL | **Mode 3:** FAIL
- Constants `GRAV_REQUEST_TIME`, `GRAV_PHP_MIN` defined without `defined()` guards. First CGI request returns 500 (init), second returns 200.
- **Grade: B (Mode 1 — needs one warm-up request)**
- Recommended: Mode 1

### OpenCart (E-commerce)

- **GitHub:** https://github.com/opencart/opencart — 7.3k stars
- **Mode 5:** PASS (302, 19 ms) | **Mode 1:** PASS (302, 46 ms) | **Mode 4:** PASS (302, 20 ms) | **Mode 3:** PASS (302, 22 ms)
- **ALL 4 MODES PASS** — install redirect works in every mode. Clean MVC with proper autoloading.
- **Grade: A+ (all modes)**

### Joomla (CMS)

- **GitHub:** https://github.com/joomla/joomla-cms — 4.7k stars
- **Mode 5:** PASS (200, 20 ms) | **Mode 1:** PASS (200, 40 ms) | **Mode 4:** PASS (200, 18 ms) | **Mode 3:** PASS (200, 14 ms)
- **ALL 4 MODES PASS** — full 23KB install wizard renders in every mode. Joomla 5's modern Symfony-based architecture is ZealPHP-clean.
- **Grade: A+ (all modes)**

### Nextcloud (Cloud Storage)

- **GitHub:** https://github.com/nextcloud/server — 27k stars
- **Mode 5:** FAIL | **Mode 1:** PASS (200, 46 ms) | **Mode 4:** FAIL | **Mode 3:** FAIL
- Mode 1 (CGI) loads the Nextcloud setup page successfully. Other modes crash due to `OC_*` constant redefinition and massive static state.
- **Grade: A (Mode 1 only)**
- Recommended: Mode 1

### Slim Framework (Micro-framework)

- **GitHub:** https://github.com/slimphp/Slim — 12k stars
- Framework routing works correctly — returns proper 405 JSON response for unregistered route methods
- Needs `setFallback()` front-controller routing in ZealPHP `app.php` (same as Laravel/Symfony)
- **Grade: A (Mode 3), confirmed working**
- Recommended: Mode 3

---

## Category Breakdown

### 1. CMS (10 apps)

#### WordPress

- **GitHub:** https://github.com/WordPress/WordPress — 19k stars
- **PHP:** 7.4+ | **Framework:** Custom (procedural + hooks)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** Thousands of `define()` calls without `defined()` guards, plugin ecosystem uses `die()`/`exit()` freely, static globals in core (`$wp`, `$wpdb`), function redeclaration in plugins
- **Recommended:** Mode 1 (CGI Pool) — this is the only mode that gives plugins their own clean process state for full concurrent production use
- The ZealPHP WordPress showcase (`sibidharan/zealphp-wordpress`) demonstrates this working end-to-end
- **Mode 4 sequential note (ext-zealphp 0.3.25):** WordPress serves its public site + login auth + comment writes end-to-end in coroutine-legacy mode run *sequentially* (worker-stable, ASAN-clean). True concurrent WP (multiple simultaneous coroutines) remains Mode 1's domain due to the cold-boot `mysqlnd`/`libtasn1` connection-teardown layer and the cold-concurrent-autoload race on unwarmed inherited classes. See the Mode-4 caveat at the top of this document.

#### Drupal

- **GitHub:** https://github.com/drupal/drupal — 4.3k stars
- **PHP:** 8.3+ | **Framework:** Custom (Symfony components)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `drupal_bootstrap()` builds a static service container, `\Drupal::state()` singleton, hooks fired via global function registry. The static service container / `Database` registry (class static properties) threw `ConnectionNotDefinedException` on request 2 — fixed by the class-static reset in ext-zealphp 0.3.25 (A/B verified: ON = `200`×8, OFF = `200` then `500`×7). Mode 4 is now worker-stable sequentially.
- **Recommended:** Mode 1 for production concurrency; Mode 4 sequential viable with ext-zealphp 0.3.25+

#### Joomla

- **GitHub:** https://github.com/joomla/joomla-cms — 4.7k stars
- **PHP:** 8.1+ | **Framework:** Custom (Joomla Framework)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `JFactory::getApplication()` singleton pattern, global `$mainframe`, procedural bootstrap
- **Recommended:** Mode 1

#### TYPO3

- **GitHub:** https://github.com/TYPO3/typo3 — 1.0k stars (monorepo)
- **PHP:** 8.2+ | **Framework:** Symfony components, PSR-compliant
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Symfony DI container (works in Mode 3), `TYPO3_REQUESTTYPE` constant needs `defined()` guard (already guarded in core), extensions may have issues
- **Recommended:** Mode 3 (Sync)

#### Concrete CMS

- **GitHub:** https://github.com/concretecms/concretecms — 768 stars
- **PHP:** 8.0+ | **Framework:** Custom (Zend-derived)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `Core::make()` singleton IoC, global `$c` page object, legacy `define()` constants
- **Recommended:** Mode 1

#### October CMS

- **GitHub:** https://github.com/octobercms/october — 11k stars
- **PHP:** 8.0+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel application container; `App::make()` singleton. Laravel's service providers run once at boot — fine for Mode 3's sequential model
- **Recommended:** Mode 3

#### Craft CMS

- **GitHub:** https://github.com/craftcms/cms — 3.1k stars
- **PHP:** 8.0+ | **Framework:** Yii 2
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Yii2 `Yii::$app` singleton, config via `craft\helpers\App`; Yii bootstraps once per process (ideal for Mode 3)
- **Recommended:** Mode 3

#### Grav

- **GitHub:** https://github.com/getgrav/grav — 14k stars
- **PHP:** 8.1+ | **Framework:** Custom (PSR-7/PSR-15)
- **Mode 1:** B | **Mode 3:** F | **Mode 4:** B | **Mode 5:** NT
- **Key issues:** Constants `GRAV_REQUEST_TIME` / `GRAV_PHP_MIN` defined without `defined()` guards crash Mode 3 on 2nd request. Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) is worker-stable sequentially via the per-request state reset.
- **Recommended:** Mode 1 or Mode 4 sequential. True concurrent Mode 4 remains Mode 1's domain.

#### Kirby

- **GitHub:** https://github.com/getkirby/kirby — 7.5k stars
- **PHP:** 8.1+ | **Framework:** Custom (modern OOP)
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Clean OOP, file-based CMS. Kirby's core avoids global state. Plugins vary.
- **Recommended:** Mode 3

#### Statamic

- **GitHub:** https://github.com/statamic/cms — 3.9k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel-based; same considerations as October CMS
- **Recommended:** Mode 3

---

### 2. E-commerce (6 apps)

#### Bagisto

- **GitHub:** https://github.com/bagisto/bagisto — 15k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel + Vue.js frontend, clean service architecture
- **Recommended:** Mode 3

#### Magento 2

- **GitHub:** https://github.com/magento/magento2 — 11k stars
- **PHP:** 8.2+ | **Framework:** Custom (Zend/Laminas-derived)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** C | **Mode 5:** F
- **Key issues:** Enormous static DI container built at bootstrap. `\Magento\Framework\App\ObjectManager::getInstance()` is process-global. Area codes (`frontend`/`adminhtml`) are set once via static state. Session handling is deeply intertwined with `$_SESSION`. Worker restart between requests is effectively required.
- **Recommended:** Mode 1 — no other mode is viable without significant patching

#### WooCommerce

- **GitHub:** https://github.com/woocommerce/woocommerce — 9.6k stars
- **PHP:** 7.4+ | **Framework:** WordPress plugin
- **Mode 1:** A | **Mode 3:** F | **Mode 4:** C | **Mode 5:** F
- **Key issues:** Runs as a WordPress plugin — all WordPress constraints apply. `WC()` global, `wc_get_cart()` static singletons.
- **Recommended:** Mode 1 (via WordPress CGI Pool)

#### PrestaShop

- **GitHub:** https://github.com/PrestaShop/PrestaShop — 7.8k stars
- **PHP:** 8.1+ | **Framework:** Custom (Symfony components)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `Context::getContext()` singleton with cart/customer/shop objects, `define()` for `_PS_ROOT_DIR_`, modules use `exit()`
- **Recommended:** Mode 1

#### OpenCart

- **GitHub:** https://github.com/opencart/opencart — 7.3k stars
- **PHP:** 8.0+ | **Framework:** Custom (MVC, procedural-style)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** Stores state in `$registry` object passed around, but that object lives in a global scope. Extensions use `exit()`. `define('DIR_APPLICATION', ...)` without guards.
- **Recommended:** Mode 1

#### Sylius

- **GitHub:** https://github.com/Sylius/Sylius — 7.7k stars
- **PHP:** 8.1+ | **Framework:** Symfony
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Clean Symfony-based architecture. PSR-compliant. Service container bootstrapped once per process.
- **Recommended:** Mode 3

---

### 3. Forums (4 apps)

#### Flarum

- **GitHub:** https://github.com/flarum/framework — 15k stars
- **PHP:** 8.1+ | **Framework:** Laravel + Mithril.js frontend
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Modern PSR-7 HTTP pipeline, Laravel Eloquent ORM. Clean separation of concerns. Extensions follow hook system.
- **Recommended:** Mode 3

#### phpBB

- **GitHub:** https://github.com/phpbb/phpbb — 1.8k stars
- **PHP:** 7.1+ | **Framework:** Custom (procedural + legacy OOP)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** B | **Mode 5:** F
- **Key issues:** Extensive `$phpbb_root_path`, `$phpEx` globals. Top-level includes with `define()`. `exit_handler()` calls `die()`. Login flows use `redirect() + exit`. Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) is worker-stable sequentially via the per-request state reset.
- **Recommended:** Mode 1 or Mode 4 sequential

#### MyBB

- **GitHub:** https://github.com/mybb/mybb — 2.9k stars
- **PHP:** 7.3+ | **Framework:** Custom (procedural)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** B | **Mode 5:** F
- **Key issues:** Global `$mybb`, `$db`, `$lang` objects, `define()`-based constants throughout, procedural plugin system with `run_hooks()` calling arbitrary functions. Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) is worker-stable sequentially via the per-request state reset.
- **Recommended:** Mode 1 or Mode 4 sequential

#### Vanilla Forums

- **GitHub:** https://github.com/vanilla/vanilla — 2.9k stars
- **PHP:** 7.4+ | **Framework:** Custom (Garden framework)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `Gdn::application()` singleton, `saveToConfig()` writes to filesystem per-request, `redirect()` calls `exit()`
- **Recommended:** Mode 1

---

### 4. Frameworks (7 apps)

#### Laravel

- **GitHub:** https://github.com/laravel/laravel — 79k stars (skeleton)
- **PHP:** 8.2+ | **Framework:** Self
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** `app()` helper is a static facade over the IoC container, service providers run once and accumulate state, `DB::connection()` holds open database connections. Mode 3's sequential model is a good fit — one request at a time, service providers stay fresh. Laravel Octane (Swoole mode) is the "right" way for coroutine concurrency but requires app changes.
- **Recommended:** Mode 3

#### Symfony

- **GitHub:** https://github.com/symfony/symfony — 30k stars
- **PHP:** 8.2+ | **Framework:** Self
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Kernel must be rebooted between requests in long-running mode, or use `kernel.terminate` event properly. `App::superglobals(true) + processIsolation(false)` (Mixed-mode) is the recommended ZealPHP-Symfony configuration per the `zealphp-symfony` bridge.
- **Recommended:** Mode 3 (use the `sibidharan/zealphp-symfony` bridge)

#### CodeIgniter 4

- **GitHub:** https://github.com/codeigniter4/CodeIgniter4 — 5.3k stars
- **PHP:** 7.4+ | **Framework:** Self
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** CI4's `Services::` is a static registry but is designed to be reset per-request via `Services::reset()`. Call it in a ZealPHP `onRequest` middleware.
- **Recommended:** Mode 3 with `Services::reset()` in request lifecycle

#### CakePHP

- **GitHub:** https://github.com/cakephp/cakephp — 8.7k stars
- **PHP:** 8.1+ | **Framework:** Self
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Registry pattern in `TableLocator`, but instances are request-scoped by design in newer versions. Session handling is well-abstracted.
- **Recommended:** Mode 3

#### Slim

- **GitHub:** https://github.com/slimphp/Slim — 12k stars
- **PHP:** 7.4+ | **Framework:** Self (PSR-7/PSR-15)
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** B
- **Key issues:** Fully PSR-7 compliant, no global state in the framework itself. App-level code may use superglobals — audit your middleware.
- **Recommended:** Mode 3 for existing apps, Mode 5 if rewriting with `$g->`

#### Yii 2

- **GitHub:** https://github.com/yiisoft/yii2 — 14k stars
- **PHP:** 7.2+ | **Framework:** Self
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** `Yii::$app` is a global singleton but is designed to be re-created. `yii\base\Application::__construct()` resets most state. Works well in Mode 3 where one app instance handles sequential requests.
- **Recommended:** Mode 3

#### Laminas (Zend)

- **GitHub:** https://github.com/laminas — 5.1k stars (org)
- **PHP:** 8.0+ | **Framework:** Self (PSR-7/PSR-15)
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Fully PSR-7/PSR-15 compliant. Laminas MVC application bootstraps cleanly. Good candidate for Mode 3.
- **Recommended:** Mode 3

---

### 5. Admin/Tools (6 apps)

#### phpMyAdmin

- **GitHub:** https://github.com/phpmyadmin/phpmyadmin — 7.2k stars
- **PHP:** 7.2+ | **Framework:** Custom (Twig + PSR-7 partially)
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** A
- **Tested:** Mode 3/4/5 PASS, Mode 1 FAIL (CGI pool crash). Requires `composer install`.
- **Key issues:** CGI pool crashes on startup — startup sequence not subprocess-safe. In-process modes all work.
- **Recommended:** Mode 3

#### Adminer

- **GitHub:** https://github.com/vrana/adminer — 6.1k stars
- **PHP:** 5.2+ | **Framework:** None (single file)
- **Mode 1:** A | **Mode 3:** F | **Mode 4:** A | **Mode 5:** F
- **Tested:** Mode 1 PASS (200, 147 ms), Mode 4 PASS (200, 27 ms), Mode 3/5 CRASH on 2nd request
- **Root cause:** `function Adminer\connection()` declared at top of `index.php` without `function_exists()`. Worker loads the file per request — fatal on reload.
- **Recommended:** Mode 1 (simplest) or Mode 4 (best performance, requires ext-zealphp)

#### TinyFileManager

- **GitHub:** https://github.com/prasathmani/tinyfilemanager — 6.2k stars
- **PHP:** 7.2+ | **Framework:** None (single file, ~7k LOC)
- **Mode 1:** A | **Mode 3:** F | **Mode 4:** F | **Mode 5:** F
- **Tested:** Mode 1 PASS (200, 42 ms), all others CRASH on 2nd request
- **Root cause:** All functions (`fm_get_mime_type`, `fm_is_dir`, etc.) declared inline without `function_exists()`. ~200 function declarations, no namespace.
- **Recommended:** Mode 1 only

#### Roundcube

- **GitHub:** https://github.com/roundcube/roundcubemail — 6.0k stars
- **PHP:** 8.0+ | **Framework:** Custom
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `$RCMAIL` global singleton, plugin system uses global hook registry (`rcube::get_instance()`), `rcube_output` accumulates response state
- **Recommended:** Mode 1

#### FileGator

- **GitHub:** https://github.com/filegator/filegator — 1.8k stars
- **PHP:** 7.1+ | **Framework:** Vue.js + PHP API backend
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** PHP backend is a clean API layer with dependency injection. Frontend is a SPA. Good architecture.
- **Recommended:** Mode 3

#### elFinder

- **GitHub:** https://github.com/Studio-42/elFinder — 3.0k stars
- **PHP:** 5.4+ | **Framework:** Custom (procedural + OOP mix)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `elFinder::$netDrivers` static state, connector script runs in a procedural style, `die()` in error paths
- **Recommended:** Mode 1

---

### 6. Wiki/Docs (3 apps)

#### MediaWiki

- **GitHub:** https://github.com/wikimedia/mediawiki — 3.7k stars (mirror)
- **PHP:** 7.4+ | **Framework:** Custom
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** C | **Mode 5:** F
- **Key issues:** Extensive `$wgUser`, `$wgTitle`, `$wgOut` process globals. `wfRunHooks()` registry is process-global. Extensions add hundreds of global variables. `wfAbortAllDB()` calls `die()`.
- **Recommended:** Mode 1

#### DokuWiki

- **GitHub:** https://github.com/dokuwiki/dokuwiki — 4.1k stars
- **PHP:** 7.4+ | **Framework:** Custom (procedural)
- **Mode 1:** F (TESTED: CGI subprocess crash) | **Mode 3:** A (TESTED: 200, 17 ms) | **Mode 4:** A (TESTED: 200, 16 ms) | **Mode 5:** A (TESTED: 200, 27 ms)
- **Key issues:** Works perfectly in-process. CGI pool subprocess crashes on DokuWiki's procedural startup sequence. Avoid Mode 1.
- **Recommended:** Mode 3 (simplest), Mode 4 (concurrency), or Mode 5 (if wrapping the entrypoint)

#### BookStack

- **GitHub:** https://github.com/BookStackApp/BookStack — 16k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel-based, clean OOP. Same considerations as other Laravel apps.
- **Recommended:** Mode 3

---

### 7. Business (5 apps)

#### Kanboard

- **GitHub:** https://github.com/kanboard/kanboard — 8.4k stars
- **PHP:** 8.0+ | **Framework:** Custom (micro-framework, clean OOP)
- **Mode 1:** A | **Mode 3:** A | **Mode 4:** A | **Mode 5:** A
- **Tested:** ALL 4 MODES PASS — fastest response in Mode 3 (45 ms), all modes stable across multiple requests
- **Why it's the gold standard:** Proper autoloading, `defined()` guards on all constants, no naked function declarations, no `die()` in normal paths, clean request/response cycle
- **Recommended:** Any mode. Mode 5 for maximum performance if wrapping the entrypoint.

#### Invoice Ninja

- **GitHub:** https://github.com/invoiceninja/invoiceninja — 8.3k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel + React frontend. `Ninja::` facades, queue workers for background jobs. Mode 3 handles the web frontend well.
- **Recommended:** Mode 3

#### Leantime

- **GitHub:** https://github.com/Leantime/leantime — 4.1k stars
- **PHP:** 8.1+ | **Framework:** Custom (PSR-based, modern OOP)
- **Mode 1:** B | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** PSR-11 container, clean service layer. `bootstrap.php` uses proper class loading.
- **Recommended:** Mode 3

#### Monica CRM

- **GitHub:** https://github.com/monicahq/monica — 22k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel, clean OOP. Background jobs via queues. Web frontend works cleanly in Mode 3.
- **Recommended:** Mode 3

#### Crater

- **GitHub:** https://github.com/crater-invoice/crater — 8.2k stars
- **PHP:** 8.0+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel + Vue.js frontend. Standard Laravel patterns.
- **Recommended:** Mode 3

---

### 8. Analytics (3 apps)

#### Matomo

- **GitHub:** https://github.com/matomo-org/matomo — 19k stars
- **PHP:** 7.2.5+ | **Framework:** Custom (Piwik framework)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** C | **Mode 5:** F
- **Key issues:** `Piwik::$plugins` static registry, `StaticContainer::get()` DI, tracker script uses `die()` after response. The `piwik.php` tracker is a self-contained script that calls `die()` after emitting the GIF — Mode 1 handles this cleanly via process isolation.
- **Recommended:** Mode 1

#### Cacti

- **GitHub:** https://github.com/Cacti/cacti — 1.5k stars
- **PHP:** 7.2+ | **Framework:** Custom (procedural)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** C | **Mode 5:** F
- **Key issues:** Heavily procedural, `exit()` calls throughout SNMP polling paths, global `$config` array, `define()` for every constant
- **Recommended:** Mode 1

#### LibreNMS

- **GitHub:** https://github.com/librenms/librenms — 3.9k stars
- **PHP:** 8.1+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Laravel-based web interface. Background polling daemons are separate processes. Web UI is clean Laravel.
- **Recommended:** Mode 3

---

### 9. Content/Media (4 apps)

#### FreshRSS

- **GitHub:** https://github.com/FreshRSS/FreshRSS — 10k stars
- **PHP:** 7.0.7+ | **Framework:** Custom (Minz micro-framework)
- **Mode 1:** A | **Mode 3:** F | **Mode 4:** B | **Mode 5:** F
- **Tested:** Mode 1 PASS (302, 42 ms); Mode 3/5 CRASH on 2nd request; Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) worker-stable sequentially.
- **Root cause:** Minz framework declares `function _t()`, `_s()`, `_n()` and others without `function_exists()` guards. These are PHP-level (not namespaced) function declarations that collide on worker reload. The per-request state reset (ext-zealphp 0.3.25) resolves this for sequential Mode 4.
- **Recommended:** Mode 1 (simplest) or Mode 4 sequential

#### Piwigo

- **GitHub:** https://github.com/Piwigo/Piwigo — 3.1k stars
- **PHP:** 7.1+ | **Framework:** Custom (procedural)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** Heavy use of global `$conf`, `$page`, `$user` arrays. Plugin system calls `die()`. `include()`-based dispatch. Mode 4 (coroutine-legacy, ext-zealphp 0.3.25) is worker-stable sequentially via the per-request state reset.
- **Recommended:** Mode 1 or Mode 4 sequential

#### Lychee

- **GitHub:** https://github.com/LycheeOrg/Lychee — 13k stars
- **PHP:** 8.2+ | **Framework:** Laravel
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Modern Laravel app, clean OOP, Vue.js frontend.
- **Recommended:** Mode 3

#### Wallabag

- **GitHub:** https://github.com/wallabag/wallabag — 10k stars
- **PHP:** 8.1+ | **Framework:** Symfony
- **Mode 1:** C | **Mode 3:** A | **Mode 4:** A | **Mode 5:** NT
- **Key issues:** Symfony-based. Clean PSR-7 architecture. Same Symfony boot considerations apply.
- **Recommended:** Mode 3

---

### 10. Utility (2 apps)

#### Nextcloud

- **GitHub:** https://github.com/nextcloud/server — 27k stars
- **PHP:** 8.0+ | **Framework:** Custom (OC_ namespace, partially Symfony-influenced)
- **Mode 1:** A | **Mode 3:** D | **Mode 4:** C | **Mode 5:** F
- **Key issues:** `OC_Hook::emit()` global hook registry, `OCP\Share\IManager::getSharesBy()` static facades, DAV stack uses `exit()` in sync-client paths, `\OC\SystemConfig` is a process singleton. App framework is extensive but pre-dates modern DI patterns.
- **Recommended:** Mode 1

#### YOURLS

- **GitHub:** https://github.com/YOURLS/YOURLS — 10k stars
- **PHP:** 7.4+ | **Framework:** Custom (procedural hooks)
- **Mode 1:** A | **Mode 3:** C | **Mode 4:** B | **Mode 5:** F
- **Key issues:** `define('YOURLS_ABSPATH', ...)` without `defined()` guard, global `$yourls_filters` hook registry, `yourls_die()` wrapper calls `die()`. Plugin API is function-based.
- **Recommended:** Mode 1

---

## Compatibility Patterns

These are the root causes behind most compatibility failures. Understanding them lets you predict whether an unlisted app will work.

### 1. Function Redeclaration

**What it is:** An app declares PHP functions in a file that gets included on every request, without a `function_exists()` guard.

```php
// BAD — crashes on 2nd request in Mode 3/5
function format_date($date) { ... }

// GOOD — safe in all modes
if (!function_exists('format_date')) {
    function format_date($date) { ... }
}
```

**Affected apps (confirmed):** Adminer, TinyFileManager, FreshRSS  
**Impact:** Fatal error `Cannot redeclare function X()` on 2nd request. Kills the worker process.  
**Modes affected:** 3 and 5 (in-process, worker is reused). Mode 4 handles it via worker rotation + per-coroutine function table (ext-zealphp). Mode 1 is immune (fresh process per request).  
**Fix:** Mode 1 or Mode 4. Or wrap the entry point in `runkit_function_remove()` / function table isolation — expensive.

### 2. Constant Redefinition

**What it is:** An app calls `define('CONSTANT', value)` without a `defined('CONSTANT')` guard.

```php
// BAD
define('APP_ROOT', __DIR__);

// GOOD
defined('APP_ROOT') || define('APP_ROOT', __DIR__);
```

**Affected apps:** WordPress, Joomla, OpenCart, YOURLS, phpBB, MyBB  
**Impact:** PHP notice (soft failure) in PHP 7, but becomes a warning in PHP 8. Some apps treat this as fatal. More importantly, the second `define()` silently fails, leaving the constant at its first value — which may be wrong if the include path changed.  
**Modes affected:** 3 and 5. Mode 1 immune. Mode 4 can isolate via `defineIsolation(true)` in ext-zealphp.

### 3. Static Singleton State

**What it is:** Frameworks that use `Singleton::getInstance()` patterns where the instance accumulates request-specific state.

```php
// Common pattern — safe only if $instance is reset per-request
class App {
    private static $instance = null;
    public static function getInstance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }
}
```

**Affected apps:** Magento 2, Joomla, phpBB, Cacti, Nextcloud, Matomo  
**Impact:** State from request N leaks into request N+1. Login sessions may bleed, cache may show stale data, database connections may be in wrong state.  
**Modes affected:** 3 and 5. Mode 1 resets per process. Mode 4's ext-zealphp snapshots static properties per-coroutine.  
**Detection:** Grep for `static \$instance`, `static \$_instance`, `private static \$app`.

### 4. exit()/die() Usage

**What it is:** Application code calls `exit()` or `die()` to terminate the request — a common pattern in legacy PHP.

```php
// Common patterns
header('Location: /login'); die;
die(json_encode(['error' => 'unauthorized']));
```

**Affected apps:** OpenCart, phpBB, Matomo tracker, Cacti, Vanilla Forums, YOURLS  
**Impact:** In Mode 3 and 5, `exit()` kills the worker process — OpenSwoole cannot trap it at PHP level. The worker is respawned by the manager, but response is lost and the next request gets a fresh worker.  
**Modes affected:** All in-process modes (3, 4, 5) if unhandled. Mode 1 is immune (subprocess dies, pool spawns replacement). Mode 4 (coroutine-legacy) handles `exit()`/`die()` worker-survival via the coroutine-legacy isolation stack (ext-zealphp).  
**Note:** There is no `App::hookExit()`. The exec-family hook toggle is `App::hookExec()` (backtick/shell_exec/exec/system/passthru). `exit()`/`die()` worker-survival is part of the coroutine-legacy isolation stack, not a separate toggle.

### 5. Superglobal Access

**What it is:** Code reads `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER` directly instead of through a framework abstraction.

```php
// Mode 5 incompatible
$user = $_POST['username'];
session_start();
$_SESSION['user'] = $user;

// Mode 5 compatible (ZealPHP native)
$g = G::instance();
$user = $g->post['username'];
$g->session['user'] = $user;
```

**Affected apps:** All legacy apps (WordPress, Joomla, phpBB, etc.)  
**Impact:** In Mode 5 (`superglobals(false)`), `$_GET`/`$_POST` are NOT populated per request. They remain as process-wide arrays, causing cross-request data contamination.  
**Modes affected:** Mode 5 only. Modes 1, 3, and 4 all populate superglobals per request.  
**Fix:** Use Mode 3 or Mode 4 for apps with direct superglobal access. Mode 5 is for native ZealPHP apps.

### 6. Composer Dependencies

**What it is:** Apps that require `vendor/` autoloading but have not had `composer install` run in the container.

**Affected apps:** phpMyAdmin (tested), Invoice Ninja, Flarum, most Laravel/Symfony apps  
**Impact:** Fatal autoload failures on first request.  
**Fix:** Always run `composer install --no-dev` in your Docker build step before starting ZealPHP. This is not a ZealPHP issue — it's standard PHP app deployment.

---

## Mode Selection Guide

Use this decision tree to find the right mode for your app:

```
Is this a native ZealPHP app using $g->get / $g->post?
├─ YES → Mode 5 (Coroutine) — highest performance, full concurrency
└─ NO  ↓

Is it built on Laravel, Symfony, CodeIgniter 4, Yii 2, or Laminas?
├─ YES → Mode 3 (Sync) — cleanest lifecycle for framework apps
│        (use zealphp-symfony bridge for Symfony)
└─ NO  ↓

Does it use bare define() without defined() guards, OR
function declarations without function_exists() guards?
├─ YES → Does it crash on 2nd request in Mode 3?
│        ├─ YES → Do you have ext-zealphp installed?
│        │        ├─ YES → Mode 4 (Hybrid) — per-coroutine isolation
│        │        └─ NO  → Mode 1 (CGI Pool) — only safe option
│        └─ NO  → Mode 3 (Sync) is fine
└─ NO  ↓

Does it need $_{GET,POST,SESSION,SERVER} superglobals AND concurrency?
├─ YES → Mode 4 (Hybrid) — requires ext-zealphp
└─ NO  ↓

Maximum compatibility with unknown/untested apps?
└─ Mode 1 (CGI Pool) — runs anything, ~1–3 ms warm (pool default)
```

### Quick Reference by App Type

| App Type | Recommended Mode | Reason |
|----------|-----------------|--------|
| Native ZealPHP app | **Mode 5** | Built for it |
| Laravel app | **Mode 3** | Clean DI lifecycle |
| Symfony app | **Mode 3** | PSR-15, use zealphp-symfony bridge |
| WordPress + plugins | **Mode 1** | Plugin ecosystem needs isolation |
| Magento 2 | **Mode 1** | Massive static DI, no alternatives |
| Single-file PHP tool (Adminer, etc.) | **Mode 1** or **Mode 4** | Redeclaration issues |
| Procedural legacy app | **Mode 1** | Process isolation handles everything |
| Modern micro-framework (Slim, etc.) | **Mode 3** | PSR-compliant |
| Flat-file CMS (Grav, Kirby, DokuWiki) | **Mode 3** | Clean OOP |

---

## Statistics

| Metric | Count | Notes |
|--------|-------|-------|
| Total apps surveyed | 50 | Mix of tested and predicted |
| Tested in Docker lab | 32 | All apps in `examples/sweep/apps/` deployed and tested |
| All 4 modes pass | 5 | Kanboard, Roundcube, OpenCart, Joomla, traditional |
| Mode 1 (CGI Pool) pass | 18 of 21 tested | adminer, cacti, dokuwiki (1st req), freshrss, joomla, kanboard, matomo, mybb, nextcloud, opencart, phpbb, phpliteadmin, piwigo, privatebin, roundcube, tinyfilemanager, traditional, vanilla, wordpress |
| Mode 1 fixed in this session | 4 root causes | 1) stderr deadlock from PHP 8.4 deprecations 2) constant/class/function leak across apps 3) flush/ob_end_flush/fastcgi_finish_request corrupting IPC stream 4) chdir() to script dir for relative includes |
| Mode 1 known issues | 3 | phpMyAdmin (ResponseRenderer-from-shutdown architectural conflict — see below), DokuWiki (works 1st req, breaks on respawn), yourls (503 pool exhausted) |
| **phpMyAdmin Mode 1 root cause** | architectural | phpMyAdmin registers `ResponseRenderer->response` as a shutdown function that writes HTML and calls `exit()`. Our pool worker's outer shutdown handler runs user shutdowns mid-cleanup; when `ResponseRenderer->response` exits, we never reach the IPC frame writeFrame line, parent sees null. **Use Mode 5 (Coroutine) for phpMyAdmin** — `HOOK_ALL` makes MySQL async, no subprocess context needed. Verified: M5 ✅ 3/3 PASS. Architectural fix would need separate IPC fd (fd 3) — not on roadmap. |
| Mode 4 (Hybrid) pass | 5 of 16 tested | adminer, kanboard, joomla, roundcube, opencart |
| Mode 4 partial | 6 | tinyfilemanager, dokuwiki, freshrss, vanilla, wordpress, matomo (alternating success — concurrent coroutine race on shared state) |
| Mode 4 failing | 5 | cacti, nextcloud, phpmyadmin, mybb, phpbb (heavy legacy apps needing fresh state — architectural limit, use Mode 1) |
| Mode 4 architectural limit | Process-wide state | Concurrent coroutines share function/class/constant tables. ext-zealphp's per-coroutine isolation handles superglobals + constants but NOT user-defined classes (loaded once, shared). For apps requiring fresh PHP state per request → use Mode 1 (CGI Pool) |
| User-globals cleanup | Yes (all modes) | Mode 1: FPM-style. Mode 3+FI: ext-zealphp zealphp_globals_clean. Mode 4/5: per-coroutine via `App::coroutineGlobalsIsolation(true)` (ext-zealphp v0.3.6+). |
| Session merge granularity | Leaf-level | TableSessionHandler + RedisSessionHandler with 3-way merge |
| Mode 1 recommended | ~24 | Legacy/procedural apps — WordPress, Magento, phpBB, etc. |
| Mode 3 recommended | ~19 | Framework-based apps — Laravel, Symfony, Flarum, etc. |
| Mode 4 viable | ~30 | Apps where ext-zealphp resolves redeclaration and isolation |
| Mode 5 applicable | ~5 | Native ZealPHP apps + clean PSR-7 apps (Slim, Kanboard) |
| PHP 8.4 incompatible | ~2 | phpLiteAdmin (not a ZealPHP issue) |

### Failure Mode Distribution (tested apps)

| Failure Cause | Apps Affected | Modes Affected | Fix |
|---------------|--------------|----------------|-----|
| Function redeclaration | Adminer, TinyFileManager, FreshRSS | 3, 5 (crash on 2nd req) | Mode 1 or Mode 4 |
| Constant redefinition | Matomo, Grav | 3, 4, 5 (crash on 2nd req) | Mode 1 (CGI process isolation) |
| CGI subprocess crash | DokuWiki, phpMyAdmin | 1 | Use Mode 3 instead |
| PHP 8.4 compat bug | phpLiteAdmin | All | Fix app code (not ZealPHP issue) |
| Missing composer deps | PrivateBin | All | Run `composer install` |
| Needs DB/config setup | MediaWiki, MyBB, Piwigo, YOURLS | All | Configure before testing |
| **No issues (all pass)** | **Kanboard, Roundcube, OpenCart, Joomla** | **None** | **Works everywhere** |

---

## ZealPHP Config Snippets

### Mode 1 — Maximum Compatibility (CGI Pool)

```php
<?php
use ZealPHP\App;

// One-call preset — superglobals(true) + isolation(CgiPool).
// As of this release legacy-cgi DEFAULTS to cgiPoolMaxRequests(1) — a fresh
// subprocess per request (Apache mod_php prefork parity). Unmodified WordPress/
// Drupal re-run unguarded top-level define()/class declarations every request,
// so a REUSED subprocess hits "Cannot redeclare class". Apps with re-entrant
// (guarded / Composer-autoloaded) boot can opt back into pool reuse with
// App::cgiPoolMaxRequests(N) for the ~1–3 ms warm dispatch.
App::mode(App::MODE_LEGACY_CGI);
App::documentRoot('wordpress');        // serve from the app's docroot
App::ignorePhpExt(false);              // allow .php URLs (wp-login.php, etc.)
// WordPress's assets live outside the default whitelist (/css/,/js/,…) — add
// the wp dirs so CSS/JS/images serve statically (admin asset subdirs too, but
// NOT /wp-admin/ itself so its .php files still execute).
App::staticHandlerLocations([
    '/wp-includes/', '/wp-content/',
    '/wp-admin/css/', '/wp-admin/js/', '/wp-admin/images/',
]);

$app = App::init('0.0.0.0', 8080);
// Front-controller fallback (Apache's .htaccess `RewriteRule . /index.php [L]`).
// Route unmatched URLs through the app's index.php — NOT raw REQUEST_URI, which
// would 403 static assets / REST / 404 URLs that must reach the front controller.
$app->setFallback(function() {
    $g = \ZealPHP\G::instance();
    $g->server['PHP_SELF']        = '/index.php';
    $g->server['SCRIPT_NAME']     = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/wordpress/index.php';
    return App::include('/index.php');
});
$app->run();
```

> **Verified (this release):** unmodified WordPress runs end-to-end in
> `legacy-cgi` — public site, **wp-admin, and the Gutenberg block editor** —
> with the global-scope include fix (issue #167) and the recycle=1 default. See
> the WordPress row note below.

### Mode 3 — Sync (Laravel / Symfony / Framework Apps)

```php
<?php
use ZealPHP\App;

// One-call preset — superglobals(true) + isolation(None).
// In-process, sequential. No subprocess overhead.
App::mode(App::MODE_MIXED);

$app = App::init('0.0.0.0', 8080);
// Register your framework's front controller:
$app->setFallback(function() {
    return App::include('/index.php');
});
$app->run();
```

### Mode 4 — coroutine-legacy (Concurrency + Superglobals, requires ext-zealphp)

```php
<?php
use ZealPHP\App;

// One-call preset — sets superglobals(true) + isolation(Coroutine)
// and auto-enables silentRedeclare, includeIsolation,
// coroutineGlobalsIsolation, coroutineStaticsIsolation.
App::mode(App::MODE_COROUTINE_LEGACY);

$app = App::init('0.0.0.0', 8080);
$app->setFallback(function() {
    return App::include('/index.php');
});
$app->run();
```

### Mode 5 — Native ZealPHP (New Apps)

```php
<?php
use ZealPHP\App;

// One-call preset — superglobals(false) + isolation(Coroutine).
// This is also the default when App::mode() is not called.
App::mode(App::MODE_COROUTINE);

$app = App::init('0.0.0.0', 8080);
$app->route('/api/users', function() {
    $g = \ZealPHP\G::instance();
    return ['users' => []];
});
$app->run();
```

---

*This document covers predicted behavior based on architectural analysis and confirmed Docker lab results. Real-world results may vary depending on plugin/extension state, database availability, and session configuration. File a GitHub issue with your app name and failure mode if you encounter a result that differs.*
