# Middleware and Authentication

Middleware is the preferred way to enforce cross-cutting policies in ZealPHP. The framework embraces PSR-15 (`Psr\Http\Server\MiddlewareInterface`) and runs every request through a configurable stack before handing it to the routing engine. This guide shows how to register middleware, build authentication flows, and combine them with the file-based routing model.

## Middleware Pipeline Overview

1. `App::init()` seeds the pipeline with `ResponseMiddleware`, which performs route matching and response emission.
2. Custom middleware added with `App::addMiddleware()` is stored until `App::run()` executes; at that point ZealPHP adds each middleware to the `StackHandler` in LIFO order (last added, first executed).
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
- Perform asynchronous validation using `go()` (when superglobals are disabled) or `prefork_request_handler()` to avoid blocking.
- Populate `G::instance()->session` or attach attributes to the PSR request (e.g., `$request = $request->withAttribute('user', $user);` before passing it down).

Your API handlers can then pull attributes from the PSR request via `$request->getAttribute('user')`.

## Testing Middleware

While ZealPHP does not yet ship a testing harness, you can instantiate middleware classes directly and feed them mocked `ServerRequestInterface` objects. The repository’s examples demonstrate how to wrap OpenSwoole requests; reuse them in unit tests.

## Built-in Middleware Classes

ZealPHP ships parity middleware for Apache/nginx behaviours. The framework's per-middleware reference table lives in `template/pages/middleware.php` (the website's `/middleware` page). The following entries cover seven additional classes — all opt-in, all PSR-15 — that complete the parity surface.

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

## Future Directions

`standards-and-roadmap.md` tracks planned improvements such as:

- Middleware groups and route-scoped stacks.
- First-class `Auth` facade for common patterns (sessions, JWT, API keys).
- Declarative configuration for CORS and rate limiting.

Contributions in these areas are welcome—align proposals with the PSR-15 contract to keep interoperability intact.
