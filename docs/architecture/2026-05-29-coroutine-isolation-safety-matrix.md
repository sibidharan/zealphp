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

| Kind | Stages | Mechanism | Coroutine-safety |
|---|---|---|---|
| **Snapshot isolation** | superglobals, `$GLOBALS`, class statics, function statics, constants, ini | save state on yield / restore on resume, keyed by the `Coroutine*` pointer | **Proven safe (10/10)** |
| **Compile/re-exec isolation** | silent-redeclare (CG-swap), include-isolation (re-execute `require_once`) | rewrite the compiler/opcode behaviour so re-run code doesn't fatal | **Fragile** — see the two hazards below |

The snapshot stages are the mental model. They are safe. The compile/re-exec
stages exist only to tolerate *badly-behaved* legacy code that redeclares
functions/classes or puts per-request logic in `require_once`'d files — patterns
a greenfield app should simply not use.

---

## Snapshot isolation — proven coroutine-safe

Each of these is saved on `on_yield` and restored on `on_resume`, keyed by the
**`Coroutine*` pointer** passed to the scheduler callback (NOT `os_get_cid()` —
see the identity note). The empirical proof is `TrustBarIsolationTest` (40
concurrent interleaved requests, **0/40 leakage** across the full contract) plus
the isolation matrix below (`min`/`cg` rows: phpMyAdmin all-200 under both
sequential and concurrent load).

| Stage | Isolates | Setter |
|---|---|---|
| base | `$_GET $_POST $_REQUEST $_COOKIE $_FILES $_SERVER $_SESSION` (+ `header()`/`setcookie()`/`http_response_code()`) | implicit in coroutine mode |
| Stage 2 | `$GLOBALS` / `global $x` (COW delta vs a once-captured parent baseline; objects/resources/refs skipped) | `coroutineGlobalsIsolation()` |
| Level 2 | class `static` properties | implicit |
| Level 3 | `ini_set()` changes (**except `session.*`** — see below) | implicit |
| Stage 5 | function-local `static $x` (ZEND_BIND_STATIC touched-set registry) | `coroutineStaticsIsolation()` |
| constants | per-request `define()` constants | `defineIsolation()` |
| env | `putenv()`/`getenv()` | implicit |

**Coroutine-identity rule (load-bearing).** Snapshots key on the `Coroutine*`
pointer, never `os_get_cid()`. Empirically (cid-probe, 3 concurrent coroutines):
`os_get_cid()` returns the right cid in `on_yield`/`on_close` but **`-1` on every
`on_resume`** (the scheduler hasn't installed the resuming coroutine as "current"
yet). Keying restores on `os_get_cid()` would look up `hash[-1]` and silently
restore nothing → cross-coroutine corruption. The pointer is correct in all three
callbacks; pointer reuse is safe because `on_close` deletes the snapshot before
the struct can be freed. Stage 7's reincluded-set is the deliberate exception (it
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

## Compile / re-execution isolation — the two hazards

These stages manipulate the compiler and the `require_once` cache so that legacy
code which re-declares or re-includes per request doesn't fatal. They are where
coroutine-safety gets hard, because **compilation touches process-global compiler
state and (under OPcache) shared memory.**

### Stage 3/4 — silent-redeclare (the CG-swap)

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

### Stage 7 — include-isolation (re-execute `require_once`)

`includeIsolation(true)` hooks `ZEND_INCLUDE_OR_EVAL`: for a `require_once` of a
non-bootstrap file, it deletes the file from `EG(included_files)` so the engine
re-includes (re-compiles + re-executes) it — letting per-request logic in
`require_once`'d files run every request. A per-coroutine once-guard keeps it
idempotent within a request.

> **HAZARD 2 — re-execution × OPcache (OPEN).** Re-executing an OPcache-cached
> script (delete-from-`included_files` → re-include → cache-hit returns the SHM
> op_array → re-execute) corrupts engine op_array state under high re-exec volume
> — a `__stack_chk_fail` "stack smashing detected" abort in the VM, accumulating
> over a few requests. Confirmed: requires Stage 7 **and** OPcache (OPcache off →
> hang instead of crash; Stage 7 off → no crash). Independent of HAZARD 1 (it
> persists after the HOOK_FILE fix). Volume-triggered: the ~19 well-behaved sweep
> apps are fine; only phpMyAdmin's Symfony-DI bootstrap (hundreds of re-executed
> service files per request) hits it.
>
> **Status:** a proper fix needs Stage 7 to be OPcache-aware (re-execute without
> the delete-from-`included_files` dance, or invalidate the SHM entry too) — a
> non-trivial redesign. **Until then, compile/re-exec-heavy apps run via
> `cgiMode('pool')`** (subprocess per request, no shared scheduler), where
> phpMyAdmin returns 200.

---

## The isolation matrix (phpMyAdmin, the hardest benchmark)

Each stage layered on, worker_num=1, OPcache on. `200` = works, `000` = hang,
`500` = clean error, `CRASH` = worker SIGSEGV/abort.

| config | result | reading |
|---|---|---|
| base superglobal snapshot (`min`) | **all 200** | snapshot isolation is safe |
| + `$GLOBALS` Stage 2 (`cg`) | **all 200** | safe |
| + silentRedeclare, HOOK_FILE on (`sr`) | all 000 / CRASH | HAZARD 1 |
| + silentRedeclare, `HOOK_ALL&~HOOK_FILE` | **0 crash / 0 hang** (200 + Bug-A 500s) | HAZARD 1 fix proven |
| + includeIsolation, HOOK_FILE on (`ii`) | all 000 / CRASH | HAZARD 1 (re-compile yields) |
| full coroutine-legacy, **HOOK_FILE fix on** | `200 200 200 000` + residual CRASH | HAZARD 1 fixed; HAZARD 2 residual |
| full, OPcache off | all 000 (hang), **0 crash** | HAZARD 2 needs OPcache |

The 19 well-behaved apps (adminer, mediawiki, joomla, roundcube, drupal,
wordpress, mybb, privatebin, …) are green in full coroutine-legacy with the
HOOK_FILE fix — **zero regressions.**

---

## Greenfield rules — what to avoid

To stay in the *proven-safe* (snapshot) lane, a greenfield ZealPHP app should:

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

A greenfield app following these uses **only snapshot isolation** — the 10/10
lane. The compile/re-exec stages exist to drag *unmodified legacy* code into the
coroutine world; they are a compatibility shim with the caveats above, not part
of the greenfield contract.

---

## Honest status

- **Snapshot isolation: 10/10 coroutine-safe.** The greenfield mental model works.
- **silent-redeclare (HAZARD 1): fixed.** Dropping HOOK_FILE under the compile
  hook makes the CG-swap atomic; no regressions on the working set.
- **include-isolation × OPcache (HAZARD 2): open.** A volume-triggered op_array
  corruption on compile/re-exec-heavy legacy apps (phpMyAdmin). `cgiMode('pool')`
  is the supported fallback; a Stage-7 OPcache-aware redesign is the real fix.

Canonical companions: `docs/architecture/2026-05-28-isolation-trust-bar.md`
(the trust-bar matrix), `docs/architecture/2026-05-29-50app-sweep-findings.md`
(the sweep), `docs/architecture/2026-05-29-ext-readiness-review.md` (the
production-readiness verdict).
