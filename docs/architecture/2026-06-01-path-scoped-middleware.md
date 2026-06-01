# Path-scoped middleware (`App::when`) + in-file `$middleware` for ZealPHP

> Status: **IMPLEMENTED** (2026-06-01). `App::when()` path-scoped middleware,
> the api-file `$middleware` convention, the `when` key on
> `App::describeRoutes()`, and the demo endpoints below are shipped, tested, and
> live. The PSR-15 pipeline plumbing was extracted out of `src/App.php` into
> `src/Middleware/Pipeline/`. Purely additive + BC — a request that hits no
> `App::when()` scope and no in-file `$middleware` takes a byte-identical fast
> path. Builds on the per-route middleware layer (`docs/architecture/2026-05-31-per-route-middleware.md`):
> same `App::middlewareAlias()` registry, same boot-compiled onion, same
> stateless-shared-instance contract.

## TL;DR

1. **There is no separate `apiMiddleware`, by design.** API endpoints (`api/**/*.php`) are not individually registered routes — they're files reached by `/api/...` URLs that flow through the *same* global stack and the *same* `ResponseMiddleware`. So a per-namespace/per-prefix guard for ZealAPI is not a second middleware system; it's a **path scope** on the one stack.
2. **One centralized verb covers everything: `App::when($pathPrefixOrRegex, $middleware)`.** It scopes a chain to a URL *path*, so it works identically for normal routes and for `/api/*` URLs. No API-specific surface to learn.
3. **Per-file co-location is the `$middleware` convention.** An `api/**.php` file may declare `$middleware = ['auth', ...];` — read like `$get`/`$post` — and it runs *innermost*, closest to the handler. The framework already reads in-file closures by name; this reuses that path.
4. **Reuses the per-route substrate wholesale.** Same `App::middlewareAlias()` registry (including parameterised `throttle:120`), same boot-time resolution, same `MiddlewareFrame` onion, same coroutine-safety contract. `App::when()` is the path-scoped sibling of the route's own `middleware:` option, not a new mechanism.

---

## 1. The problem

The per-route middleware layer (2026-05-31) gave `route()`/`nsRoute()`/`nsPathRoute()`/`patternRoute()` a `middleware:` option and `$app->group()` route groups. That covers everything you register explicitly. It does **not** cover ZealAPI.

ZealAPI files (`api/device/list.php` → `/api/device/list`) are **not individually registered routes**. They're resolved by a single implicit route that delegates to `ZealAPI::processApi()`, which locates the file, reads its `$get`/`$post`/`$list` closure, and invokes it. There is no per-file route array to hang a `middleware:` option on. So the natural question — *"how do I put `auth` on every `/api/admin/*` endpoint, or on this one file?"* — had no answer in the per-route model.

Two shapes are needed:

- **A namespace/prefix guard** — "everything under `/api/secured/` requires `api-secured`." This is inherently a *path* concern, and it should not be API-only: a normal route tree under `/admin` wants the same thing.
- **A per-file guard** — "this one endpoint file needs `request-id`." Co-located with the handler, like `$get`/`$post`.

---

## 2. The decision

**No separate `apiMiddleware`.** API endpoints are `/api/...` URLs on the same stack as everything else, so a second middleware system would be redundant surface that drifts out of sync with the route one. Instead:

### 2.1 `App::when()` — path-scoped middleware

```php
App::when(string $pathPrefixOrRegex, MiddlewareInterface|string|array $middleware): void
```

Scopes a middleware chain to a URL **path**. Because it keys on the path, it covers any request — a registered route, an implicit public file, or an `/api/*` endpoint — with one verb.

- **Scope syntax.** A literal **path prefix** by default (`'/admin'`, `'/api/admin'`); a **PCRE** if the string starts with `#` (`'#^/api/v\d+/#'`). `'/'` (or `''`) matches everything.
- **Segment-safe prefix matching.** `'/admin'` matches `/admin` and `/admin/x` but **not** `/administrators`.
- **Middleware argument.** A `MiddlewareInterface` instance, a registered alias string (including parameterised `'throttle:120'`), or a list mixing both. It reuses the **same `App::middlewareAlias()` registry** as route middleware — no second registry.

### 2.2 In-file `$middleware` for api files

```php
// api/secured/profile.php
$middleware = ['request-id'];
$get = function () { /* reads request_id from the per-request memo */ };
```

An `api/**.php` file may declare `$middleware = ['auth', ...];` — read by name exactly like `$get`/`$post`/`$list`. It runs **innermost** (after any `App::when()` scope, closest to the handler), giving per-file co-located guards. Resolved + memoized per file; also reuses the alias registry.

### 2.3 Why one verb is correct here

Traefik's mental model fits: a router rule says *"when this path matches, apply these chains."* `App::when()` is that — but the middleware runs **inside** the request lifecycle (it can read `$g`/session, run coroutine I/O, short-circuit with real app logic), which is the Hyperf runtime model. The path scope is the right key because the thing we're guarding (ZealAPI) is addressed by path, not by a route handle.

---

## 3. The design

### 3.1 Placement — inside `ResponseMiddleware::process()`, after OPTIONS

`App::when()` chains run **inside** `ResponseMiddleware::process()`, **after** path normalization and **after** the OPTIONS / CORS-preflight handling, wrapping route match + dispatch. The ordering matters:

- It reads the **normalized** request path, so the scope match sees the canonical URL.
- It runs **after** preflight handling, so a `when()` auth guard never blocks an OPTIONS preflight — the browser's preflight returns its `204 + Allow` before any scoped guard could 403 it.

### 3.2 Ordering

`App::when()` chains compose in **registration order — the first `App::when()` registered is the outermost.** The full per-request order is:

```
global addMiddleware
  → App::when (registration order, first = outermost)
    → route's own middleware: (or api in-file $middleware)
      → handler
```

The response unwinds in reverse. A middleware that returns without calling the handler **short-circuits** — the inner bands and the handler never run.

### 3.3 Boot-compiled, request-time memoized

Alias → instance resolution happens **once at `App::run()`** (single-coroutine boot). Request time is a cheap, memoized **path scan** over the registered scopes; the resolved per-band onion is reused. Because the scope table is **read-only after boot**, the scan is coroutine-safe. The **stateless contract** is unchanged from per-route middleware: one shared instance serves all concurrent requests, so per-request state lives in `$g` (`RequestContext`), never on the middleware object.

### 3.4 Reuses the per-route machinery

`App::when()` does not introduce a parallel pipeline. It reuses the `App::middlewareAlias()` registry and the same `MiddlewareFrame` onion frame the per-route layer built. The in-file `$middleware` band resolves through the same registry and memoizes per file.

---

## 4. `describeRoutes()` introspection

`App::describeRoutes()` now returns a **`when`** key alongside the existing `global`/`aliases`/`routes` keys:

```php
[
  'global'  => [ /* execution order, ending with 'ResponseMiddleware (router)' */ ],
  'aliases' => [ /* name => resolved class short-name */ ],
  'when'    => [ ['scope' => '/api/secured', 'middleware' => ['api-secured']], ... ],  // registration order
  'routes'  => [ /* {methods, path, middleware, handler} per route */ ],
]
```

`when` lists `{ scope: string, middleware: list<string> }` in registration order. Live JSON at `GET /demo/middleware/visualize`. The visualizer is now rendered **inline on the `/middleware` page** — the standalone `/middleware-visualizer` page was **removed** and its nav entry deleted; the chain view is a section of `/middleware`.

---

## 5. Internal refactor

The userland feature rode in on a plumbing extraction. The PSR-15 pipeline machinery moved **out of the giant `src/App.php`** into a dedicated namespace, `ZealPHP\Middleware\Pipeline` (`src/Middleware/Pipeline/`):

- **`MiddlewareFrame`** — the onion frame (one PSR-15 wrapper around the next handler).
- **`RouteDispatchHandler`**, **`PathDispatchHandler`**, **`ApiDispatchHandler`** — the per-band terminal handlers (route, `App::when` path scope, and api in-file `$middleware` respectively).

Supporting extractions, all purely mechanical:

- The route layer's `Generator` streaming was pulled into a shared **`App::emitGeneratorStream()`** so every band streams identically.
- `ResponseMiddleware::process()`'s match-and-dispatch tail was extracted to a public **`matchAndDispatch()`** so the path band can wrap it.
- ZealAPI's handler-invocation + return-contract logic was extracted to **`runHandlerWithContract()`**, so the api band runs handlers through the same universal return contract.
- A **`$g->psr_request`** slot was added to `RequestContext` so `ZealAPI` can reach the canonical PSR-7 request from inside its dispatch path.

This is **purely additive and BC**. Routes that hit no `App::when()` scope and api files with no in-file `$middleware` take a byte-identical fast path — no added allocation, no extra band. A **full `src/App.php` split is a separate future effort** and did **not** happen here; this refactor moved only the pipeline plumbing.

---

## 6. BC + coroutine-safety

- **BC.** Additive only. No existing call site changes shape. The no-`when()`/no-in-file path is byte-identical to the pre-existing dispatch.
- **Coroutine-safety.** Resolution is boot-time and read-only thereafter; request time is a memoized path scan. Shared middleware instances serve all concurrent coroutines, with per-request state in `$g`. Status is unchanged from the per-route layer: `RateLimit`/`ConcurrencyLimit` are safe now (Store/Counter-backed); `ForwardAuth`/`CircuitBreaker`/`Retry` are feasible now on hooked backends (coroutine HTTP client under HOOK_ALL); **DB-backed auth/session middleware stays blocked on the per-coroutine DB connection pool** (`pdo_pgsql` blocks the worker — needs a native PG coroutine client).
- **Path-rewriters stay global/pre-match.** `StripPrefix`/`AddPrefix`/`ReplacePath` change *which* route matches, so they must run before match on the global stack — `App::when()` runs after path normalization but is still match-adjacent and cannot reorder the route table.

---

## 7. Demo endpoints (live)

Wired in `route/middleware.php` + `api/` fixtures:

| Endpoint | What it proves |
|---|---|
| `GET /api/secured/list` | `App::when('/api/secured', ['api-secured'])` stamps `X-Api-Secured: 1` |
| `GET /api/secured/profile` | when scope + in-file `$middleware = ['request-id']` (stamps `X-Request-Id`; handler reads it from the memo) |
| `GET /api/open/list` | sibling namespace, **no** when (no `X-Api-Secured`) — scoping proof |
| `GET /api/blocked/secret` | `App::when('/api/blocked', ['block'])` → `ReturnMiddleware(403)` short-circuits |
| `GET /demo/scoped/test` | non-api route under `App::when('/demo/scoped')` (`X-Demo-Route`) — `when()` is not api-only |
| `GET /demo/middleware/visualize` | `describeRoutes()` JSON including the `when` key |

---

## 8. Positioning

Think like **Traefik** for the *rule* — "when this path, apply these chains" — but middleware runs **inside** the lifecycle (read/write `$g`/session, run coroutine I/O, short-circuit with real app logic), which is the **Hyperf** runtime model. `App::when()` competes with Slim/Laravel/Hyperf path-scoped middleware, not with an edge proxy. Path-rewriters (`StripPrefix`/`AddPrefix`) stay global/pre-match because they change which route matches.

---

## 9. Out of scope

- **A full `src/App.php` split.** This effort moved only the PSR-15 pipeline plumbing into `src/Middleware/Pipeline/`. The broader decomposition of `App.php` is a separate future effort — not claimed here.
- **Method-scoped `when()`** — `App::when()` scopes by path only; no per-HTTP-method variant ships. Future.
- **Declarative route+middleware config** (YAML/attributes for `when` scopes) — future, if there's demand.
- **A separate `apiMiddleware` system** — deliberately does **not** exist and is not on the roadmap; the centralized path-scoped verb is the answer by design.

---

## Appendix — sources

- ZealPHP internals: `src/App.php` (`ResponseMiddleware::process`, the extracted `matchAndDispatch()`, `emitGeneratorStream()`, the `App::when()` registry + boot resolution), `src/Middleware/Pipeline/` (`MiddlewareFrame`, `RouteDispatchHandler`, `PathDispatchHandler`, `ApiDispatchHandler`), `src/ZealAPI.php` (`runHandlerWithContract()` + in-file `$middleware` read), `src/RequestContext.php` (`$g->psr_request` slot).
- Predecessor: `docs/architecture/2026-05-31-per-route-middleware.md` (the per-route `middleware:` option, `App::middlewareAlias()`, groups, the onion + fast-path gate).
- Demo surface: `route/middleware.php` + `api/` fixtures; rendered inline on the `/middleware` page.
