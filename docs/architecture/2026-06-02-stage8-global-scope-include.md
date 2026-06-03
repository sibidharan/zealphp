# Stage 8 — true-global-scope request include (coroutine-legacy)

**Status (latest): Stage-8 SUCCEEDS — unmodified WordPress admin runs in coroutine-legacy (all pages 200, globals correct). The remaining intermittent crash is the PRE-EXISTING mysqlnd connection-teardown frontier, NOT Stage-8.**

**Remaining crash root-caused via core+gdb (2026-06-02) — it is NOT Stage-8.** Captured a core from a crashed worker; backtrace:
```
zend_mm_panic "zend_mm_heap corrupted"
 zend_mm_free_heap  ->  _efree  ->  _php_stream_free (close_options=27)
 mysqlnd_vio_close_stream (mysqlnd_vio.c:680)
 mysqlnd_conn_data_send_close  ->  mysqlnd_conn_close (MYSQLND_CLOSE_EXPLICIT)
```
That's `$wpdb` closing its MySQL connection — the documented **mysqlnd/libtasn1 connection-teardown heap-overflow** frontier. **Confirmed pre-existing by reproduction WITHOUT Stage-8:** plain coroutine-legacy WP (no `ZEALPHP_GLOBAL_INCLUDE`), hammering the public page 60× → **30 `zend_mm_heap corrupted` / 30 worker respawns** (vs **4** with Stage-8 on). Stage-8 manipulates symbol tables, not mysqlnd; it is orthogonal to this crash and in fact crashed *less*. So the globals-scope work is complete and correct; the mysqlnd teardown is the separate, already-known heavy-WP frontier (coroutine HOOK_ALL mysqlnd close heap-safety), to be tackled on its own.

**Net:** Stage-8 (global-scope include) + bucket-stable Stage-2 reset = the missing piece that makes unmodified `require_once` wp-admin render in coroutine-legacy. Ship path: port the ext prototype (`zealphp_require_global` + value-in-place `reset_to_parent`) into ext-zealphp proper, add the `App::globalScopeInclude()` gate (coroutine-legacy only), trust-bar + ASAN, then it composes with the rest of the stack. Full production WP-admin additionally needs the mysqlnd-teardown frontier resolved (independent track).

**Stage-2 bucket-stable fix + result (2026-06-02).** Made `reset_to_parent` **value-in-place**: never `zend_hash_del` a slot (a live global-scope frame holds an INDIRECT CV into the bucket — deleting frees it → UAF). Each slot's VALUE is reset in place to the parent baseline (or NULL when absent) — through `Z_REFVAL` for a live `global $x` binding (the **unchanged proven IS_REF path**, so `global $wpdb; $wpdb = new wpdb()` whose ctor yields keeps working), directly for a plain slot. Crucial lesson: my first attempt *unified* the IS_REF branch and switched its `ZVAL_NULL`→`ZVAL_UNDEF`, which re-broke the `$wpdb` write-through (`wp_set_wpdb_vars: assign field_types on null`). The minimal fix keeps IS_REF exactly and only changes the plain-value path.

**Result (1 worker, patched opcache + dups_fix + real MySQL, `ZEALPHP_GLOBAL_INCLUDE=1`):**
- Public `200`, login `302`+cookie, dashboard `200`, **all 7 admin menu pages `200`** (edit/upload/edit-comments/options-general/plugins/themes/post-new — every page that died with `array_keys(null)`), REST create-post `200`. **`wpdb-null` = 0, `array_keys(null)` = 0** in debug.log. The globals-scope wall is broken: unmodified wp-admin is functionally served in-process under coroutine concurrency.
- **REMAINING:** ~4 `zend_mm_heap corrupted` / 8 worker respawns across the e2e — the worker crashes intermittently and respawns, so requests still succeed on retry (masked), but it's **not production-safe**. Single-worker + sequential, so not concurrent-coroutine churn. Leading suspects: (a) the still-structural `zend_hash_del` in restore Step-3 (tombstones) / `clean()` at request end; (b) realloc/rehash of `EG(symbol_table)` (never-delete accumulates keys → periodic grow → bucket move → dangling indirect); (c) object-global refcount/`__destruct`-in-scheduler interaction during the value-in-place dtor. Needs a precise backtrace to close — blocked on tooling: box ASAN builds lack openswoole/opcache, valgrind+OpenSwoole-coroutines is unreliable. Next: build ASAN openswoole *or* core-dump+gdb to pinpoint, then make restore/clean bucket-stable and/or pre-size + pin the table.

---
*(earlier)* **Status:** **Gate 1 PASSED, Gate 2 BLOCKED on a Stage-2 interaction (root-caused).** `zealphp_require_global` implemented in ext-zealphp, functionally correct + Valgrind-clean standalone, AND coroutine-safe on its own. Wired into `App::executeFile` (env-gated `ZEALPHP_GLOBAL_INCLUDE=1`). Under the full WP coroutine-legacy runtime it crashes — root cause isolated below; the fix is in **Stage-2**, not Stage-8.

**Gate 2 finding (2026-06-02): Stage-8 × Stage-2 conflict — `zend_mm_heap corrupted`.**
- Wired into `executeFile` (box) + ran the full WP e2e (patched opcache + dups_fix + real MySQL, 4 workers). Public site, login, dashboard → `200`. **Admin menu pages (edit.php/plugins.php/upload.php/…) crash the worker** with `zend_mm_heap corrupted`, signal 6/11.
- **Isolated by elimination.** A controlled OpenSwoole coroutine test — `zealphp_require_global` on a file that builds a bare global, **yields** (`Co::sleep`) mid-exec, builds another, returns — across 2 concurrent coroutines, with the **bare ext (Stage-2 NOT booted)**: **survives**, both globals persist across the yield, return intact, exit 0. So the primitive's `TOP_CODE` frame is coroutine-safe by itself; the crash is NOT in the primitive.
- **Root cause:** the global-scope frame binds the included op_array's CVs as **INDIRECT zvals into `EG(symbol_table)`** (`zend_attach_symbol_table`). When WP yields on a DB query mid-bootstrap, Stage-2's per-coroutine snapshot/reset does **structural `zend_hash_del(&EG(symbol_table), key)`** (`zealphp.c:963` reset_to_parent, `:1103` restore) + re-add — freeing/moving the buckets those live indirects (and live `global $x` IS_REFERENCE bindings) point into → use-after-free → heap corruption. Correlates with the admin pages because those are the ones that bind `global $_wp_submenu_nopriv` etc. and yield during the menu-permission DB checks.

**The fix is in Stage-2 (bucket stability), not Stage-8.** Options:
1. **Value-in-place reset (preferred):** Stage-2 never `zend_hash_del`s an `EG(symbol_table)` key during a per-coroutine reset; instead overwrite the bucket value to `IS_UNDEF` ("logically absent", isset=false) and restore values in place. Preserves every bucket address → CV-indirects + `global $x` refs stay valid across yields. Cost: the table accumulates keys (bounded by distinct global names). Must re-pass the trust-bar (isolation correctness) + ASAN.
2. **Pin-aware reset:** track the keys an active global-scope frame holds as CV-indirects (walk the frame's `op_array->vars`); value-swap those in place, keep the existing del/add fast path for the rest. More surgical, more bookkeeping.
3. **Per-coroutine `EG(symbol_table)` swap** instead of in-place snapshot/restore — larger redesign of Stage-2's model.

Next: implement option 1 (or 2) in ext-zealphp, re-run the trust-bar concurrency test (40 interleaved, 0 leak) + ASAN, then the WP e2e. Until then, Stage-8 stays env-gated off; `legacy-cgi`/`cgi-pool` remain wp-admin's home.

---
*(superseded)* **Status:** **Gate 1 PASSED** — `zealphp_require_global` implemented in ext-zealphp (built against php84-vg), functionally correct AND Valgrind-clean. Wiring (`App::executeFile`) + concurrency trust-bar + WP e2e pending.

**Gate 1 results (2026-06-02, box `172.30.0.3`, php84-vg + zealphp.so):**
- Baseline `include` inside a function → `global $x` NULL (bug reproduced). `zealphp_require_global` → `global $x` resolves; bare file-scope vars land in `$GLOBALS`; `return 42` flows back.
- **Transitivity:** entry's nested `require_once` binds *its* bare globals too (`DEEP_OK` + `ENTRY_OK`) — whole `require_once` tree inherits global scope.
- Exception thrown at global scope propagates + is catchable; pre-throw global is set. No-return file → int `1` (include semantics). Double-call recompiles + re-execs cleanly.
- **Valgrind** (`USE_ZEND_ALLOC=0 --leak-check=full --error-exitcode=99`): **exit 0, zero errors** — no invalid read/write, no definite leaks, no frames flagged in the primitive.

**Goal:** make unmodified `require_once`-bootstrap apps (WordPress wp-admin) work in **coroutine-legacy** (in-process), closing the one remaining gap after the opcache `dups_fix` win.

## The problem (verified)

In coroutine-legacy the request entry runs in-process via `App::include()` → `App::executeFile()`,
whose `$result = include $absPath;` (`src/App.php:5214`) is lexically **inside the static method**.
PHP rule: an included file inherits the variable scope of the line the `include` sits on. So a bare
file-scope `$x = array();` (no `global` keyword) in the included file — or in any file it
`require_once`s — becomes a **method-local CV of `executeFile()`**, never entering `EG(symbol_table)`.

WordPress builds `$menu` / `$submenu` / `$_wp_submenu_nopriv` exactly this way
(`wp-admin/includes/menu.php:77`, bare, require_once'd). Later `user_can_access_admin_page()` does
`global $_wp_submenu_nopriv;` → reads the global table → **NULL** → `array_keys(null)` 500 at
`wp-admin/includes/plugin.php:2217` (page-dependent: only admin pages that resolve an empty `$parent`).

**ext-zealphp's Stage-2 globals isolation cannot help** — it operates exclusively on
`EG(symbol_table)` (snapshot/partition/restore across yields). A method-local CV never enters that
table, so isolation has nothing to act on. This is a **scope** problem, orthogonal to isolation.

The `#167` fix solved the identical issue for the **subprocess** modes (`cgi-pool`/`cgi-proc`) by
hoisting the request `include` to the worker's top-level loop (true global scope). The in-process
path has no equivalent — until Stage 8.

## The engine seam (PHP 8.4.21)

Include opcode handler — `Zend/zend_vm_execute.h:5275-5283`:

```c
call = zend_vm_stack_push_call_frame(
    (Z_TYPE_INFO(EX(This)) & ZEND_CALL_HAS_THIS) | ZEND_CALL_NESTED_CODE | ZEND_CALL_HAS_SYMBOL_TABLE,
    (zend_function*)new_op_array, 0, Z_PTR(EX(This)));
if (EX_CALL_INFO() & ZEND_CALL_HAS_SYMBOL_TABLE) {
    call->symbol_table = EX(symbol_table);        // inherit the INCLUDER frame's table
} else {
    call->symbol_table = zend_rebuild_symbol_table();
}
```

The included frame **always** carries `ZEND_CALL_HAS_SYMBOL_TABLE` and inherits `EX(symbol_table)`.
So if the entry include runs against `&EG(symbol_table)`, **every transitive `require_once` inside it
inherits `&EG(symbol_table)` too** — one intervention fixes the whole bootstrap tree.

`zend_execute()` top-frame path — `Zend/zend_vm_execute.h:64325-64336` (the template):

```c
execute_data = zend_vm_stack_push_call_frame(call_info /* TOP_CODE|HAS_SYMBOL_TABLE */, op_array, 0, ...);
if (EG(current_execute_data)) execute_data->symbol_table = zend_rebuild_symbol_table();
else                          execute_data->symbol_table = &EG(symbol_table);   // <-- global scope
i_init_code_execute_data(execute_data, op_array, return_value);   // attaches CVs to that table
zend_execute_ex(execute_data);
```

`zend_rebuild_symbol_table()` (`zend_execute_API.c:1800`) returns a frame's table early when the
`HAS_SYMBOL_TABLE` flag is set — i.e. a function frame gets a **fresh local** table, not `&EG(symbol_table)`.

## The primitive — `zealphp_require_global(string $path): mixed`

A dedicated global-scope executor (chosen over swapping `executeFile`'s live frame, which would
fight `extract($args)` / CV-indirection and leave a half-modified frame). It mirrors the include
handler's execute branch but **forces** `call->symbol_table = &EG(symbol_table)`:

```c
PHP_FUNCTION(zealphp_require_global)
{
    zend_string *path;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(path)
    ZEND_PARSE_PARAMETERS_END();

    zend_file_handle fh;
    zend_stream_init_filename(&fh, ZSTR_VAL(path));
    zend_op_array *op_array = zend_compile_file(&fh, ZEND_INCLUDE);   // opcache-hooked
    zend_destroy_file_handle(&fh);
    if (!op_array) { RETURN_FALSE; }

    op_array->scope = NULL;                       // top-level, no class scope

    zend_execute_data *call = zend_vm_stack_push_call_frame(
        ZEND_CALL_NESTED_CODE | ZEND_CALL_HAS_SYMBOL_TABLE,
        (zend_function*)op_array, 0, NULL);
    call->symbol_table = &EG(symbol_table);       // <-- THE WHOLE POINT
    call->prev_execute_data = EG(current_execute_data);

    i_init_code_execute_data(call, op_array, return_value);  // EG(current_execute_data)=call; CVs -> &EG(symbol_table)
    zend_execute_ex(call);
    zend_vm_stack_free_call_frame(call);
    EG(current_execute_data) = call->prev_execute_data;      // restore (free_call_frame doesn't)

    // op_array lifetime: opcache-persisted scripts must NOT be destroyed here (opcache owns them);
    // a freshly-compiled (non-opcache) op_array is destroyed by the normal RETURN path / engine.
    // Exception state propagates naturally via EG(exception) (the caller's include-equivalent).
}
```

Notes / open implementation points (resolve during build + ASAN):
- `i_init_code_execute_data` is `static zend_always_inline` in `zend_execute.c` — not exported. Either
  use the exported `zend_init_code_execute_data()` (`zend_execute.c`, which sets `prev` then calls the
  inline) or replicate the 4 lines. Prefer the exported wrapper.
- **op_array destruction:** the include handler only `destroy_op_array`s in the eval/no-return branch;
  the execute branch lets the engine own it. Match that — do not double-free an opcache-owned script.
- **EG(current_execute_data) restore:** `zend_vm_stack_free_call_frame` frees the frame but the public
  `zend_execute()` relies on the VM's RETURN to restore `current_execute_data`; for a standalone call
  we restore `call->prev_execute_data` explicitly. Verify under ASAN (this is the highest-risk line).
- **Output buffering / return contract:** the file's `echo` rides the active `ob` (executeFile's),
  `return X` is captured into `return_value` → flows back as the function result. Matches the universal
  return contract.
- **Exceptions:** an uncaught throw inside the file sets `EG(exception)`; the caller (`executeFile`'s
  try/catch) handles it exactly as it would an `include` throw.

## Framework wiring

`App::executeFile()`, gated to coroutine-legacy + a new opt-in flag (default off elsewhere):

```php
if (self::$global_scope_include && \function_exists('zealphp_require_global')) {
    $result = \zealphp_require_global($absPath);     // file-scope vars -> $GLOBALS
} else {
    $result = include $absPath;                      // unchanged fast path
}
```

`$args` ($g + route params): legacy entry files ignore them, so in Stage-8 mode we do **not**
`extract($args)` into the frame (it would otherwise land in `$GLOBALS`). If a target needs them, inject
the small known set into `EG(symbol_table)` before the call and remove after.

## Composition (why it's concurrency-safe)

Stage 8 only changes *where the file-scope vars land* (`EG(symbol_table)` instead of a method-local).
Everything else already handles them:

- **Stage 2 (globals isolation):** iterates `EG(symbol_table)`, so it now snapshots/partitions
  `$menu`/`$submenu`/`$_wp_submenu_nopriv` per coroutine across yields, and `reset_to_parent` removes
  these non-baseline keys at request end → no cross-request leak, no cross-coroutine clobber.
- **Stage 3/4 (silent-redeclare):** unchanged — re-declared functions/classes in the re-run files.
- **Stage 7 (includeIsolation):** the nested `require_once`s still re-execute per request (and now
  inherit global scope from the entry frame).
- **0.3.25 per-request resets + opcache `dups_fix` patch:** unchanged, compose as-is.

This is the capstone of the stack: silent-redeclare + includeIsolation + globals-isolation +
per-request resets + opcache dups_fix + **Stage-8 global-scope include** = unmodified
`require_once` WordPress admin running concurrently in coroutine-legacy.

## Validation gates (must pass in order)

1. **Minimal repro, ASAN + Valgrind clean.** A file with bare top-level `$zealtest = 'OK';`; a function
   reading `global $zealtest`. Baseline via `include` inside a function → NULL (reproduce the bug).
   Via `zealphp_require_global` → `'OK'`. Plus a `return 42;` file → result is `42` (return contract).
2. **Trust-bar concurrency.** 40 interleaved coroutine requests each building its own `$menu`; assert
   ZERO cross-request leakage (compose with Stage-2). No SIGSEGV, ASAN clean.
3. **Full WordPress e2e (coroutine-legacy + patched opcache + dups_fix, real MySQL):** every admin menu
   page (plugins, posts, media, settings, users…), media upload (`move_uploaded_file`), publish a post,
   write a comment. 0 redeclare, 0 `array_keys(null)`, 0 fatals.

## Risk

High — symbol-table / execute_data surgery sits next to the `require_once`-inherited-class SIGSEGV
class (fixed in 0.3.24). Gated rollout, ASAN + Valgrind on every step, trust-bar before WP. The
primitive is small and mirrors existing engine code, which bounds the blast radius.
