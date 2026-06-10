# ext-zealphp production-readiness review (2026-05-29)

Evidence-grounded verdict from a multi-reviewer assessment (5 independent
dimension reviewers reading the post-hardening-pass-2 source at `406c932` +
the 50-app sweep / gdb evidence, then adversarial challenge from both the
optimist and pessimist directions, then a balanced synthesis).

**Subject:** working tree `406c932`, header-labeled `0.3.14` (note: untagged —
`git describe` = `v0.3.7-26-g406c932`; 0.3.8–0.3.14 are header bumps without
matching git tags).

---

## Overall verdict: **works with real caveats — not upstream-grade, not "any PHP just works concurrently"**

Genuinely well-engineered, self-aware, engine-deep C that delivers real
per-coroutine isolation for an explicitly enumerated request-state contract
(the TrustBar test: **0/40 leaks** under 40 concurrent interleaved requests vs
raw OpenSwoole's 39/40) and removed the dominant Stage-6 use-after-free. But the
headline "old PHP just works *concurrently*" is gated by two source-confirmed
open issues, so it is production-ready only for a defined scope, with a working
`cgiMode('pool')` fallback.

**Not hacky. Not done.**

## Readiness scorecard

| Dimension | Score /10 | Verdict |
|---|---|---|
| Memory safety | **8** | Dominant Stage-6 UAF gone (grep-confirmed: the `(*refcount)++` pattern survives only in comments); two hardening passes closed C1/C2/H2/H3/H4 with real fixes; CG-swap is bailout-safe. A previously-feared Stage-4 loser-destroy was **downgraded HIGH→LOW** after control-flow review (frees only fresh request-heap dups; the hook body doesn't run on an OPcache cache hit) — **OPcache-on production is fine**. Two LOW footguns remain. |
| Coroutine correctness | **5** | Chaining sandwich is correct (resume chains original FIRST, yield LAST; C2 no-NULL refusal real). BUT the Stage-4 CG-swap repoints **process-global** `CG(function_table)/class_table` at stack-local scratch with **no yield guard** — a nested compile that yields under HOOK_FILE lets another coroutine read the empty table. Root of the OPEN phpMyAdmin lost-wakeup; a class-level defect. |
| Upstream acceptability | **5** | Shippable as a scoped PECL/pie package (how it ships). **Not** php-src core material: `dlsym` of mangled C++ OpenSwoole symbols couples to a private/unstable ABI; hand-written arginfo (no `.stub.php`) is a mechanical reject. Uneven opcode-handler chaining (DECLARE_*/INCLUDE_OR_EVAL clobber prior handlers, unlike BIND_STATIC). |
| Test coverage | **5** | Solid single-threaded `.phpt` (30 tests) + 2 past-segfault regressions. Blind spot exactly where the open bugs live: **no test drives `go()`/`Coroutine::run` through the dlsym'd yield/resume/close wrappers**; no valgrind/ASAN harness; the only concurrency proof is the env-gated TrustBar test (logical, not memory, isolation). |
| "Old PHP just works" claim | **5** | Half-true, honestly documented. ~19/50 apps work **sequentially**. The one thing coroutine-legacy adds over cgi-pool — legacy code under genuine concurrency — is **undelivered for DB-backed apps** (no per-coroutine DB pool). |

## Production-ready FOR

- Hosting well-behaved, already-installed traditional PHP apps (WordPress,
  Drupal, Joomla, MediaWiki, roundcube, matomo, …) under **sequential / low
  concurrency** in coroutine-legacy.
- Per-coroutine isolation of the enumerated contract (7 superglobals, class
  statics, `$GLOBALS` COW delta, `define()`, `ini_set`, `putenv/getenv`,
  `header/setcookie`, function-local `static $x`) under genuine concurrency —
  TrustBar 0/40 at ~µs/yield vs cgi-pool's ~30–50 ms `proc_open` fork.
- Distribution as a scoped PECL/pie package (`pie install zealphp/ext`),
  NTS-only.
- DB-backed apps via the `cgiMode('pool')` subprocess fallback (returns 200 for
  apps coroutine-legacy can't host in-process, e.g. phpMyAdmin).
- OPcache enabled.

## NOT ready for

- **Concurrent** unmodified WordPress / any DB-backed legacy app **in-process** —
  no per-coroutine MySQL/PDO pool exists (only `RedisConnectionPool` + CGI
  `WorkerPool` ship; `DB.php` is a bare shared `\PDO`). A shared `$pdo`/`$wpdb`
  across coroutines corrupts the wire.
- Deeply-recursive bootstraps under HOOK_FILE (phpMyAdmin's Symfony-DI build) —
  the OPEN compile-hook yield race hangs the worker (lost-wakeup).
- php-src core inclusion (mangled-C++ dlsym + no `.stub.php`).
- "Any binary blob of PHP runs unchanged" — false (dep-version conflicts, CLI
  `argv` assumptions, un-isolated process-global primitives, closure statics,
  H1 nested `$_SESSION` objects).

## "PHP just works" — honest numbers

- **~90–95%** solid for the in-process **state-isolation contract itself**
  (TrustBar 0/40, dominant UAF gone, OPcache hazard downgraded).
- **~60%** for the **stated scope** (sequential, well-behaved, already-installed
  apps, with cgi-pool for DB-heavy ones).
- **~30–40%** for the **literal headline** ("unmodified PHP under coroutine
  concurrency") — gated almost entirely by the two deep gaps below.

## Top blocking issues

| Issue | Severity | Effort |
|---|---|---|
| Compile-hook CG-swap not yield-safe (process-global CG → stack-local scratch, no yield/HOOK_FILE guard). Root of the phpMyAdmin lost-wakeup; class-level. | critical | deep-redesign |
| No per-coroutine DB connection pool — concurrent DB-backed hosting undelivered in-process. | critical | deep-redesign |
| Zero real-coroutine `.phpt` — scheduler-integration code never exercised by the unit suite; no valgrind/ASAN. | high | focused (tests) |
| Uneven opcode-handler chaining — DECLARE_*/INCLUDE_OR_EVAL don't capture-and-chain (break uopz/datadog/blackfire coexistence). | high | focused-pass |
| dlsym of mangled C++ symbols + no `.stub.php` → permanently bars php-src core. | high | (accept the PECL ceiling) |
| H1 — objects nested in `$_SESSION` arrays whole-array `ZVAL_DUP`'d (less acute than top-level via COW refcount-bump). | medium | focused-pass |
| Two coroutine-identity schemes (`(uintptr_t)Coroutine*` vs `os_get_cid()`) — pointer-reuse footgun; unify on monotonic cid. | medium | focused-pass |
| Stage-4 loser-destroy lacks a `ZEND_ACC_IMMUTABLE` assert (hygiene only — not the SHM hazard feared). | low | trivial |
| Version/provenance: `0.3.14` header has no matching git tag. | low | trivial |

## Path forward (in rough priority)

1. **Make the compile window yield-atomic** — disable the OpenSwoole scheduler /
   HOOK_FILE for the duration of `zealphp_original_compile_file` so no yield can
   occur while CG is swapped, OR drop the global-CG-swap for a
   `do_bind_function`/`do_bind_class`-level first-wins hook that never mutates the
   global table pointer. (Closes the lost-wakeup; deep.)
2. **Per-coroutine MySQL/PDO pool** mirroring `RedisConnectionPool` (per-worker
   `Coroutine\Channel` of N clients) — the single biggest gap between claim and
   reality.
3. **Real-coroutine `.phpt`** driving `go()`/`Coroutine::run` through the
   yield/resume/close wrappers + a valgrind/ASAN leak target.
4. **Normalize opcode-handler chaining** (capture-and-chain + compare-and-restore
   the DECLARE_*/INCLUDE_OR_EVAL/define handlers like BIND_STATIC already does).
5. Generate arginfo from a `.stub.php`; unify the two coroutine-identity schemes
   on the monotonic cid; add the `ZEND_ACC_IMMUTABLE` assert; tag a real release.
6. Close H1 (skip objects/resources nested inside snapshotted superglobal arrays).
7. Accept that the mangled-C++ dlsym permanently bars php-src core — the
   standalone OpenSwoole-glue PECL/pie package is the right ceiling.

## Bottom line

ext-zealphp is the real thing — careful, self-aware, engine-deep C that solves
per-coroutine request-state isolation for a defined 14-primitive contract, with
two honest hardening passes that removed the dominant UAF and closed
C1/C2/H2/H3/H4. It is **production-ready for a scope** (well-behaved installed
apps, sequential, scoped PECL package, cgi-pool escape hatch) and OPcache-safe.
It is **not** upstream-grade and **not** "any PHP just works concurrently" — that
is gated by two open deep issues that matter more than all the polish: the
compile-hook CG-swap with no yield guard (the phpMyAdmin lost-wakeup), and the
missing per-coroutine DB pool. The project's own docs name every one of these
gaps — which is exactly why the fair verdict is "works with real caveats," not
"ready" and not "hacky."
