# 50-App Coroutine-Legacy Sweep — Findings (2026-05-29)

**Setup:** 33 real PHP apps (WordPress, Drupal, Joomla, MediaWiki, Nextcloud,
phpMyAdmin, Matomo, Flarum, phpBB, …) on the docker lab, each hit across 4
modes — `coroutine` (mode5), `cgi-pool` (mode1), **`coroutine-legacy` (mode4,
the priority)**, `sync` (mode3) — against a **latest-code** image: framework
`src` at HEAD + ext-zealphp compiled for the container's **PHP 8.4.21** /
OpenSwoole 26.2.0. MySQL (`sweep-mysql`) attached. Harness: `~/sweep/` on
`sibidharan@172.30.24.4` (`sweep-test.sh`, `mode{1,3,4,5}-app.php`).

**Method:** the framework-blocker signal is "works in cgi-pool (subprocess =
Apache parity) but fails in a coroutine mode" — that isolates the *mode*, not
the app's config. Crashes make the sequential sweep **non-deterministic** (a
worker-killing app cascades to whatever's tested near it), so every root-cause
below was confirmed with **isolated, fresh-worker, single-app** repros, not raw
sweep numbers.

---

## Fixes landed (committed, verified on PHP 8.4)

### 1. `App::executeFile()` did not `chdir` to the script's directory — `f9c6339`
Apache/mod_php and php-cli run a script with CWD = the script's own dir, so
legacy apps' relative includes resolve there. In-process `executeFile()` left
CWD at the framework root (`/app`), so relative `require` in included apps
failed — the **dominant** coroutine/sync blocker:
`require './global.php'` (mybb), `require './include/auth.php'` (cacti),
`require 'conf/constants.php'` (vanilla → `/app/conf/...`), and privatebin's
relative `vendor/autoload.php` (→ "Class not found"). Fix: chdir to
`dirname($absPath)` for the include, restore via inner `finally`. **Unblocked
mybb, cacti, vanilla, piwigo, phpliteadmin, privatebin** (500 → 200/302) across
coroutine + sync; cgi-pool unaffected. Caveat: chdir is process-global —
per-coroutine CWD isolation (snapshot/restore on yield) is the follow-up for
concurrent cross-app load (tracked as "Stage 8").

### 2. Stage 7 (`includeIsolation`) re-executed circular `require_once` → OOM — ext `415496f` (0.3.11)
Stage 7 deleted a file from `EG(included_files)` on **every** `require_once` of
a non-snapshot file, so a re-entrant/circular/repeated `require_once` **within
one request** re-executed each time → unbounded re-inclusion. On the first
request to **phpmyadmin** (sodium_compat's pure-`require_once` autoload chain)
this exhausted the 1 GB memory_limit + crashed the worker (signal 6/11), and the
crash **cascaded** to other apps on the shared worker. Fix: a per-request
once-guard (keyed by `os_get_cid()`, cleared in `on_close`) — `require_once` is
now idempotent **within** a request, re-executes **across** requests. New
`zealphp_include_isolation_reset()` marks an explicit boundary (tests/sync).
phpmyadmin + nextcloud no longer OOM-crash (the 1 GB exhaustion is gone). ext
`.phpt` 30/30.

---

## Caveats characterized (not framework bugs, or deep/open)

### A. `coroutineGlobalsIsolation` coroutine deadlock — OPEN, deep
phpmyadmin (and nextcloud) still fail in `coroutine-legacy` after the above
fixes. Bisected to **`coroutineGlobalsIsolation` (Stage 2)**: with only base
superglobal isolation, phpmyadmin returns 200; adding Stage 2 → the worker dies
with `Channel::~Channel(): channel is destroyed, 1 consumers will be discarded`
→ "all coroutines asleep - deadlock" / exit 255 (and, under multi-worker load, a
SIGSEGV — `"A bug occurred in OpenSwoole-v26.2.0"`). Mechanism: Stage 2's
`on_yield`/`on_resume` `EG(symbol_table)` swap interacts with a coroutine
waiting on a channel (likely the framework's async-log flusher or the request
coroutine) and leaves it un-resumed → scheduler deadlock → worker exit. It is
**intermittent** (timing race) and **multi-mode** (255 exit vs segfault), which
is why it resists quick capture; needs a dedicated coroutine-scheduler debug
session (core/gdb + log-coroutine instrumentation). **Mitigation today:** run
such apps in `cgi-pool` (process isolation, works), or without
`coroutineGlobalsIsolation` if `$GLOBALS` isolation isn't needed.

> A misdiagnosis worth recording: an initial "objects/resources in `$GLOBALS`"
> theory (ext commit `0fc9895`, reverted) was based on a flawed repro — the
> repro crashed on `Cannot redeclare class` (missing `silentRedeclare`), not on
> the object snapshot. The corrected repro (stdClass + resource in `$GLOBALS`)
> runs clean. The real cause is the channel/coroutine deadlock above, not
> object refcount juggling.

### B. Framework-vs-app dependency version conflict (psr/log) — fundamental
kanboard fatals `Psr\Log\AbstractLogger::emergency()` incompatible with
`LoggerInterface::emergency(Stringable|string …): void`. kanboard vendors
**psr/log v1** (untyped); the **framework vendors psr/log v3** (typed). In the
shared coroutine class table the framework's autoloader (registered first)
resolves `Psr\Log\*` to v3 before kanboard's autoloader supplies v1 → its
`AbstractLogger` fails the compatibility check. Fundamental to in-process
hosting of apps whose deps conflict with the framework's; **cgi-pool's
subprocess avoids it** (only the app's vendor loads). Modern apps (psr/log v3)
don't clash. Mitigation: cgi-pool, or align versions.

### C. HOOK_ALL + legacy blocking I/O can deadlock — known, perf-VM precedent
HOOK_ALL auto-coroutinizes blocking I/O; some legacy apps do a blocking op
(e.g. session `flock`) that yields a lone coroutine with no resume event →
deadlock. The production perf-VM WordPress already runs `hookAll(0)` for this
reason. NOTE: an early "hookAll(0) fixes drupal/matomo" reading was sweep-
pollution; isolated tests showed HOOK_ALL-on does **not** deterministically
deadlock those on a fresh single request — so the hookAll default was **not**
changed. The deadlock overlaps with caveat A (coroutine scheduling under the
isolation hooks).

### D. `silentRedeclare` flips phpmyadmin 200 → 500 — separate behavior regression
With only base + `silentRedeclare`, phpmyadmin returns 500 (no crash) where
base-only returns 200. First-wins redeclaration changes a code path phpmyadmin
depends on. Separate from the crash; needs its own characterization.

### E. App-config (not framework)
8 apps return 404 in every mode (not installed / wrong entry: bookstack,
elfinder, flarum, monica, opencart, phpbb, slim-app, wallabag). grav (500) and
yourls (503) fail identically in every mode (app config). lychee 403 (needs
config). These need per-app installation + DB/composer ("configure the apps"),
not framework changes.

---

## Scoreboard (coroutine-legacy, after fixes 1 + 2)

- **Work (~17):** adminer, dokuwiki, cacti, freshrss, joomla, mediawiki, mybb,
  phpliteadmin, piwigo, privatebin, roundcube, vanilla, filegator, wordpress,
  test.php, traditional, tinyfilemanager.
- **Framework-relevant open:** phpmyadmin, nextcloud (caveat A deadlock),
  kanboard (caveat B dep-conflict), matomo/drupal (config + caveat D).
- **App-config (404 / fail-all-modes):** the 11 in caveat E.

## Honest verdict
The **common, deterministic** coroutine-legacy blockers — relative includes and
circular-`require_once` OOM — are **fixed in the framework/ext** and verified on
8.4; they were the patterns hitting the most apps. The remaining framework-level
failures reduce to **one deep open bug** (the Stage-2 coroutine deadlock) plus
**one fundamental caveat** (framework/app dep-version conflict), with the rest
being per-app install/config. "Old PHP just works" holds for the majority of
*installed, well-behaved* apps in coroutine-legacy; the deadlock + dep-conflict
are the honest boundary, and `cgi-pool` remains the compatibility fallback for
apps that hit them.
