# Templates and Rendering

ZealPHP promotes server-driven HTML rendering with a lightweight templating system built on native PHP. Templates live under `template/` and are loaded via `App::render()`. This guide explains the folder conventions, how partials are resolved, and how to leverage streaming to build responsive UIs.

## Template Roots

`App::render($template, array $data = [], ?string $directory = null)` looks for templates relative to the current route unless a directory is specified.

- Default directory: `template/home/`
- Common files:
  - `_master.php` – Layout wrapper
  - `_head.php` – Document head with metadata
  - `_style.php` – Inline styles or `<link>` references
  - `content.php` – Primary content block

Example layout (`template/home/_master.php`):

```php
<?php use ZealPHP\App; ?>
<!DOCTYPE html>
<html lang="en">
<?php App::render('_head', ['title' => $title]); ?>
<body>
    <header>
        <h1><?= $title ?></h1>
        <p><?= $description ?></p>
    </header>
    <?php App::render('content'); ?>
    <?php App::render('_footer'); ?>
</body>
</html>
```

## Rendering from Routes

Routes and APIs can call `App::render()` to compose HTML fragments:

```php
$app->route('/landing', function () {
    return App::render('_master', [
        'title' => 'Welcome to ZealPHP',
        'description' => 'Dynamic streaming powered by OpenSwoole',
    ]);
});
```

When `App::render()` is executed, ZealPHP:

1. Determines the template directory (`template/<current-route>/` or the provided directory).
2. Builds the absolute path and ensures it is inside the project root to prevent directory traversal.
3. Extracts `$data` into local variables and includes the PHP file.

If the template does not exist, `TemplateUnavailableException` is thrown with a helpful message containing the caller file and line number.

## Streaming and Prefork Execution

When ZealPHP runs in superglobals mode, implicit routes use `prefork_request_handler()` to isolate template rendering. This approach:

- Prevents partial responses from leaking when templates throw exceptions.
- Lets each template emit headers and cookies safely via `header()` and `setcookie()` (overridden by ZealPHP).
- Ensures the parent process receives the final HTML once rendering completes.

You can opt into the same behaviour in custom routes:

```php
use function ZealPHP\prefork_request_handler;

$app->route('/reports', function () {
    echo prefork_request_handler(function () {
        App::render('reports/index');
    });
});
```

## Accessing the Current Template

`App::getCurrentFile()` returns the name of the currently executing public script or the file passed into the helper. This is useful for relative template includes or conditional logic based on the active page.

```php
$file = App::getCurrentFile(); // e.g., "home"
App::render("{$file}/content");
```

## Working with Public Directory

Pages served from `public/` can include templates directly:

```php
<?php
use ZealPHP\App;

App::render('_master', [
    'title' => 'Docs',
    'description' => 'Comprehensive ZealPHP documentation',
]);
```

Because implicit routes buffer output, you can mix template rendering with streamed fragments (for example, `flush()` partial results or echo placeholders while coroutines process data).

## Tips for Template Authors

- Use short open tags (`<?`) consistently; the repository enables `short_open_tag=on`.
- Keep templates free of business logic. Transform data in route handlers or service classes and pass simple view models to `App::render()`.
- Embrace layout partials (`_head.php`, `_footer.php`) to share common markup.
- Pair templates with CSS/JS assets in `public/` or load them via CDN—ZealPHP does not prescribe an asset pipeline.
- For coroutine-enabled deployments (`App::superglobals(false)`), ensure any blocking operations are executed via `go()` or `prefork_request_handler()` before rendering to avoid stalling the event loop.
