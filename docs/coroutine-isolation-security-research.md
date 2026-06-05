# Coroutine-isolation security research

A starting map for security researchers auditing ZealPHP's **per-coroutine
request-state isolation** — the headline guarantee that lets traditional
request-style PHP run under OpenSwoole coroutine concurrency "as if each request
had its own process."

The isolation is implemented in the **[ext-zealphp](https://github.com/sibidharan/ext-zealphp)**
C extension, but you research it **through ZealPHP**, not the extension alone: the
isolation only engages under the OpenSwoole coroutine scheduler that ZealPHP drives
(`App::mode('coroutine-legacy')`). A standalone `php -m` test exercises none of the
interesting paths. This doc tells you what the failure modes are, where they live,
why they're critical, and how to reproduce + validate them.

> **Scope.** This complements [`fuzzing.md`](./fuzzing.md) (the adversarial HTTP /
> framing axis). This doc is the **memory-safety + state-isolation** axis: use-after-free,
> cross-tenant state leaks, and unbounded leaks in the per-coroutine runtime.
> Report findings privately per [`SECURITY.md`](../SECURITY.md) — **do not** open a
> public issue for an unfixed memory-safety or cross-tenant-leak bug.

---

## 1. The claim under test (threat model)

ZealPHP's `coroutine-legacy` mode multiplexes **many concurrent HTTP requests** onto
one worker's coroutine scheduler, while promising each request sees its own isolated
copy of every PHP request-state primitive:

- the 7 superglobals — `$_GET $_POST $_REQUEST $_COOKIE $_FILES $_SERVER $_SESSION`
- `$GLOBALS` / `global $x` user variables
- `define()` constants
- `ini_set()` values, `putenv()`/`getenv()`
- function-local `static $x` and class `static` properties
- `require_once`/`include_once` re-execution + `header()`/`setcookie()` response state

**A break in any of these is a security vulnerability, not just a bug:**

| Failure mode | Impact | Severity framing |
|---|---|---|
| One coroutine reads another's `$_SESSION` / `$GLOBALS` / constant | **Cross-tenant data exposure / session hijack** — request A authenticated as request B's user | Critical/High |
| Per-request state freed while a cached engine slot still points at it | **Use-after-free** → worker SIGSEGV (DoS), potential exploitation | High |
| Per-request allocation never reclaimed | **Unbounded memory growth** → OOM worker kill (DoS) | Medium |
| State persists from one request into the next on the same worker | **Cross-request leak** (a weaker cross-tenant exposure) | Medium/High |

Because requests are concurrent **and** the engine tables they touch
(`EG(symbol_table)`, `EG(zend_constants)`, op-array `run_time_cache`) are
**process-shared**, the whole isolation runtime is a chain of "snapshot the live
table on yield, restore mine on resume, reset to a clean baseline so peers don't
inherit my writes." Every link is attack surface.

---

## 2. How the isolation works (60-second model)

The extension `dlsym`s OpenSwoole's `PHPCoroutine::on_yield` / `on_resume` /
`on_close` scheduler callbacks and chains its own in front. On every coroutine
switch it runs, per state primitive, a **save → reset → restore** cycle:

- **on_yield(coro):** `snapshot_save` deep-copies this coroutine's live state into a
  per-coroutine delta, then **resets the shared table to a clean baseline** so the
  next coroutine to run starts clean.
- **on_resume(coro):** `snapshot_restore` resets the shared table to baseline, then
  re-applies this coroutine's delta.
- **on_close(coro):** `snapshot_delete` frees the per-coroutine delta + any deferred
  allocations.

Per-request (driven by ZealPHP's session manager `finally`, not the scheduler) it
also mirrors PHP-FPM's `shutdown_executor()`: reset op-array `run_time_cache`s,
function-local statics, and class statics to their boot template.

> Deep dives: ext-zealphp **`ARCHITECTURE.md`** (the C internals, subsystem by
> subsystem) and ZealPHP **[`docs/architecture/state-isolation-reference.md`](./architecture/state-isolation-reference.md)**
> (the framework view + the trust-bar + the ~97% SAPI-contract coverage derivation).

The keying detail that bites: the scheduler callbacks key on the **`Coroutine*`
pointer argument**, NOT `os_get_cid()` — because `os_get_cid()` returns `-1` on
*every* `on_resume`. Any new per-coroutine table that keys on `os_get_cid()` in a
resume-context path is a latent cross-coroutine mix-up.

---

## 3. Vulnerability classes (the taxonomy)

Every isolation bug found so far falls into one of five classes. For each: the
**pattern**, **where to look**, **why critical**, **representative issues**
(ext-zealphp tracker), and the **fix shape**.

### Class 1 — Use-after-free: freed request-state still referenced by a cached `run_time_cache` slot

**Pattern.** A per-request symbol (a `define()` constant, a class `static` property
table, a function `static`) is **freed** at request end. But PHP caches the
*resolved address* of that symbol in the **`run_time_cache`** of a **process-shared**
(opcache-persisted) op-array — `ZEND_FETCH_CONSTANT`, `ZEND_FETCH_STATIC_PROP`. The
slot is **not** cleared when the symbol is freed, so the next execution of that
op-array dereferences freed memory.

**Where to look.** Every `zend_hash_del` / `destroy_*` / `zend_array_destroy` /
`efree` of a **user** symbol in the per-request reset/clear functions
(`zealphp_constants_clear`, `zealphp_reset_request_class_statics`,
`zealphp_reset_request_statics`). Cross-check each against the `run_time_cache` reset
(`zealphp_reset_request_rtcaches`): **a free without a paired slot-reset is a UAF.**

**Why critical.** UAF → wild-pointer write in `_object_properties_init` /
`zend_gc_addref` → SIGSEGV (DoS) and a classic exploitation primitive. ASAN sees it
as heap-use-after-free; under the Zend MM allocator it can hide (use `USE_ZEND_ALLOC=0`).

**Representative issues / status.** `#8` class-static reset UAF (freed
`static_members_table` + dangling `FETCH_STATIC_PROP` slot → ~134 TB phantom alloc)
— **fixed 0.3.28** (in-place reset). `#9` `constants_clear` UAF — **fixed 0.3.30**
(defer the free to coroutine close, after the request's `run_time_cache` is gone).

**Fix shape.** Either reset the value **in place** (never free the table the cache
points at), or **orphan + defer** the free to `on_close` (past the rtcache reset).
The class-static reset MUST be paired with the rtcache reset under one gate — a stray
`FETCH_STATIC_PROP` on a freed slot SEGVs.

### Class 2 — Cross-coroutine state leak: request state left live in a process-shared engine table

**Pattern.** A coroutine writes a state primitive into a **shared** engine table and
yields **without resetting it**, so a concurrent peer (or the next request) reads the
writer's value. The subtle variants are about **zval representation**: the walk that
should reset/snapshot the value handles a plain `zval` but **not** an `IS_REFERENCE`
or **`IS_INDIRECT`** wrapper, so it operates on the wrapper and leaves the real value
untouched.

**Where to look.** The `snapshot_save` / `snapshot_restore` / `reset_to_parent`
walks for **every** state type. For each, verify it correctly handles:
`IS_REFERENCE` (a `global $x` / `$g->get` alias), **`IS_INDIRECT`** (a materialised
CV — `grep -c IS_INDIRECT` was *zero* before the #10/#14 fix), objects/resources, and
that it **resets the live table after snapshotting** (the post-save reset). A walk
that `ZVAL_DUP`s/`ZVAL_COPY`s the wrapper instead of `Z_INDIRECT_P()`/`Z_REFVAL_P()`
of it is the tell.

**Why critical.** Cross-tenant: `$_SESSION` / `global $wpdb` / a `define()` from one
user's request visible in another's. This is the headline-breaking class.

**Representative issues / status.** `#15` superglobal snapshot was **non-removing** —
a `$_SESSION` left live was captured as the *next* coroutine's → session hijack;
**fixed 0.3.31** (reset superglobals to empty after snapshot). `#10` + `#14`
`global $x` scalar writes (and Stage-8 `require_global` vars) are `IS_INDIRECT` and
the four `$GLOBALS` walks didn't deref them → every concurrent reader saw the last
writer's value; **fixed 0.3.32** (IS_INDIRECT-aware walks + a master-frame-CV guard).
`#16` two coroutines `define()`-ing the **same name** with different values — **open
/ structural**: `EG(zend_constants)` is one shared table that can't hold two values
for one name; needs per-coroutine constant tables (a redesign), the current deferral
is the memory-safe trade-off.

**Fix shape.** Deref `IS_INDIRECT`/`IS_REFERENCE` to the real slot in **all** walks;
reset the live table to baseline after capture; guard master/boot symbols (an
`IS_INDIRECT` not in the parent baseline is the master's own variable — leave it,
NULL-ing it wipes master state mid-run).

### Class 3 — Unbounded memory leak: per-request allocations never reclaimed

**Pattern.** Something allocated on the per-request compile/include path is never
freed and grows for the worker's lifetime. Two shapes: (a) a longjmp (`zend_bailout`)
skips the cleanup; (b) an allocation is **deliberately orphaned** to dodge a
use-after-free and the "reclaimed later" promise is never kept.

**Where to look.** The silent-redeclare **first-wins merge** (`scratch_cl` →
`real_cg_cl`): inherited "loser" classes are orphaned (not destroyed) to avoid
corrupting the winner — confirm they're actually reclaimed. Every alloc on the
re-executed `require_once` path. **Every `zend_execute_ex` / user-code call without a
`zend_try`** — a bailout there leaks whatever the post-call cleanup would have freed.

**Why critical.** DoS — a `require_once`-bootstrap app (WordPress) re-executing an
inherited class per request leaks one class's allocations per request, growing
without bound until the worker is OOM-killed.

**Representative issues / status.** `#17` `zealphp_require_global` leaked its op-array
on a `zend_bailout` inside the included file — **fixed 0.3.29** (`zend_try`/`zend_catch`,
free on bailout, re-raise). `#12` orphaned inherited "loser" classes **never
reclaimed** — **open / structural**: the orphan **aliases process-shared
winner-owned** inheritance structures, so it can't be freed while the winner is live
(request-end reclaim re-opens the v0.3.24 SIGSEGV); needs deep-copy-on-link or
per-coroutine class tables.

**Fix shape.** `zend_try` around any user-code execution that owns allocations;
reclaim orphans at a boundary where no live engine structure aliases them (often only
worker teardown is truly safe — be honest about the bound).

### Class 4 — Resource exhaustion / unguarded recursion

**Pattern.** A re-entrant per-request operation lacks a guard in one execution mode.

**Where to look.** Mode-dependent gates are a smell — a guard wrapped in
`if (os_get_cid)` while the dangerous operation runs unconditionally means the
non-coroutine (sync) path gets the operation without the guard.

**Why critical.** DoS — unbounded recursion → heap exhaustion → worker crash.

**Representative issues / status.** `#11` the Stage-7 include-isolation re-include
recursion guard was gated on `os_get_cid` while the `EG(included_files)` eviction ran
unconditionally → in sync mode a file that `require_once`s itself recursed to OOM —
**fixed 0.3.29** (guard runs in both modes; sync bucket key 0).

### Class 5 — Cross-request persist: state not reset at the request boundary

**Pattern.** A long-lived OpenSwoole worker never runs PHP's per-request
`shutdown_executor()`, so persisted user symbols (function statics, class statics,
resolved op-array caches) keep their **last request's** value. Conversely, an
**over-broad** reset wipes *framework* state that legitimately persists per worker.

**Where to look.** The per-request reset gate
(`App::perRequestStateResetsActive()` = `silent_redeclare && (function_isolation ||
include_isolation)`) and the **boot-snapshot exemption**
(`zealphp_process_state_snapshot`) — which symbols are protected from reset. A gap
here is either a UAF/crash of framework state **or** a leak of app state.

**Why critical.** Both directions are bugs: app state persisting cross-request
(weaker cross-tenant leak), or framework state (`App::$routes`, the middleware stack,
`Store`/`Counter` backends) getting zeroed → `handle() on null` on request 2+ (this
exact regression shipped once — gating the resets on **bare** `silentRedeclare`
without the snapshot exemption, ZealPHP issue `#227`).

**Representative status.** The per-request resets landed in 0.3.25; the `#227`
gating fix landed in ZealPHP `0.4.1`. Validated across a 12-app sweep — but the
**gate + exemption logic is the highest-leverage place to look for a regression.**

---

## 4. The high-risk surface (where to focus first)

Audit these in roughly this order — highest leak/UAF density first:

1. **The per-state snapshot walks (×7 state types).** For each
   `*_snapshot_save` / `*_snapshot_restore` / `*_reset_to_parent`: does it deref
   `IS_REFERENCE` **and** `IS_INDIRECT`? Does it reset the live table after capture?
   Does it correctly skip vs isolate objects/resources? (Class 2.)
2. **Free sites in the per-request reset/clear functions** vs the `run_time_cache`
   reset coverage. Any user-symbol free not paired with a slot reset is a UAF. (Class 1.)
3. **The scheduler-hook keying** — `arg` pointer vs `os_get_cid()`. Any per-coroutine
   table read/written in an `on_resume` path keyed on `os_get_cid()` is suspect.
4. **The silent-redeclare CG-swap + first-wins merge** — orphaned losers (leak,
   Class 3) and the inherited-class `default_properties_table` corruption (UAF/crash).
5. **The boot-snapshot exemption + per-request reset gate** — over-reset wipes
   framework state; under-reset leaks app state. (Class 5.)
6. **Every `zend_execute_ex` / `zend_call_*` of user code without a `zend_try`** —
   bailout leaks (Class 3).
7. **`Stage-8` `require_global`** — true-global-scope includes produce `IS_INDIRECT`
   CVs in `EG(symbol_table)`; the bucket-stable reset and the globals walks must stay
   IS_INDIRECT-aware (Class 2).

---

## 5. Known-open frontier (be skeptical here)

These are **documented limits, not yet fixed** — the most promising research targets:

- **`#12` orphaned inherited-class leak** — structural; the orphan aliases
  process-shared winner-owned structures. A naive reclaim re-opens a SIGSEGV.
- **`#16` same-name request constant** — structural; one shared `EG(zend_constants)`
  can't isolate the same constant name with different values across coroutines.
- **Cold-concurrent autoload** — a class with `extends`/`implements` first compiled
  by overlapping coroutines can land present-but-`UNLINKED`, transiently failing
  `new`/`class_exists` on the first cold wave (a PHP delayed-early-binding race, not
  ASAN-visible). Mitigated by preloading; not eliminated.
- **`mysqlnd` / `libtasn1` connection-teardown heap-overflow** — a `$wpdb` socket
  close under `HOOK_ALL` trips a cold-boot heap overflow (reproduces on request 1,
  independent of the isolation stack). The heavy/concurrent-WordPress frontier.

See `.claude/CLAUDE.md` (search "frontier", "honest boundary", "cold-concurrent") and
[`docs/architecture/state-isolation-reference.md`](./architecture/state-isolation-reference.md)
for the full limits list.

---

## 6. How to research it (proven methodology)

You test the extension **through a running ZealPHP coroutine-legacy server** — the
isolation needs the live scheduler. The loop that found and validated every fix above:

**Build instrumented PHP + the extension.** Use ASAN and Valgrind PHP builds with
OpenSwoole + ext-zealphp loaded. (The maintainer's rig: `php84-asan-nd` for UAF/
double-free, `php84-vg` for leaks with OpenSwoole suppressions. ASAN uses
`USE_ZEND_ALLOC=0` so the Zend allocator doesn't hide frees; `ASAN_OPTIONS=detect_leaks=0`
because LeakSanitizer is noisy under OpenSwoole — Valgrind is the leak oracle.)

**Drive it two ways:**
- **A real coroutine server under load** — boot a ZealPHP app in `coroutine-legacy`,
  hammer one endpoint with concurrent `curl` / `curl_multi` (≥40 connections at a
  freshly-booted worker for cold-concurrent races), grep the ASAN/Valgrind output for
  `AddressSanitizer` / `Invalid read|write` / `definitely lost`.
- **Targeted `.phpt` repros** that simulate concurrency directly — the proven pattern
  (see ext-zealphp `tests/046`–`049`): `OpenSwoole\Coroutine::run` + `go()` + a
  `Timer::after` + `Channel::pop` to force interleaved yields, with an
  `OpenSwoole\Atomic` leak-counter asserting **zero** cross-coroutine leakage.

**The probe → instrument → repro → fix → re-validate loop** (how #10/#14 were
root-caused): write a concurrent repro that *reproduces the leak deterministically*;
when reasoning stalls, **instrument the suspect C** (`fprintf(stderr, ...)` the engine
state — zval type, `IS_INDIRECT`/`IS_REFERENCE` flags, table membership) to see what's
actually happening (this is how `IS_INDIRECT` was found: `STbaseline=Y(t12)`, type
12 = `IS_INDIRECT`); fix; then re-validate **bidirectionally**.

**The bar — bidirectional validation.** A fix is not proven until a test **FAILS
without it and PASSES with it**, under both ASAN and Valgrind. "I reasoned it's
correct" is not enough — two fixes in this very audit *looked* right and were caught
insufficient only because a concurrent test was written first (the restore-only #15
attempt left 10 leaks; the baseline-only #10 attempt left 5). Write the failing test,
then fix.

---

## 7. Reference

- **ext-zealphp issue tracker** — the audit issues `#8`–`#18` (each with a root-cause
  writeup; `#12`, `#16` are the open structural ones).
- **ext-zealphp `tests/0NN-*.phpt`** — each numbered test pins one behaviour; the
  `04x`/`05x` range covers the isolation leak/UAF classes (e.g. `045` constant-free
  deferral, `046`/`047` superglobal isolation + round-trip, `048`/`049` global-write
  isolation).
- **ext-zealphp `ARCHITECTURE.md`** — the C internals, subsystem by subsystem, with
  the memory-management tiers and the "what can still leak" section.
- [`docs/architecture/state-isolation-reference.md`](./architecture/state-isolation-reference.md)
  — framework view, the per-coroutine trust-bar, and the SAPI-contract coverage
  derivation.
- [`fuzzing.md`](./fuzzing.md) — the complementary adversarial HTTP / framing axis.
- [`SECURITY.md`](../SECURITY.md) — coordinated disclosure (private report to
  sibi@selfmade.ninja; 48-hour acknowledgement).

> **Disclosure reminder.** A confirmed cross-tenant leak or use-after-free in the
> isolation runtime is a critical finding — report it privately, with a deterministic
> repro and your ASAN/Valgrind output, before any public discussion.
