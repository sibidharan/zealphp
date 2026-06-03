# Dev route hot-reload

Reload `route/*.php` edits **without restarting** the OpenSwoole worker process —
"save the file, refresh the page, see the new route."

## Enabling it — three equivalent switches

All three flip the same toggle (`App::$dev_reload`). Use whichever fits your
workflow. If you combine them the **`--dev` CLI flag always wins**, then an
explicit `App::devReload()` call in `app.php`, then the `ZEALPHP_DEV` env var as
the fallback.

| How | Command / code | Use when |
|---|---|---|
| **CLI flag** (simplest) | `php app.php --dev` | Day-to-day dev — nothing to edit, nothing to commit. Combine with any other flag: `php app.php start -p 9501 -d --dev`. |
| **Env var** | `ZEALPHP_DEV=1 php app.php` | Docker / shell profiles / `.env`-style setups. |
| **Programmatic** | `App::devReload(true);` in `app.php` before `$app->run()` | A project that is always run in dev from the same `app.php`. Gate it behind your own env check so it never ships on. |

```bash
# The transparent, recommended way — a single flag:
php app.php --dev
```

With dev reload on, each worker watches the `route/*.php` mtimes once a second and
calls `App::reloadRoutes()` when any of them changes. You can also trigger a
reload yourself from anywhere in the process:

```php
$count = $app->reloadRoutes(); // rebuilds the route table in place, returns the new count
```

> The full CLI surface (every command, flag, and env var) is documented in
> [CLI reference](cli.md). `--dev` is listed there alongside `-p`, `-d`, etc.

## What reloads — and what doesn't

`reloadRoutes()` restores the **app.php baseline** (the explicit routes plus the
`App::middlewareAlias` / `App::when` registries captured at boot), re-includes the
`route/*.php` files, re-appends the framework's implicit routes (api dispatch,
public-file serving, …) in priority order, and rebuilds the dispatch table.

| Reloads on a `route/*.php` edit | Stays restart-only |
|---|---|
| `route()` / `nsRoute()` / `nsPathRoute()` / `patternRoute()` | `app.php` lifecycle config — `App::mode()`, `superglobals`, worker counts |
| `App::when()` path scopes | The global middleware stack (`App::addMiddleware`) |
| `App::middlewareAlias()` | `$server->set()` settings, `HOOK_ALL`, `enable_coroutine` |
| `App::ws()` handlers | `Store::make` tables, `App::subscribe`, timers, sidecar processes |

The `app.php` lifecycle/config and OpenSwoole server settings are **frozen at
`$server->start()`** — no process can change them in place; that's an OpenSwoole
boundary, not a ZealPHP gap. Boot-master infrastructure a route file wires
(`Store::make`, `App::subscribe`, `App::onWorkerStart`, `App::addProcess`,
`App::onSignal`, timers) is **not** re-run on reload — those calls detect
`App::$reloading` and keep their one-time boot registration; only route
definitions and middleware aliases/scopes take effect.

## The one hard limit: top-level functions in route files

A `route/*.php` file that declares a **top-level `function`** cannot be
re-included in coroutine mode — PHP fatals with *"Cannot redeclare …"* and would
crash the worker. `reloadRoutes()` therefore **detects this and safely refuses**
the reload (the live route table is left untouched and a warning is logged)
rather than crash.

Two ways to make a route file hot-reloadable:

1. **Keep helpers out of `route/`** — move functions into a `src/` class
   (PSR-4 autoloaded). This is the documented "routes are thin" rule anyway.
2. **Use `App::mode('coroutine-legacy')`** — its silent-redeclare runtime
   tolerates function re-declaration (requires `ext-zealphp`).

## opcache

Re-including a changed route file only picks up the edit if opcache lets it. In
dev, set `opcache.validate_timestamps=1` (`revalidate_freq=0`) or disable
opcache. `reloadRoutes()` also calls `opcache_invalidate()` on each route file as
a belt-and-suspenders.

## Production

Leave dev reload **off** in production (the default). There, the route table is
built once in the master and copy-on-write-shared into every worker — no polling,
no per-worker rebuild. Reload code paths are never entered.
