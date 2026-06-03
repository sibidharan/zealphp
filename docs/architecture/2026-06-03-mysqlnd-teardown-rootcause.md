# The "mysqlnd/libtasn1 connection-teardown" crash — root-caused (2026-06-03)

**TL;DR (FINAL, ASAN + zend_mm confirmed):** There are **TWO independent bugs** behind the
coroutine-legacy WordPress crash, with opposite allocator visibility. Both are now isolated:

| # | Bug | Caught by | Status |
|---|-----|-----------|--------|
| **1** | **`ZEND_FETCH_CONSTANT` use-after-free** in the per-coroutine **constant isolation** (`define()` isolation). On resume, `zealphp_constants_snapshot_restore()` freed an orphaned request-constant the instant a peer coroutine re-declared the same name — but a cached op_array's `run_time_cache` FETCH_CONSTANT slot (shared across coroutines under opcache) still pointed at it. | **ASAN** (heap-use-after-free; reproduces both **without** opcache as a compile-arena AST variant and **with** opcache as the orphan-constant variant) | **FIXED** in ext-zealphp **0.3.27** — defer the orphan free to coroutine close. ASAN-clean (0 errors, 30/30), phpt 0 failures. |
| **2** | **`$wpdb` mysqli teardown allocator mismatch.** Stage-2 **object-globals isolation** holds the `$wpdb` object in the per-coroutine delta; `zealphp_globals_snapshot_delete()` destroys the delta at request-end → `$wpdb.__destruct()` → mysqlnd close → `_php_stream_free(close_options=27)` (the **persistent** free path) → `_efree` on a non-persistently-allocated stream → `zend_mm_heap corrupted`. | **zend_mm only** — **invisible to ASAN/valgrind** (under `USE_ZEND_ALLOC=0` both the persistent and request free paths become plain `free()`, so the mismatch vanishes) | **OPEN** — the production-dominant remaining crash. Independent of bug #1 (still 40 crashes / 80 reqs under VG **with** the 0.3.27 constant fix applied). |

## 2026-06-03 (evening) — debug-build confirmation + resume pointer for bug #2

Re-confirmed bug #2 on **current master + ext-zealphp 0.3.27** with real WordPress (MySQL) on the box:
coroutine-legacy serves WP **functionally** (homepage 20/20, wp-admin 5/5 logged-in dashboard, 0 uksort)
but the worker log shows **`zend_mm_heap corrupted` + `signal=11`** — **16 worker crashes over a ~30-request
wp-admin run** (each respawned by OpenSwoole, so light load is served, but it's heavy churn and drops
in-flight requests under concurrency).

**Built a dedicated zend_mm-debug toolchain** to chase the corrupting write (it is invisible to ASAN/valgrind):
- `php84-dbg` = PHP 8.4.21 `--enable-debug` (NTS DEBUG, zend_mm heap-canary validation) at
  `/home/labs/asan-hazard2/php84-dbg`. **One patch required:** `zend_verify_internal_return_type()`
  (Zend/zend_execute.c:1497) is forced to `return 1` — under `--enable-debug` an OpenSwoole/internal
  function trips its strict internal-return-type assertion during WP boot (`zend_vm_execute.h:1917`),
  aborting before the mysqlnd crash. No-op'ing the verifier lets WP boot; the heap canary is unaffected.
- openswoole 26.2.0 + ext-zealphp 0.3.27 rebuilt against it (debug extension_dir).
- Repro: `/tmp/dbg_repro.sh` (boot coroutine-legacy WP on :9843, login, hammer homepage+wp-admin).

**What the debug build told us (and didn't):** the failure prints the *bare* `zend_mm_heap corrupted`
(zend_mm's own free-list/chunk metadata is already trashed by detection time), **not** a clean
per-block canary report. That signature = an **out-of-bounds write or double-free**, not a simple
bad-pointer free. So the precise corrupting write needs a **gdb hardware watchpoint** on the mysqlnd
VIO `is_persistent` byte (or the freed stream block), driven interactively — the static canary alone
can't name it.

**Hypothesis A (TESTED — RULED OUT): on_yield/on_resume re-entrancy during the drain.** Theory: the
`$wpdb` close yields mid-`__destruct` during `request_end`, so the scheduler's `on_yield`/`on_resume`
globals callbacks re-enter the half-torn-down delta for that coroutine. **Implemented + tested** a
per-coroutine "draining" guard (`zealphp_globals_draining_ptr`): while `request_end` drains, on_yield
skips `snapshot_save` and on_resume skips `snapshot_restore` for the draining coroutine. Result on the
debug build: **still 16 crashes / 30-req run, unchanged.** So the on_yield/on_resume re-entrancy is
**NOT** the (sole) cause — the guard was reverted (unverified, no benefit; don't add to the hot
per-switch path without a proven fix).

**Hypothesis B (NEXT — untested): `reset_to_parent` iterating `EG(symbol_table)` across the yield.**
`zealphp_globals_reset_to_parent()` walks `EG(symbol_table)` freeing non-baseline globals; when a
`__destruct` (e.g. `$wpdb` close) yields mid-walk, **another coroutine resumes and swaps its own
globals into `EG(symbol_table)`**, so when the drain coroutine resumes it continues iterating a
mutated table → corruption. The draining guard does nothing here because the problem is the *other*
coroutine's legitimate EG swap, not the draining one's callbacks. **Candidate fix:** make the drain
**detach-then-destroy** — first DETACH every non-baseline object/value out of `EG(symbol_table)` into a
local list with NO yields (pure pointer moves), THEN destroy the local list (where a `__destruct` may
yield safely, because `EG(symbol_table)` is already in a clean baseline state other coroutines can swap
against). This needs a careful restructure of `reset_to_parent` + the full regression (trust bar 16/16
+ ASAN + valgrind + 12-app sweep + WP e2e).

**Hypothesis C (NEXT — untested): stream double-free / persistent-flag flip.** The visible free takes
`close_options=27` (PERSISTENT) on a non-persistent stream. Either the `php_stream`'s `is_persistent`
byte is corrupted by B's heap damage (symptom, not cause), or the stream is freed twice (once by
`$wpdb.__destruct`, once by another ref/the resource list). Distinguishing B vs C **requires a gdb
hardware watchpoint** on the stream block / its `is_persistent` byte — the static heap canary can't
name the write (it only reports `zend_mm_heap corrupted` after the metadata is already trashed). The
debug toolchain (above) is set up for exactly this gdb session.

---

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

### PINPOINTED (2026-06-04, fresh zend_mm DEBUG gdb) — it's an ALLOCATOR MISMATCH, not a double-free

The decisive frame (debug build, `USE_ZEND_ALLOC=1`, real local MySQL, coroutine-legacy):

```
zend_mm_panic "zend_mm_heap corrupted" (zend_alloc.c:396)
 _efree (ptr=0x556…)                                   # glibc-malloc region (NOT zend_mm)
 _php_stream_free (stream=0x7ffb18bc3e00, close_options=27)  streams.c:525
   525:  pefree(stream->orig_path, stream->is_persistent);   # is_persistent = 0  → efree
 mysqlnd_vio_close_stream  (mysqlnd_vio.c:680)
 mysqlnd_conn_data_send_close → mysqlnd_conn_close (MYSQLND_CLOSE_EXPLICIT)
 php_mysqli_close → mysqli_link_free_storage          # $wpdb->dbh (mysqli) free_storage
 zend_object_dtor_property                            # destroying $wpdb's dbh property
 zend_objects_store_del (object = $wpdb)              # $wpdb dtor
 zend_array_destroy (the per-coroutine delta array)
 zend_hash_index_del (zealphp_coro_globals_deltas)
 zealphp_globals_snapshot_delete (zealphp.c:1111)
 zif_zealphp_coroutine_globals_request_end (zealphp.c:2261)
```

Inspecting `*stream` at the crash: `is_persistent = 0`, `in_free = 1`, `orig_path = 0x556…
"tcp://127.0.0.1:3306"`, `ops = socket_ops`, `open_filename = mysqlnd_vio.c`. The struct is at `0x7ffb…`
(a **zend_mm / mmap** address ⇒ `emalloc`, consistent with `is_persistent=0`), but `orig_path` is at
`0x556…` (the **glibc-malloc / brk** region ⇒ `pemalloc`/`pestrdup`, i.e. PERSISTENT). So line 525 does
`pefree(orig_path, is_persistent=0)` = **`efree` on a `pemalloc`'d pointer** → zend_mm rejects a pointer
it never owned → "heap corrupted". **The crash is an allocator-routing mismatch on `stream->orig_path`,
not a double-free.** (`in_free=1` at the crash is this free's own in-progress flag, set before line
525 — not a re-entry.)

**A `_php_stream_free` entry trace settles the double-free question.** Logging `in_free` at every
`_php_stream_free` *entry* over a wp-admin hammer: 801 calls, **401 with `in_free=0`, 400 with
`in_free=1`** — a near-perfect alternation. Each real free (`in_free=0`) is immediately re-entered once
(`in_free=1`) and php-src's recursion guard early-returns it harmlessly: that alternation is the
**coroutine scheduler re-entering `_php_stream_free` while the close's socket I/O yields under
HOOK_ALL** — pervasive but guarded, NOT the crash. The crash stream (`0x7ffb18bc3e00`) is freed exactly
**once** (`in_free=0` at entry) and dies on the `orig_path` `efree`.

**PERSISTENT CONNECTIONS RULED OUT (2026-06-04).** The natural hypothesis — `orig_path` is in the
glibc-malloc region because mysqlnd built a PERSISTENT stream (`mysqli.allow_persistent => On` on the
box) and force-closed it — is **wrong**: re-running the repro with `-d mysqli.allow_persistent=0`
(forces every connection non-persistent) **still crashes 16×** with the identical `zend_mm_heap
corrupted` / `signal=11`. mysqlnd's persistent path (`mysqlnd_vio.c` 230-256: remove from
`persistent_list`; `mysqlnd_fixup_regular_list` frees `net_stream->res` and sets `res=NULL` — matching
the gdb `stream->res = 0x0`) does NOT flip `is_persistent`, and disabling persistence doesn't help. So a
genuinely-persistent `orig_path` is **not** the cause.

**Revised reading:** the `efree(orig_path)` at streams.c:525 is the **DETECTION POINT** of a zend_mm
heap that is **already corrupted upstream**, not necessarily the corrupting write itself (with
`ZEND_MM_DEBUG=1`, the canary check trips on the first `efree` that touches a corrupted arena — which
happens to be `orig_path` because the `$wpdb` teardown is the heavy free at request-end). The
corrupting write is earlier in the per-coroutine isolation path. `ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` →
0 crashes still fingers object-globals isolation as the trigger, but `orig_path` is the victim, not the
culprit. **Next probe:** bisect with the per-stage kill-switches (`ZEALPHP_FN_STATICS_*`,
`ZEALPHP_CLASS_STATICS_RESET_DISABLE`, globals-isolation on/off) to isolate which write corrupts the
arena, and/or a hardware watchpoint on the specific stream's arena chunk to catch the corrupting store.
This is a hard, iterative zend_mm-debug frontier; the practical production path for unmodified WP-admin
stays **`legacy-cgi` / `cgi-pool`** (process-isolated, no coroutine teardown), and modern Composer/PSR-4
apps that build their DB handle per request (not via a persisted `$wpdb` global object) do not hit it.

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
