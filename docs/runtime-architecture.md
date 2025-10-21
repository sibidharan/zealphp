# Runtime Architecture

ZealPHP wraps OpenSwoole’s event-driven HTTP server with a framework that feels familiar to PHP developers while enabling coroutine-friendly patterns. This document highlights the moving parts that collaborate during a request, how state is isolated, and how to opt into advanced execution modes.

## Bootstrapping

`App::init()` performs one-time initialization:

- Validates that the `uopz` extension is loaded (ZealPHP would otherwise be unable to intercept functions such as `header()` or `setcookie()`).
- Records the current working directory and entry script so the framework can build absolute paths later.
- Prepares the PSR-15 middleware stack (`OpenSwoole\Core\Psr\Middleware\StackHandler`) with `ResponseMiddleware` as the terminal handler.
- Configures coroutine hooks if superglobals are disabled (see below).
- Overrides built-in PHP functions via `uopz_set_return()` to route them through ZealPHP shims. This ensures headers, cookies, and response codes cooperate with the PSR response pipeline.

`App::run()` then constructs the OpenSwoole HTTP server, includes custom route files, registers implicit routes, wires session managers, and starts the event loop. Pass an array of OpenSwoole settings to override defaults:

```php
$app->run([
    'enable_static_handler' => false,
    'task_worker_num' => 8,
    'document_root' => __DIR__ . '/public',
]);
```

ZealPHP merges your configuration with its defaults (`enable_static_handler: true`, `task_worker_num: 4`, `pid_file: /tmp/zealphp.pid`, etc.) and forces `enable_coroutine` based on the superglobal mode.

## Superglobals and the `G` Container

Traditional PHP scripts rely on `$_GET`, `$_POST`, `$_SERVER`, etc. ZealPHP emulates this behaviour while running inside an event loop by funneling state through `ZealPHP\G`:

- When `App::$superglobals` is **true** (default), each request reconstructs the real PHP superglobals before executing route handlers. The `G` container proxies get/set operations so legacy code “just works.”
- When `App::superglobals(false)` is called, ZealPHP stops mutating global arrays and instead uses coroutine-safe properties on the `G` instance. In this mode, OpenSwoole coroutine hooks are enabled and you can safely use `go()` from within the main request handler. Access request data through `$g = G::instance(); $g->get`, `$g->server`, etc.

`G` owns additional request-scoped values:

- `zealphp_request` / `zealphp_response` – The wrapped OpenSwoole request/response objects exposed to route and API handlers.
- `status` – The HTTP status code chosen by the handler (defaults to 200).
- `response_headers_list`, `response_cookies_list`, `response_rawcookies_list` – Buffers used by `prefork_request_handler()` to flush headers back to the parent process.

## Request Lifecycle

1. **Session Manager**: Each incoming request is wrapped in either `Session\SessionManager` or `Session\CoSessionManager` depending on the superglobal mode. The manager drives `session_start()`, associates the request with `G`, and ensures `session_write_close()` always runs.
2. **Middleware Stack**: The OpenSwoole request is converted to a PSR-7 request (`OpenSwoole\Core\Psr\ServerRequest::from(...)`). ZealPHP walks the middleware stack in reverse registration order until `ResponseMiddleware` executes.
3. **Route Matching**: `ResponseMiddleware` evaluates the registered routes in the order they were defined. It supports:
   - Exact routes (`$app->route('/foo', ...)`)
   - Namespaces (`$app->nsRoute('admin', '/dashboard', ...)`)
   - Path-based namespaces (`$app->nsPathRoute('api', '{module}/{action}', ...)`)
   - Regular expressions (`$app->patternRoute('/raw/(?P<rest>.*)', ...)`)
4. **Handler Invocation**: Parameters captured from the URI are injected into the handler based on argument names. ZealPHP also recognises special parameters: `app`, `request`, `response`, and `server`.
5. **Response Resolution**:
   - If the handler returns an `OpenSwoole\Core\Psr\Response`, ZealPHP emits it immediately.
   - If it returns an `int`, the value becomes the HTTP status code.
   - If it returns an array/object, ZealPHP serialises it to JSON and sets `Content-Type: application/json`.
   - Otherwise buffered output (including `echo`) is sent as the response body.
   - Exceptions are caught, logged via `elog()`, and transformed into formatted stack traces when `App::$display_errors` is true.

## Prefork Execution

ZealPHP favours a single-request-per-worker model to protect superglobals. When you need to isolate work:

- `prefork_request_handler(callable $taskLogic)` executes the callback in a forked worker process. It captures buffered output, HTTP status, headers, and cookies, then streams them back to the parent request. Use this for legacy code that assumes blocking semantics or manipulates globals heavily.
- `coprocess()` / `coproc()` create dedicated processes with coroutine support for longer running workloads that should not block the main worker. These helpers are only available when superglobals are enabled (`coproc` throws otherwise).

## Task Workers

`$server->task([...])` dispatches background jobs to OpenSwoole task workers. ZealPHP provides a simple convention: task handlers live in `task/<name>.php` and define a closure matching the filename. Within an API or route handler, pass the handler path and arguments, then process the asynchronous response in the finish callback. See `api/swoole/task.php` and `task/backup.php` for the reference pairing.

## Middleware and PSR Integration

ZealPHP speaks PSR-7 (HTTP messages) and PSR-15 (HTTP server middleware). Custom middleware implements `Psr\Http\Server\MiddlewareInterface` and is pushed via `App::addMiddleware()`. Items are buffered until `App::run()` registers them, ensuring everything executes within the same PSR stack alongside the built-in `ResponseMiddleware`.

Common use cases:

- Authentication/authorisation checks
- CSRF validation for form endpoints
- Request/response logging
- Response header shaping (e.g., HSTS, CORS)

See [middleware-and-authentication.md](middleware-and-authentication.md) for concrete examples.

## Session Management

`SessionManager` orchestrates cookie-based sessions that mimic native PHP semantics:

- Generates IDs via `session_create_id()` unless an incoming cookie or request parameter provides one (configurable to use-only-cookies).
- Persists session state using the custom `FileSessionHandler`.
- Attaches request/response wrappers to `G` so handlers can reach `ZealPHP\HTTP\Request` and `ZealPHP\HTTP\Response`.

When superglobals are disabled, `CoSessionManager` applies the same behaviour while remaining coroutine-safe.

## Error Handling and Logging

- Use `elog($message, $level)` to emit structured logs. Levels such as `"warn"`, `"error"`, and `"task"` are used throughout the framework.
- `jTraceEx($exception)` builds Java-style stack traces for easier debugging.
- When `App::$display_errors` is false, clients receive generic `500 Internal Server Error` responses even if the server logs the detailed exception.

## Choosing Between Execution Modes

| Mode | `App::superglobals(...)` | Coroutines (`go()`) | Prefork / `coproc` | Use Case |
|------|--------------------------|---------------------|--------------------|----------|
| Legacy | `true` (default) | Disabled in main request; use `coproc()` or task workers | Available | Drop-in support for existing PHP apps that expect mutable superglobals. |
| Coroutine-first | `false` | Enabled automatically | `coproc()` unavailable (superglobals disabled), still can use task workers | New code bases leveraging async IO and coroutine scheduling without global state. |

Pick the mode that matches your application’s performance profile. You can toggle superglobals early in `app.php` before calling `App::run()`.
