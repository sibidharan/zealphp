# Middleware and Authentication

Middleware is the preferred way to enforce cross-cutting policies in ZealPHP. The framework embraces PSR-15 (`Psr\Http\Server\MiddlewareInterface`) and runs every request through a configurable stack before handing it to the routing engine. This guide shows how to register middleware, build authentication flows, and combine them with the file-based routing model.

## Middleware Pipeline Overview

1. `App::init()` seeds the pipeline with `ResponseMiddleware`, which performs route matching and response emission.
2. Custom middleware added with `App::addMiddleware()` is stored until `App::run()` executes; at that point ZealPHP reverses the wait-stack before feeding it to the `StackHandler` (whose `add()` prepends). The net effect: the **first** middleware you register is the **outermost** — first to process the request, last to process the response. `ResponseMiddleware` always runs innermost.
3. `SessionManager` or `CoSessionManager` wraps the entire stack to guarantee that sessions are opened before middleware runs and closed afterward.

```php
use ZealPHP\App;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $duration = round((microtime(true) - $start) * 1000, 2);
        return $response->withHeader('X-Response-Time', "{$duration}ms");
    }
}

$app = App::init();
$app->addMiddleware(new TimingMiddleware());
$app->run();
```

## Authentication Middleware Pattern

Create middleware that inspects the request, validates credentials, and either forwards the request or terminates it with an error response.

```php
use ZealPHP\G;
use OpenSwoole\Core\Psr\Response;

class SessionAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = G::instance();
        $session = $g->session ?? [];

        if (empty($session['user_id'])) {
            $body = json_encode(['error' => 'unauthorized'], JSON_PRETTY_PRINT);
            return (new Response($body, 403))->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request)->withHeader('X-User-Id', (string)$session['user_id']);
    }
}
```

Register the middleware before calling `run()`:

```php
$app = App::init();
$app->addMiddleware(new SessionAuthMiddleware());
$app->run();
```

### Targeting Specific Routes

If only a subset of endpoints requires authentication, register the middleware conditionally:

```php
$app->addMiddleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/private')) {
            // perform auth checks
        }
        return $handler->handle($request);
    }
});
```

Alternatively, mount authenticated routes in a dedicated namespace handled by a custom route file under `route/`, then call into ZealAPI manually once credentials are verified.

## Combining Middleware with File-based APIs

Middleware runs before route selection, so you can rely on it inside `api/*` closures:

```php
// After SessionAuthMiddleware runs
$profile = function () {
    $session = ZealPHP\G::instance()->session;
    return ['user_id' => $session['user_id']];
};
```

For token-based APIs, parse headers using the PSR request:

```php
class BearerAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(?<token>.+)$/', $auth, $matches)) {
            return (new Response('Missing bearer token', 401));
        }

        if (!token_is_valid($matches['token'])) {
            return (new Response('Invalid token', 403));
        }

        ZealPHP\G::instance()->session['auth_token'] = $matches['token'];
        return $handler->handle($request);
    }
}
```

## Middleware Ordering

The most recently added middleware executes first. A typical order:

1. **Security** – Authentication, authorisation, CSRF.
2. **Request Shaping** – Input sanitisation, locale negotiation.
3. **Telemetry** – Logging, tracing, metrics.
4. **ResponseMiddleware** – Built-in terminal middleware that invokes route handlers.

If you need to guarantee that a middleware executes after routing (for example, to post-process responses), attach it to the response returned by `$handler->handle()` rather than registering it later.

## Integrating with External Identity Providers

Inside middleware you have full access to the PSR request:

- Read cookies and headers.
- Perform asynchronous validation using `go()` (when superglobals are disabled) or `coproc()` to spawn a non-blocking background process when superglobals are enabled.
- Populate `G::instance()->session` with auth state, or stash a resolved user object on `G::instance()->memo` (e.g. `G::instance()->memo['user'] = $user;`).

Handlers receive a `ZealPHP\HTTP\Request` wrapper — not the PSR `ServerRequestInterface` that flows through the middleware stack — so PSR `withAttribute()`/`getAttribute()` never reaches them. Use `G::instance()->memo['user']`, `G::instance()->session['user_id']`, or any other key on `G::instance()` to pass auth context from middleware to handlers.

## Testing Middleware

While ZealPHP does not yet ship a testing harness, you can instantiate middleware classes directly and feed them mocked `ServerRequestInterface` objects. The repository’s examples demonstrate how to wrap OpenSwoole requests; reuse them in unit tests.

## Built-in Middleware Classes

ZealPHP ships parity middleware for Apache/nginx behaviours. The framework's per-middleware reference table lives in `template/pages/middleware.php` (the website's `/middleware` page). The following entries cover several additional classes — all opt-in, all PSR-15 — that complete the parity surface.

### `RequestIdMiddleware`

Request-correlation, not an Apache/nginx parity item — the kind of edge concern you'd add at the proxy, expressed as an in-process middleware your handlers can also read. It assigns every request a correlation id and echoes it on the response (default header `X-Request-Id`), so a single request can be traced across logs, downstream services, and the client.

- With `trustInbound: true` (the default), an id already set by an upstream proxy is propagated; pass `trustInbound: false` to always mint a fresh one.
- A minted id is `bin2hex(random_bytes(16))` — 32 hex chars, collision-safe.
- The id is written to the per-request memo, so handlers read it with `RequestContext::once('request_id', fn() => null)` / `RequestContext::has('request_id')`.

Stateless and coroutine-safe: the id lives in `$g` (request context), never on the shared middleware instance, so one instance serves every concurrent request.

```php
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\RequestContext;

// Global — every request gets a correlation id
$app->addMiddleware(new RequestIdMiddleware());

// Read it inside a handler
$app->route('/api/job', function () {
    return ['job' => 'queued', 'request_id' => RequestContext::once('request_id', fn() => null)];
});

// Custom header, always mint a fresh id (ignore inbound)
$app->addMiddleware(new RequestIdMiddleware('X-Correlation-Id', trustInbound: false));
```

### `ContentEncodingMiddleware`

Apache `mod_mime AddEncoding` parity. Sets the response `Content-Encoding` header from the request URL's dot-separated file suffixes — `archive.tar.gz` with the map below yields `Content-Encoding: x-gzip`, and a doubly-encoded `data.gz.gz` yields `gzip, gzip` (order preserved, duplicates intentionally kept). The middleware is additive: it never overrides a `Content-Encoding` the handler (or a compression middleware that actually encoded the body) already set.

```php
use ZealPHP\Middleware\ContentEncodingMiddleware;

$app->addMiddleware(new ContentEncodingMiddleware([
    'gz'  => 'gzip',
    'br'  => 'br',
    'bz2' => 'bzip2',
]));
```

### `ContentLanguageMiddleware`

Apache `mod_mime AddLanguage` parity. Sets the response `Content-Language` header from the request URL's dot-separated suffixes — `page.en.html` yields `Content-Language: en`. Multiple language suffixes accumulate in order and are emitted comma-joined (RFC 9110 §8.5 allows a list). The middleware only sets the header when the response doesn't already declare one.

```php
use ZealPHP\Middleware\ContentLanguageMiddleware;

$app->addMiddleware(new ContentLanguageMiddleware([
    'en' => 'en',
    'fr' => 'fr',
    'de' => 'de',
]));
```

### `MergeSlashesMiddleware`

Apache `MergeSlashes On` / nginx `merge_slashes` parity. Collapses runs of consecutive slashes in the request path to a single slash before routing, so `/a//b///c` matches the same route as `/a/b/c`. This is an internal rewrite (no redirect) — it mutates `$g->server['REQUEST_URI']`, which the router reads. The query string is left untouched. Register it ahead of route-dependent middleware.

```php
use ZealPHP\Middleware\MergeSlashesMiddleware;

$app->addMiddleware(new MergeSlashesMiddleware());
// Now: /api//users///42 routes the same as /api/users/42
```

### `RequestHeaderMiddleware`

Apache `mod_headers RequestHeader` parity. Manipulates the request headers the application sees before handlers run. Headers are written into `$g->server` using the mod_php CGI convention (`HTTP_<NAME>`, uppercased, dashes → underscores), so `apache_request_headers()`, `getallheaders()`, and `$g->server['HTTP_*']` all reflect the change. Operations: `set` (replace/create), `append` / `add` (comma-joined append or create), `unset`.

```php
use ZealPHP\Middleware\RequestHeaderMiddleware;

$app->addMiddleware(new RequestHeaderMiddleware([
    ['op' => 'set',    'name' => 'X-Forwarded-Proto', 'value' => 'https'],
    ['op' => 'append', 'name' => 'X-Trace',           'value' => 'edge'],
    ['op' => 'unset',  'name' => 'X-Debug'],
]));
```

### `ReturnMiddleware`

nginx `return` directive parity. Unconditionally returns a fixed response — the route handler never runs. For 3xx statuses the second argument is treated as the redirect target (`Location`); for any other status it is the response body. Pair with `ScopedMiddleware` to limit it to a path (the nginx `location { return ... }` shape).

```php
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// Outright block a path
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(403), '/blocked'));

// Permanent redirect from /old → /new
$app->addMiddleware(ScopedMiddleware::match(new ReturnMiddleware(301, '/new'), '#^/old$#'));

// Health-check stub
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(200, 'pong'), '/ping'));
```

### `ScopedMiddleware`

Apply another middleware only to matching request paths — the Apache-container equivalent for middleware. Two factory methods:

- `ScopedMiddleware::location($inner, '/admin')` — `<Location "/admin">`: literal URL-path prefix (matches `/admin`, `/admin/x`, and — like Apache — `/administrator`; use a trailing slash or a regex for segment precision).
- `ScopedMiddleware::match($inner, '#^/api/#')` — `<LocationMatch>` / `<FilesMatch>`: PCRE pattern against the path.

Outside the scope the inner middleware is skipped entirely.

```php
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\BlockPhpExtMiddleware;

$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(realm: 'Admin', htpasswd: __DIR__ . '/.htpasswd'),
    '/admin'
));

$app->addMiddleware(ScopedMiddleware::match(new BlockPhpExtMiddleware(), '#\.php$#'));
```

### `SetEnvIfMiddleware`

Apache `mod_setenvif` parity. Sets request "environment" variables (into `$g->server`, where mod_php code reads them as `$_SERVER`) when an attribute of the request matches a regex. The classic use is tagging bots, internal IPs, or URL areas so downstream middleware / handlers can branch on a simple flag. Attribute names mirror Apache: the special tokens `Remote_Addr`, `Remote_Host`, `Server_Addr`, `Request_Method`, `Request_Protocol`, `Request_URI`; any other name is treated as a request header (so `User-Agent` gives `BrowserMatch` behaviour).

```php
use ZealPHP\Middleware\SetEnvIfMiddleware;

$app->addMiddleware(new SetEnvIfMiddleware([
    ['attr' => 'User-Agent',  'regex' => '#bot#i',    'set' => ['IS_BOT' => '1']],
    ['attr' => 'Request_URI', 'regex' => '#^/admin#', 'set' => ['ADMIN_AREA' => '1']],
    ['attr' => 'Remote_Addr', 'regex' => '#^10\.#',   'set' => ['INTERNAL' => '1']],
]));
```

## Per-route Middleware

Global middleware (`App::addMiddleware()`) wraps *every* request. When a policy belongs to a handful of routes — auth on `/admin`, a rate limit on one endpoint, a correlation id on a job API — attach it **per route** instead.

The reference point for this model is **Hyperf** (a Swoole application server with `#[Middleware]` on routes and per-coroutine context), not Traefik. Traefik is an L7 edge reverse-proxy that forwards to backend services and never runs your code; ZealPHP per-route middleware competes with Slim / Laravel / Hyperf *route* middleware. ZealPHP borrows Traefik's *vocabulary* — named middleware, ordered chains — on top of Hyperf's coroutine **runtime** model.

The differentiator: ZealPHP middleware runs **inside** the request lifecycle. It can read and write `$g`, touch the session, run a `Store`/Redis query, spawn `go()` coroutines, and short-circuit with real application logic — none of which an edge proxy can do. Because per-route middleware runs *after* route matching, path-rewriters (Traefik `StripPrefix` / `AddPrefix` / `ReplacePath`) must stay global / pre-match; auth, headers, rate-limit, redirect, IP allow-list, and compression are clean per-route fits.

### The `middleware:` route option

Every route registrar — `route()`, `nsRoute()`, `nsPathRoute()`, `patternRoute()` — accepts a `middleware:` list of `MiddlewareInterface` instances and/or alias strings. It is purely additive and backward-compatible: routes without `middleware:` are byte-for-byte unchanged (a zero-cost fast path).

```php
use ZealPHP\Middleware\IpAccessMiddleware;

$app->route('/admin/users',
    methods: ['GET'],
    middleware: ['auth', 'request-id', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    handler: fn() => User::all(),
);
```

There are two ways to declare middleware on a route, and they **combine**: the array-option form (`['middleware' => [...]]`) runs first (outermost), then the named-arg `middleware:` entries.

```php
$app->route('/reports',
    ['middleware' => ['audit-log']],     // array option → outermost
    handler: fn() => Report::all(),
    middleware: ['request-id'],          // named arg    → inner of the two
);
```

### Named aliases — `App::middlewareAlias()`

Register a short name once and reference it from any route by string. Pass a **ready instance** (reused as-is) or a **factory callable** that returns a `MiddlewareInterface`. Factories run **once at `App::run()`** (boot, single-coroutine); the resulting instance is **shared** across every request that uses the alias. A parameterised reference like `'throttle:120'` calls the factory with the comma-split args (`fn('120')`) — the Laravel `'throttle:60,1'` shape.

```php
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware};

App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware(htpasswdFile: __DIR__ . '/.htpasswd'));
App::middlewareAlias('admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));
App::middlewareAlias('throttle',   fn($n = '60') => new RateLimitMiddleware(limit: (int)$n));

$app->route('/api/heavy', middleware: ['throttle:120'], handler: fn() => Heavy::run());
```

**Stateless contract:** one alias instance serves every concurrent coroutine, so middleware objects must hold *no per-request state*. Put request-scoped data in `$g` (the request context / memo), never on the middleware instance — exactly how `RequestIdMiddleware` stashes its id in `$g->memo['request_id']`.

### Route groups — `$app->group()`

Share a prefix and a middleware chain across a block of routes. The signature is `group(string $prefix, array|callable $middleware = [], ?callable $registrar = null)` — the middleware may be omitted (`group('/admin', fn($g) => ...)`). The callback receives a `ZealPHP\RouteGroup` whose `route()/nsRoute()/nsPathRoute()/patternRoute()/group()` mirror `App`'s, prepending the prefix and prepending the group's shared middleware. Group middleware wraps **outside** each route's own middleware, which wraps outside the handler. Groups nest.

```php
$app->group('/admin', ['auth', 'admin-only'], function ($g) {
    $g->route('/users', fn() => User::all());

    $g->group('/audit', ['audit-log'], function ($g) {   // → /admin/audit/recent
        $g->route('/recent', fn() => Audit::recent());
    });
});
```

Note: `patternRoute()` inside a group does **not** auto-apply the prefix (a raw regex is ambiguous to prefix) — but the group's shared middleware still applies.

## Path-scoped middleware (`App::when()`)

`App::when()` scopes a middleware chain to a URL **path** rather than to a specific route registration. It is the central, declarative counterpart to the per-route `middleware:` option: instead of repeating `middleware: ['auth']` on every route under `/admin`, declare it **once** by path.

```php
App::when(string $pathPrefixOrRegex, MiddlewareInterface|string|array $middleware): void
```

The design decision behind it: **there is no separate "API middleware."** API endpoints (`api/**/*.php`) are just routes reached by `/api/...` URLs, and they flow through the **same** global pipeline as every other route. So one path-scoped verb covers everything — ordinary routes **and** the file-based API — with no second registry to learn.

### Scope syntax

The first argument selects *which paths* the chain applies to:

| Form | Example | Matches |
|------|---------|---------|
| **Literal path prefix** (default) | `'/admin'` | `/admin` and `/admin/anything` — but **not** `/administrators` |
| **PCRE** (string starts with `#`) | `'#^/api/v\d+/#'` | any path the regex matches |
| **Everything** | `'/'` or `''` | every request |

Prefix matching is **segment-safe**: `/admin` matches the `/admin` segment and anything below it, but never a longer word that merely starts with the same letters (`/administrators` is *not* matched). Use a regex when you need finer control.

### What the chain accepts

The `$middleware` argument is the **same** shape the route `middleware:` option accepts — and it reuses the **same** `App::middlewareAlias()` registry:

- a ready `MiddlewareInterface` instance,
- a registered alias string (including a parameterised reference like `'throttle:120'`),
- or a list mixing both.

```php
use ZealPHP\Middleware\IpAccessMiddleware;

// Alias chain, scoped to an entire path prefix:
App::when('/admin', ['auth', 'admin-only']);

// A live instance is fine too:
App::when('/internal', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));

// Regex scope + a parameterised alias:
App::when('#^/api/v\d+/#', ['throttle:120']);
```

Because the file-based API is reached through `/api/...`, the **same** verb guards it — no API-specific API:

```php
// Every api/secured/*.php endpoint (e.g. GET /api/secured/list) is guarded:
App::when('/api/secured', ['api-secured']);

// Short-circuit a whole API namespace with a fixed response:
use ZealPHP\Middleware\ReturnMiddleware;
App::middlewareAlias('block', new ReturnMiddleware(403));
App::when('/api/blocked', ['block']);   // GET /api/blocked/secret → 403, handler never runs
```

A sibling namespace with **no** `App::when()` declaration is untouched — that is the scoping proof: `GET /api/secured/list` carries the guard's header, `GET /api/open/list` does not.

### API in-file `$middleware` — co-located per-file guards

An `api/**/*.php` file may declare an in-file `$middleware` list, read the same way the dispatcher reads `$get` / `$post`:

```php
// api/secured/profile.php
$middleware = ['request-id'];   // co-located guard, closest to the handler

$get = function () {
    // handler reads the id the in-file middleware stamped:
    return ['request_id' => ZealPHP\RequestContext::once('request_id', fn () => null)];
};
```

In-file `$middleware` runs **innermost** — after any `App::when()` scope that covers the file, closest to the handler. It is resolved and memoized per file and reuses the **same** alias registry. Use it for a guard that belongs to exactly one endpoint and reads best next to its handler.

### Ordering — `App::when()` is its own band

`App::when()` inserts a new band into the pipeline, between the global stack and the route's own middleware:

```
global addMiddleware  →  App::when (registration order)  →  route middleware: / api in-file $middleware  →  handler
```

- **`App::when()` composes in registration order — the first `App::when()` you register is the outermost** (it processes the request first and the response last), exactly like the global stack.
- A route's own `middleware:` (or an API file's in-file `$middleware`) runs **inside** every `App::when()` scope that matches, closest to the handler.
- The response unwinds in reverse, and any middleware that returns **without** calling the handler (a 403, a redirect) short-circuits the chain before the handler runs.

### Where it runs in the request lifecycle

`App::when()` middleware runs **inside** `ResponseMiddleware::process()` — **after** path normalization and **after** OPTIONS / CORS-preflight handling, wrapping route match + dispatch. The preflight ordering is deliberate: a `when()` auth guard **never blocks a CORS preflight**, because the preflight short-circuits before the `when()` band is entered.

### Resolution and the stateless contract

Alias-to-instance resolution happens **once at `App::run()`** (boot, single-coroutine). At request time, `App::when()` is a cheap, memoized path scan — read-only after boot, therefore coroutine-safe. As with every other ZealPHP middleware band, the resolved instance is **shared** across concurrent requests, so the chain **must be stateless**: keep per-request state in `$g` (`RequestContext`), never on the middleware object.

### A non-API example — `App::when()` is not API-only

Path scoping applies to ordinary routes just as well as to `/api/*`:

```php
App::middlewareAlias('demo-header', /* … stamps X-Demo-Route … */);

App::when('/demo/scoped', ['demo-header']);

$app->route('/demo/scoped/test', fn () => 'scoped');   // response carries X-Demo-Route
```

### Ordering

One rule, pinned crisply: **first-registered (or first-listed) is outermost** — it processes the request first and the response last.

```
global  →  App::when (registration order)  →  group  →  route / api in-file  →  handler
```

Within each band, the first entry you add or list is the outer wrap; the response unwinds in reverse. A middleware that returns *without* calling the handler (a 403, a redirect) short-circuits the chain before the handler runs. This is consistent with the global stack: OpenSwoole's `StackHandler::add()` prepends, and the `array_reverse` at `run()` means the **first** middleware you add is outermost — the first to run. (Earlier revisions of this doc said "last added runs first"; that was wrong for the outermost/innermost framing — the first you add is the first to *process the request*.)

### Coroutine-safety status

Per-route middleware rides *on* ZealPHP's coroutine-safety substrate, so what is safe depends on what each middleware touches:

| Status | Middleware / pattern |
|--------|----------------------|
| **Coroutine-safe now** | `RateLimitMiddleware` + `ConcurrencyLimitMiddleware` (backed by `Store` / `Counter` shared memory) |
| **Feasible now** | ForwardAuth, request-level CircuitBreaker, Retry — on hooked backends (the `ZealPHP\HTTP` coroutine client, `Store`, the pooled Redis client) |
| **Blocked** | DB-backed auth/session middleware — waits on the per-coroutine DB connection pool. `pdo_pgsql` still blocks the worker (needs a native Postgres coroutine client) |

### Visualizing the chains

`$app->describeRoutes()` returns the whole picture and works before **and** after `run()`:

```php
$map = $app->describeRoutes();
// [
//   'global'  => ['CorsMiddleware', 'ETagMiddleware', 'ResponseMiddleware (router)'],
//   'aliases' => ['auth', 'admin-only', 'throttle', 'request-id'],
//   'routes'  => [
//     ['methods' => ['GET'], 'path' => '/admin/users',
//      'middleware' => ['auth', 'request-id', 'IpAccessMiddleware'], 'handler' => 'Closure'],
//   ],
// ]
```

The `global` chain is in execution order, ending with `ResponseMiddleware (router)`; each middleware name is the resolved instance's class short-name, or the alias string before resolution. The demo exposes this live at `GET /demo/middleware/visualize`, and the `/middleware` page renders it inline as a Traefik-style ordered-chain view (the **Live middleware visualizer** section).

## ZealAPI Auth Hooks

For file-based API handlers under `api/`, ZealPHP ships first-class authentication integration points rather than requiring manual session checks inside every closure. Wire the three callbacks once at boot and every `api/*` handler gains access to the same auth logic:

```php
use ZealPHP\App;
use ZealPHP\G;

// Register before App::run()
App::authChecker(fn() => !empty(G::instance()->session['user_id']));
App::adminChecker(fn() => !empty(G::instance()->session['is_admin']));
App::usernameProvider(fn() => G::instance()->session['username'] ?? null);
```

Inside any API closure, the `$this` context is the `ZealAPI` instance, so:

```php
// api/orders/create.php
$post = function () {
    if (!$this->requirePostAuth()) {
        return; // 403 already sent
    }
    // $this->isAuthenticated(), $this->isAdmin(), $this->getUsername() all available
    return ['status' => 'created'];
};
```

All three hooks default to `null` (fail-closed): without `App::authChecker()`, `isAuthenticated()` returns `false` and `requirePostAuth()` rejects every request. See `template/pages/api.php` on the live site for the full auth-hooks reference.

## Future Directions

`standards-and-roadmap.md` tracks planned improvements such as:

- A higher-level `Auth` facade built on top of the existing `App::authChecker()` / `App::adminChecker()` / `App::usernameProvider()` hooks (sessions, JWT, API keys in one call).

Note: middleware groups and route-scoped stacks now ship — see [Per-route Middleware](#per-route-middleware) above (`middleware:` route option, `App::middlewareAlias()`, `$app->group()`). CSRF protection (`CsrfMiddleware`), CORS (`CorsMiddleware`), and rate limiting (`RateLimitMiddleware`) are already shipped as built-in PSR-15 middleware — see `template/pages/middleware.php` on the live site.

Contributions in these areas are welcome—align proposals with the PSR-15 contract to keep interoperability intact.
