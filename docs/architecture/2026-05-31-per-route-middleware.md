# Per-route middleware for ZealPHP — design proposal & Swoole-vet assessment

> Status: **Phase 1 IMPLEMENTED** (2026-05-31). The userland per-route middleware
> layer below shipped: the `middleware:` route option on all four registrars,
> the `App::middlewareAlias()` named registry, `$app->group()` route groups, the
> pre-compiled per-route onion + fast-path gate, `App::describeRoutes()`
> introspection + the `/middleware-visualizer` page, and the `RequestIdMiddleware`
> catalog addition. Covered by `tests/Unit/RouteMiddlewareTest.php` (21),
> `tests/Unit/RequestIdMiddlewareTest.php` (5), `tests/Integration/RouteMiddlewareTest.php`
> (7); PHPStan level 10 clean. Phases 2–3 (attribute routing, path-rewriters,
> ForwardAuth/CircuitBreaker/Retry, and the systems-research DB substrate) remain
> as scoped below.
> Question driving this: "add middleware as a route option / per-route middleware like Traefik — how do we differentiate from Traefik, what do we lack, how do we improve?"

## TL;DR

1. **Per-route middleware is the easy part** — ~300 lines of userland PHP, no extension/runtime work. It's a developer-ergonomics feature, not systems research.
2. **Traefik is the wrong yardstick.** Traefik is an L7 *edge reverse-proxy*; it forwards to backend services and never runs your code. The right reference for "per-route middleware in a long-running coroutine PHP server" is **Hyperf** (then Laravel Octane / Webman). Adopt Traefik's *ergonomic* (named, ordered, composable chains), Hyperf's *runtime model*.
3. **The hard, grant-worthy work is the coroutine-safety substrate** — per-coroutine DB connection pool, the mysqlnd cold-teardown heap-overflow, and a native libpq/PostgreSQL coroutine client. Per-route middleware *rides on* that substrate; it does not replace it.
4. **Most Traefik-style middlewares are shippable today**; only DB-backed auth/session middleware is gated on the connection pool, and `pdo_pgsql`-backed anything is gated on a native PG client.

---

## 1. Current state (what ZealPHP has today)

**Routing.** A route is stored as a plain array (`src/App.php:4207`, and the `nsRoute`/`nsPathRoute`/`patternRoute` builders):

```php
$this->routes[] = [
    'path'      => $path,
    'pattern'   => $pattern,
    'methods'   => self::normalizeMethods($methods),
    'handler'   => $handler,
    'param_map' => $this->buildParamMap($handler),  // reflection cached at registration
    'raw'       => (bool)($options['raw'] ?? false),
];
```

There is **no `middleware` field**. The route options surface (already wired with named arguments) is:

```php
route(string $path, $options = [], $handler = null, array $methods = [], bool $raw = false)
```

**Middleware.** PSR-15, *global only*: `App::addMiddleware()` pushes onto `$middleware_wait_stack`; at `run()` the stack is reversed and fed to OpenSwoole's `StackHandler` (`src/App.php:7784`). Net ordering (verified against `vendor/.../StackHandler.php`: `add()` does `array_unshift`, `handle()` runs `[0]`, combined with the `array_reverse` at registration): **the first middleware you `addMiddleware()` is the outermost and runs first**; `ResponseMiddleware` is always innermost. Every middleware runs for **every** request, *before* `ResponseMiddleware::process()` (`src/App.php:8548`) matches the route and calls `dispatchRoute()` (`8347`).

**The closest thing to per-route today** is `ScopedMiddleware` (`src/Middleware/ScopedMiddleware.php`): wraps an inner middleware so it runs only when `str_starts_with($path, $prefix)` (`::location`) or `preg_match($regex, $path)` (`::match`). But it is registered on the *global* stack, self-filters by URL path, and runs *before* route matching — so it is **path-scoped, not route-scoped**: it never knows which route matched.

**The full built-in catalog** (`src/Middleware/`) already mirrors most of the Apache/nginx/Traefik surface: `Cors`, `ETag`, `Range`, `Compression`, `BodySizeLimit`, `SessionStart`, `IniIsolation`, `Charset`, `CacheControl`, `Expires`, `Header`, `RequestHeader`, `ContentEncoding`, `ContentLanguage`, `BasicAuth`, `IpAccess`, `RateLimit`, `ConcurrencyLimit`, `BlockPhpExt`, `MergeSlashes`, `MimeType`, `BodyRewrite`, `SetEnvIf`, `Redirect`, `Return`, `Scoped`, `HostRouter`, `Referer`.

---

## 2. The right reference is Hyperf, not Traefik

| | **Traefik v3** | **Hyperf** | **ZealPHP (today)** |
|---|---|---|---|
| What it is | L7 edge reverse-proxy | Swoole app server | Swoole app server |
| "Route" maps to | a backend **service** (forwarded) | an in-process controller method | an in-process handler closure |
| Per-route middleware | ordered `middlewares: [...]` list on the router, named & shared | `#[Middleware(X::class)]` / `#[Middlewares([...])]` attribute on class **or** method, DI-resolved | **none** (global only) |
| Ordering | first-listed = outermost | `Global → Method → Class` (method wraps *inside* class), numeric priority since 3.0.34 | global only; first-registered = outermost |
| Request state | N/A (stateless proxy) | `Hyperf\Context\Context` (coroutine-local) | `$g` / `RequestContext` (coroutine-local) — **same idea** |
| Connection model | proxies to backend | **mandatory** per-coroutine pool | Redis pool ✅, **DB pool ✗** |
| Runs your code? | never | yes | yes |

The shape to adopt is Hyperf's: **attributes for declarative per-route/per-controller middleware, a named registry for reuse, the coroutine context as the request store, and a mandatory per-coroutine pool for any I/O the middleware does.** Traefik only contributes the *vocabulary* (named middleware, chains, the ordered list) and the *edge catalog* (which middlewares are worth having).

---

## 3. The coroutine runtime contract (non-negotiables)

These are what an FPM-shaped "just add PSR-15 per route" design gets wrong. Every Swoole framework's docs converge on them:

1. **Middleware objects are shared across all coroutines** — registered once at boot, alive for the worker's whole life, run by concurrent requests simultaneously. They **must be stateless**: any per-request field on a middleware object races. Request state goes in `$g` (coroutine-local), never on the middleware. *(Hyperf `Context`, Octane "don't inject request into singletons", Webman `controller_reuse` warning — all the same rule.)*
2. **Pre-compile the chain at registration, never per request.** ZealPHP already caches `param_map` per route so there's zero reflection per request — and the 117k req/s headline rides on that discipline. The per-route middleware chain must be compiled **once** into a flat closure onion and cached on the route array, then merely invoked per request. A per-request clone-based `StackHandler` is GC pressure at that throughput.
3. **Fast-path the no-middleware route.** `dispatchRoute` must `if (empty($route['middleware']))` straight through to today's path. ~95% of routes have no middleware and must not regress.
4. **I/O in middleware yields → use a per-coroutine pooled connection, never a shared handle.** Sharing one DB/Redis socket across coroutines interleaves wire frames (OpenSwoole: *"already bound to another coroutine … reading or writing the same socket in multiple coroutines is not allowed"*).
5. **Return pooled resources to the owning coroutine via `defer()` (LIFO).** Hyperf PR #3082 is the scar: a `defer()` that releases a connection from a *different* coroutine throws "already bound." Bind release to the owning coroutine; release even when the handler throws or the client disconnects mid-stream, or the pool bleeds under load.

> **ZealPHP advantage to claim:** Laravel Octane must *reset the container between requests* because singletons hold request state. ZealPHP never needs that reset — `$g` is coroutine-local by construction, and `coroutine-legacy` isolates `$GLOBALS`/statics/superglobals per coroutine via ext-zealphp. That's a *cleaner* answer to the statelessness problem than the dominant Laravel-on-Swoole story.

---

## 4. Proposed API

### 4.1 Per-route middleware option (the headline ask)
Mirrors the existing `methods:` / `raw:` named args:

```php
$app->route('/admin/users', fn() => User::all(),
    middleware: ['auth', 'admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    methods: ['GET']);
```

`middleware:` accepts **instances** or **string aliases** (resolved from a registry). Array form composes too: `['methods' => [...], 'middleware' => [...]]`.

### 4.2 Named middleware registry (Traefik's "named & shared", Laravel's aliases)
```php
App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware(...));
App::middlewareAlias('admin-only', fn() => new RoleMiddleware('admin'));
App::middlewareAlias('throttle',   fn($n = 60) => new RateLimitMiddleware(limit: (int)$n));   // parameterised
// usage: middleware: ['auth', 'throttle:120']
```
Factories are invoked **once at registration** (boot, single-coroutine — safe), and the resulting instance is reused (stateless, per rule #1). Parameterised aliases mirror Laravel's `throttle:60,1`.

### 4.3 Route groups (apply a chain + prefix to many routes — Traefik chains, Slim groups)
```php
$app->group('/admin', middleware: ['auth', 'admin-only'], function ($g) {
    $g->route('/users',    fn() => User::all());
    $g->route('/settings', fn() => Settings::get());
});
```
Combines the `nsRoute` prefix with a shared middleware chain.

### 4.4 PHP 8 attributes for controller routes (Hyperf-style, optional Phase 2)
For class-based controllers loaded via the API/route layer:
```php
#[Middleware(AuthMiddleware::class)]
class AdminController {
    #[Middleware(RateLimitMiddleware::class)]
    public function users() { ... }
}
```

### 4.5 Ordering (must be documented crisply)
`global (outermost) → group → route → handler (innermost)`; response unwinds in reverse. Within a level, declaration order; first = outermost (consistent with the global-stack rule). *(Pick one convention and pin it — do **not** copy Hyperf's counter-intuitive `Method-inside-Class` inversion; readers trip on it.)*

---

## 5. Implementation sketch

1. **Route array** gains `'middleware' => [...]` (raw list) and `'mw_pipeline' => null` (compiled, lazy). All four registrars accept the `middleware:` named arg, merged into `$options` exactly like `methods:`/`raw:` already are (`src/App.php:4145`).
2. **Compile once** at `App::run()` (after all routes + aliases are registered, single-coroutine): resolve aliases → instances, then fold the list into a flat closure onion whose innermost terminal calls the *existing* `dispatchRoute` param-injection + handler invocation. Cache it on `$route['mw_pipeline']`. (Reuse the same `StackHandler` machinery, built once — not per request.)
3. **Dispatch** (`ResponseMiddleware::dispatchRoute`, `src/App.php:8347`): `if (empty($route['middleware'])) { …today's path… } else { return ($route['mw_pipeline'])($g->zealphp_request); }`. The terminal handler re-enters the current param-injection + return-contract logic with the matched params (already in scope).
4. **Short-circuit** is free: a route middleware that returns a response without calling `$handler->handle()` (a 403/redirect) never reaches the route handler — standard PSR-15.
5. **Coroutine-safety**: middleware instances are shared (built at boot); request state stays in `$g`; any pooled resource a middleware acquires is released via `defer()` bound to the request coroutine.

This is **purely additive and BC** — routes without `middleware:` are byte-for-byte unchanged.

---

## 6. The pre-match vs post-match split (a real difference from Traefik)

ZealPHP matches the route *then* dispatches, so per-route middleware runs **after** the match. Therefore Traefik's **path-rewriting** middlewares cannot be per-route here — they change *which* route matches, so they must run **before** matching (global stack / a pre-match transform):

| Traefik middleware | ZealPHP placement |
|---|---|
| `StripPrefix` / `AddPrefix` / `ReplacePath` / `ReplacePathRegex` / `StripPrefixRegex` | **pre-match** (global / path-transform) — *not* per-route |
| `BasicAuth` / `DigestAuth` / `ForwardAuth` / `Headers` / `RateLimit` / `InFlightReq` / `RedirectScheme` / `RedirectRegex` / `IPAllowList` / `Compress` / `CircuitBreaker` / `Retry` | **post-match** — clean fit for per-route |

In Traefik every middleware sits uniformly between router and service; ZealPHP's match-then-dispatch model splits them. The docs must state this, and ZealPHP should ship the path-rewriters as **global** middleware (it has `MergeSlashes` already; `StripPrefix`/`AddPrefix`/`ReplacePath` are easy additions that rewrite `$g->server['REQUEST_URI']`/`PATH_INFO` before match).

---

## 7. Traefik compatibility matrix

| Traefik v3 middleware | ZealPHP status | Notes |
|---|---|---|
| RateLimit | ✅ **Have** | `RateLimitMiddleware` (Store sliding-window), coroutine-safe |
| InFlightReq | ✅ **Have** | `ConcurrencyLimitMiddleware` (Counter/Atomic) |
| BasicAuth / DigestAuth | ✅ Have (Basic) | `BasicAuthMiddleware` (htpasswd/callback); Digest is a gap |
| Headers (+ CORS, security) | ✅ Have | `HeaderMiddleware` + `CorsMiddleware` |
| RedirectScheme / RedirectRegex | ✅ Have | `RedirectMiddleware` (prefix + regex) |
| IPAllowList | ✅ Have | `IpAccessMiddleware` (CIDR allow/deny) |
| Compress | ✅ Have | OpenSwoole `http_compression` + `CompressionMiddleware` |
| Errors (custom error pages) | ✅ ~Have | `App::setFallback` + `renderError`; per-status backend pages a gap |
| **ForwardAuth** | 🟡 **Feasible now** | needs only the coroutine HTTP client (`ZealPHP\HTTP`) — hooked under HOOK_ALL |
| **CircuitBreaker** (request-level) | 🟡 **Feasible now** | state in Store/Atomic or pooled Redis; *(note: the existing `CircuitBreakerBackend` is for Store/Redis resilience, a different thing)* |
| **Retry** | 🟡 **Feasible now** | re-dispatch / re-issue via coroutine HTTP client (hooked) |
| `StripPrefix`/`AddPrefix`/`ReplacePath`/`ReplacePathRegex` | 🟡 Gap (easy) | pre-match path rewriters — global, not per-route (§6) |
| Buffering | 🟡 Partial | interacts with the streaming/Generator + `raw` path; retry-replay buffering is non-trivial |
| ContentType / GrpcWeb / PassTLSClientCert | ⚪ Edge/N-A | TLS-cert & gRPC-web translation belong at a real proxy in front |
| TLS termination, LB services (wrr/p2c), weighted/mirroring/failover, sticky, health-checks | ⚪ **Out of scope** | OpenSwoole `Server` is a process singleton — these are reverse-proxy concerns; keep Traefik/Caddy/nginx in front for true multi-service edge routing & TLS |
| **DB-backed `auth`/session middleware** (the common case) | 🔴 **Blocked on the substrate** | needs the per-coroutine **DB connection pool**; `pdo_pgsql`-backed blocks the worker (needs native `Coroutine\Postgres`) |

---

## 8. How ZealPHP differentiates from Traefik (the answer)

- **Layer.** Traefik route = edge match → forward to a backend service; middleware are network transforms at the proxy. ZealPHP route = match → invoke an **in-process** handler (reflection param injection: `$id`, `$request`, `$response`, `$app`) riding the **universal return contract** (int=status, array=JSON, Generator=stream), under **coroutine concurrency**. ZealPHP per-route middleware competes with **Slim/Laravel/Hyperf route middleware**, not with Traefik.
- **App-state awareness.** ZealPHP middleware can read/write `$g`, the session, run a DB/Redis query, spawn `go()` coroutines, and short-circuit with real application logic. Traefik middleware can never see inside the app process. *That* is the differentiator — middleware that lives **inside** the request lifecycle, not at the edge.
- **Honest boundary.** Don't try to be the edge proxy. OpenSwoole's `Server` is a process singleton (one config, one worker pool); `HostRouterMiddleware` does in-process host routing but the docs already defer true isolation/TLS/LB to a real proxy. ZealPHP should be **excellent at per-route middleware for its own handlers** and explicitly keep TLS, load-balancing, and multi-backend edge routing at Traefik/Caddy/nginx in front.

---

## 9. NLnet framing — separate the two deliverables

For the grant application, keep these in different buckets:

- **Developer-ergonomics deliverable (userland, ~weeks):** per-route + group middleware, named-alias registry, attribute routing, the path-rewrite middlewares. Pure PHP composition; no runtime work; competitive parity with Hyperf/Laravel. Valuable, but **not** systems research.
- **Systems-research deliverable (the hard, novel part — this is the paragraph you highlighted):**
  1. a robust **per-coroutine DB connection pool** with correct coroutine-bound checkout/return (`defer` LIFO; the "already bound to another coroutine" hazard);
  2. resolving the **mysqlnd cold-shutdown teardown heap-overflow** (a genuine memory-safety bug);
  3. a **native libpq/PostgreSQL coroutine client** path, because `pdo_pgsql` does its own C-side socket I/O and is **not** intercepted by OpenSwoole's runtime hooks (unlike mysqlnd-on-`php_stream`) — so it blocks the worker;
  4. plus the ext-zealphp **per-coroutine isolation stack** already underway.

  This substrate is the differentiator from "just another router," and it is what makes the bread-and-butter `auth`/session per-route middleware *safe* in the first place. Frame the middleware UX as **riding on** the substrate, sequenced after it.

---

## 10. Phased roadmap

- **Phase 1 (userland, BC-additive) — ✅ DONE:** `middleware:` route option + array key; named-alias registry (`App::middlewareAlias`); `group()`; per-route onion (instances resolved once at boot, lean per-request `MiddlewareFrame`/`RouteDispatchHandler` wrappers — no per-request alias lookup or `new`); fast-path gate (`empty($route['middleware'])` → today's path, byte-identical); `App::describeRoutes()` + `/middleware-visualizer`; `RequestIdMiddleware`. Ordering + stateless contract documented across routing/middleware/learn pages.
- **Phase 2 (userland):** PHP 8 attribute routing for controllers; the pre-match path-rewriters (`StripPrefix`/`AddPrefix`/`ReplacePath`); `ForwardAuth`, request-level `CircuitBreaker`, `Retry` (all on hooked backends).
- **Phase 3 (systems — the NLnet core):** per-coroutine DB pool + mysqlnd teardown fix + native PG client. *Unblocks* DB-backed `auth`/session middleware and makes CircuitBreaker/Retry over SQL backends safe.
- **Phase 4 (optional):** declarative route+middleware config (YAML/attributes) à la Traefik's dynamic config, if there's demand.

---

## Appendix — sources

- ZealPHP internals: `src/App.php` (route builders 4207/4257/4332/4382; `ResponseMiddleware::process` 8548; `dispatchRoute` 8347; global stack reversal 7784), `src/Middleware/ScopedMiddleware.php`, `vendor/openswoole/core/.../StackHandler.php`.
- Traefik v3: routers/middleware/services/entrypoints (doc.traefik.io v3).
- Hyperf middleware attributes + order + priority; `Hyperf\Context\Context`; `hyperf/db-connection` pool; PR #3082 ("already bound to another coroutine").
- Laravel Octane state-reset / singleton-leak guidance; Webman middleware levels + `controller_reuse`.
- OpenSwoole runtime-flags (what is/isn't hooked: mysqlnd ✓, Redis ✓, cURL ✓; `pdo_pgsql`/`libmysqlclient`/Mongo/AMQP ✗) and native `Coroutine\Postgres`.
