# Error Handling

ZealPHP provides three layers of error-handling parity with Apache+mod_php:

1. **Custom error pages** — Apache `ErrorDocument` equivalent (`App::setErrorHandler()`).
2. **Per-coroutine PHP handlers** — `set_error_handler`, `set_exception_handler`, `register_shutdown_function` are isolated per request despite being process-global in vanilla PHP.
3. **Content negotiation** — default error bodies respect `Accept: application/json` for API clients.

For the broader Apache parity surface (uopz overrides, public/ routing, sendFile, CGI worker), see [apache-parity.md](apache-parity.md).

---

## Overview

mod_php apps assume two things ZealPHP must emulate:

- **One PHP process per request.** Setting `set_error_handler()` in one request shouldn't catch warnings in another. `register_shutdown_function()` should run at the end of THIS request, not when the worker dies.
- **`ErrorDocument N /path`** in `.htaccess` lets you wire a custom page for any HTTP status. ZealPHP exposes this as `App::setErrorHandler($status, $handler)`.

Both rely on per-coroutine state in `G` plus a single process-level native handler installed at boot that delegates to the active coroutine's stack.

---

## `App::setErrorHandler()` — Apache `ErrorDocument` equivalent

```php
// Status-specific
$app->setErrorHandler(404, function($status) {
    return App::renderToString('error/404', ['status' => $status]);
});

$app->setErrorHandler(500, function($exception) {
    return ['error' => 'Internal Server Error', 'trace_id' => uniqid()];
});

// Catch-all (fires when no status-specific handler matches)
$app->setErrorHandler(function($status, $exception) { /* ... */ });
```

### Handler param injection

The handler is dispatched through the same `ResponseMiddleware::dispatchRoute()` machinery as a regular route. Param injection by name:

| Param | Value |
|---|---|
| `$status` | The error status code being rendered (`int`). |
| `$exception` | The caught `\Throwable` (only present for 500 cases originating from a throw; `null` otherwise). |
| `$request` | `ZealPHP\HTTP\Request` wrapper. |
| `$response` | `ZealPHP\HTTP\Response` wrapper. |
| `$app` | The middleware instance (rarely needed). |

### Handler return values

Same conventions as a normal route handler — see [routing.md](routing.md):

| Return | Effect |
|---|---|
| `string` | HTML body. |
| `array` / object | JSON-serialized, `Content-Type: application/json`. |
| `\Generator` | Streaming response (writes chunks via `OpenSwoole\Response::write()`, ends inline). |
| `Psr\Http\Message\ResponseInterface` | Used directly. |
| `void` + `echo` | Output buffer captured and used as body. |
| `int` (4xx/5xx) | Re-routes through `renderError()` — but inside a handler this triggers the recursion guard and returns the framework default. |

The error status is seeded into `$g->status` before dispatch, so a handler returning a plain string produces a Response with the right status code. Handlers can override via `http_response_code()` inside the body.

### When handlers fire

`renderError($status, ?$exception)` is called from every error site in the framework — 14 of them:

| Site | Status | Trigger |
|---|---|---|
| `dispatchRoute` is_int branch | as returned | `return 404;` from any route handler |
| `dispatchRoute` catch | 500 | uncaught Throwable from a route handler |
| `dispatchRawRoute` catch | 500 | same, for raw routes |
| `dispatchRoute` exit-nonzero | 500 | `exit(1)` / `die(1)` from a handler |
| Top-level `on('request')` catch | 500 | exceptions outside route dispatch |
| `.php` block route | 403 | explicit `.php` URL when `App::$ignore_php_ext` |
| Dotfile pattern route | 403 | URL with dotfile component |
| 3× implicit-route 403 branches | 403 | `includeCheck()` reject |
| URL-decoded traversal check | 400 | `..`, `\0`, backslash in path |
| `invokeFallbackOrNotFound()` no-fallback branch | 404 | unmatched URL when no fallback set |
| `ResponseMiddleware::process()` final 404 | 404 | unmatched URL (no implicit/explicit/fallback match) |

In every case, the handler return flows back through `dispatchRoute`'s `ResponseInterface` branch and emits as a normal PSR response.

---

## `App::renderError()` — the central dispatcher

[`App::renderError(int $status, ?\Throwable $exception = null): ResponseInterface`](../src/App.php):

```
1. Read G->error_render_depth — if >= 1, skip dispatch, go straight to defaultErrorResponse.
   (Recursion guard: a handler that triggers another error doesn't loop.)
2. Look up handler: status-specific → catch-all → null.
3. If found: seed G->status = $status, increment error_render_depth, dispatch via
   ResponseMiddleware::dispatchRoute([handler, param_map, raw], ['status' => $status, 'exception' => $exception]).
4. On catch: log, decrement depth, fall through.
5. defaultErrorResponse — content-negotiated HTML or JSON body.
```

### Recursion guard

When a 500 handler itself throws, naively the dispatchRoute catch would call `renderError(500, $e)` again — infinite recursion. The guard:

- `renderError` increments `G->error_render_depth` before dispatch.
- `dispatchRoute`'s catch checks `G->error_render_depth > 0` and **rethrows** instead of calling renderError again.
- The throw propagates back to renderError's own try/catch, which falls to `defaultErrorResponse()` for the ORIGINAL status (not 500).
- After dispatch (success or exception), depth is decremented in a `finally`.

So a 502 handler that throws → the framework returns the **default 502 page**, not a default 500 page.

### Default body — content negotiation

When no handler is registered (or the registered one threw), [`App::defaultErrorResponse()`](../src/App.php) inspects request `Accept`:

```php
$wantsJson = str_contains($accept, 'application/json')
          && !str_contains($accept, 'text/html');
```

- **JSON:** `{"error": {"status": 500, "message": "Internal Server Error", "trace": "..."}}` — trace populated only when `App::$display_errors`.
- **HTML:** `<pre>{status} {reason}</pre>` plus optional trace block.

Reason phrases come from a const map (`App::REASON_PHRASES`) covering 400/401/403/404/405/406/408/409/410/413/414/415/418/422/429/500/501/502/503/504. Custom handlers override negotiation — user intent trumps `Accept`.

---

## Per-coroutine error/exception/shutdown handlers

### Storage

Three properties on `G`:

```php
public array $error_handlers_stack = [];      // [[callable, levels], ...]
public array $exception_handlers_stack = [];  // [callable, ...]
public array $shutdown_functions = [];        // [[callable, args], ...]
```

### uopz overrides (namespaced)

In [`src/utils.php`](../src/utils.php):

| Function | Effect on G |
|---|---|
| `\ZealPHP\set_error_handler($cb, $levels)` | push `[$cb, $levels]` onto stack; return previous top callable |
| `\ZealPHP\restore_error_handler()` | pop stack |
| `\ZealPHP\set_exception_handler($cb)` | push onto stack; return previous |
| `\ZealPHP\restore_exception_handler()` | pop |
| `\ZealPHP\register_shutdown_function($cb, ...args)` | append `[$cb, $args]` to queue |
| `\ZealPHP\error_reporting($level)` | read/write `G->error_reporting_level` (defaults to `App::$initial_error_reporting`) |

Each registered via `uopz_set_return` in `App::__construct()`.

### Process-level native dispatcher — installed BEFORE uopz

Order matters. At the very top of `App::__construct()`:

```php
self::$initial_error_reporting = \error_reporting();   // capture before uopz overrides it

\set_error_handler(static function ($severity, $message, $file, $line) {
    $g = G::instance();
    $level = $g->error_reporting_level ?? App::$initial_error_reporting;
    if (!($severity & $level)) return true;            // suppressed per-coroutine
    $stack = $g->error_handlers_stack;
    if (!empty($stack)) {
        [$callable, $levels] = $stack[count($stack) - 1];
        if ($severity & $levels) {
            try { return (bool)$callable($severity, $message, $file, $line); }
            catch (\Throwable $e) { return false; }   // avoid loops
        }
    }
    return false;                                       // PHP default
});

\set_exception_handler(static function (\Throwable $e) {
    $g = G::instance();
    $stack = $g->exception_handlers_stack;
    if (!empty($stack)) {
        try { $stack[count($stack) - 1]($e); } catch (\Throwable $e2) {}
    }
});

// uopz_set_return calls follow ...
```

After uopz installs, user-space `set_error_handler(...)` writes to `G` instead of overwriting the native handler. Engine-raised errors still flow through the bootstrap dispatcher, which now reads per-coroutine state.

### Exception handler wired into dispatchRoute

`set_exception_handler` normally only fires for uncaught exceptions — but ZealPHP's `dispatchRoute` catches everything before they bubble out. To make the API useful, both catch blocks check `G->exception_handlers_stack` before falling through to `renderError`:

```php
} catch (\Throwable $e) {
    if ($e instanceof ExitException) { /* ... */ }

    // Inside error-render recursion → rethrow (handled by outer renderError).
    if (($g->error_render_depth ?? 0) > 0) { @ob_end_clean(); throw $e; }

    // User-installed exception handler runs before default error page.
    $excStack = $g->exception_handlers_stack ?? [];
    if (!empty($excStack)) {
        ob_start();
        try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) {}
        $body = ob_get_clean();
        return (new Response($body))->withStatus($g->status ?? 500);
    }

    return App::instance()->renderError(500, $e);
}
```

### Per-request shutdown function lifecycle

[Inside the `on('request')` handler](../src/App.php), after the middleware stack returns but BEFORE PSR emit:

```
1. capture $serverResponse = middleware->handle(...)
2. read $g->shutdown_functions queue
3. ob_start()  (nested under any existing buffer)
4. for each [fn, args]: try fn(...args); catch -> log
5. clear $g->shutdown_functions = []
6. capture ob_get_clean() — if non-empty, append to $serverResponse body
7. if $g->status was changed by a shutdown fn -> $serverResponse->withStatus($g->status)
8. emit normally
```

This lets shutdown functions still mutate the response — they can `echo`, call `http_response_code(503)`, or modify state. Mod_php semantics expect output and headers to still be settable from shutdown functions, so we run them before the wire bytes leave.

The `$body . $extra` write uses a fresh `php://temp` stream wrapped in `OpenSwoole\Core\Psr\Stream` because the PSR Stream interface doesn't expose a way to append to an existing body.

---

## Important caveats

### Handler registration is process-global

`App::setErrorHandler()` writes to `App::$error_handlers` — a STATIC array. Registering a handler from inside a request mutates the global registry for ALL subsequent requests on this worker. Recommended pattern: register handlers at boot (in `app.php` or a route fixture file), not from inside route handlers.

### Tests must avoid handler-from-route mutation

Integration tests that need to verify handler-specific shapes (array return, Generator return) should either (a) use a status code that's unused elsewhere, or (b) sub-dispatch by URI inside the globally-registered handler. The [test fixture](../route/_error_test.php) demonstrates pattern (b): one `setErrorHandler(404, ...)` is registered at fixture load, and it dispatches by URI suffix internally.

### Status codes OpenSwoole drops

OpenSwoole's `Response::status()` honors only the status codes in its internal reason-phrase table. Codes like **308** and **451** are missing and silently downgrade to 200 unless you pass an explicit reason phrase. The framework's `redirect()` works around this for 308. For custom error pages on those statuses, either pick a different code or call `$response->parent->status($code, 'Reason Phrase')` directly in your handler.

### Generator status preservation

`Response::flush()` clears `G->status` to null as part of pushing headers to OpenSwoole. For Generator-returning handlers, `dispatchRoute` captures the status BEFORE calling flush:

```php
if ($object instanceof \Generator) {
    $streamStatus = $g->status ?? 200;             // capture FIRST
    $g->openswoole_response->status($streamStatus);
    $g->zealphp_response->header('Accept-Ranges', 'none');
    $g->zealphp_response->flush();                 // safe to clear g->status now
    foreach ($object as $chunk) { ... }
    return (new Response('', $streamStatus));
}
```

Both `dispatchRoute` and `dispatchRawRoute` apply this pattern.

---

## End-to-end flow

### Custom 404 from `return 404;`

```
Route handler returns 404
    -> dispatchRoute is_int branch: $istatus=404, in [400,600) → App::instance()->renderError(404)
        -> renderError: depth=0, look up 404 handler → found
            -> seed G->status=404, depth=1
            -> ResponseMiddleware->dispatchRoute([handler, param_map, raw], ['status'=>404,'exception'=>null], 'GET')
                -> Inner dispatch: ob_start, call handler($status=404, ...)
                -> Handler returns "CUSTOM-404-BODY" (string)
                -> Inner dispatch is_string branch: ob_end_clean, return Response("CUSTOM-404-BODY", 404)
            <- ResponseInterface returned
        <- decrement depth back to 0
    <- outer dispatchRoute's is_int branch returns the inner ResponseInterface
    -> bubbles up the PSR middleware stack
    -> emitted to client: 404 with body "CUSTOM-404-BODY"
```

### Throwing 502 handler — recursion guard activates

```
Route returns 502
    -> dispatchRoute is_int → renderError(502)
        -> depth=0, find 502 handler, seed status=502, depth=1
        -> dispatchRoute(502_handler)
            -> handler throws RuntimeException
            -> dispatchRoute catch: ExitException? no; depth=1 > 0 → RETHROW
        <- catch in renderError's try fires
        -> log "Error handler for 502 itself threw"
        -> finally: depth=0
    <- return defaultErrorResponse(502, null)
        -> Accept: text/html → `<pre>502 Bad Gateway</pre>`
    -> emit
```

### Concurrent error handlers — isolation

```
Coroutine A: handler /slow-handler-set
    -> set_error_handler($cbA) (push to G_A->error_handlers_stack)
    -> co::sleep(0.5)
                                          Coroutine B: handler /fast-trigger
                                          -> @trigger_error('from B', E_USER_WARNING)
                                          -> native dispatcher fires:
                                              $g = G::instance() -> G_B (different coroutine context)
                                              G_B->error_handlers_stack = []
                                              return false (PHP default)
                                          -> handler returns JSON {handler_fired: 0}
    -> sleep done, returns
```

A's handler never sees B's warning because the native dispatcher reads `G::instance()` — which returns the coroutine-scoped instance via `OpenSwoole\Coroutine::getContext()` when `App::$superglobals === false`.

---

## Verification

Three integration test suites cover the surface:

- [`tests/Integration/ErrorHandlingTest.php`](../tests/Integration/ErrorHandlingTest.php) — 9 cases on `setErrorHandler`: status-specific 404/500/403/400/418 handlers, exception param injection, array→JSON, Generator streaming, handler-self-throws recursion guard, status-only-return routing.
- [`tests/Integration/ErrorHandlersIsolationTest.php`](../tests/Integration/ErrorHandlersIsolationTest.php) — 10 cases: warning capture, stack push/restore/pop-beyond-empty, **cross-coroutine isolation** (curl_multi staggered fire + Store-backed CID write), exception handler echo, shutdown function order/status/per-request/throw-survives.
- [`tests/Integration/ContentNegotiationTest.php`](../tests/Integration/ContentNegotiationTest.php) — 6 cases: HTML default, custom handler wins over Accept, per-request `error_reporting`, suppression by level.

Manual smoke:

```bash
curl http://localhost:8080/__error_test/throw-not-found                       # CUSTOM-404-BODY
curl -H 'Accept: application/json' http://localhost:8080/this-does-not-exist  # default 404 (HTML via fallback fixture, or JSON otherwise)
curl http://localhost:8080/__error_test/handler-self-throws                   # default 502 (recursion guard)
curl http://localhost:8080/__error_test/exception-handler-echo                # HANDLED:boom-exc
curl http://localhost:8080/__error_test/shutdown-echo                         # HANDLER-RANSHUTDOWN-RAN
curl http://localhost:8080/__error_test/shutdown-status                       # status 503
```

## Source map

| Concern | File |
|---|---|
| `setErrorHandler`, `renderError`, `defaultErrorResponse`, `REASON_PHRASES` | [src/App.php](../src/App.php) |
| Process-level native handler bootstrap | [src/App.php](../src/App.php) `__construct()` (top) |
| `set_error_handler` / `set_exception_handler` / `register_shutdown_function` / `error_reporting` overrides | [src/utils.php](../src/utils.php) |
| G state (`error_handlers_stack`, `exception_handlers_stack`, `shutdown_functions`, `error_reporting_level`, `error_render_depth`, `error_status`, `error_exception`) | [src/G.php](../src/G.php) |
| Exception handler integration in `dispatchRoute` / `dispatchRawRoute` catch | [src/App.php](../src/App.php) |
| Shutdown function drain | [src/App.php](../src/App.php) `on('request')` handler |
| Test fixture | [route/_error_test.php](../route/_error_test.php) |
