# RFC Draft: Silent-Redeclare Extension Hook for Long-Running PHP Servers

> Status: pre-RFC draft for community discussion
> Target: PHP 8.5 (or earliest viable minor)
> Authors: ZealPHP project (sibidharan@selfmade.ninja)
> Last updated: 2026-05-28

## Problem

Long-running PHP servers (OpenSwoole, RoadRunner, FrankenPHP, ZealPHP) all share one fundamental incompatibility with legacy PHP apps:

```
Fatal error: Cannot redeclare login_header() (previously declared in
/var/www/wp-login.php:41) in /var/www/wp-login.php on line 41
```

This fires on the SECOND request for any file that declares top-level functions/classes/constants. In PHP-FPM, every request gets a fresh process, so there's nothing to redeclare against. In long-running servers, the function from request 1 is still in `EG(function_table)` when request 2 tries to bind it.

**The current workaround in each project**:
- FrankenPHP: hand-curated `opcache.preload` per app + worker mode
- RoadRunner: app must be refactored to use closures/autoloaders only
- OpenSwoole: same as RoadRunner
- ZealPHP: Stage 4 CG-swap (works for cold compile, fails on opcache hot path)

Each project reinvents the workaround. **The clean fix lives in the engine**.

## Proposal

Add an extension hook that fires BEFORE `do_bind_function` / `do_bind_class` / `do_bind_class_late_binding` would error on duplicate symbols. If the hook returns a "skip" verdict, the bind is silently no-op (first-declaration wins). Otherwise, the engine emits its current `E_COMPILE_ERROR` behavior.

### API shape

In `Zend/zend.h`:

```c
typedef enum {
    ZEND_SILENT_REDECLARE_DISPATCH = 0,  /* Default: fall through to current behavior */
    ZEND_SILENT_REDECLARE_SKIP    = 1,  /* Skip the bind — keep existing entry */
} zend_silent_redeclare_verdict;

typedef zend_silent_redeclare_verdict (*zend_silent_redeclare_cb_t)(
    zend_string *name,             /* lc target name */
    zend_function *new_fn,         /* incoming function (NULL for class) */
    zend_class_entry *new_class,   /* incoming class (NULL for function) */
    HashTable *target_table        /* EG(function_table) or CG(class_table) */
);

ZEND_API void zend_register_silent_redeclare_hook(zend_silent_redeclare_cb_t cb);
ZEND_API void zend_unregister_silent_redeclare_hook(void);
```

### Engine wiring

In `Zend/zend_inheritance.c` (or wherever `do_bind_function`'s dup-check lives):

```c
ZEND_API zend_result do_bind_function(zend_function *func, zval *lcname) {
    zend_string *lc = Z_STR_P(lcname);
    zval *existing = zend_hash_find(EG(function_table), lc);
    if (UNEXPECTED(existing != NULL)) {
        /* NEW: ask the extension hook for a verdict */
        if (zend_silent_redeclare_cb != NULL) {
            if (zend_silent_redeclare_cb(lc, func, NULL, EG(function_table))
                == ZEND_SILENT_REDECLARE_SKIP) {
                return SUCCESS;  /* silent no-op, first declaration wins */
            }
        }
        /* Existing behavior */
        do_bind_function_error(lc, &func->op_array, false);
        return FAILURE;
    }
    return zend_hash_add_ptr(EG(function_table), lc, func) ? SUCCESS : FAILURE;
}
```

Same shape applied to `do_bind_class` and the late-binding variant. Also needs to be called from opcache's `zend_accel_load_script` at the equivalent dup-check site.

### Extension usage

```c
/* In an extension's MINIT */
static zend_silent_redeclare_verdict my_hook(
    zend_string *name, zend_function *fn, zend_class_entry *ce, HashTable *t)
{
    if (my_extension_is_enabled() && zend_hash_exists(t, name)) {
        return ZEND_SILENT_REDECLARE_SKIP;
    }
    return ZEND_SILENT_REDECLARE_DISPATCH;
}

PHP_MINIT_FUNCTION(my_extension) {
    zend_register_silent_redeclare_hook(my_hook);
    return SUCCESS;
}
```

## Why this needs to be in the engine

The dup-check lives in two places:

1. `Zend/zend_inheritance.c` — `do_bind_function` / `do_bind_class` called by the VM
2. `ext/opcache/zend_accelerator_util_funcs.c` — `zend_accel_load_script` called by opcache on cache hits

Both are inside the PHP binary or opcache's shared object. An extension can't intercept them without LD_PRELOAD (deployment-hostile) or PHP-engine patching (per-version maintenance).

A registered hook in both call sites would let any extension implement the "silent-redeclare" semantic cleanly. ZealPHP, FrankenPHP, RoadRunner can all use the same primitive.

## Compatibility & safety

- Default behavior unchanged — hook is opt-in.
- No new opcodes, no new bytecode flags.
- Hook can only SUPPRESS dup errors, never INTRODUCE new ones. Read-only access to the target table.
- First-wins semantic matches FPM ("whatever was declared by the first request stays declared").
- ABI-compatible: extensions that don't register a hook see no change.
- Future-proof: when PHP adds a new declare/bind path (anything with this dup pattern), the hook would just need to be wired at the same site.

## Performance

- Hook check is a single function-pointer null-check + (if non-null) one indirect call per `do_bind_*` invocation.
- `do_bind_*` runs once per top-level declaration per file compile. For a fresh WordPress request that compiles 50 files with 10 top-level functions each, that's 500 hook calls — sub-microsecond cumulative cost.
- On the opcache hot path the bind happens for every cached symbol on every replay. Same order of magnitude.

## Alternatives considered

1. **LD_PRELOAD wrapper around `do_bind_function`** — works but requires every deploy to set `LD_PRELOAD` correctly. Hostile to containers, embedded SAPIs, and distro packaging.
2. **Patch PHP per minor version** — what ZealPHP would have to do without this RFC. Per-version maintenance burden across 8.3 / 8.4 / 8.5 / 8.6.
3. **Engine flag `opcache.allow_silent_redeclare=1`** — too coarse. Affects every file, not just the long-running-server use case.
4. **Per-file opcache blacklist** — works for known apps (WordPress wp-login.php) but requires per-app maintenance, doesn't compose.

## Reference implementation

A working prototype lives in [`ext/zealphp/zealphp.c`](../../ext/zealphp/zealphp.c) at `zealphp_compile_file_hook` — Stage 4 today. It catches the cold-compile case but not the opcache hot-path case, because there's no engine hook for the latter. This RFC's `zend_register_silent_redeclare_hook` makes the prototype complete.

## What ZealPHP would do with this

1. Register the hook in MINIT.
2. Gate the hook on a per-process flag set by `App::silentRedeclare(true)`.
3. The hook returns SKIP for any user-defined symbol already in the table.
4. WordPress's `wp-login.php` would run cleanly in pure coroutine mode across thousands of requests per worker — no second-request crash, no special routing, no preload curation.

The framework's `App::registerCgiBackend()` (the documented FPM pair-up for the current gap) becomes unnecessary for the redeclare case. M1 Pool stays available for apps that genuinely need fresh-process semantics for other reasons (raw `$_SERVER` mutation, etc.).

## Discussion questions for the RFC thread

1. Should the hook see the FULL signature comparison so apps can implement "skip only if signatures match"? Or is "name exists" sufficient?
2. Should there be SEPARATE hooks for function, class, class-with-parent? Or one polymorphic hook (as drafted)?
3. Should `define()` get the same treatment via a separate hook? (Currently a userland intercept works for `define()` because it's a normal function call.)
4. Should the hook be allowed to MUTATE the incoming declaration (e.g., rename it)? Or strictly veto-only?

## Next steps

- Get this in front of php-internals@lists.php.net
- Build a working patch against PHP master
- Benchmark hook overhead with and without it registered
- Coordinate with FrankenPHP / RoadRunner / OpenSwoole maintainers — multiple projects co-signing makes the case stronger
