#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
SSE-streaming config converter for ZealPHP's /api/convert endpoint.
Reads config from argv[1] (base64) or stdin, outputs SSE events to stdout.
"""

import asyncio
import sys
import base64
from agents import Agent, Runner, function_tool

ZEALPHP_REF = r"""
ZealPHP is a PHP web framework on OpenSwoole. ZealPHP IS the HTTP server — no Apache/nginx needed.

## App Structure
- app.php — entry point. Defines routes, calls $app->run().
- public/ — the document root. Move all PHP files from Apache's document root here.
  Files are auto-served at base name: public/qn.php → /qn. No route needed for base URLs.
  Static files (CSS, JS, images, fonts) in public/ are served directly by OpenSwoole.
  ONLY parameterized URLs like /qn/{id} need explicit $app->route() calls.
- route/ — route files auto-included at startup.

## Initialization
```php
$app = App::init('0.0.0.0', 8080);  // ONLY takes ($host, $port, $cwd)
$app->run(['task_worker_num' => 0]);
```

## Routes — {param} Syntax (parameters injected by name via reflection)
```php
$app->route('/user/{id}', function($id) { return ['id' => $id]; });
$app->route('/user/{id}/{tab}', function($id, $tab) { ... });
$app->nsRoute('admin', '/users', function() { ... });           // → /admin/users
$app->nsPathRoute('api', '{path}', function($path) { ... });    // last param catches /slashes/
$app->patternRoute('/files/.*', function() { ... });             // raw regex
```

Magic params (injected automatically): $request, $response, $app

## Redirects
```php
$app->route('/old', function() { header('Location: /new'); return 301; });
$app->route('/blog/{slug}', function($slug) { header('Location: /articles/' . $slug); return 302; });
```

## Fallback (ONLY for CMS front-controller: WordPress, Drupal, Laravel)
```php
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});
```

## Legacy Mode (ONLY for unmodifiable apps like WordPress)
```php
App::superglobals(true);        // Enable $_GET, $_POST, $_SESSION
App::$ignore_php_ext = false;   // Allow .php URLs (/wp-login.php)
```

## Middleware
```php
$app->addMiddleware(new CorsMiddleware(['*']));    // CORS
$app->addMiddleware(new ETagMiddleware());          // ETag/304
```

## NOT NEEDED in ZealPHP (drop or comment briefly):
- Static file serving, directory indexing, charset, MIME types → OpenSwoole handles it
- ServerSignature, Options, ModPagespeed → not applicable
- PHP file handling, .php extension blocking → built-in
- SSL, proxy_pass, rate limiting → reverse proxy concern

## upload_max_filesize / post_max_size → package_max_length in $app->run()
```php
$app->run(['package_max_length' => 512 * 1024 * 1024]);
```
"""


@function_tool
def get_reference() -> str:
    """Get ZealPHP API reference."""
    return ZEALPHP_REF


converter = Agent(
    name="converter",
    model="gpt-5.4-mini",
    instructions="""Convert Apache .htaccess or nginx config to a ZealPHP app.php.

1. Call get_reference() first.
2. Classify: LEGACY CMS (front-controller → setFallback + includeFile + superglobals) vs MODERN APP ({param} routes, no include).
3. Output ONLY PHP code — no markdown, no explanations.

MOST IMPORTANT RULES:

RULE 1: Always start with a migration comment:
// Migration: move all PHP files from your Apache document root into the public/ folder.
// Files in public/ are auto-served: public/qn.php → /qn, public/watch.php → /watch, etc.

RULE 2: Only create routes for PARAMETERIZED URLs (RewriteRules with capture groups).
Base URLs like /qn are auto-served from public/qn.php — do NOT create routes for those.
/qn/{id} NEEDS a route because the framework can't auto-serve parameterized paths.

RULE 3: Do NOT create routes for things the framework handles automatically:
- Base file URLs → auto-served from public/
- .php extension blocking → built-in
- Extensionless URL resolution → built-in
- Trailing slash handling → not needed
Only create routes for: parameterized URLs, redirects [R=301], catch-all fallbacks.

OTHER RULES:
- App::init() takes ($host, $port) — NEVER arrays or phpSettings.
- Use {param} syntax: $app->route('/user/{id}', function($id) { ... })
- NEVER use $matches[], $_GET assignment, require/include in handlers, or exit()/die().
- Drop non-route Apache directives (ServerSignature, Options, AddType, charset, static caching) — one brief comment.
- CORS → CorsMiddleware. Upload size → package_max_length.
- HTTPS/SSL redirect → comment: reverse proxy concern.
- Redirect RewriteRules [R=301] → route with header('Location: ...'); return 301;
- Catch-all fallback rules → $app->setFallback()
- Always include: <?php, require autoload, use statements, App::init(), routes, $app->run().
- If not valid config: output ONLY: // Error: Not a valid Apache .htaccess or nginx server config""",
    tools=[get_reference],
)


async def main():
    if len(sys.argv) > 1:
        config = base64.b64decode(sys.argv[1]).decode("utf-8")
    else:
        config = sys.stdin.read()

    config = config.strip()
    if not config:
        print("data: // Error: Empty input\n")
        print("data: [DONE]\n")
        return

    result = Runner.run_streamed(
        converter,
        input=f"Convert to ZealPHP app.php:\n\n{config}",
    )
    async for event in result.stream_events():
        if event.type == "raw_response_event" and getattr(event.data, "type", "") == "response.output_text.delta":
            print(event.data.delta, end="", flush=True)

    print("\n__DONE__")


if __name__ == "__main__":
    asyncio.run(main())
