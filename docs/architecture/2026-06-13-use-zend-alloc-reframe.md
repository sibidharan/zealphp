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
