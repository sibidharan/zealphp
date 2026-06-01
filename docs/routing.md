# Routing

ZealPHP blends implicit routing (public directory and APIs) with programmable routes that you can register from any PHP file. This document explains each routing primitive, the execution order, and best practices for structuring route definitions.

## Implicit Routes

Implicit routes are registered by `App::run()` after all custom route files have been included:

- **Public directory** – Requests map to files under `public/`, the **document root** (the Apache `DocumentRoot` equivalent). It defaults to `public/`; change it with `App::documentRoot('…')` before `App::init()`. Examples:
  - `/` → `public/index.php`
  - `/about` → `public/about.php`
  - `/blog/post-1` → `public/blog/post-1.php` (falls back to `public/blog/post-1/index.php` when a directory exists)
  - `.php` suffixes are optional; ZealPHP drops them automatically.
- **API namespace** – Requests under `/api/*` map to files inside `api/`. For example, `/api/device/list` includes `api/device/list.php`, binds the exported closure, and executes it via `ZealAPI`.
- **.php guard** – By default, requests that explicitly target `.php` files are blocked: an existing-but-blocked file (e.g., `/secret.php` where `public/secret.php` exists) returns 403 Forbidden; a `.php` URL with no backing file returns 404 Not Found. Set `App::$ignore_php_ext = false` if you need to serve raw PHP files directly.

Implicit routes register last with the lowest priority, so any explicit route you register can override them.

## Route Injection via `route/`

Every PHP file inside the `route/` directory is automatically included before implicit routes are defined. This is the preferred place to register routes that should live outside `app.php`. Example (`route/contact.php`):

```php
<?php

use ZealPHP\App;

$app = App::instance();

$app->route('/contact', function () {
    App::render('contact');
});
```

Because inclusion is order-insensitive, keep your files focused (one feature per file) to avoid merge conflicts.

## Explicit Routing API

### `route(string $path, array|callable $options = [], ?callable $handler = null, array $methods = [], bool $raw = false)`

- Path placeholders use `{name}` syntax; captured parameters are injected into the handler by name.
- **Options** — supply them as the `$options` array (2nd argument) **or** as named arguments. The two forms are interchangeable and compose; a named argument overrides the matching `$options` key.
  - `methods` (`array`, default `['GET']`) — allowed HTTP verbs. Lowercase verbs are normalised to uppercase.
  - `raw` (`bool`, default `false`) — skip the per-request output buffer (`ob_start()`). Use it for handlers that stream or write to `$response` directly (SSE, `$response->stream()`, binary payloads) instead of relying on the framework to capture echoed output.
  - `middleware` (`array`, default `[]`) — a per-route PSR-15 middleware chain (instances and/or named alias strings). Purely additive: routes without it take the unchanged fast path. See [Per-route middleware](#per-route-middleware) below.
- Return values:
  - `int`: response status code
  - `ResponseInterface`: emitted as-is
  - `array|object`: serialised to JSON
  - anything else: echoed output from the handler buffer

```php
// Two-arg shorthand — GET only:
$app->route('/hello/{name}', fn (string $name) => "Hi {$name}");

// Array options form (backward-compatible):
$app->route('/users', ['methods' => ['GET', 'POST']], $handler);

// Named-argument form — same result:
$app->route('/users', methods: ['GET', 'POST'], handler: $handler);

// raw: skip output buffering for a hand-rolled streaming writer:
$app->route('/export.csv', methods: ['GET'], raw: true, handler: function ($response) {
    $response->stream(fn ($write) => $write("id,name\n"));
});
```

> **Handler last, no `handler:` keyword needed.** The second argument is type-dispatched: a **callable** second arg *is* the handler (`route('/x', $fn)`), an **array** second arg is the options (`route('/x', ['methods' => [...]], $fn)` — handler stays last). So you only need the `handler:` named argument when you want to *skip* options and still pass other named args. The one combination PHP itself forbids is a **positional handler after a named argument** — `route('/x', methods: ['GET'], $fn)` is a fatal "positional argument after named argument"; in that case put `methods` in the options array (`route('/x', ['methods' => ['GET']], $fn)`) or name the handler too.

> The `$options` / `methods:` / `raw:` arguments are identical across **all four registrars** below — `route()`, `nsRoute()`, `nsPathRoute()`, and `patternRoute()` share the same signature tail `(…, array|callable $options = [], ?callable $handler = null, array $methods = [], bool $raw = false)`.

### `nsRoute(string $namespace, string $path, array|callable $options = [], ?callable $handler = null, array $methods = [], bool $raw = false)`

Prefixes routes with a static namespace segment. Useful for administrative or versioned areas.

```php
$app->nsRoute('admin', '/dashboard', ['methods' => ['GET']], function () {
    return App::render('admin/dashboard');
});
// Resolves to /admin/dashboard
```

### `nsPathRoute(string $namespace, string $path, array|callable $options = [], ?callable $handler = null, array $methods = [], bool $raw = false)`

Allows deeply nested placeholders while keeping a namespace prefix. ZealPHP uses this internally to wire `/api/{module}/{action}`.

```php
$app->nsPathRoute('reports', '{year}/{month}', function ($year, $month) {
    // /reports/2024/03
});
```

### `patternRoute(string $regex, array|callable $options = [], ?callable $handler = null, array $methods = [], bool $raw = false)`

Registers a route using a PCRE `pattern`. Named capture groups become handler parameters.

```php
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function ($rest) {
    echo "You requested: {$rest}";
});
```

Pattern routes are powerful but should be used sparingly—prefer `route()` and `nsRoute()` for readability.

## Per-route middleware

Attach a PSR-15 middleware chain to a **single route** — auth, headers, rate-limit, a redirect — without registering it globally. The `middleware` option is accepted by **all four registrars** (`route()`, `nsRoute()`, `nsPathRoute()`, `patternRoute()`), and like `methods`/`raw` it works as a named argument **and** as an array-option key. It is **purely additive and backward-compatible**: a route that declares no middleware takes the unchanged fast path with zero added work.

Each entry is either a ready `MiddlewareInterface` **instance** or a named **alias string** (registered with `App::middlewareAlias()`, below). The two declaration forms **combine** — the array-option entries run first (outermost), then the named-argument entries.

> **Per-route vs path-scoped:** `middleware:` attaches a chain to *one* route. To apply a chain to a *slice of URLs* — `/admin/*`, the whole `/api/*` surface, a `#regex#` — use [`App::when()`](middleware-and-authentication.md), the centralized path-scoped registry. It is also the one mechanism that covers the [ZealAPI](api-layer.md) layer (api files are just `/api/...` URLs). Both reuse the same `App::middlewareAlias()` registry.

```php
use ZealPHP\Middleware\{RequestIdMiddleware, IpAccessMiddleware};

// Mix alias strings with a live instance:
$app->route('/admin/users', methods: ['GET'],
    middleware: ['auth', 'request-id', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    handler: fn () => User::all());

// Same option on any registrar:
$app->nsRoute('api', '/jobs', middleware: ['request-id'], handler: $list);

// Array-option + named-arg combine — array entries are outermost:
$app->route('/report', ['middleware' => ['auth']], $handler, middleware: ['request-id']);
// chain: auth (outer) -> request-id -> handler
```

### Named middleware aliases — `App::middlewareAlias()`

Register a reusable middleware once and reference it by name everywhere (the named-and-shared vocabulary from Traefik, the route-alias pattern from Laravel). Pass either a ready `MiddlewareInterface` instance (reused as-is) or a **factory callable** that returns one.

```php
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware, RequestIdMiddleware};

App::middlewareAlias('auth',       fn () => new BasicAuthMiddleware($verifier));
App::middlewareAlias('admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));
App::middlewareAlias('request-id', fn () => new RequestIdMiddleware());

// Parameterised reference: 'throttle:120' calls the factory with the
// comma-split args (fn('120')), mirroring Laravel 'throttle:60,1'.
App::middlewareAlias('throttle', fn ($n = '60') => new RateLimitMiddleware(limit: (int) $n));

$app->route('/admin/users', middleware: ['auth', 'admin-only', 'throttle:120'], handler: $fn);
```

A factory runs **once at `App::run()`** (boot, single-coroutine) and the resulting instance is **shared across every request** that uses the alias. Therefore middleware **must be stateless** — one object serves all concurrent coroutines; keep per-request state in `$g` (`RequestContext`), never on the middleware object.

### Route groups — `$app->group()`

Apply a shared URL **prefix** and/or a shared **middleware chain** to many routes at once.

```php
$app->group(string $prefix, array|callable $middleware = [], ?callable $registrar = null): void
```

The callback receives a `ZealPHP\RouteGroup` whose `route()`/`nsRoute()`/`nsPathRoute()`/`patternRoute()`/`group()` mirror `App`'s — each prepends the group prefix and prepends the group's shared middleware. The middleware argument may be omitted: `group('/admin', fn ($g) => ...)`.

```php
$app->group('/admin', ['auth', 'admin-only'], function ($g) {
    $g->route('/users',    fn () => User::all());       // /admin/users
    $g->route('/settings', fn () => Settings::get());   // /admin/settings

    $g->group('/audit', ['audit-log'], function ($g) {  // nests prefix + middleware
        $g->route('/recent', fn () => Audit::recent()); // /admin/audit/recent
        // chain: auth -> admin-only -> audit-log -> handler
    });
});

// Middleware optional — just a prefix and a registrar:
$app->group('/v1', function ($g) {
    $g->route('/ping', fn () => 'pong');                // /v1/ping
});
```

Group middleware wraps **outside** a route's own middleware, which wraps outside the handler. Groups nest. **One caveat:** `patternRoute()` inside a group does **not** auto-apply the prefix (a raw regex is ambiguous to prefix — bake the prefix into your pattern); the group **middleware still applies**.

### Execution order

A request walks the chain from the outside in; the response unwinds in reverse. A middleware that returns without calling the handler (a 403, a redirect) **short-circuits** — the handler never runs.

```
global (first-registered = outermost)
  -> group middleware (outer groups before inner)
    -> route middleware (first-listed = outermost; array-option before named-arg)
      -> handler
```

> The first middleware you register globally is the **outermost** (it runs first). This is consistent with the global stack: OpenSwoole's `StackHandler::add()` prepends, and `App::run()` reverses the wait-stack before building it — so first-added ends up outermost.

### Introspection — `$app->describeRoutes()`

```php
$app->describeRoutes(): array{
    global:  list<string>,  // global chain in execution order, ending with 'ResponseMiddleware (router)'
    aliases: list<string>,
    routes:  list<array{methods: list<string>, path: string, middleware: list<string>, handler: string}>
}
```

Works **before or after** `App::run()`: after boot each route's middleware is resolved to instances (reported as class short-names); before boot, alias strings are shown verbatim. The demo exposes this live at `GET /demo/middleware/visualize`, and the website renders it as a Traefik-style chain view in the **Live middleware visualizer** section of the `/middleware` page.

### Worked example

A correlation id on every request, basic-auth + an IP allow-list on the admin area, and a per-route rate limit — composed from aliases, a group, and an inline instance.

```php
use ZealPHP\App;
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware, RequestIdMiddleware};

$app = App::instance();

// 1) Reusable middleware by name.
App::middlewareAlias('request-id', fn () => new RequestIdMiddleware());
App::middlewareAlias('auth',       fn () => new BasicAuthMiddleware($verifier));
App::middlewareAlias('throttle',   fn ($n = '60') => new RateLimitMiddleware(limit: (int) $n));

// 2) request-id on every request, globally (outermost of all).
$app->addMiddleware(new RequestIdMiddleware());

// 3) The whole /admin area is auth-gated and IP-restricted.
$app->group('/admin', ['auth', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])], function ($g) {
    $g->route('/users', fn () => User::all());                       // auth -> ip -> handler

    // 4) One route adds a tighter rate limit on top of the group chain.
    $g->route('/export', methods: ['POST'], middleware: ['throttle:30'],
        handler: fn () => Report::export());                         // auth -> ip -> throttle:30 -> handler
});

$app->run();
```

`ZealPHP\Middleware\RequestIdMiddleware` (used above) assigns/propagates an `X-Request-Id` correlation id and echoes it on the response; handlers read it from the per-request memo (`RequestContext::once('request_id', fn () => null)`). It is stateless and coroutine-safe — the canonical shape for per-route middleware.

## Accessing Request Context

Handlers can declare special parameters to access framework objects:

- `$request` – `ZealPHP\HTTP\Request` wrapper
- `$response` – `ZealPHP\HTTP\Response` wrapper
- `$app` – the current `ZealPHP\App` instance

To access the underlying `OpenSwoole\HTTP\Server`, call `App::getServer()` — it is not an injectable handler parameter.

```php
$app->route('/status', function ($response) {
    $response->json(['ok' => true]);
});
```

## Returning PSR Responses

ZealPHP recognises PSR-7 responses from `OpenSwoole\Core\Psr\Response`. Returning one enables fine-grained control:

```php
use OpenSwoole\Core\Psr\Response;

$app->route('/psr', function () {
    return (new Response('PSR Hello'))->withStatus(205);
});
```

## Combining Explicit and Implicit Routes

You can override or extend implicit behaviour:

- Serve custom logic before falling back to the public directory.
- Inject authentication logic on top of `/api/*` by registering a more specific `nsRoute('api', ...)`.
- Disable the `.php` guard for a subset of paths using pattern routes.

Because ZealPHP processes routes in registration order, place overrides early (e.g., inside `route/` files) and leave broad catch-alls until the end.

## Tips

- Keep route handlers thin; delegate business logic to services or API modules.
- Use named placeholders consistently—handler signatures depend on them.
- Validate and sanitise input even though `REST::cleanInputs()` strips tags. Custom validation belongs in middleware or the handler itself.
- Consider grouping related routes into dedicated files within `route/` to keep the codebase navigable.
