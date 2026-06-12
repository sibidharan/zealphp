# The ZealPHP Isolation Stages — canonical taxonomy

**This document is the single source of truth for isolation-stage naming and
numbering.** Every living document (framework `CLAUDE.md`, `docs/*.md`, the
website guides, ext-zealphp `ARCHITECTURE.md` and source comments, the scaffold
`CLAUDE.md`) uses these names and numbers. Dated `docs/architecture/*` files
are historical records and keep whatever numbering they shipped with — the
[legacy aliases](#legacy-aliases) table below maps them.

A **stage** is one per-coroutine isolation unit of the coroutine-legacy
compatibility runtime: a piece of process-global PHP state that ext-zealphp
snapshots/restores (or guards) per coroutine so request-style code can run
concurrently on the PHP-FPM "fresh state per request" mental model.

## The stages

| Stage | Name | Isolates | Framework knob | Env kill-switch | ext primitive | Since |
|---|---|---|---|---|---|---|
| **S1** | Superglobals | the 7 `$_*` superglobals (IS_REFERENCE-aware) | implicit in `coroutine-legacy` | — | `on_yield`/`on_resume` snapshot lanes; `zealphp_superglobals_adopt()` for `go()` children | 0.3.x |
| **S2** | Globals | `$GLOBALS` / `global $x` (COW delta vs parent baseline) **+ request-frame parking**: every Stage-S6 frame CV (all types — strings/arrays/objects/refs) parks into a per-coroutine registry at yield, bucket severed (ext#52) | `App::coroutineGlobalsIsolation(bool)` | `ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` | `zealphp_coroutine_globals()` | 0.3.7; parking 0.3.47 (objects) → 0.3.49 (all types) |
| **S3** | Redeclare | conditional re-declaration → first wins, no fatal. Three components: **S3a** runtime `ZEND_DECLARE_*` opcode skip; **S3b** `define()` intercept (silent re-define); **S3c** compile-time CG-table swap + first-wins merge (top-level declarations) | `App::silentRedeclare(bool)` | — | `zealphp_silent_redeclare()` | 0.3.x |
| **S4** | *(retired — alias of S3c)* | — | — | — | — | — |
| **S5** | Statics | **S5a** function-local `static $x` (BIND_STATIC touched-set registry); **S5b** class static properties (deep-copy snapshot at yield — implicit in `coroutine-legacy`) | S5a: `App::coroutineStaticsIsolation(bool)`; S5b: implicit | `ZEALPHP_FN_STATICS_DISABLE=1` (S5a) | `zealphp_fn_statics_isolation()` | 0.3.x |
| **S6** | *(retired — removed op_array compile cache; never reuse)* | — | — | — | — | — |
| **S7** | Include-once | per-request `require_once`/`include_once` re-execution (`EG(included_files)` eviction + per-coroutine re-include guard) | `App::includeIsolation(bool)` | — | `zealphp_include_isolation()` | 0.3.x |
| **S8** | Global scope | true-global-scope request include — `ZEND_CALL_TOP_CODE` frame with `symbol_table = &EG(symbol_table)` so bare top-level vars become real globals | `App::globalScopeInclude(bool)` | `ZEALPHP_GLOBAL_INCLUDE` | `zealphp_require_global()` | 0.3.26 |
| **S9** | Process settings | process-global runtime settings, one sub-stage each: **S9a** CWD (`chdir`), **S9b** locale (`setlocale`), **S9c** umask, **S9d** timezone (`date_default_timezone_set`), **S9e** mb encoding (`mb_internal_encoding`), **S9f** libxml error flag, **S9g** `ini_set()` values (implicit; the framework separately ships `IniIsolationMiddleware` for non-ext setups), **S9h** `putenv()`/`getenv()` environment | `App::coroutineCwdIsolation()` / `LocaleIsolation()` / `UmaskIsolation()` / `TimezoneIsolation()` / `MbencIsolation()` / `LibxmlIsolation()` | `ZEALPHP_CWD_ISOLATION_DISABLE=1`, `ZEALPHP_LOCALE_…`, `ZEALPHP_UMASK_…`, `ZEALPHP_TZ_…`, `ZEALPHP_MBENC_…`, `ZEALPHP_LIBXML_…` | `zealphp_cwd_isolation()` etc. | 0.3.35–0.3.45 |
| **S10** | Constants | per-request `define()` constants, removed at request end (NOT auto-enabled by `coroutine-legacy` — opt in) | `App::defineIsolation(bool)` | — | `zealphp_define_isolation()` | 0.3.x |
| **S11** | Request resets | per-request state RESET to the boot template (mirrors FPM's fresh process): **S11a** run_time_caches, **S11b** function statics, **S11c** class statics (in-place since 0.3.28) | gated on `App::perRequestStateResetsActive()` | `ZEALPHP_FN_STATICS_RESET_DISABLE`, `ZEALPHP_CLASS_STATICS_RESET_DISABLE` | `zealphp_reset_request_*()` | 0.3.25 |
| **S12** | Exit hook | `exit()`/`die()` → `ZealPHP\HaltException` (worker survival + catch-block immunity) | `App::hookExit(?bool)` | `ZEALPHP_EXIT_HOOK_DISABLE=1` | `zealphp_exit_hook()` | 0.3.48 |

Rules:

- **Stage numbers are append-only.** A new isolation unit takes the next free
  number; retired numbers (S4, S6) are tombstones and are never reused.
- **Components get sub-letters** (S3a/S3b/S3c, S9a–S9f, S11a–S11c), not new
  numbers — a sub-letter is part of one knob's machinery.
- The S2 request-frame parking (ext#52) is **part of S2**, not a stage of its
  own — it is the S2 machinery's handling of S8 frames. (It was called
  "Pass 1b" in 0.3.47-era source comments.)

## Legacy aliases

Historical docs, commit messages, issues, and test filenames predate this
taxonomy. Mapping:

| Legacy term | Canonical |
|---|---|
| Stage 1 (superglobal snapshot) | **S1** |
| Stage 2 ($GLOBALS COW) | **S2** |
| "Pass 1b" / "Stage-8 object parking" | **S2** request-frame parking |
| Stage 3 (silent-redeclare opcodes) | **S3a** |
| Stage 3.5 (define intercept) | **S3b** |
| Stage 4 (compile-time CG-swap) | **S3c** |
| Stage 5 (function statics) | **S5a** |
| Stage 6 / 6.2 (op_array compile cache) | retired — removed for a UAF; see zealphp.c history |
| Stage 7 (require_once re-exec) | **S7** |
| Stage 8 (global-scope include) | **S8** |
| ext ARCHITECTURE.md "Stage 1/Stage 2" *of the $GLOBALS feature* | implementation **generations** (v1 deep-copy → v2 COW), NOT stages — renamed "gen-1/gen-2" in living docs |

Note the collision that motivated this taxonomy: "Stage 2" simultaneously meant
(a) the $GLOBALS isolation unit and (b) the second *implementation generation*
of that unit in ext docs; "Stage 3.5"/"Stage 4" were components of one knob;
S9–S12-class features shipped unnumbered.
