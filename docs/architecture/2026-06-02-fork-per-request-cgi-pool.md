# Fork-per-request CGI pool (`cgiMode('fork')`) — Apache MPM prefork for ZealPHP

**Status:** design / RFC (2026-06-02). Implements the "proper answer" to the
unmodified-WordPress pool-reuse problem flagged in issue #167.

## Problem

Unmodified legacy apps (WordPress, Drupal) re-run **unguarded top-level
`define()` / `class` / `function` declarations on every request**. A PHP process
**cannot safely un-declare** a class (a class with `extends`/`implements` can only
be *orphaned*, not destroyed — ext-zealphp 0.3.24 — and an orphan stays in the
table, so re-declaration still fatals). Therefore a **reused** process can never
be bulletproof for arbitrary unmodified legacy code.

Today we offer two points on the spectrum:

| Mode | Mechanism | Unmodified WP | Per-request cost |
|---|---|---|---|
| `cgiMode('pool')` + `cgiPoolMaxRequests(N>1)` | reuse a warm subprocess | ❌ `Cannot redeclare class` | ~1–3 ms warm dispatch |
| `cgiMode('pool')` + `cgiPoolMaxRequests(1)` (legacy-cgi default) | respawn a **fresh** PHP via `proc_open` per request | ✅ correct | **~30–50 ms cold start** (PHP init + ext load + opcache attach + autoload) |
| `cgiMode('proc')` | `proc_open` a fresh PHP per request | ✅ correct | ~30–50 ms cold start |

The correct-but-fast cell is missing. **`cgiMode('fork')` fills it**: fresh-process
semantics (empty symbol table per request → nothing to un-declare) at **fork cost
(~1 ms COW)** instead of cold-PHP-start cost — exactly Apache's `prefork` MPM and
PHP-FPM's process model, but pre-warmed.

## Core idea

A long-lived **fork-master** template process boots PHP **once** (extensions,
opcache, the framework, and — critically — opcache-compiles the target app's
files), then **`pcntl_fork()`s a fresh child per request**. The child:

1. inherits the warm parent via copy-on-write (PHP already started, opcache warm,
   classes the app *autoloads* already compiled),
2. runs the request at **true global scope** (the #170 fix — top-level `$menu`
   etc. become real `$GLOBALS`),
3. writes the response frame, then **`_exit()`s** — taking all its `define()`s /
   `class` declarations / state with it.

The parent **never runs app code**, so its symbol table stays pristine. Every fork
starts from the same clean, warm baseline. No un-declaring, ever.

## Fork safety — the load-bearing constraint

**`pcntl_fork()` MUST NOT happen inside a process running the OpenSwoole reactor.**
After `$server->start()`, an OpenSwoole worker owns an event loop, a coroutine
scheduler, timers, `signalfd`, and the master↔worker UNIX pipes. Forking that
process yields a child with a half-initialised reactor → undefined behaviour
(double-driven epoll, corrupted scheduler, duplicated pipe fds). This is why we do
**not** fork inside the HTTP workers.

**The fork-master is a plain PHP process with NO OpenSwoole reactor.** It is
spawned once at boot via `proc_open` (the same way `WorkerPool` spawns
`pool_worker.php` today), and runs a classic blocking `accept()`/`fork()` loop on a
UNIX domain socket. `pcntl_fork()` there is safe — there is no reactor to corrupt.

**The child's exit must be `posix`-clean, not a normal PHP shutdown.** After
writing its response the child calls `_exit(0)` semantics — we use
`posix_kill(getmypid(), SIGKILL)` *or* `pcntl_exec`-free fast exit — so it does
**not** run PHP's `RSHUTDOWN`/`MSHUTDOWN`, user `register_shutdown_function`s, or
object `__destruct`ors that could flush COW-shared resources (a shared opcache lock,
the parent's listen socket) and corrupt the parent. The app's *response* is already
fully captured and sent before exit. (Exception: the child must run the app's own
output/flush, which it does explicitly before exiting.)

Other hygiene the child does immediately after fork:
- **close the inherited listen socket** (only the parent accepts),
- keep only the accepted connection fd for the response,
- reset signal handlers it shouldn't inherit.

## Architecture

```
OpenSwoole HTTP worker (coroutine)                 fork-master (no reactor)
  ResponseMiddleware                                  accept() loop
    → App::include('/index.php')                        │
      → Dispatcher::cgiFork($path)                       │
        → ForkClient: connect UNIX sock ───req frame──►  accept()
                                                          fork() ──► child:
                                                          │            close listener
                                                          │            populate $_SERVER/$_GET/$_FILES
                                                          │            $body = stdin (php://input)
                                                          │            include $path AT GLOBAL SCOPE  (#170)
                                                          │            write resp frame → conn
        ◄──────────── resp frame ──────────────────────  │            _exit(0)   ← fresh state dies
        return body                                       reap SIGCHLD (waitpid WNOHANG)
```

- **`src/fork_master.php`** (new) — the template. Boots, optional preload, binds a
  UNIX socket (`$ZEALPHP_FORK_SOCK`), `accept()`/`fork()` loop, SIGCHLD reaper,
  per-child watchdog (kills a child exceeding `App::$cgi_timeout`), a
  max-concurrent-forks semaphore (backpressure; default = `cgi_pool_size × k`).
- **`src/CGI/ForkPool.php`** (new) — host-side handle: spawns/owns the fork-master
  (one per OpenSwoole worker, or one shared — see "open questions"), exposes
  `dispatch(array $req): array`. Mirrors `WorkerPool`'s public shape so
  `Dispatcher::cgiPool` can branch on strategy with minimal change.
- **Request body / `$_FILES`** ride the existing `IPC` frame + the `CgiInputStream`
  `php://input` bridge already shipped in #170. The global-scope include is the
  same logic as `pool_worker.php`'s `pool_prepare_request`/`pool_finish_request`
  — **factor that into a shared `src/CGI/RequestRunner.php`** so `pool_worker`,
  `cgi_worker`, and the fork child all use one implementation.

## Preload (the warmth)

The parent opcache-compiles the app so children inherit compiled op_arrays COW:
- `App::cgiForkPreload([...files])` and/or `App::preloadDir($docroot)` run in the
  fork-master at boot (NOT in a worker — same master-only rule as
  `preloadClassmap`). For WordPress: compile `wp-load.php`, `wp-settings.php`,
  `wp-includes/**` so the child's `include` is mostly cache hits.
- The parent must **not execute** the bootstrap (that would dirty its symbol table).
  Compile-only (`opcache_compile_file`) gives the COW warmth without declaring.

## Integration

- `App::cgiMode('fork')` — the 4th strategy beside `pool|proc|fcgi`.
- `Dispatcher::cgiSubprocess`/`cgiPool` gain a `cgiFork()` branch selected by
  `App::$cgi_mode === 'fork'`. The implicit-route + `App::include` plumbing is
  unchanged (it already routes `.php` through the CGI dispatcher).
- `App::mode('legacy-cgi')` could later default to `fork` once it's proven — for
  now `legacy-cgi` stays `pool` + recycle=1 (the safe floor shipped in #171).

## Failure modes & limits

- **fork() fails** (EAGAIN/ENOMEM under load): the master returns a `503` frame;
  the worker surfaces it. The concurrency cap prevents fork-bombing into this.
- **child crash / SIGSEGV**: the accepted connection closes without a frame; the
  host `ForkClient` reads EOF → `500`. SIGCHLD reaper `waitpid`s the zombie.
- **child timeout**: parent watchdog `SIGKILL`s a child past `cgi_timeout`.
- **fd/zombie leaks**: every child closes the listener; parent reaps in a
  `SIGCHLD` handler with `waitpid(-1, WNOHANG)` loop. Validated with an fd-count +
  zombie-count assertion under a stress burst.
- **Not portable to non-fork platforms** (Windows): `cgiMode('fork')` requires
  `pcntl` + `posix`; falls back to `pool` with a boot warning if absent.
- **Still subject to the cold-boot `mysqlnd`/`libtasn1` teardown** only if the app
  hits it on request 1 — but fork gives a *fresh* process each time, so it behaves
  exactly like FPM (which WordPress targets), i.e. fine.

## Validation plan (before it ships on by default)

1. Unit: `RequestRunner` global-scope include + return contract (shared with the
   existing pool/cgi tests).
2. Integration: `cgiMode('fork')` end-to-end on a fixture app; assert top-level
   `$x` becomes `$GLOBALS['x']` (global-scope proof), POST body via `php://input`,
   `$_FILES` upload.
3. **Stress / safety**: 2000-request burst at concurrency 50 → assert (a) zero
   zombies (`ps` defunct count == 0 after drain), (b) flat fd count in the master,
   (c) flat RSS (no COW leak), (d) zero `Cannot redeclare`.
4. **Real WordPress**: full wp-admin + block editor + media upload under load on
   the lab instance; compare p50/p99 latency vs `proc` and `pool`+recycle=1
   (expect ~30–50 ms → ~1–3 ms per-request floor).
5. ASAN build of the fork child path (the user owns the ext/ASAN env).

## Phasing

- **P1** — extract `src/CGI/RequestRunner.php` (shared global-scope include +
  return contract) from `pool_worker.php`; no behaviour change. *Low risk, ships
  first.*
- **P2** — `src/fork_master.php` + `src/CGI/ForkPool.php` + `Dispatcher::cgiFork` +
  `App::cgiMode('fork')`. Opt-in, off by default.
- **P3** — preload hooks (`cgiForkPreload`), watchdog, concurrency cap, stress
  tests, the validation matrix above.
- **P4** — once proven, consider making `legacy-cgi` default to `fork`.

## Open questions (for review)

1. **One fork-master per OpenSwoole worker, or one shared master?** Per-worker is
   simpler (no cross-worker socket contention, each worker owns its master's
   lifecycle) and matches today's `WorkerPool` (per-worker pool). Shared master
   centralises preload memory but adds a socket hotspot. **Leaning per-worker.**
2. **Child exit mechanism** — `posix_kill(SIGKILL)` (bluntest, guaranteed no
   teardown) vs a guarded `pcntl`-style fast exit. SIGKILL is safest re COW
   corruption but skips even the app's legitimate shutdown functions (WP rarely
   relies on these for the response, which is already sent). **Leaning SIGKILL
   after the response frame is flushed.**
3. **Preload depth for WordPress** — compile-only the static includes; the
   per-request `wp-settings` run still happens in the child (it must — it *is* the
   request). Measure how much COW warmth the compile-only preload actually buys.

## Relationship to the per-request reset stack (option "A")

The reset stack (`process_state_clean` + the 0.3.25 rtcache/statics/class-statics
resets) makes a *reused* process *better*, but can never reach fork's correctness
(it can't un-declare inherited classes). Fork **obsoletes** the need for reuse on
unguarded apps. We keep the reset wiring as an **opt-in** (`ZEALPHP_POOL_FULL_RESET`)
for re-entrant apps that genuinely benefit from warm reuse, but `fork` is the
recommended path for unmodified legacy apps.
