# Routing

ZealPHP blends implicit routing (public directory and APIs) with programmable routes that you can register from any PHP file. This document explains each routing primitive, the execution order, and best practices for structuring route definitions.

## Implicit Routes

Implicit routes are registered by `App::run()` after all custom route files have been included:

- **Public directory** – Requests map to files under `public/`. Examples:
  - `/` → `public/index.php`
  - `/about` → `public/about.php`
  - `/blog/post-1` → `public/blog/post-1.php` (falls back to `public/blog/post-1/index.php` when a directory exists)
  - `.php` suffixes are optional; ZealPHP drops them automatically.
- **API namespace** – Requests under `/api/*` map to files inside `api/`. For example, `/api/device/list` includes `api/device/list.php`, binds the exported closure, and executes it via `ZealAPI`.
- **.php guard** – By default, requests that explicitly target `.php` files (e.g., `/secret.php`) return 403. Set `App::$ignore_php_ext = false` if you need to serve raw PHP files directly.

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

### `route(string $path, array|callable $options, ?callable $handler = null)`

- Path placeholders use `{name}` syntax; captured parameters are injected into the handler by name.
- Options accept an array with `methods => ['GET', 'POST', ...]`. Defaults to `GET`.
- Return values:
  - `int`: response status code
  - `ResponseInterface`: emitted as-is
  - `array|object`: serialised to JSON
  - anything else: echoed output from the handler buffer

```php
$app->route('/hello/{name}', function (string $name) {
    echo "Hi {$name}";
});
```

### `nsRoute(string $namespace, string $path, array|callable $options, ?callable $handler = null)`

Prefixes routes with a static namespace segment. Useful for administrative or versioned areas.

```php
$app->nsRoute('admin', '/dashboard', ['methods' => ['GET']], function () {
    return App::render('admin/dashboard');
});
// Resolves to /admin/dashboard
```

### `nsPathRoute(string $namespace, string $path)`

Allows deeply nested placeholders while keeping a namespace prefix. ZealPHP uses this internally to wire `/api/{module}/{action}`.

```php
$app->nsPathRoute('reports', '{year}/{month}', function ($year, $month) {
    // /reports/2024/03
});
```

### `patternRoute(string $regex, array|callable $options, ?callable $handler = null)`

Registers a route using a PCRE `pattern`. Named capture groups become handler parameters.

```php
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function ($rest) {
    echo "You requested: {$rest}";
});
```

Pattern routes are powerful but should be used sparingly—prefer `route()` and `nsRoute()` for readability.

## Accessing Request Context

Handlers can declare special parameters to access framework objects:

- `$request` – `ZealPHP\HTTP\Request` wrapper
- `$response` – `ZealPHP\HTTP\Response` wrapper
- `$app` – the current `ZealPHP\App` instance
- `$server` – the underlying `OpenSwoole\HTTP\Server`

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
