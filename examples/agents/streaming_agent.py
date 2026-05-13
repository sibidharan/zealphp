#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
ZealPHP Agent Streaming Example
================================
A multi-agent system using the OpenAI Agents SDK with gpt-4.1-mini.
Demonstrates streaming responses, tool use, and agent handoff.

Usage:
    uv run examples/agents/streaming_agent.py

Requires: OPENAI_API_KEY environment variable
"""

import asyncio
from agents import Agent, Runner, function_tool

@function_tool
def get_zealphp_info(topic: str) -> str:
    """Get information about ZealPHP framework features."""
    info = {
        "routing": "ZealPHP uses Flask-style routes: $app->route('/user/{id}', function($id) { ... }). "
                   "Supports nsRoute, nsPathRoute, patternRoute. Parameter injection via reflection.",
        "streaming": "Three patterns: Generator yield for SSR, $response->stream() for fine-grained control, "
                     "$response->sse() for Server-Sent Events. All coroutine-safe.",
        "websocket": "App::ws('/path', onMessage, onOpen, onClose). Built on OpenSwoole WebSocket\\Server. "
                     "Auto-handles PING/PONG frames. Per-worker fd tracking.",
        "wordpress": "ZealPHP runs unmodified WordPress via CGI worker (proc_open). True global scope isolation. "
                     "Supports login, admin dashboard, REST API, pretty permalinks — zero modifications.",
        "coroutines": "OpenSwoole coroutines with go() + Channel. HOOK_ALL makes PHP libraries async automatically. "
                      "Thousands of concurrent requests per worker.",
        "middleware": "PSR-15 middleware stack. Built-in: CorsMiddleware, ETagMiddleware, CompressionMiddleware. "
                      "Last-added runs first (outermost wrap).",
    }
    return info.get(topic.lower(), f"No specific info on '{topic}'. Available topics: {', '.join(info.keys())}")

@function_tool
def generate_zealphp_route(description: str) -> str:
    """Generate a ZealPHP route handler based on a description."""
    return f"""$app->route('/api/generated', ['methods' => ['GET', 'POST']], function($request, $response) {{
    // Generated route: {description}
    return ['status' => 'ok', 'description' => '{description}'];
}});"""

zealphp_expert = Agent(
    name="zealphp_expert",
    model="gpt-4.1-mini",
    instructions="""You are a ZealPHP framework expert. You help developers build async PHP
    applications on OpenSwoole. Use the get_zealphp_info tool to look up framework features,
    and generate_zealphp_route to create route examples. Be concise and practical.""",
    tools=[get_zealphp_info, generate_zealphp_route],
)

code_reviewer = Agent(
    name="code_reviewer",
    model="gpt-4.1-mini",
    instructions="""You review PHP code for best practices, security issues, and performance.
    When reviewing ZealPHP code, check for: coroutine safety, proper error handling,
    middleware ordering, and response streaming patterns. Be brief — bullet points only.""",
)

triage_agent = Agent(
    name="Triage",
    model="gpt-4.1-mini",
    instructions="""Route the user's request to the right specialist:
    - Questions about ZealPHP features, routing, streaming → ZealPHP Expert
    - Code review requests → Code Reviewer
    - General questions → answer directly""",
    handoffs=[zealphp_expert, code_reviewer],
)


async def main():
    print("ZealPHP Agent (streaming with gpt-4.1-mini)")
    print("Type a question about ZealPHP, or paste code for review.")
    print("Type 'quit' to exit.\n")

    while True:
        try:
            user_input = input("You: ").strip()
        except (EOFError, KeyboardInterrupt):
            break
        if not user_input or user_input.lower() == "quit":
            break

        print("Agent: ", end="", flush=True)
        result = Runner.run_streamed(triage_agent, input=user_input)
        async for event in result.stream_events():
            if event.type == "raw_response_event" and getattr(event.data, "type", "") == "response.output_text.delta":
                print(event.data.delta, end="", flush=True)
        print("\n")


if __name__ == "__main__":
    asyncio.run(main())
