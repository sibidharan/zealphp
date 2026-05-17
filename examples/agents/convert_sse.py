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

## Rewrites — internal vs external (CRITICAL — pick the right pattern)

Apache `[L]` without `[R]` = INTERNAL rewrite. URL bar does NOT change. Use
`App::includeFile()` to load the destination in-process. NEVER use
`header('Location: ...')` here — that would expose the internal URL the
rewrite was hiding.

```php
// RewriteRule ^old$ /new [L]              -> internal, URL stays /old
$app->route('/old', function() {
    return App::includeFile(App::$cwd . '/public/new.php');
});

// RewriteRule ^qn/([^/]+)?$ "qn.php?id=$1" [L,QSA]    -> internal with param
$app->route('/qn/{id}', function($id) {
    $g = G::instance();
    $g->get['id'] = $id;
    $g->server['SCRIPT_NAME']     = '/qn.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/qn.php';
    App::includeFile(App::$cwd . '/public/qn.php');
});
```

Apache `[R=301,L]` or `[R=302,L]` = EXTERNAL redirect. URL bar DOES change.
Use `$response->redirect($url, $status)`.

```php
// RewriteRule ^old$ /new [R=301,L]
$app->route('/old', function($response) { return $response->redirect('/new', 301); });

// RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
$app->route('/blog/{slug}', function($slug, $response) {
    return $response->redirect("/articles/{$slug}", 302);
});
```

Decision: read the flag block. `R=`anything → `$response->redirect()`. No `R` → `App::includeFile()`.

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
    """Get the ZealPHP API reference (routes, middleware, return values, legacy mode)."""
    return ZEALPHP_REF


@function_tool
def get_rewrite_skill() -> str:
    """Get the dedicated skill for converting Apache RewriteRule directives — explains
    when to use App::includeFile() (internal rewrite, no [R] flag) vs $response->redirect()
    (external redirect, [R=301]/[R=302]). Call this whenever the input contains
    RewriteRule lines."""
    import os
    here = os.path.dirname(os.path.abspath(__file__))
    skill_path = os.path.join(here, "skills", "htaccess-rewrite-mapping.md")
    try:
        with open(skill_path, "r", encoding="utf-8") as f:
            return f.read()
    except FileNotFoundError:
        return "(rewrite skill file missing — fall back to the rules in the system prompt)"


converter = Agent(
    name="converter",
    model="gpt-5.4-mini",
    instructions="""Convert Apache .htaccess or nginx config to a ZealPHP app.php.

1. Call get_reference() first for the general API.
2. If the input contains any `RewriteRule` line, ALSO call get_rewrite_skill() for the
   internal-vs-external decision rules. Apply them strictly — don't use header('Location:')
   for a no-[R] rule.
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
Only create routes for: parameterized URLs, rewrites (internal [L] or external [R=301]), catch-all fallbacks.

OTHER RULES:
- App::init() takes ($host, $port) — NEVER arrays or phpSettings.
- Use {param} syntax: $app->route('/user/{id}', function($id) { ... })
- NEVER use $matches[], $_GET assignment, require/include in handlers, or exit()/die().
- Drop non-route Apache directives (ServerSignature, Options, AddType, charset, static caching) — one brief comment.
- CORS → CorsMiddleware. Upload size → package_max_length.
- HTTPS/SSL redirect → comment: reverse proxy concern.
- Internal RewriteRule (no [R]) → route with App::includeFile(App::$cwd . '/public/<target>.php'). NEVER use header('Location: ...') here — that would expose the internal URL.
- External Redirect RewriteRule [R=301] / [R=302] → route with $response->redirect($url, $status). Returns the right shape automatically.
- Catch-all fallback rules → $app->setFallback() containing App::includeFile() (internal — preserves URL).
- Always include: <?php, require autoload, use statements, App::init(), routes, $app->run().
- If not valid config: output ONLY: // Error: Not a valid Apache .htaccess or nginx server config""",
    tools=[get_reference, get_rewrite_skill],
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
