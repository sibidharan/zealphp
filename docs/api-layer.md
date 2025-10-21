# API Layer

ZealPHP exposes a lightweight convention for building HTTP APIs while preserving the familiar ergonomics of file-based PHP. Each endpoint lives in `api/<module>/<action>.php` (module optional) and exports a closure whose name matches the file base name. `ZealAPI` discovers and binds these closures at runtime, injecting useful helpers and enforcing PSR-compatible responses.

## File Structure and Naming

- `api/<name>.php` &rarr; `/api/<name>`
- `api/<module>/<action>.php` &rarr; `/api/<module>/<action>`
- The file must assign a closure to a variable named after the file:

```php
<?php
// File: api/device/list.php

$list = function () {
    return $this->json(['devices' => []]);
};
```

`ZealAPI::processApi()` includes the file, binds `$list` to the API object (`$this`), and executes it. If the variable is missing or not callable, ZealPHP responds with `404 method_not_found`.

## Handler Signature

ZealPHP inspects the closure signature and injects arguments by name. Supported parameters:

- **Route placeholders** – e.g., `{id}` maps to `$id`.
- **Framework objects**:
  - `$app` – current `ZealPHP\ZealAPI` instance
  - `$request` – PSR-7 request wrapper (`ZealPHP\HTTP\Request`)
  - `$response` – PSR-7 response wrapper (`ZealPHP\HTTP\Response`)
  - `$server` – underlying `OpenSwoole\HTTP\Server`

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
| `$this->_request` / `$this->_response` | Raw request/response references saved by `REST`. | `log_request($this->_request);` |
| `$this->request` / `$this->_response` | Request and response injected via the constructor, accessible for advanced use cases. | `$this->request->parent->server` |

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

## Authentication and Authorisation

APIs commonly apply authentication middleware (see [middleware-and-authentication.md](middleware-and-authentication.md)). Because ZealPHP routes `/api/*` through `nsPathRoute`, you can register targeted middleware or explicit routes above the implicit ones:

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

## Task Workers and Coroutines from APIs

APIs can trigger asynchronous work without blocking the request thread:

- Dispatch a task: see `api/swoole/task.php` for serialising `OpenSwoole\Core\Psr\Response` objects returned by `task/backup.php`.
- Run coroutines: use `go()` or `co::run()` when superglobals are disabled (`App::superglobals(false)`), or call `coproc()` / `prefork_request_handler()` to isolate blocking logic when superglobals are enabled.

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
