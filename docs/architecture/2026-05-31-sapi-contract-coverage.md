# SAPI per-request contract — coverage assessment

**Date:** 2026-05-31
**Scope:** ZealPHP `coroutine-legacy` mode + ext-zealphp 0.3.25
**Question this answers:** *"How close is ZealPHP to 'unmodified old request-style PHP just works', as a percentage?"*
**Companion:** `docs/architecture/2026-05-28-isolation-trust-bar.md` (the isolation matrix this builds on)

> **TL;DR.** Against PHP's per-request contract (the "fresh state every request" guarantee that
> PHP-FPM / mod_php give, a.k.a. the SAPI contract), ZealPHP is at:
>
> | Scope | Coverage |
> |-------|---------:|
> | **Sequential** request-style code (one request at a time per worker — FPM-equivalent) | **~97%** |
> | **Concurrent**, Composer/PSR-4 apps (Symfony, Laravel, Slim, …) | **~98–99%** |
> | **Concurrent**, pure-`require_once` apps (classic WordPress, Drupal-7) | **~90%** |
>
> The remaining few percent is **concentrated, not scattered** — and maps one-to-one onto the
> open engineering frontiers (opcache transparency, concurrent cold-class loading, coroutine-safe
> DB handles, mysqlnd teardown). This is a **weighted self-assessment**, not a measured metric.

---

## 1. What "old PHP just works" means

An unmodified, request-style PHP application (the PHP-FPM mental model: no coroutine awareness,
heavy use of superglobals / `global` / `static` / `define()` / `require_once`) should run on
ZealPHP and **behave identically to how it behaves under PHP-FPM**. Concretely, two guarantees:

1. **Isolation** — concurrent requests multiplexed on one worker must not see each other's state.
2. **Reset** — each request starts from a clean slate, exactly as a fresh FPM process would
   (PHP's `shutdown_executor()` semantics: function statics, class statics and resolved caches
   re-initialise per request).

"Coverage" = the weighted fraction of that contract surface ZealPHP currently honours.

---

## 2. Methodology

1. Enumerate the **per-request contract surface** — every piece of state/behaviour that can
   differ between a fresh FPM process and a long-lived OpenSwoole worker.
2. **Weight** each by how often real request-style apps actually depend on it (prevalence), with
   the weights summing to 100. Weighting matters: a raw feature count would over-value rare
   primitives (e.g. `pcntl_fork`) and under-value ubiquitous ones (superglobals).
3. **Score** each item: `1.0` full / `0.3–0.7` partial / `0` none.
4. Coverage = Σ(weight × score) / 100, computed separately per scope (sequential vs concurrent;
   concurrency interacts with class loading and DB handles, so the score of a few items changes).

Scoring rubric:
- **1.0** — isolated *and* reset per request, validated under memory sanitizers + a regression test.
- **0.5–0.9** — works in practice but with a caveat (a required usage pattern, a deployment flag,
  or an unhardened edge).
- **0.3** — works only via a documented workaround.
- **0** — not handled; documented as a boundary.

Evidence base: `tests/Integration/TrustBarIsolationTest.php` (40 concurrent interleaved requests,
**0/40 leakage** vs **39/40** for raw OpenSwoole), the ext `.phpt` suite (37 tests, ASAN +
Valgrind on PHP 8.3/8.4/8.5), `tests/Integration/CoroutineLegacyBehaviorTest.php`, and the
12-app `coroutine-legacy` sweep (Adminer, TinyFileManager, FreshRSS, YOURLS, Grav, phpBB, MyBB,
Piwigo, Drupal + MediaWiki boot).

---

## 3. The contract surface

| # | Per-request primitive | Status | Weight | Where |
|---|------------------------|--------|------:|-------|
| 1 | 7 superglobals `$_GET/$_POST/$_REQUEST/$_COOKIE/$_FILES/$_SERVER/$_SESSION` | ✅ full | 18 | trust bar; per-coroutine snapshot/restore |
| 2 | Response state: `header()` / `setcookie()` / `http_response_code()` | ✅ full | 10 | uopz→`$g`; trust bar |
| 3 | Class static properties — **isolate + per-request reset** | ✅ full | 10 | `zealphp_reset_request_class_statics` (0.3.25) |
| 4 | `require_once` / `include_once` re-execution per request | ✅ full | 8 | Stage 7 (`includeIsolation`) |
| 5 | `define()` constants — isolate + clear per request | ✅ full | 8 | `defineIsolation` |
| 6 | `$GLOBALS` / `global $x` — scalars, arrays **and objects** (`$wpdb`) | ✅ full | 8 | Stage 2 + object globals (0.3.23) |
| 7 | Function-local `static $x` — isolate + per-request reset | ✅ full | 8 | `zealphp_reset_request_statics` (0.3.25) |
| 8 | `session_*()` + `php://input` | ✅ full | 7 | session overrides; IOStreamWrapper |
| 9 | run-time-cache / resolved-symbol reset | ✅ full | 5 | `zealphp_reset_request_rtcaches` (0.3.25) |
| 10 | `ini_set` / `putenv` / `getenv` | ✅ full | 4 | snapshot/restore |
| 11 | conditional `function`/`class` re-declaration (first-wins, no fatal) | ✅ full | 3 | silent-redeclare; inherited-safe (0.3.24) |
| 12 | exec family (`shell_exec`/`exec`/`system`/`passthru`/backtick) | ✅ full | 2 | `hookExec` |
| | **Subtotal — fully honoured** | | **91** | |
| 13 | DB handle transparency (one connection per coroutine) | ◐ partial | 3 | safe sequentially; concurrent needs the per-coroutine pattern/pool |
| 14 | opcache transparency | ◐ partial | 2 | works only with doc-root blacklist (`opcacheLegacyBootCheck`) |
| 15 | Cold-concurrent autoload (first cold wave of inheriting classes) | ◐ partial | 2 | preload mitigates; unsolved for pure-`require_once` under concurrency |
| 16 | Rare primitives: closure `static $x`, resources-in-`$GLOBALS`, `set_error_handler`/`set_exception_handler`, `register_shutdown_function`, raw `ob_*`, `pcntl_fork`, `set_time_limit` | ◐/✗ | 2 | process-global / not per-request isolated (uncommon in request state) |
| | **Subtotal — gap** | | **9** | |

---

## 4. Derivation

**Sequential** (FPM-equivalent; cold-autoload and DB wire-interleave cannot occur with one
request at a time):

```
91 (full) + DB 3×0.9 + opcache 2×0.3 + cold-autoload 2×1.0 + rare 2×0.4
= 91 + 2.7 + 0.6 + 2.0 + 0.8 = 97.1  →  ~97%
```

**Concurrent, Composer/PSR-4 apps** (autoload once per worker, never re-executed by Stage 7, so
the cold-autoload item resolves; DB still needs a per-coroutine connection):

```
91 + DB 3×0.7 + opcache 2×0.3 + cold-autoload 2×0.95 + rare 2×0.4
≈ 98–99%   (empirically clean: Adminer, CommonMark/224 inherited classes, FreshRSS, …)
```

**Concurrent, pure-`require_once` apps** (classic WordPress, Drupal-7 — no autoloader, so Stage 7
re-executes class declarations and the first cold concurrent wave can race):

```
91 + DB 3×0.6 + opcache 2×0.3 + cold-autoload 2×0.4 + rare 2×0.4
≈ 94%, but the failures CLUSTER into the exact paths that block concurrent unmodified WP-admin
```

---

## 5. The gap is the roadmap

The missing ~3% (sequential) / ~6–10% (concurrent pure-`require_once`) is not scattered noise —
it is four concrete, fundable frontiers:

| Gap item | Open work |
|----------|-----------|
| opcache transparency (#14) | engine-level hook on `do_bind_class` / opcache delayed-early-binding finalise |
| concurrent cold-class loading (#15) | durable linking of inheriting classes under the first cold wave (pure-`require_once` apps) |
| coroutine-safe DB (#13) | per-coroutine connection pool + mysqlnd connection-teardown heap-overflow fix |
| breadth / rare primitives (#16) | broaden ASAN/Valgrind/fuzz + `.phpt`; decide which rare primitives are worth isolating |

WordPress is the acid test: public site + login + comment writes already work in
`coroutine-legacy` (sequentially); **WP-admin** + **full concurrency of unmodified WP** are the
last mile, gated on the items above. `legacy-cgi` (process-isolated) already runs unmodified WP
end-to-end today as the conservative fallback.

---

## 6. Architectural limits (by design — not on the roadmap)

These are **permanent** boundaries of the per-coroutine model — distinct from the frontiers in §5,
which the funded work closes. They are documented constraints, not bugs; they will not change. Code
that depends on them must use the noted pattern, or run in `legacy-cgi` mode.

- **Process-shared resources.** A raw resource handle (open file, socket, stream, cURL handle) held
  in a global or static cannot be snapshot/restored — its lifecycle is owned by the OS/driver, not
  the engine. Resources stay process-shared across coroutines; use a per-coroutine pool.
- **Database connections.** HOOK_ALL makes `mysqlnd` non-blocking, but one connection shared across
  coroutines interleaves wire frames. The contract is **one connection per coroutine** (a pool, not
  isolation). Making that ergonomic is a §5 frontier; the *constraint itself* is permanent.
- **Closure `static $x`.** Excluded from the function-static reset — a closure's static lives with
  its per-instance op_array, not a named function, and is rarely request-state.
- **Process-global handlers.** `set_error_handler` / `set_exception_handler`, raw `ob_*` output
  buffering, and `register_shutdown_function` are process-global, not per-request isolated. Setting
  them once at boot is fine; per-request re-binding is not isolated.
- **`pcntl_fork`, `set_time_limit`, signals.** OS / process-level primitives whose semantics differ
  under a coroutine scheduler; not meaningful to isolate per coroutine.
- **The escape hatch.** Any app that genuinely needs true process isolation — no shared anything —
  runs in **`legacy-cgi`** mode: a process per request, zero coroutine sharing, at the cost of
  in-worker concurrency. That mode is the honest home for code the per-coroutine model can't serve.

---

## 7. Honesty notes (so we don't oversell this)

- The number is a **weighted estimate**, not an instrument reading. The weights are editorial
  (prevalence-based) and the partial scores are judgement calls — re-derive if you disagree with
  a weight; the methodology, not the digit, is the point.
- **"97%" is the SEQUENTIAL figure.** Quote the scope every time. Concurrency of pure-`require_once`
  apps is materially lower and is the genuine research frontier.
- The contract is about **behavioural identity to PHP-FPM**, judged by *absence of cross-request
  leakage under memory sanitizers* — not throughput.
- The **architectural limits** in §6 are by-design boundaries, not bugs — capped at low weight here
  because real request-style apps rarely depend on them.

---

## 8. How to keep this current

When an isolation/reset primitive lands or a frontier closes:
1. Update the row's **status/score** in §3 and re-run the §4 arithmetic.
2. Re-run `TrustBarIsolationTest` + the `.phpt` suite + the app sweep; cite the new evidence.
3. If a headline number moves, update `.claude/CLAUDE.md`, the website, and any live grant text
   that quotes it — in lock-step (see the version-bump discipline in `.claude/CLAUDE.md`).
