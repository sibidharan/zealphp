#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
Apache/nginx → ZealPHP Converter Agent
=======================================
Converts .htaccess or nginx config into a ZealPHP app.php.
Uses gpt-4.1-mini with streaming, few-shot examples, and tool-assisted validation.

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

### Example 2: Custom app with URL rewrites → app.php (Modern)

INPUT:
```
RewriteEngine On
RewriteBase /

RewriteRule ^/?user/([^/]+)?$ "user.php?id=$1" [L,QSA]
RewriteRule ^/?user/([^/]+)/([^/]+)?$ "user.php?id=$1&tab=$2" [L,QSA]
RewriteRule ^/?search/([^/]+)?$ "search.php?q=$1" [L,QSA]
RewriteRule ^/?api/([^/]+)?$ "api.php?action=$1" [L,QSA]

RewriteCond %{THE_REQUEST} ^(.+)\.php([#?][^\ ]*)?\ HTTP/
RewriteRule ^(.+)\.php$ "/$1" [R=404,L]

RewriteCond %{REQUEST_FILENAME}\.php -f
RewriteRule ^([^/.]+)$ $1.php [L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

// Migration: move user.php, search.php, api.php into the public/ folder.
// Once in public/, base URLs are auto-served: /user, /search, /api.
// Only the parameterized URL patterns below need explicit routes.

$app = App::init('0.0.0.0', 8080);

$app->route('/user/{id}', function($id) {
    // Handle user page with id
});

$app->route('/user/{id}/{tab}', function($id, $tab) {
    // Handle user page with id and tab
});

$app->route('/search/{q}', function($q) {
    // Handle search
});

$app->route('/api/{action}', function($action) {
    // Handle API action
});

// .php extension blocking + extensionless PHP URLs are built-in.

$app->run(['task_worker_num' => 0]);
```

WHY: This is NOT a CMS front-controller pattern. Each RewriteRule maps a clean URL to a
specific PHP file with query params. PHP files go in public/ (auto-served at base URL).
Only parameterized URLs (/user/{id}) need explicit routes — the base URLs (/user, /search)
are handled automatically. No include/require, no superglobals.

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
use ZealPHP\Middleware\CorsMiddleware;

// Migration: move user.php, search.php, profile.php into the public/ folder.
// Base URLs (/user, /search) are auto-served. Only parameterized patterns need routes.
// Dropped: ServerSignature, Options, charset, AddType, ModPagespeed, static cache headers.

$app = App::init('0.0.0.0', 8080);

$app->addMiddleware(new CorsMiddleware(['*']));

$app->route('/user/{id}', function($id) {
    // Handle user page with id
});

$app->route('/search/{q}', function($q) {
    // Handle search with query
});

// Catch-all: unmatched URLs → profile page
$app->setFallback(function($request) {
    $g = \ZealPHP\G::instance();
    $username = trim(parse_url($g->server['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    // Handle profile for $username
});

$app->run([
    'task_worker_num' => 0,
    'package_max_length' => 512 * 1024 * 1024,
]);
```

WHY: Most directives are Apache-specific and don't apply. PHP files go in public/ — auto-served
at base URLs. Only parameterized URLs (/user/{id}) need routes. CORS → CorsMiddleware.
upload_max_filesize → package_max_length. Catch-all profile rule → setFallback().

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
"""


CONVERTER_INSTRUCTIONS = """You convert Apache .htaccess and nginx server configs into ZealPHP app.php files.

WORKFLOW:
1. Call get_zealphp_reference() to get the ZealPHP API reference
2. Call get_conversion_examples() to see correct conversion examples
3. Classify the input as LEGACY CMS or MODERN APP (see rules below)
4. Generate a COMPLETE app.php
5. Call validate_conversion() with the original and your output to check for issues
6. If issues found, fix and output the corrected version

CLASSIFICATION RULES — this determines the entire conversion strategy:

LEGACY CMS (WordPress, Drupal, Laravel, Joomla, etc.):
- Has a front-controller pattern: `RewriteRule . /index.php [L]` or `try_files $uri /index.php`
- Everything routes through ONE entry PHP file
- Use: App::superglobals(true), App::$ignore_php_ext = false, setFallback() + includeFile()

MODERN APP (custom app with clean URL rewrites):
- Has many RewriteRules mapping clean URLs to specific PHP files with query params
- Each URL pattern maps to a different PHP file
- Use: $app->route() with {param} syntax for PARAMETERIZED URLs only
- DO NOT use superglobals(true) unless the app truly needs it

THE MOST IMPORTANT RULES:

RULE 1 — ALWAYS START WITH THE MIGRATION STEP:
The output MUST begin with a comment telling the user to move files:
// Migration: move all PHP files from your Apache document root into the public/ folder.
// Files in public/ are auto-served: public/qn.php → /qn, public/watch.php → /watch, etc.

RULE 2 — ONLY CREATE ROUTES FOR PARAMETERIZED URLs:
RewriteRules with capture groups like `^/?qn/([^/]+)?$` need routes because the URL
has a parameter. But a plain RewriteRule mapping `/qn` → `qn.php` does NOT need a route
because public/qn.php is auto-served at /qn.

Example: `RewriteRule ^/?qn/([^/]+)?$ "qn.php?id=$1" [L,QSA]`
→ The route for /qn/{id}: `$app->route('/qn/{id}', function($id) { /* handle */ });`
→ But /qn itself does NOT need a route — public/qn.php handles it.

RULE 3 — DO NOT CREATE ROUTES FOR THINGS THE FRAMEWORK HANDLES:
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

3. NEVER use include/require in route handlers:
   - WRONG: $app->route('/user/{id}', function($id) { require 'user.php'; })
   - RIGHT: $app->route('/user/{id}', function($id) { /* handler logic */ })

4. NEVER use exit() or die() — not safe in OpenSwoole coroutine context

5. DROP Apache/nginx directives that don't apply — ONE brief comment for ALL dropped items:
   - ServerSignature, Options -Indexes, AddType, AddCharset, ModPagespeed, static cache headers
   - .php extension blocking, extensionless PHP URL resolution → built-in
   - But NEVER drop RewriteRules with capture groups — those MUST become routes

6. CORS (Access-Control-Allow-Origin) → $app->addMiddleware(new CorsMiddleware(['*']))

7. upload_max_filesize / post_max_size → package_max_length in $app->run()

8. Redirect RewriteRules [R=301] → route with header('Location: ...'); return 301;

9. Catch-all profile/fallback rule → $app->setFallback()

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

    # Anti-pattern checks
    if "require __dir__" in code_lower or "require_once __dir__" in code_lower:
        if "vendor/autoload" not in code_lower or code_lower.count("require") > 1:
            issues.append("Do not use require/include in route handlers — handlers should contain logic directly")

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

    print("Apache/nginx → ZealPHP Converter (gpt-4.1-mini)")
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
