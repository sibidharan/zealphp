# Compatibility Database

> Last updated: 2026-06-11

How 50 popular PHP apps run on ZealPHP, **graded in `coroutine-legacy` mode** — the headline mode for legacy apps ("old PHP just works, concurrently"). Every graded row is a **real install**: real database, real authenticated login, real write operation, and a 6-way concurrent burst — tested individually on the current stack (**ZealPHP v0.4.8 + ext-zealphp v0.3.48**, PHP 8.4.21, OpenSwoole 26.2.0).

This database was **rebuilt from scratch on 2026-06-11**. It carries **only** current-stack results — no historical grades, no superseded mode numbering. Apps not yet re-validated on the current stack are marked _pending_ (re-validation in progress); they carry **no** grade rather than a stale one.

**Status: 26/50 re-validated** (B: 4 · C: 11 · D: 11); 24 pending.

---

## Mode reference

The framework's only mode API is `App::mode()` with four string presets — there are no mode numbers.

| Preset | `App::mode(...)` | Concurrency | Role |
|--------|------------------|-------------|------|
| **`coroutine-legacy`** | `App::MODE_COROUTINE_LEGACY` | full coroutine | **The headline mode.** Unmodified request-style PHP (superglobals, `$_SESSION`, `exit`/`die`, `require_once`) under coroutine concurrency, with per-coroutine isolation of superglobals, `$GLOBALS`, function statics, constants, and `require_once` re-execution. Requires ext-zealphp. |
| **`coroutine`** | `App::MODE_COROUTINE` | full coroutine | Native ZealPHP — uses `$g->get`/`$g->post` instead of superglobals. Highest performance; the rewrite target. |
| **`mixed`** | `App::MODE_MIXED` | sequential / worker | **Sequential fallback.** In-process, superglobals populated per request, no coroutine concurrency. For apps that can't yet run concurrently. |
| **`legacy-cgi`** | `App::MODE_LEGACY_CGI` | sequential / subprocess | **Compatibility floor.** Per-request subprocess (Apache mod_php parity). For pure-`require_once` apps with no autoloader. |

`mixed` and `legacy-cgi` are **fallbacks**, used only when an app can't run under `coroutine-legacy` yet.

## Grading scale (coroutine-legacy)

| Grade | Meaning |
|-------|---------|
| **A** | All flows pass (homepage, login, write, concurrent burst) with only DB/config setup. |
| **B** | All flows pass, but needed documented knobs (`globalScopeInclude`, `ignorePhpExt`, `preloadClassmap`, …) or minor workarounds. |
| **C** | Read paths + login work; a real-work flow (write, or stability under concurrent burst) fails — documented. |
| **D** | Boots but has major breakage; usable only via a sequential fallback. |
| **F** | Cannot boot/serve in coroutine-legacy. |
| **ENV** | A missing environment dependency (search engine, IMAP, license key) blocked full testing. |

## Summary

| # | App | Category | Stars | Framework | coroutine-legacy | Knobs / notes |
|---|-----|----------|-------|-----------|:---:|---------------|
| 1 | WordPress | CMS | 19k | Custom | **C** | App::mode(App::MODE_COROUTINE_LEGACY) + App::globalScopeInclude(true) (template… |
| 2 | Drupal | CMS | 4.3k | Custom | **D** | App::preloadClassmap() after composer dump-autoload -o (template's globalScopeI… |
| 3 | Joomla | CMS | 4.7k | Custom | _pending_ | re-validation in progress |
| 4 | TYPO3 | CMS | 1.0k | Symfony | _pending_ | re-validation in progress |
| 5 | Concrete CMS | CMS | 768 | Custom | _pending_ | re-validation in progress |
| 6 | October CMS | CMS | 11k | Laravel | _pending_ | re-validation in progress |
| 7 | Craft CMS | CMS | 3.1k | Yii-based | _pending_ | re-validation in progress |
| 8 | Grav | CMS | 14k | Custom | **D** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) |
| 9 | Kirby | CMS | 7.5k | Custom | **C** | define('KIRBY_HELPER_GO', false) before loading Kirby — OpenSwoole's go() short… |
| 10 | Statamic | CMS | 3.9k | Laravel | _pending_ | re-validation in progress |
| 11 | Bagisto | E-comm | 15k | Laravel | _pending_ | re-validation in progress |
| 12 | Magento 2 | E-comm | 11k | Custom | _pending_ | re-validation in progress |
| 13 | WooCommerce | E-comm | 9.6k | WordPress | _pending_ | re-validation in progress |
| 14 | PrestaShop | E-comm | 7.8k | Custom | _pending_ | re-validation in progress |
| 15 | OpenCart | E-comm | 7.3k | Custom | **C** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) |
| 16 | Sylius | E-comm | 7.7k | Symfony | _pending_ | re-validation in progress |
| 17 | Flarum | Forums | 15k | Custom | _pending_ | re-validation in progress |
| 18 | phpBB | Forums | 1.8k | Custom | **C** | App::globalScopeInclude(true) (template default, kept); App::ignorePhpExt(false… |
| 19 | MyBB | Forums | 2.9k | Custom | **C** | App::ignorePhpExt(false) — REQUIRED: MyBB is multi-entry (*.php URLs); default … |
| 20 | Vanilla Forums | Forums | 2.9k | Custom | **B** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) |
| 21 | Laravel | Framework | 79k | Self | **B** | App::preloadClassmap() + require app vendor/autoload.php in master (template gu… |
| 22 | Symfony | Framework | 30k | Self | **D** | App::preloadClassmap() + require app vendor/autoload.php in master (template gu… |
| 23 | CodeIgniter 4 | Framework | 5.3k | Self | **D** | CI_ENVIRONMENT=production (development mode fatals: Kint/Debug-Toolbar 'Cannot … |
| 24 | CakePHP | Framework | 8.7k | Self | **D** | composer require psr/http-message:^1.1 — skeleton vendor ships 2.0 whose typed … |
| 25 | Slim | Framework | 12k | Self | **B** | App::preloadClassmap() + require /apps50/slim/vendor/autoload.php in master (co… |
| 26 | Yii 2 | Framework | 14k | Self | **C** | composer install --no-dev + composer dump-autoload -o (dev deps pull psr/http-m… |
| 27 | Laminas | Framework | 5.1k | Self | **C** | App::mode(App::MODE_COROUTINE_LEGACY); App::preloadClassmap() (warms ZealPHP ow… |
| 28 | phpMyAdmin | Admin | 7.2k | Custom | **D** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) [template … |
| 29 | Adminer | Admin | 6.1k | Custom | **B** | App::defineIsolation(true) (Adminer defines per-request constants adminer\SERVE… |
| 30 | TinyFileManager | Admin | 6.2k | Custom | **D** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) [REQUIRED:… |
| 31 | Roundcube | Admin | 6.0k | Custom | _pending_ | re-validation in progress |
| 32 | FileGator | Admin | 1.8k | Vue+PHP | _pending_ | re-validation in progress |
| 33 | elFinder | Admin | 3.0k | Custom | **C** | App::mode(App::MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) |
| 34 | MediaWiki | Wiki | 3.7k | Custom | **D** | App::mode(MODE_COROUTINE_LEGACY); App::globalScopeInclude(true) |
| 35 | DokuWiki | Wiki | 4.1k | Custom | **D** | App::globalScopeInclude(true) (template default, kept); App::ignorePhpExt(false… |
| 36 | BookStack | Wiki | 16k | Laravel | **C** | App::mode(App::MODE_COROUTINE_LEGACY); App::preloadClassmap() (after composer d… |
| 37 | Kanboard | Business | 8.4k | Custom | **D** | globalScopeInclude(false) AND (true) both tried - session write-loss identical … |
| 38 | Invoice Ninja | Business | 8.3k | Laravel | _pending_ | re-validation in progress |
| 39 | Leantime | Business | 4.1k | Custom | _pending_ | re-validation in progress |
| 40 | Monica CRM | Business | 22k | Laravel | _pending_ | re-validation in progress |
| 41 | Crater | Business | 8.2k | Laravel | _pending_ | re-validation in progress |
| 42 | Matomo | Analytics | 19k | Custom | _pending_ | re-validation in progress |
| 43 | Cacti | Analytics | 1.5k | Custom | _pending_ | re-validation in progress |
| 44 | LibreNMS | Analytics | 3.9k | Laravel | _pending_ | re-validation in progress |
| 45 | FreshRSS | Content | 10k | Custom | **C** | App::globalScopeInclude(true) (template default, kept); App::defineIsolation(tr… |
| 46 | Piwigo | Content | 3.1k | Custom | **D** | App::ignorePhpExt(false) — Piwigo needs direct *.php URLs (install.php, ws.php,… |
| 47 | Lychee | Content | 13k | Laravel | _pending_ | re-validation in progress |
| 48 | Wallabag | Content | 10k | Symfony | _pending_ | re-validation in progress |
| 49 | Nextcloud | Utility | 27k | Custom | _pending_ | re-validation in progress |
| 50 | YOURLS | Utility | 10k | Custom | **C** | App::ignorePhpExt(false) — YOURLS requires direct *.php URLs (admin/install.php… |

> Failure classes (root causes + fix status) are catalogued in [`docs/architecture/2026-06-11-coroutine-legacy-blocker-report.md`](architecture/2026-06-11-coroutine-legacy-blocker-report.md). Several blockers found during this sweep are already fixed on the current stack (Stage-8 object-store corruption → ext 0.3.47; `exit()`/`die()` swallow → ext 0.3.48; session persistence → #379; ZealAPI scope → #376), so _pending_ rows are expected to grade higher than the first-pass results.

---

## Per-app detail (re-validated)

#### WordPress — **C**
- **homepage:** 200/69KB · **login:** ok (wp-login.php POST -> wp-admin Dashboard 200; session persists across requests and ser… · **write:** ok: POST rest_route=/wp/v2/posts -> 201, post id 6 'ZealPHP coroutine-legacy test post' p…
- **concurrent burst:** unstable: warm bursts 6-8x200 + 7-9x500 + 1-2x302 + 3-4x000(hung); 11 worker SIGSEGVs across 2 bursts (~5/burst, self-healing respawn); best observed…
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY) + App::globalScopeInclude(true) (template default; required for wp-admin)`
  - `App::ignorePhpExt(false) — required so wp-login.php / wp-admin/*.php URLs are served instead of the framework's implicit .php 403 block`
- **Sequential fallback:** mixed: homepage 200/69KB and burst20 = 20x200 with 0 worker deaths (fully stable under concurrency); but /wp-admin/ returned 302 (admin session not re-validated under mixed) and REST write 403 (nonce unobtainable withou…

#### Drupal — **D**
- **homepage:** 200/21KB (15.7KB after big_pipe uninsta… · **login:** failed: credentials accepted (POST /user/login -> 303 /user/1) but session never persists… · **write:** failed: GET/POST /node/add/page -> 403 Access denied (blocked on the login failure)
- **concurrent burst:** concurrent -P6: 20x500; sequential -P1: 20x200. 0 segfault/zend_mm/Fatal patterns in boot.log (workers survive; the 500s are clean Drupal exception p…
- **coroutine-legacy knobs:**
  - `App::preloadClassmap() after composer dump-autoload -o (template's globalScopeInclude removed)`
  - `SessionStartMiddleware (first-visit PHPSESSID cookie)`
  - `request-side cookie mirror PHPSESSID -> SESS<sha256(host)[0:32]> in front controller (Drupal cookie-auth applies())`
  - `web/index.php: `require_once autoload.php` -> `require` (Stage 7 re-execution)`
  - `drush pmu big_pipe (removed BigPipeHooks getOption()-on-null 500s; did NOT fix the concurrency 500s)`
- **Sequential fallback:** mixed: boots, homepage 200, but login fails IDENTICALLY (303 then anonymous on next request; node/add 403) — the Drupal session-handler bypass is mode-independent, so MODE_MIXED does not rescue auth/write flows either

#### Grav — **D**
- **homepage:** 200/13.5KB · **login:** failed: login POST succeeds (303, writes session) but the immediately-following authentic… · **write:** n/a: admin dashboard 500s on every authenticated request, so no page-create possible in c…
- **concurrent burst:** 1-2x200 then 16-17x500 + worker SIGSEGV (signal=11); reproducible under 6-way concurrency even after sequential warmup. An earlier warm-sequentialize…
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `putenv GRAV_ROOT / GRAV_WEBROOT before boot`
  - `define('PAGE_ORDER_PREFIX_REGEX') at boot (master) to survive per-request constant reset`
  - `ini_set display_errors=0 to suppress benign re-define warnings`
  - `zeal-entry.php wrapper to collapse Grav's open output buffers (register_shutdown_function never fires on long-lived worker)`
  - `defineIsolation deliberately LEFT OFF (poisons Grav: GRAV_WEBROOT read back as YAML_EXT value on request 2)`
- **Sequential fallback:** mixed: App::MODE_MIXED FIXES the session-decode 500 (traditional SessionManager round-trips Grav's Messages object natively) — public homepage stays 200 across repeated cookied requests r1/r2/r3. BUT /admin/ then breaks…

#### Kirby — **C**
- **homepage:** 200/9843B · **login:** ok — GET /panel/login 200/162KB (kirby_session cookie + 64-char csrf from panel HTML), PO… · **write:** ok — POST /api/site/children created page 'zeal-revalid-1781185914' (200), PATCH /api/pag…
- **concurrent burst:** / : 9x200 11x500; /notes : 3x200 17x500 (xargs -P 6). 0 segfault/zend_mm/Fatal error/deadlock in boot.log this run; worker self-recovers — post-burst…
- **coroutine-legacy knobs:**
  - `define('KIRBY_HELPER_GO', false) before loading Kirby — OpenSwoole's go() shortname collides with Kirby's go() helper ('Cannot redeclare function go() in kirby…`
  - `explicit catch-all patternRoute('#^/(?P<zealpath>.*)$#', all methods) registered before run() — Kirby routes every path virtually; ZealPHP's implicit /{file} +…`
  - `use App::include($script) NOT App::includeFile($script) in the catch-all — includeFile() treats the docroot-relative '/index.php' as an outside-docroot ABSOLUT…`
  - `require app vendor/autoload.php + App::preloadClassmap() at boot — REQUIRED: without it the per-request class-static reset breaks Kirby on request 2+ even sequ…`
- **Sequential fallback:** mixed: same install + same app.php with App::mode(App::MODE_MIXED) (app_mixed.php) — burst20 on / AND /notes both 20x200, 0 fatals/segfaults in boot_mixed.log; re-confirmed in this 2026-06-11 run

#### OpenCart — **C**
- **homepage:** 200/34KB · **login:** ok · **write:** ok: created category via admin POST catalog/category.save -> {category_id:59} and {catego…
- **concurrent burst:** 16x200 4x000 (at -P6 vs 4 workers); 2 worker SIGSEGV (signal=11) + 1 coroutine deadlock during burst, server self-healed (respawned, port stayed up, …
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::defineIsolation(true) - REQUIRED: OpenCart define('VERSION',...) collides across requests; without it the admin path breaks (proven in MIXED-mode fallback…`
  - `explicit routes for /index.php, /admin/index.php, /admin, /admin/ registered before run() - REQUIRED: ZealPHP's implicit .php-extension block returns 403, and …`
  - `worker_num=4 (raised from template 2)`
- **Sequential fallback:** mixed: MODE_MIXED storefront burst 40/40 x200, 0 worker deaths, 0 segfaults (sequential-per-worker eliminates the concurrent-mysqli crash) - BUT MODE_MIXED lacks defineIsolation so OpenCart's define('VERSION') collides …

#### phpBB — **C**
- **homepage:** 200/13.8KB (forum index 'ZealPHP Test B… · **login:** ok (POST /ucp.php?mode=login with creation_time+form_token+sid -> 302; mode=logout link p… · **write:** ok: new topic via POST /posting.php?mode=post&f=2 -> 302 to /viewtopic.php?t=3; verified …
- **concurrent burst:** FAIL on ext 0.3.47: round1 19x500 1x000, round2 10x500 9x000 1x200; zend_mm_heap corrupted + SIGSEGV(11)/SIGABRT(6) worker deaths each burst; even 3-…
- **coroutine-legacy knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::ignorePhpExt(false) — phpBB lives on /ucp.php-style URLs; without it every .php URL 404s`
  - `master pre-fork warmup: require phpBB3/vendor/autoload.php + register \phpbb\class_loader + App::preloadDir(phpBB3/phpbb) — phpbb\* is NOT in the composer clas…`
  - `define('PHPBB_ROOT_PATH', abs path) — pins phpBB's CWD-relative $phpbb_root_path`
  - `app-level prerequisite (not a ZealPHP knob): rename phpBB3/install/ after install or all pages render 'board currently unavailable'`
  - `tried+rejected (prior session, same install): App::preloadClassmap() (force-loads symfony proxy-manager-bridge V1 compat shim -> boot fatal 'Declaration ... mu…`
- **Sequential fallback:** legacy-cgi: FULL PASS re-verified on this run — homepage 200/13.3KB, burst20 = 20x200, 0 worker deaths, 0 corruption lines (app_cgi.php kept beside app.php). mixed: unusable (class redeclare fatals).

#### MyBB — **C**
- **homepage:** 200/13.5KB · **login:** ok (intermittent): succeeds with mybbuser cookie + User Control Panel, but ~1-in-8 isolat… · **write:** ok: authenticated newthread.php form POST created thread tid=1 'ZealPHP coroutine-legacy …
- **concurrent burst:** best (USE_ZEND_ALLOC=0 + defineIsolation): 14x200 1x500 5x000(conn refused), post-burst alive 200, 2 worker deaths (zend_mm_heap corrupted sig6 + mys…
- **coroutine-legacy knobs:**
  - `App::ignorePhpExt(false) — REQUIRED: MyBB is multi-entry (*.php URLs); default blocks them with 403, including install/index.php`
  - `App::globalScopeInclude(true) — template default kept`
  - `App::defineIsolation(true) — added for MyBB's per-request define()s (TIME_NOW/THIS_SCRIPT freeze: identical mybb[lastvisit] cookie values served minutes apart …`
  - `USE_ZEND_ALLOC=0 env — with it the master survives worker crashes (self-healing respawn); without it the master died twice (full outage)`
- **Sequential fallback:** mixed: NOT usable — App::mode(App::MODE_MIXED) served 500 on EVERY request including request 1 and all of burst20 (0 crashes though); MyBB's require_once bootstrap never re-executes without Stage 7, so per-request state…

#### Vanilla Forums — **B**
- **homepage:** 200/52KB · **login:** ok · **write:** ok: created discussion #5 (admin, CategoryID=1), verified via GET /discussion/5 (200/54KB…
- **concurrent burst:** cold-concurrent first wave ~6-10x500 then 200s (workers survive, 0 crashes); warm steady-state 20x200 repeatable across 3 rounds, 0 worker deaths
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::ignorePhpExt(false)`
  - `App::$cwd + documentRoot set to /apps50/vanilla/public (Vanilla PATH_ROOT=getcwd())`
  - `fallback sets $_GET['p']=clean-path so /categories /discussions route via Gdn_Request UrlFormat`
  - `PATCH: garden-container Container.php — array_values() on 7 reflection-invoke sites (PHP8 named-param fatal $defaultgroup)`
  - `PATCH: Vanilla\Models\ModelCache.php — hydrate() called with no args (PHP8 named-param fatal $junctionExclusions)`
  - `manual conf/config.php (installer signed in admin + built all DB tables but never persisted config.php to disk under coroutine-legacy)`
- **Sequential fallback:** mixed: App::MODE_MIXED runs every failing flow clean — homepage 200, post-form request-2 200 (no Smarty fatal), burst20 20x200, 0 worker deaths. Sequential per-worker handling avoids the coroutine-concurrency state corr…

#### Laravel — **B**
- **homepage:** 200/70KB · **login:** ok · **write:** ok: POST /tasks (CSRF + auth session) created row, verified via GET /tasks + SELECT in ze…
- **concurrent burst:** 20x200 (two clean consecutive runs, 0 worker deaths/segfaults). Later re-runs degraded to 500s solely from shared-MariaDB exhaustion: SQLSTATE[08004]…
- **coroutine-legacy knobs:**
  - `App::preloadClassmap() + require app vendor/autoload.php in master (template guidance for composer apps; globalScopeInclude removed)`
  - `composer require psr/http-message:^1.1 — Laravel vendor ships psr/http-message v2 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes (autolo…`
  - `composer install --no-dev + dump-autoload -o --no-dev — dev-only classes (mockery PHPUnit TestListener trait, faker) hard-fatal the classmap warm (trait-not-fo…`
  - `composer.json exclude-from-classmap: vendor/psy/psysh/ + vendor/symfony/*/DependencyInjection/ — psysh Hoa polyfills (incompatible signatures) and symfony opti…`
  - `app.php fallback must use App::include('/index.php') NOT App::includeFile() — _template.php bug: includeFile takes an ABSOLUTE path, so '/index.php' resolves a…`
  - `explicit catch-all $app->patternRoute('#^/.*$#', ...) -> front controller so non-/ URLs route to Laravel (implicit /{file} routes intercept single-segment path…`
  - `public/index.php: '$app = require_once bootstrap/app.php' -> plain 'require' (1 line). With require_once, concurrent bursts raced Stage 7's per-request EG(incl…`
  - `public/index.php: defined('LARAVEL_START') || define(...) guard — the constant persists per worker and the bare define() echoed an 'already defined' warning in…`
- **Sequential fallback:** mixed: full suite also passes (homepage 200/70KB, session counter, login ok, POST /tasks write ok, burst20 20x200, 0 fatals) — needs the same index.php require_once->require tweak; app_mixed.php left in tree

#### Symfony — **D**
- **homepage:** 200/18432B · **login:** failed: credentials+CSRF accepted (POST /en/login -> 302 to /en/admin/post/) but the secu… · **write:** blocked in coroutine-legacy (needs login). Proven under mixed fallback: POST /en/admin/po…
- **concurrent burst:** 20x200 (coroutine-legacy), grep -ciE 'segfault|zend_mm|core dump|Fatal error' boot log = 0; mixed burst also 20x200
- **coroutine-legacy knobs:**
  - `App::preloadClassmap() + require app vendor/autoload.php in master (template guidance for composer apps; globalScopeInclude removed)`
  - `composer require psr/http-message:^1.1 — demo vendor ships psr/http-message 2.0 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes`
  - `composer install --no-dev + dump-autoload -o --no-dev — phpstan-doctrine's MappingDriverChain has an incompatible-declaration CompileError that hard-fatals the…`
  - `rm -rf var/cache/prod + cache:warmup AFTER --no-dev — container compiled with dev deps wires Twig profiler against symfony/stopwatch -> 'Class Stopwatch not fo…`
  - `public/index.php replaced with classic front controller (require autoload, new Kernel, handle/send/terminate; original at index.php.orig) — symfony/runtime's a…`
  - `APP_SECRET must be set in .env.local — create-project leaves it empty; doctrine console + login SignatureHasher throw 'A non-empty secret is required'`
  - `explicit catch-all $app->patternRoute('#^/.*$#', ...) -> front controller (implicit /{file} routes intercept single-segment paths)`
  - `curl-only note, not a ZealPHP knob: Symfony 8 stateless CSRF (csrf-token placeholder) validates via same-origin — POSTs need Origin: + Referer: headers, _csrf_…`
  - `FAILED knobs for the login flow: App::sessionLifecycle(false) (zeal_session_write_close then persists NOTHING since $g->session is never set); security.session…`
- **Sequential fallback:** mixed: FULL suite passes with STOCK security config and zero extra knobs beyond the composer-app set — login ok (302 -> /en/admin/post/, admin Post List 200), admin post create ok (id=31, verified admin list + public sl…

#### CodeIgniter 4 — **D**
- **homepage:** 200/0KB - CI4 headers (Cache-Control, c… · **login:** n/a: appstarter ships no auth; custom session route used instead - 200/0b, body+Set-Cooki… · **write:** failed: POST /demo/notes/add -> 200/0b AND row never reaches MariaDB (notes count stays 0…
- **concurrent burst:** 20x200 (all 0-byte bodies); 0 segfault/zend_mm/fatal during burst; one earlier 'all coroutines asleep - deadlock' fatal at worker recycle
- **coroutine-legacy knobs:**
  - `CI_ENVIRONMENT=production (development mode fatals: Kint/Debug-Toolbar 'Cannot use a scalar value as an array' in ThirdParty/Kint/CallFinder.php:186; TypeError…`
  - `app.php: function is_cli(): bool { return false; } (CI4 sees PHP_SAPI=cli in OpenSwoole workers -> builds CLIRequest -> TypeError array_shift(null) in HTTP/CLI…`
  - `app.php: require APP_DIR/vendor/autoload.php before App::preloadClassmap() (preloadClassmap only warms REGISTERED composer loaders; warmed 1062 symbols)`
  - `public/index.php: replaced exit(Boot::bootWeb($paths)) with plain call (did not fix body swallow)`
  - `public/index.php: cleared G::instance()->shutdown_functions (CI4 Exceptions::shutdownHandler exits -> zeal log 'shutdown function threw: openswoole exit'; did …`
  - `public/index.php: header_remove('Content-Length') (did not fix)`
  - `MODE_MIXED only: require_once for Paths.php/Boot.php + defined() guard on FCPATH (plain require refataled 'Cannot redeclare class Config\Paths' every request)`
- **Sequential fallback:** mixed: boots + burst20 20x200 + zero fatals after require_once/FCPATH patches, but the SAME 0-byte body swallow on every CI4 page - fallback does not make the app usable either

#### CakePHP — **D**
- **homepage:** 200/7.5KB · **login:** n/a: cakephp/app skeleton ships no auth plugin; per install hint verified session flash i… · **write:** ok: POST /posts/add (real 152-char _csrfToken from form + cookie jar) -> 302 Location /po…
- **concurrent burst:** FAILS HARD: surviving bursts ~35-50% 500 (13x200 6x500 1x404); on BOTH 2026-06-11 re-validation runs the server then WEDGED PERMANENTLY mid-burst — w…
- **coroutine-legacy knobs:**
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
- **Sequential fallback:** mixed: FULL suite passes — homepage 200/7.5KB, GET /posts/add CSRF form 200, POST 302 + row in DB, flash message 'The post has been saved' RENDERS (session flash works), second requests 200, burst20 = 20x200 twice, 0 fa…

#### Slim — **B**
- **homepage:** 200/12B (Hello world!); GET /users 200/… · **login:** ok: POST /login user=admin/pass=secret -> {"login":"ok","user":"admin"}; $_SESSION['user'… · **write:** ok: POST /tasks (session-authenticated) -> INSERT into MySQL zext_slim.tasks; verified vi…
- **concurrent burst:** 20x200 (two consecutive runs) + auth burst 10x200 GET /tasks + 5x200 POST /tasks; boot.log new-fatal grep during bursts = 0. The single 'all coroutin…
- **coroutine-legacy knobs:**
  - `App::preloadClassmap() + require /apps50/slim/vendor/autoload.php in master (composer-app pattern; globalScopeInclude removed — it did NOT help session persist…`
  - `composer require psr/http-message:^1.1 — slim-skeleton vendor ships psr/http-message 2.0 whose typed interfaces fatal /zeal's openswoole/core PSR-7 v1 classes …`
  - `composer install + dump-autoload -o --no-dev — strip phpstan/prophecy/codesniffer dev classes from the optimized classmap so preloadClassmap warm in the master…`
  - `App::addMiddleware(new SessionStartMiddleware()) — REQUIRED: ZealPHP's SessionManager only auto-emits Set-Cookie for RETURNING visitors (incoming PHPSESSID). W…`
  - `HEADLINE FIX — $GLOBALS['_SESSION'] = &\ZealPHP\G::instance()->session at the top of every session-touching closure. In coroutine-legacy ZealPHP binds $_SESSIO…`
  - `Run Slim in the request coroutine frame via a native catch-all $app->patternRoute('#^/.*$#', ...) + setFallback that require the front controller (public/slim_…`

#### Yii 2 — **C**
- **homepage:** 200/10170B · **login:** ok: admin/admin via CSRF form POST, 'Logout (admin)' verified in response · **write:** ok: contact form POST (CSRF + fixedVerifyCode captcha) -> .eml written to runtime/mail (c…
- **concurrent burst:** warm steady-state: 19x200 1x500 (also observed 20x200 and 38/40x200 runs); COLD first concurrent wave after fresh boot: 17-24 of 60 responses come ba…
- **coroutine-legacy knobs:**
  - `composer install --no-dev + composer dump-autoload -o (dev deps pull psr/http-message 2.0 via codeception/guzzle whose typed interfaces fatal against /zeal ope…`
  - `require APP_DIR/vendor/autoload.php in master + App::preloadClassmap() (template composer-app shape; globalScopeInclude removed)`
  - `require APP_DIR/vendor/yiisoft/yii2/Yii.php in master — class Yii has no namespace, is NOT PSR-4 compliant so absent from the optimized classmap; warms the Yii…`
  - `web/index.php switched to YII_DEBUG=false / YII_ENV='prod' — required: master classmap warm executes BaseYii.php side-effect defines (YII_ENV='prod' baseline),…`
  - `public -> web symlink for the protocol docroot`
  - `tried, no effect, reverted: ZEALPHP_CLASS_STATICS_RESET_DISABLE=1 (burst anomaly rate unchanged: 2/40 vs 4/40)`
- **Sequential fallback:** mixed: MODE_MIXED + App::silentRedeclare(true) (entry plain-requires Yii.php every request) = EVERYTHING clean: login ok, contact flash ok, burst20 20x200 with 20/20 full homepage bodies, 0 fatals/deadlocks

#### Laminas — **C**
- **homepage:** 200/5KB · **login:** ok (session route, HTTP 200; cookie round-trips via SessionStartMiddleware) · **write:** failed: $_SESSION write inside App::include() (Laminas front-controller dispatch) not per…
- **concurrent burst:** 20x200 (warm); first cold burst before worker-start warmup was 14x200 3x500 + 1 SIGSEGV worker death; after warmup 60x200 over 3 rounds, 0 deaths
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::preloadClassmap() (warms ZealPHP own classmap in master)`
  - `SessionStartMiddleware (required: first-time visitors otherwise get NO Set-Cookie, so session never round-trips)`
  - `onWorkerStart: require app vendor/autoload.php + App::preloadDir('module') + preloadClassmap() (warms Laminas class graph single-coroutine; eliminates cold-con…`
- **Sequential fallback:** mixed: session write_op works perfectly under App::MODE_MIXED — hits increment 1->2->3->4->5->6->7 across requests, persists across second-request homepage. Confirms the write-back failure is coroutine-legacy snapshot x…

#### phpMyAdmin — **D**
- **homepage:** 200/48KB · **login:** failed: session not persisted across requests in coroutine-legacy. phpMyAdmin uses a cust… · **write:** n/a: blocked by login failure (cannot authenticate, so no authenticated write possible)
- **concurrent burst:** sequential 20x200; concurrent wave (P6) -> 2x500 2x200 2x000 + worker heap-corruption crashes (delta +4 deaths). Total 8-10 worker deaths logged: 'ze…
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true) [template default]`
  - `App::ignorePhpExt(false) [phpMyAdmin serves /index.php?route=... URLs]`
  - `App::documentRoot(/apps50/phpmyadmin/public)`
  - `fallback front-controller in app.php routing all non-static URLs to /index.php`
- **Sequential fallback:** mixed: App::MODE_MIXED boots + homepage 200/48KB, but ALSO broken — request 2 throws 'Constant PHPMYADMIN already defined in index.php:23' (no Stage-7 include isolation in MIXED -> require_once'd bootstrap re-defines co…

#### Adminer — **B**
- **homepage:** 200/5.2KB · **login:** ok · **write:** ok: CREATE TABLE zeal_notes + INSERT via SQL-command UI POST (CSRF token) -> 'Query execu…
- **concurrent burst:** 20x200, 0 worker deaths (boot.log: 0 Fatal/segfault/zend_mm)
- **coroutine-legacy knobs:**
  - `App::defineIsolation(true) (Adminer defines per-request constants adminer\SERVER/DB/ME from $_GET)`
  - `App::globalScopeInclude(true) (template default, kept)`
  - `template fix: fallback must use App::include($script) not App::includeFile($script) — a docroot-relative '/adminer.php' fails includeFile's startsWith(docroot)…`
  - `WORKAROUND for ext-zealphp define-isolation case bug: byte-patch define('Adminer\X') -> define('adminer\X') (12 sites; behavior-identical, PHP constant-namespa…`
  - `wrapper entry public/index.php: ini_set('display_errors','0') + require adminer.php — suppresses the residual harmless 'Constant adminer\VERSION already define…`

#### TinyFileManager — **D**
- **homepage:** 200/13KB · **login:** failed: SUCCESS branch runs (pv=1, vt=1, $_SESSION['filemanager']['logged']='admin' confi… · **write:** n/a: blocked by login failure — TFM upload (multipart + dzchunk* + token) is auth-gated, …
- **concurrent burst:** 20x200 (0 worker deaths: segfault/zend_mm/Fatal=0)
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true) [REQUIRED: TFM declares bare top-level $CONFIG/$root_path/$root_url and reads them via `global` inside methods; without it -> 'Ca…`
  - `front-controller fallback in app.php (ENTRY=/index.php, static passthrough)`
- **Sequential fallback:** mixed: FAILS to render TFM at all — homepage 500/201B 'Tiny File Manager Error: Cannot load configuration' (App::globalScopeInclude is gated to coroutine-legacy via globalScopeIncludeEffective()&&silent_redeclare, so in…

#### elFinder — **C**
- **homepage:** 200/618b (HTML+JS shell); static assets… · **login:** n/a: elFinder is a file manager, no auth layer · **write:** failed: connector mkdir/upload hang -> HTTP 200 but 0 bytes (6s timeout). elFinderConnect…
- **concurrent burst:** 20x200 on homepage, 0 worker deaths
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `App::hookAll(0)`
  - `explicit $app->route('/php/connector.minimal.php') — the implicit .php-block route 403s direct .php URLs`
  - `custom elFinderSessionInterface impl (pure $_SESSION wrapper) in connector — stock elFinderSession::start() registers a PROTECTED method via set_error_handler(…`
- **Sequential fallback:** mixed: SAME failure — under App::MODE_MIXED the connector still hangs/0-bytes (the ob_end_clean+echo+exit output path is broken in mixed too, JSON leaks to stdout). No mode reconciles elFinder's buffer-teardown output c…

#### MediaWiki — **D**
- **homepage:** rendered 36185 bytes internally but cli… · **login:** failed: api.php token/clientlogin sequence cannot complete — worker crashes (coroutine-le… · **write:** failed: could not reach authenticated edit (login never completes; multi-request flow die…
- **concurrent burst:** n/a: server unstable — workers SIGSEGV per request, master dies
- **coroutine-legacy knobs:**
  - `App::mode(MODE_COROUTINE_LEGACY)`
  - `App::globalScopeInclude(true)`
  - `master pre-fork classmap preload of MediaWiki core+vendor+skins (custom resolver)`
  - `preload denylist for per-request singletons (SettingsBuilder, ExtensionRegistry, RequestContext, DeferredUpdates, ActionEntryPoint, ApiEntryPoint)`
  - `preload guard: skip /maintenance/ and /installer/ EXCEPT /installer/Hook/ (DatabaseUpdater.php top-level require_once Maintenance.php defines MW_ENTRY_POINT=cl…`
  - `patch includes/BootstrapHelperFunctions.php wfIsCLI(): treat web MW_ENTRY_POINT as non-CLI (OpenSwoole runs PHP_SAPI=cli, made MW use FauxRequest -> Request UR…`
  - `USE_ZEND_ALLOC=0 (converts zend_mm_heap-corrupted SIGABRT into SIGSEGV; still crashes at teardown)`
  - `ob shield + MediaWikiEntryPoint enableOutputCapture (attempted output-flush workaround)`
- **Sequential fallback:** mixed: MODE_MIXED renders the homepage once per cold worker (200, 36184 bytes, real <title>ZextWiki</title> Vector-skin page) with NO crash, but re-includes fatal on wfWebStartNoLocalSettings() redeclaration (no silentR…

#### DokuWiki — **D**
- **homepage:** 302->200/8.0KB (index.php redirect to d… · **login:** failed: POST /doku.php do=login returns 500 'DokuWiki Setup Error: openswoole exit' — Dok… · **write:** page create via doku.php do[save] POST: state change SUCCEEDS (data/pages/zealtest2.txt w…
- **concurrent burst:** 2x200 5x500 13x000 on /doku.php?id=start at -P6 — both workers die (zend_mm_heap corrupted, signal 6/11) and then the OpenSwoole master itself crashe…
- **coroutine-legacy knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::ignorePhpExt(false) — DokuWiki uses direct .php entry points (doku.php, install.php, lib/exe/*.php); default .php-block returned 403`
  - `App::defineIsolation(true) — DOKU_* constants re-defined on Stage-7 re-executed includes`
  - `[workaround, tested then reverted] 2-line patch in inc/ActionRouter.php handleFatalException to rethrow OpenSwoole\ExitException — turns the 500 POST responses…`
- **Sequential fallback:** mixed: WORSE, not viable — homepage 500/212, burst alternates 500/200 (classic require_once request-2 breakage: init.php not re-executed, bootstrap globals gone), login session does not stick (logged_in=0), page save fa…

#### BookStack — **C**
- **homepage:** 302/354b (redirect to /login; authed ho… · **login:** ok · **write:** ok: created book + page via POST, verified in DB (entities id=2) and follow-up GET 200/49…
- **concurrent burst:** 4x ok (2x200 + 2x302), 16x500 — Laravel global-container concurrency race; 0 worker deaths
- **coroutine-legacy knobs:**
  - `App::mode(App::MODE_COROUTINE_LEGACY)`
  - `App::preloadClassmap() (after composer dump-autoload -o)`
  - `App::preloadDir('/apps50/bookstack/app')`
  - `documentRoot=/apps50/bookstack/public (BookStack native web root)`
  - `removed globalScopeInclude (modern Laravel/PSR-4 app)`
  - `tried App::defineIsolation(true) — did NOT fix the concurrency 500s`
- **Sequential fallback:** mixed: NOT working — App::MODE_MIXED breaks BookStack's front-controller bootstrap (require bootstrap/app.php returns true, 'alias() on true' at index.php:19). The mixed-mode burst's '20x200' were 500-error pages served…

#### Kanboard — **D**
- **homepage:** 302/797B redirect -> login form 200/983… · **login:** failed: CSRF token never persists - session writes are lost for pre-existing sessions, so… · **write:** blocked: no authenticated session is obtainable in coroutine-legacy
- **concurrent burst:** run1: 12x302 8x500; run2: 7x500 2x302 then BOTH workers died (zend_mm_heap corrupted, sig6+sig11), server hung, 11 connections never answered; prior …
- **coroutine-legacy knobs:**
  - `globalScopeInclude(false) AND (true) both tried - session write-loss identical either way`
  - `defineIsolation(true) - kept, but does NOT cover namespaced compile-time const (schema\VERSION) re-exec under Stage 7`
  - `ini display_errors=0 - required to keep redeclare/session-ini warnings out of response bodies`
  - `USE_ZEND_ALLOC=0 - burst still 13x500 + 3 worker deaths but no zend_mm fatal and server survives (vs total crash-loop death without it)`
- **Sequential fallback:** mixed: ALL flows pass - login admin/admin ok (dashboard 200 with Logout), project 'ZealProj' created (id=2), task 'Zeal task one' created and DB-verified (tasks.id=2), second_request 302/200, burst20 = 20x302, 0 worker …

#### FreshRSS — **C**
- **homepage:** 302->200/6.8KB (GET / redirects to /i/,… · **login:** ok (2 caveats): real challenge-response login works in stock coroutine-legacy — GET ?c=ja… · **write:** failed stock / ok patched: authenticated add-feed POST (?c=feed&a=add with _csrf from the…
- **concurrent burst:** 17x302 3x000 on GET / at -P6, reproducible across boots: exactly one worker dies SIGSEGV (signal=11) per burst; server self-heals via respawn and pos…
- **coroutine-legacy knobs:**
  - `App::globalScopeInclude(true) (template default, kept)`
  - `App::defineIsolation(true) — FreshRSS constants.php top-level consts re-executed by Stage 7`
  - `fallback handler: docroot resolved via realpath() — public/ is a symlink to app/p, otherwise the static-file prefix check never matches and feed.xml 500s throu…`
  - `fallback handler: directory URLs resolve to dir/index.php (FreshRSS app lives at /i/); App::include() not includeFile()`
  - `php -d display_errors=0 at process start — per-request ini re-park overrides app.php boot-time ini_set; without it Stage-7 'Constant already defined' warnings …`
  - `DB tables created via FreshRSS_UserDAO::createUser() in a one-off CLI script (cli/create-user.php skipped table creation because the user dir predated the MySQ…`
  - `login scripting: POST to ?c=auth&a=login (CSRF-exempt, forwards internally to formLogin) — a=formLogin directly is CSRF-gated 403; challenge=crypt(storedHash.n…`
  - `[workaround, tested then reverted] 2-line p/i/index.php patch rethrowing OpenSwoole\ExitException — turns the 500 POST responses (login, add-feed) into clean 3…`
  - `[workaround, tested then reverted] 1-line app/Models/SimplePieCustom.php patch skipping CURLOPT_PROTOCOLS_STR/CURLOPT_REDIR_PROTOCOLS_STR — makes feed fetching…`
- **Sequential fallback:** mixed: WORSE, not viable (prior session on identical build, not re-run today): homepage 500/212B on request 2 (classic require_once breakage), login flow hung the server entirely — 6979 abnormal-exit/signal lines + two …

#### Piwigo — **D**
- **homepage:** 200/3.6KB · **login:** ok via remember_me=1 workaround — POST identification.php 302 + pwg_remember cookie; ws.p… · **write:** failed: pwg.categories.add via ws.php -> 403 'Invalid security token'. pwg_token changes …
- **concurrent burst:** 2x200 18x000 per burst (reproduced 3x) — workers crash under -P6 concurrency (signal=11 SIGSEGV + one signal=6, 6 worker deaths per burst, no PHP fat…
- **coroutine-legacy knobs:**
  - `App::ignorePhpExt(false) — Piwigo needs direct *.php URLs (install.php, ws.php, identification.php, admin.php)`
  - `App::globalScopeInclude(true) (template default, required — bare top-level globals in section_init/menubar)`
  - `ZEALPHP_CLASS_STATICS_RESET_DISABLE=1 — still required on ext 0.3.48: without it EVERY request 2+ 500s (ImageStdParams::$all_types array->int TypeError in coun…`
  - `USE_ZEND_ALLOC=0 NO LONGER needed (was required on 0.3.46): login POST clean without it, and burst A/B identical with/without`
  - `login workaround: remember_me=1 (pwg_remember cookie auto_login per request) — Piwigo's native session login never persists (getStatus=guest after 302+pwg_id)`
- **Sequential fallback:** mixed: NOT viable (prior-run evidence, same framework v0.4.8: homepage 500 — array_push(): Argument #1 must be array, null given at section_init.inc.php:666; globalScopeInclude is coroutine-legacy-gated so Piwigo's bare…

#### YOURLS — **C**
- **homepage:** 200/6KB · **login:** ok · **write:** ok: created short link 'zealtest' via authenticated admin-ajax (action=add + add_url nonc…
- **concurrent burst:** UNSTABLE: best round 17x200 3x500; repeats degrade (14x200 2x500 4x000; 7x200 9x500 4x000); 2-13 worker deaths per round (zend_mm_heap corrupted, SIG…
- **coroutine-legacy knobs:**
  - `App::ignorePhpExt(false) — YOURLS requires direct *.php URLs (admin/install.php, admin/admin-ajax.php, yourls-api.php); default .php-block route 403s them`
  - `fallback handler must call App::include($script), not the template's App::includeFile($script) — includeFile() expects an ABSOLUTE path, so ENTRY '/yourls-load…`
  - `App::globalScopeInclude(true) (template default, kept)`
  - `created public/index.php from YOURLS' shipped sample-public-front-page.txt (stock YOURLS setup step; without it GET / 302-redirects to itself via yourls-loader)`
  - `tried-but-ineffective: App::defineIsolation(true) and USE_ZEND_ALLOC=0 — neither stopped the concurrency heap corruption; final config omits both`
- **Sequential fallback:** mixed: stable under burst20 x2 (0 worker deaths, zero 500s; defineIsolation MUST be off in mixed or constants vanish on request 2) — request-2 OK, short-link 301 redirect OK, but homepage responses flap between 200 and …

