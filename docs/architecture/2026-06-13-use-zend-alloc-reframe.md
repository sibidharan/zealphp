# `USE_ZEND_ALLOC=0` reframed — it masks a WordPress memory leak, not an allocator bug (2026-06-13)

**TL;DR.** The long-standing "`USE_ZEND_ALLOC=0` for coroutine-legacy WordPress" guidance was
attributed to an *allocator-mismatch crash*. That attribution is **wrong** for the dominant
production case. Proven this session on the current ext (0.3.55), real WordPress, real MySQL:

1. **The `orig_path` / mysqlnd allocator mismatch (ext#5 / ext#44) is fully shimmed.** An
   in-process classifier on the mysqlnd vio `close_stream` path logged **0 culprit closes**
   (`NEVER_REPAIRED` / `REMALLOC_AFTER_REPAIR` / region-`mismatch`) over hundreds of `$wpdb`
   teardowns under load. The 0.3.46 vio shim re-pairs `orig_path` to the stream's real allocator;
   it holds. This is **not** what drives the crash anymore.

2. **The real driver is a ~6.4 MB/request memory leak — and it is WordPress-specific, not
   ZealPHP's isolation.** Deterministic, linear:

   | App (coroutine-legacy) | per-request RSS growth |
   |---|---|
   | Unmodified WordPress | **~6400 KB/req** (59→379→698→1017 MB over 50/100/150 sequential req → OOM) |
   | Trivial app (per-request globals + a yield) | **~4 KB/req — flat** (23→25 MB over 400 req) |

   The framework's per-coroutine isolation does **not** leak. WordPress re-bootstraps its entire
   state every request (the per-request globals reset hands each request a clean slate), and WP's
   bootstrap is **not idempotent in a long-lived worker** — it accumulates in things a long-lived
   process never frees (object cache, interned strings, non-idempotent init). **This is precisely
   why PHP-FPM recycles WordPress workers via `pm.max_requests`.** The per-request state resets
   (rtcache / function-statics / class-statics) restore *framework* symbols; they cannot make
   unmodified WP's bootstrap idempotent.

   Ruled out: not cyclic garbage (`gc_collect_cycles()` per request reclaims nothing); not held in
   any isolation table (deltas / indirect-objs / tombstones / cid→ptr / class-static snapshots /
   baseline all FLAT at request-end while `zmem` climbs 6 MB/req); biggest live global is just
   `_SERVER[22]`.

3. **The leak → `memory_limit` (1 GB) OOM → the coroutine-legacy worker-EXIT teardown corrupts the
   heap** (`zend_mm_heap corrupted`, SIGABRT/SIGSEGV).

4. **The crashes are triggered by worker EXIT, not by concurrency.** With `memory_limit=6G` (OOM
   impossible) and no `max_request` recycle, `wrk -t6 -c60 -d40s` ran **0 crashes / 0 abnormal
   exits**. Heavy concurrency is clean *as long as the worker does not exit*. There is **no pure
   concurrency-corruption frontier** here.

5. **What `USE_ZEND_ALLOC=0` actually does:** disabling Zend MM disables **`memory_limit`
   enforcement** (the limit is a Zend-MM accounting feature). So the worker never hits the OOM
   fatal, never takes the OOM-bailout exit, and never runs the corrupting teardown. It also
   collapses `pefree`/`efree` to one `free()`, so *if* a teardown does run it is harmless. It was
   **never fixing an allocator bug** — it was removing the memory ceiling that triggers the exit.

## The production-correct fix

**`max_request` worker recycle** bounds the leak — and it is **already wired**: `App::run()`
`array_merge`s the user `$settings` over the defaults into `$server->set()`, so
`run(['worker_num' => N, 'max_request' => 50])` reaches OpenSwoole. At `max_request=50`, peak
worker RSS held at **41 MB** (vs 1 GB OOM), zero OOM. This is FPM `pm.max_requests` parity and is
the doctrinally-correct way to run an accumulating legacy app under a long-lived worker.

`USE_ZEND_ALLOC=0` is therefore **not required and not recommended** for this — it carries a
~10–20% alloc-path cost and removes the memory ceiling (so a runaway request can exhaust host RAM
instead of failing one request).

### Remaining rough edge — worker-exit teardown under concurrency

Recycling *during* heavy concurrency still trips the **same worker-exit teardown corruption** (~2
self-healing `zend_mm_heap corrupted` per 40 s of `wrk -c60` with `max_request=50`). So
`max_request` makes WP-under-load *much* better (no OOM, RSS bounded, self-healing) but not yet
perfectly clean. The targeted fix is to make the coroutine-legacy **worker-exit path** (OOM bailout
*and* `max_request` recycle) drain in-flight coroutines and tear down per-coroutine isolation state
cleanly before the worker exits. Until then, `cgi-pool` / `legacy-cgi` remain the conservative home
for unmodified WordPress under sustained load (process-isolated, no coroutine teardown).

## Method notes (for the next investigator)

- The crash is a **heisenbug under live gdb** (the breakpoint overhead suppresses it) and **cores
  pipe to apport** (host-controlled `core_pattern`, inaccessible in-container). massif under
  OpenSwoole coroutines did not flush usable output. The decisive tool here was **in-process,
  native-speed instrumentation** gated behind an env flag: a `close_stream` classifier for the
  orig_path question, and a `request_end` table-count + `zend_memory_usage` logger for the leak —
  both add ~zero overhead and do not perturb the timing.
- Bisect leverage: `ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` zeroed the leak (and kept WP at 200),
  which first localized it to "globals isolation territory" — but the trivial-app control then
  showed the isolation itself is clean, so the residual is WP's own re-init, surfaced *because*
  isolation resets WP's globals each request.

Supersedes the "bug #2 = `$wpdb` allocator mismatch" conclusion in
`docs/architecture/2026-06-03-mysqlnd-teardown-rootcause.md` for the steady-state/under-load case
(that doc predates the 0.3.46 vio shim).

---

## BREAKTHROUGH (later 2026-06-13): patched opcache eliminates the leak — the real fix

The `max_request` recycle above *bounds* the leak; **opcache eliminates it at the source.** The
leak comes from Stage 7 **re-COMPILING** WP's `require_once`'d class files every request (each
re-compile mints an orphaned inherited-loser CE — the ext#12 leak, ~6.4 MB/req for WP because its
inherited classes are method-heavy). The leak tests ran under **CLI SAPI with
`opcache.enable_cli=0`** — opcache OFF — forcing re-compilation. With **opcache ON**, Stage 7 still
**re-EXECUTES** the file (side effects stay fresh — the FPM contract preserved) but runs the
**cached op_array instead of re-compiling** → no new loser CE → **no leak.** This is the
"compile-once box."

The only blocker was the documented php-src inconsistency (php-src#22214): stock opcache's
function-table copy ignores `opcache.dups_fix`, fataling on WP's re-executed function files
(`Cannot redeclare function _wp_can_use_pcre_u()`). **`patches/opcache-function-dups-fix.patch`**
(shipped in the repo; built via `docker/patch-opcache.sh` / `ZEALPHP_PATCH_OPCACHE`) closes it.

**Validated — unmodified WordPress, coroutine-legacy, real MySQL, clean ext 0.3.55 + patched
opcache (`enable_cli=1 dups_fix=1 validate_timestamps=0`):**

| | result |
|---|---|
| Sequential (100 req) | **100/100 200s, RSS FLAT (~12 KB/req vs 6400 KB/req opcache-off)**, 0 redeclare |
| Concurrent (`wrk -t6 -c60 -d45s`) | **0 `zend_mm_heap corrupted`, 0 OOM, 0 redeclare, 0 abnormal exits**, 42.5 req/s (**10×** the 3.7 req/s opcache-off) |

So the memory-safety story for unmodified WP under coroutine-legacy is **closed** with patched
opcache — **no leak, no OOM, no worker-exit corruption, no `max_request`, no `USE_ZEND_ALLOC=0`.**
The recipe: the patched-opcache Docker image (`ZEALPHP_PATCH_OPCACHE=1`) + a php.ini with
`opcache.enable_cli=1`, `opcache.dups_fix=1`, `opcache.validate_timestamps=0`. Bonus: opcache
compiles at worker-start (single-coroutine), so classes link before request concurrency hits —
also dodging the cold-concurrent-autoload unlinked-class race.

### The `$wpdb`-null *functional* concurrency race — FIXED (Phase R, ext 0.3.56)

With the crashes gone, a pre-existing functional race was exposed: under concurrency a fraction of
requests got a clean 500 `Call to a member function prepare() on null` — `$wpdb` reads NULL
(~43% at c16; **0 crashes**, worker survives).

**Decisive root cause (not what was first assumed).** At the 500, `$GLOBALS['wpdb']` is a live
**OBJ** in 149/149 captures while the *function local* `$wpdb` reads NULL. So the global is fine —
the failure is a **stale local REF-binding**: a deep WP function (`get_posts` /
`_prime_post_caches` / `get_terms` / …) did `global $wpdb`, binding its CV to one
`zend_reference`, but the engine's attach/detach symbol-table protocol (the re-attach in
`zend_leave_helper`, which assumes a single flow of control) on the **shared `EG(symbol_table)`**
(Stage 8) later handed the `wpdb` bucket a *different* canonical reference. The deep frame keeps
an **orphaned reference reading NULL** while `$GLOBALS['wpdb']` reads the live object. 100%
Stage-8-attributed (controlled A/B: Stage 8 ON → 1533 ref divergences + 160 `prepare()`-on-null
at c8; Stage 8 OFF → **0** of both). It hits OBJECT globals (`$wpdb`) and ARRAY globals
(`$wp_filter` → `array_pop(): … null given`) identically.

**Fix — `snapshot_restore` "Phase R".** A type-agnostic end-of-restore single `O(frames × vars)`
sweep: for every live-frame CV carrying the stale signature (IS_REFERENCE whose deref is
NULL/UNDEF), look up the key in `EG(symbol_table)`; if the bucket holds a live OBJECT/ARRAY
canonical via a *different* `zend_reference`, converge the CV onto it (`ZVAL_REF` + `GC_ADDREF`,
releasing the old ref). Tightly guarded so a genuine non-global by-ref local is never repointed.
Validated WP c8×200 (Stage-8 ON + opcache): `prepare()`-on-null **160 → 0**, `array_pop`-on-null
**→ 0**, 0 segv, 0 crash; ext phpt **67/67** (incl. the 062 ext#52 concurrency pin + new
063 deep-binding-survives-yield pin). Refcount-balanced; no ASAN / isolation regression.

### Remaining: `$wp_object_cache` lost-object-global (a *different* root cause — tracked)

A separate, narrower residual remains under **opcache** concurrency: `switch_to_blog()` on null
(~30% at c8, opcache-specific). Here the BUCKET itself is NULL (`$GLOBALS['wp_object_cache']`
absent), not a stale ref. Root cause: WP's `wp_start_object_cache()` has
`static $first_init = true;` and on `!$first_init` calls `wp_cache_switch_to_blog()` instead of
`wp_cache_init()` — so `$wp_object_cache` is never created for that request. That function-local
static **leaks across concurrent coroutines** because **S5a (per-coroutine function-static
isolation) is bypassed under opcache** — opcache-cached op_arrays route `ZEND_BIND_STATIC` through
the baked-in handler, not S5a's user-opcode hook, so the touched-set registry never sees the
function. Matrix (c8×200, opcache on): S5a-on ≈ S5a-off (≈61/200 — S5a not helping), reset-off =
200/200 (the request-END `zealphp_reset_request_statics` is the only thing working, but can't stop
a concurrent in-flight read). Forcing `$first_init = true` each call → 200/200 (confirms). The
complete fix needs **per-coroutine function-static storage (fresh-per-request)**, beyond the
current per-yield-save/restore + request-end-reset model. The memory-safety win above is
independent of both.
