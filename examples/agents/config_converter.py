#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
Apache/nginx → ZealPHP Converter Agent
=======================================
Converts .htaccess or nginx config into a ZealPHP app.php.
Uses gpt-4.1-mini with streaming and tool-assisted conversion.

Usage:
    uv run examples/agents/config_converter.py
    echo "RewriteRule ^api/(.*)$ index.php [L]" | uv run examples/agents/config_converter.py

Requires: OPENAI_API_KEY environment variable
"""

import asyncio
import sys
from agents import Agent, Runner, function_tool


ZEALPHP_REFERENCE = """
ZealPHP route registration reference:

# Basic route
$app->route('/path', function() { echo "hello"; });

# With methods and params
$app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id, $request, $response) {
    return ['id' => $id];
});

# Namespace route (prefix)
$app->nsRoute('admin', '/dashboard', function() { ... });

# Namespace path route (directory-style)
$app->nsPathRoute('api', '{resource}', ['methods' => ['GET','POST','PUT','DELETE']], function($resource, $request) { ... });

# Regex pattern route
$app->patternRoute('/files/.*', function($response) { ... });

# Fallback for unmatched URLs (replaces RewriteRule . /index.php [L])
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

# Redirect
$app->route('/old-path', function() {
    header('Location: /new-path');
    return 301;
});

# Middleware
$app->addMiddleware(new CorsMiddleware(['*']));
$app->addMiddleware(new ETagMiddleware());

# Legacy app mode (WordPress, Drupal, etc.)
App::superglobals(true);        // Enable $_GET, $_POST, $_SESSION
App::$ignore_php_ext = false;   // Allow .php in URLs

# Static files served by OpenSwoole enable_static_handler (no config needed)
# SSL/TLS: pass 'enable_http2' => true, 'ssl_cert_file' => '...', 'ssl_key_file' => '...' to $app->run()

# Server settings
$app->run([
    'task_worker_num' => 0,
    'worker_num' => 4,
]);
"""


@function_tool
def get_zealphp_reference() -> str:
    """Get ZealPHP route and configuration reference for converting Apache/nginx configs."""
    return ZEALPHP_REFERENCE


@function_tool
def validate_conversion(original_config: str, zealphp_code: str) -> str:
    """Validate a conversion by checking for common patterns that need special handling."""
    issues = []
    original_lower = original_config.lower()

    if "rewritecond %{https}" in original_lower or "ssl" in original_lower:
        if "ssl_cert" not in zealphp_code.lower() and "https" not in zealphp_code.lower():
            issues.append("Original has SSL/HTTPS config — add ssl_cert_file/ssl_key_file to $app->run() settings")

    if "proxy_pass" in original_lower or "proxypass" in original_lower:
        issues.append("Reverse proxy directives found — ZealPHP doesn't proxy; use native routes or a separate proxy")

    if "auth_basic" in original_lower or "htpasswd" in original_lower:
        issues.append("Basic auth found — implement as ZealPHP middleware instead of .htaccess")

    if "expires" in original_lower or "cache-control" in original_lower:
        if "middleware" not in zealphp_code.lower() and "cache" not in zealphp_code.lower():
            issues.append("Cache headers found — add custom middleware or headers in route handlers")

    if "rewriterule" in original_lower and "setfallback" not in zealphp_code.lower():
        if "index.php" in original_lower:
            issues.append("Front controller rewrite detected but no setFallback() — add fallback for catch-all routing")

    if not issues:
        return "Conversion looks correct — all major directives accounted for."
    return "Potential issues:\n" + "\n".join(f"- {i}" for i in issues)


converter = Agent(
    name="config_converter",
    model="gpt-4.1-mini",
    instructions="""You convert Apache .htaccess and nginx server configs into ZealPHP app.php files.

Rules:
1. Always call get_zealphp_reference() first to get the latest syntax
2. Output a COMPLETE app.php — include <?php, require, App::init(), routes, and $app->run()
3. For WordPress/CMS configs with RewriteRule . /index.php, use App::superglobals(true) + setFallback()
4. For API configs, use explicit $app->route() calls
5. Static file directives (expires, gzip) → note that OpenSwoole handles static files automatically
6. After generating code, call validate_conversion() to check for issues
7. Keep the output clean — no excessive comments, just the essential config

When outputting the app.php, wrap it in a PHP code block.""",
    tools=[get_zealphp_reference, validate_conversion],
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
            if event.type == "raw_response_event" and hasattr(event.data, "type"):
                if event.data.type == "output_text_delta":
                    print(event.data.delta, end="", flush=True)
        print("\n")


if __name__ == "__main__":
    asyncio.run(main())
