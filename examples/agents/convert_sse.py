#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
SSE-streaming config converter for ZealPHP's /api/convert endpoint.
Reads config from argv[1] (base64) or stdin, outputs SSE events to stdout.

System prompt is intentionally compact — for the full reference (matrices,
recipes, NOT-SUPPORTED list, middleware-gap inline templates), see the
sibling config_converter.py. Both agents emit the same code shape:
App::include('/path.php') + RequestContext::instance() + fluent config
methods + universal return contract + explicit refusal comments.
"""

import asyncio
import sys
import base64
from agents import Agent, Runner, function_tool

# Sync these constants with config_converter.py so the website's /api/convert
# and the CLI tool advertise the same encoded knowledge surface.
SYSTEM_PROMPT_VERSION = "2026-05-17"
ZEALPHP_VERSION = "^0.2.18"


ZEALPHP_REF = r"""
ZealPHP is a PHP web framework on OpenSwoole. ZealPHP IS the HTTP server — no Apache/nginx needed.
System prompt v2026-05-17; emit code targeting ZealPHP ^0.2.18.

## App Structure
- app.php — entry point. Configures the framework, calls App::init(), registers routes, calls $app->run().
- public/ — the document root. Move all PHP files from Apache's document root here.
  Files are auto-served at base name: public/qn.php -> /qn. No route needed for base URLs.
  Static files (CSS, JS, images, fonts) in public/ are served directly by OpenSwoole.
  ONLY parameterized URLs like /qn/{id} need explicit $app->route() calls.
- route/ — route files auto-included at startup.
- template/ — view templates rendered via App::render() / App::renderToString() / App::renderStream().

## Configure FIRST (static, fluent method form), then init, then route, then run

```php
App::superglobals(false);              // coroutine mode (default for new apps)
App::ignorePhpExt(true);               // strip .php from URLs (clean URLs)
App::documentRoot('public');           // emit ONLY if non-default
App::traceEnabled(false);              // already default; emit only for security configs
```

NEVER emit raw property assignment: App::$ignore_php_ext = true; <- WRONG
ALWAYS use the fluent method form (one App per process; static config before init).

## Initialization
```php
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

App::superglobals(false);              // static config FIRST
$app = App::init('0.0.0.0', 8080);     // ONLY takes ($host, $port, $cwd)
$app->run(['task_worker_num' => 0]);
```

## Routes — {param} Syntax (parameters injected by name via reflection)
```php
$app->route('/user/{id}', function($id) { return ['id' => $id]; });
$app->route('/user/{id}/{tab}', function($id, $tab) { ... });
$app->nsRoute('admin', '/users', function() { ... });             // -> /admin/users
$app->nsPathRoute('api', '{path}', function($path) { ... });      // last param catches /slashes/
$app->patternRoute('/files/.*', function() { ... });               // raw regex
```

Magic params (injected automatically): $request, $response, $app

## File-execution family — pick by intent

| Method | Input | When |
|---|---|---|
| `App::render($tpl, $args)` | template name | echo template HTML in handler/template |
| `App::renderToString($tpl, $args)` | template name | template HTML as a value (email/cache/embed) |
| `App::renderStream($tpl, $args)` | template name | SSR streaming, returns Generator |
| `App::include($publicPath, $args)` | path relative to `public/` | serve a public-side .php file from a route |

`App::includeFile()` is the DEPRECATED alias — never emit in new code; always emit `App::include()`.

CRITICAL emission rules for App::include():
- Public-RELATIVE paths only:  `App::include('/qn.php')`  (leading slash optional)
- WRONG: `App::include(App::$cwd . '/public/qn.php')`
- WRONG: `App::include(__DIR__ . '/public/qn.php')`
- NEVER emit the $g->server PHP_SELF / SCRIPT_NAME / SCRIPT_FILENAME preamble —
  the framework populates these automatically (Apache mod_php parity).

## Universal return contract (handler / fallback / error handler / App::include()'d file)

| File / handler does | Framework emits |
|---|---|
| `echo "html";` (no return) | 200 + HTML body |
| `return 404;` | 404 status, empty body |
| `return ['ok' => true];` | 200 + JSON |
| `return "explicit html";` | 200 + HTML body |
| `echo "shell"; return "body";` | 200 + "shellbody" |
| `return (function() { yield ...; })();` | SSR streaming |
| `return function($req) { yield ...; };` | Closure -> param-injected stream |

Emit `return 403;` not `http_response_code(403);`. Emit `return [...]` not `echo json_encode(...)`.
Cross-link: /responses#return-contract.

## $g vs $_GET parity rule (always use $g; pair with `// legacy: $_GET[..]` comment)

Read/write request state via RequestContext::instance(): `$g->get`, `$g->post`, `$g->cookie`,
`$g->server`, `$g->session`, `$g->status`. This works in BOTH modes (under superglobals(true)
it bridges to $_GET; under superglobals(false) it's per-coroutine isolated and $_GET is NOT
populated per request).

```php
$app->patternRoute('/article/([0-9]+)\.html', function ($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;        ← only safe under App::superglobals(true)
    return App::include('/article.php');
});
```

NEVER emit bare `$_GET['x'] = $x;` in the primary code path — leaks across coroutines.

## Rewrites — internal vs external (CRITICAL — pick the right pattern)

Apache `[L]` without `[R]` = INTERNAL rewrite. URL bar does NOT change. Use
`App::include('/path.php')` to load the destination in-process. NEVER use
`header('Location: ...')` here — that would expose the internal URL the
rewrite was hiding.

```php
// RewriteRule ^old$ /new [L]              -> internal, URL stays /old
$app->route('/old', fn() => App::include('/new.php'));

// RewriteRule ^qn/([^/]+)?$ "qn.php?id=$1" [L,QSA]    -> internal with param
$app->route('/qn/{id}', function($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;
    // legacy: $_GET['id'] = $id;
    return App::include('/qn.php');
});
```

Apache `[R=301,L]` or `[R=302,L]` = EXTERNAL redirect. URL bar DOES change.
Use `$response->redirect($url, $status)`.

```php
// RewriteRule ^old$ /new [R=301,L]
$app->route('/old', fn($response) => $response->redirect('/new', 301));

// RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
$app->route('/blog/{slug}', fn($slug, $response) => $response->redirect("/articles/{$slug}", 302));
```

Decision: read the flag block. `R=` anything -> `$response->redirect()`. No `R` -> `App::include()`.

## Fallback (ONLY for CMS front-controller: WordPress, Drupal, Laravel)
```php
$app->setFallback(fn() => App::include('/index.php'));
```

## Legacy Mode (ONLY for unmodifiable apps like WordPress)
```php
App::superglobals(true);     // Enable $_GET, $_POST, $_SESSION
App::ignorePhpExt(false);    // Allow .php URLs (/wp-login.php)
```

## Middleware
```php
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\ETagMiddleware;

$app->addMiddleware(new CorsMiddleware(['*']));    // CORS
$app->addMiddleware(new ETagMiddleware());          // ETag/304
```

## Middleware gaps (PROPOSED but not yet shipped — emit inline anonymous middleware + comment)

`HeaderMiddleware` (Header set / add_header), `BasicAuthMiddleware` (auth_basic),
`CharsetMiddleware`, `CacheControlMiddleware`, `ExpiresMiddleware`,
`IpAccessMiddleware` (Allow/Deny), `RateLimitMiddleware`, `ConcurrencyLimitMiddleware`,
`HostRouterMiddleware`, `ProxyMiddleware`, `BlockPhpExtMiddleware`.

Emit pattern:

    // PROPOSED: HeaderMiddleware — not yet shipped. Inline implementation:
    $app->addMiddleware(new class implements \Psr\Http\Server\MiddlewareInterface {
        public function process(
            \Psr\Http\Message\ServerRequestInterface $request,
            \Psr\Http\Server\RequestHandlerInterface $handler
        ): \Psr\Http\Message\ResponseInterface {
            $response = $handler->handle($request);
            return $response->withHeader('X-Foo', 'bar');
        }
    });

## NOT SUPPORTED — refuse explicitly, never silently drop

When input uses any of these features, emit an explicit refuse comment:

    // (NOT SUPPORTED): <Feature>. <one-sentence rationale>.
    //                  <migration path or workaround>.
    //                  See https://php.zeal.ninja/legacy-apps#limitations

Categories to refuse:
- SSI / .shtml / XBitHack / Options +Includes / AddHandler server-parsed
- mod_speling (CheckSpelling), mod_imagemap (Imap*), mod_dav (Dav On)
- mod_perl / mod_python / mod_ruby / mod_isapi / mod_lua hooks
- CERN meta files (MetaDir/MetaFiles/MetaSuffix), AuthLDAP*, AuthDigest*
- Anonymous* auth, mod_substitute (defer to inline middleware on demand)
- nginx return 444, early_hints, directio, recursive_error_pages
- nginx stream {} (L4 proxy), mail {} (SMTP/IMAP), grpc_pass

## NOT NEEDED in ZealPHP (drop with ONE brief comment):
- Static file serving, directory indexing, charset, MIME types → OpenSwoole handles
- ServerSignature, Options -Indexes, ModPagespeed → not applicable / no-op
- PHP file handling, location ~ \.php$ { fastcgi_pass ... } → ZealPHP IS the PHP runtime
- gzip on (OpenSwoole http_compression default)
- SSL termination, proxy_pass, edge rate limiting → reverse proxy concern (Caddy/Traefik/nginx)

## upload_max_filesize / post_max_size / client_max_body_size -> package_max_length
```php
$app->run(['package_max_length' => 512 * 1024 * 1024]);
```

## composer.json install line
    composer require sibidharan/zealphp:^0.2.18
"""


@function_tool
def get_reference() -> str:
    """Get the ZealPHP API reference (App::include, RequestContext, return contract, recipes)."""
    return ZEALPHP_REF


@function_tool
def get_rewrite_skill() -> str:
    """Get the dedicated skill for converting Apache RewriteRule directives — explains
    when to use App::include() (internal rewrite, no [R] flag) vs $response->redirect()
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
    instructions=f"""Convert Apache .htaccess or nginx config to a ZealPHP app.php.
System prompt v{SYSTEM_PROMPT_VERSION}; emit code targeting ZealPHP {ZEALPHP_VERSION}.

1. Call get_reference() first for the general API + return contract + NOT-SUPPORTED list.
2. If the input contains any `RewriteRule` line, ALSO call get_rewrite_skill() for the
   internal-vs-external decision rules. Apply them strictly — don't use header('Location:')
   for a no-[R] rule.
3. Classify: LEGACY CMS (front-controller -> setFallback + App::include + superglobals) vs
   LEGACY APP WITH PARAMETERIZED REWRITES (each capture-rule -> route + $g->get + App::include)
   vs MODERN APP ({{param}} routes, no App::include).
4. Detect NOT-SUPPORTED directives FIRST — prepare explicit refuse comments.
5. Output ONLY PHP code — no markdown, no explanations before/after.

MOST IMPORTANT RULES:

RULE 1: Always start with a migration comment block listing every .php file referenced:
// Migration: move these files into the public/ folder:
//   qn.php, watch.php, account.php, _data.php, ...
// Files in public/ are auto-served: public/qn.php -> /qn, public/watch.php -> /watch, etc.

RULE 2: Only create routes for PARAMETERIZED URLs (RewriteRules with capture groups).
Base URLs like /qn are auto-served from public/qn.php — do NOT create routes for those.
/qn/{{id}} NEEDS a route because the framework can't auto-serve parameterized paths.

RULE 3: NEVER emit these forbidden patterns:
- App::includeFile(...)                         — deprecated; emit App::include() instead
- App::include(App::$cwd . '/public/...')       — wrong; paths are public-relative
- App::include(__DIR__ . '/public/...')         — wrong; paths are public-relative
- $g->server['PHP_SELF'/'SCRIPT_NAME'/'SCRIPT_FILENAME'] = ...
                                                — framework auto-populates inside App::include()
- App::$ignore_php_ext = false                  — use App::ignorePhpExt(false)
- App::$superglobals = true                     — use App::superglobals(true)
- App::$directory_index = [...]                 — use App::directoryIndex([...])
- App::$directory_slash = true                  — use App::directorySlash(true)
- App::$path_info = true                        — use App::pathInfo(true)
- App::$block_dotfiles = true                   — use App::blockDotfiles(true)
- bare $_GET['x'] = $x;                         — use $g = RequestContext::instance(); $g->get['x'] = $x;
                                                  AND emit `// legacy: $_GET['x'] = $x;` comment
- exit() / die()                                — not safe in OpenSwoole coroutine context
- http_response_code(N)                         — use `return N;` (universal return contract)
- echo json_encode([...])                       — use `return [...];` (universal return contract)
- header('Location: ...'); return N             — use $response->redirect($url, N)

RULE 4: In mode A (parameterized rewrites), each route handler MUST follow this template:

   $app->route('/<path>/{{<key>}}', function($<key>) {{
       $g = RequestContext::instance();
       $g->get['<key>'] = $<key>;
       // legacy: $_GET['<key>'] = $<key>;
       return App::include('/<target>.php');
   }});

RULE 5: Do NOT create routes for things the framework handles automatically:
- Base file URLs in public/ -> auto-served
- .php extension blocking -> built-in (App::ignorePhpExt())
- Extensionless URL resolution -> built-in
- Trailing-slash ADD for directories -> App::directorySlash(true)
- Directory index files -> App::directoryIndex([...])

Only create routes for: parameterized URLs, redirects [R=301], catch-all fallbacks,
deny rules ([F]/[G]/<FilesMatch>), and trailing-slash STRIP (inverse direction).

RULE 6: REFUSE EXPLICITLY when input uses NOT-SUPPORTED features. Emit:
// (NOT SUPPORTED): <Feature>. <rationale>. <workaround>.
//                  See https://php.zeal.ninja/legacy-apps#limitations
Never silently drop. Categories: SSI, mod_speling, mod_imagemap, mod_dav, mod_lua hooks,
AuthLDAP*, AuthDigest*, Anonymous*, CERN meta files, nginx return 444, early_hints,
nginx stream {{}} / mail {{}} / grpc_pass.

RULE 7: For ⚠ MIDDLEWARE-GAP directives (auth_basic, Header set, add_header, expires,
IP ACL, rate limit, etc.), emit BOTH (a) inline anonymous PSR-15 middleware AND
(b) a `// PROPOSED: <Name>Middleware — not yet shipped` comment.

OTHER RULES:
- App::init() takes ($host, $port) — NEVER arrays or phpSettings.
- Use {{param}} syntax: $app->route('/user/{{id}}', function($id) {{ ... }})
- Drop non-route Apache directives (ServerSignature, Options, ModPagespeed, static caching,
  charset, AddType) — ONE brief comment listing all dropped items.
- CORS -> CorsMiddleware. Upload size -> package_max_length. Body size -> package_max_length.
- HTTPS/SSL redirect -> ONE comment: front proxy concern (Caddy/Traefik/nginx) — or
  inline middleware shape if user explicitly needs it.
- Internal RewriteRule (no [R]) -> route returning App::include('/<target>.php'). NEVER
  emit header('Location: ...') here — would expose the internal URL the rewrite hid.
- External Redirect RewriteRule [R=301]/[R=302] -> route returning $response->redirect($url, $status).
- Catch-all fallback rules -> $app->setFallback() containing App::include() (internal — URL preserved).
- Output: <?php, require autoload, `use ZealPHP\\App; use ZealPHP\\RequestContext;`, static
  config (App::superglobals / App::ignorePhpExt), App::init(), middleware, routes,
  $app->run(['task_worker_num' => 0]).
- PSR-2 indentation (4 spaces). Short array syntax.
- If input is not a valid config:
  Output ONLY: // Error: Not a valid Apache .htaccess or nginx server config""",
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
