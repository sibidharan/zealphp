# Templates and Rendering

ZealPHP renders HTML on the server with plain PHP — no Blade, no Twig, no compile step. Templates live under `template/`, public-served files live under `public/`, and the framework exposes a small **file-execution family** of methods that runs PHP files through a single private core (`App::executeFile()`) and applies the [universal return contract](#universal-return-contract).

This guide covers every member of that family, the htmx-style fragment pattern, and SSR streaming from templates.

## The file-execution family — five ways to run a PHP file

The first four methods share a single private core (`App::executeFile()`) that runs the file, captures output, and applies the universal return contract. They differ only in (a) where the path is resolved from and (b) what the wrapper does with the result. The fifth — `App::fragment()` — runs *inside* a template and marks a named region the framework can extract by name.

| Method | Path resolved from | Returns | Use when |
|--------|--------------------|---------|----------|
| `App::render($tpl, $args)` | `template/` (with `.php` suffix) | `mixed` — full return contract. **BC:** templates with no explicit `return` have their captured output echoed back. | Direct output in a route handler or inside another template |
| `App::renderToString($tpl, $args)` | `template/` | `string` — coerces every shape (Generator consumed, Closure invoked, scalar cast) | Need HTML as a value: email body, cache entry, pass into another renderer |
| `App::renderStream($tpl, $args)` | `template/` | `\Generator` — yields whatever the template returned, chunk-by-chunk | SSR streaming; works with echo templates AND streaming-Closure templates uniformly |
| `App::include($publicPath, $args = [])` | `public/` (Apache document-root convention — leading `/` optional) | `mixed` — full return contract, never echoed. Auto-populates `$_SERVER['PHP_SELF']`, `SCRIPT_NAME`, `SCRIPT_FILENAME`. | Apache-style rewrites — serve `public/new.php` from a different route URL in-process |
| `App::fragment($name, $fn)` *(v0.2.24)* | N/A — called *inside* a template | `void`. The closure's return rides the full return contract when extracted. | htmx-style template-fragment pattern — one file, two responses |

`App::includeFile($path)` is a **deprecated** alias for `App::include()` retained for the WordPress showcase and existing scaffolds. New code should use `App::include()`.

## `App::render()` — render a template

```php
public static function render(
    string $__template_file = 'index',
    array $__args = [],
    string $__default_template_dir = 'template'
): mixed
```

Paths resolve relative to `template/`. A leading `/` makes the path absolute from `template/`; otherwise the current public file's basename auto-namespaces the lookup (e.g. `App::render('header')` from `public/users.php` looks for `template/users/header.php` first, then `template/header.php`).

Templates receive each key of `$__args` as a local variable via `extract()`. No magic syntax — just PHP.

```php
$app->route('/users/{id}', function($id) {
    $user = User::find($id);
    if (!$user) return 404;

    return App::render('profile', [
        'user'    => $user,
        'posts'   => $user->posts(),
        'isAdmin' => $user->role === 'admin',
    ]);
});
```

**Backwards-compatibility behaviour.** Templates that only echo (no explicit `return`) have their captured output echoed back from `App::render()` — this keeps every existing `App::render('_master', ...)` call site in `public/*.php` working unchanged. Explicit non-string returns flow through to the caller so a route handler can `return App::render(...)` and have the universal contract apply at the response boundary.

### Path resolution

| Call | Resolves to | When |
|------|-------------|------|
| `App::render('home')` | `template/home.php` | Top-level template |
| `App::render('/components/_card')` | `template/components/_card.php` | Leading `/` = absolute from `template/` |
| `App::render('header')` from `public/users.php` | `template/users/header.php` | Auto-namespaces by current public file |
| `App::render('header')` (fallback) | `template/header.php` | If the namespaced path does not exist |

## `App::renderToString()` — render to a string

```php
public static function renderToString(
    string $__template_file = 'index',
    array $__args = [],
    string $__default_template_dir = 'template'
): string
```

Same path resolution as `App::render()`, but the result is coerced to a string regardless of what the template returned: Generators are consumed and concatenated, Closures are invoked with parameter injection, arrays/objects are JSON-encoded, scalars are cast.

```php
$body = App::renderToString('emails/welcome', [
    'user' => $user,
    'url'  => $verifyUrl,
]);

mail($user->email, 'Welcome', $body);
```

## `App::renderStream()` — render as a Generator

```php
public static function renderStream(
    string $__template_file = 'index',
    array $__args = [],
    string $__default_template_dir = 'template'
): \Generator
```

Returns a Generator that yields whatever the template returned, chunk by chunk. Compose multiple template streams in a route handler with `yield from`:

```php
$app->route('/users', function() {
    return (function() {
        yield from App::renderStream('shell-open', ['title' => 'Users']);
        yield from App::renderStream('users/stream', ['users' => User::all()]);
        yield from App::renderStream('shell-close');
    })();
});
```

### Three streaming template styles

`renderStream()` accepts three template shapes — all three compose in the same `yield from` pipeline.

| Style | Template code | Best for |
|-------|---------------|----------|
| Closure with param injection (cleanest) | `return function($users) { yield ...; };` | New streaming templates — framework injects `$users` from `$args` by name, no `use()` needed |
| IIFE Generator | `return (function() use ($users) { yield ...; })();` | When you need variables from the include scope via `use()` |
| Regular echo template | `<h1><?= $title ?></h1>` | Non-streaming templates — output captured and yielded as one chunk |

Example streaming template (`template/users/stream.php`):

```php
<?php
return function($users) {
    yield "<section class='users'>";
    foreach ($users as $user) {
        yield "<div class='card'>"
            . htmlspecialchars($user->name)
            . "</div>\n";
    }
    yield "</section>";
};
```

The framework injects `$users` from the args array by name, exactly like route parameter injection — and, exactly like route handlers, `$req` / `$res` are accepted as short aliases for `$request` / `$response` when those are present in the args array. Each `yield` flushes to the browser immediately.

## `App::include()` — run a `public/` file through the framework

```php
public static function include(string $publicPath, array $args = []): mixed
```

Resolves `$publicPath` relative to `public/` (Apache document-root convention — leading `/` optional, so `'/about.php'` and `'about.php'` both work). Auto-populates `$_SERVER['PHP_SELF']`, `SCRIPT_NAME`, and `SCRIPT_FILENAME` for the included file (Apache mod_php parity). Applies `includeCheck()` containment so a traversal attempt outside the document root is refused with HTTP 403 via the universal return contract.

In coroutine mode the file runs in-process via the shared `executeFile()` core. When `processIsolation()` is on (the default for legacy-app modes), it dispatches to a pre-spawned subprocess pool (`cgiMode('pool')`, ~1–3 ms warm) for global-scope isolation — the file's return value still flows back through the universal return contract via the metadata channel. Use `App::cgiMode('proc')` to switch to a fresh `proc_open` subprocess per request (~30–50 ms cold) when you need fully-isolated process state on every call.

```php
// Apache-style rewrite — serve public/new.php from /old-page in-process
$app->route('/old-page', fn() => App::include('/new.php'));

// Pass arguments to the included file (coroutine mode only)
$app->route('/render/{slug}', fn($slug) => App::include('/article.php', [
    'slug' => $slug,
]));
```

`App::tryInclude($publicPath)` is a variant that returns `null` instead of `403` when the file is missing — useful for chaining extension-resolver patterns without conflating "not found" with "security violation".

## `App::includeFile()` — deprecated alias

```php
public static function includeFile(string $path): mixed   // @deprecated since 0.2.18
```

Kept for backward compatibility with the WordPress showcase and existing user scaffolds. Accepts an absolute path. For paths under the document root, delegates to `App::include()` (security check + `$_SERVER` preamble apply). For paths outside (test fixtures, embedded utilities) the call passes straight to the shared core so the return contract still applies but no security gate fires — matching historical behaviour. **New code should call `App::include()` with a public-relative path instead.**

## `App::fragment()` — htmx template fragments *(v0.2.24)*

```php
public static function fragment(string $name, callable $fn): void
```

`App::fragment()` turns any template into a dual-mode file: the same `App::render('page', $args)` call serves **either** the complete page (no fragment selector → every `App::fragment()` block runs inline) **or** just one named region (`$args['fragment'] = 'name'` → that region's buffer is cleared, only its closure runs, and the rest of the template short-circuits via `HaltException`). Same template, same route handler, two different responses on the same URL — the [htmx-essay template-fragment](https://htmx.org/essays/template-fragments/) pattern without separate partial files.

```php
// template/contacts/list.php — one template, both responses
<ul id="contacts">
<?php foreach ($contacts as $c): ?>
  <?php App::fragment("contact-{$c['id']}", function() use ($c) { ?>
    <li id="contact-<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></li>
  <?php }); ?>
<?php endforeach; ?>
</ul>
```

```php
// Route handler — ONE entry, both modes
$app->route('/contacts', function($g) {
    return App::render('contacts/list', [
        'contacts' => Contact::all(),
        // No selector → full <ul> with every row inline.
        // ?fragment=contact-2 → just that one <li> on the wire.
        'fragment' => is_string($g->get['fragment'] ?? null) ? $g->get['fragment'] : null,
    ]);
});
```

Inside the closure, the universal contract applies — `return 404;` for auth, `return ['id' => 1];` for JSON, `return (fn() => yield ...)();` for streaming.

### Three behaviours worth knowing

- **Missing fragment → 404** per the universal return contract. Asking for `?fragment=does-not-exist` does not silently fall back to the full page.
- **First match wins** when the same name appears twice — the first block extracts, the rest of the template short-circuits.
- **Nested renders compose** — an `App::render()` called from inside a fragment closure does *not* inherit the parent's fragment selector. Each render's scope is saved + restored in `$g->memo['_fragment']`.

## Universal return contract

One contract, every entry point. Route handler, fallback, error handler, `App::render() / renderToString() / renderStream() / include()`, public file, API closure, streaming-template Closure — every one rides the same return-shape mapping. The shared private core that implements this is `App::executeFile()`.

| The handler / file does | Core sees | `ResponseMiddleware` emits |
|-------------------------|-----------|----------------------------|
| `echo "html"; // no explicit return` | `"html"` (buffered) | 200 + HTML body |
| `return 404;` | `404` (int) | 404 status, empty body |
| `return ['ok' => true];` | `['ok' => true]` (array) | 200 + JSON (`Content-Type: application/json`) |
| `return "explicit html";` | `"explicit html"` (string) | HTML body |
| `echo "shell"; return "body";` | `"shellbody"` (concatenated) | HTML body (wire order preserved) |
| `return (function() { yield ...; })();` | `\Generator` | SSR stream — each `yield` flushed |
| `return function($req) { yield ...; };` | `\Closure` (param-injected when invoked) | SSR stream after invocation |
| `echo "header"; return (function() { yield ...; })();` | `\Generator` wrapping `"header"` + delegated yields | Streamed in source order |
| `return new Response($body, 200);` | `ResponseInterface` | PSR-7 response used directly (output buffer ignored) |

**Valid HTTP status codes.** When the contract says `int = HTTP status`, the int must be in the range **100–599** (RFC 7230). Codes outside that range are coerced to 500 with a warning logged via `elog()`. `return 1;` is the one special case — PHP's `include` returns `1` when a file has no explicit `return`, so the framework treats it as "no explicit return" inside `App::include() / render() / renderToString() / renderStream()` and surfaces the buffered echo as the response body. If you want HTTP 1 specifically, return `100` instead.

## Yield from everywhere

Generators work in route handlers, public files, API handlers, and template files — anything that runs through the universal return contract.

| Location | How to stream | Example |
|----------|---------------|---------|
| Route handler | Return a Generator directly | `return (function() { yield "chunk"; })();` |
| Public file | Return a Generator from the file | `public/feed.php` → `<?php return (function() { yield "..."; })();` |
| API handler | Return a Generator from `$get`/`$post` | `$get = function() { return (function() { yield ...; })(); };` |
| Template (via `renderStream()`) | Return a Closure or Generator | `return function($items) { yield ...; };` |
| File dispatched via `App::include()` | Same — file's `return` flows through the contract | `return (function() { yield ...; })();` |

```php
// public/feed.php — a streaming public page
<?php
use ZealPHP\App;

return (function() {
    yield App::renderToString('shell-open', ['title' => 'Live Feed']);
    yield "<h1>Feed</h1>";
    foreach (fetchFeedItems() as $item) {
        yield "<article>{$item->title}</article>\n";
    }
    yield App::renderToString('shell-close');
})();
```

`$g->_streaming = true` is set by `stream()` / `sse()` so `ResponseMiddleware` knows to skip `ob_get_clean()`.

## Layouts and composition

Components render other components. Build a layout system with one master layout composing smaller components — no template-inheritance syntax, just PHP includes.

```php
// public/about.php — page entry (3 lines)
<?php use ZealPHP\App;
App::render('_master', ['title' => 'About Us', 'page' => 'about']);
```

```php
// template/_master.php — layout wrapper
<!doctype html>
<html>
<head><title><?= htmlspecialchars($title) ?></title></head>
<body>
  <?php App::render('_nav', ['active' => $page]) ?>
  <main>
    <?php App::render("/pages/$page") ?>
  </main>
  <?php App::render('_footer') ?>
</body>
</html>
```

This is exactly how the ZealPHP docs site works — every page in `public/` is 3 lines calling `App::render('_master', [...])`. The master renders the nav, the page content, and the footer.

## Editor / IDE: making injected variables visible

`App::render('page', ['title' => …])` injects template variables via `extract()` at runtime. Static analyzers (VSCode's PHP extension / Intelephense, PHPStan) **cannot see through `extract()`**, so they flag `$title` as an *undefined variable* even though it's passed and works. Three ways to fix it — pick per template:

**1. Return a closure with typed params — cleanest, no docblocks.** ZealPHP injects a returned closure's parameters *by name* (`resolveClosureParams`), so the variables become ordinary function parameters the IDE fully understands (types, autocomplete, no warnings):

```php
<?php
// template/pages/home.php
return function (string $title, array $users, \ZealPHP\RequestContext $g): string {
    $rows = '';
    foreach ($users as $u) { $rows .= '<li>' . htmlspecialchars($u['name']) . '</li>'; }
    return '<h1>' . htmlspecialchars($title) . "</h1><ul>{$rows}</ul>";
};
```
Works for both regular and streaming (`yield`) templates. This sidesteps `extract()` entirely.

**2. `@var` docblocks — low-friction retrofit for echo-style templates.** Keep the `<?php … echo $title; ?>` style; just declare the injected vars up top:

```php
<?php
/** @var string                  $title */
/** @var \ZealPHP\RequestContext  $g     */
?>
<h1><?= htmlspecialchars($title) ?></h1>
```
Precise, and it keeps the undefined-variable check working for genuine typos. (The scaffold's templates use this form as the worked example.)

**3. Typed view-model object** — pass one typed object and access properties (`App::render('home', ['vm' => new HomeView(...)])` → `$vm->title`); best for large pages.

**Blunt fallback:** `{"intelephense.diagnostics.undefinedVariables": false}` in `.vscode/settings.json` silences it project-wide — but it also hides real undefined-variable bugs, so prefer 1–3. There is no `extract()`-stub trick: a static analyzer fundamentally can't know an arbitrary runtime array's keys, so the fix is always to make the variable *not* come from `extract()` (1, 3) or to *declare* it (2).

## Tips for template authors

- **Always escape user data** with `htmlspecialchars()`. PHP templates have no auto-escaping — full control, full responsibility.
- Keep templates free of business logic. Transform data in route handlers or service classes and pass simple view models to `App::render()`.
- Use the route-handler / public-file / API-closure layers for logic; templates are view-only.
- Pair templates with CSS/JS assets in `public/` — ZealPHP does not prescribe an asset pipeline.
- For coroutine-enabled deployments (`App::superglobals(false)`), prefer `App::renderStream()` over blocking renders when the data source is itself async.
