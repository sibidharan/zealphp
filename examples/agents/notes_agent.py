#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""ZealPHP Learn notes agent — 6 tools, calls ZealPHP API via HTTP.

Called by ZealPHP's Chat.php via proc_open. Reads JSON from argv[1]
(base64-encoded), streams SSE-formatted events to stdout.

The agent authenticates as the user by sending their PHPSESSID cookie
with every API call. This means note changes go through the same API
endpoints as the frontend — triggering WebSocket broadcasts for live
cross-tab updates.

Input JSON: {"message": "...", "thread_id": "...", "session_id": "...", "api_base": "...", "user_id": N, "profile": {...}}
Output: SSE events (event: token/tool_call/tool_args/tool_done/notes_changed/done, data: JSON)
"""
import asyncio
import base64
import json
import os
import sys
import urllib.parse
import urllib.request
from dataclasses import dataclass

from agents import Agent, Runner, RunContextWrapper, SQLiteSession, function_tool

MAX_NOTES = int(os.environ.get("ZEALPHP_LEARN_MAX_NOTES", "256"))


@dataclass
class AgentContext:
    api_base: str
    session_id: str
    user_id: int


def _api(ctx: AgentContext, method: str, path: str, data=None):
    url = f"{ctx.api_base}{path}"
    body = json.dumps(data).encode() if data else None
    req = urllib.request.Request(
        url,
        data=body,
        headers={
            "Cookie": f"PHPSESSID={ctx.session_id}",
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
        method=method,
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            text = resp.read().decode()
            return json.loads(text) if text.strip() else {}
    except urllib.error.HTTPError as e:
        return {"error": f"HTTP {e.code}", "detail": e.read().decode()[:200]}


@function_tool
def list_notes(context: RunContextWrapper[AgentContext]) -> str:
    """List all of the user's notes with id, title, and date."""
    notes = _api(context.context, "GET", "/api/learn/notes")
    if isinstance(notes, dict) and "error" in notes:
        return f"Error: {notes['error']}"
    if not notes:
        return "(no notes)"
    return "\n".join(f"id={n['id']} title={n['title']!r}" for n in notes)


@function_tool
def read_note(context: RunContextWrapper[AgentContext], note_id: int) -> str:
    """Read a single note's full content given its id."""
    n = _api(context.context, "GET", f"/api/learn/notes/{note_id}")
    if isinstance(n, dict) and "error" in n:
        return "Note not found."
    return f"id={n['id']} title={n['title']!r}\n\n{n.get('body', '')}"


@function_tool
def search_notes(context: RunContextWrapper[AgentContext], query: str) -> str:
    """Search the user's notes for matches in title or body. Up to 10 hits."""
    q = urllib.parse.quote(query)
    rows = _api(context.context, "GET", f"/api/learn/notes/search?q={q}")
    if isinstance(rows, dict) and "error" in rows:
        return f"Error: {rows['error']}"
    if not rows:
        return f"(no matches for {query!r})"
    return "\n".join(f"id={r['id']} title={r['title']!r}" for r in rows)


@function_tool
def create_note(
    context: RunContextWrapper[AgentContext], title: str, body: str
) -> str:
    """Create a new note for the user. Returns the new note's id."""
    result = _api(
        context.context, "POST", "/api/learn/notes", {"title": title, "body": body}
    )
    if isinstance(result, dict) and "error" in result:
        return f"Error: {result['error']}"
    return f"Created note id={result.get('id', '?')}."


@function_tool
def update_note(
    context: RunContextWrapper[AgentContext],
    note_id: int,
    title: str | None = None,
    body: str | None = None,
) -> str:
    """Update an existing note's title or body. Must belong to the user."""
    data = {}
    if title is not None:
        data["title"] = title
    if body is not None:
        data["body"] = body
    result = _api(context.context, "POST", f"/api/learn/notes/{note_id}", data)
    if isinstance(result, dict) and "error" in result:
        return f"Error: {result['error']}"
    return f"Updated note id={note_id}."


@function_tool
def delete_note(context: RunContextWrapper[AgentContext], note_id: int) -> str:
    """Delete a note permanently. Must belong to the user."""
    result = _api(context.context, "DELETE", f"/api/learn/notes/{note_id}")
    if isinstance(result, dict) and "error" in result:
        return "Note not found."
    return f"Deleted note id={note_id}."


def build_agent(profile: dict) -> Agent:
    recent = "\n".join(
        f"  - {t}" for t in profile.get("recent_note_titles", [])
    ) or "  (none yet)"
    sys_prompt = (
        f"You are {profile['username']}'s personal notes assistant. "
        f"They currently have {profile['note_count']} notes. Their most recent notes are:\n{recent}\n\n"
        "Use your tools to list, search, read, create, update, or delete notes as requested. "
        "Always confirm destructive actions in your reply. "
        "When showing a list of notes, format as <ul><li>title — id</li></ul>. Be concise.\n\n"
        "OUTPUT FORMAT — raw HTML, NOT markdown. <p> for paragraphs, <code> for inline code, "
        "<strong>/<em> for emphasis, <ul>/<ol>/<li> for lists. Never use markdown syntax."
    )
    model = os.environ.get("ZEALPHP_LEARN_AI_MODEL", "gpt-4.1-mini")
    return Agent(
        name="ZealPHP Notes",
        model=model,
        instructions=sys_prompt,
        tools=[
            list_notes,
            read_note,
            search_notes,
            create_note,
            update_note,
            delete_note,
        ],
    )


def emit(event: str, data: dict) -> None:
    sys.stdout.write(f"event: {event}\n")
    sys.stdout.write(f"data: {json.dumps(data)}\n\n")
    sys.stdout.flush()


async def main():
    payload = json.loads(base64.b64decode(sys.argv[1]).decode())
    thread_id = payload.get("thread_id", "default")
    profile = payload.get("profile", {
        "username": "user", "note_count": 0, "recent_note_titles": []
    })

    ctx = AgentContext(
        api_base=payload["api_base"],
        session_id=payload["session_id"],
        user_id=int(payload["user_id"]),
    )

    emit("thread", {"thread_id": thread_id})

    sessions_dir = os.path.join(os.path.dirname(__file__), "../../.sessions")
    os.makedirs(sessions_dir, exist_ok=True)
    session = SQLiteSession(
        db_path=os.path.join(sessions_dir, "learn_threads.db"),
        session_id=thread_id,
    )

    agent = build_agent(profile)
    result = Runner.run_streamed(agent, input=payload["message"], session=session, context=ctx)

    tool_names = {}
    async for ev in result.stream_events():
        if ev.type == "raw_response_event":
            t = getattr(ev.data, "type", "")
            if t == "response.output_text.delta":
                if ev.data.delta:
                    emit("token", {"token": ev.data.delta})
            elif (
                t == "response.output_item.added"
                and getattr(ev.data.item, "type", "") == "function_call"
            ):
                item_id = ev.data.item.id
                call_id = getattr(ev.data.item, "call_id", item_id)
                name = ev.data.item.name
                tool_names[item_id] = name
                tool_names[call_id] = name
                emit("tool_call", {
                    "id": call_id,
                    "name": name,
                    "phase": "start",
                })
            elif t == "response.function_call_arguments.delta":
                emit("tool_args", {
                    "id": ev.data.item_id,
                    "delta": ev.data.delta,
                })
        elif (
            ev.type == "run_item_stream_event"
            and ev.item.type == "tool_call_output_item"
        ):
            call_id = ev.item.raw_item.get("call_id", "?")
            out = str(ev.item.output)[:200]
            name = tool_names.get(call_id, "")
            emit("tool_done", {
                "id": call_id,
                "status": "ok",
                "result_preview": out,
            })
            if name in ("create_note", "update_note", "delete_note"):
                emit("notes_changed", {})

    emit("done", {"done": True})


if __name__ == "__main__":
    asyncio.run(main())
