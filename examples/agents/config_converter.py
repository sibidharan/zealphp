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


ZEALPHP_REFERENCE = """
## ZealPHP Framework Reference

ZealPHP is a PHP web framework built on OpenSwoole. It serves as both a modern async
framework AND a backwards-compatible Apache/nginx replacement for legacy PHP apps.

### Core Concepts

**app.php** is the entry point. It configures the framework, defines routes, and starts the server.
**public/** is the web root — static files and PHP page files live here.
**route/** contains route files auto-included at startup.

### Route Registration

```php
// Basic route
$app->route('/path', function() { echo "hello"; });

// With HTTP methods and URL params (Flask-style {param} syntax)
$app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id, $request, $response) {
    return ['id' => $id];  // Auto JSON
});

// Namespace route (adds prefix)
$app->nsRoute('admin', '/dashboard', function() { ... });

// Namespace path route (directory-style)
$app->nsPathRoute('api', '{resource}', ['methods' => ['GET','POST','PUT','DELETE']], function($resource) { ... });

// Regex pattern route
$app->patternRoute('/files/.*', function() { ... });
```

### Fallback Handler (replaces .htaccess RewriteRule)

```php
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});
```

### Legacy App Mode

```php
App::superglobals(true);        // Enable $_GET, $_POST, $_SESSION, $_COOKIE, $_SERVER
App::$ignore_php_ext = false;   // Allow .php extensions in URLs
```

### App Initialization & Server

```php
$app = App::init('0.0.0.0', 8080);  // Always assign to $app
// ... define routes ...
$app->run(['task_worker_num' => 0, 'worker_num' => 4]);
```

### Redirect
```php
$app->route('/old', function() {
    header('Location: /new');
    return 301;
});
```

### Middleware
```php
$app->addMiddleware(new CorsMiddleware(['*']));
$app->addMiddleware(new ETagMiddleware());
```

### Static Files
OpenSwoole's `enable_static_handler` serves CSS, JS, images from `public/` automatically.
No config needed — equivalent to nginx `location ~* \\.(css|js|png)$`.

### SSL/HTTP2
```php
$app->run([
    'ssl_cert_file' => '/path/to/cert.pem',
    'ssl_key_file' => '/path/to/key.pem',
    'enable_http2' => true,
]);
```

### CLI Management
```
php app.php start -p 9501 -d   # Start daemonized on port 9501
php app.php stop               # Stop the server
php app.php status             # Check if running
```
"""


FEW_SHOT_EXAMPLES = """
## Conversion Examples

### Example 1: WordPress .htaccess → app.php

INPUT:
```
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\\App;
use ZealPHP\\G;

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

### Example 2: nginx with API + static caching → app.php

INPUT:
```
server {
    listen 80;
    root /var/www/html;
    location / { try_files $uri $uri/ /index.php?$args; }
    location ~ \\.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; }
    location /api/ { proxy_pass http://localhost:3000; }
    location ~* \\.(css|js|png)$ { expires 30d; }
}
```

OUTPUT:
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\\App;
use ZealPHP\\G;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);

// Note: location /api/ proxy_pass cannot be replicated in ZealPHP.
// Use native ZealPHP routes or a reverse proxy in front of ZealPHP.

// Static files (css, js, png) served automatically by OpenSwoole — no config needed.
// For cache headers, add custom middleware.

$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

$app->run(['task_worker_num' => 0]);
```

### Example 3: Redirect-heavy .htaccess → app.php

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
use ZealPHP\\App;

$app = App::init('0.0.0.0', 8080);

$app->route('/old-page', function() {
    header('Location: /new-page');
    return 301;
});

$app->patternRoute('/blog/.*', ['methods' => ['GET']], function($response) {
    $uri = $_SERVER['REQUEST_URI'];
    $path = preg_replace('#^/blog/#', '/articles/', $uri);
    header('Location: ' . $path);
    return 302;
});

// Note: HTTPS redirect should be handled by a reverse proxy
// (nginx/Caddy) in front of ZealPHP, or via middleware.

$app->run();
```
"""


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

    if "rewritecond %{https}" in original_lower or "ssl" in original_lower:
        if "ssl_cert" not in zealphp_code.lower() and "https" not in zealphp_code.lower():
            issues.append("SSL/HTTPS config found — add ssl_cert_file/ssl_key_file to $app->run() or note reverse proxy")

    if "proxy_pass" in original_lower or "proxypass" in original_lower:
        if "proxy" not in zealphp_code.lower():
            issues.append("Reverse proxy directives found — ZealPHP doesn't proxy; note this in a comment")

    if "auth_basic" in original_lower or "htpasswd" in original_lower:
        issues.append("Basic auth found — implement as ZealPHP middleware")

    if "rewriterule" in original_lower and "setfallback" not in zealphp_code.lower() and "route" not in zealphp_code.lower():
        issues.append("RewriteRule found but no setFallback() or route() — conversion may be incomplete")

    if "app::init" not in zealphp_code.lower():
        issues.append("Missing App::init() — every app.php needs $app = App::init('0.0.0.0', port)")

    if "$app->run()" not in zealphp_code and "$app->run([" not in zealphp_code:
        issues.append("Missing $app->run() — server won't start without it")

    if not issues:
        return "Conversion looks correct — all directives accounted for."
    return "Issues found:\n" + "\n".join(f"- {i}" for i in issues)


converter = Agent(
    name="config_converter",
    model="gpt-4.1-mini",
    instructions="""You convert Apache .htaccess and nginx server configs into ZealPHP app.php files.

WORKFLOW:
1. Call get_zealphp_reference() to get the ZealPHP API reference
2. Call get_conversion_examples() to see few-shot examples of correct conversions
3. Generate a COMPLETE app.php based on the input config
4. Call validate_conversion() with the original and your output to check for issues
5. If issues are found, fix them and output the corrected version

RULES:
- Always output a COMPLETE app.php: <?php, require, use statements, App::init(), routes, $app->run()
- Always assign App::init() result to $app: `$app = App::init('0.0.0.0', 8080);`
- For CMS configs (WordPress, Drupal) with front controller rewrites, use App::superglobals(true) + setFallback()
- For API-only configs, use explicit $app->route() calls without superglobals
- Static file directives → comment that OpenSwoole handles it automatically
- Proxy directives → comment that a reverse proxy should be used in front
- Wrap the output in a PHP code block""",
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
