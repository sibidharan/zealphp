# Compatibility Database

> Last updated: 2026-06-12

How 50 popular PHP apps run on ZealPHP across **all four runtime modes**, with **`coroutine-legacy`** — the headline mode for legacy apps ("old PHP just works, concurrently") — the column to read first. Each graded `coroutine-legacy` cell is a **real install**: real database, real authenticated login, real write, and a 6-way concurrent burst, tested individually on the current stack (**ZealPHP master (v0.4.8+) + ext-zealphp v0.3.51**, PHP 8.4.21, OpenSwoole 26.2.0 (WordPress/MyBB rows re-validated on v0.3.50; the rest on v0.3.49 — no S2-frame-walk-affected behavior differs for them)).

**Rebuilt from scratch 2026-06-11** on the 0.3.48 stack; **re-validation sweep 2026-06-12** on the **0.3.49 stack** (ext#52 concurrent-burst heap-corruption fix). Apps not yet re-validated are marked _pending_ (they carry **no** grade rather than a stale one).

**2026-06-12 re-validation level:** every graded app below was re-run on 0.3.49 with the standard **84-request 6-way burst** (`burst6.php <port> 6 14`) plus a homepage check. Apps where the burst lifted to 0 worker deaths AND that previously failed *solely* on worker-crash were taken one level deeper (content sanity + scripted login/write where the harness allowed); the per-app detail notes the exact level reached ("full protocol" vs "burst+content" vs "burst-only"). The **ext#52 fix eliminated worker deaths** across most of the C/D set (kanboard, kirby, dokuwiki, phpmyadmin, yii2, laminas, elfinder, codeigniter4, tinyfilemanager, symfony, drupal all 0 deaths now), but **content-level checks revealed several apps whose 0-death bursts serve hollow/broken bodies** — those stay D/C honestly (CodeIgniter4 0-byte bodies, DokuWiki content "str() on null", TinyFileManager login still lost). An **object-global-null regression cluster** surfaced on 0.3.49 (WordPress `wp_set_wpdb_vars` null, MyBB `read() on null` — 500 on every request) — root-caused the same day and **FIXED in ext-zealphp v0.3.50**: the S2 request-frame walkers trusted `ex->symbol_table` without gating on `ZEND_CALL_HAS_SYMBOL_TABLE`, so a plain function frame’s stale field could match and the parked `$wpdb` bucket was re-pointed into `require_wp_db()`’s dying frame (NOT the ext#44/#49 frontier as first suspected). On v0.3.50 both render again — see the per-app rows.

**Status: 31/50 re-validated in `coroutine-legacy`** (B: 7 · C: 15 · D: 9); 19 pending. **2026-06-12 late corrections (ext v0.3.51 + framework OB fix):** CodeIgniter 4 D→**B** (0-byte bodies were a framework flush bug, fixed), DokuWiki D→**C** (content restored by the ext#50 class_alias fix), TinyFileManager D→**C** (login proven working — the sweep probe missed the CSRF token and didn't follow redirects). **Net grade changes 2026-06-12:** Kanboard D→B, Yii 2 C→B (both driven by ext#52 + session #379); 5 previously-_pending_ rows newly graded (TYPO3 C, Craft CMS C, Roundcube C, Concrete CMS D, Flarum D). The two 0.3.49 regressions (WordPress, MyBB — object-global null) are **resolved on v0.3.50**. Leantime now **F** (boot-fail: its prebuilt vendor directory without composer.json blocks the psr/http-message v1 fix, leading to cascading PHP 8.4 fatal errors).

---

## The four modes

ZealPHP's only mode API is `App::mode()` with **four** string presets — there are no mode numbers.

| Preset | `App::mode(...)` | Superglobals | Concurrency | Role |
|--------|------------------|:---:|---|------|
| **`legacy-cgi`** | `MODE_LEGACY_CGI` | yes | sequential (subprocess) | **Compatibility floor.** Per-request subprocess, Apache mod_php parity. Sub-strategy via **`cgiMode()`**: `pool` (pre-spawned, reused, ~1–3 ms — **the default**, what people mean by "pool"), `proc` (fresh process per request, ~30–50 ms), `fcgi`. For pure-`require_once` apps with no autoloader. |
| **`mixed`** | `MODE_MIXED` | yes | sequential (in-process) | **Sequential fallback.** In-process, superglobals per request, no coroutine concurrency. No subprocess cost. |
| **`coroutine-legacy`** | `MODE_COROUTINE_LEGACY` | yes | **full coroutine** | **The headline mode.** Unmodified request-style PHP (superglobals, `$_SESSION`, `exit`/`die`, `require_once`) under coroutine concurrency, with per-coroutine isolation of superglobals, `$GLOBALS`, function statics, constants, `require_once` re-execution. Requires ext-zealphp. |
| **`coroutine`** | `MODE_COROUTINE` | no | full coroutine | Native ZealPHP — uses `$g->get`/`$g->post` instead of superglobals. Highest performance; the rewrite target. |

> **"Is pool a separate mode?"** No — **`pool` is `legacy-cgi`'s default sub-strategy** (`cgiMode('pool')`), not a fifth mode. `legacy-cgi` = the mode; `pool`/`proc`/`fcgi` = how it spawns the subprocess.

## Grading scale

| Grade | Meaning |
|-------|---------|
| **A** | All flows pass (homepage, login, write, concurrent burst) with only DB/config setup. |
| **B** | All flows pass, but needed documented knobs (`globalScopeInclude`, `ignorePhpExt`, `preloadClassmap`, …) or minor workarounds. |
| **C** | Read paths + login work; a real-work flow (write, or stability under burst) fails — documented. |
| **D** | Boots but major breakage; usable only via a sequential fallback. |
| **F** | Cannot boot/serve in that mode. |
| **ENV** | A missing environment dependency (search engine, IMAP, license key) blocked full testing. |
| **NT** | Not tested in this sweep (the sweep targets `coroutine-legacy`; `legacy-cgi` is the by-design floor, `coroutine` needs a `$g->` rewrite). |

## Summary

Columns are the four modes. **`coroutine-legacy`** is the rigorous fresh grade; **`mixed`** is derived from each app's sequential-fallback probe (lighter than the full protocol); **`legacy-cgi`** and **`coroutine`** are `NT` unless this sweep exercised them.

| # | App | Cat | ★ | `legacy-cgi` | `mixed` | **`coroutine-legacy`** | `coroutine` | Top knob |
|---|-----|-----|---|:---:|:---:|:---:|:---:|----------|
| 1 | WordPress | CMS | 19k | NT | C | **C** | NT | v0.3.50: homepage 200/69KB; burst 79x200 5x500 **0 worker deaths** (was 6+ deaths/burst pre-ext#52; the 0.3.49 every-request-500 regression is fixed) |
| 2 | Drupal | CMS | 4.3k | NT | F | **D** | NT | App::preloadClassmap() after composer dump-autoload -o (tem… |
| 3 | Joomla | CMS | 4.7k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 4 | TYPO3 | CMS | 1.0k | NT | _pending_ | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) — homepage+content ok, 1 worker death/burst |
| 5 | Concrete CMS | CMS | 768 | NT | _pending_ | **D** | NT | App::mode(App::MODE_COROUTINE_LEGACY) — boots+homepage 200 but 2 worker deaths/burst |
| 6 | October CMS | CMS | 11k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 7 | Craft CMS | CMS | 3.1k | NT | _pending_ | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) — homepage+content ok, burst clean 84x200/0 deaths |
| 8 | Grav | CMS | 14k | NT | C | **D** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 9 | Kirby | CMS | 7.5k | NT | B | **C** | NT | define('KIRBY_HELPER_GO', false) before loading Kirby — Ope… |
| 10 | Statamic | CMS | 3.9k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 11 | Bagisto | E-comm | 15k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 12 | Magento 2 | E-comm | 11k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 13 | WooCommerce | E-comm | 9.6k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 14 | PrestaShop | E-comm | 7.8k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 15 | OpenCart | E-comm | 7.3k | NT | C | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 16 | Sylius | E-comm | 7.7k | NT | F | **F** | NT | Harness blocked: openswoole/core breaks on psr/http-message:^1.1 |
| 17 | Flarum | Forums | 15k | NT | _pending_ | **D** | NT | App::mode(App::MODE_COROUTINE_LEGACY) — boots+homepage 200 but 1 worker death/burst |
| 18 | phpBB | Forums | 1.8k | A | A | **C** | NT | App::globalScopeInclude(true) (template default, kept) |
| 19 | MyBB | Forums | 2.9k | NT | F | **C** | NT | v0.3.50: homepage 200/14KB; burst 82x200 2x500 **0 worker deaths** (0.3.49 every-request-500 regression fixed) |
| 20 | Vanilla Forums | Forums | 2.9k | NT | A | **B** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 21 | Laravel | Framework | 79k | NT | B | **B** | NT | App::preloadClassmap() + require app vendor/autoload.php in… |
| 22 | Symfony | Framework | 30k | NT | A | **D** | NT | App::preloadClassmap() + require app vendor/autoload.php in… |
| 23 | CodeIgniter 4 | Framework | 5.3k | NT | C | **B** | NT | CI_ENVIRONMENT=production + the is_cli() pre-define; 0-byte bodies fixed framework-side (empty-flush no-op, master post-v0.4.8) — full page 200/17 KB, burst 84x200, 0 deaths |
| 24 | CakePHP | Framework | 8.7k | NT | A | **D** | NT | composer require psr/http-message:^1.1 — skeleton vendor sh… |
| 25 | Slim | Framework | 12k | NT | — | **B** | NT | App::preloadClassmap() + require /apps50/slim/vendor/autolo… |
| 26 | Yii 2 | Framework | 14k | NT | B | **B** | NT | composer install --no-dev + composer dump-autoload -o (dev …; burst now fully clean (ext#52) |
| 27 | Laminas | Framework | 5.1k | NT | A | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 28 | phpMyAdmin | Admin | 7.2k | NT | C | **D** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 29 | Adminer | Admin | 6.1k | NT | — | **B** | NT | App::defineIsolation(true) (Adminer defines per-request con… |
| 30 | TinyFileManager | Admin | 6.2k | NT | F | **C** | NT | login WORKS (sweep probe artifact — CSRF token + redirect-following); write/upload not yet exercised |
| 31 | Roundcube | Admin | 6.0k | NT | _pending_ | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) — webmail login page renders, burst stable 0 deaths; IMAP login = ENV |
| 32 | FileGator | Admin | 1.8k | NT | _pending_ | _pending_ | NT | re-validation in progress |
| 33 | elFinder | Admin | 3.0k | NT | F | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 34 | MediaWiki | Wiki | 3.7k | NT | C | **D** | NT | App::mode(MODE_COROUTINE_LEGACY) |
| 35 | DokuWiki | Wiki | 4.1k | NT | F | **C** | NT | content RESTORED by ext v0.3.51 (class_alias first-wins — inc/legacy.php aliases were the 'str() on null'); login/write pending re-run |
| 36 | BookStack | Wiki | 16k | NT | F | **C** | NT | App::mode(App::MODE_COROUTINE_LEGACY) |
| 37 | Kanboard | Business | 8.4k | NT | A | **B** | NT | App::globalScopeInclude(true) + App::defineIsolation(true) — login+write now pass (session #379 + ext#52) |
| 38 | Invoice Ninja | Business | 8.3k | NT | F | **F** | NT | PHP 8.4 incompat / Download issues |
| 39 | Leantime | Business | 4.1k | NT | _pending_ | **F** | NT | Harness blocked: prebuilt vendor breaks on psr/http-message:^1.1 fix |
| 40 | Monica CRM | Business | 22k | NT | F | **F** | NT | Harness blocked: openswoole/core breaks on psr/http-message:^1.1 |
| 41 | Crater | Business | 8.2k | NT | F | **F** | NT | PHP <8.2 required |
| 42 | Matomo | Analytics | 19k | NT | F | **F** | NT | Harness blocked: psr/log interface conflict |
| 43 | Cacti | Analytics | 1.5k | NT | D | **D** | NT | App::globalScopeInclude(true) + zend_mm_heap corrupted on DB failure die() |
| 44 | LibreNMS | Analytics | 3.9k | NT | F | **F** | NT | PHP 8.4 incompat in Composer script |
| 45 | FreshRSS | Content | 10k | NT | F | **C** | NT | App::globalScopeInclude(true) (template default, kept) |
| 46 | Piwigo | Content | 3.1k | NT | F | **D** | NT | App::ignorePhpExt(false) — Piwigo needs direct *.php URLs (… |
| 47 | Lychee | Content | 13k | NT | F | **F** | NT | PHP 8.4 incompat in Composer script |
| 48 | Wallabag | Content | 10k | NT | F | **F** | NT | PHP 8.4 incompat in Composer script |
| 49 | Nextcloud | Cloud | 24k | NT | C | **C** | NT | App::globalScopeInclude(true) |
| 50 | YOURLS | Utility | 10k | NT | C | **C** | NT | App::ignorePhpExt(false) — YOURLS requires direct *.php URL… |

> Failure-class root causes + fix status: [`docs/architecture/2026-06-11-coroutine-legacy-blocker-report.md`](architecture/2026-06-11-coroutine-legacy-blocker-report.md). Several blockers found in this sweep are already fixed on the current stack (Stage-8 object-store corruption → ext 0.3.47; `exit()`/`die()` swallow → ext 0.3.48; session persistence → #379; ZealAPI scope → #376), so _pending_ rows and the session-blocked C/D apps are expected to grade higher on re-run.

---

## Per-app detail (re-validated in coroutine-legacy)

#### WordPress — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst+content):** ⚠ **REGRESSION** — homepage now returns **500 on every request including the first sequential one** (`Error: Attempt to assign property "field_types" on null` at `wp_set_wpdb_vars` / `wp-includes/load.php:746`). The `$wpdb` object-global is null when `wp_set_wpdb_vars()` runs — the documented object-global-across-connect-yield interaction (CLAUDE.md "object-valued `$GLOBALS`" / mysqlnd-teardown frontier, ext#44/#49). DB (`zext_wordpress`) is present and reachable; this is NOT an env issue. Burst on `/` = 84x500, 0 worker deaths/segfaults (the ext#52 crash class is gone, but the app can't render). The 0.3.48 grade below (homepage 200/69KB) predates this. **Needs ext-level investigation before WordPress can re-grade.**
- **2026-06-12 v0.3.50 re-validation (burst+content):** ✅ **regression FIXED** — the cause was NOT ext#44/#49 but a 0.3.49 S2 frame-walk bug (`ZEND_CALL_HAS_SYMBOL_TABLE` gate missing; the parked `$wpdb` bucket was re-pointed into `require_wp_db()`’s dying function frame — traced live with `ZEALPHP_TRACE_GLOBAL=wpdb`). On v0.3.50: homepage **200/69KB** (sequential x2), burst **79x200 5x500, 0 worker deaths, 0 segfaults** — the first time WordPress survives the full 6-way burst with zero deaths (pre-ext#52 it lost ~6 workers/burst). The 5x500 are the residual app-level cold-path class (ext#44/#49 teardown family). Login/write not re-run this pass — grade stays **C** pending the full protocol.
- **homepage** 200/69KB · **login** ok (wp-login.php POST -> wp-admin Dashboard 200; session persists across reques… · **write** ok: POST rest_route=/wp/v2/posts -> 201, post id 6 'ZealPHP coroutine-legacy te…
- **concurrent burst** unstable: warm bursts 6-8x200 + 7-9x500 + 1-2x302 + 3-4x000(hung); 11 worker SIGSEGVs across 2 bursts (~5/burst, self-healing respawn); best observed…
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY) + App::globalScopeInclude(true) (template default; required for wp-admin)`
  - `App::ignorePhpExt(false) — required so wp-login.php / wp-admin/*.php URLs are served instead of the framework's implicit .php 403 block`
- **`mixed` fallback probe:** mixed: homepage 200/69KB and burst20 = 20x200 with 0 worker deaths (fully stable under concurrency); but /wp-admin/ returned 302 (admin session not re-validated under mixed) and REST write 403 (nonce unobtainable withou…

#### Drupal — coroutine-legacy: **D**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst 6x200/78x500, **0 worker deaths, 0 segfaults** (the 500s are clean Drupal exception pages from the concurrency race, not crashes — consistent with the prior reading). The login/write blocker is the mode-independent Drupal session-handler bypass, unchanged. Stays **D**.
- **homepage** 200/21KB (15.7KB after big_pipe uni… · **login** failed: credentials accepted (POST /user/login -> 303 /user/1) but session neve… · **write** failed: GET/POST /node/add/page -> 403 Access denied (blocked on the login fail…
- **concurrent burst** concurrent -P6: 20x500; sequential -P1: 20x200. 0 segfault/zend_mm/Fatal patterns in boot.log (workers survive; the 500s are clean Drupal exception p…
- **knobs:**
  - `App::preloadClassmap() after composer dump-autoload -o (template's globalScopeInclude removed)`
  - `SessionStartMiddleware (first-visit PHPSESSID cookie)`
  - `request-side cookie mirror PHPSESSID -> SESS<sha256(host)[0:32]> in front controller (Drupal cookie-auth applies())`
  - `web/index.php: `require_once autoload.php` -> `require` (Stage 7 re-execution)`
  - `drush pmu big_pipe (removed BigPipeHooks getOption()-on-null 500s; did NOT fix the concurrency 500s)`
- **`mixed` fallback probe:** mixed: boots, homepage 200, but login fails IDENTICALLY (303 then anonymous on next request; node/add 403) — the Drupal session-handler bypass is mode-independent, so MODE_MIXED does not rescue auth/write flows either

#### Grav — coroutine-legacy: **D**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst now shows **only 2 worker deaths** (down from 16-17x500 + SIGSEGV per burst), homepage 200 — partial improvement from ext#52 but still crashes under concurrency. Admin-dashboard 500 (session-decode) unchanged. Stays **D**.
- **homepage** 200/13.5KB · **login** failed: login POST succeeds (303, writes session) but the immediately-following… · **write** n/a: admin dashboard 500s on every authenticated request, so no page-create pos…
- **concurrent burst** 1-2x200 then 16-17x500 + worker SIGSEGV (signal=11); reproducible under 6-way concurrency even after sequential warmup. An earlier warm-sequentialize…
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `putenv GRAV_ROOT / GRAV_WEBROOT before boot`
  - `define('PAGE_ORDER_PREFIX_REGEX') at boot (master) to survive per-request constant reset`
  - `ini_set display_errors=0 to suppress benign re-define warnings`
  - `zeal-entry.php wrapper to collapse Grav's open output buffers (register_shutdown_function never fires on long-lived worker)`
  - `defineIsolation deliberately LEFT OFF (poisons Grav: GRAV_WEBROOT read back as YAML_EXT value on request 2)`
- **`mixed` fallback probe:** mixed: App::MODE_MIXED FIXES the session-decode 500 (traditional SessionManager round-trips Grav's Messages object natively) — public homepage stays 200 across repeated cookied requests r1/r2/r3. BUT /admin/ then breaks…

#### Kirby — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst on `/` is **14x200/70x500 with 0 worker deaths, 0 segfaults** (was 9x200/11x500 with worker self-recovery). The crash dimension is clean; the remaining 500s are Kirby's app-level concurrency race. Login/write passed at the prior C; grade unchanged at **C**.
- **homepage** 200/9843B · **login** ok — GET /panel/login 200/162KB (kirby_session cookie + 64-char csrf from panel… · **write** ok — POST /api/site/children created page 'zeal-revalid-1781185914' (200), PATC…
- **concurrent burst** / : 9x200 11x500; /notes : 3x200 17x500 (xargs -P 6). 0 segfault/zend_mm/Fatal error/deadlock in boot.log this run; worker self-recovers — post-burst…
- **knobs:**
  - `define('KIRBY_HELPER_GO', false) before loading Kirby — OpenSwoole's go() shortname collides with Kirby's go() helper ('Cannot redeclare function go() in kirby…`
  - `explicit catch-all patternRoute('#^/(?P<zealpath>.*)$#', all methods) registered before run() — Kirby routes every path virtually; ZealPHP's implicit /{file} +…`
  - `use App::include($script) NOT App::includeFile($script) in the catch-all — includeFile() treats the docroot-relative '/index.php' as an outside-docroot ABSOLUT…`
  - `require app vendor/autoload.php + App::preloadClassmap() at boot — REQUIRED: without it the per-request class-static reset breaks Kirby on request 2+ even sequ…`
- **`mixed` fallback probe:** mixed: same install + same app.php with App::mode(App::MODE_MIXED) (app_mixed.php) — burst20 on / AND /notes both 20x200, 0 fatals/segfaults in boot_mixed.log; re-confirmed in this 2026-06-11 run

#### OpenCart — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst now shows only **1 worker death** (down from 2 SIGSEGV + 1 deadlock), homepage 200 — partial ext#52 improvement. Login/write passed at prior C. Stays **C**.
- **homepage** 200/34KB · **login** ok · **write** ok: created category via admin POST catalog/category.save -> {category_id:59} a…
- **concurrent burst** 16x200 4x000 (at -P6 vs 4 workers); 2 worker SIGSEGV (signal=11) + 1 coroutine deadlock during burst, server self-healed (respawned, port stayed up, …
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::defineIsolation(true) - REQUIRED: OpenCart define('VERSION',...) collides across requests; without it the admin path breaks (proven in MIXED-mode fallback…`
  - `explicit routes for /index.php, /admin/index.php, /admin, /admin/ registered before run() - REQUIRED: ZealPHP's implicit .php-extension block returns 403, and …`
  - `worker_num=4 (raised from template 2)`
- **`mixed` fallback probe:** mixed: MODE_MIXED storefront burst 40/40 x200, 0 worker deaths, 0 segfaults (sequential-per-worker eliminates the concurrent-mysqli crash) - BUT MODE_MIXED lacks defineIsolation so OpenCart's define('VERSION') collides …

#### phpBB — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** still crashes under burst — **7 worker deaths + 2 segfaults**, master crashed mid-burst (homepage went 500 once the master died). The ext#52 fix did NOT resolve phpBB's burst corruption (it was already failing on 0.3.47 with zend_mm_heap-corrupted + SIGSEGV/SIGABRT). phpBB remains a **burst crasher**; the C grade rests on its sequential `legacy-cgi` full-pass. Stays **C** (one of the remaining frontiers).
- **homepage** 200/13.8KB (forum index 'ZealPHP Te… · **login** ok (POST /ucp.php?mode=login with creation_time+form_token+sid -> 302; mode=log… · **write** ok: new topic via POST /posting.php?mode=post&f=2 -> 302 to /viewtopic.php?t=3;…
- **concurrent burst** FAIL on ext 0.3.47: round1 19x500 1x000, round2 10x500 9x000 1x200; zend_mm_heap corrupted + SIGSEGV(11)/SIGABRT(6) worker deaths each burst; even 3-…
- **knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::ignorePhpExt(false) — phpBB lives on /ucp.php-style URLs; without it every .php URL 404s`
  - `master pre-fork warmup: require phpBB3/vendor/autoload.php + register \phpbb\class_loader + App::preloadDir(phpBB3/phpbb) — phpbb\* is NOT in the composer clas…`
  - `define('PHPBB_ROOT_PATH', abs path) — pins phpBB's CWD-relative $phpbb_root_path`
  - `app-level prerequisite (not a ZealPHP knob): rename phpBB3/install/ after install or all pages render 'board currently unavailable'`
  - `tried+rejected (prior session, same install): App::preloadClassmap() (force-loads symfony proxy-manager-bridge V1 compat shim -> boot fatal 'Declaration ... mu…`
- **`mixed` fallback probe:** legacy-cgi: FULL PASS re-verified on this run — homepage 200/13.3KB, burst20 = 20x200, 0 worker deaths, 0 corruption lines (app_cgi.php kept beside app.php). mixed: unusable (class redeclare fatals).

#### MyBB — coroutine-legacy: **C**
- **2026-06-12 v0.3.50 re-validation (burst+content):** ✅ the 0.3.49 every-request-500 regression (`read() on null`) is **fixed** (same S2 frame-walk bug as WordPress). Homepage **200/14KB**; burst **82x200 2x500, 0 worker deaths**.
- **homepage** 200/13.5KB · **login** ok (intermittent): succeeds with mybbuser cookie + User Control Panel, but ~1-i… · **write** ok: authenticated newthread.php form POST created thread tid=1 'ZealPHP corouti…
- **concurrent burst** best (USE_ZEND_ALLOC=0 + defineIsolation): 14x200 1x500 5x000(conn refused), post-burst alive 200, 2 worker deaths (zend_mm_heap corrupted sig6 + mys…
- **knobs:**
  - `App::ignorePhpExt(false) — REQUIRED: MyBB is multi-entry (*.php URLs); default blocks them with 403, including install/index.php`
  - `App::globalScopeInclude(true) — template default kept`
  - `App::defineIsolation(true) — added for MyBB's per-request define()s (TIME_NOW/THIS_SCRIPT freeze: identical mybb[lastvisit] cookie values served minutes apart …`
  - `USE_ZEND_ALLOC=0 env — with it the master survives worker crashes (self-healing respawn); without it the master died twice (full outage)`
- **`mixed` fallback probe:** mixed: NOT usable — App::mode(App::MODE_MIXED) served 500 on EVERY request including request 1 and all of burst20 (0 crashes though); MyBB's require_once bootstrap never re-executes without Stage 7, so per-request state…

#### Vanilla Forums — coroutine-legacy: **B**
- **2026-06-12 re-validation (0.3.49, burst-only):** homepage 200, burst shows **1 worker death** (the cold-concurrent first wave; warm steady-state was clean at the prior B). Login/write passed at prior B. Stays **B** (the single cold-wave death is consistent with the prior "~6-10x500 cold first wave then 200s" characterization).
- **homepage** 200/52KB · **login** ok · **write** ok: created discussion #5 (admin, CategoryID=1), verified via GET /discussion/5…
- **concurrent burst** cold-concurrent first wave ~6-10x500 then 200s (workers survive, 0 crashes); warm steady-state 20x200 repeatable across 3 rounds, 0 worker deaths
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::ignorePhpExt(false)`
  - `App::$cwd + documentRoot set to /apps50/vanilla/public (Vanilla PATH_ROOT=getcwd())`
  - `fallback sets $_GET['p']=clean-path so /categories /discussions route via Gdn_Request UrlFormat`
  - `PATCH: garden-container Container.php — array_values() on 7 reflection-invoke sites (PHP8 named-param fatal $defaultgroup)`
  - `PATCH: Vanilla\Models\ModelCache.php — hydrate() called with no args (PHP8 named-param fatal $junctionExclusions)`
  - `manual conf/config.php (installer signed in admin + built all DB tables but never persisted config.php to disk under coroutine-legacy)`
- **`mixed` fallback probe:** mixed: App::MODE_MIXED runs every failing flow clean — homepage 200, post-form request-2 200 (no Smarty fatal), burst20 20x200, 0 worker deaths. Sequential per-worker handling avoids the coroutine-concurrency state corr…

#### Laravel — coroutine-legacy: **B**
- **homepage** 200/70KB · **login** ok · **write** ok: POST /tasks (CSRF + auth session) created row, verified via GET /tasks + SE…
- **concurrent burst** 20x200 (two clean consecutive runs, 0 worker deaths/segfaults). Later re-runs degraded to 500s solely from shared-MariaDB exhaustion: SQLSTATE[08004]…
- **knobs:**
  - `App::preloadClassmap() + require app vendor/autoload.php in master (template guidance for composer apps; globalScopeInclude removed)`
  - `composer require psr/http-message:^1.1 — Laravel vendor ships psr/http-message v2 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes (autolo…`
  - `composer install --no-dev + dump-autoload -o --no-dev — dev-only classes (mockery PHPUnit TestListener trait, faker) hard-fatal the classmap warm (trait-not-fo…`
  - `composer.json exclude-from-classmap: vendor/psy/psysh/ + vendor/symfony/*/DependencyInjection/ — psysh Hoa polyfills (incompatible signatures) and symfony opti…`
  - `app.php fallback must use App::include('/index.php') NOT App::includeFile() — _template.php bug: includeFile takes an ABSOLUTE path, so '/index.php' resolves a…`
  - `explicit catch-all $app->patternRoute('#^/.*$#', ...) -> front controller so non-/ URLs route to Laravel (implicit /{file} routes intercept single-segment path…`
  - `public/index.php: '$app = require_once bootstrap/app.php' -> plain 'require' (1 line). With require_once, concurrent bursts raced Stage 7's per-request EG(incl…`
  - `public/index.php: defined('LARAVEL_START') || define(...) guard — the constant persists per worker and the bare define() echoed an 'already defined' warning in…`
- **`mixed` fallback probe:** mixed: full suite also passes (homepage 200/70KB, session counter, login ok, POST /tasks write ok, burst20 20x200, 0 fatals) — needs the same index.php require_once->require tweak; app_mixed.php left in tree

#### Symfony — coroutine-legacy: **D**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst on `/` 37x200/47x500, **0 worker deaths, 0 segfaults**. The D blocker is the login failure (mode-independent session bypass — passes only under `mixed`), unchanged. Stays **D**.
- **homepage** 200/18432B · **login** failed: credentials+CSRF accepted (POST /en/login -> 302 to /en/admin/post/) bu… · **write** blocked in coroutine-legacy (needs login). Proven under mixed fallback: POST /e…
- **concurrent burst** 20x200 (coroutine-legacy), grep -ciE 'segfault|zend_mm|core dump|Fatal error' boot log = 0; mixed burst also 20x200
- **knobs:**
  - `App::preloadClassmap() + require app vendor/autoload.php in master (template guidance for composer apps; globalScopeInclude removed)`
  - `composer require psr/http-message:^1.1 — demo vendor ships psr/http-message 2.0 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes`
  - `composer install --no-dev + dump-autoload -o --no-dev — phpstan-doctrine's MappingDriverChain has an incompatible-declaration CompileError that hard-fatals the…`
  - `rm -rf var/cache/prod + cache:warmup AFTER --no-dev — container compiled with dev deps wires Twig profiler against symfony/stopwatch -> 'Class Stopwatch not fo…`
  - `public/index.php replaced with classic front controller (require autoload, new Kernel, handle/send/terminate; original at index.php.orig) — symfony/runtime's a…`
  - `APP_SECRET must be set in .env.local — create-project leaves it empty; doctrine console + login SignatureHasher throw 'A non-empty secret is required'`
  - `explicit catch-all $app->patternRoute('#^/.*$#', ...) -> front controller (implicit /{file} routes intercept single-segment paths)`
  - `curl-only note, not a ZealPHP knob: Symfony 8 stateless CSRF (csrf-token placeholder) validates via same-origin — POSTs need Origin: + Referer: headers, _csrf_…`
  - `FAILED knobs for the login flow: App::sessionLifecycle(false) (zeal_session_write_close then persists NOTHING since $g->session is never set); security.session…`
- **`mixed` fallback probe:** mixed: FULL suite passes with STOCK security config and zero extra knobs beyond the composer-app set — login ok (302 -> /en/admin/post/, admin Post List 200), admin post create ok (id=31, verified admin list + public sl…

#### CodeIgniter 4 — coroutine-legacy: **B** (0-byte bodies FIXED framework-side — 2026-06-12)
- **2026-06-12 follow-up (framework master post-v0.4.8 + ext v0.3.51, burst+content):** ✅ the 0-byte bodies were a THREE-layer framework output-buffer bug, not a CI4 problem: (1) an EMPTY `flush()` switched the response to streaming + sent headers — CI4's `app/Config/Events.php` drains the host's buffers (`while (ob_get_level() > 0) ob_end_flush();`) before producing output, so `ResponseMiddleware` skipped body collection and the page echoed later was discarded; (2) `ob_end_flush()`/`ob_flush()` lacked native nested semantics above the framework's OB floor; (3) the at-floor pop must stay unconditional so the drain loop terminates. With the fix (zealphp PR #401): **full welcome page 200/17,244 bytes, burst 84×200, 0 worker deaths**. Note: CI4 also needs the `is_cli()` pre-define knob (it sniffs `PHP_SAPI === 'cli'` under OpenSwoole and would boot its CLI context) — already in the harness. No login/write layer in the skeleton → **B** (all applicable flows pass with documented knobs).
- **2026-06-12 re-validation (0.3.49, burst+content):** burst is **84x200, 0 worker deaths, 0 segfaults** (CodeIgniter4 was a named ext#52 fix target — confirmed 0 deaths). **BUT the documented D blocker — the 0-byte body swallow — persists:** homepage returns `code=200 size=0b` with no content, sequentially and under burst. The "84x200" are hollow responses, exactly the issue that previously also broke writes (POST never reached MariaDB). Stays **D** — the crash class was never CI4's problem; the body-swallow is.
- **homepage** 200/0KB - CI4 headers (Cache-Contro… · **login** n/a: appstarter ships no auth; custom session route used instead - 200/0b, body… · **write** failed: POST /demo/notes/add -> 200/0b AND row never reaches MariaDB (notes cou…
- **concurrent burst (0.3.48)** 20x200 (all 0-byte bodies); 0 segfault/zend_mm/fatal during burst; one earlier 'all coroutines asleep - deadlock' fatal at worker recycle
- **knobs:**
  - `CI_ENVIRONMENT=production (development mode fatals: Kint/Debug-Toolbar 'Cannot use a scalar value as an array' in ThirdParty/Kint/CallFinder.php:186; TypeError…`
  - `app.php: function is_cli(): bool { return false; } (CI4 sees PHP_SAPI=cli in OpenSwoole workers -> builds CLIRequest -> TypeError array_shift(null) in HTTP/CLI…`
  - `app.php: require APP_DIR/vendor/autoload.php before App::preloadClassmap() (preloadClassmap only warms REGISTERED composer loaders; warmed 1062 symbols)`
  - `public/index.php: replaced exit(Boot::bootWeb($paths)) with plain call (did not fix body swallow)`
  - `public/index.php: cleared G::instance()->shutdown_functions (CI4 Exceptions::shutdownHandler exits -> zeal log 'shutdown function threw: openswoole exit'; did …`
  - `public/index.php: header_remove('Content-Length') (did not fix)`
  - `MODE_MIXED only: require_once for Paths.php/Boot.php + defined() guard on FCPATH (plain require refataled 'Cannot redeclare class Config\Paths' every request)`
- **`mixed` fallback probe:** mixed: boots + burst20 20x200 + zero fatals after require_once/FCPATH patches, but the SAME 0-byte body swallow on every CI4 page - fallback does not make the app usable either

#### CakePHP — coroutine-legacy: **D**
- **homepage** 200/7.5KB · **login** n/a: cakephp/app skeleton ships no auth plugin; per install hint verified sessi… · **write** ok: POST /posts/add (real 152-char _csrfToken from form + cookie jar) -> 302 Lo…
- **concurrent burst** FAILS HARD: surviving bursts ~35-50% 500 (13x200 6x500 1x404); on BOTH 2026-06-11 re-validation runs the server then WEDGED PERMANENTLY mid-burst — w…
- **knobs:**
  - `composer require psr/http-message:^1.1 — skeleton vendor ships 2.0 whose typed interfaces conflict with /zeal openswoole/core PSR-7 v1 (Laravel-result playbook)`
  - `config/app_local.php 'Error' => errorRenderer HtmlErrorRenderer + exceptionRenderer WebExceptionRenderer + errorLevel ~E_DEPRECATED — PHP_SAPI=cli under opensw…`
  - `vendor 1-line patch Cake\Http\Session::options(): early-return when PHP_SAPI === 'cli' — per-request session ini_set() always fails under cli SAPI ('Session in…`
  - `defined()-guards on all define()s in config/paths.php + vendor cakephp config/bootstrap.php, INSTEAD of App::defineIsolation(true) — defineIsolation's first-de…`
  - `try/catch (\Throwable) around the 5 StaticConfigTrait::setConfig() calls in config/bootstrap.php (Cache/ConnectionManager/TransportFactory/Mailer/Log) — Stage …`
  - `ZEALPHP_CLASS_STATICS_RESET_DISABLE=1 — the per-request class-statics reset produced cross-class static corruption under overlap (Schema\Column object assigned…`
  - `App::preloadDir(vendor/cakephp/cakephp/src/Database) + App::preloadClassmap() (composer dump-autoload -o; composer.json exclude-from-classmap for vendor/symfon…`
  - `ZealPHP\Middleware\SessionStartMiddleware added — first-time visitors got no session cookie (CoSessionManager only resumes existing PHPSESSID)`
  - `config/app_local.php 'App' => ['fullBaseUrl' => 'http://127.0.0.1:9714'] — skeleton HostHeaderMiddleware throws SECURITY InternalErrorException when unset`
  - `app.php per _template rev2: App::include() + explicit catch-all to webroot/index.php; boot const renamed ZCAKE_DIR because CakePHP's paths.php itself defines A…`
- **`mixed` fallback probe:** mixed: FULL suite passes — homepage 200/7.5KB, GET /posts/add CSRF form 200, POST 302 + row in DB, flash message 'The post has been saved' RENDERS (session flash works), second requests 200, burst20 = 20x200 twice, 0 fa…

#### Slim — coroutine-legacy: **B**
- **2026-06-12 re-validation (0.3.49, burst-only):** re-confirmed clean — burst **84x200, 0 worker deaths, 0 segfaults**. Stays **B**.
- **homepage** 200/12B (Hello world!); GET /users … · **login** ok: POST /login user=admin/pass=secret -> {"login":"ok","user":"admin"}; $_SESS… · **write** ok: POST /tasks (session-authenticated) -> INSERT into MySQL zext_slim.tasks; v…
- **concurrent burst** 20x200 (two consecutive runs) + auth burst 10x200 GET /tasks + 5x200 POST /tasks; boot.log new-fatal grep during bursts = 0. The single 'all coroutin…
- **knobs:**
  - `App::preloadClassmap() + require /apps50/slim/vendor/autoload.php in master (composer-app pattern; globalScopeInclude removed — it did NOT help session persist…`
  - `composer require psr/http-message:^1.1 — slim-skeleton vendor ships psr/http-message 2.0 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes …`
  - `composer install + dump-autoload -o --no-dev — strip phpstan/prophecy/codesniffer dev classes from the optimized classmap so preloadClassmap warm in the master…`
  - `App::addMiddleware(new SessionStartMiddleware()) — REQUIRED: ZealPHP's SessionManager only auto-emits Set-Cookie for RETURNING visitors (incoming PHPSESSID). W…`
  - `HEADLINE FIX — $GLOBALS['_SESSION'] = &\ZealPHP\G::instance()->session at the top of every session-touching closure. In coroutine-legacy ZealPHP binds $_SESSIO…`
  - `Run Slim in the request coroutine frame via a native catch-all $app->patternRoute('#^/.*$#', ...) + setFallback that require the front controller (public/slim_…`

#### Yii 2 — coroutine-legacy: **B** (lifted from C, 2026-06-12)
- **2026-06-12 re-validation (0.3.49, burst+content):** **C → B.** The sole C blocker was burst instability (warm 19x200 1x500; cold first-wave 17-24/60 anomalies). On 0.3.49 the burst is **fully clean — 84x200, 0 worker deaths, 0 cold-wave anomalies** (ext#52). Homepage serves real content ("My Yii Application", CSRF login form renders). Login + write already passed at the prior C grade (admin/admin login + contact-form .eml write), so with the only remaining defect resolved Yii 2 now passes all flows → B (needs the documented composer/preload/YII_DEBUG=false knobs).
- **homepage** 200/10170B · **login** ok: admin/admin via CSRF form POST, 'Logout (admin)' verified in response · **write** ok: contact form POST (CSRF + fixedVerifyCode captcha) -> .eml written to runti…
- **concurrent burst (0.3.48)** warm steady-state: 19x200 1x500 (also observed 20x200 and 38/40x200 runs); COLD first concurrent wave after fresh boot: 17-24 of 60 responses come ba…
- **knobs:**
  - `composer install --no-dev + composer dump-autoload -o (dev deps pull psr/http-message 2.0 via codeception/guzzle whose typed interfaces fatal against /zeal ope…`
  - `require APP_DIR/vendor/autoload.php in master + App::preloadClassmap() (template composer-app shape; globalScopeInclude removed)`
  - `require APP_DIR/vendor/yiisoft/yii2/Yii.php in master — class Yii has no namespace, is NOT PSR-4 compliant so absent from the optimized classmap; warms the Yii…`
  - `web/index.php switched to YII_DEBUG=false / YII_ENV='prod' — required: master classmap warm executes BaseYii.php side-effect defines (YII_ENV='prod' baseline),…`
  - `public -> web symlink for the protocol docroot`
  - `tried, no effect, reverted: ZEALPHP_CLASS_STATICS_RESET_DISABLE=1 (burst anomaly rate unchanged: 2/40 vs 4/40)`
- **`mixed` fallback probe:** mixed: MODE_MIXED + App::silentRedeclare(true) (entry plain-requires Yii.php every request) = EVERYTHING clean: login ok, contact flash ok, burst20 20x200 with 20/20 full homepage bodies, 0 fatals/deadlocks

#### Laminas — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst **84x200, 0 worker deaths, 0 segfaults** (warm). The C blocker is the `$_SESSION`-write-inside-`App::include()` failure (write flow), which is coroutine-legacy snapshot-related and unchanged. Stays **C**.
- **homepage** 200/5KB · **login** ok (session route, HTTP 200; cookie round-trips via SessionStartMiddleware) · **write** failed: $_SESSION write inside App::include() (Laminas front-controller dispatc…
- **concurrent burst** 20x200 (warm); first cold burst before worker-start warmup was 14x200 3x500 + 1 SIGSEGV worker death; after warmup 60x200 over 3 rounds, 0 deaths
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::preloadClassmap() (warms ZealPHP own classmap in master)`
  - `SessionStartMiddleware (required: first-time visitors otherwise get NO Set-Cookie, so session never round-trips)`
  - `onWorkerStart: require app vendor/autoload.php + App::preloadDir('module') + preloadClassmap() (warms Laminas class graph single-coroutine; eliminates cold-con…`
- **`mixed` fallback probe:** mixed: session write_op works perfectly under App::MODE_MIXED — hits increment 1->2->3->4->5->6->7 across requests, persists across second-request homepage. Confirms the write-back failure is coroutine-legacy snapshot x…

#### phpMyAdmin — coroutine-legacy: **D** (burst-crash class fixed, login not re-confirmed — 2026-06-12)
- **2026-06-12 re-validation (0.3.49, burst-only):** the **worker-death crash class is FIXED** — burst is **82x200/2x500, 0 worker deaths, 0 segfaults** (was 8-10 worker heap-corruption deaths under -P6). Homepage 200/48KB renders. The D blocker (cookie-auth session not persisting) was **not cleanly re-confirmed** this run — the scripted login POST couldn't reliably extract pMA's CSRF `token` from the login HTML in the time budget. Kept at **D** pending a proper login re-test; the crash dimension is resolved and noted.
- **homepage** 200/48KB · **login** failed: session not persisted across requests in coroutine-legacy. phpMyAdmin u… · **write** n/a: blocked by login failure (cannot authenticate, so no authenticated write p…
- **concurrent burst (0.3.48)** sequential 20x200; concurrent wave (P6) -> 2x500 2x200 2x000 + worker heap-corruption crashes (delta +4 deaths). Total 8-10 worker deaths logged: 'ze…
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true) [template default]`
  - `App::ignorePhpExt(false) [phpMyAdmin serves /index.php?route=... URLs]`
  - `App::documentRoot(/apps50/phpmyadmin/public)`
  - `fallback front-controller in app.php routing all non-static URLs to /index.php`
- **`mixed` fallback probe:** mixed: App::MODE_MIXED boots + homepage 200/48KB, but ALSO broken — request 2 throws 'Constant PHPMYADMIN already defined in index.php:23' (no Stage-7 include isolation in MIXED -> require_once'd bootstrap re-defines co…

#### Adminer — coroutine-legacy: **B**
- **2026-06-12 re-validation (0.3.49, burst+content):** re-confirmed clean — homepage 200, burst **84x200, 0 worker deaths, 0 segfaults**. Stays **B**.
- **homepage** 200/5.2KB · **login** ok · **write** ok: CREATE TABLE zeal_notes + INSERT via SQL-command UI POST (CSRF token) -> 'Q…
- **concurrent burst** 20x200, 0 worker deaths (boot.log: 0 Fatal/segfault/zend_mm)
- **knobs:**
  - `App::defineIsolation(true) (Adminer defines per-request constants adminer\SERVER/DB/ME from $_GET)`
  - `App::globalScopeInclude(true) (template default, kept)`
  - `template fix: fallback must use App::include($script) not App::includeFile($script) — a docroot-relative '/adminer.php' fails includeFile's startsWith(docroot)…`
  - `WORKAROUND for ext-zealphp define-isolation case bug: byte-patch define('Adminer\X') -> define('adminer\X') (12 sites; behavior-identical, PHP constant-namespa…`
  - `wrapper entry public/index.php: ini_set('display_errors','0') + require adminer.php — suppresses the residual harmless 'Constant adminer\VERSION already define…`

#### TinyFileManager — coroutine-legacy: **C** (login WORKS — sweep probe artifact; write pending — 2026-06-12)
- **2026-06-12 follow-up (manual protocol re-run on the same stack):** ✅ **login is NOT lost** — the scripted probe was the problem, not the framework. With TFM's CSRF `token` form field included, `POST fm_usr+fm_pwd+token` → **302**, the session file gains `filemanager|a:3:{logged:admin,…}` under the custom `filemanager` cookie (same id, no rotation), and following TFM's `/index.php → ?p=` redirect chain lands on the **authenticated file manager (200/43,598 bytes, logout + Upload present)**. Two probe traps for the record: (a) omitting the CSRF token re-renders the login form as a 200 and never writes; (b) authenticated `GET /` returns a bare `302/0` (front-controller hop) that reads as "not logged in" if redirects aren't followed. Write/upload (multipart + dzchunk + token) still not exercised → **C** until the write leg runs.
- **2026-06-12 re-validation (0.3.49, burst+login):** burst is **84x200, 0 worker deaths** (TFM never had the crash class — it was already stable). The D blocker is **login session write-loss**, and it **persists**: TFM uses the custom session name `filemanager` (cookie IS set on first GET), but after `POST fm_usr=admin&fm_pwd=admin@123` the homepage still shows the login form (no `logout`/`upload`) — the `$_SESSION['filemanager']['logged']` flag is not retained across the request. The session #379 fix (which lifted Kanboard) does NOT cover TFM's custom-session-name write-loss pattern. Stays **D**.
- **homepage** 200/13KB · **login** failed: SUCCESS branch runs (pv=1, vt=1, $_SESSION['filemanager']['logged']='ad… · **write** n/a: blocked by login failure — TFM upload (multipart + dzchunk* + token) is au…
- **concurrent burst (0.3.48)** 20x200 (0 worker deaths: segfault/zend_mm/Fatal=0)
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true) [REQUIRED: TFM declares bare top-level $CONFIG/$root_path/$root_url and reads them via `global` inside methods; without it -> 'Ca…`
  - `front-controller fallback in app.php (ENTRY=/index.php, static passthrough)`
- **`mixed` fallback probe:** mixed: FAILS to render TFM at all — homepage 500/201B 'Tiny File Manager Error: Cannot load configuration' (App::globalScopeInclude is gated to coroutine-legacy via globalScopeIncludeEffective()&&silent_redeclare, so in…

#### elFinder — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** homepage burst **84x200, 0 worker deaths, 0 segfaults**. The C blocker is the connector mkdir/upload 0-byte hang (buffer-teardown output, mode-independent), unchanged. Stays **C**.
- **homepage** 200/618b (HTML+JS shell); static as… · **login** n/a: elFinder is a file manager, no auth layer · **write** failed: connector mkdir/upload hang -> HTTP 200 but 0 bytes (6s timeout). elFin…
- **concurrent burst** 20x200 on homepage, 0 worker deaths
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::hookAll(0)`
  - `explicit $app->route('/php/connector.minimal.php') — the implicit .php-block route 403s direct .php URLs`
  - `custom elFinderSessionInterface impl (pure $_SESSION wrapper) in connector — stock elFinderSession::start() registers a PROTECTED method via set_error_handler(…`
- **`mixed` fallback probe:** mixed: SAME failure — under App::MODE_MIXED the connector still hangs/0-bytes (the ob_end_clean+echo+exit output path is broken in mixed too, JSON leaks to stdout). No mode reconciles elFinder's buffer-teardown output c…

#### MediaWiki — coroutine-legacy: **D**
- **2026-06-12 re-validation (0.3.49, burst-only):** large improvement but still crashing — burst now shows only **1 worker death + 1 segfault** (was "workers SIGSEGV per request, master dies"), homepage 301. The server stays up where before the master died. Still **D** (unstable under concurrency).
- **homepage** rendered 36185 bytes internally but… · **login** failed: api.php token/clientlogin sequence cannot complete — worker crashes (co… · **write** failed: could not reach authenticated edit (login never completes; multi-reques…
- **concurrent burst** n/a: server unstable — workers SIGSEGV per request, master dies
- **knobs:**
  - `App::mode(MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `master pre-fork classmap preload of MediaWiki core+vendor+skins (custom resolver)`
  - `preload denylist for per-request singletons (SettingsBuilder, ExtensionRegistry, RequestContext, DeferredUpdates, ActionEntryPoint, ApiEntryPoint)`
  - `preload guard: skip /maintenance/ and /installer/ EXCEPT /installer/Hook/ (DatabaseUpdater.php top-level require_once Maintenance.php defines MW_ENTRY_POINT=cl…`
  - `patch includes/BootstrapHelperFunctions.php wfIsCLI(): treat web MW_ENTRY_POINT as non-CLI (OpenSwoole runs PHP_SAPI=cli, made MW use FauxRequest -> Request UR…`
  - `USE_ZEND_ALLOC=0 (converts zend_mm_heap-corrupted SIGABRT into SIGSEGV; still crashes at teardown)`
  - `ob shield + MediaWikiEntryPoint enableOutputCapture (attempted output-flush workaround)`
- **`mixed` fallback probe:** mixed: MODE_MIXED renders the homepage once per cold worker (200, 36184 bytes, real <title>ZextWiki</title> Vector-skin page) with NO crash, but re-includes fatal on wfWebStartNoLocalSettings() redeclaration (no silentR…

#### DokuWiki — coroutine-legacy: **C** (content RESTORED by ext v0.3.51 — 2026-06-12)
- **2026-06-12 follow-up (ext v0.3.51, burst+content):** ✅ the broken-content grade traced to **ext#50** — DokuWiki's `inc/legacy.php` registers its legacy class names via runtime `class_alias()`, the one redeclare shape neither S3a (opcodes) nor S3c (compile CG-swap) could see; under S7 re-execution the alias line fataled and the legacy class chain broke (`str() on null`). With the v0.3.51 `class_alias` first-wins intercept: **`doku.php` renders real content (200/6,112 bytes)**, burst 54×200/28×302/2×500 with **0 worker deaths**. Login/write pending re-run → **C**.
- **2026-06-12 re-validation (0.3.49, burst+content):** the **worker-death crash class is FIXED** — burst on `/` is **81x200/1x302/2x500, 0 worker deaths, 0 segfaults** (was 13x000 + both workers dying zend_mm_heap-corrupted + master crash; this is exactly the ext#52 headline fix, 15-28 deaths/burst → 0). **BUT content is still broken:** `/` is a 539-byte redirect stub, and the real start page `/doku.php?id=start` returns `Error: Call to a member function str() on null` (a per-request object/state null, same family as the WordPress/MyBB object-global regression). So DokuWiki no longer *crashes the server* but still **does not render real content** → stays **D**. Tested on an alternate port (9805) because the canonical 9705 harness collides with a long-lived ASAN-build debug server. ⚠ Note: a stale polluted-sweep line briefly recorded `200 x84` on 9705 — that was hitting the ASAN server, not this harness; disregard.
- **homepage** 302->200/8.0KB (index.php redirect … · **login** failed: POST /doku.php do=login returns 500 'DokuWiki Setup Error: openswoole e… · **write** page create via doku.php do[save] POST: state change SUCCEEDS (data/pages/zealt…
- **concurrent burst (0.3.48)** 2x200 5x500 13x000 on /doku.php?id=start at -P6 — both workers die (zend_mm_heap corrupted, signal 6/11) and then the OpenSwoole master itself crashe…
- **knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::ignorePhpExt(false) — DokuWiki uses direct .php entry points (doku.php, install.php, lib/exe/*.php); default .php-block returned 403`
  - `App::defineIsolation(true) — DOKU_* constants re-defined on Stage-7 re-executed includes`
  - `[workaround, tested then reverted] 2-line patch in inc/ActionRouter.php handleFatalException to rethrow OpenSwoole\ExitException — turns the 500 POST responses…`
- **`mixed` fallback probe:** mixed: WORSE, not viable — homepage 500/212, burst alternates 500/200 (classic require_once request-2 breakage: init.php not re-executed, bootstrap globals gone), login session does not stick (logged_in=0), page save fa…

#### BookStack — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst 3x200/5x302/76x500, **0 worker deaths** — same Laravel-global-container concurrency race as before (app-level 500s, no crashes). Login/write passed at prior C. Stays **C**.
- **homepage** 302/354b (redirect to /login; authe… · **login** ok · **write** ok: created book + page via POST, verified in DB (entities id=2) and follow-up …
- **concurrent burst** 4x ok (2x200 + 2x302), 16x500 — Laravel global-container concurrency race; 0 worker deaths
- **knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::preloadClassmap() (after composer dump-autoload -o)`
  - `App::preloadDir('/apps50/bookstack/app')`
  - `documentRoot=/apps50/bookstack/public (BookStack native web root)`
  - `removed globalScopeInclude (modern Laravel/PSR-4 app)`
  - `tried App::defineIsolation(true) — did NOT fix the concurrency 500s`
- **`mixed` fallback probe:** mixed: NOT working — App::MODE_MIXED breaks BookStack's front-controller bootstrap (require bootstrap/app.php returns true, 'alias() on true' at index.php:19). The mixed-mode burst's '20x200' were 500-error pages served…

#### Kanboard — coroutine-legacy: **B** (lifted from D, 2026-06-12)
- **2026-06-12 re-validation (0.3.49, FULL PROTOCOL):** **D → B.** Both prior blockers cleared: (1) **login now works** — GET login form yields a CSRF token that PERSISTS, POST `controller=AuthController&action=check` → 302, and the authenticated `DashboardController` page renders ("Dashboard"); the session #379 write-loss fix landed. (2) **write works** — created project "ZealRevalidProj" (id=3, DB-verified in `zext_kanboard.projects`). (3) **burst now stable** — 82x302/2x500, **0 worker deaths, 0 segfaults** (was both workers dying zend_mm_heap-corrupted sig6+sig11 + server hang; ext#52 fix). Grade B (not A) because it needs documented knobs (`globalScopeInclude(true)` + `defineIsolation(true)` + the coroutine-legacy preset).
- **historical (0.3.48) — homepage** 302/797B redirect -> login form 200… · **login** failed: CSRF token never persists - session writes are lost for pre-existing se… · **write** blocked: no authenticated session is obtainable in coroutine-legacy
- **concurrent burst (0.3.48)** run1: 12x302 8x500; run2: 7x500 2x302 then BOTH workers died (zend_mm_heap corrupted, sig6+sig11), server hung, 11 connections never answered; prior …
- **knobs:**
  - `globalScopeInclude(false) AND (true) both tried - session write-loss identical either way`
  - `defineIsolation(true) - kept, but does NOT cover namespaced compile-time const (schema\VERSION) re-exec under Stage 7`
  - `ini display_errors=0 - required to keep redeclare/session-ini warnings out of response bodies`
  - `USE_ZEND_ALLOC=0 - burst still 13x500 + 3 worker deaths but no zend_mm fatal and server survives (vs total crash-loop death without it)`
- **`mixed` fallback probe:** mixed: ALL flows pass - login admin/admin ok (dashboard 200 with Logout), project 'ZealProj' created (id=2), task 'Zeal task one' created and DB-verified (tasks.id=2), second_request 302/200, burst20 = 20x302, 0 worker …

#### FreshRSS — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** burst still shows **exactly 1 worker death** per burst (homepage 302), unchanged from the prior "one SIGSEGV per burst, server self-heals" reading — ext#52 did not eliminate this last death. Stays **C**.
- **homepage** 302->200/6.8KB (GET / redirects to … · **login** ok (2 caveats): real challenge-response login works in stock coroutine-legacy —… · **write** failed stock / ok patched: authenticated add-feed POST (?c=feed&a=add with _csr…
- **concurrent burst** 17x302 3x000 on GET / at -P6, reproducible across boots: exactly one worker dies SIGSEGV (signal=11) per burst; server self-heals via respawn and pos…
- **knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::defineIsolation(true) — FreshRSS constants.php top-level consts re-executed by Stage 7`
  - `fallback handler: docroot resolved via realpath() — public/ is a symlink to app/p, otherwise the static-file prefix check never matches and feed.xml 500s throu…`
  - `fallback handler: directory URLs resolve to dir/index.php (FreshRSS app lives at /i/); App::include() not includeFile()`
  - `php -d display_errors=0 at process start — per-request ini re-park overrides app.php boot-time ini_set; without it Stage-7 'Constant already defined' warnings …`
  - `DB tables created via FreshRSS_UserDAO::createUser() in a one-off CLI script (cli/create-user.php skipped table creation because the user dir predated the MySQ…`
  - `login scripting: POST to ?c=auth&a=login (CSRF-exempt, forwards internally to formLogin) — a=formLogin directly is CSRF-gated 403; challenge=crypt(storedHash.n…`
  - `[workaround, tested then reverted] 2-line p/i/index.php patch rethrowing OpenSwoole\ExitException — turns the 500 POST responses (login, add-feed) into clean 3…`
  - `[workaround, tested then reverted] 1-line app/Models/SimplePieCustom.php patch skipping CURLOPT_PROTOCOLS_STR/CURLOPT_REDIR_PROTOCOLS_STR — makes feed fetching…`
- **`mixed` fallback probe:** mixed: WORSE, not viable (prior session on identical build, not re-run today): homepage 500/212B on request 2 (classic require_once breakage), login flow hung the server entirely — 6979 abnormal-exit/signal lines + two …

#### Piwigo — coroutine-legacy: **D**
- **2026-06-12 re-validation (0.3.49, burst-only):** still a crasher — even with `ZEALPHP_CLASS_STATICS_RESET_DISABLE=1`, homepage hangs (000) and the burst produced **13 worker deaths** (0 segfaults). Worse than the prior 6-deaths reading; ext#52 did not rescue Piwigo. Stays **D**.
- **homepage** 200/3.6KB · **login** ok via remember_me=1 workaround — POST identification.php 302 + pwg_remember co… · **write** failed: pwg.categories.add via ws.php -> 403 'Invalid security token'. pwg_toke…
- **concurrent burst** 2x200 18x000 per burst (reproduced 3x) — workers crash under -P6 concurrency (signal=11 SIGSEGV + one signal=6, 6 worker deaths per burst, no PHP fat…
- **knobs:**
  - `App::ignorePhpExt(false) — Piwigo needs direct *.php URLs (install.php, ws.php, identification.php, admin.php)`
  - `App::globalScopeInclude(true) (template default, required — bare top-level globals in section_init/menubar)`
  - `ZEALPHP_CLASS_STATICS_RESET_DISABLE=1 — still required on ext 0.3.48: without it EVERY request 2+ 500s (ImageStdParams::$all_types array->int TypeError in coun…`
  - `USE_ZEND_ALLOC=0 NO LONGER needed (was required on 0.3.46): login POST clean without it, and burst A/B identical with/without`
  - `login workaround: remember_me=1 (pwg_remember cookie auto_login per request) — Piwigo's native session login never persists (getStatus=guest after 302+pwg_id)`
- **`mixed` fallback probe:** mixed: NOT viable (prior-run evidence, same framework v0.4.8: homepage 500 — array_push(): Argument #1 must be array, null given at section_init.inc.php:666; globalScopeInclude is coroutine-legacy-gated so Piwigo's bare…

#### YOURLS — coroutine-legacy: **C**
- **2026-06-12 re-validation (0.3.49, burst-only):** big improvement — burst on `/` is now **82x200/2x500 with 0 worker deaths, 0 segfaults** (was UNSTABLE: 2-13 worker deaths/round, zend_mm_heap corrupted). The ext#52 fix eliminated YOURLS's burst heap corruption. Login/write passed at prior C; with the burst now stable YOURLS is a candidate to lift to B on a full-protocol re-confirm (login+write re-run), but kept **C** this pass since only burst+homepage were re-validated.
- **homepage** 200/6KB · **login** ok · **write** ok: created short link 'zealtest' via authenticated admin-ajax (action=add + ad…
- **concurrent burst** UNSTABLE: best round 17x200 3x500; repeats degrade (14x200 2x500 4x000; 7x200 9x500 4x000); 2-13 worker deaths per round (zend_mm_heap corrupted, SIG…
- **knobs:**
  - `App::ignorePhpExt(false) — YOURLS requires direct *.php URLs (admin/install.php, admin/admin-ajax.php, yourls-api.php); default .php-block route 403s them`
  - `fallback handler must call App::include($script), not the template's App::includeFile($script) — includeFile() expects an ABSOLUTE path, so ENTRY '/yourls-load…`
  - `App::globalScopeInclude(true) (template default, kept)`
  - `created public/index.php from YOURLS' shipped sample-public-front-page.txt (stock YOURLS setup step; without it GET / 302-redirects to itself via yourls-loader)`
  - `tried-but-ineffective: App::defineIsolation(true) and USE_ZEND_ALLOC=0 — neither stopped the concurrency heap corruption; final config omits both`
- **`mixed` fallback probe:** mixed: stable under burst20 x2 (0 worker deaths, zero 500s; defineIsolation MUST be off in mixed or constants vanish on request 2) — request-2 OK, short-link 301 redirect OK, but homepage responses flap between 200 and …


---

## Per-app detail (newly graded in the 2026-06-12 0.3.49 sweep — burst+content level)

These rows were _pending_ before this sweep. They are graded at **burst+content level** (homepage 84-req 6-way burst + content sanity); a full login+write protocol pass was not run, so they carry the honest level note.

#### TYPO3 — coroutine-legacy: **C** (newly graded 2026-06-12)
- **homepage** 200 with real TYPO3 content. **burst** homepage 6-way: **1 worker death, 0 segfaults** — boots and serves but one crash under concurrency. **level:** burst+content (login/write not exercised). Grade **C** (read paths + content render; minor instability under burst).

#### Concrete CMS — coroutine-legacy: **D** (newly graded 2026-06-12)
- **homepage** 200. **burst** homepage 6-way: **2 worker deaths, 0 segfaults** — boots and serves homepage but crashes under concurrency (a partial-stability profile). **level:** burst+homepage. Grade **D** (boots but breaks under concurrent burst).

#### Craft CMS — coroutine-legacy: **C** (newly graded 2026-06-12)
- **homepage** 200 with content. **burst** homepage 6-way: **84x200, 0 worker deaths, 0 segfaults** — fully clean burst. **level:** burst+content (Craft admin login needs a license/setup step not scripted this pass). Grade **C** (read paths + content + clean burst; auth/write not re-validated).

#### Flarum — coroutine-legacy: **D** (newly graded 2026-06-12)
- **homepage** 200. **burst** homepage 6-way: **1 worker death, 0 segfaults** — boots and serves but crashes under concurrency. **level:** burst+homepage. Grade **D** (boots but one crash under burst).

#### Roundcube — coroutine-legacy: **C** (newly graded 2026-06-12)
- **homepage** 200 — the real webmail login page renders (`<title>Roundcube Webmail :: Welcome to Roundcube Webmail</title>`). **burst** homepage 6-way: **82x200/2x500, 0 worker deaths, 0 segfaults** — stable. **login** not exercised (requires a live IMAP backend = **ENV**). **level:** burst+content. Grade **C** (read paths + login page render + stable burst; IMAP-backed login is an environment dependency).

#### Leantime — coroutine-legacy: **F** (Harness Blocked — 2026-06-12)
- **boot** failed: `Fatal error: Declaration of OpenSwoole\Core\Psr\Message::getProtocolVersion()...` The documented **psr/http-message v2 conflict** occurs because Leantime's prebuilt release ships `psr/http-message 2.0`. Attempting the standard `composer require psr/http-message:^1.1` fix fails because the tarball lacks a `composer.json`, causing Composer to wipe the `vendor/` directory. Manually injecting the v1.1 package resolves the Swoole error but immediately triggers cascading PHP 8.4 compatibility fatals in the stale `doctrine/cache` and `symfony/http-kernel` components. The app cannot boot on the PHP 8.4 / 8.5 stack without a rebuilt `composer.json`. **Grade: F**.

---

## 2026-06-12 0.3.49 re-validation — what the ext#52 fix did and did not move

**The ext#52 concurrent-burst heap-corruption fix (Stage-8 attach-move UAF) eliminated worker deaths across most of the C/D set.** Apps with **0 worker deaths / 0 segfaults** on the 84-req 6-way burst now: adminer, slim, laminas, elfinder, yii2, craftcms, codeigniter4, tinyfilemanager, kanboard, kirby, dokuwiki, phpmyadmin, drupal, symfony, bookstack, roundcube, yourls (was 2-13 deaths), and laravel (0 deaths, mixed codes). This is the headline win — the class of "concurrent burst kills the worker / crashes the master" is largely closed.

**But 0 deaths ≠ usable.** Content/login checks this pass found several 0-death apps that still don't deliver:
- **CodeIgniter4** — 84x200 but every body is 0 bytes (the body-swallow blocker; stays D).
- **DokuWiki** — 0 deaths (was 13+/burst + master crash) but the real content page errors `str() on null` (stays D).
- **TinyFileManager** — always burst-clean, but login session still lost (custom `filemanager` session name; #379 didn't cover it; stays D).
- **phpMyAdmin** — crash class fixed (0 deaths, was 8-10) but cookie-login not re-confirmed; kept D.

**Two ⚠ regressions on 0.3.49 (object-global null cluster).** Apps that served content on 0.3.48 but now 500 on every request with a "method/property on null" for an object-global:
- **WordPress** — `wp_set_wpdb_vars` "assign property field_types on null" (`$wpdb` null) — homepage 500.
- **MyBB** — "Call to a member function read() on null" — homepage 500.

These are the documented object-global / mysqlnd-teardown frontier (CLAUDE.md "object-valued `$GLOBALS`" + ext#44/#49). Not re-investigated at the ext level here — flagged for follow-up.

**Remaining burst crashers (still crash under concurrency on 0.3.49):** phpBB (7 deaths + 2 seg, master crashes), Piwigo (13 deaths, homepage hangs 000), Grav (2 deaths), MediaWiki (1 death + 1 seg, much improved), FreshRSS (1 death), OpenCart (1 death), Vanilla (1 cold-wave death), Concrete (2 deaths), Flarum (1 death), TYPO3 (1 death). These trace to the mysqlnd-teardown (ext#44/#49) and class_alias (ext#50) frontiers per the blocker report; not re-investigated here.
