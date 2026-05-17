# Skill: Apache `RewriteRule` → ZealPHP

Mechanical reference for converting any `RewriteRule` line to the right
ZealPHP route shape. Two questions decide everything:

1. **Does the flag block contain `R=…` or just `L`?** — picks the primitive.
2. **If `[L]`, does the destination set a query string?** — picks whether you
   populate `$g->get` before delegating.

## The two modes

| Apache flags | Mode | Browser behavior | ZealPHP primitive |
|---|---|---|---|
| `[L]` (no `R`) | **Internal rewrite** | URL bar stays at the user's URL. Server serves the destination's content. No `Location:` header sent. | `App::include('/<target>.php')` |
| `[R=301,L]` or `[R=302,L]` or `[R,L]` | **External redirect** | Browser receives `301`/`302` + `Location:` header. Browser does a fresh request. URL bar changes. | `$response->redirect($url, $status)` |

If the original `.htaccess` author used `[R=301]`, they explicitly wanted
the URL to change. If they didn't, they explicitly didn't. **Don't
substitute one for the other** — that defeats the rewrite the user wrote.

## Decision table

```
Does the [...] block contain `R=` or bare `R`?
├── YES → external redirect → $response->redirect($target, $status)
└── NO  → internal rewrite  → App::include('/<target>.php')
                              (public-relative path — Apache DocumentRoot convention)
```

## API form (post-rename — emit only these)

- **`App::include('/path.php')`** — the rename of the legacy `App::includeFile()`.
  `App::includeFile()` still works (deprecated alias) but is NEVER emitted in new code.
- **Public-RELATIVE paths only.** `App::include('/qn.php')` resolves to `public/qn.php`.
  Leading slash is ergonomic sugar — both `'/qn.php'` and `'qn.php'` work. NEVER emit
  `App::include(App::$cwd . '/public/qn.php')` — that's the old form; the framework
  resolves under `public/` automatically.
- **Never emit the `$g->server` preamble**. `App::include()` populates `PHP_SELF` /
  `SCRIPT_NAME` / `SCRIPT_FILENAME` automatically (Apache mod_php parity). Setting
  them yourself is redundant.
- **Use `RequestContext::instance()` for capture-to-querystring**, not bare `$_GET`.
  Always pair with a `// legacy: $_GET[...] = ...;` comment so users learn the
  parity rule (the legacy alias `\ZealPHP\G` still works; `RequestContext` is the
  canonical name).

## Conversion templates

### Internal — `RewriteRule ^old$ /new [L]`

```php
$app->route('/old', fn() => App::include('/new.php'));
```

### Internal with captures — `RewriteRule ^qn/([^/]+)$ qn.php?id=$1 [L,QSA]`

```php
$app->route('/qn/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/qn.php');
});
```

The `$g->get` writes are critical when `App::superglobals(true)` is on —
the legacy `.php` file reads `$_GET['id']` exactly as it did under Apache.
The `// legacy:` comment teaches the parity rule (`$_GET` writes leak across
coroutines in the default mode; `$g->get` is per-coroutine safe in both modes).

Note the `return` — `App::include()` honors the universal return contract.
Whatever the included file returns (int / array / string / Generator / void+echo)
flows back through `ResponseMiddleware`. See `/responses#return-contract`.

### Catch-all internal — `RewriteRule . /index.php [L]`

```php
$app->setFallback(fn() => App::include('/index.php'));
```

This is the classic WordPress / Drupal / Laravel front-controller. The URL the
user typed stays in the address bar; `index.php` parses it and decides what to
render.

### External permanent — `RewriteRule ^old$ /new [R=301,L]`

```php
$app->route('/old', fn($response) => $response->redirect('/new', 301));
```

### External temporary — `RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]`

```php
$app->route('/blog/{slug}', fn($slug, $response) => $response->redirect("/articles/{$slug}", 302));
```

### External cross-host — `RewriteRule ^docs$ https://docs.example.com [R=301,L]`

```php
$app->route('/docs', fn($response) => $response->redirect('https://docs.example.com', 301));
```

### HTTPS upgrade — `RewriteCond %{HTTPS} off` + `RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]`

```php
// Preferred: terminate TLS in a front proxy (Caddy / Traefik / Cloudflare / nginx)
// and let it redirect HTTP -> HTTPS upstream of ZealPHP.
//
// If you must inline it, emit a custom middleware (no built-in HTTPSRedirectMiddleware yet):
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $params = $request->getServerParams();
        $forwarded = $params['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (($params['HTTPS'] ?? '') !== 'on' && $forwarded !== 'https') {
            $uri = $request->getUri();
            $url = 'https://' . $uri->getHost() . $uri->getPath()
                 . ($uri->getQuery() ? '?' . $uri->getQuery() : '');
            return (new \OpenSwoole\Core\Psr\Response(''))
                ->withStatus(301)
                ->withHeader('Location', $url);
        }
        return $handler->handle($request);
    }
});
```

## Anti-patterns (don't do these)

1. **`header('Location: …'); return 301;` for a `[L]`-only rule.** Exposes the
   internal URL the rewrite was hiding. Always use `App::include()` for
   internal rewrites.

2. **`App::include()` for an `[R=301]` rule.** Defeats the redirect —
   browser sees `/old`'s URL in the address bar and gets `/new`'s content,
   which is exactly what `[R=301]` is meant to FIX (force the canonical URL
   into the address bar for SEO).

3. **`App::include(App::$cwd . '/public/...')` or `App::include(__DIR__ . '/...')`.**
   Paths are public-relative — the framework resolves under `public/` automatically.
   Emit `App::include('/qn.php')`.

4. **Emitting the `$g->server` preamble** (`PHP_SELF` / `SCRIPT_NAME` / `SCRIPT_FILENAME`).
   `App::include()` populates these inside the framework. Setting them in user code is
   redundant; the old call-site preamble is gone.

5. **`App::includeFile(...)` in newly emitted code.** It's the deprecated alias.
   `App::include()` is the new name and is what every generated app.php should use.

6. **Bare `$_GET['x'] = $x;` for capture-to-querystring.** Writes to `$_GET` leak across
   coroutines in the default (coroutine) mode. Use `$g = RequestContext::instance();
   $g->get['x'] = $x;` and pair with a `// legacy: $_GET['x'] = $x;` comment.

7. **Building the target path from a URL param without sanitization.** This
   is a **path-traversal vulnerability**, NOT just a code-style issue:

   ```php
   // ⚠️ VULNERABLE — attacker can pass /serve/..%2F..%2F..%2Fetc%2Fpasswd
   $app->route('/serve/{file}', fn($file) => App::include('/' . $file . '.php'));
   ```

   `App::include()` runs the framework's `includeCheck()` so paths cannot
   escape `public/` — but the visible path the user controls still leaks
   internal filenames. **Whitelist** the allowed targets:

   ```php
   $allowed = ['users', 'orders', 'health'];
   $app->route('/serve/{file}', function ($file) use ($allowed) {
       if (!in_array($file, $allowed, true)) return 404;
       return App::include('/' . $file . '.php');
   });
   ```

8. **Forgetting to populate `$g->get` for parameterized internal rewrites.**
   The legacy `.php` file reads `$_GET['id']` and gets nothing — silent bug.

9. **Routing a base file URL.** `public/qn.php` is auto-served at `/qn`;
   don't write `$app->route('/qn', …)`. Only parameterized URLs need routes.

## When you see flags you don't recognize

`QSA` (Query String Append) — query-string from the original URL is appended
to the rewrite target. `App::superglobals(true)` + `$g->get` writes handle
this automatically because `$_GET` is built from the original request.

`NC` (No Case) — Apache ignored case for the match. Almost never needed in
practice; if the original author used it intentionally, convert to a
case-insensitive regex with `patternRoute()` using `(?i)` prefix.

`L` (Last) — stop processing rules. Always present in real-world examples;
no ZealPHP equivalent needed — each route is independent.

`F` (Forbidden) — return 403. Convert to `fn() => 403;` (universal return contract).

`G` (Gone) — return 410. Convert to `fn() => 410;` (universal return contract).

`E=…` (Environment variable) — sets a server variable. Almost always for
nginx/Apache logging; safe to comment out and ignore for ZealPHP. The
special case `[E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` is a PHP-FPM
workaround that ZealPHP doesn't need — drop with an explanatory comment.

## Universal return contract — `App::include()` inherits it

Any file invoked via `App::include()` can `return` any of these and the
framework translates them into the right HTTP response:

| File does | Framework emits |
|---|---|
| `echo "html"; // no return` | 200 + HTML body |
| `return 404;` | 404, empty body |
| `return ['ok' => true];` | 200 + JSON |
| `return "explicit html";` | 200 + HTML |
| `return (function() { yield ...; })();` | SSR streaming |

Cross-link in generated comments: `/responses#return-contract`.
