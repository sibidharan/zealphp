# The "mysqlnd/libtasn1 connection-teardown" crash — root-caused (2026-06-03)

**TL;DR (FINAL, ASAN + zend_mm confirmed):** There are **TWO independent bugs** behind the
coroutine-legacy WordPress crash, with opposite allocator visibility. Both are now isolated:

| # | Bug | Caught by | Status |
|---|-----|-----------|--------|
| **1** | **`ZEND_FETCH_CONSTANT` use-after-free** in the per-coroutine **constant isolation** (`define()` isolation). On resume, `zealphp_constants_snapshot_restore()` freed an orphaned request-constant the instant a peer coroutine re-declared the same name — but a cached op_array's `run_time_cache` FETCH_CONSTANT slot (shared across coroutines under opcache) still pointed at it. | **ASAN** (heap-use-after-free; reproduces both **without** opcache as a compile-arena AST variant and **with** opcache as the orphan-constant variant) | **FIXED** in ext-zealphp **0.3.27** — defer the orphan free to coroutine close. ASAN-clean (0 errors, 30/30), phpt 0 failures. |
| **2** | **`$wpdb` mysqli teardown allocator mismatch.** Stage-2 **object-globals isolation** holds the `$wpdb` object in the per-coroutine delta; `zealphp_globals_snapshot_delete()` destroys the delta at request-end → `$wpdb.__destruct()` → mysqlnd close → `_php_stream_free(close_options=27)` (the **persistent** free path) → `_efree` on a non-persistently-allocated stream → `zend_mm_heap corrupted`. | **zend_mm only** — **invisible to ASAN/valgrind** (under `USE_ZEND_ALLOC=0` both the persistent and request free paths become plain `free()`, so the mismatch vanishes) | **OPEN** — the production-dominant remaining crash. Independent of bug #1 (still 40 crashes / 80 reqs under VG **with** the 0.3.27 constant fix applied). |

The historical "mysqlnd/libtasn1 connection-teardown heap-overflow" label was a mis-guess (libtasn1/TLS
ruled out three ways — see below). The visible `zend_mm_heap corrupted` at the mysqlnd free is **bug #2**;
**bug #1** is a separate UAF that ASAN surfaced because its malloc layout exposes the dangling read.

---

## How the two-bug split was proven

The decisive experiment: build ext-zealphp + the patched opcache for **two** runtimes and run unmodified
WordPress (real local MySQL, coroutine-legacy, 1 worker) on each:

- **`php84-asan-nd` + opcache-asan, `USE_ZEND_ALLOC=0`** (ASAN, malloc): catches bug #1 exactly (the
  `FETCH_CONSTANT` UAF). After the 0.3.27 constant fix → **0 ASAN errors, 30/30 200s.** Bug #2 is
  invisible here (malloc collapses the persistent/request free distinction).
- **`php84-vg` + patched opcache + `dups_fix=1`** (production runtime, zend_mm): catches bug #2 (the
  `zend_mm_heap corrupted` at the mysqlnd free). With the 0.3.27 constant fix applied it **still crashes
  40×/80** at `zealphp_globals_snapshot_delete`. Bug #1 doesn't dominate here.

So a fix verified clean under one runtime can still crash under the other — the two bugs needed two
toolchains to separate. phpt stays 0-failures throughout (backward-safe).

---

## Bug #1 — constant isolation `FETCH_CONSTANT` UAF (FIXED, ext-zealphp 0.3.27)

ASAN, deep `malloc_context_size`, `fast_unwind_on_malloc=0`:

**With opcache (production config)** — the orphan-constant variant:
- **Read:** `ZEND_FETCH_CONSTANT` in the request handler.
- **Freed:** `efree → zealphp_free_orphan_constant (zealphp.c:273) → zealphp_constants_snapshot_restore
  (zealphp.c:347) → zealphp_on_resume → Coroutine::resume()`.

**Without opcache** — the compile-arena variant (same class, different free site): the constant's value
references a `zend_ast` literal zval in the compile arena, which Stage-7's per-request re-compile destroys
via `zend_arena_destroy` in `zealphp_compile_file_hook`.

**Mechanism (with opcache).** The per-coroutine constant isolation orphans request `define()`'d constants
on yield (removed from `EG(zend_constants)`, struct kept) and re-inserts them on resume. If a peer
coroutine re-declared the same name while suspended, `restore` freed the now-unreachable orphan **right
there in `on_resume`** — but the shared `run_time_cache` FETCH_CONSTANT slot still pointed at it, so the
next constant fetch read freed memory.

**Fix (`zealphp_constants_snapshot_restore`).** Don't free the orphan mid-request. Keep it in the
per-coroutine snapshot's `ptrs` set; `snapshot_delete` frees it at **coroutine close**, after the
request's `run_time_cache` reset, where no fetch can read it. Re-inserted constants (name not in EG) are
dropped from `ptrs` since EG now owns them. Verified: ASAN 0 errors over 30 WP requests with patched
opcache + `dups_fix`; phpt 0 failures.

---

## Bug #2 — object-globals `$wpdb` teardown (OPEN, the production crash)

GDB backtrace (VG, zend_mm, with the 0.3.27 constant fix loaded):
```
zend_mm_panic "zend_mm_heap corrupted" (zend_alloc.c:398)
 zend_mm_free_heap -> _efree
 _php_stream_free (stream, close_options=27)          # 27 = PERSISTENT|RSRC_DTOR|RELEASE|CALL_DTOR
 mysqlnd_vio_close_stream (mysqlnd_vio.c:680)
 mysqlnd_conn_data_send_close -> mysqlnd_conn_close (MYSQLND_CLOSE_EXPLICIT)   # $wpdb.__destruct
 ...
 _zend_hash_del_el_ex (ht = zealphp_coro_globals_deltas)
 zend_hash_index_del (ht = zealphp_coro_globals_deltas)
 zealphp_globals_snapshot_delete (zealphp.c:1100)     # destroy the per-coroutine delta
 zif_zealphp_coroutine_globals_request_end (zealphp.c:2239)   # the request-end drain
```

`$wpdb` is a **global object holding a mysqli connection resource**. Stage-2 object-globals isolation
(`zealphp_globals_isolatable_obj` → objects YES, resources NO; v0.3.23) isolates the `$wpdb` **object**
per coroutine (snapshot into the delta, restore across yields, final release at request-end). But the
mysqli **resource inside the object is process-shared**. Destroying the delta runs `$wpdb`'s close, which
takes the **persistent** free path (`close_options=27`) even though WP connects **non-persistent** — i.e.
the stream's `is_persistent` reads true when it should be false, so a request-heap (`emalloc`) stream is
freed via the persistent (`pefree`) path → zend_mm metadata corruption.

**Why it's ASAN/valgrind-invisible:** under `USE_ZEND_ALLOC=0` both `pefree` and `efree` are `free()`, so
the persistent/request mismatch never corrupts — and there is **no OOB write or UAF** ASAN can flag (it's
an allocator-routing mismatch, not a memory-safety violation in the classic sense). This is why the
earlier discriminating kill-switch test (`ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` → 0 crashes) correctly
fingered object-globals as the trigger, but ASAN — the tool that would normally pinpoint the corrupting
write — comes back clean.

### Fix directions (ext-zealphp, not yet landed)

1. **Don't drive a resource-bearing object's `__destruct` from the isolation teardown.** The delta should
   release its `$wpdb` ref without being the path that fires the mysqli close, OR fire it in a context
   where the persistent-flag routing is correct.
2. **Exclude objects that (transitively) hold a live `IS_RESOURCE` from object-globals isolation** —
   leave them process-shared. *Caveat:* this reintroduces the cross-coroutine `$wpdb` slot race the
   isolation was built to fix (`global $wpdb; $wpdb = new wpdb()` under concurrency), so it's only safe
   paired with a per-coroutine connection the isolation doesn't touch.
3. **One connection per coroutine via a pool** the isolation never snapshots.
4. **Diagnose the exact `is_persistent`-flip** with a zend_mm **debug build** (`--enable-debug` zend_mm
   tracking) or `ZEND_MM_DEBUG` — the one class of tool that can catch zend_mm-internal corruption that
   ASAN/valgrind (forced to malloc) cannot.

`ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` is **not** a safe workaround (it reintroduces cross-coroutine
`$wpdb` leakage). It is a useful diagnostic only.

---

## libtasn1 / TLS = RED HERRING (ruled out three ways)

MySQL `have_ssl=DISABLED` / `Ssl_accepts=0`; WP `DB_HOST = '127.0.0.1'` (local, no `p:`, no SSL flags);
the persistent repro crashes against that local non-TLS MySQL with no ASN.1/GnuTLS in the path. The
"libtasn1" label was an inherited mis-guess and should be dropped from the framework + ext CLAUDE.md in
favor of this two-bug split.

## Note

This supersedes the single-cause "mysqlnd/libtasn1 connection-teardown heap-overflow" framing. Bug #1 is
fixed (0.3.27); bug #2 (object-globals `$wpdb`) is the remaining coroutine-legacy WordPress frontier.
Modern Composer/PSR-4 apps that build their DB handle per request (not via a persisted global object) do
not hit bug #2.
