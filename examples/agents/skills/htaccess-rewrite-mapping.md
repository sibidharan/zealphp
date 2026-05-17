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

3. **Building the target path from a URL param without sanitization.** This
   is a **path-traversal vulnerability**, NOT just a code-style issue:

   ```php
   // ⚠️ VULNERABLE — attacker can pass /serve/..%2F..%2F..%2Fetc%2Fpasswd
   $app->route('/serve/{file}', fn($file) =>
       App::includeFile(App::$cwd . '/public/' . $file . '.php')
   );
   ```

   `App::includeFile()` executes whatever file path you hand it. If the
   path is user-controlled and you don't constrain it, the user can read or
   execute any PHP file the worker has access to.

   **Two safe patterns** depending on what flexibility you actually need:

   ```php
   // (a) Whitelist — only specific known files are allowed.
   $allowed = ['users', 'orders', 'health'];
   $app->route('/serve/{file}', function ($file) use ($allowed) {
       if (!in_array($file, $allowed, true)) return 404;
       return App::includeFile(App::$cwd . '/public/' . $file . '.php');
   });

   // (b) basename() + realpath() containment — for filesystem-like resources.
   $app->route('/serve/{file}', function ($file) {
       $base = App::$cwd . '/public/';
       $abs  = realpath($base . basename($file) . '.php');
       if ($abs === false || !str_starts_with($abs, $base)) return 404;
       return App::includeFile($abs);
   });
   ```

   `basename()` strips any directory parts; `realpath()` resolves symlinks
   and `..` segments. The `str_starts_with($abs, $base)` check confirms the
   resolved file is still inside the document root — required because
   `realpath()` alone doesn't prevent escape, it just normalizes.

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
