# Templates and Rendering

ZealPHP renders HTML on the server with plain PHP — no Blade, no Twig, no compile step. Templates live under `template/`, public-served files live under `public/`, and rendering comes down to two methods:

- **`App::render()`** renders a page or a response. Options select a fragment, make the render htmx-aware, or return a stream.
- **`App::component()`** returns markup as a value and never echoes. Use it to compose: a card inside a page, an htmx partial, an email body.

Both run the template through one private core (`App::executeFile()`) and apply the [universal return contract](#universal-return-contract). Two more methods complete the set: `App::include()` runs a file from `public/`, and `App::fragment()` marks a named region inside a template.

This guide covers each of them, the htmx-style fragment pattern, and SSR streaming from templates.

## The rendering methods

| Method | Path resolved from | Returns | Use when |
|--------|--------------------|---------|----------|
| `App::render($tpl, $args, …options)` | `template/` (with `.php` suffix) | `mixed` — full return contract. A string result is also echoed (`echo: true`, the default), so `App::render('_master', …)` statements in `public/*.php` keep working. | A page, or a route's response. Options: `fragment:`, `htmx:`, `fullPage:`, `stream:`, `echo:` |
| `App::component($name, $props)` | `template/components/` for a name (`'card'`); `template/` for a leading `/` (`'/emails/welcome'`) | `ZealPHP\SafeHtml` — markup, never echoed. Cast with `(string)` where a plain string is required. | Markup inside a page, an htmx partial, an email body, anything you compose |
| `App::include($publicPath, $args = [])` | `public/` (Apache document-root convention — leading `/` optional) | `mixed` — full return contract, never echoed. Auto-populates `$_SERVER['PHP_SELF']`, `SCRIPT_NAME`, `SCRIPT_FILENAME`. | Apache-style rewrites — serve `public/new.php` from a different route URL in-process |
| `App::fragment($name, $fn)` *(v0.2.24)* | N/A — called *inside* a template | `void`. The closure's return rides the full return contract when extracted. | htmx-style template-fragment pattern — one file, two responses |

`App::includeFile($path)` is a **deprecated** alias for `App::include()` retained for the WordPress showcase and existing scaffolds. New code should use `App::include()`.

### Deprecated helpers

Earlier releases had a separate method for each way of rendering. They still work until 1.0, but new code should use the two methods above.

| Deprecated | Use instead |
|------------|-------------|
| `App::renderToString($tpl, $args)` | `App::component('/tpl', $args)` — write the path absolute from `template/`, and add `(string)` where a plain string is required |
| `App::renderStream($tpl, $args)` | `App::render($tpl, $args, stream: true)` |
| `App::renderHtmx($tpl, $args, $fragmentName, $fullPageTemplate)` | `App::render($tpl, $args, htmx: true, fragment: $fragmentName, fullPage: $fullPageTemplate)` |

## `App::render()` — render a page or a response

```php
public static function render(
    string $__template_file = 'index',
    array $__args = [],
    string $__default_template_dir = 'template',
    ?string $fragment = null,
    bool $htmx = false,
    ?string $fullPage = null,
    bool $stream = false,
    bool $echo = true,
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

### Options

| Option | What it does |
|--------|--------------|
| `fragment: 'name'` | Renders only that `App::fragment()` region (see below). A name with no matching region is a 404. |
| `htmx: true` | Renders a fragment for htmx requests and the full page otherwise. The region is `fragment:`, else derived from `HX-Target` / `HX-Trigger-Name`. Sends `Vary: HX-Request, HX-Target, HX-Trigger-Name`. See the [htmx guide](htmx.md). |
| `fullPage: 'tpl'` | With `htmx: true` only: the first template is a bare partial for htmx requests, and `fullPage:` is the page for normal requests. |
| `stream: true` | Returns a `\Generator` and never echoes. Compose several with `yield from`; see [Rendering as a stream](#rendering-as-a-stream--stream-true). |
| `echo: false` | Returns the result without echoing it. |

```php
App::render('_master', ['page' => 'home']);              // echo the page (public/*.php)
return App::render('users/show', ['user' => $u]);         // a route's response
return App::render('search', $args, htmx: true);          // fragment for htmx, page otherwise
return App::render('contacts', $args, fragment: 'row-7'); // one App::fragment() region
return App::render('feed', $args, stream: true);          // a Generator, flushed chunk by chunk
```

**Echo side effect.** When a template's result is a string — it only echoed, or it returned a string — `App::render()` both **echoes** that string and **returns** it. That keeps every `App::render('_master', ...)` statement in `public/*.php` working. Non-string results (an int status, an array, a Generator, a `ResponseInterface`) are returned without being echoed.

What that means in practice:

| Where you call it | What happens |
|---|---|
| `return App::render('page');` in a route handler | The body is sent once. A string return replaces anything echoed during the handler. |
| `App::render('page');` as a statement (in `public/*.php` or a template) | The output is echoed once, where the call sits. |
| `<?= App::render('child') ?>` inside a template | **The child appears twice** — once from `render()`'s echo, once from `<?=`. |
| `$html = App::render('child');` inside a template | **The child is echoed immediately**, and again wherever you echo `$html`. |
| `<?= App::component('/child') ?>` or `$html = App::component('/child');` | The child appears once. `component()` never echoes. |

To compose templates, use `<?= App::component(...) ?>`, or call `App::render()` as a statement. `App::render(..., stream: true)` with `yield from` is the streaming equivalent. The echo is kept for backward compatibility; `tests/Unit/RenderEchoSemanticsTest.php` pins each row.

### Path resolution

`render()` and `component()` resolve a name by the same rule:

| Name | Looks in, in order |
|------|--------------------|
| `'/emails/welcome'` | `template/emails/welcome.php` — a leading `/` is absolute from `template/` |
| `'header'` from `public/users.php` | nearest first: `template/users/header.php` (the page's folder), `template/users/components/header.php` (that page's components), `template/header.php`, `template/components/header.php` |

Keep a component used by one page in that page's `components/` folder; move it to `template/components/` when another page needs it — no call changes. In each place `name.php` comes before the partial `_name.php`, and `'ui/button'` names a folder. A missing template's error lists every place it looked. A registered reactive component's name wins over templates in `component()`.

## `App::component()` — markup as a value

```php
public static function component(string $component, array $props = [], ?string $key = null): SafeHtml
```

`App::component()` returns the rendered markup and never echoes it. The name resolves by the [same rule as `render()`](#path-resolution) (a registered reactive component's name wins). The props become template variables.

**A component follows the content half of the return contract only, by design.** It's a piece of someone else's response, so it can't set that response's status or turn it into JSON:

| The template… | `component()` gives |
|---|---|
| echoes, returns a string, `SafeHtml` or any `Stringable` | that markup (echo first, then the return, in order) |
| returns a Generator | its chunks, concatenated |
| returns a Closure | the Closure called with its parameters injected by name from the props, then the same rules |
| returns an `int` status, an array, a `ResponseInterface` or any other value | a `LogicException` naming what came back — return those from the route or page that owns the response |

When a component *is* the whole response (`return App::component(...)` from a route), the route owns the response and the full contract applies to that return: the `SafeHtml` is sent as HTML.

```php
<!-- in a template -->
<?php foreach ($features as $f): ?>
  <?= App::component('card', ['title' => $f['name'], 'body' => $f['desc']]) ?>
<?php endforeach; ?>
```

The result is a `ZealPHP\SafeHtml`. It works anywhere a string is concatenated or echoed, and a route can `return` it (the response is the HTML). Cast it with `(string)` where a plain string is required — `json_encode()`, `strlen()`, `preg_match()`, a `string` parameter:

```php
$body = (string) App::component('/emails/welcome', [
    'user' => $user,
    'url'  => $verifyUrl,
]);

mail($user->email, 'Welcome', $body);
```

Props, slots, folders and reactive components are covered in [Components](components.md).

## Rendering as a stream — `stream: true`

`App::render($tpl, $args, stream: true)` returns a Generator that yields whatever the template returned, chunk by chunk. It never echoes. Compose several template streams in a route handler with `yield from`:

```php
$app->route('/users', function() {
    return (function() {
        yield from App::render('shell-open', ['title' => 'Users'], stream: true);
        yield from App::render('users/stream', ['users' => User::all()], stream: true);
        yield from App::render('shell-close', stream: true);
    })();
});
```

### Three streaming template styles

`stream: true` accepts three template shapes — all three compose in the same `yield from` pipeline.

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

`App::fragment()` turns any template into a dual-mode file: the same `App::render('page', $args)` call serves **either** the complete page (no fragment selector → every `App::fragment()` block runs inline) **or** just one named region (`App::render('page', $args, fragment: 'name')` → that region's buffer is cleared, only its closure runs, and the rest of the template short-circuits via `HaltException`). Same template, same route handler, two different responses on the same URL — the [htmx-essay template-fragment](https://htmx.org/essays/template-fragments/) pattern without separate partial files.

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
    // No selector → full <ul> with every row inline.
    // ?fragment=contact-2 → just that one <li> on the wire.
    $fragment = is_string($g->get['fragment'] ?? null) ? $g->get['fragment'] : null;
    return App::render('contacts/list', ['contacts' => Contact::all()], fragment: $fragment);
});
```

Older code passes the selector as a `fragment` key in `$args`; that still works, but `fragment:` is the documented form.

Inside the closure, the universal contract applies — `return 404;` for auth, `return ['id' => 1];` for JSON, `return (fn() => yield ...)();` for streaming.

### Three behaviours worth knowing

- **Missing fragment → 404** per the universal return contract. Asking for `?fragment=does-not-exist` does not silently fall back to the full page.
- **First match wins** when the same name appears twice — the first block extracts, the rest of the template short-circuits.
- **Nested renders compose** — an `App::render()` called from inside a fragment closure does *not* inherit the parent's fragment selector. Each render's scope is saved + restored in `$g->memo['_fragment']`.

## Universal return contract

One contract, every entry point. Route handler, fallback, error handler, `App::render() / component() / include()`, public file, API closure, streaming-template closure — every one rides the same return-shape mapping, implemented by the shared private core `App::executeFile()`.

**One exception:** *combining* `echo` with an explicit non-null `return` concatenates only on the `executeFile`-backed entry points, **not** a top-level route handler, which `ResponseMiddleware` invokes directly. The two affected rows are marked `[1]`; see the note below the table.

| The handler / file does | Core sees | `ResponseMiddleware` emits |
|-------------------------|-----------|----------------------------|
| `echo "html"; // no explicit return` | `"html"` (buffered) | 200 + HTML body |
| `return 404;` | `404` (int) | 404 status, empty body |
| `return ['ok' => true];` | `['ok' => true]` (array) | 200 + JSON (`Content-Type: application/json`) |
| `return "explicit html";` | `"explicit html"` (string) | HTML body |
| `echo "shell"; return "body";` `[1]` | `"shellbody"` (concatenated) | HTML body (wire order preserved) |
| `return (function() { yield ...; })();` | `\Generator` | SSR stream — each `yield` flushed |
| `return function($req) { yield ...; };` | `\Closure` (param-injected when invoked) | SSR stream after invocation |
| `echo "header"; return (function() { yield ...; })();` `[1]` | `\Generator` wrapping `"header"` + delegated yields | Streamed in source order |
| `return new Response($body, 200);` | `ResponseInterface` | PSR-7 response used directly (output buffer ignored) |

**`[1]` `echo` + explicit non-null `return` — `executeFile` entry points only.** The `"shellbody"` concatenation and the `echo`-then-`Generator` prepend are implemented inside `App::executeFile()`, so they hold for the `executeFile`-backed entry points: `App::render() / component() / include()`, public files, API closures, and CGI-dispatched files. A **top-level route handler**, by contrast, is invoked directly by `ResponseMiddleware`, which discards the buffered output once the handler returns a non-null value — so a pre-`return` `echo` is **dropped** and only the returned value reaches the wire. In a route handler use **echo-only** *or* **return-only** (a returned non-null value replaces any buffered echo); to concatenate buffered output with an explicit body, route through `App::include()` / `App::render()`.

**Valid HTTP status codes.** When the contract says `int = HTTP status`, the int must be in the range **100–599** (RFC 7230). Codes outside that range are coerced to 500 with a warning logged via `elog()`. `return 1;` is the one special case — PHP's `include` returns `1` when a file has no explicit `return`, so the framework treats it as "no explicit return" inside `App::include() / render() / component()` and surfaces the buffered echo as the response body. If you want HTTP 1 specifically, return `100` instead.

## Yield from everywhere

Generators work in route handlers, public files, API handlers, and template files — anything that runs through the universal return contract.

| Location | How to stream | Example |
|----------|---------------|---------|
| Route handler | Return a Generator directly | `return (function() { yield "chunk"; })();` |
| Public file | Return a Generator from the file | `public/feed.php` → `<?php return (function() { yield "..."; })();` |
| API handler | Return a Generator from `$get`/`$post` | `$get = function() { return (function() { yield ...; })(); };` |
| Template (via `App::render(..., stream: true)`) | Return a Closure or Generator | `return function($items) { yield ...; };` |
| File dispatched via `App::include()` | Same — file's `return` flows through the contract | `return (function() { yield ...; })();` |

```php
// public/feed.php — a streaming public page
<?php
use ZealPHP\App;

return (function() {
    yield from App::render('shell-open', ['title' => 'Live Feed'], stream: true);
    yield "<h1>Feed</h1>";
    foreach (fetchFeedItems() as $item) {
        yield "<article>{$item->title}</article>\n";
    }
    yield from App::render('shell-close', stream: true);
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
  <?= App::component('/_nav', ['active' => $page]) ?>
  <main>
    <?= App::component("/pages/$page") ?>
  </main>
  <?= App::component('/_footer') ?>
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

## Escaping output

PHP templates have no auto-escaping, so escape every value that came from a user, a database, or a URL. `ZealPHP\e()` is the short form for HTML text and quoted attribute values:

```php
<?php use function ZealPHP\e; ?>
<a href="/users/<?= e($user['id']) ?>" title="<?= e($user['bio']) ?>"><?= e($user['name']) ?></a>
```

`e()` encodes `& < > " '`, turns `null` into an empty string, and replaces invalid UTF-8 with U+FFFD instead of returning an empty string. It's safe inside single- or double-quoted attributes. It is **not** safe in unquoted attributes, inside `<script>` or `<style>`, or in `on*` event-handler attributes.

Other contexts have their own method on `ZealPHP\Escape`:

| Where the value goes | Use | Example |
|---|---|---|
| HTML text, quoted attribute | `e($v)` / `Escape::html($v)` | `<p><?= e($bio) ?></p>` |
| One URL component (path segment, query value) | `Escape::url($v)` | `<a href="/search?q=<?= Escape::url($q) ?>">` |
| A whole URL from user input in `href` / `src` | `Escape::safeUrl($v)` | `<a href="<?= Escape::safeUrl($site) ?>">` — `javascript:`, `data:` and other schemes become `#` |
| A value inside `<script>` or a `data-*` attribute | `Escape::js($v)` | `<script>const user = <?= Escape::js($user) ?>;</script>` |
| Markup you built and trust | `Escape::raw($html)` | `e()` emits a `SafeHtml` value unchanged |

`Escape::js()` emits JSON with `< > & ' "` escaped as `\u` sequences, so the value can't close the `<script>` element or a quoted attribute. Only wrap markup in `Escape::raw()` when every user-supplied part of it was already escaped.

## Tips for template authors

- **Always escape user data** — see [Escaping output](#escaping-output). PHP templates have no auto-escaping — full control, full responsibility.
- Keep templates free of business logic. Transform data in route handlers or service classes and pass simple view models to `App::render()`.
- Use the route-handler / public-file / API-closure layers for logic; templates are view-only.
- Pair templates with CSS/JS assets in `public/` — ZealPHP does not prescribe an asset pipeline.
- For coroutine-enabled deployments (`App::superglobals(false)`), prefer `App::render(..., stream: true)` over blocking renders when the data source is itself async.
