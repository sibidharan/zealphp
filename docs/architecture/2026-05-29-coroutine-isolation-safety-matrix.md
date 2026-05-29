# Coroutine isolation — how it works, and the safety matrix

**The win we are after:** an old-school PHP developer writes request-style code
(superglobals, `define()`, `static $x`, class statics, `ini_set`) and it *just
works* under OpenSwoole coroutine concurrency — one OS process, many concurrent
requests, each with the fresh-per-request state the PHP-FPM mental model
promises. This document is the result of a deep gdb/strace/isolation-matrix
investigation (the 50-app sweep, phpMyAdmin as the hardest benchmark). It states,
stage by stage, **what is provably coroutine-safe, what isn't, and which patterns
a greenfield ZealPHP app must avoid.**

Legacy apps are the *benchmark*, not the goal: they show us where the model
breaks. Making any unmodified legacy app run 100% is a bonus; making the *mental
model* sound is the deliverable.

---

## The mental model

A single PHP process runs N coroutines (one per in-flight request). PHP's
request-state primitives — superglobals, `$GLOBALS`, function/class statics,
user constants, `ini_set` — are **process-global C state**. Raw OpenSwoole shares
them across coroutines, so request B sees request A's `$_SESSION`, `static $x`,
etc. (`TrustBarIsolationTest`: raw OpenSwoole leaks 39/40 under 40 interleaved
requests). ext-zealphp makes each primitive **per-coroutine** by hooking
OpenSwoole's scheduler (`on_yield`/`on_resume`/`on_close`) and snapshotting /
restoring state across every coroutine switch.

There are two fundamentally different kinds of isolation, and they have very
different safety profiles:

| Kind | What it covers | Mechanism | Coroutine-safety |
|---|---|---|---|
| **State isolation** | superglobals, `$GLOBALS`, class statics, function statics, constants, ini, env | save state on yield / restore on resume, keyed by the `Coroutine*` pointer | **Proven safe (10/10)** |
| **Code isolation** | silent redeclaration, include re-execution | rewrite the compiler/opcode behaviour so re-run code doesn't fatal | **Fragile** — see the two hazards below |

**State isolation** is the mental model. It is safe. **Code isolation** exists
only to tolerate *badly-behaved* legacy code that redeclares functions/classes or
puts per-request logic in `require_once`'d files — patterns a greenfield app
should simply not use.

> **A note on names.** This document uses **descriptive names** (the ones the
> public API uses): *superglobal isolation*, *global-variable isolation*,
> *class-static isolation*, *function-static isolation*, *constant isolation*,
> *ini isolation*, *silent redeclaration*, *include re-execution*. The source and
> older docs also carry historical internal labels (*Stage 2*, *Level 2/3*,
> *Stage 3/3.5/4/5/7*); those are noted in parentheses only where you need them to
> cross-reference the C code. The descriptive names are canonical.

---

## State isolation — proven coroutine-safe

Each of these is saved on `on_yield` and restored on `on_resume`, keyed by the
**`Coroutine*` pointer** passed to the scheduler callback (NOT `os_get_cid()` —
see the identity note). The empirical proof is `TrustBarIsolationTest` (40
concurrent interleaved requests, **0/40 leakage** across the full contract) plus
the isolation matrix below (superglobal- and global-isolation rows: phpMyAdmin
all-200 under both sequential and concurrent load).

| Isolation | Isolates | Setter | (internal) |
|---|---|---|---|
| **Superglobal isolation** | `$_GET $_POST $_REQUEST $_COOKIE $_FILES $_SERVER $_SESSION` (+ `header()`/`setcookie()`/`http_response_code()`) | implicit in coroutine mode | base |
| **Global-variable isolation** | `$GLOBALS` / `global $x` (COW delta vs a once-captured parent baseline; objects/resources/refs skipped) | `coroutineGlobalsIsolation()` | Stage 2 |
| **Class-static isolation** | class `static` properties | implicit | Level 2 |
| **Function-static isolation** | function-local `static $x` (touched-set registry on the `ZEND_BIND_STATIC` opcode) | `coroutineStaticsIsolation()` | Stage 5 |
| **Constant isolation** | per-request `define()` constants | `defineIsolation()` | Stage 3.5 |
| **INI isolation** | `ini_set()` changes (**except `session.*`** — see below) | implicit | Level 3 |
| **Environment isolation** | `putenv()`/`getenv()` | implicit | — |

**Coroutine-identity rule (load-bearing).** Snapshots key on the `Coroutine*`
pointer, never `os_get_cid()`. Empirically (cid-probe, 3 concurrent coroutines):
`os_get_cid()` returns the right cid in `on_yield`/`on_close` but **`-1` on every
`on_resume`** (the scheduler hasn't installed the resuming coroutine as "current"
yet). Keying restores on `os_get_cid()` would look up `hash[-1]` and silently
restore nothing → cross-coroutine corruption. The pointer is correct in all three
callbacks; pointer reuse is safe because `on_close` deletes the snapshot before
the struct can be freed. Include re-execution's reincluded-set is the deliberate exception (it
runs only in PHP-execution context + `on_close`, where `os_get_cid()` is
reliable; separate hash, never collides).

**`session.*` is NOT ini-snapshot-isolated** (a pattern that *can't* be). Those
directives have a stateful `on_modify` (`OnUpdateSession*`) that rejects changes
once a session is active / headers sent, emitting *"Session ini settings cannot
be changed after headers have already been sent"* on every yield/resume that
re-applies them — a per-switch warning flood that feeds the async logger and can
wedge a worker. Sessions are owned by the framework's per-coroutine session layer
anyway. **General rule: ini directives whose `on_modify` has side effects or is
stage-gated cannot be snapshot-isolated.**

---

## Code isolation — the two hazards

These mechanisms manipulate the compiler and the `require_once` cache so that
legacy code which re-declares or re-includes per request doesn't fatal. They are
where coroutine-safety gets hard, because **compilation touches process-global
compiler state and (under OPcache) shared memory.**

### Silent redeclaration — the CG-swap (internal: Stage 3/4)

`silentRedeclare(true)` installs a `zend_compile_file` hook that swaps the
process-global `CG(function_table)` / `CG(class_table)` to stack-local scratch
tables for the duration of each compile (so a top-level `function`/`class`
re-declaration sees an empty table and wins-first instead of fataling), then
first-wins-merges scratch into the real tables.

> **HAZARD 1 — compile-time yield (FIXED).** HOOK_FILE coroutinizes the source-
> file read *inside* `zend_compile_file`. If that read yields while CG points at
> the stack-local scratch and a `zend_try` bailout frame is live, the coroutine
> switch corrupts engine state: a worker **SIGSEGV under OPcache**, a **lost-
> wakeup hang** without it (gdb: all procs idle in `epoll_wait`, the request
> coroutine suspended forever). Confirmed by the control `silentRedeclare +
> HOOK_ALL&~HOOK_FILE` → 0 crashes / 0 hangs.
>
> **Fix (shipped):** `App::run()` drops `HOOK_FILE` from the resolved hook flags
> whenever `silentRedeclare + enableCoroutine` are both active. The compile-time
> file read then runs **blocking** (cannot yield) → the CG-swap window is atomic.
> Network/socket/sleep hooks stay on, so coroutine concurrency for I/O-bound work
> is unaffected; only file I/O is synchronous under the compile hook. Override
> with `ZEALPHP_ALLOW_COMPILE_HOOK_FILE=1`. (A per-compile `enable/disable_hook`
> toggle inside the extension was tried and is *worse* — mid-request wrapper swaps
> have side effects — so the fix is framework-side, not a C hack.)

The merge also **never frees an immutable (OPcache SHM) op_array/class** on the
loser branch (`ZEND_ACC_IMMUTABLE` guard) — freeing SHM the process still
executes is a use-after-free.

### Include re-execution (internal: Stage 7)

`includeIsolation(true)` hooks `ZEND_INCLUDE_OR_EVAL`: for a `require_once` of a
non-bootstrap file, it deletes the file from `EG(included_files)` so the engine
re-includes (re-compiles + re-executes) it — letting per-request logic in
`require_once`'d files run every request. A per-coroutine once-guard keeps it
idempotent within a request.

> **HAZARD 2 — re-execution corrupts the VM on a deep-bootstrap app (OPEN).**
> With include re-execution on, phpMyAdmin's Symfony-DI bootstrap crashes the
> worker with `__stack_chk_fail` "stack smashing detected" (SIGABRT) in the PHP
> VM (`execute_ex` vicinity), accumulating over a few requests. It is reached
> only via include re-execution (`includeIsolation` off → the app 500s early on a
> missing constant and never reaches the deep build), so re-execution *triggers*
> it; whether re-execution *causes* it or merely lets the app run far enough to
> hit a pre-existing depth/state problem is unresolved.
>
> **What the deep investigation established (and ruled out):**
> - **Not OPcache-specific.** Crashes with OPcache on *and* off (once HAZARD 1's
>   HOOK_FILE drop is in place). The earlier "needs OPcache" reading was an
>   artifact of HAZARD 1 masking it as a hang when OPcache was off.
> - **Not unbounded re-execution.** The per-coroutine once-guard works: only ~5
>   files re-execute per request (seen-set ≤13), and each request gets a fresh,
>   cleanly-incrementing coroutine id — no cid reuse, no runaway recursion.
> - **Not the CG-swap, not the immutable-SHM-free, not the Stage-5 registry** —
>   each was isolated out (the immutable-op_array hypothesis failed because
>   OPcache is the *outermost* `zend_compile_file` wrapper and returns SHM
>   op_arrays on cache hits without ever calling our hook).
> - **Stack-related but not stack-size-fixable.** Coroutine stack 2 MB → crash
>   every 4th request, 16 MB → every 5th, 32 MB → every 2nd (worse). Non-monotonic
>   — bigger stacks add memory pressure rather than headroom, so it is not a clean
>   "deepen the stack" fix.
> - **Volume/app-specific.** The ~19 other sweep apps (adminer, mediawiki,
>   wordpress, drupal, joomla, …) are clean in the same full config — only
>   phpMyAdmin's deep DI build hits it.
>
> **Status:** root-causing further is blocked on **PHP debug symbols** — the
> crashing frame is an unsymbolicated address in the custom `-O2` PHP build, so
> the exact smashed buffer/function is unknown. The next step is a PHP `-g` build
> (or `php8.x-dbgsym`) on an isolated VM to symbolicate the `__stack_chk_fail`
> frame, then fix the specific overflow. **Until then, deep-bootstrap apps like
> phpMyAdmin run via `cgiMode('pool')`** (subprocess per request, no shared
> coroutine scheduler), where phpMyAdmin returns 200. This is the documented
> boundary, not the greenfield contract (which never relies on re-execution).

---

## The isolation matrix (phpMyAdmin, the hardest benchmark)

Each stage layered on, worker_num=1, OPcache on. `200` = works, `000` = hang,
`500` = clean error, `CRASH` = worker SIGSEGV/abort.

| config | result | reading |
|---|---|---|
| superglobal isolation only | **all 200** | state isolation is safe |
| + global-variable isolation | **all 200** | safe |
| + silent redeclaration, HOOK_FILE on | all 000 / CRASH | HAZARD 1 |
| + silent redeclaration, `HOOK_ALL&~HOOK_FILE` | **0 crash / 0 hang** (200 + Bug-A 500s) | HAZARD 1 fix proven |
| + include re-execution, HOOK_FILE on | all 000 / CRASH | HAZARD 1 (re-compile yields) |
| full coroutine-legacy, **HOOK_FILE fix on** | `200 200 200 000` + residual CRASH | HAZARD 1 fixed; HAZARD 2 residual |
| full, HOOK_FILE fix on, OPcache **off** | still `200 200 200 000` CRASH | HAZARD 2 is **not** OPcache-specific |
| HAZARD 2, coroutine stack 2/16/32 MB | crash every 4th / 5th / 2nd | stack-related but **non-monotonic** (not stack-size-fixable) |

The 19 well-behaved apps (adminer, mediawiki, joomla, roundcube, drupal,
wordpress, mybb, privatebin, …) are green in full coroutine-legacy with the
HOOK_FILE fix — **zero regressions.**

---

## Greenfield rules — what to avoid

To stay in the *proven-safe* (state-isolation) lane, a greenfield ZealPHP app should:

1. **Declare functions/classes once** (Composer autoload), never conditionally
   re-declare them per request. → you never need `silentRedeclare`; you never
   touch the CG-swap.
2. **Put per-request logic in functions/handlers, not in `require_once`'d files
   that you expect to re-run.** `require_once` means *once* — don't rely on
   re-execution. → you never need `includeIsolation`; you never touch HAZARD 2.
3. **Use `$g->get`/`$_SESSION`/`static $x`/`define()`/`ini_set()` freely** — these
   are snapshot-isolated and safe per request.
4. **Don't `ini_set('session.*')`** at runtime — use the framework session layer.
5. **One DB connection per coroutine** (a pool) — a shared `$pdo` corrupts the
   wire under concurrency (a separate, documented boundary; not isolation).
6. **Don't `fork`/`pcntl`/`set_time_limit`** inside a coroutine worker.

A greenfield app following these uses **only state isolation** — the 10/10 lane.
Code isolation (silent redeclaration, include re-execution) exists to drag
*unmodified legacy* code into the coroutine world; it is a compatibility shim
with the caveats above, not part of the greenfield contract.

---

## Honest status

- **State isolation: 10/10 coroutine-safe.** The greenfield mental model works.
- **Silent redeclaration (HAZARD 1): fixed.** Dropping HOOK_FILE under the compile
  hook makes the CG-swap atomic; no regressions on the working set.
- **Include re-execution on a deep-bootstrap app (HAZARD 2): open.** An
  app-specific `__stack_chk_fail` VM crash on phpMyAdmin's Symfony-DI build,
  reached via re-execution. Ruled out: OPcache-specificity, unbounded recursion,
  the CG-swap, immutable-SHM-free, the Stage-5 registry, and stack-size (all
  tested). Root-causing further is blocked on PHP debug symbols to identify the
  smashed frame. `cgiMode('pool')` is the supported fallback; the ~19 other sweep
  apps are unaffected.

Canonical companions: `docs/architecture/2026-05-28-isolation-trust-bar.md`
(the trust-bar matrix), `docs/architecture/2026-05-29-50app-sweep-findings.md`
(the sweep), `docs/architecture/2026-05-29-ext-readiness-review.md` (the
production-readiness verdict).
