#!/usr/bin/env -S uv run --with openai-agents
# /// script
# requires-python = ">=3.10"
# dependencies = ["openai-agents"]
# ///
"""ZealPHP Learn notes agent — 6 tools, scoped to USER_ID, streams SSE events.

Called by ZealPHP's route/learn.php via proc_open. Reads JSON from argv[1]
(base64-encoded), streams SSE-formatted events to stdout.

Input JSON: {"message": "...", "thread_id": "...", "db_path": "...", "user_id": N, "profile": {...}}
Output: SSE events (event: token/tool_call/tool_args/tool_done/notes_changed/done, data: JSON)
"""
import asyncio
import base64
import json
import os
import sqlite3
import sys
import time

from agents import Agent, Runner, SQLiteSession, function_tool

DB_PATH = None
USER_ID = None
MAX_NOTES = int(os.environ.get("ZEALPHP_LEARN_MAX_NOTES", "256"))


def _db():
    c = sqlite3.connect(DB_PATH, timeout=2.0)
    c.row_factory = sqlite3.Row
    c.execute("PRAGMA journal_mode = WAL")
    c.execute("PRAGMA foreign_keys = ON")
    return c


@function_tool
def list_notes() -> str:
    """List all of the user's notes with id, title, and date."""
    with _db() as c:
        rows = c.execute(
            "SELECT id, title, updated_at FROM notes WHERE user_id=? ORDER BY updated_at DESC",
            (USER_ID,),
        ).fetchall()
    if not rows:
        return "(no notes)"
    return "\n".join(f"id={r['id']} title={r['title']!r}" for r in rows)


@function_tool
def read_note(note_id: int) -> str:
    """Read a single note's full content given its id."""
    with _db() as c:
        r = c.execute(
            "SELECT id, title, body FROM notes WHERE id=? AND user_id=?",
            (note_id, USER_ID),
        ).fetchone()
    return f"id={r['id']} title={r['title']!r}\n\n{r['body']}" if r else "Note not found."


@function_tool
def search_notes(query: str) -> str:
    """Search the user's notes for matches in title or body (SQL LIKE). Up to 10 hits."""
    q = f"%{query}%"
    with _db() as c:
        rows = c.execute(
            "SELECT id, title, substr(body, 1, 80) AS snip FROM notes WHERE user_id=? AND (title LIKE ? OR body LIKE ?) ORDER BY updated_at DESC LIMIT 10",
            (USER_ID, q, q),
        ).fetchall()
    if not rows:
        return f"(no matches for {query!r})"
    return "\n".join(f"id={r['id']} title={r['title']!r}" for r in rows)


@function_tool
def create_note(title: str, body: str) -> str:
    """Create a new note for the user. Returns the new note's id."""
    title = title.strip()
    if not title or len(title) > 200:
        return "Error: title must be 1-200 chars."
    if len(body) > 4096:
        return "Error: body must be <= 4096 chars."
    now = int(time.time())
    with _db() as c:
        count = c.execute(
            "SELECT COUNT(*) FROM notes WHERE user_id=?", (USER_ID,)
        ).fetchone()[0]
        if count >= MAX_NOTES:
            return f"Error: note limit ({MAX_NOTES}) reached."
        cur = c.execute(
            "INSERT INTO notes (user_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?)",
            (USER_ID, title, body, now, now),
        )
        return f"Created note id={cur.lastrowid}."


@function_tool
def update_note(
    note_id: int, title: str | None = None, body: str | None = None
) -> str:
    """Update an existing note's title or body. Must belong to the user."""
    with _db() as c:
        existing = c.execute(
            "SELECT title, body FROM notes WHERE id=? AND user_id=?",
            (note_id, USER_ID),
        ).fetchone()
        if not existing:
            return "Note not found."
        new_title = (title if title is not None else existing["title"]).strip()
        new_body = body if body is not None else existing["body"]
        if not new_title or len(new_title) > 200:
            return "Error: title must be 1-200 chars."
        if len(new_body) > 4096:
            return "Error: body too long."
        c.execute(
            "UPDATE notes SET title=?, body=?, updated_at=? WHERE id=? AND user_id=?",
            (new_title, new_body, int(time.time()), note_id, USER_ID),
        )
        return f"Updated note id={note_id}."


@function_tool
def delete_note(note_id: int) -> str:
    """Delete a note permanently. Must belong to the user."""
    with _db() as c:
        cur = c.execute(
            "DELETE FROM notes WHERE id=? AND user_id=?", (note_id, USER_ID)
        )
        return (
            f"Deleted note id={note_id}." if cur.rowcount else "Note not found."
        )


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
        tools=[list_notes, read_note, search_notes, create_note, update_note, delete_note],
    )


def emit(event: str, data: dict) -> None:
    sys.stdout.write(f"event: {event}\n")
    sys.stdout.write(f"data: {json.dumps(data)}\n\n")
    sys.stdout.flush()


async def main():
    global DB_PATH, USER_ID

    payload = json.loads(base64.b64decode(sys.argv[1]).decode())
    DB_PATH = payload["db_path"]
    USER_ID = int(payload["user_id"])
    thread_id = payload.get("thread_id", "default")
    profile = payload.get("profile", {
        "username": "user", "note_count": 0, "recent_note_titles": []
    })

    emit("thread", {"thread_id": thread_id})

    sessions_dir = os.path.join(os.path.dirname(__file__), "../../.sessions")
    os.makedirs(sessions_dir, exist_ok=True)
    session = SQLiteSession(
        db_path=os.path.join(sessions_dir, "learn_threads.db"),
        session_id=thread_id,
    )

    agent = build_agent(profile)
    result = Runner.run_streamed(agent, input=payload["message"], session=session)

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
                call_id = ev.data.item.id
                tool_names[call_id] = ev.data.item.name
                emit("tool_call", {
                    "id": call_id,
                    "name": ev.data.item.name,
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
