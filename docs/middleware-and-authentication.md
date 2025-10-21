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

## Future Directions

`standards-and-roadmap.md` tracks planned improvements such as:

- Middleware groups and route-scoped stacks.
- First-class `Auth` facade for common patterns (sessions, JWT, API keys).
- Declarative configuration for CORS and rate limiting.

Contributions in these areas are welcome—align proposals with the PSR-15 contract to keep interoperability intact.
