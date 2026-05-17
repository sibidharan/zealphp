#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
Apache/nginx -> ZealPHP Converter Agent
=======================================
Converts .htaccess or nginx config into a ZealPHP app.php.
Uses gpt-5.4-mini with streaming, few-shot examples, and tool-assisted validation.

What this agent knows (encoded in the system prompt + reference + examples):
  - The four file-execution methods (render / renderToString / renderStream / include)
    and the rule that App::include() is the only correct form for serving public
    files. The legacy App::includeFile() name is the deprecated alias and is never
    emitted in new code.
  - The universal return contract — int / array / string / Generator / Closure /
    void+echo — applies identically to route handlers, App::include()'d files,
    template Closures, fallbacks, and error handlers.
  - The `$g` vs `$_*` parity rule: always read/write request state via
    `RequestContext::instance()`; the PHP superglobals are only safe under
    `superglobals(true)` and leak across coroutines otherwise.
  - 12 Apache rewrite recipes (A-L), the Apache `AllowOverride` coverage matrix,
    and the nginx routing/HTTP coverage matrix.
  - The known-limitations list — when input uses an unsupported feature (SSI,
    mod_speling, mod_imagemap, mod_dav, AuthLDAP, AuthDigest, etc.), the agent
    MUST refuse explicitly with a `// (NOT SUPPORTED)` comment rather than
    silently dropping the directive.
  - The fluent method form for configurables (`App::ignorePhpExt(false)` rather
    than `App::$ignore_php_ext = false`), per the framework's one-app-per-
    process / configure-then-init lifecycle.
  - The `^0.2.18` `composer.json` constraint pinned in generated code.

Usage:
    uv run examples/agents/config_converter.py
    echo "RewriteRule ^api/(.*)$ index.php [L]" | uv run examples/agents/config_converter.py

Requires: OPENAI_API_KEY environment variable
"""

import asyncio
import sys
from agents import Agent, Runner, function_tool


# Bumped whenever the prompt's encoded knowledge changes meaningfully. Sync
# with the framework version that the emitted `composer.json` snippets pin.
SYSTEM_PROMPT_VERSION = "2026-05-17-b"
ZEALPHP_VERSION = "^0.2.21"


ZEALPHP_REFERENCE = r"""
## ZealPHP Framework Reference — for Converter Agent (system prompt v2026-05-17-b, targets ^0.2.21)

ZealPHP is a PHP web framework built on OpenSwoole. It replaces Apache/nginx entirely —
ZealPHP IS the HTTP server. There is no separate web server. There is no PHP-FPM.

### Architecture

- **app.php** — entry point. Configures the framework (static methods, see below),
  initialises with `App::init()`, registers routes and middleware, then calls `$app->run()`.
- **public/** — the document root (equivalent to Apache's DocumentRoot / htdocs).
  All PHP files from the old Apache document root MUST be moved into `public/`.
  Once in `public/`, they are auto-served at their base name: `public/qn.php` -> `/qn`.
  Static files (CSS, JS, images, fonts) in `public/` are served directly by OpenSwoole.
- **route/** — route definition files for parameterized URL patterns. Auto-included at startup.
- **template/** — view templates rendered via `App::render()` / `App::renderToString()` /
  `App::renderStream()`. Templates are view-only — they do not serve as public files.

### Migration Step: Move Files to public/

When converting from Apache, the FIRST instruction must be:
"Move all PHP files from your Apache document root into the `public/` folder."

Once files are in `public/`, they are auto-served. You do NOT need routes for base URLs.
`public/qn.php` is available at `/qn` automatically — no `$app->route('/qn', ...)` needed.

You ONLY need explicit `$app->route()` calls for:
1. Parameterized URLs: `/qn/{id}` (auto-serving can't handle URL params)
2. Redirect rules: `[R=301,L]` etc.
3. Catch-all / fallback rules
4. Routes that need special HTTP method handling

### Configuration is static and goes BEFORE `App::init()`

ZealPHP's mental model: configure, then init, then route, then run. Why static
and not instance methods? OpenSwoole's `Server` is a process singleton — exactly
one ZealPHP App per process. Static config matches the process reality. (Multi-
port still means one App per process; truly independent apps run as separate
PHP processes, each with its own PID file under `/tmp/zealphp/`.) Config must
land BEFORE `App::init()` returns; it cannot live on an instance that does not
exist yet.

Use the fluent setter methods (the canonical API form). Direct property
assignment still works for BC but is NEVER emitted in new code.

```php
// CORRECT — fluent method form
App::superglobals(false);              // coroutine mode (default for new apps)
App::ignorePhpExt(true);               // strip .php from URLs (clean URLs)
App::documentRoot('public');           // emit ONLY if different from default
App::traceEnabled(false);              // emit only in security-focused configs (already default)
App::directorySlash(true);
App::directoryIndex(['index.php', 'index.html']);
App::pathInfo(true);
App::blockDotfiles(true);
App::defaultCharset('utf-8');

// WRONG — never emit raw property assignment in generated code
App::$superglobals = false;            // do not emit
App::$ignore_php_ext = true;           // do not emit
App::$directory_index = ['index.php']; // do not emit
```

### App Initialization

```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

// Static configuration FIRST.
App::superglobals(false);

// Then init.
$app = App::init('0.0.0.0', 8080);
// ... routes + middleware ...
$app->run(['task_worker_num' => 0]);
```

`App::init()` signature: `App::init($host, $port, $cwd)` — no other parameters.
NEVER pass arrays, phpSettings, or config objects to `App::init()`.

### Route Registration — `{param}` Syntax

ZealPHP uses Flask-style `{param}` placeholders. Parameters are injected into the handler
function BY NAME via reflection. No manual `$_GET` assignment needed when the URL itself
carries the value — the framework injects it directly.

```php
// Single param — $id is injected from URL
$app->route('/user/{id}', function($id) {
    return ['user_id' => $id];  // arrays auto-encode to JSON
});

// Multiple params
$app->route('/user/{id}/post/{post_id}', function($id, $post_id) {
    return ['user' => $id, 'post' => $post_id];
});

// With HTTP methods
$app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id) {
    return ['id' => $id];
});
```

### Magic Parameter Names (injected automatically, not from URL)

| Name        | Type                          | Description                          |
|-------------|-------------------------------|--------------------------------------|
| `$request`  | `ZealPHP\HTTP\Request`        | HTTP request object                  |
| `$response` | `ZealPHP\HTTP\Response`       | HTTP response object                 |
| `$app`      | `App`                         | App instance                         |

Any parameter not matching a URL `{param}` or magic name gets its PHP default value.

### Route Types

```php
// 1. Basic route — most common
$app->route('/path/{param}', function($param) { ... });

// 2. Namespace route — adds a prefix
$app->nsRoute('admin', '/dashboard', function() { ... });
// Creates route at /admin/dashboard

// 3. Namespace path route — last {param} catches everything including slashes
$app->nsPathRoute('api', '{path}', function($path) { ... });
// /api/users/123/posts -> $path = "users/123/posts"

// 4. Pattern route — raw regex, no {param} syntax
$app->patternRoute('/files/.*', function() { ... });
```

### File-execution family — four methods, ONE shared core

ZealPHP has exactly four ways to run a PHP file through the framework. All four
share a private core, all four honor the universal return contract (see next
section). Pick the one whose **intent** matches the call site.

| Method | Input | Resolved from | Returns | When to use |
|---|---|---|---|---|
| `App::render($tpl, $args = [])` | template name | `template/` (no `.php` suffix needed) | `mixed` (echoes BC string output for void callers) | Default for emitting template HTML inside a route handler or another template |
| `App::renderToString($tpl, $args = [])` | template name | `template/` | `string` | When you need template HTML as a value (email body, cache fill, embedding) |
| `App::renderStream($tpl, $args = [])` | template name | `template/` | `Generator` | SSR streaming — yields template output chunk by chunk |
| `App::include($publicPath, $args = [])` | path RELATIVE to `public/` | `public/` (Apache document-root convention) | `mixed` (full return contract) | Serving a public-side `.php` file from a route handler — the standard tool when porting a `RewriteRule ... target.php` to ZealPHP |

CRITICAL emission rules for `App::include()`:

1. **Public-relative paths only**. The path is relative to `public/`, matching
   Apache's DocumentRoot model. Leading slash is optional ergonomic sugar.
   - CORRECT: `App::include('/qn.php')` or `App::include('qn.php')`
   - WRONG: `App::include(App::$cwd . '/public/qn.php')`  ← never emit this
   - WRONG: `App::include(__DIR__ . '/public/qn.php')`     ← never emit this

2. **Never emit the `$g->server` preamble** (`PHP_SELF`, `SCRIPT_NAME`, `SCRIPT_FILENAME`).
   The framework populates those automatically inside `App::include()` to mirror Apache
   mod_php behaviour. Setting them yourself is at best redundant and at worst wrong.

3. **`App::includeFile()` is the deprecated alias.** It still works (no runtime warning;
   the WordPress showcase and existing scaffolds rely on it) — but NEVER emit it in new code.
   Always emit `App::include()`. Mention the rename only if asked.

4. **For templates**, always emit `App::render('template-name')` — never `App::include()`.
   Templates live in `template/`, not `public/`.

### Universal return contract (single source of truth)

Any function that produces a response — route handler, fallback, error handler,
`App::render()`, `App::renderToString()`, `App::renderStream()`, `App::include()`,
public file, API closure — uses the same return contract. The framework
translates the return value into an HTTP response identically regardless of
where it came from.

| File / handler does | Framework emits |
|---|---|
| `echo "html"; // no explicit return` | `200` + HTML body (output buffer captured) |
| `return 404;` | `404` status, empty body |
| `return ['ok' => true];` | `200` + JSON (Content-Type set) |
| `return "explicit html";` | `200` + HTML body |
| `echo "shell"; return "body";` | `200` + `shellbody` (echo concatenated to return) |
| `return (function() { yield ...; })();` | SSR streaming via Generator |
| `return function($req) { yield ...; };` | Closure with param injection, then SSR stream |
| `echo "header"; return (function() { yield ...; })();` | Streamed in source order: header first, then yields |
| `return ResponseInterface` (PSR-7) | Used as-is |

Generated code should use this contract idiomatically:
- For "deny" / "forbidden" / "gone" responses, emit `return 403;` / `return 410;` —
  NEVER `http_response_code(403); exit;` or `header('HTTP/1.1 403');`.
- For JSON, emit `return ['key' => $value];` — NEVER `header('Content-Type: application/json'); echo json_encode(...);`.
- For status-with-custom-body inside `App::include()`'d files, set `$g = RequestContext::instance(); $g->status = N;` and return a string — the framework reads `$g->status`.
- Cross-link in generated comments: "see /responses#return-contract for the full table".

### `$g` vs `$_GET` parity rule (request state in both modes)

Read/write request state via `RequestContext::instance()`:
`$g->get`, `$g->post`, `$g->cookie`, `$g->server`, `$g->session`, `$g->files`,
`$g->status`.

This works identically in BOTH modes:
- Under `App::superglobals(true)`, `$g->get` is bridged to `$GLOBALS['_GET']` —
  the two forms are observationally equivalent.
- Under `App::superglobals(false)` (coroutine mode, the new default), `$g->get`
  is a per-coroutine isolated property; `$_GET` is NOT populated per request
  and writes to it leak across coroutines.

**Rule**: emit the `$g->` form. Always. Add an explanatory comment for legacy
readers:

```php
$app->patternRoute('/article/([0-9]+)\.html', function ($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;  ← only safe under App::superglobals(true)
    return App::include('/article.php');
});
```

NEVER emit bare `$_GET['x'] = $x;` in the primary code path — it leaks across
coroutines in the default (and recommended) mode. Always pair the `$g->get`
write with a `// legacy: $_GET['x'] = $x;` comment so users learn the rule.

The legacy alias `\ZealPHP\G` still exists (a class_alias for
`RequestContext`); the canonical name to emit is `RequestContext`.

### Apache rewrite recipes — `.php` destinations (the 12 you actually see)

These are the canonical migration shapes. The agent recognises each pattern in
the input and emits the corresponding template idiomatically.

**Recipe A — Strip `.php` extension (clean URLs)**

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}\.php -f
RewriteRule ^(.+)$ $1.php [L]
```
```php
// Built-in: the implicit /{file} route serves /about and /about.php from public/about.php.
App::ignorePhpExt(true);   // strip .php from URLs
```

**Recipe B — Pretty URL -> real `.php` file (with route param + QSA)**

```apache
RewriteRule ^my-page$                 /pages/my-page.php           [L]
RewriteRule ^article/([0-9]+)\.html$  /article.php?id=$1           [L,QSA]
RewriteRule ^user/([a-z0-9-]+)$       /user/profile.php?slug=$1    [L,QSA]
```
```php
$app->route('/my-page', fn() => App::include('/pages/my-page.php'));

$app->patternRoute('/article/([0-9]+)\.html', function ($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/article.php');
});

$app->patternRoute('/user/([a-z0-9-]+)', function ($slug) {
    $g = RequestContext::instance();
    $g->get['slug'] = $slug;
    // legacy: $_GET['slug'] = $slug;
    return App::include('/user/profile.php');
});
```

**Recipe C — Front controller (WordPress / Drupal / Laravel)**

```apache
# WordPress / Laravel
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]

# Drupal 7 / older CMSes — pass the path as ?q=
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?q=$1 [L,QSA]
```
```php
// WordPress / Laravel
$app->setFallback(fn() => App::include('/index.php'));

// Drupal
$app->setFallback(function () {
    $g = RequestContext::instance();
    $g->get['q'] = ltrim(parse_url($g->server['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    // legacy: $_GET['q'] = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
    return App::include('/index.php');
});
```

**Recipe D — API prefix -> single front controller**

```apache
RewriteRule ^api/(.*)$ /api/index.php [L,QSA]
```
```php
$app->nsPathRoute('api', '{path}', function (string $path) {
    $g = RequestContext::instance();
    $g->get['path'] = $path;
    // legacy: $_GET['path'] = $path;
    return App::include('/api/index.php');
});
```

**Recipe E — Specific `.php` file in subdirectory**

```apache
RewriteRule ^admin/?$         /admin/login.php       [L]
RewriteRule ^admin/users$     /admin/users/index.php [L]
RewriteRule ^checkout/done$   /shop/thankyou.php     [L]
```
```php
$app->route('/admin',         fn() => App::include('/admin/login.php'));
$app->route('/admin/users',   fn() => App::include('/admin/users/index.php'));
$app->route('/checkout/done', fn() => App::include('/shop/thankyou.php'));
```

**Recipe F — Block direct access to internal `.php` files**

```apache
RewriteRule ^wp-includes/(.+\.php)$ - [F,L]
```
```php
// 403 before the implicit router serves the file.
$app->nsPathRoute('wp-includes', '{rest}(\.php)?', fn() => 403);
```

**Recipe G — HTTPS redirect (canonical scheme)**

```apache
RewriteCond %{HTTPS} off
RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```
```php
// Preferred: terminate TLS in a front proxy (Caddy/Traefik/nginx).
// Inline equivalent (custom middleware — there is no built-in HTTPSRedirectMiddleware yet):
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

**Recipe H — Canonical host (www vs apex)**

```apache
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteRule (.*) https://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```
```php
// Same shape as G — inline middleware that checks Host and 301s if not canonical.
// No built-in CanonicalHostMiddleware yet; emit the inline anonymous middleware.
```

**Recipe I — Maintenance mode**

```apache
RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.42$
RewriteRule .* /maintenance.html [R=503,L]
```
```php
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $allowList = ['203.0.113.42'];
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        if (!in_array($remote, $allowList, true)) {
            return (new \OpenSwoole\Core\Psr\Response(''))
                ->withStatus(503)
                ->withHeader('Location', '/maintenance.html');
        }
        return $handler->handle($request);
    }
});
```

**Recipe J — Custom error page (ErrorDocument)**

```apache
ErrorDocument 404 /custom-404.php
ErrorDocument 500 /custom-500.php
```
```php
$app->setErrorHandler(404, fn() => App::include('/custom-404.php'));
$app->setErrorHandler(500, fn($exception) => App::include('/custom-500.php', ['exception' => $exception]));
// Args passed to App::include() are extract()'ed into the file's scope — same as render().
```

**Recipe K — SEO redirect (old paths -> new)**

```apache
RedirectMatch 301 ^/old-section/(.*)$ /new-section/$1
RewriteRule ^blog/(.*)$ /articles/$1 [R=301,L]
```
```php
$app->patternRoute('/old-section/(.*)', fn($rest, $response) => $response->redirect("/new-section/{$rest}", 301));
$app->patternRoute('/blog/(.*)',         fn($rest, $response) => $response->redirect("/articles/{$rest}",    301));
```

**Recipe L — Trailing-slash enforcement (add slash for directories)**

```apache
RewriteCond %{REQUEST_URI} !(\.[a-zA-Z]+|/)$
RewriteRule (.*) /$1/ [R=301,L]
```
```php
// Built-in: add the trailing slash for directories.
App::directorySlash(true);
```

For the OPPOSITE direction (strip trailing slash from non-directory URLs),
there is no built-in flag yet. Emit an explicit patternRoute, registered AFTER
the more specific routes so it doesn't shadow them.

### Apache `AllowOverride` coverage matrix (full)

Legend: `OK` built-in / `MW` needs middleware (named below; inline implementation provided) /
`PHP` PHP-level idiom / `NS` (NOT SUPPORTED — refuse explicitly).

**AllowOverride All — request shape, server identity, conditionals**

| Apache | Status | Emit |
|---|---|---|
| `<Files>`, `<FilesMatch>` | OK | Route patterns + PHP `if` over `$g->server['REQUEST_URI']` |
| `<If>`, `<ElseIf>`, `<Else>` | OK | Native PHP control flow in handlers/middleware |
| `<IfModule>`, `<IfDefine>`, `<IfVersion>` etc. | OK | PHP `if (class_exists / extension_loaded / version_compare)` |
| `LimitRequestBody` | OK | `'package_max_length' => N` in `$app->run()` |
| `RLimitCPU / MEM / NPROC` | PHP | `set_time_limit`, `memory_limit`, OS `ulimit` |
| `ServerSignature` | OK | No-op — never sent |
| `SSIErrorMsg`, `XBitHack`, `Options +Includes`, `AddHandler server-parsed .shtml` | NS | Server-Side Includes — refuse, point to `App::render()` / `App::include()` |

**AllowOverride AuthConfig — authentication**

| Apache | Status | Emit |
|---|---|---|
| `AuthType Basic` + `AuthUserFile` + `Require` | MW | `BasicAuthMiddleware` is PROPOSED — not yet in `src/Middleware/`. Emit the inline anonymous middleware (see BasicAuth template below) AND note the framework gap. |
| `AuthType Digest` + `AuthDigest*` | NS | Digest auth is largely retired — refuse and recommend HTTPS + Basic + Bearer tokens |
| `AuthLDAP*` | NS | Refuse, link to PHP `ldap` extension as the integration path |
| `Anonymous*` (anonymous FTP-style auth) | NS | Refuse |
| `Require valid-user`, `Require user X`, `Require group Y` | MW | Configured via `BasicAuthMiddleware` callback (inline implementation — proposed) |
| `<Limit>`, `<LimitExcept>` | OK | `methods` array on route definitions |
| `<RequireAll>`, `<RequireAny>`, `Satisfy` | MW | Same `BasicAuthMiddleware` config combinators (inline — proposed) |
| `Session*` (mod_session) | OK | Use PHP-native sessions (the `Session` family) |
| `SSL*` (cipher, verify) | PHP | OpenSwoole TLS config at boot |

**AllowOverride FileInfo — headers, content negotiation, rewrites**

| Apache | Status | Emit |
|---|---|---|
| `RewriteEngine`, `RewriteRule`, `RewriteCond`, `Redirect`, `RedirectMatch` | OK | Native routing — see Recipes A-L |
| `Header set / append / unset / add / merge` | OK | `$app->addMiddleware(new HeaderMiddleware(['set' => [...], 'append' => [...], 'add' => [...], 'unset' => [...]]));` (built-in, v0.2.21+) |
| `RequestHeader` | MW | `HeaderMiddleware` is response-side only; request-header mutation is a small custom middleware |
| `ErrorDocument 404 /foo.php` | OK | `App::setErrorHandler(404, fn() => App::include('/foo.php'))` |
| `AddDefaultCharset utf-8`, `AddCharset utf-8 .css .js ...` | OK | `$app->addMiddleware(new CharsetMiddleware('utf-8'));` (built-in, v0.2.21+) — appends `; charset=...` to text-ish Content-Types |
| `AddType application/wasm .wasm` | OK | `$app->addMiddleware(new MimeTypeMiddleware(['wasm' => 'application/wasm']));` (built-in, v0.2.21+) — fills Content-Type based on URL extension when handler didn't set one |
| `AddEncoding gzip .gz` | OK | OpenSwoole `http_compression` handles content encoding |
| `BrowserMatch`, `SetEnvIf*`, `SetEnv`, `UnsetEnv`, `PassEnv` | PHP | Inline `if` over `$g->server['HTTP_USER_AGENT']`; assign to `$g->server['MY_VAR']` |
| `Cookie*` (mod_usertrack) | OK | `setcookie()` (ZealPHP override accepts `$samesite`) |
| `FileETag` | OK | `ETagMiddleware` (built-in) |
| `EnableMMAP`, `EnableSendfile` | OK | `$response->sendFile()` uses kernel sendfile transparently |
| `ForceType` | PHP | `$response->header('Content-Type', $type)` in handler |
| `Substitute "s/foo/bar/"` (body rewrite) | MW | `BodyRewriteMiddleware` PROPOSED — not yet built. Inline implementation on demand. |

**AllowOverride Indexes — directory listings, expires**

| Apache | Status | Emit |
|---|---|---|
| `DirectoryIndex index.php index.html` | OK | `App::directoryIndex(['index.php', 'index.html'])` |
| `DirectorySlash` | OK | `App::directorySlash(true)` |
| `FallbackResource` | OK | `App::setFallback(fn() => ...)` |
| `ExpiresActive`, `ExpiresByType`, `ExpiresDefault` | OK | `$app->addMiddleware(new ExpiresMiddleware(['image/' => '+30 days', 'text/css' => '+1 year'], '+5 minutes'));` and/or `new CacheControlMiddleware(['css' => 31536000, 'js' => 31536000])` (both built-in, v0.2.21+) |
| `AddIcon`, `AddAlt`, `IndexOptions`, `IndexStyleSheet`, `HeaderName`, `ReadmeName` | NS | Apache mod_autoindex full customisation — basic listing is supported via `App::autoindex(true)` (when shipped), but icon/description/style customisation is not |
| `ImapBase`, `ImapDefault`, `ImapMenu` | NS | mod_imagemap — dead since ~1995 |
| `MetaDir`, `MetaFiles`, `MetaSuffix` | NS | CERN meta files — dead |

**AllowOverride Limit — host-based access**

| Apache | Status | Emit |
|---|---|---|
| `Allow from`, `Deny from`, `Order` (2.2 syntax) | OK | `$app->addMiddleware(new IpAccessMiddleware(['allow' => ['10.0.0.0/8', '127.0.0.1'], 'deny' => []]));` (built-in, v0.2.21+) — IPv4/IPv6 CIDR support, deny wins ties |
| `<Limit METHOD>`, `<LimitExcept METHOD>` | OK | `methods` array on route |

**AllowOverride Options — feature toggles**

| Apache | Status | Emit |
|---|---|---|
| `Options Indexes` (directory listing) | NS | Off-by-default; the basic-listing toggle `App::autoindex(true)` is on the roadmap but not yet shipped — refuse the full Apache surface |
| `Options FollowSymLinks`, `SymLinksIfOwnerMatch` | PHP | `realpath()` resolves; `includeCheck()` enforces containment |
| `Options ExecCGI` | OK | N/A — no external CGI handlers; `App::include()` is the in-process equivalent |
| `Options Includes`, `Options IncludesNoExec`, `XBitHack` | NS | SSI |
| `Options MultiViews` | MW | Custom middleware if needed; uncommon |
| `CheckSpelling`, `CheckCaseOnly`, `CheckBasenameMatch` (mod_speling) | NS | Refuse — security-questionable, low-value |
| `FilterChain`, `FilterDeclare`, `FilterProvider` (mod_filter) | MW | Map to PSR-15 middleware |

### nginx routing/HTTP coverage matrix (full)

Same legend.

**Virtual host & listen**

| nginx | Status | Emit |
|---|---|---|
| `server { ... }` | OK | One `App::init(host, port)` per server block; multi-app deployments run multiple PHP processes |
| `listen 80;` / `listen 443 ssl http2;` | OK | `App::init('0.0.0.0', 80)`; TLS via `ssl_cert_file`/`ssl_key_file`/`enable_http2` in `$app->run()` |
| `server_name a.com b.com;` | MW | `HostRouterMiddleware` PROPOSED — not yet built. Emit inline host-routing middleware over `$g->server['HTTP_HOST']`, or recommend one ZealPHP instance per host behind Caddy/Traefik |

**Routing (location family)**

| nginx | Status | Emit |
|---|---|---|
| `location /prefix/ { ... }` | OK | `$app->route('/prefix/...', ...)` or `nsPathRoute('prefix', ...)` |
| `location = /exact { ... }` | OK | `$app->route('/exact', ...)` |
| `location ~ \.php$ { ... }` (regex) | OK | `$app->patternRoute('.*\\.php$', ...)` |
| `location ~* \.(css|js)$` (case-insensitive) | MW | ZealPHP patterns are case-sensitive — wrap with `(?i)` flag |
| `location @named { ... }` | OK | `App::setErrorHandler(...)` covers the use case |
| `root /var/www/html;` | OK | `public/` is the convention; override via `App::documentRoot('htdocs')` |
| `index index.php index.html;` | OK | `App::directoryIndex(['index.php', 'index.html'])` |
| `try_files $uri $uri/ /index.php?$args;` | OK | Implicit router tries `public/{file}.php`, then `setFallback` handles the rest |
| `error_page 404 /custom-404.html;` | OK | `App::setErrorHandler(404, fn() => App::include('/custom-404.html'))` |
| `internal;` | OK | Not addressable from outside — covered by `setErrorHandler`/`setFallback` |

**Rewrite module**

| nginx | Status | Emit |
|---|---|---|
| `rewrite ^/old$ /new last;` (internal) | OK | `$app->route('/old', fn() => App::include('/new.php'))` |
| `rewrite ^/old$ /new break;` | OK | Same — `App::include()` returns a result; no re-match |
| `rewrite ^/old$ /new redirect;` (302) | OK | `return $response->redirect('/new', 302);` |
| `rewrite ^/old$ /new permanent;` (301) | OK | `return $response->redirect('/new', 301);` |
| `return 301 https://$host$request_uri;` | OK | `return $response->redirect('https://...', 301);` or middleware (Recipe G) |
| `return 444;` (close connection) | NS | Refuse — no clean equivalent |
| `return 200 "OK\n";` (inline body) | OK | `return "OK\n";` (universal return contract) |
| `if ($http_user_agent ~ MSIE) { ... }` | PHP | `if (preg_match('/MSIE/', $g->server['HTTP_USER_AGENT']))` |
| `if (-f $request_filename) { ... }` | PHP | `if (file_exists(App::$cwd . '/public/' . $path))` |

**Request body / headers**

| nginx | Status | Emit |
|---|---|---|
| `client_max_body_size 100m;` | OK | `'package_max_length' => 100 * 1024 * 1024` in `$app->run()` |
| `client_header_buffer_size`, `large_client_header_buffers` | MW | OpenSwoole `'http_header_buffer_size'` and similar |

**Response transmission**

| nginx | Status | Emit |
|---|---|---|
| `sendfile on;` | OK | Built-in: `$response->sendFile()` uses kernel sendfile |
| `tcp_nopush on;` | OK | OpenSwoole socket option (default sensible) |
| `tcp_nodelay on;` | OK | `'open_tcp_nodelay' => true` |
| `keepalive_timeout 75s;` | OK | `'keepalive_timeout' => N` |

**Content types & caching**

| nginx | Status | Emit |
|---|---|---|
| `types { ... }`, `default_type ...;` | OK | `$app->addMiddleware(new MimeTypeMiddleware(['wasm' => 'application/wasm', ...]));` (built-in, v0.2.21+) for handler-generated bodies; OpenSwoole static handler has its own MIME map for static files |
| `open_file_cache` | OK | OpenSwoole has built-in static-file caching with `enable_static_handler` |
| `expires 30d;` | OK | `$app->addMiddleware(new ExpiresMiddleware([], '+30 days'));` or `new CacheControlMiddleware()` (both built-in, v0.2.21+) |
| `etag on;` | OK | `ETagMiddleware` (built-in) |
| `gzip on;` / `gzip_types ...;` | OK | OpenSwoole `http_compression` |

**Logging**

| nginx | Status | Emit |
|---|---|---|
| `access_log /var/log/access.log combined;` | OK | `access_log()` in `src/utils.php`; configurable via `ZEALPHP_*` env vars |
| `error_log /var/log/error.log warn;` | OK | `elog()` / `zlog()` |
| `log_format custom "...";` | MW | Fixed format today; custom format is a known gap |

**Rate limiting**

| nginx | Status | Emit |
|---|---|---|
| `limit_rate 50k;` (bandwidth) | MW | Inline middleware: `$response->write($chunk); \OpenSwoole\Coroutine::sleep($delay);` |
| `limit_req zone=one burst=5;` | MW | `RateLimitMiddleware` PROPOSED — not yet built. Inline sliding-window in `Store` |
| `limit_conn zone=one 10;` | MW | `ConcurrencyLimitMiddleware` PROPOSED — not yet built. Inline using `Counter` |
| `limit_except GET POST { deny all; }` | OK | Route `methods` array |

**Auth**

| nginx | Status | Emit |
|---|---|---|
| `auth_basic "Realm";` + `auth_basic_user_file htpasswd;` | MW | `BasicAuthMiddleware` PROPOSED — not yet built. Inline implementation (see BasicAuth template) |
| `satisfy any;` / `satisfy all;` | MW | Auth-middleware composition (inline, gated on BasicAuthMiddleware shipping) |

**Proxy / FastCGI**

| nginx | Status | Emit |
|---|---|---|
| `proxy_pass http://backend;` | MW | `ProxyMiddleware` PROPOSED — not yet built. Recommend front proxy, or inline a small forwarder using OpenSwoole HTTP client |
| `fastcgi_pass unix:/run/php-fpm.sock;` | OK | N/A — DROP ENTIRELY. ZealPHP IS the PHP runtime. |
| `proxy_set_header X-Real-IP $remote_addr;` | OK | N/A unless proxying; receiving side reads `$g->server['HTTP_X_REAL_IP']` |

**SSL / TLS**

| nginx | Status | Emit |
|---|---|---|
| `ssl_certificate`, `ssl_certificate_key` | OK | `'ssl_cert_file'`, `'ssl_key_file'` in `$app->run()` |
| `ssl_protocols`, `ssl_ciphers` | OK | OpenSwoole `'ssl_protocols'`, `'ssl_ciphers'` |
| `add_header Strict-Transport-Security ...;` | OK | `$app->addMiddleware(new HeaderMiddleware(['set' => ['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains']]));` (built-in, v0.2.21+) |

**Misc**

| nginx | Status | Emit |
|---|---|---|
| `merge_slashes on;` | PHP | Middleware: `$g->server['REQUEST_URI'] = preg_replace('#/+#', '/', $g->server['REQUEST_URI']);` |
| `server_tokens off;` | OK | No Server header by default |
| `chunked_transfer_encoding on;` | OK | OpenSwoole handles chunked for streaming responses |
| `early_hints` (103) | NS | Not implemented; refuse with note |
| `stream { ... }` (Layer-4 proxy) | NS | Out of protocol scope — use dedicated L4 proxy |
| `mail { ... }` (SMTP/IMAP proxy) | NS | Out of protocol scope |
| `grpc_pass` | NS | Out of scope |

### KNOWN LIMITATIONS — refuse explicitly, never emit silent-broken code

When the input config uses any of these features, the agent MUST emit an
explicit `// (NOT SUPPORTED)` comment in the output explaining what was
dropped and why. Silently dropping a directive is worse than refusing it
because the user can't tell what happened.

**Refuse template** — copy this shape (substitute the directive name and reason):

```
// (NOT SUPPORTED): Server-Side Includes (Options +Includes / XBitHack / .shtml).
//                  ZealPHP has no SSI runtime. SSI was Apache's pre-PHP
//                  templating system; modern PHP apps use templates instead.
//                  Migrate <!--#include --> to App::include() or App::render().
//                  See https://php.zeal.ninja/legacy-apps#limitations
```

The fenced list of refuse-categories:

| Feature | Refuse with this rationale |
|---|---|
| Server-Side Includes (SSI) — `Options +Includes`, `XBitHack`, `.shtml`, `<!--#include-->`, `SSIErrorMsg`, `SSITimeFormat`, `SSIUndefinedEcho`, `AddHandler server-parsed` | No SSI runtime; use `App::render()` / `App::include()` |
| mod_speling — `CheckSpelling`, `CheckCaseOnly`, `CheckBasenameMatch` | Security-questionable (cache pollution / info disclosure); send a real 404 |
| mod_imagemap — `ImapBase`, `ImapDefault`, `ImapMenu` | Dead tech (~1995); browsers do client-side imagemaps |
| mod_dav (WebDAV) — `Dav On`, `DavMinTimeout`, PROPFIND/MKCOL | Different protocol scope; use a real WebDAV server |
| mod_perl, mod_python, mod_ruby | Different runtimes; run them out-of-process |
| mod_isapi | Windows IIS-only; OpenSwoole is Linux-first |
| mod_lua hooks — `LuaHookAccessChecker`, `LuaMapHandler`, etc. | Use PSR-15 middleware instead |
| CERN meta files — `MetaDir`, `MetaFiles`, `MetaSuffix` | Dead (~1996); use `Header` directives via inline middleware |
| mod_status, mod_info — server-info / server-status | Not built in; roll a `/metrics` route |
| mod_proxy_balancer (load balancing) | Out of scope; use HAProxy / Nginx / Caddy |
| AuthLDAP* (LDAP authentication) | Use PHP `ldap` extension directly in custom middleware |
| AuthDigest* (HTTP Digest Auth) | Largely retired; use HTTPS + Basic + Bearer tokens |
| `<Limit>` for 1xx/2xx/3xx ErrorDocument | Apache itself limits ErrorDocument to 4xx/5xx — same here |
| Apache mod_session (server-side session API distinct from PHP sessions) | Use PHP-native sessions (`Session` family) |
| `Anonymous*` auth | Niche; refuse |
| mod_substitute body rewriting | Not built-in; inline `BodyRewriteMiddleware` on demand |
| `stream { ... }` (nginx L4) | Out of protocol scope |
| `mail { ... }` (nginx SMTP/IMAP) | Out of protocol scope |
| `grpc_pass` (nginx gRPC) | Out of scope |
| `early_hints` (HTTP 103) | Not implemented; defer |
| `directio` (O_DIRECT) | OpenSwoole doesn't expose; rely on filesystem cache |
| `recursive_error_pages` | Not native; handlers can manually invoke other handlers |
| `return 444;` (nginx close-without-response) | No clean equivalent in HTTP semantics |

### Built-in middleware emissions (v0.2.21+) — emit the class, NOT inline anonymous

As of v0.2.21, these middlewares ship in `src/Middleware/`. The bot MUST emit
the built-in class instantiation, NOT the old inline-anonymous-class pattern,
and MUST NOT prepend a `// PROPOSED:` comment. Wrong-shape emissions break
user code at boot or pile up unnecessary scaffolding.

**`HeaderMiddleware`** — for top-level `Header set` (Apache) / `add_header` (nginx):

```php
// Apache `Header set X-Frame-Options "DENY"` + `Header set X-Content-Type-Options "nosniff"` + ...
// nginx     `add_header X-Frame-Options DENY` + `add_header X-Content-Type-Options nosniff` + ...
$app->addMiddleware(new \ZealPHP\Middleware\HeaderMiddleware([
    'set' => [
        'X-Frame-Options'         => 'DENY',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
    ],
    // 'append' => ['Vary' => 'Accept-Encoding'],
    // 'unset'  => ['Server', 'X-Powered-By'],
]));
```

Collect ALL top-level Header set / add_header directives into ONE
HeaderMiddleware call. `set` overwrites; `add` (multi-value, e.g. Set-Cookie)
emits one line per value; `append` joins comma-separated (mod_headers
`Header append`); `unset` strips. Skip CORS headers — those still go to
`CorsMiddleware`.

**`CharsetMiddleware`** — for `AddDefaultCharset utf-8` / `AddCharset utf-8 .css .js ...`:

```php
// Apache `AddDefaultCharset utf-8`
$app->addMiddleware(new \ZealPHP\Middleware\CharsetMiddleware('utf-8'));

// Apache `AddDefaultCharset iso-8859-1`
$app->addMiddleware(new \ZealPHP\Middleware\CharsetMiddleware('iso-8859-1'));
```

Appends `; charset=<charset>` to text-ish Content-Types (text/*, application/json,
application/xml, image/svg+xml, …) when the response doesn't already declare one.
Binary types (image/png, application/octet-stream, …) are left untouched.

**`CacheControlMiddleware`** — for `<FilesMatch ...> Header set Cache-Control ...` /
nginx `expires 30d` block:

```php
// Apache <FilesMatch "\.(css|js|jpe?g|png|gif|svg|ico|woff2?)$">
//           Header set Cache-Control "max-age=2628000, public"
//       </FilesMatch>
// nginx   location ~* \.(css|js|jpe?g|png|gif|svg|ico|woff2?)$ { expires 30d; }
$app->addMiddleware(new \ZealPHP\Middleware\CacheControlMiddleware());
// Defaults: 30 days for css/js/mjs/jpg/jpeg/png/gif/webp/avif/svg/ico/woff/woff2/ttf/eot/otf/wasm.

// Custom map (1y for fingerprinted assets, 5m for HTML):
$app->addMiddleware(new \ZealPHP\Middleware\CacheControlMiddleware([
    'css' => 31536000, 'js' => 31536000, 'woff2' => 31536000,
    'html' => 300,
]));

// Private cache (per-user assets — intermediate caches must not store):
$app->addMiddleware(new \ZealPHP\Middleware\CacheControlMiddleware(null, false));
```

Constructor: `(?array $map = null, bool $publicCache = true)`. `$map` is
`ext => max-age-seconds`; pass `null` for sensible defaults. Skips if the
response already has `Cache-Control` set, and only fires when the URL path
ends in a recognised extension.

**`ExpiresMiddleware`** — for Apache `ExpiresActive` / `ExpiresByType`:

```php
// Apache ExpiresActive On
//        ExpiresByType image/jpeg "access plus 1 month"
//        ExpiresByType text/css   "access plus 1 year"
//        ExpiresDefault           "access plus 5 minutes"
$app->addMiddleware(new \ZealPHP\Middleware\ExpiresMiddleware(
    [
        'image/'           => '+30 days',
        'text/css'         => '+1 year',
        'text/javascript'  => '+1 year',
        'font/'            => '+1 year',
    ],
    '+5 minutes',  // ExpiresDefault — pass null to skip when no prefix matches
));
```

Constructor: `(array $byType = [], ?string $default = null)`. Matches by
Content-Type *prefix* (longest-first for determinism). Values are
strtotime()-parsed: `'+1 year'`, `'+30 days'`, `'+86400 seconds'`. Often
paired with `CacheControlMiddleware`; emit both when Apache config used
`ExpiresByType` AND `<FilesMatch> Header set Cache-Control` patterns.

**`IpAccessMiddleware`** — for Apache `Allow from`/`Deny from`/`Order` (2.2 syntax)
or `Require ip ...` (2.4+) or nginx `allow`/`deny`:

```php
// Apache 2.2: Order Deny,Allow / Deny from all / Allow from 10.0.0.0/8 127.0.0.1
// Apache 2.4+: Require ip 10.0.0.0/8 127.0.0.1
// nginx:      allow 10.0.0.0/8; allow 127.0.0.1; deny all;
$app->addMiddleware(new \ZealPHP\Middleware\IpAccessMiddleware([
    'allow' => ['10.0.0.0/8', '127.0.0.1'],
    'deny'  => [],
]));

// Deny-list mode (allow everyone except specific abusers):
$app->addMiddleware(new \ZealPHP\Middleware\IpAccessMiddleware([
    'allow' => ['*'],
    'deny'  => ['1.2.3.4', '203.0.113.0/24'],
]));
```

Resolution: deny matches → 403 (deny wins ties); else if `allow` non-empty
and doesn't match → 403; else pass-through. Supports IPv4/IPv6 + CIDR
(`10.0.0.0/8`, `2001:db8::/32`). Reads `$g->server['REMOTE_ADDR']` —
behind a proxy this is the proxy IP, not the real client. Document the
caveat in a comment when emitting behind-proxy.

**`MimeTypeMiddleware`** — for `AddType application/wasm .wasm` (handler-generated bodies):

```php
// Apache AddType application/wasm           .wasm
//        AddType model/gltf-binary          .glb
//        AddType model/vnd.usdz+zip         .usdz
$app->addMiddleware(new \ZealPHP\Middleware\MimeTypeMiddleware([
    'wasm' => 'application/wasm',
    'glb'  => 'model/gltf-binary',
    'usdz' => 'model/vnd.usdz+zip',
]));
```

Sets Content-Type by URL extension only when the upstream response has no
Content-Type set. Explicit `$response->header('Content-Type', ...)` calls in
the handler always win. For files served by OpenSwoole's static handler,
the static handler's own MIME map is the right knob — this middleware is for
PHP handlers emitting raw bytes for custom file types.

**`BlockPhpExtMiddleware`** — for Apache `RewriteRule ... \.php [R=404]` or nginx
`location ~ \.php$ { return 404; }`:

```php
// Apache:  RewriteCond %{THE_REQUEST} ^[A-Z]{3,}\s([^.]+)\.php [NC]
//          RewriteRule ^ - [R=404,L]
// nginx:   location ~ \.php$ { return 404; }
$app->addMiddleware(new \ZealPHP\Middleware\BlockPhpExtMiddleware());
```

No constructor args. Refuses any request whose URL path matches `\.php(\?|$)`
with a 404. Pair with `App::ignorePhpExt(true)` for the full "extensionless
URLs only" stance.

### Middleware-gap inline templates (PROPOSED — still pending)

When the input needs middleware that ISN'T yet built into ZealPHP, emit BOTH
(a) the inline anonymous-class implementation AND (b) a `// PROPOSED:` comment
naming the proposed `*Middleware` class so the user knows the gap is tracked.
The remaining PROPOSED gaps as of v0.2.21:

- `BasicAuthMiddleware` (Apache `AuthType Basic` / nginx `auth_basic`)
- `RateLimitMiddleware` (nginx `limit_req`)
- `ConcurrencyLimitMiddleware` (nginx `limit_conn`)
- `HostRouterMiddleware` (nginx `server_name a.com b.com` multi-host routing)
- `BodyRewriteMiddleware` (Apache `mod_substitute`)
- `ProxyMiddleware` (nginx `proxy_pass`)

**BasicAuth template** (for `auth_basic` / `AuthType Basic`):

```php
// nginx auth_basic "Realm"; / Apache AuthType Basic equivalent.
// PROPOSED: BasicAuthMiddleware — not yet shipped in ZealPHP. Inline implementation:
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Basic ')) {
            return (new \OpenSwoole\Core\Psr\Response('Authentication required'))
                ->withStatus(401)
                ->withHeader('WWW-Authenticate', 'Basic realm="Realm"');
        }
        // Verify against htpasswd / DB / callback — placeholder:
        [$user, $pass] = explode(':', base64_decode(substr($auth, 6)), 2) + [null, null];
        // if (!verifyCredentials($user, $pass)) { ...same 401 response... }
        return $handler->handle($request);
    }
});
```

Same inline-anonymous-middleware shape for the other PROPOSED entries
above. NEVER emit `new BasicAuthMiddleware(...)` or
`new RateLimitMiddleware(...)` etc. as a class instantiation — those
classes don't exist in `src/Middleware/` yet, and the user's app.php
would fatal at boot.

### Legacy App Mode (WordPress, Drupal, etc.)

ONLY enable for apps that cannot be refactored:

```php
App::superglobals(true);    // $_GET, $_POST, $_SESSION populated (CGI subprocess)
App::ignorePhpExt(false);   // allow .php in URLs (/wp-login.php)
```

In subprocess mode (`superglobals(true)`), each `App::include()` runs the file
in a separate process with full global-scope isolation — like Apache's prefork
MPM. ONLY use for legacy apps; coroutine mode is faster and now the default
for new projects.

### Middleware (built-in, v0.2.21+)

```php
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Middleware\SessionStartMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Middleware\CharsetMiddleware;
use ZealPHP\Middleware\CacheControlMiddleware;
use ZealPHP\Middleware\ExpiresMiddleware;
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\Middleware\MimeTypeMiddleware;
use ZealPHP\Middleware\BlockPhpExtMiddleware;

$app->addMiddleware(new CorsMiddleware(['*']));
$app->addMiddleware(new ETagMiddleware());
$app->addMiddleware(new RangeMiddleware());
$app->addMiddleware(new SessionStartMiddleware());
$app->addMiddleware(new HeaderMiddleware(['set' => ['X-Frame-Options' => 'DENY']]));
$app->addMiddleware(new CharsetMiddleware('utf-8'));
$app->addMiddleware(new CacheControlMiddleware());
$app->addMiddleware(new ExpiresMiddleware(['image/' => '+30 days'], '+5 minutes'));
$app->addMiddleware(new IpAccessMiddleware(['allow' => ['10.0.0.0/8'], 'deny' => []]));
$app->addMiddleware(new MimeTypeMiddleware(['wasm' => 'application/wasm']));
$app->addMiddleware(new BlockPhpExtMiddleware());
```

**Constructor signatures (verified against `src/Middleware/`)**:
- `HeaderMiddleware(array $config = [])` — keys: `set`, `add`, `append`, `unset`
- `CharsetMiddleware(string $charset = 'utf-8')`
- `CacheControlMiddleware(?array $map = null, bool $publicCache = true)` — `$map` is `ext => seconds`
- `ExpiresMiddleware(array $byType = [], ?string $default = null)` — `$byType` is `CT-prefix => relative-date`
- `IpAccessMiddleware(array $config = [])` — keys: `allow`, `deny` (string[] of literals or CIDR)
- `MimeTypeMiddleware(array $map = [])` — `ext => mime-type`
- `BlockPhpExtMiddleware()` — no constructor args

### What OpenSwoole Handles Automatically (DO NOT convert these)

- Static file serving (CSS, JS, images, fonts) — `enable_static_handler` is on by default
- Directory index (`index.php`) — built-in implicit routes
- Gzip compression — `http_compression` is on by default
- Directory listing prevention — basic listing is opt-in via `App::autoindex(true)` (when shipped); off by default
- PHP file handling — ZealPHP IS the PHP runtime, no FastCGI/PHP-FPM

### What Belongs to a Reverse Proxy (DO NOT convert; ONE-LINE comment)

- SSL termination / HTTPS redirect (Recipe G if you must inline it)
- proxy_pass / reverse proxy (recommend Caddy / Traefik / nginx in front)
- ModPagespeed
- DDoS / rate limiting at the edge

### Server Options (passed to `$app->run()`)

```php
$app->run([
    'task_worker_num'    => 0,                       // task workers (default 0)
    'worker_num'         => 4,                       // HTTP workers
    'package_max_length' => 512 * 1024 * 1024,       // max request size (replaces upload_max_filesize)
    'ssl_cert_file'      => '/path/cert.pem',
    'ssl_key_file'       => '/path/key.pem',
    'enable_http2'       => true,                    // HTTP/2 (requires SSL)
]);
```

### `patternRoute()` — raw regex routes

When the URL shape doesn't fit `{param}` placeholders (file-extension patterns,
trailing-slash, security-deny rules), use `patternRoute`. The argument is a raw
regex (no implicit anchoring), invoked when the request URI matches.

```php
// Block dotfiles and log files
$app->patternRoute('/.*\\.(env|log|git|htaccess).*', function() { return 403; });

// Strip trailing slashes (registered AFTER explicit routes so it doesn't shadow /dir/)
$app->patternRoute('/(.+)/$', function($response) {
    $g = RequestContext::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    return $response->redirect($path, 301);
});
```

### Method-restricted routes

```php
$app->route('/form', ['methods' => ['POST']], function() {
    return 'received';
});

$app->route('/api/users/{id}', ['methods' => ['GET', 'PUT', 'DELETE']], function($id) {
    // ...
});
```

If `methods` is omitted, the route accepts GET (and HEAD implicitly).

### `composer.json` — pin the framework

When generated output suggests a `composer.json` install line, use:

    composer require sibidharan/zealphp:^0.2.21

(Bump the floor when a newer release lands; pinned in the prompt for now.)
"""


FEW_SHOT_EXAMPLES = r"""
## Conversion Examples (canonical shapes — copy idiomatically)

### Example 1: WordPress .htaccess -> app.php (LEGACY CMS / front-controller)

INPUT:
```
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
```

OUTPUT:
```php
<?php
// Detected: WordPress
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

// Migration: move public_html/* into the public/ folder.
// WordPress is a front-controller app — every unmatched URL flows through public/index.php.

App::superglobals(true);     // WordPress reads $_GET / $_POST / $_SESSION directly
App::ignorePhpExt(false);    // allow .php URLs like /wp-login.php

$app = App::init('0.0.0.0', 8080);

$app->setFallback(fn() => App::include('/index.php'));

$app->run(['task_worker_num' => 0]);
```

WHY: WordPress routes everything through `index.php`. `setFallback()` replaces the catch-all
`RewriteRule`. `superglobals(true)` + `ignorePhpExt(false)` is required because WordPress
reads `$_GET` / `$_POST` / `$_SESSION` directly and uses `.php` URLs like `/wp-login.php`.
`App::include('/index.php')` honors the universal return contract — see
/responses#return-contract.

### Example 2: LAMP app with parameterized rewrites -> app.php (parameterized-rewrites flavor of LEGACY APP)

INPUT:
```
RewriteEngine on
RewriteBase /

RewriteRule ^/?qn/([^/]+)?$ "qn.php?id=$1" [L,QSA]
RewriteRule ^/?watch/([^/]+)?$ "watch.php?v=$1" [L,QSA]
RewriteRule ^/?_/([^/]+)/([^/]+)?$ "_data.php?switch=$1&query=$2" [L,QSA]
RewriteRule ^/?account/([^/]+)?$ "account.php?id=$1" [L,QSA]
RewriteRule ^/?account/([^/]+)/([^/]+)?$ "account.php?id=$1&sid=$2" [L,QSA]
RewriteRule ^/?api/([^/]+)?$ "api.php?rquest=$1" [L,QSA]
RewriteRule ^/?api/([^/]+)/([^/]+)?$ "api.php?rquest=$2&ns=$1" [L,QSA]

# Profile catch-all (must be last)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)/?$ "profile.php?username=$1" [QSA,L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

// Migration: move these files into the public/ folder:
//   qn.php, watch.php, _data.php, account.php, api.php, profile.php
// Files in public/ are auto-served at their base name (public/qn.php -> /qn).
// The routes below add the parameterized variants (e.g. /qn/{id}).
// Return values flow through the universal contract — see /responses#return-contract.

App::superglobals(true);     // legacy *.php files keep reading $_GET unchanged
App::ignorePhpExt(false);    // allow .php URLs for legacy links

$app = App::init('0.0.0.0', 8080);

$app->route('/qn/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/qn.php');
});

$app->route('/watch/{v}', function($v) {
    $g = RequestContext::instance();
    $g->get['v'] = $v;
    // legacy: $_GET['v'] = $v;
    return App::include('/watch.php');
});

$app->route('/_/{switch}/{query}', function($switch, $query) {
    $g = RequestContext::instance();
    $g->get['switch'] = $switch;
    $g->get['query']  = $query;
    // legacy: $_GET['switch'] = $switch; $_GET['query'] = $query;
    return App::include('/_data.php');
});

$app->route('/account/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/account.php');
});

$app->route('/account/{id}/{sid}', function($id, $sid) {
    $g = RequestContext::instance();
    $g->get['id']  = $id;
    $g->get['sid'] = $sid;
    // legacy: $_GET['id'] = $id; $_GET['sid'] = $sid;
    return App::include('/account.php');
});

$app->route('/api/{rquest}', function($rquest) {
    $g = RequestContext::instance();
    $g->get['rquest'] = $rquest;
    // legacy: $_GET['rquest'] = $rquest;
    return App::include('/api.php');
});

// Note: target captures are reordered ($2 first, $1 as ns) — preserved in $g->get.
$app->route('/api/{ns}/{rquest}', function($ns, $rquest) {
    $g = RequestContext::instance();
    $g->get['rquest'] = $rquest;
    $g->get['ns']     = $ns;
    // legacy: $_GET['rquest'] = $rquest; $_GET['ns'] = $ns;
    return App::include('/api.php');
});

// Catch-all: unmatched single-segment URLs -> profile.php?username=<segment>
$app->setFallback(function() {
    $g = RequestContext::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    // legacy: $_GET['username'] = $username;
    return App::include('/profile.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: Each parameterized rewrite becomes a route that (a) populates `$g->get` via
`RequestContext::instance()` and (b) returns `App::include('/<target>.php')`.
Public-relative paths only — no `App::$cwd . '/public/'` prefix; the framework
sets `$_SERVER['PHP_SELF']` / `SCRIPT_NAME` / `SCRIPT_FILENAME` automatically.
`return` flows through the universal contract: status, JSON, HTML, Generator —
whatever the included file returns is honoured.

### Example 3: Redirect rules -> app.php

INPUT:
```
RewriteRule ^old-page$ /new-page [R=301,L]
RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

$app = App::init('0.0.0.0', 8080);

// [R=301,L] => permanent external redirect — use $response->redirect()
$app->route('/old-page', fn($response) => $response->redirect('/new-page', 301));

// [R=302,L] => temporary external redirect — same helper, different status
$app->route('/blog/{slug}', fn($slug, $response) => $response->redirect("/articles/{$slug}", 302));

// HTTPS redirect: prefer terminating TLS in a front proxy (Caddy / Traefik / nginx).
// If you must inline it, see Recipe G in the reference for the middleware shape.

$app->run(['task_worker_num' => 0]);
```

WHY: Every input rule carries `[R=...]` — explicit external redirect. Each becomes
`$response->redirect($url, $status)`, which sets `Location` and the right status.
HTTPS redirect belongs at the edge in most deployments.

### Example 4: Complex .htaccess with mixed directives -> app.php

INPUT:
```
<IfModule php7_module>
php_value upload_max_filesize 512M
php_value post_max_size 512M
</IfModule>

ServerSignature Off
Options -Indexes

<IfModule pagespeed_module>
ModPagespeed off
</IfModule>

AddDefaultCharset utf-8
AddCharset utf-8 .atom .css .js .json .rss .vtt .xml

Header set Access-Control-Allow-Origin "*"
Header set X-Frame-Options "DENY"

<FilesMatch ".(css|jpg|jpeg|png|gif|js|ico|woff|woff2|svg)$">
    Header set Cache-Control "max-age=2628000, public"
</FilesMatch>

RewriteEngine on
RewriteBase /

RewriteRule ^/?user/([^/]+)?$ "user.php?id=$1" [L,QSA]
RewriteRule ^/?search/([^/]+)?$ "search.php?q=$1" [L,QSA]

RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^/]+)/?$ "profile.php?username=$1" [QSA,L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Middleware\CharsetMiddleware;
use ZealPHP\Middleware\CacheControlMiddleware;

// Migration: move these files into the public/ folder:
//   user.php, search.php, profile.php
// Files in public/ are auto-served at their base name (public/user.php -> /user).
// Dropped (handled by OpenSwoole or no-ops in ZealPHP): ServerSignature, Options -Indexes,
// ModPagespeed.

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

// CORS — built-in middleware
$app->addMiddleware(new CorsMiddleware(['*']));

// AddDefaultCharset utf-8 + AddCharset utf-8 .atom .css .js .json .rss .vtt .xml
$app->addMiddleware(new CharsetMiddleware('utf-8'));

// Header set X-Frame-Options "DENY"  (CORS headers already covered by CorsMiddleware)
$app->addMiddleware(new HeaderMiddleware([
    'set' => [
        'X-Frame-Options' => 'DENY',
    ],
]));

// <FilesMatch ".(css|jpg|jpeg|png|gif|js|ico|woff|woff2|svg)$">
//     Header set Cache-Control "max-age=2628000, public"
// </FilesMatch>
// Defaults already cover css/js/jpg/jpeg/png/gif/svg/ico/woff/woff2 at 2_628_000s (~30d, public).
$app->addMiddleware(new CacheControlMiddleware());

$app->route('/user/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/user.php');
});

$app->route('/search/{q}', function($q) {
    $g = RequestContext::instance();
    $g->get['q'] = $q;
    // legacy: $_GET['q'] = $q;
    return App::include('/search.php');
});

// Catch-all: unmatched single-segment URLs -> profile.php?username=<segment>
$app->setFallback(function() {
    $g = RequestContext::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    // legacy: $_GET['username'] = $username;
    return App::include('/profile.php');
});

$app->run([
    'task_worker_num'    => 0,
    'package_max_length' => 512 * 1024 * 1024,
]);
```

WHY: parameterized rewrites -> route + `App::include()` + `$g->get` (with
legacy comment). CORS -> `CorsMiddleware`. `Header set X-Frame-Options` ->
`HeaderMiddleware(['set' => [...]])` (built-in, v0.2.21+). `AddDefaultCharset
utf-8` -> `CharsetMiddleware('utf-8')`. The `<FilesMatch>` cache rule ->
`CacheControlMiddleware()` (defaults cover the listed extensions).
`upload_max_filesize` -> `package_max_length`. ServerSignature / Options /
ModPagespeed are no-ops / not applicable — ONE comment notes them.

### Example 5: nginx CMS config -> app.php

INPUT:
```
server {
    listen 80;
    server_name example.com;
    root /var/www/html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
    location ~* \.(css|js|png|jpg|gif|ico)$ {
        expires 30d;
    }
}
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

// Migration: move /var/www/html into public/.
// Dropped: location ~ \.php$ { fastcgi_pass ... } — ZealPHP IS the PHP runtime, no FastCGI.
// Dropped: expires 30d — belongs to a front proxy or future CacheControlMiddleware.

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

$app->setFallback(fn() => App::include('/index.php'));

$app->run(['task_worker_num' => 0]);
```

WHY: `try_files` with `/index.php` fallback is the CMS front-controller pattern.
This is a legacy migration — `superglobals(true)` + `setFallback()` +
`App::include('/index.php')`.

### Example 6: Laravel public/.htaccess -> app.php (DETECTED FRAMEWORK)

INPUT:
```
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
```

OUTPUT:
```php
<?php
// Detected: Laravel
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

// Migration: Laravel front-controller. All requests flow through public/index.php.
// Static files in public/ are auto-served by OpenSwoole.
//
// Dropped: RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
//   This Apache+PHP-FPM workaround is unnecessary — ZealPHP exposes the Authorization
//   header natively via $_SERVER['HTTP_AUTHORIZATION'] (populated from OpenSwoole headers).
//
// Note: each request runs Laravel's bootstrap inside a CGI subprocess in superglobals mode.
// For warm-start, consider App::onWorkerStart() to preload the framework, or Laravel Octane.

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

// Trailing-slash strip (mirror the Laravel .htaccess rule).
$app->patternRoute('/(.+)/$', function($response) {
    $g = RequestContext::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    return $response->redirect($path, 301);
});

$app->setFallback(fn() => App::include('/index.php'));

$app->run(['task_worker_num' => 0]);
```

WHY: Laravel is a front-controller framework. The HTTP_AUTHORIZATION env-var rule
is a PHP-FPM workaround ZealPHP doesn't need — drop it with an explanatory
comment. The trailing-slash rule becomes an explicit `patternRoute` registered
before the fallback so it can take precedence.

### Example 7: nginx LAMP-style with rewrite blocks

INPUT:
```
server {
    listen 80;
    server_name app.example.com;
    root /var/www/html;
    index index.php;

    client_max_body_size 100M;
    gzip on;
    gzip_types text/plain text/css application/javascript application/json;

    add_header X-Frame-Options "DENY";
    add_header X-Content-Type-Options "nosniff";

    location ~* ^/qn/([^/]+)?$ {
        rewrite ^/qn/(.+)$ /qn.php?id=$1 last;
    }

    location ~* ^/watch/([^/]+)?$ {
        rewrite ^/watch/(.+)$ /watch.php?v=$1 last;
    }

    location ~* ^/api/([^/]+)/([^/]+)?$ {
        rewrite ^/api/([^/]+)/(.+)$ /api.php?ns=$1&rquest=$2 last;
    }

    location / {
        try_files $uri $uri/ /profile.php?username=$uri;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
}
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\HeaderMiddleware;

// Migration: move these files into the public/ folder:
//   qn.php, watch.php, api.php, profile.php
// Dropped: gzip on (OpenSwoole http_compression handles it), index directive (covered by
//   App::directoryIndex default), location ~ \.php$ { fastcgi_pass ... } (ZealPHP IS the
//   PHP runtime — no FastCGI).

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

// Two top-level `add_header` directives -> HeaderMiddleware (built-in, v0.2.21+).
$app->addMiddleware(new HeaderMiddleware([
    'set' => [
        'X-Frame-Options'        => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
    ],
]));

$app->route('/qn/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/qn.php');
});

$app->route('/watch/{v}', function($v) {
    $g = RequestContext::instance();
    $g->get['v'] = $v;
    // legacy: $_GET['v'] = $v;
    return App::include('/watch.php');
});

$app->route('/api/{ns}/{rquest}', function($ns, $rquest) {
    $g = RequestContext::instance();
    $g->get['ns']     = $ns;
    $g->get['rquest'] = $rquest;
    // legacy: $_GET['ns'] = $ns; $_GET['rquest'] = $rquest;
    return App::include('/api.php');
});

// try_files $uri ... /profile.php?username=$uri -> setFallback that delegates to profile.php
$app->setFallback(function() {
    $g = RequestContext::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    // legacy: $_GET['username'] = $username;
    return App::include('/profile.php');
});

$app->run([
    'task_worker_num'    => 0,
    'package_max_length' => 100 * 1024 * 1024,
]);
```

WHY: nginx `rewrite ... last;` inside a `location` block and Apache
`RewriteRule ... [L]` produce the same migration shape. The `location ~ \.php$`
block is ZealPHP's entire reason to exist, so it's dropped with a header
comment. `client_max_body_size 100M` -> `package_max_length`. `gzip on` is
dropped (OpenSwoole `http_compression` is on by default). Two top-level
`add_header` directives collapse into one `HeaderMiddleware(['set' => [...]])`
(built-in, v0.2.21+).

### Example 8: Apache .htaccess with security blocks, headers, [F]/[G], ErrorDocument

INPUT:
```
RewriteEngine On

# Global static response headers
Header set X-Frame-Options "DENY"
Header set X-Content-Type-Options "nosniff"
Header set Referrer-Policy "strict-origin-when-cross-origin"

# Block access to sensitive files
<FilesMatch "\.(env|log|git|htaccess)$">
    Require all denied
</FilesMatch>

# Forbid private/ folder
RewriteRule ^private/.*$ - [F]

# Retired URLs return 410
RewriteRule ^old-section/.+$ - [G]

# Strip trailing slashes on non-directory URLs
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.+)/$ /$1 [L,R=301]

# Custom 404 page
ErrorDocument 404 /not-found.php

# A normal app route
RewriteRule ^/?article/([^/]+)?$ "article.php?slug=$1" [L,QSA]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\HeaderMiddleware;

// Migration: move these files into the public/ folder:
//   article.php, not-found.php

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

// Top-level Header set directives -> HeaderMiddleware (built-in, v0.2.21+).
$app->addMiddleware(new HeaderMiddleware([
    'set' => [
        'X-Frame-Options'         => 'DENY',
        'X-Content-Type-Options'  => 'nosniff',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
    ],
]));

// Security: deny access to sensitive file patterns (<FilesMatch> + Require all denied).
// Universal return contract: a bare int return becomes a status-only response.
$app->patternRoute('/.*\\.(env|log|git|htaccess).*', fn() => 403);

// [F] flag: forbid private/ tree
$app->patternRoute('/private/.*', fn() => 403);

// [G] flag: retired URLs
$app->patternRoute('/old-section/.+', fn() => 410);

// Normal app route — parameterized rewrite to article.php
$app->route('/article/{slug}', function($slug) {
    $g = RequestContext::instance();
    $g->get['slug'] = $slug;
    // legacy: $_GET['slug'] = $slug;
    return App::include('/article.php');
});

// Trailing-slash strip — register AFTER explicit routes
$app->patternRoute('/(.+)/$', function($response) {
    $g = RequestContext::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    return $response->redirect($path, 301);
});

// ErrorDocument 404 -> custom 404 fallback. Setting $g->status before App::include() lets
// the framework emit a 404 with the custom body (universal return contract handles status).
$app->setFallback(function() {
    $g = RequestContext::instance();
    $g->status = 404;
    return App::include('/not-found.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: every new pattern in one place. `<FilesMatch>` + `Require all denied` ->
`patternRoute` returning 403 (universal contract — int return becomes status).
`[F]` -> 403, `[G]` -> 410. Three top-level `Header set` directives collapse
into ONE `HeaderMiddleware(['set' => [...]])` call (built-in, v0.2.21+).
Trailing-slash strip is a `patternRoute` registered AFTER explicit routes.
`ErrorDocument 404` becomes `setFallback` with
`$g->status = 404; return App::include('/not-found.php');`.

### Example 9: REFUSAL — SSI-heavy .htaccess

INPUT:
```
Options +Includes
AddType text/html .shtml
AddHandler server-parsed .shtml

DirectoryIndex index.shtml

RewriteRule ^home$ /index.shtml [L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

// (NOT SUPPORTED): Server-Side Includes (Options +Includes / AddHandler server-parsed / .shtml).
//                  ZealPHP has no SSI runtime. SSI was Apache's pre-PHP templating system;
//                  modern PHP apps use templates instead.
//                  Migrate the .shtml file's <!--#include --> directives into PHP — call
//                  App::render() (for template/) or App::include() (for public/) at the spots
//                  where the includes were.
//                  See https://php.zeal.ninja/legacy-apps#limitations
//
// (NOT SUPPORTED): AddType text/html .shtml / AddHandler server-parsed .shtml — same reason.
//                  ZealPHP does not serve .shtml; rename to .php and convert the includes.
//
// Other directives:
//   DirectoryIndex index.shtml — would normally map to App::directoryIndex(['index.shtml']),
//     but since ZealPHP can't parse .shtml, this is a no-op until the file is converted.
//   RewriteRule ^home$ /index.shtml [L] — would map to App::include('/index.shtml'), but
//     ZealPHP cannot parse SSI in .shtml. Convert index.shtml to index.php first, then
//     emit: $app->route('/home', fn() => App::include('/index.php'));

$app = App::init('0.0.0.0', 8080);

$app->run(['task_worker_num' => 0]);
```

WHY: The input relies on a feature ZealPHP does not support. We refuse
explicitly with `// (NOT SUPPORTED)` comments and explain the migration path,
rather than silently emitting routes that would serve unparsed `.shtml` files.
This is critical for user trust — silent drops are worse than explicit refusals.

### Example 10: auth_basic — middleware gap with inline implementation

INPUT:
```
server {
    listen 80;
    server_name dashboard.example.com;
    root /var/www/dashboard;

    auth_basic           "Restricted Area";
    auth_basic_user_file /etc/nginx/.htpasswd;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
}
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

// Migration: move /var/www/dashboard into public/.
// Dropped: location ~ \.php$ { fastcgi_pass ... } — ZealPHP IS the PHP runtime.

App::superglobals(true);
App::ignorePhpExt(false);

$app = App::init('0.0.0.0', 8080);

// nginx auth_basic "Restricted Area"; auth_basic_user_file /etc/nginx/.htpasswd; equivalent.
// PROPOSED: BasicAuthMiddleware — not yet shipped in ZealPHP. Inline implementation:
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Basic ')) {
            return (new \OpenSwoole\Core\Psr\Response('Authentication required'))
                ->withStatus(401)
                ->withHeader('WWW-Authenticate', 'Basic realm="Restricted Area"');
        }
        [$user, $pass] = explode(':', base64_decode(substr($auth, 6)), 2) + [null, null];
        // Verify against /etc/nginx/.htpasswd (or DB / callback):
        //   if (!htpasswdVerify('/etc/nginx/.htpasswd', $user, $pass)) {
        //       return (new \OpenSwoole\Core\Psr\Response('Forbidden'))->withStatus(403);
        //   }
        return $handler->handle($request);
    }
});

$app->setFallback(fn() => App::include('/index.php'));

$app->run(['task_worker_num' => 0]);
```

WHY: `auth_basic` is a known framework gap. Emit the inline anonymous
middleware AND a comment naming the proposed `BasicAuthMiddleware`, so the
user can drop it in when the framework ships the middleware. Verify-credentials
is left as a placeholder block with the htpasswd lookup commented in — the
agent never invents an htpasswd parser inline.
"""


CONVERTER_INSTRUCTIONS = """You convert Apache .htaccess and nginx server configs into ZealPHP app.php files.

WORKFLOW:
1. Call get_zealphp_reference() to get the ZealPHP API reference (system prompt v""" + SYSTEM_PROMPT_VERSION + """, targets """ + ZEALPHP_VERSION + """).
2. Call get_conversion_examples() to see correct canonical conversion examples.
3. Classify the input — LEGACY CMS, LEGACY APP WITH PARAMETERIZED REWRITES, or MODERN APP (see rules below).
4. Detect any NOT-SUPPORTED directives FIRST and prepare explicit refusal comments — never silently drop.
5. Generate a COMPLETE app.php that:
   - Uses App::include('/path.php') with public-relative paths (NEVER App::includeFile, NEVER App::$cwd . '/public/' prefix).
   - Uses $g = RequestContext::instance(); $g->get['x'] = $x; for capture-to-querystring (NEVER bare $_GET in primary code path).
   - Uses fluent method form for configurables: App::superglobals(true), App::ignorePhpExt(false), App::documentRoot('public'), App::traceEnabled(false) (NEVER raw $App::$prop = ... assignment).
   - Honors the universal return contract: return 403, return ['ok'=>true], return Generator — never http_response_code() / echo json_encode() / exit().
   - For BUILT-IN middlewares (v0.2.21+): emits the class instantiation directly — `HeaderMiddleware`, `CharsetMiddleware`, `CacheControlMiddleware`, `ExpiresMiddleware`, `IpAccessMiddleware`, `MimeTypeMiddleware`, `BlockPhpExtMiddleware`, `CorsMiddleware`, `ETagMiddleware`, `RangeMiddleware`, `SessionStartMiddleware`, `CompressionMiddleware`. NEVER wrap these in inline anonymous classes — they ship and have stable constructor signatures.
   - For STILL-PROPOSED middlewares (`BasicAuthMiddleware`, `RateLimitMiddleware`, `ConcurrencyLimitMiddleware`, `HostRouterMiddleware`, `BodyRewriteMiddleware`, `ProxyMiddleware`): emits the inline anonymous PSR-15 middleware AND a `// PROPOSED: <Name>Middleware — not yet shipped` comment. NEVER emit `new BasicAuthMiddleware(...)` etc. as class instantiation — the user's app.php would fatal at boot.
   - For ❌ unsupported features: emits explicit `// (NOT SUPPORTED): ...` comments with rationale + link to /legacy-apps#limitations.
6. Call validate_conversion() with the original and your output to check for issues.
7. If issues found, fix and output the corrected version.

CLASSIFICATION RULES — this determines the entire conversion strategy.

Apply these tests in order. The FIRST match wins.

(A) LEGACY APP WITH PARAMETERIZED REWRITES — THE COMMON CASE.
    Trigger: the config contains ONE OR MORE `RewriteRule` lines with a capture group
    whose target is a `.php` file with query-string params, e.g.
    `RewriteRule ^/?qn/([^/]+)?$ "qn.php?id=$1" [L,QSA]`.
    This is the typical LAMP app — most pages are individual `*.php` files in the document
    root, and `.htaccess` parses clean URLs into query params.
    Output shape:
      - App::superglobals(true)        — legacy *.php files keep reading $_GET unchanged
      - App::ignorePhpExt(false)       — allow .php URLs for legacy links
      - One $app->route() per parameterized rewrite. Each handler:
          * uses $g = RequestContext::instance(); $g->get['<key>'] = $<key>;
            (with a // legacy: $_GET['<key>'] = $<key>; comment)
          * returns App::include('/<target>.php')   — public-relative, no prefix
      - The catch-all profile rule becomes $app->setFallback() with the same shape
      - The bare path (/qn) gets NO route — public/qn.php is auto-served already
      - The framework auto-populates $_SERVER['PHP_SELF'] / SCRIPT_NAME / SCRIPT_FILENAME
        inside App::include() — DO NOT emit the preamble in user code.

(B) LEGACY CMS (WordPress, Drupal, Laravel, Joomla, etc.).
    Trigger: the ONLY meaningful RewriteRule is the front-controller catch-all
    (`RewriteRule . /index.php [L]` or `try_files $uri /index.php`) and there are no
    parameterized rewrites that target other .php files.
    Output shape:
      - App::superglobals(true), App::ignorePhpExt(false)
      - $app->setFallback(fn() => App::include('/index.php'));
      - No per-URL routes
      - Add `// Detected: <Framework>` as the first comment line if a framework
        signature matched (WordPress / Laravel / Symfony / Drupal / CodeIgniter)

(C) MODERN APP — fallthrough only.
    Trigger: rewrites map to non-PHP handlers, or the entry is a single Slim/Laravel-style
    front controller AND the user has explicitly said they want fresh handlers.
    Output shape:
      - $app->route() with {param} syntax and inline handler logic (no App::include)
      - DO NOT use superglobals(true)

If the config has parameterized .php rewrites mixed with a /index.php catch-all
(common in WordPress-on-custom-permalinks), prefer (A) and emit setFallback() to index.php
in addition to per-rewrite routes.

THE MOST IMPORTANT RULES:

RULE 1 — ALWAYS START WITH A FILE-BY-FILE MIGRATION HEADER:
The output MUST begin with a comment block listing EVERY distinct .php file referenced
as a rewrite target (and index.php if the fallback uses it). Example:

// Migration: move these files into the public/ folder:
//   qn.php, watch.php, account.php, _data.php, contents.php, video.php,
//   api.php, help.php, profile.php, index.php
// Files in public/ are auto-served at their base name (public/qn.php -> /qn).
// The routes below add the parameterized variants (e.g. /qn/{id}).

RULE 2 — ONLY CREATE ROUTES FOR PARAMETERIZED URLs:
RewriteRules with capture groups like `^/?qn/([^/]+)?$` need routes because the URL
has a parameter. A plain RewriteRule mapping `/qn` -> `qn.php` does NOT need a route
because public/qn.php is auto-served at /qn.

RULE 3 — IN MODE (A), ROUTE HANDLERS MUST DELEGATE VIA App::include():
The whole point of mode (A) is to keep legacy *.php files running unchanged.
Each parameterized-rewrite route handler MUST follow this template (note the
`return` — flows through the universal contract; note the public-relative path
— Apache DocumentRoot convention; note the legacy comment — teaches the rule):

   $app->route('/<path>/{<key1>}/{<key2>}', function($<key1>, $<key2>) {
       $g = RequestContext::instance();
       $g->get['<key1>'] = $<key1>;
       $g->get['<key2>'] = $<key2>;
       // legacy: $_GET['<key1>'] = $<key1>; $_GET['<key2>'] = $<key2>;
       return App::include('/<target>.php');
   });

Per-rewrite generation rule. For each rule of the form
`RewriteRule ^/?<path>/(...)/?$ "<target>.php?<keyA>=$1&<keyB>=$2" [L,QSA]`:
  1. Use the QUERY-STRING KEY NAMES from the target for the {param} placeholders.
     E.g. `account.php?id=$1&sid=$2` -> `/account/{id}/{sid}`, NOT `/account/{p1}/{p2}`.
  2. Emit the handler body above, with $g->get[<key>] = $<key> for every captured param.
  3. If the target is the same file but with different query-key sets (e.g. account.php
     with just id vs id+sid), emit ONE route per signature.
  4. Never emit `return null;`, `return;`, or a stub body — the handler must `return App::include(...)`.

RULE 4 — NEVER EMIT THESE FORBIDDEN PATTERNS:
  - App::includeFile(...)             — deprecated alias; emit App::include() instead.
  - App::include(App::$cwd . ...)     — wrong: paths are public-relative.
  - App::include(__DIR__ . ...)       — wrong: paths are public-relative.
  - $g->server['PHP_SELF']     = ...  — framework auto-populates inside App::include().
  - $g->server['SCRIPT_NAME']  = ...  — framework auto-populates inside App::include().
  - $g->server['SCRIPT_FILENAME'] = ... — framework auto-populates inside App::include().
  - App::$ignore_php_ext = false      — use App::ignorePhpExt(false) instead.
  - App::$superglobals = true         — use App::superglobals(true) instead.
  - App::$directory_index = [...]     — use App::directoryIndex([...]) instead.
  - App::$directory_slash = true      — use App::directorySlash(true) instead.
  - App::$path_info = true            — use App::pathInfo(true) instead.
  - App::$block_dotfiles = true       — use App::blockDotfiles(true) instead.
  - bare $_GET['x'] = $x; (in primary code path) — use $g = RequestContext::instance(); $g->get['x'] = $x; AND emit the `// legacy: $_GET['x'] = $x;` comment.
  - exit() / die()                    — not safe in OpenSwoole coroutine context.
  - http_response_code(N)             — use `return N;` (universal contract).
  - echo json_encode(...)             — use `return [array];` (universal contract).
  - header('Location: ...'); return N — use $response->redirect($url, N).

RULE 5 — DO NOT CREATE ROUTES FOR THINGS THE FRAMEWORK HANDLES:
- Base URLs for files in public/ -> auto-served, no route needed
- .php extension blocking -> built-in (App::ignorePhpExt() default true)
- Extensionless URL resolution -> built-in
- Trailing slash addition for directories -> App::directorySlash(true)
- Directory index files -> App::directoryIndex([...])

Only create routes for: parameterized URLs, redirects [R=301], catch-all fallbacks,
patternRoute deny rules, and trailing-slash STRIP (the inverse direction is not built in).

RULE 6 — REFUSE EXPLICITLY when input uses NOT-SUPPORTED features. The
ALLOWED refuse list is exhaustive — do NOT refuse anything outside it:
  - Server-Side Includes / .shtml / mod_speling / mod_imagemap / mod_dav
  - mod_perl / mod_python / mod_ruby / mod_isapi / mod_lua hooks
  - CERN meta files / AuthLDAP* / AuthDigest* / Anonymous* auth
  - `return 444;` (nginx close-without-response) / `early_hints` / `directio`
  - `stream { ... }` / `mail { ... }` / `grpc_pass` (nginx)

  Emission shape (always include the link):
  ```
  // (NOT SUPPORTED): <Feature name>. <one-sentence rationale>.
  //                  <one-sentence migration path or workaround>.
  //                  See https://php.zeal.ninja/legacy-apps#limitations
  ```

  CRITICAL: `auth_basic` / `AuthType Basic`, `limit_req`, `limit_conn`,
  `server_name a.com b.com` (multi-host), `proxy_pass`, and `mod_substitute`
  are NOT on the refuse list. These ARE supported — emit the inline
  anonymous PSR-15 middleware with a `// PROPOSED: <Name>Middleware`
  comment (see RULE 7 TIER B). Do NOT emit a `(NOT SUPPORTED)` comment
  for these — the inline middleware IS the supported emission. Adding a
  `(NOT SUPPORTED)` header alongside working middleware code confuses
  users into thinking their auth doesn't work when it does. The
  `(NOT SUPPORTED)` label is reserved for directives where no code is
  emitted at all (the framework genuinely can't express them).

  Never silently drop. The user's trust depends on knowing what was refused.

RULE 7 — MIDDLEWARE EMISSION (v0.2.21+) — built-in vs PROPOSED:

  TIER A — BUILT-IN (ship in src/Middleware/): emit the class instantiation
  directly. Do NOT prepend `// PROPOSED:`. Do NOT wrap in inline anonymous
  class. Constructor signatures are stable.

    Apache/nginx directive                     ZealPHP emission
    ----------------------------------------   ----------------------------------------
    Header set / add_header (top-level)        new HeaderMiddleware(['set' => [...],
                                                    'append' => [...], 'add' => [...],
                                                    'unset' => [...]])
    AddDefaultCharset / AddCharset             new CharsetMiddleware('utf-8')
    <FilesMatch> Header set Cache-Control      new CacheControlMiddleware()  // defaults
    expires 30d; (nginx)                       new CacheControlMiddleware() or
                                                  new ExpiresMiddleware([], '+30 days')
    ExpiresActive / ExpiresByType              new ExpiresMiddleware(['image/' => '+30 days',
                                                    'text/css' => '+1 year'], '+5 minutes')
    Allow from / Deny from / Order / Require ip  new IpAccessMiddleware(['allow' => [...],
                                                    'deny' => [...]])  // CIDR supported
    AddType (handler-generated bodies)         new MimeTypeMiddleware(['wasm' => 'application/wasm'])
    Refuse `.php` in URLs                      new BlockPhpExtMiddleware()  // no args
    Access-Control-Allow-Origin (CORS)         new CorsMiddleware(['*'])
    ETag                                       new ETagMiddleware()
    Range / 206 / 416                          new RangeMiddleware()
    Eager session cookie                       new SessionStartMiddleware()

  TIER B — STILL PROPOSED (NOT YET in src/Middleware/): emit a WORKING
  inline anonymous-class PSR-15 middleware implementation, PLUS a
  `// PROPOSED:` comment naming the class. These directives are SUPPORTED
  in ZealPHP — the inline anonymous-class PSR-15 middleware is a fully
  valid PHP construct and the framework's middleware stack handles it
  identically to a named class. Do NOT refuse with `(NOT SUPPORTED)`;
  that's reserved for features the framework genuinely can't express
  (SSI, mod_dav, AuthLDAP, return 444, etc.).

  NEVER emit `new BasicAuthMiddleware(...)` etc. as class instantiation
  — those classes don't exist yet, and the user's app.php would fatal at
  boot with `Class 'ZealPHP\\Middleware\\BasicAuthMiddleware' not found`.

  // PROPOSED: BasicAuthMiddleware — not yet shipped. Inline implementation:
  $app->addMiddleware(new class implements \\Psr\\Http\\Server\\MiddlewareInterface {
      public function process(
          \\Psr\\Http\\Message\\ServerRequestInterface $request,
          \\Psr\\Http\\Server\\RequestHandlerInterface $handler
      ): \\Psr\\Http\\Message\\ResponseInterface {
          $auth = $request->getHeaderLine('Authorization');
          if (!str_starts_with($auth, 'Basic ')) {
              return (new \\OpenSwoole\\Core\\Psr\\Response('Authentication required'))
                  ->withStatus(401)
                  ->withHeader('WWW-Authenticate', 'Basic realm="Realm"');
          }
          // Verify $auth here against htpasswd / DB / callback — placeholder block.
          return $handler->handle($request);
      }
  });

  Still-PROPOSED names to use in the `// PROPOSED:` comment (emit inline,
  not (NOT SUPPORTED)):
    auth_basic / AuthType Basic       -> BasicAuthMiddleware
    limit_req (rate limit)            -> RateLimitMiddleware
    limit_conn (concurrent limit)     -> ConcurrencyLimitMiddleware
    server_name multi-host            -> HostRouterMiddleware
    proxy_pass                        -> ProxyMiddleware
    mod_substitute (body rewrite)     -> BodyRewriteMiddleware

  Cross-reference: the reference (get_zealphp_reference()) shows the exact
  constructor signature for each built-in class — copy from there, don't
  guess parameter names.

ADDITIONAL RULES:

1. NEVER fabricate API that doesn't exist:
   - App::init() takes ($host, $port, $cwd) — NEVER pass arrays or config objects
   - There is NO App::init(['phpSettings' => ...])
   - There is NO $app->config() or $app->setting()

2. Use {param} syntax for routes, NOT raw regex:
   - WRONG: $app->route('/user/([^/]+)', function($matches) { ... })
   - RIGHT: $app->route('/user/{id}', function($id) { ... })
   - Parameters are injected BY NAME via reflection.

3. DROP Apache/nginx directives that don't apply — ONE brief comment for ALL dropped items:
   - ServerSignature, Options -Indexes, ModPagespeed
   - .php extension blocking, extensionless PHP URL resolution -> built-in
   - gzip on (OpenSwoole http_compression default), location ~ \\.php$ { fastcgi_pass ... }
   - But NEVER drop RewriteRules with capture groups — those MUST become routes
   - And NEVER drop NOT-SUPPORTED directives — those get explicit refuse comments (Rule 6)

4. CORS (Access-Control-Allow-Origin) -> $app->addMiddleware(new CorsMiddleware(['*']))

5. upload_max_filesize / post_max_size / client_max_body_size -> package_max_length in $app->run()

6. Redirect RewriteRules [R=301] / [R=302] -> route with $response->redirect($url, $status).
   NEVER emit `header('Location: ...'); return N;` — use the redirect helper.

7. Catch-all profile/fallback rule -> $app->setFallback() — in mode (A), the fallback
   body uses the same $g->get + return App::include() pattern as a regular route.

APACHE FLAG / RULE HANDLING (compact table — apply per rewrite):
  [F]                                -> emit a route or patternRoute returning 403
  [G]                                -> emit a route or patternRoute returning 410
  [R=301] / [R=302] / [R=307]        -> route returning $response->redirect($url, <status>)
  [E=VAR:value]                      -> $g = RequestContext::instance(); $g->server['VAR'] = 'value';
  [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]  -> DROP this rule with a comment.
                                       ZealPHP exposes the Authorization header natively via
                                       $_SERVER['HTTP_AUTHORIZATION'] (populated from OpenSwoole).
  [L]                                -> no-op (ZealPHP routes are terminal)
  [QSA]                              -> no-op for our $g->get assignment pattern
  [NC]                               -> no-op for {param} or wrap regex with (?i) for patternRoute
  [OR] between RewriteConds          -> combine inside the handler body if needed
  <Files X> / Deny from all          -> $app->patternRoute('/X', fn() => 403);
  <FilesMatch "\\.(env|log|git)$"> Require all denied
                                     -> $app->patternRoute('/.*\\.(env|log|git).*', fn() => 403);
  Allow from <ip> / Deny from <ip>   -> comment: PROPOSED IpAccessMiddleware (or inline `in_array`)
  ErrorDocument N /path              -> $app->setErrorHandler(N, fn() => App::include('/path'))
  Redirect / RedirectMatch           -> route returning $response->redirect($url, 301)

STATIC RESPONSE HEADERS (v0.2.21+ — use built-in HeaderMiddleware):
  Apache `Header set X-Foo "bar"` (top-level, NOT inside <FilesMatch>) and nginx
  `add_header X-Foo "bar";` (top-level inside server {}) are GLOBAL static headers
  applied to every response. Collect all such directives into ONE `HeaderMiddleware`
  call. Do NOT emit inline anonymous classes — `HeaderMiddleware` ships in v0.2.21+.

  Emit pattern:

    $app->addMiddleware(new \\ZealPHP\\Middleware\\HeaderMiddleware([
        'set' => [
            'X-Frame-Options'         => 'DENY',
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        ],
        // 'append' => ['Vary' => 'Accept-Encoding'],   // mod_headers `Header append`
        // 'add'    => ['Link' => ['<x>; rel=preload', '<y>; rel=preload']],  // mod_headers `Header add` (multi-value)
        // 'unset'  => ['Server', 'X-Powered-By'],
    ]));

  One key under `'set'` per Header set / add_header directive collected.
  `Header append X "v"` -> `'append' => ['X' => 'v']` (joins comma-separated to existing).
  `Header add X "v1"` then `Header add X "v2"` -> `'add' => ['X' => ['v1', 'v2']]`.
  `Header unset X` / nginx `more_clear_headers X` -> `'unset' => ['X']`.
  Skip CORS headers (those go to CorsMiddleware).
  For <FilesMatch> cache headers, use `new CacheControlMiddleware()` (defaults cover
  the usual static-asset extensions) or `new CacheControlMiddleware(['css' => 31536000])`.
  If there are NO non-CORS static headers, do not emit this block.

TRAILING-SLASH STRIP:
  Apache `RewriteRule ^(.+)/$ /$1 [L,R=301]` and nginx `rewrite ^(.+)/$ /$1 permanent;`
  both strip a trailing slash from non-directory URLs. ZealPHP's built-in
  `App::directorySlash(true)` only handles the OPPOSITE direction (adds slash for dirs).
  Emit a patternRoute mirroring the original rule, registered AFTER all explicit routes
  so it doesn't shadow paths that intentionally end with /:

    $app->patternRoute('/(.+)/$', function($response) {
        $g = RequestContext::instance();
        $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        return $response->redirect($path, 301);
    });

NGINX MODE-A EQUIVALENT (same shape as Apache mode A):
  Both nginx forms compile to the same ZealPHP shape:

    rewrite ^/qn/([^/]+)$ /qn.php?id=$1 last;
    location ~* ^/api/(.+)$ { rewrite ^/api/(.+) /api.php?action=$1 last; }

  -> $app->route('/qn/{id}', function($id) {
        $g = RequestContext::instance();
        $g->get['id'] = $id;
        // legacy: $_GET['id'] = $id;
        return App::include('/qn.php');
    });

  Drop the `location` wrapper — it's just nginx scoping.
  Drop the `last` / `break` / `permanent` flags — ZealPHP routes are implicitly terminal.
  The whole `location ~ \\.php$ { fastcgi_pass ... }` block: DROP it entirely. ZealPHP IS
  the PHP runtime; there is no PHP-FPM to forward to.
  nginx `client_max_body_size 100M;` -> `package_max_length` in $app->run() options.
  nginx `return N;` -> `return N;` from a route (universal return contract).
  nginx `return 200 "OK\\n";` -> `return "OK\\n";` (universal return contract).
  nginx `error_page N /path;` -> `App::setErrorHandler(N, fn() => App::include('/path'))`.
  nginx `gzip on;` and `gzip_types ...;` -> drop (OpenSwoole http_compression default).
  nginx `proxy_pass`, `proxy_set_header` -> drop with a comment recommending front proxy;
        emit inline ProxyMiddleware shape only if user explicitly needs same-process forwarding.

FRAMEWORK DETECTION (run BEFORE classification):
  Detect the originating framework from signature patterns. All matches classify as MODE B
  (front-controller via setFallback + App::include('/index.php')). When detected,
  emit `// Detected: <framework>` as the FIRST comment line of app.php and apply the
  framework-specific tweak:

    WordPress    : `RewriteRule ^index\\.php$ - [L]` + the standard `!-f !-d -> /index.php`
                   catchall.    Tweak: none — mode B verbatim.
    Laravel      : `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
                   AND `RewriteRule ^ index.php [L]` AND a trailing-slash redirect.
                   Tweak: DROP the HTTP_AUTHORIZATION env-var rule. Add a comment that
                   ZealPHP exposes the Authorization header natively and that each request
                   spawns a CGI subprocess (heavy for Laravel bootstrap; consider
                   App::onWorkerStart() for preloading).
    Symfony      : `RewriteCond %{ENV:REDIRECT_STATUS}` AND `RewriteRule ^ ... index.php`.
                   Tweak: same as Laravel — drop HTTP_AUTHORIZATION workaround.
    Drupal       : `RewriteRule ^(.*)$ index.php?q=$1`.  Tweak: the path arrives as
                   $g->get['q'] inside the front-controller (legacy: $_GET['q']).
    CodeIgniter  : `RewriteRule ^(.*)$ index.php?/$1`.  Tweak: note path-as-PATH_INFO.

OUTPUT FORMAT:
- Output ONLY the PHP code — no markdown fences, no explanations before/after the code.
- Include: <?php opening tag, require autoload, use statements (App + RequestContext + any
  built-in middleware classes used — HeaderMiddleware, CharsetMiddleware, CacheControlMiddleware,
  ExpiresMiddleware, IpAccessMiddleware, MimeTypeMiddleware, BlockPhpExtMiddleware, CorsMiddleware,
  ETagMiddleware, RangeMiddleware, SessionStartMiddleware), static config (App::superglobals /
  App::ignorePhpExt / etc.), App::init(), middleware, routes, $app->run().
- PSR-2 indentation (4 spaces). Short array syntax `[]`. One-line comments for the Apache/nginx
  equivalent of each block, so users learning the migration can map back.
- End with `$app->run(['task_worker_num' => 0]);` (or include `package_max_length` when the
  input had upload-size config).
- If the input is not a valid Apache or nginx config:
  Output ONLY: // Error: Not a valid Apache .htaccess or nginx server config"""


@function_tool
def get_zealphp_reference() -> str:
    """Get the complete ZealPHP framework reference for converting Apache/nginx configs."""
    return ZEALPHP_REFERENCE


@function_tool
def get_conversion_examples() -> str:
    """Get few-shot examples of Apache/nginx to ZealPHP conversions."""
    return FEW_SHOT_EXAMPLES


@function_tool
def validate_conversion(original_config: str, zealphp_code: str) -> str:
    """Validate a conversion by checking for common patterns that need special handling."""
    issues = []
    original_lower = original_config.lower()
    code_lower = zealphp_code.lower()

    # ------------------------------------------------------------------
    # Structural checks
    # ------------------------------------------------------------------
    if "app::init" not in code_lower:
        issues.append("Missing App::init() — every app.php needs $app = App::init('0.0.0.0', port)")

    if "$app->run()" not in zealphp_code and "$app->run([" not in zealphp_code:
        issues.append("Missing $app->run() — server won't start without it")

    if "app::init([" in code_lower or "app::init({" in code_lower:
        issues.append("App::init() takes ($host, $port, $cwd) — NOT arrays or config objects")

    # ------------------------------------------------------------------
    # Anti-pattern checks (the post-rename rules)
    # ------------------------------------------------------------------

    if "app::includefile(" in code_lower:
        issues.append(
            "App::includeFile() is the deprecated alias — emit App::include() instead. "
            "Paths are public-relative (e.g. App::include('/qn.php'))."
        )

    if "app::include(app::$cwd" in code_lower or "app::include(__dir__" in code_lower:
        issues.append(
            "App::include() takes a public-RELATIVE path (Apache DocumentRoot convention). "
            "Use App::include('/qn.php'), NOT App::include(App::$cwd . '/public/qn.php') or "
            "App::include(__DIR__ . '/public/qn.php'). The framework resolves it under public/."
        )

    # The $g->server PHP_SELF/SCRIPT_NAME/SCRIPT_FILENAME preamble is now framework-internal.
    import re
    preamble_keys = ("'php_self'", "'script_name'", "'script_filename'",
                     '"php_self"', '"script_name"', '"script_filename"')
    if any(k in code_lower for k in preamble_keys):
        # Only flag if it's an assignment, not a read.
        if re.search(r"\$g->server\s*\[\s*['\"](?:php_self|script_name|script_filename)['\"]\s*\]\s*=",
                     zealphp_code, re.IGNORECASE):
            issues.append(
                "Do not emit the $g->server['PHP_SELF'/'SCRIPT_NAME'/'SCRIPT_FILENAME'] preamble in "
                "user code — App::include() populates these automatically inside the framework "
                "(Apache mod_php parity). Removing the preamble keeps generated code clean."
            )

    if "$matches[" in code_lower:
        issues.append("Do not use $matches[] — use {param} syntax and named function parameters")

    # Bare $_GET writes in primary code path — must be RequestContext::instance() form
    # with a `// legacy:` comment showing the $_GET equivalent.
    bare_get_assign = re.findall(
        r"^[^/\n]*\$_GET\s*\[\s*['\"][^'\"]+['\"]\s*\]\s*=",
        zealphp_code,
        re.MULTILINE,
    )
    if bare_get_assign:
        issues.append(
            "Bare $_GET['x'] = ... assignment found in primary code path — switch to "
            "$g = RequestContext::instance(); $g->get['x'] = $x; and pair it with a "
            "`// legacy: $_GET['x'] = $x;` comment. $_GET writes leak across coroutines "
            "in the default (coroutine) mode."
        )

    # Raw property assignment for configurables — fluent method form is now canonical
    fluent_violations = [
        (r"app::\$ignore_php_ext\s*=",  "App::ignorePhpExt(true|false)"),
        (r"app::\$superglobals\s*=",    "App::superglobals(true|false)"),
        (r"app::\$directory_index\s*=", "App::directoryIndex([...])"),
        (r"app::\$directory_slash\s*=", "App::directorySlash(true|false)"),
        (r"app::\$path_info\s*=",       "App::pathInfo(true|false)"),
        (r"app::\$block_dotfiles\s*=",  "App::blockDotfiles(true|false)"),
        (r"app::\$display_errors\s*=",  "App::displayErrors(true|false)"),
        (r"app::\$document_root\s*=",   "App::documentRoot('public')"),
        (r"app::\$trace_enabled\s*=",   "App::traceEnabled(true|false)"),
        (r"app::\$default_charset\s*=", "App::defaultCharset('utf-8')"),
        (r"app::\$autoindex\s*=",       "App::autoindex(true|false)"),
    ]
    for pattern, replacement in fluent_violations:
        if re.search(pattern, code_lower):
            issues.append(
                f"Raw property assignment found — use the fluent method form: {replacement}. "
                f"Configurables follow App::superglobals() precedent (one App per process, "
                f"static config before init)."
            )

    if "exit" in code_lower.split("//")[0] or "die(" in code_lower:
        issues.append("Never use exit()/die() — not safe in OpenSwoole coroutine context")

    if "http_response_code(" in code_lower:
        issues.append(
            "Do not emit http_response_code(N) — use `return N;` from the handler "
            "(universal return contract: int return becomes status-only response)."
        )

    if re.search(r"echo\s+json_encode\s*\(", code_lower):
        issues.append(
            "Do not emit `echo json_encode(...)` — use `return [array];` from the handler "
            "(universal return contract: array return becomes JSON with Content-Type set)."
        )

    if re.search(r"header\s*\(\s*['\"]location\s*:", code_lower, re.IGNORECASE):
        # Allowed inside the inline HTTPS-redirect / canonical-host middleware shape;
        # flag only when it's clearly the body of a route handler (no `withHeader`).
        if "withheader(" not in code_lower or "->redirect(" not in code_lower:
            issues.append(
                "Do not emit `header('Location: ...');` from a route handler — use "
                "$response->redirect($url, $status). For middleware, use the PSR-7 "
                "$response->withHeader('Location', $url)->withStatus($status) form."
            )

    # ------------------------------------------------------------------
    # Missing conversion checks
    # ------------------------------------------------------------------
    if "rewritecond %{https}" in original_lower or "ssl " in original_lower or "ssl_certificate" in original_lower:
        if "ssl" not in code_lower and "reverse proxy" not in code_lower and "front proxy" not in code_lower:
            issues.append("SSL/HTTPS config found — note reverse proxy or add ssl options to $app->run()")

    if "proxy_pass" in original_lower or "proxypass" in original_lower:
        if "proxy" not in code_lower:
            issues.append("Reverse proxy directives found — add comment that a front proxy is recommended")

    if "auth_basic" in original_lower or "htpasswd" in original_lower or "authtype basic" in original_lower:
        if "basicauth" not in code_lower and "www-authenticate" not in code_lower:
            issues.append(
                "Basic auth found — emit the inline anonymous BasicAuthMiddleware (PROPOSED) "
                "with a 401 + WWW-Authenticate response when Authorization is missing or invalid."
            )

    if "rewriterule" in original_lower:
        if "setfallback" not in code_lower and "route(" not in code_lower:
            issues.append("RewriteRules found but no setFallback() or route() — conversion may be incomplete")

    # Count RewriteRules with capture groups vs route() calls
    capture_rules = len(re.findall(r'rewriterule\s+\S*\([^)]+\)', original_lower))
    route_calls = zealphp_code.count("->route(") + zealphp_code.count("->patternroute(") + zealphp_code.count("->nspathroute(")
    if capture_rules > 0 and route_calls < capture_rules // 2:
        issues.append(
            f"CRITICAL: Found {capture_rules} RewriteRules with capture groups but only "
            f"{route_calls} route()/patternRoute()/nsPathRoute() calls. Every parameterized "
            f"RewriteRule MUST become a route. Add the missing routes."
        )

    if "access-control-allow-origin" in original_lower:
        if "corsmiddleware" not in code_lower:
            issues.append("CORS header found — use CorsMiddleware instead of manual headers")

    if "upload_max_filesize" in original_lower or "post_max_size" in original_lower \
       or "client_max_body_size" in original_lower:
        if "package_max_length" not in code_lower:
            issues.append("Upload-size / body-size config found — use package_max_length in $app->run() options")

    # ------------------------------------------------------------------
    # LEGACY-WITH-PARAMETERIZED-REWRITES mode checks
    # ------------------------------------------------------------------
    php_target_rewrites = re.findall(
        r'rewriterule\s+\S+\s+["\']?(\w+)\.php\?',
        original_lower,
    )
    if php_target_rewrites:
        include_calls = code_lower.count("app::include(")
        route_calls_total = zealphp_code.count("->route(")
        # Every parameterized route should delegate; allow one "free" route for redirects.
        expected_delegations = min(route_calls_total, len(php_target_rewrites))
        if route_calls_total > 0 and include_calls < expected_delegations:
            issues.append(
                f"CRITICAL: {len(php_target_rewrites)} rewrite(s) target *.php files but only "
                f"{include_calls} App::include() call(s) appear. Each parameterized rewrite must "
                "delegate via App::include('/<target>.php') after populating $g->get from URL params. "
                "A stub body like 'return null;' does not execute the original file."
            )
        if "superglobals(true)" not in code_lower:
            issues.append(
                "Rewrites target *.php files but App::superglobals(true) is missing. Without it, "
                "the included legacy file cannot read $_GET — add App::superglobals(true) and "
                "App::ignorePhpExt(false) before App::init()."
            )
        # In mode A every $g->get assignment should be paired with a // legacy: comment.
        g_get_writes = len(re.findall(r"\$g->get\s*\[\s*['\"][^'\"]+['\"]\s*\]\s*=", zealphp_code))
        legacy_comments = code_lower.count("// legacy: $_get")
        if g_get_writes > 0 and legacy_comments < g_get_writes:
            issues.append(
                f"Found {g_get_writes} $g->get assignment(s) but only {legacy_comments} `// legacy: $_GET ...` "
                f"comment(s) — pair every $g->get write with a comment showing the $_GET equivalent so "
                f"users learn the parity rule (§5b)."
            )

    # ------------------------------------------------------------------
    # Universal return contract — [F] / [G] flag handling
    # ------------------------------------------------------------------
    if re.search(r'\[\s*f\s*[,\]]', original_lower) or re.search(r',\s*f\s*\]', original_lower):
        if "return 403" not in code_lower and "fn() => 403" not in code_lower:
            issues.append(
                "[F] forbidden flag found — emit a route or patternRoute returning 403 "
                "(universal return contract: int return -> status-only response)."
            )

    if re.search(r'\[\s*g\s*[,\]]', original_lower) or re.search(r',\s*g\s*\]', original_lower):
        if "return 410" not in code_lower and "fn() => 410" not in code_lower:
            issues.append(
                "[G] gone flag found — emit a route or patternRoute returning 410 "
                "(universal return contract: int return -> status-only response)."
            )

    # HTTP_AUTHORIZATION Laravel/Symfony workaround — must be DROPPED, not converted
    if "http_authorization" in original_lower and "%{http:authorization}" in original_lower:
        bad = re.search(
            r"\$g->server\s*\[\s*['\"]http_authorization['\"]\s*\]\s*=",
            zealphp_code,
            re.IGNORECASE,
        )
        if bad:
            issues.append(
                "The [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}] rule was converted to "
                "code that copies the header — drop it instead with a comment explaining "
                "that ZealPHP exposes the Authorization header natively via $_SERVER."
            )

    # <Files> / <FilesMatch> deny rules — must compile to a 403 patternRoute
    if re.search(r'(<filesmatch|<files\s)', original_lower) and \
       re.search(r'(deny\s+from\s+all|require\s+all\s+denied)', original_lower):
        if "return 403" not in code_lower and "fn() => 403" not in code_lower:
            issues.append(
                "<Files>/<FilesMatch> deny rule found — emit a patternRoute returning 403 "
                "for those file patterns (e.g. \\.env, \\.git, \\.log)."
            )

    # nginx `return N;` (other than 200/3xx redirects) — should propagate to a route
    # returning N (universal contract).
    nginx_returns = re.findall(r'(?:^|\s)return\s+(\d{3})\b', original_lower)
    nginx_returns = [s for s in set(nginx_returns) if s not in ('200', '301', '302', '307', '308')]
    for status in nginx_returns:
        if f"return {status}" not in code_lower and f"fn() => {status}" not in code_lower:
            issues.append(
                f"nginx `return {status}` found — emit a route returning {status} "
                f"(universal return contract)."
            )

    # ------------------------------------------------------------------
    # Static response headers -> HeaderMiddleware (built-in v0.2.21+)
    # ------------------------------------------------------------------
    header_set_lines = re.findall(
        r'(?:^|\n)\s*(?:header\s+set|add_header)\s+([A-Za-z][\w-]*)',
        original_lower,
    )
    non_cors_headers = [h for h in header_set_lines if not h.startswith('access-control-')]
    if non_cors_headers and "headermiddleware" not in code_lower:
        issues.append(
            f"Static response headers found in input ({len(non_cors_headers)} non-CORS "
            "directives) — emit `new HeaderMiddleware(['set' => [...]])` (built-in, v0.2.21+). "
            "Do NOT emit an inline anonymous PSR-15 middleware or `// PROPOSED:` comment — "
            "HeaderMiddleware ships."
        )

    # ------------------------------------------------------------------
    # Built-in middleware emission enforcement (v0.2.21+)
    # ------------------------------------------------------------------
    # For each Apache/nginx directive whose middleware now ships, the bot
    # MUST emit the class instantiation, NOT an inline anonymous class with
    # a `// PROPOSED:` comment. We detect each by sniffing the input for
    # the directive AND sniffing the output for the wrong pattern.

    has_inline_anon = re.search(
        r"new\s+class\s+implements\s+\\?Psr\\?\\Http\\?\\Server\\?\\MiddlewareInterface",
        zealphp_code,
        re.IGNORECASE,
    )

    shipped_directive_checks = [
        # (input regex,                          middleware class name,    proposed-comment marker)
        (r'(?:^|\n)\s*(?:header\s+set|add_header)\s+', "HeaderMiddleware",        "proposed: headermiddleware"),
        (r'\baddcharset\b|\badddefaultcharset\b',      "CharsetMiddleware",       "proposed: charsetmiddleware"),
        (r'\bexpires(?:active|bytype|default)\b|\bexpires\s+\d', "ExpiresMiddleware", "proposed: expiresmiddleware"),
        (r'<filesmatch[^>]*>\s*[^<]*header\s+set\s+cache-control', "CacheControlMiddleware", "proposed: cachecontrolmiddleware"),
        (r'\ballow\s+from\b|\bdeny\s+from\b|\brequire\s+ip\b|^\s*(?:allow|deny)\s+\d', "IpAccessMiddleware", "proposed: ipaccessmiddleware"),
        (r'\baddtype\s+\S+\s+\.', "MimeTypeMiddleware", "proposed: mimetypemiddleware"),
    ]
    for input_re, mw_class, proposed_marker in shipped_directive_checks:
        if re.search(input_re, original_lower, re.IGNORECASE | re.MULTILINE):
            if mw_class.lower() not in code_lower:
                issues.append(
                    f"Input directive matches `{mw_class}` (built-in, v0.2.21+) territory but "
                    f"the output never instantiates `new {mw_class}(...)`. Emit the built-in "
                    f"class instead of inline anonymous middleware."
                )
            elif proposed_marker in code_lower and has_inline_anon:
                # User wrote both the inline anonymous AND a // PROPOSED: comment for a
                # middleware that now ships — that's the exact regression we're guarding against.
                issues.append(
                    f"Output contains `// PROPOSED: {mw_class}` + inline anonymous class for "
                    f"a middleware that ships in v0.2.21+. Replace with `new {mw_class}(...)` "
                    f"and drop both the inline anonymous block and the `// PROPOSED:` comment."
                )

    # General check: if the output contains a // PROPOSED: comment for a middleware that
    # now ships, flag it. (PROPOSED is still valid for BasicAuth / RateLimit /
    # ConcurrencyLimit / HostRouter / BodyRewrite / Proxy.)
    shipped_names = (
        "HeaderMiddleware", "CharsetMiddleware", "CacheControlMiddleware",
        "ExpiresMiddleware", "IpAccessMiddleware", "MimeTypeMiddleware",
        "BlockPhpExtMiddleware",
    )
    for name in shipped_names:
        marker = f"proposed: {name.lower()}"
        if marker in code_lower:
            issues.append(
                f"`// PROPOSED: {name}` comment found in output, but this middleware ships "
                f"in v0.2.21+. Drop the comment and emit `new {name}(...)` directly."
            )

    # Trailing-slash strip
    has_apache_slash_strip = bool(
        re.search(r'rewriterule\s+\^\(?\.\+\)?/?\$?\s+/\$?1', original_lower)
        and "r=301" in original_lower
    )
    has_nginx_slash_strip = bool(
        re.search(r'rewrite\s+\^\(?\.\+\)?/?\$?\s+/\$?1\s+permanent', original_lower)
    )
    if has_apache_slash_strip or has_nginx_slash_strip:
        if "patternroute" not in code_lower or "redirect(" not in code_lower:
            issues.append(
                "Trailing-slash strip rule found — emit a patternRoute('/(.+)/$', ...) that "
                "calls $response->redirect($pathWithoutSlash, 301)."
            )

    # ------------------------------------------------------------------
    # NOT-SUPPORTED detection — must produce explicit `// (NOT SUPPORTED)` comment
    # ------------------------------------------------------------------
    not_supported_patterns = {
        "Server-Side Includes (SSI)": [
            r"options\s+\+includes",
            r"xbithack\b",
            r"addhandler\s+server-parsed",
            r"addtype\s+text/html\s+\.shtml",
        ],
        "mod_speling (CheckSpelling)": [
            r"checkspelling\b",
            r"checkcaseonly\b",
            r"checkbasenamematch\b",
        ],
        "mod_imagemap": [r"imapbase\b", r"imapdefault\b", r"imapmenu\b"],
        "mod_dav (WebDAV)": [r"^\s*dav\s+on\b", r"davminTimeout\b", r"davlockdb\b"],
        "AuthLDAP*": [r"authldap\w*\b", r"authldapurl\b"],
        "AuthDigest*": [r"authtype\s+digest", r"authdigest\w*"],
        "CERN meta files": [r"metadir\b", r"metafiles\b", r"metasuffix\b"],
        "mod_lua hooks": [r"luahook\w+", r"luamap\w+"],
        "nginx return 444 (close without response)": [r"\breturn\s+444\b"],
        "early_hints (HTTP 103)": [r"\bearly_hints\b"],
        "nginx stream { } (L4 proxy)": [r"^\s*stream\s*\{"],
        "nginx mail { } (SMTP/IMAP proxy)": [r"^\s*mail\s*\{"],
        "grpc_pass": [r"\bgrpc_pass\b"],
    }
    refuse_marker = "(not supported)"
    for feature, patterns in not_supported_patterns.items():
        if any(re.search(p, original_lower, re.MULTILINE) for p in patterns):
            # Need an explicit refuse comment for this feature.
            if refuse_marker not in code_lower or feature.lower().split()[0] not in code_lower:
                issues.append(
                    f"NOT SUPPORTED feature detected ({feature}) — emit an explicit "
                    f"`// (NOT SUPPORTED): {feature}. <rationale>. See "
                    f"https://php.zeal.ninja/legacy-apps#limitations` comment in the output. "
                    f"Silently dropping unsupported directives is forbidden — refuse with clarity."
                )
                break  # one per pass keeps the issue list focused

    # ------------------------------------------------------------------
    # Framework detection — when a known signature is in the input, the output should
    # carry a `// Detected: <framework>` comment and use mode-B (setFallback).
    # ------------------------------------------------------------------
    fw_signatures = {
        "Laravel":     r"\[\s*e=http_authorization:%\{http:authorization\}\s*\]",
        "Symfony":     r"rewritecond\s+%\{env:redirect_status\}",
        "Drupal":      r"rewriterule\s+\^?\(?\.\*\)?\$?\s+index\.php\?q=\$1",
        "CodeIgniter": r"rewriterule\s+\^?\(?\.\*\)?\$?\s+index\.php\?/\$1",
        "WordPress":   r"rewriterule\s+\^index\\?\.php\$\s+-\s+\[l\]",
    }
    detected_fw = None
    for fw, sig in fw_signatures.items():
        if re.search(sig, original_lower):
            detected_fw = fw
            break
    if detected_fw:
        marker_lower = f"detected: {detected_fw.lower()}"
        if marker_lower not in code_lower:
            issues.append(
                f"{detected_fw} signature detected in input — emit `// Detected: {detected_fw}` "
                f"as the first comment in app.php and apply mode-B (front-controller) shape."
            )
        if "setfallback" not in code_lower:
            issues.append(
                f"{detected_fw} is a front-controller framework — output must use "
                f"$app->setFallback(fn() => App::include('/index.php'))."
            )

    if not issues:
        return "Conversion looks correct — all directives accounted for."
    return "Issues found:\n" + "\n".join(f"- {i}" for i in issues)


converter = Agent(
    name="config_converter",
    model="gpt-5.4-mini",
    instructions=CONVERTER_INSTRUCTIONS,
    tools=[get_zealphp_reference, get_conversion_examples, validate_conversion],
)


async def main():
    if not sys.stdin.isatty():
        user_input = sys.stdin.read().strip()
        if user_input:
            print("Converting config to ZealPHP app.php...\n")
            result = Runner.run_streamed(converter, input=f"Convert this config to ZealPHP app.php:\n\n{user_input}")
            async for event in result.stream_events():
                if event.type == "raw_response_event" and getattr(event.data, "type", "") == "response.output_text.delta":
                    print(event.data.delta, end="", flush=True)
            print()
            return

    print(f"Apache/nginx -> ZealPHP Converter (gpt-5.4-mini, prompt v{SYSTEM_PROMPT_VERSION}, targets {ZEALPHP_VERSION})")
    print("Paste your .htaccess or nginx config, then type 'convert' on a new line.")
    print("Type 'quit' to exit.\n")

    while True:
        lines = []
        try:
            print("Config (paste, then type 'convert'):")
            while True:
                line = input()
                if line.strip().lower() == "convert":
                    break
                if line.strip().lower() == "quit":
                    return
                lines.append(line)
        except (EOFError, KeyboardInterrupt):
            break

        config_text = "\n".join(lines).strip()
        if not config_text:
            continue

        print("\nConverting...\n")
        result = Runner.run_streamed(
            converter,
            input=f"Convert this config to ZealPHP app.php:\n\n{config_text}",
        )
        async for event in result.stream_events():
            if event.type == "raw_response_event" and getattr(event.data, "type", "") == "response.output_text.delta":
                print(event.data.delta, end="", flush=True)
        print("\n")


if __name__ == "__main__":
    asyncio.run(main())
