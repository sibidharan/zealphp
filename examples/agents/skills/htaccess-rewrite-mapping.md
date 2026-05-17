# Skill: Apache `RewriteRule` → ZealPHP

Mechanical reference for converting any `RewriteRule` line to the right
ZealPHP route shape. The rule comes down to one bit of information: **does
the flag block contain `R=…` or just `L`?**

## The two modes

| Apache flags | Mode | Browser behavior | ZealPHP primitive |
|---|---|---|---|
| `[L]` (no `R`) | **Internal rewrite** | URL bar stays at the user's URL. Server serves the destination's content. No `Location:` header sent. | `App::includeFile()` |
| `[R=301,L]` or `[R=302,L]` or `[R,L]` | **External redirect** | Browser receives `301`/`302` + `Location:` header. Browser does a fresh request. URL bar changes. | `$response->redirect($url, $status)` |

If the original `.htaccess` author used `[R=301]`, they explicitly wanted
the URL to change. If they didn't, they explicitly didn't. **Don't
substitute one for the other** — that defeats the rewrite the user wrote.

## Decision table

```
Does the [...] block contain `R=` or bare `R`?
├── YES → external redirect → $response->redirect($target, $status)
└── NO  → internal rewrite  → App::includeFile(App::$cwd . '/public/<target>.php')
```

## Conversion templates

### Internal — `RewriteRule ^old$ /new [L]`

```php
$app->route('/old', function() {
    return App::includeFile(App::$cwd . '/public/new.php');
});
```

### Internal with captures — `RewriteRule ^qn/([^/]+)$ qn.php?id=$1 [L,QSA]`

```php
$app->route('/qn/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;                                       // populate $_GET like Apache does
    $g->server['SCRIPT_NAME']     = '/qn.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/qn.php';
    App::includeFile(App::$cwd . '/public/qn.php');
});
```

The `$g->get` writes are critical when `App::superglobals(true)` is on —
the legacy `.php` file reads `$_GET['id']` exactly as it did under Apache.

### Catch-all internal — `RewriteRule . /index.php [L]` (with the usual non-file/non-directory conditions)

```php
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF']        = '/index.php';
    $g->server['SCRIPT_NAME']     = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});
```

This is the classic WordPress/Drupal/Laravel front-controller. The URL the
user typed stays in the address bar; `index.php` parses it and decides what
to render.

### External permanent — `RewriteRule ^old$ /new [R=301,L]`

```php
$app->route('/old', function($response) {
    return $response->redirect('/new', 301);
});
```

### External temporary — `RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]`

```php
$app->route('/blog/{slug}', function($slug, $response) {
    return $response->redirect("/articles/{$slug}", 302);
});
```

### External cross-host — `RewriteRule ^docs$ https://docs.example.com [R=301,L]`

```php
$app->route('/docs', function($response) {
    return $response->redirect('https://docs.example.com', 301);
});
```

### HTTPS upgrade — `RewriteCond %{HTTPS} off` + `RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]`

```php
// Comment, do NOT convert:
//   HTTPS termination is a reverse-proxy concern (nginx, Caddy, Cloudflare).
//   ZealPHP runs behind the proxy; the proxy redirects HTTP -> HTTPS.
```

## Anti-patterns (don't do these)

1. **`header('Location: …'); return 301;` for a `[L]`-only rule.** Exposes the
   internal URL the rewrite was hiding. Always use `App::includeFile()` for
   internal rewrites.

2. **`App::includeFile()` for an `[R=301]` rule.** Defeats the redirect —
   browser sees `/old`'s URL in the address bar and gets `/new`'s content,
   which is exactly what `[R=301]` is meant to FIX (force the canonical URL
   into the address bar for SEO).

3. **Forgetting to populate `$g->get` for parameterized internal rewrites.**
   The legacy `.php` file reads `$_GET['id']` and gets nothing — silent bug.

4. **Routing a base file URL.** `public/qn.php` is auto-served at `/qn`;
   don't write `$app->route('/qn', …)`. Only parameterized URLs need routes.

## When you see flags you don't recognize

`QSA` (Query String Append) — query-string from the original URL is appended
to the rewrite target. `App::superglobals(true)` + `$g->get` writes handle
this automatically because `$_GET` is built from the original request.

`NC` (No Case) — Apache ignored case for the match. Almost never needed in
practice; if the original author used it intentionally, convert to a
case-insensitive regex with `patternRoute()`.

`L` (Last) — stop processing rules. Always present in real-world examples;
no ZealPHP equivalent needed — each route is independent.

`F` (Forbidden) — return 403. Convert to `return 403;`.

`G` (Gone) — return 410. Convert to `return 410;`.

`E=…` (Environment variable) — sets a server variable. Almost always for
nginx/Apache logging; safe to comment out and ignore for ZealPHP.
