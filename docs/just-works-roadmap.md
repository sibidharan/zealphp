# The road to "old PHP just works"

> **Living document** — updated whenever a frontier lands or a new one is found.
> Last updated: **2026-06-12** · stack: ZealPHP master (v0.4.8+) + ext-zealphp **v0.3.49** · PHP 8.4 · OpenSwoole 26.x

ZealPHP's coroutine-legacy mode is a **compatibility runtime**: traditional
request-style PHP — written against the PHP-FPM "fresh state per request"
mental model — runs *concurrently* under OpenSwoole coroutines, with every
request-state primitive isolated per coroutine (the
[isolation stages](architecture/isolation-stages.md), S1–S12). This page is
the honest scorecard: what already "just works", what works conditionally, and
exactly what still stands between today and the unconditional promise.

For per-app evidence, read the [compatibility database](compatibility-database.md).
For mode selection, read [running modern apps](running-modern-apps.md) and the
[runtime architecture](runtime-architecture.md).

---

## What "just works" today

These classes are **fixed and pinned by tests** on the current stack:

| Class | What used to break | Fixed in | Pinned by |
|---|---|---|---|
| Request-state isolation (S1–S5, S7, S9, S11) | superglobals, `$GLOBALS`, statics, redeclares, `require_once` no-ops, cwd/locale/umask/tz/mbenc/libxml leaks across concurrent requests | ext 0.3.x series | trust bar: 40-way interleaved burst, **0 leakage** on the 16-item contract set (`tests/Integration/TrustBarIsolationTest.php`) |
| Login sessions | `$_SESSION` mutations after request 1 silently dropped → infinite login loops (phpMyAdmin CSRF, TinyFileManager, Grav) | framework [#379](https://github.com/sibidharan/zealphp/issues/379) | `tests/Unit/SessionLiveStoreTest.php`; TinyFileManager logs in end-to-end |
| `exit()` / `die()` | swallowed by app `catch (\Exception)` blocks → 500s on every redirecting POST (FreshRSS), 0-byte bodies (CI4) | ext 0.3.48 ([ext#47](https://github.com/sibidharan/ext-zealphp/issues/47)) + `App::hookExit()` | `tests/Unit/ExitHaltContractTest.php`, ext `tests/060`/`061` |
| Object globals (`$wpdb`-class) | concurrent worker SIGSEGVs with a process-global DB object at file scope | ext 0.3.47 ([ext#46](https://github.com/sibidharan/ext-zealphp/issues/46)-era Stage-8 parking) | YOURLS/PDO/mysqli repros 0 deaths; ext phpt |
| **Concurrent-burst heap corruption** (the big one) | `zend_mm_heap corrupted` worker SIGSEGVs under 6-way bursts — DokuWiki 15–28 deaths/burst, phpMyAdmin 13–17, Kanboard 0–16, CodeIgniter4 crash | **ext 0.3.49** ([ext#52](https://github.com/sibidharan/ext-zealphp/issues/52)): the engine's symbol-table attach/detach protocol moves values assuming a single flow; concurrent S8 requests over-freed each other's globals. 0.3.49 parks ALL request-frame globals per yield | all four apps → **0 deaths** (84-req 6-way bursts); ASAN 0 reports; Valgrind 0 errors; ext `tests/062` bidirectional |
| Inherited-class re-declaration | hard SIGSEGV on `require_once`'d `class X extends Y` re-execution (the original "WordPress crash") | ext 0.3.24 (S3c orphans inherited losers) | ext `tests/035` bidirectional |
| Per-request state resets (S11) | init-once guards / static registries kept last request's state (Drupal's container, `switch_to_blog(null)`) | ext 0.3.25/0.3.28 | 12-app sweep, ext `tests/040` |

**Modern Composer apps** (Laravel, Slim, Symfony-class) and **most legacy
`require_once` apps** boot, route, log in, and survive concurrent bursts in
coroutine-legacy on this stack. Apps that can't be made coroutine-safe always
have **`legacy-cgi`** (process-per-request, mod_php semantics) as the
no-questions-asked fallback — that mode IS "old PHP just works", at process
cost.

## The honest contract (conditional, by design)

Two conditions are **deliberate contracts**, not bugs — they will stay even at
"done":

1. **Class-graph warmup** — a class with `extends`/`implements` first compiled
   by overlapping coroutines can be observed present-but-unlinked (PHP's
   delayed early binding). Warm the graph before concurrency:
   `App::preloadClassmap()` (Composer `--optimize`), `App::preloadDir()`, or
   `App::preloadClasses()`. Autoloader-less apps (classic WordPress) belong in
   `legacy-cgi`.
2. **One DB connection per coroutine** — HOOK_ALL makes mysqlnd non-blocking,
   but a single handle shared across coroutines interleaves wire frames. Use
   per-request connections (object globals are per-coroutine since 0.3.23) or
   `ZealPHP\Db\DbConnectionPool`.

## What still stands between us and the unconditional promise

Ordered by impact. Each is tracked; this list shrinks release by release.

| # | Frontier | Symptom | Status / tracking |
|---|---|---|---|
| 1 | **mysqlnd / libtasn1 connection-teardown heap overflow** | cold-boot first-request worker crash (self-healing respawn) on persistent-keyed mysqlnd connects; WordPress under heavy concurrency is the benchmark victim | steady-state **shimmed to zero** in ext 0.3.46 (vio allocator re-pair); cold-boot residual open — [ext#44](https://github.com/sibidharan/ext-zealphp/issues/44), [ext#49](https://github.com/sibidharan/ext-zealphp/issues/49); sibling userland-persistent-streams [ext#45](https://github.com/sibidharan/ext-zealphp/issues/45). Workarounds: `USE_ZEND_ALLOC=0`, `max_request` recycle, `legacy-cgi` |
| 2 | **`class_alias()` under S7 re-execution** | re-executed `class_alias` fatals (silent-redeclare doesn't cover the alias path) | scoped point fix — [ext#50](https://github.com/sibidharan/ext-zealphp/issues/50) |
| 3 | **Per-coroutine class-static isolation (S5b deep cases)** | OOP static caches mutated mid-request can leak to a concurrent peer before request-end reset (S11c resets per request; concurrent-window isolation is the remainder) | the OOP-static-cache frontier — [ext#48](https://github.com/sibidharan/ext-zealphp/issues/48) |
| 4 | **Same-name request constants across coroutines** | with S10 on, a coroutine can read a peer's `define()` value after resume in a narrow window | [ext#16](https://github.com/sibidharan/ext-zealphp/issues/16) |
| 5 | **`$g` ↔ S8 include scope propagation matrix** | route-callback `$g->get` mutations don't propagate into `App::include()`'d file scope in coroutine-legacy; broader mode-compat matrix needed | [ext#43](https://github.com/sibidharan/ext-zealphp/issues/43), [ext#39](https://github.com/sibidharan/ext-zealphp/issues/39) |
| 6 | **opcache + re-executed app files** | warm-SHM "Cannot redeclare" for simple early-bound classes/functions in re-executed files | class case: `opcache.dups_fix=1` (stock PHP); function case needs the shipped 3-line opcache patch (`patches/opcache-function-dups-fix.patch`, upstream [php-src#22214](https://github.com/php/php-src/issues/22214)); fallback: blacklist the app docroot |
| 7 | **Orphaned inherited "loser" classes** | bounded per-worker memory growth under S7 re-execution (the safe alternative to the UAF it replaced) | mitigated by `max_request` recycle; real fix designed — [ext#12](https://github.com/sibidharan/ext-zealphp/issues/12) |
| 8 | **WP-admin under sustained load** | deep call stack + an admin-UI null-array on the dashboard; entangled with frontier #1 | umbrella [#167](https://github.com/sibidharan/zealphp/issues/167); `legacy-cgi`/`cgi-pool` remain the production home for wp-admin |
| 9 | **PHP 8.4 ecosystem incompat (not ours)** | apps that fatal on vanilla PHP 8.4 (Matomo's bundled php-di LSP violation, phpLiteAdmin) | out of scope — fails without ZealPHP too; tracked in the [compatibility database](compatibility-database.md) per app |

### What will NEVER be isolated (permanent boundaries)

Process-level state the runtime deliberately shares — the PHP-FPM mental model
holds, not "any binary blob of PHP runs unchanged": resource handles (file
locks, raw sockets), `pcntl_fork`/`set_time_limit` semantics, closure
`static $x`, and `set_error_handler`/`register_shutdown_function`
process-global behavior. The weighted SAPI-contract coverage derivation
(~97%) and the architectural limits live in
[`docs/architecture/2026-05-31-sapi-contract-coverage.md`](architecture/2026-05-31-sapi-contract-coverage.md).

## How progress is measured

1. **The trust bar** — 40-way interleaved concurrency, zero-leakage contract
   on the full request-state set (raw OpenSwoole leaks 39/40).
2. **The compatibility database** — 50 real apps, each graded on a real
   install with a real login, write, and 6-way concurrent burst on the
   current stack. Re-validation on ext 0.3.49 is in progress; the burst-death
   column collapsed to zero for every previously-crashing graded app.
3. **ASAN + Valgrind gates** — every memory-safety fix ships with a clean
   ASAN A/B and Valgrind pass, and a bidirectional phpt (fails on the old
   ext, passes on the new).
