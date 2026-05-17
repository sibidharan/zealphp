# Skill: Apache `RewriteRule` → ZealPHP

Mechanical reference for converting any `RewriteRule` line to the right
ZealPHP route shape (and the surrounding directives like `Header set`,
`AddDefaultCharset`, `<FilesMatch>`, `Allow from`, etc. to their built-in
middleware classes). Two questions decide everything for RewriteRule:

1. **Does the flag block contain `R=…` or just `L`?** — picks the primitive.
2. **If `[L]`, does the destination set a query string?** — picks whether you
   populate `$g->get` before delegating.

For non-rewrite directives, see the **Built-in middleware emission table**
at the bottom — most of the common ones (`Header set`, `AddDefaultCharset`,
`<FilesMatch> Header set Cache-Control`, `ExpiresByType`, `Allow from`,
`AddType`) map to shipped middleware classes as of v0.2.21.

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

## Built-in middleware emission table (v0.2.21 phase 2 — ALL 12 ship)

For non-`RewriteRule` directives surrounding the rewrites, emit the built-in
middleware class directly. Do NOT emit inline anonymous PSR-15 classes for
these — they ship in `src/Middleware/` with stable constructor signatures.

| Apache / nginx directive | Emit |
|---|---|
| `Header set X "v"` / nginx `add_header X v` | `new HeaderMiddleware(['set' => ['X' => 'v']])` |
| `Header append X "v"` | `new HeaderMiddleware(['append' => ['X' => 'v']])` |
| `Header add X "v1"` repeated | `new HeaderMiddleware(['add' => ['X' => ['v1', 'v2']]])` |
| `Header unset X` / `more_clear_headers X` | `new HeaderMiddleware(['unset' => ['X']])` |
| `AddDefaultCharset utf-8` / `AddCharset utf-8 .css .js ...` | `new CharsetMiddleware('utf-8')` |
| `<FilesMatch> Header set Cache-Control` / nginx `expires 30d` | `new CacheControlMiddleware()` (defaults cover css/js/jpg/png/svg/woff2/...) |
| Custom cache map | `new CacheControlMiddleware(['css' => 31536000, 'html' => 300])` |
| `ExpiresActive On` + `ExpiresByType image/jpeg "access plus 30 days"` | `new ExpiresMiddleware(['image/' => '+30 days', 'text/css' => '+1 year'], '+5 minutes')` |
| `Allow from 10.0.0.0/8` + `Deny from all` (Apache 2.2) | `new IpAccessMiddleware(['allow' => ['10.0.0.0/8'], 'deny' => []])` |
| `Require ip 10.0.0.0/8` (Apache 2.4+) | `new IpAccessMiddleware(['allow' => ['10.0.0.0/8'], 'deny' => []])` |
| nginx `allow 10.0.0.0/8; deny all;` | `new IpAccessMiddleware(['allow' => ['10.0.0.0/8'], 'deny' => []])` |
| `AddType application/wasm .wasm` (handler-generated bodies) | `new MimeTypeMiddleware(['wasm' => 'application/wasm'])` |
| Apache `RewriteRule ... \.php [R=404]` / nginx `location ~ \.php$ { return 404; }` | `new BlockPhpExtMiddleware()` |
| `Access-Control-Allow-Origin "*"` | `new CorsMiddleware(['*'])` |
| `FileETag` / nginx `etag on` | `new ETagMiddleware()` |
| `auth_basic "Realm"; auth_basic_user_file htpasswd;` / Apache `AuthType Basic` + `AuthUserFile` | `new BasicAuthMiddleware(htpasswdFile: '/etc/zealphp/.htpasswd', realm: 'Realm')` (or `verify:` callable for DB-backed) |
| nginx `limit_req zone=one burst=5;` | `Store::make('rate_limit', 16384, ['ip' => [Table::TYPE_STRING, 64], 'count' => [Table::TYPE_INT, 4], 'reset' => [Table::TYPE_INT, 4]])` BEFORE `$app->run()`, then `new RateLimitMiddleware(limit: 60, window: 60, tableName: 'rate_limit')` |
| nginx `limit_conn zone=one 100;` | `$counter = Counter::make('active');` BEFORE `$app->run()`, then `new ConcurrencyLimitMiddleware(100, $counter)` |
| Apache `Substitute "s\|foo\|bar\|"` (mod_substitute) | `new BodyRewriteMiddleware([['pattern' => '\|foo\|', 'replacement' => 'bar']])` — skips streaming + binary bodies |
| nginx multi-host `server { server_name a.com; } server { server_name b.com; }` | `new HostRouterMiddleware(['a.com' => $handlerA, 'b.com' => $handlerB, '*.example.com' => $wildcard, '*' => $catchAll])` — case-insensitive, port-stripped, falls through if no `*` and no match |

Collect all top-level `Header set` / `add_header` directives into ONE
`HeaderMiddleware` call. Don't emit one middleware per directive.

### Critical setup ordering

`RateLimitMiddleware` and `ConcurrencyLimitMiddleware` need their Store
table / Counter to exist BEFORE `$app->run()`. The shared-memory resource
is forked into every worker; creating it after `$app->run()` is too late.
Emit the `Store::make(...)` / `Counter::make(...)` BEFORE `$app->addMiddleware(...)`.

### `App::clientIp()` and `App::trustedProxies()` — behind a proxy

When the input config has any proxy signal (`proxy_pass`, `X-Forwarded-For`,
`RemoteIPHeader`, known proxy IPs):

```php
App::trustedProxies(['10.0.0.0/8', '127.0.0.1']);  // boot
// ... handlers / IP-needing middleware use App::clientIp() now
```

`App::clientIp()` walks `X-Forwarded-For` right-to-left against the trusted-
proxy CIDR list and returns the first untrusted hop. Without `trustedProxies()`
set, it returns `$g->server['REMOTE_ADDR']` unchanged (refuses to honor
headers from untrusted callers).

### One remaining genuine middleware gap

Only `ProxyMiddleware` (nginx `proxy_pass`) is unbuilt — ZealPHP is an
origin server, not a forwarding proxy. Recommend Caddy/Traefik/nginx in
front. If same-process forwarding is required (rare in practice), emit
the inline anonymous shape with a `// PROPOSED: ProxyMiddleware` comment.

### Other v0.2.21 configurables (Apache parity at the App layer)

- `App::stripTrailingSlash(true)` — inverse of `directorySlash`; Apache `RewriteRule ^(.+)/$ /$1 [R=301]` equivalent
- `App::serverAdmin('admin@x.com')` — Apache `ServerAdmin`
- `App::canonicalName('www.example.com')` + `App::useCanonicalName(true)` — Apache `ServerName` / `UseCanonicalName`
- `App::hostnameLookups(true)` — Apache `HostnameLookups`, populates `$g->server['REMOTE_HOST']`
- `App::accessLogFormat('%h %l %u %t "%r" %>s %b')` — Apache `CustomLog`/`LogFormat`
- `App::limitRequestFields(100)`, `App::limitRequestFieldSize(8190)`, `App::limitRequestLine(8190)` — Apache `LimitRequestFields*`
- `App::tryInclude($path, $args)` — variant of `App::include()` returning `null` on missing file (for fall-through chains)
