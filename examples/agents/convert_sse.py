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

ZEALPHP_REF = """
ZealPHP route registration:

$app = App::init('0.0.0.0', 8080);  // Always assign to $app

// Basic route with params
$app->route('/user/{id}', ['methods' => ['GET','POST']], function($id, $request, $response) {
    return ['id' => $id];
});

// Namespace routes
$app->nsRoute('admin', '/dashboard', function() { ... });
$app->nsPathRoute('api', '{resource}', ['methods' => ['GET','POST','PUT','DELETE']], function($resource) { ... });

// Regex pattern
$app->patternRoute('/files/.*', function() { ... });

// Fallback (replaces RewriteRule . /index.php [L])
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

// Redirect
$app->route('/old', function() { header('Location: /new'); return 301; });

// Legacy mode (WordPress/Drupal)
App::superglobals(true);
App::$ignore_php_ext = false;

// Middleware
$app->addMiddleware(new CorsMiddleware(['*']));

// Static files: OpenSwoole enable_static_handler handles CSS/JS/images automatically
// SSL: $app->run(['ssl_cert_file'=>'...','ssl_key_file'=>'...','enable_http2'=>true]);

$app->run(['task_worker_num' => 0]);
"""


@function_tool
def get_reference() -> str:
    """Get ZealPHP API reference."""
    return ZEALPHP_REF


converter = Agent(
    name="converter",
    model="gpt-4.1-mini",
    instructions="""Convert Apache .htaccess or nginx config to a ZealPHP app.php.

1. Call get_reference() first
2. Output ONLY the PHP code — no explanations, no markdown fences, no commentary
3. Always include: <?php, require, use statements, App::init(), routes, $app->run()
4. For CMS front-controller rewrites: use App::superglobals(true) + setFallback()
5. For redirects: use $app->route() with header('Location: ...')
6. For API routes: use explicit $app->route() calls
7. Static file/cache directives: add a single comment that OpenSwoole handles it
8. proxy_pass: comment that a reverse proxy should be used in front
9. If the input is not a valid Apache or nginx config, output ONLY: // Error: Not a valid Apache .htaccess or nginx server config""",
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
