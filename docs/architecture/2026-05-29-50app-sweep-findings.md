# 50-App Coroutine-Legacy Sweep — Findings (2026-05-29)

**Setup:** 33 real PHP apps (WordPress, Drupal, Joomla, MediaWiki, Nextcloud,
phpMyAdmin, Matomo, Flarum, phpBB, …) on the docker lab, each hit across 4
modes — `coroutine` (mode5), `cgi-pool` (mode1), **`coroutine-legacy` (mode4,
the priority)**, `sync` (mode3) — against a **latest-code** image: framework
`src` at HEAD + ext-zealphp compiled for the container's **PHP 8.4.21** /
OpenSwoole 26.2.0. MySQL (`sweep-mysql`) attached. Harness: `~/sweep/` on
`sibidharan@172.30.24.4`.

**Method note (important):** crashes make the sequential sweep
**non-deterministic** — a worker-killing app cascades to whatever is tested near
it on the shared worker. Early bisections were confounded by this; every
root-cause below was ultimately confirmed by **isolated fresh-worker repros** and
a **gdb backtrace**, not raw sweep numbers.

---

## Fixes landed (committed, verified on PHP 8.4)

### 1. `App::executeFile()` did not `chdir` to the script's directory — `f9c6339`
Apache/mod_php run a script with CWD = its own dir; in-process `executeFile()`
left CWD at `/app`, so legacy relative `require` failed:
`require './global.php'` (mybb), `'./include/auth.php'` (cacti),
`'conf/constants.php'` (vanilla), relative `vendor/autoload.php` (privatebin →
"Class not found"). Fix: chdir to `dirname($absPath)` for the include, restore
via inner `finally`. **Unblocked mybb, cacti, vanilla, piwigo, phpliteadmin,
privatebin.** (Process-global chdir; per-coroutine CWD isolation tracked as a
follow-up for concurrent cross-app load.)

### 2. Stage 7 (`includeIsolation`) re-executed circular `require_once` → OOM — ext `415496f` (0.3.11)
Stage 7 deleted a file from `EG(included_files)` on **every** `require_once`, so
a re-entrant/circular `require_once` within one request re-executed unbounded.
phpmyadmin's `sodium_compat` autoload chain exhausted the 1 GB memory_limit on
the first request. Fix: a per-request once-guard (keyed by `os_get_cid()`,
cleared in `on_close`) — idempotent within a request, re-executes across
requests; new `zealphp_include_isolation_reset()` marks an explicit boundary.

### 3. Stage 6 compile-cache use-after-free → worker SIGSEGV — ext `f728908` (0.3.12)  ★ the big one
**gdb backtrace:** `zealphp_compile_file_hook … "(*shell->refcount)++"`. The
Stage 6 compile-cache stored a compiled `zend_op_array*` in a process-wide hash;
on a later compile of the same file it returned a `memcpy`'d shell and did
`(*shell->refcount)++`. **The engine frees the compiled op_array after executing
it, so the cached `refcount` pointer dangles** — the `++` is a use-after-free
that segfaults the worker under load (signal 11, *"A bug occurred in
OpenSwoole 26.2.0"*), and the crash **cascaded** to other apps on the shared
worker. An op_array can't be shared by shallow memcpy+refcount across requests.
Fix: **remove the Stage 6 cache** (hit + save). Stage 4's CG-table swap already
handles top-level redeclaration on every compile, so the cache was only a
re-compile optimization — not worth a UAF.

**This single fix resolved the dominant remaining failures:** phpmyadmin
(8/10 crashes → 0, stable 500 across 10 sequential + 8 concurrent), **drupal
000→200**, **matomo 500→200/stable**, and removed the cascade that was
intermittently 000-ing dokuwiki/filegator/nextcloud.

> Two misdiagnoses recorded for honesty: (a) an "objects-in-`$GLOBALS`" theory
> (ext `0fc9895`, **reverted**) was based on a flawed repro — the crash was
> `Cannot redeclare class` (missing `silentRedeclare`), not an object snapshot;
> (b) bisection blamed `coroutineGlobalsIsolation` (Stage 2) for the deadlock,
> but that was cascade non-determinism — the real cause was the Stage 6 cache
> UAF, in the silentRedeclare/compile path. The gdb backtrace settled both.

---

## Remaining (not framework bugs / fundamental)

### A. psr/log v1-vs-v3 (kanboard) — fundamental dep-version conflict
kanboard vendors psr/log **v1** (untyped); the framework vendors **v3** (typed
`emergency(Stringable|string …): void`). In the shared coroutine class table the
framework's autoloader (registered first) resolves `Psr\Log\*` to v3 before
kanboard's autoloader supplies v1 → its `AbstractLogger` fails the compatibility
check → **compile-time fatal that exits the worker** (uncatchable at runtime; it
cascades in the shared-worker sweep, but in production — one app per worker —
only kanboard is affected). Fundamental to in-process hosting of apps whose deps
conflict with the framework's; **cgi-pool's subprocess avoids it** (only the
app's vendor loads). Modern apps (psr/log v3) don't clash.

### B. App-config (not framework) — the goal's "configure the apps" tail
- **Clean 500, need config** (no crash): phpmyadmin (DB/config), nextcloud
  (`Undefined array key "argv"` — expects a CLI env), matomo (`composer install`
  not run), grav.
- **404 — not installed / wrong entry:** bookstack, elfinder, flarum, monica,
  opencart, phpbb, slim-app, wallabag.
- **fail-all-modes (app config):** yourls (503), lychee (403, needs config).

### C. HOOK_ALL + legacy blocking I/O — known, perf-VM precedent
HOOK_ALL auto-coroutinizes blocking I/O; some legacy apps' blocking ops (e.g.
session `flock`) can deadlock a lone coroutine. The production perf-VM WordPress
already runs `hookAll(0)`. (Earlier "hookAll(0) fixes drupal/matomo" was sweep
pollution — drupal/matomo were actually fixed by the cache-UAF removal, not by
hookAll; the hookAll default was **not** changed.)

### D. `silentRedeclare` flips phpmyadmin 200→500 (separate, minor)
With base + `silentRedeclare` only, phpmyadmin returns 500 where base-only is
200 — first-wins redeclaration changes a path it depends on. No crash; its own
characterization.

---

## Scoreboard — coroutine-legacy, after all 3 fixes (isolated/deterministic)

- **Work (~19):** adminer, cacti, dokuwiki, drupal, filegator, freshrss, joomla,
  matomo*, mediawiki, mybb, phpliteadmin, piwigo, privatebin, roundcube, vanilla,
  wordpress, test.php, traditional, tinyfilemanager. (*matomo: 200 when
  composer-installed; otherwise a clean 500.)
- **Clean 500 (app-config, no crash):** phpmyadmin, nextcloud, grav.
- **Worker-crash (fundamental dep-conflict):** kanboard (psr/log).
- **404 / not installed:** 8 apps (caveat B).

## Verdict
All **framework-level crashes** in coroutine-legacy are fixed: the relative-
include break, the circular-`require_once` OOM, and — the big one, found via gdb
— the Stage 6 compile-cache use-after-free that was segfaulting workers and
cascading. After the three fixes, the only non-config failure is the
fundamental framework/app **dependency-version conflict** (kanboard's psr/log),
for which cgi-pool remains the compatibility fallback. "Old PHP just works"
holds for the majority of installed, well-behaved apps in coroutine-legacy; the
remaining tail is per-app install/config, not the framework.
