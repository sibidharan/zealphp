#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""
ZealPHP AI Chat Agent
=====================
SSE-streaming chat agent for the ZealPHP homepage demo.
Uses OpenAI Agents SDK with streaming, tool use, and SQLiteSession
for persistent conversation threads.

Called by ZealPHP's route/chat.php via proc_open. Reads JSON from argv[1]
(base64-encoded), streams SSE-formatted events to stdout.

Input JSON: {"message": "...", "thread_id": "..."}
Output: SSE events (event: token/thread/done, data: JSON)

Usage:
    echo '{"message":"hello","thread_id":"abc123"}' | base64 | xargs uv run examples/agents/chat_agent.py
"""

import asyncio
import json
import sys
import base64
import os
from agents import Agent, Runner, SQLiteSession, function_tool


REFERENCE_PATH = os.path.join(os.path.dirname(__file__), "zealphp_reference.txt")


@function_tool
def get_zealphp_reference(query: str) -> str:
    """Look up ZealPHP framework documentation. Returns relevant sections from the complete reference.

    Args:
        query: What to look up (e.g., 'routing', 'sse streaming', 'websocket', 'store', 'coroutines', 'middleware', 'templates', 'legacy apps', 'api', 'performance', 'sessions', 'timers', 'cli')
    """
    try:
        with open(REFERENCE_PATH, "r") as f:
            content = f.read()
    except FileNotFoundError:
        return "Reference file not found."

    sections = content.split("\n## ")
    query_lower = query.lower().strip()

    matched = []
    for section in sections:
        heading = section.split("\n")[0].lower()
        body = section.lower()
        if query_lower in heading or query_lower in body:
            matched.append("## " + section if not section.startswith("ZealPHP") else section)

    if matched:
        return "\n".join(matched[:3])
    return content[:3000]


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

Use get_zealphp_reference() to look up features, code examples, and API details. Always call it before answering ZealPHP questions — it has the complete, accurate reference.

IMPORTANT — Output format:
You MUST output raw HTML, NOT markdown. Your response is streamed directly into an HTML chat bubble via innerHTML.
- Use <code> for inline code references
- Use <pre><code> for multi-line code blocks
- Use <p> for paragraphs (do NOT wrap your entire response in a single <p>)
- Use <strong> for bold, <em> for italic
- Use <ul>/<ol> with <li> for lists
- Use <br> for line breaks within a paragraph
- Do NOT use markdown syntax (no backticks, no #, no *, no -)
- Do NOT wrap the entire response in a container div
- Keep responses concise (2-4 sentences unless a code example is requested).

If the question is not about ZealPHP, answer helpfully but briefly.
This conversation is being streamed token-by-token to demonstrate ZealPHP's SSE capabilities.""",
    tools=[get_zealphp_reference],
)


SESSIONS_DIR = os.path.join(os.path.dirname(__file__), "../../.sessions")
os.makedirs(SESSIONS_DIR, exist_ok=True)
DB_PATH = os.path.join(SESSIONS_DIR, "chat_threads.db")


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
    thread_id = payload.get("thread_id", "default")

    if not message:
        print("event: error")
        print(f'data: {json.dumps({"error": "Empty message"})}')
        print()
        return

    print("event: thread")
    print(f"data: {json.dumps({'thread_id': thread_id})}")
    print(flush=True)

    session = SQLiteSession(db_path=DB_PATH, session_id=thread_id)

    result = Runner.run_streamed(zealphp_assistant, input=message, session=session)

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
