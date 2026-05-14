#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
ZealPHP AI Chat Agent
=====================
SSE-streaming chat agent for the ZealPHP homepage demo.
Uses OpenAI Agents SDK with streaming, tool use, and multi-agent handoff.

Called by ZealPHP's route/chat.php via proc_open. Reads JSON from argv[1]
(base64-encoded), streams SSE-formatted events to stdout.

Input JSON: {"message": "...", "history": [...]}
Output: SSE events (event: token/thread/done, data: JSON)

Usage:
    echo '{"message":"hello"}' | base64 | xargs uv run examples/agents/chat_agent.py
"""

import asyncio
import json
import sys
import base64
from agents import Agent, Runner, function_tool


@function_tool
def get_zealphp_features(topic: str) -> str:
    """Get detailed information about a ZealPHP framework feature.

    Args:
        topic: Feature to look up (routing, streaming, websocket, store, coroutines, middleware, templates, legacy, api, performance)
    """
    features = {
        "routing": (
            "Flask-style routes: $app->route('/user/{id}', function($id) { ... }). "
            "Parameters injected by name via reflection — zero config. "
            "nsRoute for namespaced groups, nsPathRoute for catch-all, patternRoute for regex. "
            "Reflection is cached at registration — zero overhead per request."
        ),
        "streaming": (
            "Four streaming patterns: "
            "1) Generator yield — return a Generator, each yield sent immediately for SSR. "
            "2) App::renderStream() — streaming templates with parameter injection. "
            "3) $response->stream($fn) — fine-grained control with $write() closure. "
            "4) $response->sse($fn) — Server-Sent Events with $emit($data, $event, $id). "
            "All coroutine-safe. Generators work in routes, public files, API handlers, and templates."
        ),
        "websocket": (
            "App::ws('/path', $onMessage, $onOpen, $onClose). Built on OpenSwoole WebSocket\\Server "
            "(backward-compatible — all HTTP routes still work). Per-worker fd tracking. "
            "Auto-drops PING/PONG/CONTINUATION frames. Sends CLOSE 1001 on shutdown."
        ),
        "store": (
            "Store — OpenSwoole\\Table wrapper for cross-worker shared memory. "
            "Must be created before $app->run(). Store::make('name', maxRows, columns). "
            "Store::set/get/del/exists/incr/decr/count. Lock-free, shared across all workers. "
            "Counter — OpenSwoole\\Atomic wrapper for lock-free cross-worker integers."
        ),
        "coroutines": (
            "OpenSwoole coroutines with go() + Channel. HOOK_ALL makes existing PHP libraries "
            "async automatically — no rewrites needed. Thousands of concurrent requests per worker. "
            "Write synchronous-looking code that runs concurrently. C1000K capable."
        ),
        "middleware": (
            "PSR-15 middleware stack. Built-in: CorsMiddleware (CORS preflight + headers), "
            "ETagMiddleware (W/\"md5\" + 304), CompressionMiddleware (gzip/deflate reference). "
            "Last-added runs first (outermost). ResponseMiddleware always innermost."
        ),
        "templates": (
            "App::render($tpl, $args) for direct output, App::renderToString() for HTML as value, "
            "App::renderStream() returns a Generator for SSR streaming. "
            "Streaming templates: return function($var) { yield ...; }; — params injected by name."
        ),
        "legacy": (
            "Run WordPress, Drupal, or any PHP app unmodified. CGI worker (proc_open) provides "
            "true global scope isolation. App::superglobals(true) enables $_GET/$_POST/$_SESSION. "
            "App::setFallback() replaces Apache's .htaccess RewriteRule."
        ),
        "api": (
            "ZealAPI — file-based REST. Drop a PHP file in api/ and it becomes a route. "
            "api/users/get.php defines $get = function(...) — variable name matches filename. "
            "Auto-bound as closure with $this set to ZealAPI instance."
        ),
        "performance": (
            "67k req/s on 4 workers (local quad-core benchmark). 21ms p90 latency. 0 failures. "
            "Multi-process workers + coroutines for true parallelism. Shared memory via Store "
            "(no Redis needed). Reproducible — run scripts/bench.sh yourself."
        ),
    }
    key = topic.lower().strip()
    if key in features:
        return features[key]
    return f"Available topics: {', '.join(features.keys())}. Ask about one of these."


@function_tool
def generate_code_example(description: str) -> str:
    """Generate a ZealPHP code example based on a description.

    Args:
        description: What the code should do (e.g., 'SSE streaming endpoint', 'JSON API with auth')
    """
    examples = {
        "sse": '''$app->route('/events', function($response) {
    $response->sse(function($emit) {
        for ($i = 1; $i <= 5; $i++) {
            co::sleep(1);
            $emit(json_encode(['tick' => $i]), 'update');
        }
        $emit(json_encode(['done' => true]), 'complete');
    });
});''',
        "json": '''$app->route('/api/users/{id}', function($id) {
    $user = ['id' => $id, 'name' => 'Alice'];
    return $user; // auto JSON serialized
});''',
        "stream": '''$app->route('/stream', function() {
    return (function() {
        yield "<html><body>";
        yield "<h1>Streaming...</h1>";
        co::sleep(1); // simulate async work
        yield "<p>Done!</p>";
        yield "</body></html>";
    })();
});''',
        "websocket": '''$app->ws('/chat',
    function($server, $frame) {
        $server->push($frame->fd, "Echo: " . $frame->data);
    },
    function($server, $request) {
        $server->push($request->fd, "Welcome!");
    }
);''',
    }
    desc_lower = description.lower()
    for key, code in examples.items():
        if key in desc_lower:
            return code
    return f'''$app->route('/example', function($request, $response) {{
    // {description}
    return ['status' => 'ok'];
}});'''


zealphp_assistant = Agent(
    name="ZealPHP Assistant",
    model="gpt-4.1-mini",
    instructions="""You are a helpful AI assistant embedded in the ZealPHP framework website.
ZealPHP is an async PHP framework built on OpenSwoole — it's the PHP runtime for AI web apps.

Key selling points:
- SSR streaming via Generator yield, $response->stream(), $response->sse()
- 67k req/s on 4 workers, 21ms p90 latency
- WebSocket, coroutines (go() + Channel), shared memory Store
- One server does everything: HTTP + WebSocket + SSE + task workers + sessions
- Runs WordPress unmodified via CGI worker
- PSR-15 middleware, reflection-based parameter injection

Use get_zealphp_features() to look up specific features when asked.
Use generate_code_example() to create code snippets when asked for examples.

Keep responses concise (2-4 sentences). Use markdown for code snippets.
If the question is not about ZealPHP, answer helpfully but briefly.
This conversation is being streamed token-by-token to demonstrate ZealPHP's SSE capabilities.""",
    tools=[get_zealphp_features, generate_code_example],
)


async def main():
    if len(sys.argv) > 1:
        raw = base64.b64decode(sys.argv[1]).decode("utf-8")
    else:
        raw = sys.stdin.read()

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        print("event: error")
        print(f'data: {json.dumps({"error": "Invalid JSON input"})}')
        print()
        return

    message = payload.get("message", "").strip()
    history = payload.get("history", [])

    if not message:
        print("event: error")
        print(f'data: {json.dumps({"error": "Empty message"})}')
        print()
        return

    input_items = []
    for msg in history:
        role = msg.get("role", "user")
        content = msg.get("content", "")
        if role == "user":
            input_items.append({"role": "user", "content": content})
        elif role == "assistant":
            input_items.append({"role": "assistant", "content": content})

    input_items.append({"role": "user", "content": message})

    result = Runner.run_streamed(zealphp_assistant, input=input_items)

    async for event in result.stream_events():
        if event.type == "raw_response_event":
            data_type = getattr(event.data, "type", "")
            if data_type == "response.output_text.delta":
                delta = event.data.delta
                if delta:
                    print("event: token")
                    print(f"data: {json.dumps({'token': delta})}")
                    print(flush=True)

    print("event: done")
    print(f"data: {json.dumps({'done': True})}")
    print(flush=True)


if __name__ == "__main__":
    asyncio.run(main())
