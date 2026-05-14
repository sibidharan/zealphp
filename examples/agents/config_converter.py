#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
Apache/nginx → ZealPHP Converter Agent
=======================================
Converts .htaccess or nginx config into a ZealPHP app.php.
Uses gpt-5.4-mini with streaming, few-shot examples, and tool-assisted validation.

Usage:
    uv run examples/agents/config_converter.py
    echo "RewriteRule ^api/(.*)$ index.php [L]" | uv run examples/agents/config_converter.py

Requires: OPENAI_API_KEY environment variable
"""

import asyncio
import sys
from agents import Agent, Runner, function_tool


ZEALPHP_REFERENCE = r"""
## ZealPHP Framework Reference — for Converter Agent

ZealPHP is a PHP web framework built on OpenSwoole. It replaces Apache/nginx entirely —
ZealPHP IS the HTTP server. There is no separate web server.

### Architecture

- **app.php** — entry point. Defines routes, configures server, calls $app->run().
- **public/** — the document root (equivalent to Apache's DocumentRoot / htdocs).
  All PHP files from the old Apache document root MUST be moved into `public/`.
  Once in `public/`, they are auto-served at their base name: `public/qn.php` → `/qn`.
  Static files (CSS, JS, images, fonts) in `public/` are served directly by OpenSwoole.
- **route/** — route definition files for parameterized URL patterns. Auto-included at startup.

### Migration Step: Move Files to public/

When converting from Apache, the FIRST instruction must be:
"Move all PHP files from your Apache document root into the `public/` folder."

Once files are in `public/`, they are auto-served. You do NOT need routes for base URLs.
`public/qn.php` is available at `/qn` automatically — no $app->route('/qn', ...) needed.

You ONLY need explicit $app->route() calls for:
1. Parameterized URLs: `/qn/{id}` (auto-serving can't handle URL params)
2. Redirect rules: [R=301,L]
3. Catch-all / fallback rules
4. Routes that need special HTTP method handling

### App Initialization

```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

$app = App::init('0.0.0.0', 8080);
// ... define routes ...
$app->run(['task_worker_num' => 0]);
```

App::init() signature: `App::init($host, $port, $cwd)` — no other parameters.
NEVER pass arrays, phpSettings, or config objects to App::init().

### Route Registration — {param} Syntax

ZealPHP uses Flask-style `{param}` placeholders. Parameters are injected into the handler
function BY NAME via reflection. No manual $_GET assignment needed.

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

| Name        | Type                    | Description                          |
|-------------|-------------------------|--------------------------------------|
| `$request`  | `ZealPHP\HTTP\Request`  | HTTP request object                  |
| `$response` | `ZealPHP\HTTP\Response` | HTTP response object                 |
| `$app`      | `App`                   | App instance                         |

Any parameter not matching a URL {param} or magic name gets its PHP default value.

### Route Types

```php
// 1. Basic route — most common
$app->route('/path/{param}', function($param) { ... });

// 2. Namespace route — adds a prefix
$app->nsRoute('admin', '/dashboard', function() { ... });
// Creates route at /admin/dashboard

// 3. Namespace path route — last {param} catches everything including slashes
$app->nsPathRoute('api', '{path}', function($path) { ... });
// /api/users/123/posts → $path = "users/123/posts"

// 4. Pattern route — raw regex, no {param} syntax
$app->patternRoute('/files/.*', function() { ... });
```

### Redirects

```php
$app->route('/old-page', function() {
    header('Location: /new-page');
    return 301;
});

// With captured param
$app->route('/blog/{slug}', function($slug) {
    header('Location: /articles/' . $slug);
    return 301;
});
```

### Fallback Handler

Catch-all for unmatched routes. Equivalent to Apache's `RewriteRule . /index.php [L]`.
ONLY use for CMS/front-controller apps (WordPress, Laravel, Drupal) that route everything
through a single entry point.

```php
$app->setFallback(function() {
    $g = \ZealPHP\G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});
```

### Legacy App Mode (WordPress, Drupal, etc.)

ONLY enable these for apps that cannot be refactored:

```php
App::superglobals(true);        // Enable $_GET, $_POST, $_SESSION etc.
App::$ignore_php_ext = false;   // Allow .php in URLs (/wp-login.php)
```

App::includeFile() runs each PHP file in a separate process with full global scope
isolation — like Apache's prefork MPM. ONLY use for legacy apps.

### Middleware

```php
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\ETagMiddleware;

$app->addMiddleware(new CorsMiddleware(['*']));
$app->addMiddleware(new ETagMiddleware());
```

### What OpenSwoole Handles Automatically (DO NOT convert these)

- Static file serving (CSS, JS, images, fonts) — `enable_static_handler` is on by default
- Directory index (index.php) — built-in implicit routes
- Gzip compression — `http_compression` is on by default
- Directory listing prevention — ZealPHP never lists directories
- PHP file handling — ZealPHP IS the PHP runtime

### What Belongs to a Reverse Proxy (DO NOT convert, just comment)

- SSL termination / HTTPS redirect
- proxy_pass / reverse proxy
- Rate limiting
- ModPagespeed
- ServerSignature
- Server tokens
- IP-based access control

### Server Options (passed to $app->run())

```php
$app->run([
    'task_worker_num' => 0,                    // Task workers (default 0)
    'worker_num' => 4,                         // HTTP workers
    'package_max_length' => 512 * 1024 * 1024, // Max request size (replaces upload_max_filesize)
    'ssl_cert_file' => '/path/cert.pem',       // SSL cert
    'ssl_key_file' => '/path/key.pem',         // SSL key
    'enable_http2' => true,                    // HTTP/2 (requires SSL)
]);
```

### Status-only responses (no body)

Returning an int from a route handler emits an empty-body response with that
status. The framework's ResponseMiddleware discards the output buffer on int
returns, so any echo is dropped. Use this shape for 403/410/404/405/etc:

```php
$app->route('/forbidden', function() { return 403; });
$app->route('/retired',   function() { return 410; });
```

For status WITH a body, set $g->status and return a string:

```php
$app->route('/api/v1/user/{id}', function($id) {
    $g = ZealPHP\G::instance();
    if (!$id) { $g->status = 400; return 'Missing id'; }
    return ['id' => $id];
});
```

### patternRoute() — raw regex routes

When the URL shape doesn't fit `{param}` placeholders (e.g. file-extension
patterns, trailing-slash, security-deny rules), use patternRoute. The argument
is a raw regex (no implicit anchoring), invoked when the request URI matches.

```php
// Block access to dotfiles and log files
$app->patternRoute('/.*\\.(env|log|git|htaccess).*', function() { return 403; });

// Strip trailing slashes (registered LAST so it doesn't shadow /dir/)
$app->patternRoute('/(.+)/$', function() {
    $g = ZealPHP\G::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    header('Location: ' . $path);
    return 301;
});
```

### Method-restricted routes

The second argument to $app->route() can be an options dict containing
`methods` — a list of HTTP verbs the route responds to. Other verbs return 405.

```php
$app->route('/form', ['methods' => ['POST']], function() {
    return 'received';
});
$app->route('/api/users/{id}', ['methods' => ['GET', 'PUT', 'DELETE']], function($id) {
    // …
});
```

If `methods` is omitted, the route accepts GET (and HEAD implicitly).
"""


FEW_SHOT_EXAMPLES = r"""
## Conversion Examples

### Example 1: WordPress .htaccess → app.php (Legacy CMS)

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
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\G;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: WordPress routes everything through index.php. setFallback() replaces the catch-all
RewriteRule. superglobals(true) + ignore_php_ext = false is required because WordPress
reads $_GET, $_POST, $_SESSION directly and uses .php URLs like /wp-login.php.

### Example 2: LAMP app with parameterized rewrites → app.php (LEGACY APP WITH PARAMETERIZED REWRITES)

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
use ZealPHP\G;

// Migration: move these files into the public/ folder:
//   qn.php, watch.php, _data.php, account.php, api.php, profile.php
// Files in public/ are auto-served at their base name (public/qn.php → /qn).
// The routes below add the parameterized variants (e.g. /qn/{id}).

App::superglobals(true);          // legacy *.php files keep reading $_GET unchanged
App::$ignore_php_ext = false;     // allow .php URLs for legacy links

$app = App::init('0.0.0.0', 8080);

$app->route('/qn/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;
    $g->server['SCRIPT_NAME']     = '/qn.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/qn.php';
    App::includeFile(App::$cwd . '/public/qn.php');
});

$app->route('/watch/{v}', function($v) {
    $g = G::instance();
    $g->get['v'] = $v;
    $g->server['SCRIPT_NAME']     = '/watch.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/watch.php';
    App::includeFile(App::$cwd . '/public/watch.php');
});

$app->route('/_/{switch}/{query}', function($switch, $query) {
    $g = G::instance();
    $g->get['switch'] = $switch;
    $g->get['query']  = $query;
    $g->server['SCRIPT_NAME']     = '/_data.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/_data.php';
    App::includeFile(App::$cwd . '/public/_data.php');
});

$app->route('/account/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;
    $g->server['SCRIPT_NAME']     = '/account.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/account.php';
    App::includeFile(App::$cwd . '/public/account.php');
});

$app->route('/account/{id}/{sid}', function($id, $sid) {
    $g = G::instance();
    $g->get['id']  = $id;
    $g->get['sid'] = $sid;
    $g->server['SCRIPT_NAME']     = '/account.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/account.php';
    App::includeFile(App::$cwd . '/public/account.php');
});

$app->route('/api/{rquest}', function($rquest) {
    $g = G::instance();
    $g->get['rquest'] = $rquest;
    $g->server['SCRIPT_NAME']     = '/api.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/api.php';
    App::includeFile(App::$cwd . '/public/api.php');
});

// Note: target captures are reordered ($2 first, $1 as ns) — preserved in $g->get.
$app->route('/api/{ns}/{rquest}', function($ns, $rquest) {
    $g = G::instance();
    $g->get['rquest'] = $rquest;
    $g->get['ns']     = $ns;
    $g->server['SCRIPT_NAME']     = '/api.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/api.php';
    App::includeFile(App::$cwd . '/public/api.php');
});

// Catch-all: unmatched single-segment URLs → profile.php?username=<segment>
$app->setFallback(function() {
    $g = G::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    $g->server['SCRIPT_NAME']     = '/profile.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/profile.php';
    App::includeFile(App::$cwd . '/public/profile.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: This is a LEGACY APP WITH PARAMETERIZED REWRITES — many `.php` files, each rewrite
parses URL captures into query-string params. The whole point of the conversion is to
keep those `.php` files running unchanged. So:
  - App::superglobals(true) makes $g->get proxy to $_GET (in the CGI subprocess), so the
    legacy code reads $_GET['id'] just like under Apache.
  - Each route handler populates $g->get with the captured params (using the target's
    query-key names, NOT generic p1/p2), then App::includeFile()s the target file.
  - public/qn.php at /qn is auto-served — no route needed for the bare path.
  - The trailing profile catch-all rule becomes setFallback() with the same shape.

### Example 3: Redirect rules → app.php

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

$app->route('/old-page', function() {
    header('Location: /new-page');
    return 301;
});

$app->route('/blog/{slug}', function($slug) {
    header('Location: /articles/' . $slug);
    return 302;
});

// HTTPS redirect: handle via reverse proxy (nginx/Caddy) in front of ZealPHP.

$app->run();
```

WHY: Redirect rules become route handlers that return the status code. HTTPS redirect is
a transport concern — belongs to the reverse proxy, not the app server.

### Example 4: Complex .htaccess with mixed directives → app.php

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
use ZealPHP\G;
use ZealPHP\Middleware\CorsMiddleware;

// Migration: move these files into the public/ folder:
//   user.php, search.php, profile.php
// Files in public/ are auto-served at their base name (public/user.php → /user).
// The routes below add the parameterized variants.
// Dropped: ServerSignature, Options, charset, AddType, ModPagespeed, static cache headers.

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

$app->addMiddleware(new CorsMiddleware(['*']));

$app->route('/user/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;
    $g->server['SCRIPT_NAME']     = '/user.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/user.php';
    App::includeFile(App::$cwd . '/public/user.php');
});

$app->route('/search/{q}', function($q) {
    $g = G::instance();
    $g->get['q'] = $q;
    $g->server['SCRIPT_NAME']     = '/search.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/search.php';
    App::includeFile(App::$cwd . '/public/search.php');
});

// Catch-all: unmatched single-segment URLs → profile.php?username=<segment>
$app->setFallback(function() {
    $g = G::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    $g->server['SCRIPT_NAME']     = '/profile.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/profile.php';
    App::includeFile(App::$cwd . '/public/profile.php');
});

$app->run([
    'task_worker_num'    => 0,
    'package_max_length' => 512 * 1024 * 1024,
]);
```

WHY: LEGACY APP WITH PARAMETERIZED REWRITES — each rule maps a clean URL to a `.php` file
in the document root. App::superglobals(true) + App::includeFile() with $g->get populated
delegates to the original file, which reads $_GET unchanged. CORS → CorsMiddleware.
upload_max_filesize → package_max_length. The profile catch-all rule → setFallback() with
the same delegation pattern. Apache-only directives (ServerSignature, Options, charset,
AddType, ModPagespeed, static cache headers) are dropped — handled by OpenSwoole or a
reverse proxy.

### Example 5: nginx CMS config → app.php

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
use ZealPHP\G;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

// Static files served automatically by OpenSwoole.
// Cache headers: configure via reverse proxy or custom middleware.

$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: try_files with /index.php fallback is the CMS front-controller pattern.
This IS a legacy migration — use superglobals + setFallback + includeFile.

### Example 6: Laravel public/.htaccess → app.php (LEGACY CMS / FRAMEWORK-DETECTED)

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
use ZealPHP\G;

// Migration: this is a Laravel front-controller app. All requests flow through
// public/index.php (Laravel's bootstrap). Static files in public/ are auto-served
// by OpenSwoole.
//
// The original .htaccess contained `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
// — that rule is intentionally NOT converted. It exists to work around Apache+PHP-FPM
// stripping the Authorization header; ZealPHP exposes it natively via
// $_SERVER['HTTP_AUTHORIZATION'] (populated from OpenSwoole's header array).
//
// Note: each request runs Laravel's bootstrap inside a CGI subprocess (process-isolated
// global scope). For warm-start performance consider `App::onWorkerStart(...)` to preload
// the framework. For deployment performance, look at Laravel Octane patterns separately.

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

// Trailing-slash strip (the Laravel .htaccess does this; mirror it here).
$app->patternRoute('/(.+)/$', function() {
    $g = G::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    header('Location: ' . $path);
    return 301;
});

$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF']        = '/index.php';
    $g->server['SCRIPT_NAME']     = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

$app->run(['task_worker_num' => 0]);
```

WHY: Laravel is a front-controller framework — every request flows through
`public/index.php`. That's a textbook mode-B migration: `superglobals(true)` so
Laravel's bootstrap sees `$_GET`/`$_POST`/`$_SESSION`, plus `setFallback()` that
`includeFile()`s the entry point. The HTTP_AUTHORIZATION env-var rule is a
PHP-FPM workaround Apache+CGI needs but ZealPHP doesn't — drop it (the comment
explains why so the user doesn't think it was missed). The trailing-slash rule
becomes an explicit `patternRoute` registered before the fallback so it can take
precedence on URLs that end with `/`.

### Example 7: nginx LAMP-style with multiple location/rewrite blocks (NGINX MODE-A)

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

    # Profile catch-all
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
use ZealPHP\G;

// Migration: move these files into the public/ folder:
//   qn.php, watch.php, api.php, profile.php
// Files in public/ are auto-served at their base name (public/qn.php → /qn).
// The routes below add the parameterized variants.
// Dropped: gzip on (OpenSwoole http_compression handles it), index directive,
// and the `location ~ \.php$ { fastcgi_pass ... }` block (ZealPHP IS the PHP runtime).

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

// Static response headers from `add_header` directives
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $response = $handler->handle($request);
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
});

$app->route('/qn/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;
    $g->server['SCRIPT_NAME']     = '/qn.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/qn.php';
    App::includeFile(App::$cwd . '/public/qn.php');
});

$app->route('/watch/{v}', function($v) {
    $g = G::instance();
    $g->get['v'] = $v;
    $g->server['SCRIPT_NAME']     = '/watch.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/watch.php';
    App::includeFile(App::$cwd . '/public/watch.php');
});

$app->route('/api/{ns}/{rquest}', function($ns, $rquest) {
    $g = G::instance();
    $g->get['ns']     = $ns;
    $g->get['rquest'] = $rquest;
    $g->server['SCRIPT_NAME']     = '/api.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/api.php';
    App::includeFile(App::$cwd . '/public/api.php');
});

// try_files $uri ... /profile.php?username=$uri → setFallback that delegates to profile.php
$app->setFallback(function() {
    $g = G::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $g->get['username'] = $username;
    $g->server['SCRIPT_NAME']     = '/profile.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/profile.php';
    App::includeFile(App::$cwd . '/public/profile.php');
});

$app->run([
    'task_worker_num'    => 0,
    'package_max_length' => 100 * 1024 * 1024,
]);
```

WHY: nginx `rewrite … last;` inside a `location` block and Apache
`RewriteRule … [L]` produce the same migration shape — the converter is
rewrite-shape-agnostic. The `location ~ \.php$ { fastcgi_pass ... }` block is
ZealPHP's whole reason to exist, so it's dropped entirely (with a header
comment). `client_max_body_size 100M` → `package_max_length`. `gzip on` is
dropped because OpenSwoole's `http_compression` is on by default. Two top-level
`add_header` directives collapse into one anonymous PSR-15 middleware with two
`->withHeader()` calls. The `try_files $uri $uri/ /profile.php?username=$uri`
catch-all becomes `setFallback()` with the same `$g->get` + `App::includeFile()`
pattern used in mode-A routes.

### Example 8: Apache .htaccess with security blocks, response headers, [F]/[G], ErrorDocument

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
ErrorDocument 404 /not-found.html

# A normal app route (mode-A)
RewriteRule ^/?article/([^/]+)?$ "article.php?slug=$1" [L,QSA]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\G;

// Migration: move these files into the public/ folder:
//   article.php, not-found.html

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

// Static response headers from `Header set` directives
$app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
    public function process(
        \Psr\Http\Message\ServerRequestInterface $request,
        \Psr\Http\Server\RequestHandlerInterface $handler
    ): \Psr\Http\Message\ResponseInterface {
        $response = $handler->handle($request);
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
});

// Security: deny access to sensitive file patterns (<FilesMatch> + Require all denied)
$app->patternRoute('/.*\\.(env|log|git|htaccess).*', function() { return 403; });

// [F] flag: forbid private/ tree
$app->patternRoute('/private/.*', function() { return 403; });

// [G] flag: retired URLs
$app->patternRoute('/old-section/.+', function() { return 410; });

// Mode-A route
$app->route('/article/{slug}', function($slug) {
    $g = G::instance();
    $g->get['slug'] = $slug;
    $g->server['SCRIPT_NAME']     = '/article.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/article.php';
    App::includeFile(App::$cwd . '/public/article.php');
});

// Trailing-slash strip — register AFTER explicit routes
$app->patternRoute('/(.+)/$', function() {
    $g = G::instance();
    $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    header('Location: ' . $path);
    return 301;
});

// ErrorDocument 404 → custom 404 fallback
$app->setFallback(function() {
    $g = G::instance();
    $g->status = 404;
    $g->server['SCRIPT_NAME']     = '/not-found.html';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/not-found.html';
    App::includeFile(App::$cwd . '/public/not-found.html');
});

$app->run(['task_worker_num' => 0]);
```

WHY: every new pattern in one place. `<FilesMatch>` + `Require all denied`
becomes a `patternRoute` returning 403. `[F]` and `[G]` flags become
status-only int returns (ZealPHP's framework discards the buffer on int returns,
so the body is empty — that's the standard "status only" shape). Three
top-level `Header set` directives collapse into one PSR-15 middleware. The
trailing-slash strip is an explicit `patternRoute` registered AFTER explicit
routes so it doesn't shadow URLs that intentionally end with `/`.
`ErrorDocument 404` becomes `setFallback` with `$g->status = 404`, replacing
the framework's hard-coded `<pre>404 Not Found</pre>` message with the user's
custom page.
"""


CONVERTER_INSTRUCTIONS = """You convert Apache .htaccess and nginx server configs into ZealPHP app.php files.

WORKFLOW:
1. Call get_zealphp_reference() to get the ZealPHP API reference
2. Call get_conversion_examples() to see correct conversion examples
3. Classify the input — LEGACY CMS, LEGACY APP WITH PARAMETERIZED REWRITES, or MODERN APP (see rules below)
4. Generate a COMPLETE app.php
5. Call validate_conversion() with the original and your output to check for issues
6. If issues found, fix and output the corrected version

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
      - App::$ignore_php_ext = false   — allow .php URLs for legacy links
      - One $app->route() per parameterized rewrite. Each handler:
          * populates $g->get with the captured params (named by the target's query keys)
          * sets $g->server['SCRIPT_NAME'] and SCRIPT_FILENAME for the target
          * calls App::includeFile(App::$cwd . '/public/<target>.php')
      - The catch-all profile rule (RewriteCond REQUEST_FILENAME !-f + a username capture)
        becomes $app->setFallback() with the same shape
      - The bare path (/qn) gets NO route — public/qn.php is auto-served already
      - This is the ONLY mode that uses App::includeFile() in route handlers

(B) LEGACY CMS (WordPress, Drupal, Laravel, Joomla, etc.).
    Trigger: the ONLY meaningful RewriteRule is the front-controller catch-all
    (`RewriteRule . /index.php [L]` or `try_files $uri /index.php`) and there are no
    parameterized rewrites that target other .php files.
    Output shape:
      - App::superglobals(true), App::$ignore_php_ext = false
      - setFallback() that includeFile()s /public/index.php (no per-URL routes)

(C) MODERN APP — fallthrough only.
    Trigger: rewrites map to non-PHP handlers, or the entry is a single Slim/Laravel-style
    front controller AND the user has explicitly said they want fresh handlers.
    Output shape:
      - $app->route() with {param} syntax and inline handler logic (no includeFile)
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
// Files in public/ are auto-served at their base name (public/qn.php → /qn).
// The routes below add the parameterized variants (e.g. /qn/{id}).

RULE 2 — ONLY CREATE ROUTES FOR PARAMETERIZED URLs:
RewriteRules with capture groups like `^/?qn/([^/]+)?$` need routes because the URL
has a parameter. A plain RewriteRule mapping `/qn` → `qn.php` does NOT need a route
because public/qn.php is auto-served at /qn.

RULE 3 — IN MODE (A), ROUTE HANDLERS MUST DELEGATE VIA App::includeFile():
The whole point of mode (A) conversion is to keep legacy *.php files running unchanged.
Each parameterized-rewrite route handler MUST follow this template:

   $app->route('/<path>/{<key1>}/{<key2>}', function($<key1>, $<key2>) {
       $g = \\ZealPHP\\G::instance();
       $g->get['<key1>'] = $<key1>;
       $g->get['<key2>'] = $<key2>;
       $g->server['SCRIPT_NAME']     = '/<target>.php';
       $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/<target>.php';
       App::includeFile(App::$cwd . '/public/<target>.php');
   });

Per-rewrite generation rule. For each rule of the form
`RewriteRule ^/?<path>/(...)/?$ "<target>.php?<keyA>=$1&<keyB>=$2" [L,QSA]`:
  1. Use the QUERY-STRING KEY NAMES from the target for the {param} placeholders.
     E.g. `account.php?id=$1&sid=$2` → `/account/{id}/{sid}`, NOT `/account/{p1}/{p2}`.
  2. Emit the handler body above, with $g->get[<key>] = $<key> for every captured param.
  3. If the target is the same file but with different query-key sets (e.g. account.php
     with just id vs id+sid), emit ONE route per signature.
  4. Never emit `return null;`, `return;`, or a stub body — the handler must call
     App::includeFile() in mode (A) and contain inline logic in mode (C).

RULE 4 — DO NOT CREATE ROUTES FOR THINGS THE FRAMEWORK HANDLES:
- Base URLs for files in public/ → auto-served, no route needed
- .php extension blocking → built-in (App::$ignore_php_ext defaults to true)
- Extensionless URL resolution → built-in
- Trailing slash removal → not needed in ZealPHP
- Directory index files → built-in

Only create routes for: parameterized URLs, redirects [R=301], and catch-all fallbacks.

ADDITIONAL RULES:

1. NEVER fabricate API that doesn't exist:
   - App::init() takes ($host, $port, $cwd) — NEVER pass arrays or config objects
   - There is NO App::init(['phpSettings' => ...])
   - There is NO $app->config() or $app->setting()

2. Use {param} syntax, NOT raw regex:
   - WRONG: $app->route('/user/([^/]+)', function($matches) { $_GET['id'] = $matches[1]; })
   - RIGHT: $app->route('/user/{id}', function($id) { ... })
   - Parameters are injected BY NAME via reflection

3. PREFER App::includeFile() over raw require/include in route handlers:
   - In LEGACY APP WITH PARAMETERIZED REWRITES mode, route handlers MUST call
     App::includeFile() to delegate to the original PHP file. This is the whole
     point of the conversion — keep legacy *.php files running.
   - WRONG: $app->route('/u/{id}', function($id) { require 'user.php'; });
   - RIGHT: $app->route('/u/{id}', function($id) {
              $g = \\ZealPHP\\G::instance(); $g->get['id'] = $id;
              App::includeFile(App::$cwd . '/public/user.php');
            });
   - In MODERN APP mode, write handler logic directly — no includeFile.

4. NEVER use exit() or die() — not safe in OpenSwoole coroutine context

5. DROP Apache/nginx directives that don't apply — ONE brief comment for ALL dropped items:
   - ServerSignature, Options -Indexes, AddType, AddCharset, ModPagespeed, static cache headers
   - .php extension blocking, extensionless PHP URL resolution → built-in
   - But NEVER drop RewriteRules with capture groups — those MUST become routes

6. CORS (Access-Control-Allow-Origin) → $app->addMiddleware(new CorsMiddleware(['*']))

7. upload_max_filesize / post_max_size → package_max_length in $app->run()

8. Redirect RewriteRules [R=301] → route with header('Location: ...'); return 301;

9. Catch-all profile/fallback rule → $app->setFallback() — in mode (A), the fallback
   body uses the same $g->get + includeFile() pattern as a regular route.

APACHE FLAG / RULE HANDLING (compact table — apply per rewrite):
  [F]                                → emit a route or patternRoute returning 403
  [G]                                → emit a route or patternRoute returning 410
  [R=301] / [R=302] / [R=307]        → route with header('Location: ...'); return <status>;
  [E=VAR:value]                      → $g->server['VAR'] = 'value'; inside the handler
  [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]  → DROP this rule with a comment.
                                       ZealPHP exposes the Authorization header
                                       natively via $_SERVER['HTTP_AUTHORIZATION'].
  [L]                                → no-op (ZealPHP routes are last-match by default)
  [QSA]                              → no-op for our $g->get assignment pattern
  [NC]                               → no-op (the {param} regex is broad)
  [OR] between RewriteConds          → combine inside the handler body if needed
  <Files X> Deny from all            → $app->patternRoute('/X', function() { return 403; });
  <FilesMatch "\\.(env|log|git)$"> Deny from all / Require all denied
                                     → $app->patternRoute('/.*\\.(env|log|git).*',
                                                          function() { return 403; });
  Allow from <ip> / Deny from <ip>   → comment: "IP ACL belongs in reverse proxy"
  ErrorDocument N /path              → setFallback that emits /path with $g->status = N
  Redirect / RedirectMatch           → route returning 301 + header('Location: ...')

STATIC RESPONSE HEADERS:
  Apache `Header set X-Foo "bar"` (top-level, NOT inside <FilesMatch>) and nginx
  `add_header X-Foo "bar";` (top-level inside server {}) are GLOBAL static headers
  applied to every response. Collect all such directives and emit ONE inline anonymous
  PSR-15 middleware. Skip CORS headers (those go to CorsMiddleware) and cache headers
  inside <FilesMatch>/extension blocks (OpenSwoole's static handler or a reverse proxy
  handles those — emitting a route-pattern middleware for them is a poor fit).

  Emit pattern:

    $app->addMiddleware(new class implements \\Psr\\Http\\Server\\MiddlewareInterface {
        public function process(
            \\Psr\\Http\\Message\\ServerRequestInterface $request,
            \\Psr\\Http\\Server\\RequestHandlerInterface $handler
        ): \\Psr\\Http\\Message\\ResponseInterface {
            $response = $handler->handle($request);
            return $response
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }
    });

  One ->withHeader() call per Header/add_header directive collected.
  If there are NO static headers, do not emit this block.

TRAILING-SLASH STRIP:
  Apache `RewriteRule ^(.+)/$ /$1 [L,R=301]` and nginx `rewrite ^(.+)/$ /$1 permanent;`
  both strip a trailing slash from non-directory URLs. ZealPHP's built-in
  App::$directory_slash only handles the OPPOSITE direction (adds slash for dirs).
  Emit a patternRoute that mirrors the original rule, registered AFTER all explicit
  routes so it doesn't shadow paths that intentionally end with /:

    $app->patternRoute('/(.+)/$', function() {
        $g = \\ZealPHP\\G::instance();
        $path = rtrim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        header('Location: ' . $path);
        return 301;
    });

NGINX MODE-A EQUIVALENT (same shape as Apache mode A):
  Both nginx forms compile to the same ZealPHP shape:

    rewrite ^/qn/([^/]+)$ /qn.php?id=$1 last;
    location ~* ^/api/(.+)$ { rewrite ^/api/(.+) /api.php?action=$1 last; }

  → $app->route('/qn/{id}', function($id) {
        $g = G::instance();
        $g->get['id'] = $id;
        $g->server['SCRIPT_NAME']     = '/qn.php';
        $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/qn.php';
        App::includeFile(App::$cwd . '/public/qn.php');
    });

  Drop the `location` wrapper — it's just nginx scoping.
  Drop the `last` / `break` / `permanent` flags — ZealPHP routes are implicitly terminal.
  The whole `location ~ \\.php$ { fastcgi_pass ... }` block: DROP it entirely. ZealPHP IS
  the PHP runtime; there is no PHP-FPM to forward to.
  nginx `client_max_body_size 100M;` → `package_max_length` in $app->run() options.
  nginx `return N;` → route returning N (302/301/308 go through the redirect pattern).
  nginx `error_page N /path;` → setFallback that includeFile()s /path with $g->status = N.
  nginx `gzip on;` and `gzip_types ...;` → drop (OpenSwoole http_compression is on by default).
  nginx `proxy_pass`, `proxy_set_header`, `auth_basic` → drop with a comment pointing to a
  reverse proxy. ZealPHP is the app server, not the edge.

FRAMEWORK DETECTION (run BEFORE classification):
  Detect the originating framework from signature patterns. All matches classify as MODE B
  (front-controller via setFallback + includeFile('/public/index.php')). When detected,
  emit `// Detected: <framework>` as the FIRST comment line of app.php and apply the
  framework-specific tweak:

    WordPress    : `RewriteRule ^index\\.php$ - [L]` + the standard `!-f !-d → /index.php`
                   catchall.    Tweak: none — mode B verbatim.
    Laravel      : `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`
                   AND `RewriteRule ^ index.php [L]` AND a trailing-slash redirect.
                   Tweak: DROP the HTTP_AUTHORIZATION env-var rule. Add a comment that
                   ZealPHP exposes the Authorization header natively and that each request
                   spawns a CGI subprocess (heavy for Laravel bootstrap; consider
                   App::onWorkerStart() for preloading).
    Symfony      : `RewriteCond %{ENV:REDIRECT_STATUS}` AND `RewriteRule ^ ... index.php`.
                   Tweak: same as Laravel — drop HTTP_AUTHORIZATION workaround.
    Drupal       : `RewriteRule ^(.*)$ index.php?q=$1`.  Tweak: note that the path arrives
                   as $_GET['q'] inside the front-controller.
    CodeIgniter  : `RewriteRule ^(.*)$ index.php?/$1`.  Tweak: note path-as-PATH_INFO.

OUTPUT FORMAT:
- Output ONLY the PHP code — no markdown fences, no explanations before/after
- Include: <?php, require, use statements, App::init(), routes, $app->run()
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

    # Structural checks
    if "app::init" not in code_lower:
        issues.append("Missing App::init() — every app.php needs $app = App::init('0.0.0.0', port)")

    if "$app->run()" not in zealphp_code and "$app->run([" not in zealphp_code:
        issues.append("Missing $app->run() — server won't start without it")

    if "app::init([" in code_lower or "app::init({" in code_lower:
        issues.append("App::init() takes ($host, $port, $cwd) — NOT arrays or config objects")

    # Anti-pattern checks. The legacy-with-rewrites mode legitimately uses
    # App::includeFile() inside route handlers, so we no longer flag include/require
    # by default — the missing-delegation check below catches the opposite failure.

    if "$matches[" in code_lower:
        issues.append("Do not use $matches[] — use {param} syntax and named function parameters")

    if "$_get[" in code_lower and "superglobals(true)" not in code_lower:
        issues.append("Do not assign to $_GET in modern mode — use {param} injection instead")

    if "exit" in code_lower.split("//")[0] or "die(" in code_lower:
        issues.append("Never use exit()/die() — not safe in OpenSwoole coroutine context")

    # Missing conversion checks
    if "rewritecond %{https}" in original_lower or "ssl" in original_lower:
        if "ssl" not in code_lower and "reverse proxy" not in code_lower and "proxy" not in code_lower:
            issues.append("SSL/HTTPS config found — note reverse proxy or add ssl options to $app->run()")

    if "proxy_pass" in original_lower or "proxypass" in original_lower:
        if "proxy" not in code_lower:
            issues.append("Reverse proxy directives found — add comment that a reverse proxy should be used")

    if "auth_basic" in original_lower or "htpasswd" in original_lower:
        if "middleware" not in code_lower and "auth" not in code_lower:
            issues.append("Basic auth found — note that this should be implemented as middleware")

    if "rewriterule" in original_lower:
        if "setfallback" not in code_lower and "route(" not in code_lower:
            issues.append("RewriteRules found but no setFallback() or route() — conversion may be incomplete")

    # Count RewriteRules with capture groups vs route() calls
    import re
    capture_rules = len(re.findall(r'rewriterule\s+\S*\([^)]+\)', original_lower))
    route_calls = zealphp_code.count("->route(")
    if capture_rules > 0 and route_calls < capture_rules // 2:
        issues.append(
            f"CRITICAL: Found {capture_rules} RewriteRules with capture groups but only "
            f"{route_calls} route() calls. Every parameterized RewriteRule MUST become a route. "
            f"Add the missing routes."
        )

    if "access-control-allow-origin" in original_lower:
        if "corsmiddleware" not in code_lower:
            issues.append("CORS header found — use CorsMiddleware instead of manual headers")

    if "upload_max_filesize" in original_lower or "post_max_size" in original_lower:
        if "package_max_length" not in code_lower:
            issues.append("Upload size config found — use package_max_length in $app->run() options")

    # LEGACY-WITH-PARAMETERIZED-REWRITES mode checks.
    # If any RewriteRule targets a *.php file with query params, the conversion is in
    # mode (A) and each parameterized route MUST delegate via App::includeFile(); the
    # output also needs App::superglobals(true) so the included file sees $_GET.
    php_target_rewrites = re.findall(
        r'rewriterule\s+\S+\s+["\']?(\w+)\.php\?',
        original_lower,
    )
    if php_target_rewrites:
        includefile_calls = code_lower.count("app::includefile(")
        route_calls       = zealphp_code.count("->route(")
        # Every parameterized route should delegate; allow one "free" route for redirects.
        expected_delegations = min(route_calls, len(php_target_rewrites))
        if route_calls > 0 and includefile_calls < expected_delegations:
            issues.append(
                f"CRITICAL: {len(php_target_rewrites)} rewrite(s) target *.php files but only "
                f"{includefile_calls} App::includeFile() call(s) appear in route handlers. "
                "Each parameterized rewrite must delegate to the target file via "
                "App::includeFile() after populating $g->get from URL params. A stub body "
                "like 'return null;' does not execute the original file."
            )
        if "superglobals(true)" not in code_lower:
            issues.append(
                "Rewrites target *.php files but App::superglobals(true) is missing. "
                "Without it, the included legacy file cannot read $_GET — add "
                "App::superglobals(true) and App::$ignore_php_ext = false before App::init()."
            )

    # [F] / [G] flag handling — must compile to a status-only int return.
    # original_lower is already lowercased; match the lowercase flag letter.
    if re.search(r'\[\s*f\s*[,\]]', original_lower) or re.search(r',\s*f\s*\]', original_lower):
        if "return 403" not in code_lower and "return(403" not in code_lower:
            issues.append(
                "[F] forbidden flag found — emit a route or patternRoute returning 403."
            )

    if re.search(r'\[\s*g\s*[,\]]', original_lower) or re.search(r',\s*g\s*\]', original_lower):
        if "return 410" not in code_lower and "return(410" not in code_lower:
            issues.append(
                "[G] gone flag found — emit a route or patternRoute returning 410."
            )

    # HTTP_AUTHORIZATION Laravel/Symfony workaround — must be DROPPED, not converted
    if "http_authorization" in original_lower and "%{http:authorization}" in original_lower:
        # Acceptable: a drop-comment mentioning authorization. Unacceptable: the rule was
        # turned into code that assigns/copies the header (env_set, $g->server['HTTP_AUTHORIZATION'] = ...).
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
        if "return 403" not in code_lower and "return(403" not in code_lower:
            issues.append(
                "<Files>/<FilesMatch> deny rule found — emit a patternRoute returning 403 "
                "for those file patterns (e.g. \\.env, \\.git, \\.log)."
            )

    # nginx `return N;` statements (other than 200 / 3xx redirects)
    nginx_returns = re.findall(r'(?:^|\s)return\s+(\d{3})\b', original_lower)
    nginx_returns = [s for s in set(nginx_returns) if s not in ('200', '301', '302', '307', '308')]
    for status in nginx_returns:
        if f"return {status}" not in code_lower:
            issues.append(
                f"nginx `return {status}` found — emit a route returning {status}."
            )

    # Static response headers — when input has `Header set X` or `add_header X` (and X
    # isn't access-control-*), the output must contain ->withHeader() in a middleware.
    header_set_lines = re.findall(
        r'(?:^|\n)\s*(?:header\s+set|add_header)\s+([A-Za-z][\w-]*)',
        original_lower,
    )
    non_cors_headers = [h for h in header_set_lines if not h.startswith('access-control-')]
    if non_cors_headers and "withheader(" not in code_lower:
        issues.append(
            f"Static response headers found in input ({len(non_cors_headers)} non-CORS "
            "directives) — emit an inline anonymous PSR-15 middleware that withHeader()s "
            "them. CorsMiddleware covers Access-Control-* only; other headers need the "
            "anonymous middleware shape."
        )

    # Trailing-slash strip rule
    has_apache_slash_strip = bool(
        re.search(r'rewriterule\s+\^\(?\.\+\)?/?\$?\s+/\$?1', original_lower)
        and "r=301" in original_lower
    )
    has_nginx_slash_strip = bool(
        re.search(r'rewrite\s+\^\(?\.\+\)?/?\$?\s+/\$?1\s+permanent', original_lower)
    )
    if has_apache_slash_strip or has_nginx_slash_strip:
        if "patternroute" not in code_lower or "return 301" not in code_lower:
            issues.append(
                "Trailing-slash strip rule found — emit a patternRoute('/(.+)/$', ...) that "
                "returns 301 with a Location header to the path without trailing slash."
            )

    # Framework detection — when a known signature is in the input, the output should
    # carry a `// Detected: <framework>` comment and use mode-B (setFallback).
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
                f"$app->setFallback() that App::includeFile()s public/index.php."
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

    print("Apache/nginx → ZealPHP Converter (gpt-5.4-mini)")
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
