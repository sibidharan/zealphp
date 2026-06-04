# API Layer

ZealPHP exposes a lightweight convention for building HTTP APIs while preserving the familiar ergonomics of file-based PHP. Each endpoint lives in `api/<module>/<action>.php` (module optional) and exports a closure whose name matches the file base name. `ZealAPI` discovers and binds these closures at runtime, injecting useful helpers and enforcing PSR-compatible responses.

## File Structure and Naming

- `api/<name>.php` &rarr; `/api/<name>`
- `api/<module>/<action>.php` &rarr; `/api/<module>/<action>`

ZealAPI supports two dispatch modes inside each file.

### Mode 1 — Filename match (all HTTP methods)

Assign a closure to a variable whose name matches the file base name. It receives every HTTP method:

```php
<?php
// File: api/device/list.php

$list = function () {
    // Mode 1 sends EVERY HTTP method to this one closure. Use the helper to
    // tell which verb came in (there is no separate $get/$post here):
    $method = $this->get_request_method();   // 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'

    if ($method === 'POST') {
        return $this->json(['created' => true, 'method' => $method]);
    }

    return $this->json(['devices' => [], 'method' => $method]);
};
```

`ZealAPI::processApi()` includes the file, binds `$list` to the API object (`$this`), and executes it. Since a Mode 1 closure answers **every** verb, `$this->get_request_method()` (returns `GET`/`POST`/`PUT`/`DELETE`/`PATCH`, defaulting to `GET`) is how you branch on the HTTP method inside it — the per-method helpers table below lists the related request accessors. If you'd rather split each verb into its own closure, use Mode 2 (per-method dispatch) instead.

### Mode 2 — Per-method dispatch

When no filename-matching variable exists, ZealAPI looks for `$get`, `$post`, `$put`, `$delete`, and `$patch` closures and dispatches by HTTP method:

```php
<?php
// File: api/users.php

$get = function () {
    return $this->json(['users' => []]);
};

$post = function () {
    // create a user
    return $this->json(['created' => true]);
};
```

Behaviour of per-method dispatch:
- **Undefined methods** return `405 Method Not Allowed` with an `Allow` response header listing the supported methods plus `OPTIONS`, and a machine-readable JSON body:
  ```json
  {"error": "method_not_allowed", "allowed": ["GET", "POST", "OPTIONS"]}
  ```
- **HEAD** is automatically derived from `$get` — no separate handler needed.
- **OPTIONS** is always appended to the `Allow` list.

**Priority:** filename match always wins. If `$list` exists in `list.php`, any `$get`/`$post` in the same file are unreachable (a warning is logged).

**Error responses when no handler is found:**
- File missing or path outside `api/` → `404 method_not_found`
- File exists but defines neither a filename-match closure nor any method handler → `404 handler_not_found` (with a hint)

## Handler Signature

ZealPHP inspects the closure signature and injects arguments by name. Supported parameters:

- **Framework objects**:
  - `$app` – current `ZealPHP\ZealAPI` instance
  - `$request` – `ZealPHP\HTTP\Request` wrapper
  - `$response` – `ZealPHP\HTTP\Response` wrapper
  - `$server` – underlying `OpenSwoole\HTTP\Server`
- **Any other name** – receives `null`, or the parameter's declared default value if one exists.

> **ZealAPI does NOT inject route path parameters.** The URL segments `module` and `action` are consumed by `processApi()` during file resolution and are never passed as closure arguments. To read URL path values use `$request->get` (the query-string array) or `$this->_request` (the cleaned merged inputs). There is no `{id} → $id` injection in ZealAPI — that feature belongs to `$app->route()` handlers, not file-based API closures.

Example (`api/response/override.php`):

```php
<?php
use function ZealPHP\response_set_status;

$override = function ($response) {
    $response->write('BAD REQUEST');
    response_set_status(400);
};
```

## Built-in Helpers

When the closure runs, `$this` refers to `ZealPHP\ZealAPI`, which extends `REST`. Key methods:

| Method | Description | Example |
|--------|-------------|---------|
| `$this->json(array $data)` | Serialises data to JSON. Typically paired with `$this->response()`. | `echo $this->json(['status' => 'ok']);` |
| `$this->response(string $body, int $status)` | Sets headers and writes the response with a specific status code. | `$this->response($this->json($payload), 201);` |
| `$this->paramsExists(array $keys)` | Verifies the presence of query or form parameters; uses cleaned inputs. | `if (!$this->paramsExists(['id'])) { ... }` |
| `$this->die(\Throwable $e)` | Standardised exception handler that logs and returns an error payload. | `throw new \RuntimeException('Unauthorized');` |
| `$this->_request` | Sanitised, merged inputs populated by `REST::inputs()` — GET/POST params stripped of HTML tags (or PUT payload parsed from `php://input`). This is the safe, cleaned view of request data. | `$title = $this->_request['title'] ?? null;` |
| `$this->request` | The `ZealPHP\HTTP\Request` wrapper injected at construction — gives access to the raw OpenSwoole request and the full PSR-7 surface. | `$raw = $this->request->parent->rawContent();` |
| `$this->_response` | The `ZealPHP\HTTP\Response` wrapper injected at construction — use for low-level response control (status, headers, streaming). | `$this->_response->status(201);` |

Additional convenience:

- `$this->cwd` – Absolute path to the project root; useful for reading files safely within the API context.
- `$g = ZealPHP\G::instance()` – Access virtualised superglobals for advanced manipulations (e.g., sharing data with other parts of the request).

## Return Values and Response Control

API closures can respond in multiple ways:

1. **Return PSR response**:
   ```php
   use OpenSwoole\Core\Psr\Response;

   $psr = function () {
       return (new Response('PSR Hello'))->withStatus(205);
   };
   ```
   ZealPHP bypasses buffering and emits the response directly.

2. **Return scalar / array**:
   - `int`: overrides HTTP status code.
   - `array|object`: automatically JSON-encoded with `Content-Type: application/json`.
   - `string`: appended to the buffered body.

3. **Echo / print**:
   Output is buffered and sent after the closure completes. This is useful for streaming templates or logging debug information.

4. **Use `$response` wrapper**:
   Call `$response->json()` or `$response->status()` to influence the underlying OpenSwoole response object.

## Accessing Request Data

`REST::inputs()` populates `$this->_request` with sanitised values:

- `GET` and `POST` parameters are merged and stripped of HTML tags.
- `PUT` payloads are parsed via `php://input`.
- Unrecognised methods return `406 Not Acceptable`.

For raw access:

```php
$data = $this->request->parent->rawContent(); // actual OpenSwoole Request
$serverVars = ZealPHP\G::instance()->server;  // virtualised $_SERVER
```

## Scoping middleware to API namespaces & endpoints

ZealAPI files are not individually registered routes — every `/api/*` request funnels through ZealAPI's dynamic file resolver. So there is **no separate "api middleware" system**: an `api/admin/users/delete.php` endpoint is reached by the URL `/api/admin/users/delete`, which flows through the same stack as every other request. You scope middleware by **path** with [`App::when()`](middleware-and-authentication.md), and it covers the api layer for free:

```php
App::middlewareAlias('auth', fn() => new BasicAuthMiddleware($verifier));

App::when('/api/admin',  ['auth', 'admin-only']);   // every api/admin/*.php endpoint
App::when('/api/admin/users/delete', ['audit']);    // a single api endpoint
App::when('/api',        ['request-id']);           // the whole /api/* surface
```

`when()` matches a literal path **prefix** on segment boundaries (`/api/admin` matches `/api/admin/x` but not `/api/administrators`), or a **PCRE** if the string starts with `#`. Scopes compose in **registration order — first registered is outermost**; the chain wraps route matching + dispatch, runs *after* CORS/OPTIONS handling (so a guard never blocks a preflight), and short-circuits if a middleware returns without calling the handler.

For a guard that belongs to **one file**, declare it inline — read like `$get`/`$post`, it runs **innermost** (after any `App::when` scope, closest to the handler):

```php
// api/admin/users/delete.php
$middleware = ['confirm-token'];     // runs after App::when('/api/admin')['auth']

$delete = function () {
    return ['deleted' => true];
};
```

Per-request order for an api endpoint: global `addMiddleware` → `App::when` scopes → the file's in-file `$middleware` → handler. Middleware references the same [`App::middlewareAlias()`](middleware-and-authentication.md) registry as routes. See the [middleware guide](middleware-and-authentication.md) for the full model and the coroutine-safety status.

## Authentication and Authorisation

APIs commonly apply authentication middleware (see [middleware-and-authentication.md](middleware-and-authentication.md)) — most often a path-scoped `App::when('/api', ['auth'])` (above) over the whole surface or a subtree. You can also register an explicit route above the implicit ones for a bespoke flow:

```php
$app->nsRoute('api', '/secure/{module}/{action}', function ($module, $action) {
    // custom auth before delegating to ZealAPI
});
```

Inside a closure, leverage sessions or tokens:

```php
use ZealPHP\G;

$profile = function () {
    $session = G::instance()->session;
    if (empty($session['user_id'])) {
        $this->response($this->json(['error' => 'Unauthorized']), 403);
        return;
    }
    return ['user_id' => $session['user_id']];
};
```

## Authentication hooks (v0.2.25)

Issue #13 introduced three optional callbacks on `App` that ZealAPI consults to answer "who is the current user?". They replace the pre-v0.2.25 hardcoded `return false;` stub on `isAuthenticated()` that 403'd every endpoint guarded by `requirePostAuth()`.

ZealPHP deliberately ships no default checker — the framework doesn't know about your auth system. Wire the callbacks once during boot (in `app.php` for a single app, or in your platform wrapper's bootstrap — labs / Symfony bundle / etc. — so downstream apps inherit the answers without per-app glue).

### The three callbacks

Each follows the getter/setter shape established by `App::superglobals()`: no-arg returns the current callable (or null), one-arg installs it. Pass `null` to clear and restore the default.

| Setter | Callback signature | Default | Consumed by |
|--------|--------------------|---------|-------------|
| `App::authChecker(?callable $fn = null): ?callable` | `fn(): bool` | `null` &rarr; `false` (fail-closed) | `$this->isAuthenticated()` |
| `App::adminChecker(?callable $fn = null): ?callable` | `fn(): bool` | `null` &rarr; `false` | `$this->isAdmin()` |
| `App::usernameProvider(?callable $fn = null): ?callable` | `fn(): ?string` | `null` &rarr; `null` | `$this->getUsername()` |

Per-callback registration (each is independent — admins and identity don't have to be wired together):

```php
use ZealPHP\App;

App::authChecker(fn(): bool => isset($_SESSION['user_id']));
App::adminChecker(fn(): bool => ($_SESSION['role'] ?? null) === 'admin');
App::usernameProvider(fn(): ?string => $_SESSION['username'] ?? null);
```

Return-value coercion:
- `isAuthenticated()` casts the checker's return to `bool` — apps may return any truthy value (a user object, a non-empty session id) without ceremony.
- `getUsername()` returns the provider's string verbatim (including `''`), but coerces any non-string (`null`, `false`, an int) to `null`.

### The four consumed methods

Available as `$this->X()` inside any ZealAPI handler closure (they live on `ZealAPI`, which extends `REST`):

| Method | Returns | Behaviour |
|--------|---------|-----------|
| `$this->isAuthenticated(): bool` | `bool` | Calls the registered `authChecker`. Returns `false` when none is registered. |
| `$this->isAdmin(): bool` | `bool` | Calls the registered `adminChecker`. Returns `false` when none is registered. Independent of `isAuthenticated()` — an app may be authenticated without being admin. |
| `$this->getUsername(): ?string` | `?string` | Calls the registered `usernameProvider`. Returns `null` when none is registered, or when the provider returns a non-string. |
| `$this->requirePostAuth(): bool` | `bool` | Composite guard. Returns `false` and emits a `403 {"error":"Unauthorized"}` JSON response when either `REQUEST_METHOD !== "POST"` or `isAuthenticated()` is false. Returns `true` only when both conditions hold. Use it as a one-line gate at the top of mutating endpoints. |

### End-to-end example

Wire the callbacks once during boot, then any API handler can branch on identity:

```php
// app.php — bootstrap (runs once at startup)
use ZealPHP\App;

App::authChecker(fn(): bool => !empty($_SESSION['user_id']));
App::adminChecker(fn(): bool => ($_SESSION['role'] ?? null) === 'admin');
App::usernameProvider(fn(): ?string => $_SESSION['username'] ?? null);

App::init();
$app = new App();
$app->run();
```

```php
<?php
// api/posts/create.php
$create = function ($app) {
    if (!$app->requirePostAuth()) {
        return; // 403 already emitted; short-circuit
    }

    $author = $app->getUsername() ?? 'anonymous';
    $payload = $app->_request;

    return [
        'created_by' => $author,
        'is_admin'   => $app->isAdmin(),
        'title'      => $payload['title'] ?? null,
    ];
};
```

### Hooks vs middleware

Both layers are valid; they answer different questions.

| Layer | Question it answers | When to use |
|-------|---------------------|-------------|
| Auth middleware | "Should this request reach the handler at all?" | Deny-all gates — blanket 401 / 403 for the entire `/api/*` surface or a subtree. Runs before the handler and can short-circuit. |
| Auth hooks (this section) | "Inside a handler, who is the caller?" | Per-handler identity-aware logic — e.g., `if ($app->isAdmin()) { …include hidden fields… }`, audit logging by `$app->getUsername()`, soft-deny on writes via `requirePostAuth()`. |

The two compose freely: a middleware can enforce "must be authenticated to reach this URL," and the handler still uses the hooks to discover *which* authenticated user it's serving.

## Task Workers and Coroutines from APIs

APIs can trigger asynchronous work without blocking the request thread:

- Dispatch a task: see `api/swoole/task.php` for serialising `OpenSwoole\Core\Psr\Response` objects returned by `task/backup.php`.
- Run coroutines: use `go()` or `co::run()` when superglobals are disabled (`App::superglobals(false)`), or call `coproc()` to spawn a background process that can block without affecting the main request when superglobals are enabled.

## Error Handling

Wrap risky code in try/catch and delegate to `$this->die($exception)` for consistent logging and error payloads. The helper maps common exception messages to HTTP status codes (400, 403, 404) and responds with a JSON body.

### Example: End-to-end Resource

```php
<?php
// File: api/device/check.php
use ZealPHP\G;

$check = function (string $serial, $request, $response) {
    if (!$this->paramsExists(['serial'])) {
        return $this->response($this->json(['error' => 'missing_serial']), 422);
    }

    $serial = $request->get['serial'] ?? $serial;
    $db = device_repository(); // your abstraction
    $exists = $db->exists($serial);

    return [
        'serial' => $serial,
        'exists' => $exists,
        'request_id' => G::instance()->session['UNIQUE_REQUEST_ID'] ?? null,
    ];
};
```

This example demonstrates parameter validation, access to both cleaned and raw request data, and returning a structured payload that ZealPHP encodes automatically.
