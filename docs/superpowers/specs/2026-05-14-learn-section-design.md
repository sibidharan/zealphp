# `/learn` Section — Design

## Goal

Ship a comprehensive learn-by-building tutorial section on the existing ZealPHP OSS website. Inspired in structure (not text/branding) by `react.dev/learn`. Twelve progressively-disclosed lessons. The Notes + AI-chat **tutorial app is the demo**, not a separate page — Lessons 8 and 9 host live, working interactions on the lesson page itself.

The narrative argument: you can build a modern, AI-assisted, component-driven web app with plain PHP + OpenSwoole + htmx, with no Node/React stack required. The implementation has to embody that claim, not just describe it.

After v1 ships and stabilizes, the tutorial app is extracted to a standalone `sibidharan/zealphp-learn` repo for `composer create-project`.

## Audience

PHP developers (juniors → senior), and full-stack devs who default to React + Node and want to see what a PHP-native alternative looks like end-to-end. Tone: confident, technical, sharp, educational. No hedging.

---

## Non-Goals (v1)

- A full multi-user authentication system. The vault model is intentionally a single secret, no email, no password recovery.
- Mobile-app companion or PWA install flow.
- i18n / multi-language lesson content.
- Storing notes in a real database (Postgres, SQLite). v1 is JSON-file per vault, locked via `flock`. Lesson 7 explicitly calls this out as a teaching choice, not a production recommendation.
- Backporting the new tool-call timeline UI to the existing home-page chat. The home chat keeps its text-only behavior; the learn chat is the showcase of the richer protocol.
- Tests in the extracted companion repo. The in-repo learn app gets PHPUnit coverage for the Notes/vault API; companion-repo testing is a follow-up.

---

## High-Level Architecture (Section A)

`/learn` is bolted onto the existing OSS site with **the rendering pipeline in `_master.php` left intact** — the existing `App::render("/pages/$page", ...)` line already resolves `$page = "learn/components"` to `template/pages/learn/components.php`, which is what we want. The only tweak to `_master.php` is forwarding one extra variable to `_head.php` so it can conditionally load the learn-specific CSS/JS: `App::render('/_head', compact('title', 'description', 'page'))` instead of the current two-arg compact. That's a 9-character change. The render flow itself is untouched.

A single new entry in `template/_nav.php`'s `$links` array adds `Learn` to the top navigation. Top-nav redesign is out of scope and will be tackled separately.

The sidebar is rendered **inside** the lesson content area by each lesson page, not by `_master.php`. This isolates the layout change to the `/learn/*` URL space; the rest of the site continues to look identical.

The lesson pages themselves are written to **demonstrate** good use of `App::render` / `App::renderToString` / `App::renderStream`. The Components lesson points at the rendered source. The Notes endpoint uses `renderStream`. The chat endpoint uses `renderToString` to compose assistant bubble HTML server-side.

### File layout

```
public/
  learn.php                              # /learn — index, Quick Start
  learn/
    create-app.php                       # /learn/create-app
    first-page.php
    components.php
    routing.php
    sessions.php
    htmx.php
    notes.php                            # contains the Notes app demo
    ai-chat.php                          # contains the AI chat demo
    async.php
    deployment.php
    philosophy.php
  css/learn.css                          # loaded only on learn pages (via _head.php conditional)
  js/learn.js                            # htmx + Notes/Chat client glue
route/
  learn.php                              # all /api/learn/* endpoints
template/
  _learn_sidebar.php                     # rendered by lesson pages, not by _master.php
  pages/learn.php                        # rendered when $page === 'learn'
  pages/learn/                           # rendered when $page === 'learn/<slug>'
    create-app.php
    first-page.php
    components.php
    routing.php
    sessions.php
    htmx.php
    notes.php
    ai-chat.php
    async.php
    deployment.php
    philosophy.php
  components/
    _callout.php                         # info/warn/deep-dive boxes
    _lesson_header.php                   # title + breadcrumb + prev/next chips
    _youwilllearn.php                    # the "You will learn" bullet box
    _deepdive.php                        # collapsible <details> block
    _tryit.php                           # bordered "Try it now" panel
    _note_card.php                       # one note's <article> — used in stream + chat refresh
    _counter_button.php                  # demonstrates renderToString in htmx lesson
examples/agents/
  notes_agent.py                         # uv-shebanged, 5 function tools
storage/
  learn-notes/                           # per-vault JSON: {sha256-hex}.json (gitignored except .gitkeep)
docs/
  learn-app.md                           # internal dev notes; source for the companion-repo README
.env.example                             # OPENAI_API_KEY, ZEALPHP_LEARN_AI_MODEL
scripts/
  extract-learn-repo.sh                  # one-shot extraction to sibidharan/zealphp-learn
```

### Routing wiring

- `public/learn.php` → `App::render('_master', ['title' => 'ZealPHP · Learn', 'page' => 'learn', 'active' => 'learn'])` → `_master.php` renders `template/pages/learn.php`.
- `public/learn/<slug>.php` → same call with `'page' => "learn/<slug>"` → `_master.php` renders `template/pages/learn/<slug>.php`.
- `_master.php` is modified only in the one-line tweak above (forwarding `$page` to `_head.php`). The implicit nested-public route `/learn/<slug>` already maps to `public/learn/<slug>.php` via ZealPHP's existing `nsPathRoute` for nested public dirs.
- `template/_nav.php` gains one entry: `'learn' => ['/learn', 'Learn']` placed after `'getting-started'`. The nav highlight uses prefix matching: `$active === 'learn' || str_starts_with($active, 'learn/')`. This requires a tiny tweak to the existing `$active` comparison in `_nav.php` (replace `$active === $key` with `$key === 'learn' ? str_starts_with($active, 'learn') : $active === $key`).

### CSS / JS loading

- `_head.php` gains a conditional: if `str_starts_with($page ?? '', 'learn')`, append `<link rel="stylesheet" href="/css/learn.css">`. Same for the htmx CDN script tag and `/js/learn.js`. Non-learn pages remain untouched.
- htmx is loaded from `https://unpkg.com/htmx.org@1.9.12` (pinned). No bundler.

### Vault model (recurring concept)

Lessons 6 (Sessions), 8 (Notes), and 9 (AI Chat) all use the same vault construct.

- User enters a "vault secret" on `/learn/notes`. The raw secret is **never persisted**. We compute `$hash = hash('sha256', $secret)` and store it in `$_SESSION['vault']`. The notes JSON file is keyed by hash: `storage/learn-notes/{hash}.json`.
- Anyone who knows the same secret accesses the same vault. There is no recovery — losing the secret means a new vault. This is intentional and explained on the page.
- A vault has at most 256 notes (cheap guard to keep the file readable for a tutorial demo). Each note has a max body of 4 KB. Exceeding either limit returns 422.
- `POST /api/learn/vault/lock` clears `$_SESSION['vault']` and redirects back to `/learn/notes`, which re-shows the unlock form.

---

## Lessons (Section B)

Twelve lessons, grouped in the sidebar:

**Get Started**
1. Quick Start (`/learn`)
2. Create a ZealPHP App (`/learn/create-app`)
3. Your First Page (`/learn/first-page`)

**Core Concepts**
4. Components (`/learn/components`)
5. Routing (`/learn/routing`)
6. Sessions & State (`/learn/sessions`)
7. Add htmx (`/learn/htmx`)

**Build the App**
8. Build Personal Notes (`/learn/notes`)
9. Add AI Chat (`/learn/ai-chat`)
10. Async & Coroutines (`/learn/async`)
11. Deployment (`/learn/deployment`)
12. Philosophy (`/learn/philosophy`)

Each lesson page includes:
- `_lesson_header.php` — number badge, title, prev/next chips, breadcrumb.
- `_youwilllearn.php` — 3-5 bullets summarizing the lesson.
- Body content with `_callout.php` info/warn boxes, `_code.php` blocks, `_deepdive.php` collapsibles where helpful.
- `_tryit.php` interactive panel for lessons that have one (3, 4, 6, 7).
- Live working demo embedded for lessons 8 and 9.
- Footer with prev/next chips.

### Lesson content briefs

| # | Slug | What it teaches | Live demo |
|---|------|-----------------|-----------|
| 1 | `learn` | What ZealPHP is in one paragraph. Why OpenSwoole. Why server-rendered. Why htmx over React. Tour CTA. | None |
| 2 | `create-app` | `composer create-project sibidharan/zealphp-project`, system deps (`sudo bash setup.sh`), `php app.php`, folder structure tree. | None |
| 3 | `first-page` | `public/index.php` echoing HTML, then upgrade to `App::render('_master', [...])`. Implicit public routing rule. | "Try it" iframe loading a tiny first-page route |
| 4 | `components` | Reusable PHP templates; side-by-side React vs. PHP equivalent; the three render methods table with file links. | "Try it" panel: card with `$variant` prop changed via htmx swapping |
| 5 | `routing` | Implicit (public/), implicit (api/), explicit (`$app->route`), namespaced (`nsRoute`, `nsPathRoute`), dynamic params. | Live link panel hitting existing `/demo/inject/*` |
| 6 | `sessions` | `session_start()`, `$_SESSION`, coroutine-safe sessions. Introduces the vault concept and shows the exact code that powers it. | "Try it" panel: shows your `session_id()`, lets you set/get a `$_SESSION` value |
| 7 | `htmx` | `hx-get`, `hx-post`, `hx-target`, `hx-swap`. Progressive enhancement explained. | "Try it" panel: counter button rendering via `renderToString('_counter_button')` |
| 8 | `notes` | Full Notes app on this page. Vault gate at top. Add, list, edit, delete with htmx. Source-code panels below each interaction explain the PHP that just handled it. | **The Notes app itself** |
| 9 | `ai-chat` | Chat box on this page (vault-required). Agent has access to your vault and tools to modify it. Tool calls render as timeline cards. Two-column: notes list left, chat right. Short "How this works" panel. | **The chat itself** |
| 10 | `async` | Where OpenSwoole helps. `go() + Channel` example. Live timing panel: parallel vs. sequential. | Live timing panel |
| 11 | `deployment` | `php app.php start -d`, systemd, Nginx reverse proxy, env vars, Docker. | None |
| 12 | `philosophy` | "Plain PHP scales further than you think" / "JavaScript where it helps, not as a tax" / "Server-first is simpler". CTA to `zealphp-learn`. | None |

### Sidebar (`template/_learn_sidebar.php`)

Hard-coded array of lesson groups. Renders a sticky `<aside>` on desktop (≥1024px), collapsible drawer on mobile via `<input type="checkbox">` (no JS). Highlights active item by matching `$active` against the lesson slug. Sidebar is rendered by the lesson page (not by `_master.php`), so non-learn pages are unaffected.

---

## Notes App + AI Agent (Section C)

### Endpoints (`route/learn.php`)

All endpoints validate `$hash = $_SESSION['vault'] ?? null` and return 401 + a clear JSON `{error: "vault_locked"}` if absent (except `POST /api/learn/vault`).

| Method | Path | Behavior | Render |
|---|---|---|---|
| `POST` | `/api/learn/vault` | Reads `secret` from body. Validates 1 ≤ len(secret) ≤ 256. Sets `$_SESSION['vault'] = hash('sha256', $secret)`. Redirects 302 to `/learn/notes`. | — |
| `POST` | `/api/learn/vault/lock` | `unset($_SESSION['vault'])`. Redirects 302 to `/learn/notes`. | — |
| `GET` | `/api/learn/notes` | HTML fragment: notes list. Used by htmx to refresh after vault changes. | `App::renderStream('/components/_note_card', $note)` per note via Generator, yielding chunks as soon as each is rendered |
| `POST` | `/api/learn/notes` | Body: `{title, body}`. Creates note. Returns HTML for the new note's `<article>`. htmx swaps it into `#notes-list` via `hx-swap="afterbegin"`. | `App::renderToString('/components/_note_card', $note)` |
| `POST` | `/api/learn/notes/{id}` | Body: `{title?, body?}`. Updates note. Returns the note's `<article>`. | `renderToString` |
| `DELETE` | `/api/learn/notes/{id}` | Deletes note. Returns empty 200. htmx `hx-swap="outerHTML"` on the article removes it from the DOM. | — |
| `POST` | `/api/learn/chat` | SSE stream — see C.3. | `$response->sse(...)` |
| `GET` | `/api/learn/chat/status` | JSON `{ai_enabled, mock_mode, model}`. | — |
| `GET` | `/api/learn/demo/incr` | Counter demo for Lesson 7. Increments a session-scoped int and returns the rendered button. | `renderToString('/components/_counter_button')` |

### Storage format

`storage/learn-notes/{hash}.json`:

```json
{
  "version": 1,
  "notes": [
    {
      "id": "9f3e8c2a-...",
      "title": "Buy milk",
      "body": "Whole, not skim",
      "created_at": 1747200000,
      "updated_at": 1747200000
    }
  ]
}
```

- `flock($fp, LOCK_EX)` on writes (both PHP and Python — Python uses `fcntl.flock(fp, fcntl.LOCK_EX)`, which is `flock`-compatible).
- `flock($fp, LOCK_SH)` on reads.
- Max 256 notes per vault. Max title 200 chars. Max body 4096 bytes.

### Python agent (`examples/agents/notes_agent.py`)

uv-shebang style identical to `examples/agents/chat_agent.py`. Receives `{message, thread_id, vault_path}` as base64 argv[1]. Module-global `VAULT_PATH` set from `vault_path`. SQLiteSession per `thread_id` for memory across turns.

**Tools** (all `@function_tool`):

```python
@function_tool
def list_notes() -> str:
    """List all notes in the user's vault, with id, title, and created date."""

@function_tool
def read_note(note_id: str) -> str:
    """Read a single note's full content given its id."""

@function_tool
def create_note(title: str, body: str) -> str:
    """Create a new note in the user's vault. Returns the new note's id."""

@function_tool
def update_note(note_id: str, title: str | None = None, body: str | None = None) -> str:
    """Update an existing note's title or body."""

@function_tool
def delete_note(note_id: str) -> str:
    """Delete a note permanently."""
```

All tools open `VAULT_PATH`, acquire `LOCK_EX`, mutate, release. Title/body limits enforced server-side in the tool function — exceeding returns an error string the agent surfaces to the user. Invalid `note_id` returns "Note not found."

**System prompt** (mirrors `chat_agent.py` HTML-only rule):

```
You are the user's personal notes assistant. They keep a private vault of notes.

Use your tools to list, read, create, update, or delete notes as the user requests.
Always confirm destructive actions in your reply
  (e.g., "I deleted the 'Buy milk' note.").
When showing a list of notes, format as <ul><li>title — id</li></ul>.
Be concise.

OUTPUT FORMAT — raw HTML, NOT markdown.
- <p> for paragraphs, <code> for inline code, <pre><code> for blocks
- <strong>/<em> for emphasis; <ul>/<ol>/<li> for lists; <br> for line breaks
- Never use markdown syntax (no #, no *, no -, no backticks)
- Do not wrap the entire response in a container div
```

### SSE wire protocol

```
event: thread        data: {"thread_id": "abc"}
event: token         data: {"token": "<p>Sure, I'll do that.</p>"}
event: tool_call     data: {"id": "call_1", "name": "create_note", "phase": "start"}
event: tool_args     data: {"id": "call_1", "delta": "{\"title\":\"Buy"}
event: tool_args     data: {"id": "call_1", "delta": " milk\"}"}
event: tool_done     data: {"id": "call_1", "status": "ok", "result_preview": "id: 9f3e..."}
event: vault_changed data: {}
event: token         data: {"token": "<p>Created!</p>"}
event: done          data: {"done": true}
event: error         data: {"error": "rate_limit"}
```

**Python side — event mapping** from `result.stream_events()`:

| Agents SDK event | Wire event |
|---|---|
| `raw_response_event` / `response.output_text.delta` | `token` |
| `raw_response_event` / `response.output_item.added` where `item.type == "function_call"` | `tool_call` (phase: start) |
| `raw_response_event` / `response.function_call_arguments.delta` | `tool_args` |
| `raw_response_event` / `response.function_call_arguments.done` | (suppressed; `tool_done` will follow) |
| `run_item_stream_event` / `tool_call_output_item` | `tool_done` (status `ok`, result truncated to 200 chars in `result_preview`) |

Python also emits a `vault_changed` event after any `tool_done` for `create_note` / `update_note` / `delete_note`. PHP redundantly checks for the same tool names in the proxy and emits `vault_changed` itself if Python didn't — belt-and-suspenders.

**PHP side — proxy structure** (in `route/learn.php`, almost identical to `route/chat.php`):

- `proc_open` `uv run examples/agents/notes_agent.py <b64-payload>`. Env: `OPENAI_API_KEY` passed through, `OPENAI_MODEL` from `ZEALPHP_LEARN_AI_MODEL` or default `gpt-4.1-mini`.
- Read stdout line by line. For each `event: X\ndata: Y\n\n` block, re-emit via `$emit($data, $event)`.
- On `tool_done` for a vault-mutating tool, also `$emit('{}', 'vault_changed')` if not already emitted.
- Rate limit: 30 chat turns per IP per hour (vs. 60 for `/api/chat`), via `Store::make('learn_chat_rl', ...)`. Surfaces as `event: error` `data: {"error": "rate_limit", "retry_after": <s>}`.

### Frontend chat timeline (`public/js/learn.js`)

The assistant bubble is a container of "items" — text fragments interleaved with tool cards — built up incrementally as events stream in:

```html
<div class="chat-msg assistant">
  <div class="chat-bubble">
    <div class="chat-item text"><p>Sure, I'll do that.</p></div>
    <div class="chat-item tool" data-id="call_1" data-status="ok">
      <div class="tool-head">
        <span class="tool-icon">⚙</span>
        <span class="tool-name">create_note</span>
        <span class="tool-status">done</span>
      </div>
      <details class="tool-detail">
        <summary>args + result</summary>
        <pre class="tool-args">{"title": "Buy milk", "body": "Whole, not skim"}</pre>
        <pre class="tool-result">id: 9f3e8c2a-...</pre>
      </details>
    </div>
    <div class="chat-item text"><p>Created!</p></div>
  </div>
</div>
```

**Event handlers:**

- `token` → if last item is `text`, append `delta` to it; else create a new `text` item.
- `tool_call` (phase start) → close any open text item; create a new `tool` item with `data-id`, status `running`, empty args/result.
- `tool_args` → append `delta` to the matching tool card's `<pre class="tool-args">`.
- `tool_done` → set `data-status` on the tool card, populate `<pre class="tool-result">`, update header label.
- `vault_changed` → `htmx.ajax('GET', '/api/learn/notes', '#notes-list')`.
- `done` → re-enable chat input.
- `error` → render an error banner inside the assistant bubble; re-enable input.

Thread persistence: `localStorage.setItem('zealphp_learn_thread', threadId)`. On lock-vault, also clear this so a new vault gets a fresh thread.

### Mock mode (no OPENAI_API_KEY)

PHP-only, no Python invoked. `route/learn.php`'s chat handler detects the missing key and serves a rule-based SSE stream that **actually mutates the vault**. Parses the user's message for keywords:

- "list" / "show all" → emits `tool_call list_notes` → `tool_done` with synthesized result → `token` with the rendered list HTML.
- "create" / "add" → emits `token` "Got it, creating that…", then `tool_call create_note` with streamed JSON args extracted from the message via regex `create (?:a )?note (?:titled |called |saying )?["']?(.+?)["']?$`, then `tool_done`, then `vault_changed`, then `token` confirmation.
- "delete" → similar with `delete_note` matching by title fuzzy-equality.
- "read" / "what's in" → `read_note` for the first match.
- Anything else → `token` only: a generic "Mock mode is active — set `OPENAI_API_KEY` to enable real AI. Try: 'create a note titled buy milk'."

Status response: `{ai_enabled: false, mock_mode: true, model: "mock-rules-v1"}`. The chat UI surfaces a small badge: "Mock mode — set OPENAI_API_KEY for real AI."

### Layout for Lesson 9

Inside `template/pages/learn/ai-chat.php`, content area is a CSS grid:

- Left column (40%): `<div id="notes-list">` with the current vault's notes (htmx-refreshable).
- Right column (60%): chat box (messages + input).
- Below: "How this works" `<details>` block — 4 short lines, links to `route/learn.php` and `examples/agents/notes_agent.py`. No SDK explanation; the streaming tool cards are the documentation.

Vault unlock form sits above both columns if locked.

---

## `zealphp-learn` Companion Repo (Section D)

### What's in scope

The companion is a `composer create-project`-ready template that someone can clone, set `OPENAI_API_KEY`, run `php app.php`, and have the Learn tutorial app running standalone (no main-site OSS scaffolding). It is **not** a copy of php.zeal.ninja.

### Extraction script (`scripts/extract-learn-repo.sh`)

Lives in the main repo. Run by a human, never by CI. Resolves the target via the CLAUDE.md companion-discovery chain:

1. `$ZEALPHP_LEARN_DIR` env var
2. `../zealphp-learn` (sibling of main repo)
3. `../../zealphp-learn` (parent's sibling)
4. Else: fail with a clear message telling the user to clone or set the env var.

**Behavior:**

- Refuses to run if the target's working tree is dirty (surfaces, does not auto-stash).
- `rsync` with explicit allow-list path roots (no `--delete` of unknown target files):
  - `public/learn.php`, `public/learn/`, `public/css/learn.css`, `public/js/learn.js`
  - `route/learn.php`
  - `template/_learn_sidebar.php`
  - `template/pages/learn.php`, `template/pages/learn/`
  - `template/components/_callout.php`, `template/components/_lesson_header.php`, `template/components/_youwilllearn.php`, `template/components/_deepdive.php`, `template/components/_tryit.php`, `template/components/_note_card.php`, `template/components/_counter_button.php`
  - `examples/agents/notes_agent.py`
  - `docs/learn-app.md` → companion `README.md` (heredoc-stitched, not raw-copied)
- Writes (does not rsync — these are intentionally divergent):
  - Companion's `app.php` (slimmed; see D.3)
  - Companion's `template/_master.php` (slimmed; no main-site nav)
  - Companion's `template/_head.php` (slimmed)
  - Companion's `template/_footer.php` (slimmed)
  - Companion's `composer.json` (depends on `sibidharan/zealphp: ^X.Y.Z` for the current release)
  - Companion's `.env.example`
  - Companion's `.gitignore`
- Merges `.env.example` (append-only of new keys, never overwrites).
- Runs `composer install` in the target.
- Prints `git status` of the target and a hint message telling the user how to commit + push + tag.

The script does NOT auto-commit, auto-push, or auto-tag — explicit user actions, per CLAUDE.md release safety policy.

### Companion repo layout

```
zealphp-learn/
  app.php                              # ~40 lines, see D.3
  composer.json                        # ^X.Y.Z dep on sibidharan/zealphp
  composer.lock
  vendor/                              # checked in, like sibidharan/zealphp-project
  .env.example
  .gitignore                           # ignores storage/learn-notes/*.json, .sessions/*.db
  README.md                            # extraction-specific
  LICENSE                              # mirror upstream
  CHANGELOG.md                         # starts at vX.Y.Z
  public/
    index.php                          # redirects to /learn
    learn.php
    learn/{all 11 lesson sub-pages}.php
    css/learn.css
    js/learn.js
  route/
    learn.php
  template/
    _master.php                        # slimmed
    _head.php                          # slimmed
    _footer.php                        # slimmed
    _learn_sidebar.php
    pages/learn.php
    pages/learn/{12 lesson templates}.php
    components/_*.php
  examples/agents/
    notes_agent.py
  storage/
    learn-notes/.gitkeep
    .sessions/.gitkeep
  scripts/
    setup.sh                           # copy of main-repo setup.sh
```

### Slimmed `app.php` (companion)

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;

$app = new App();
$app->superglobals(false);
require __DIR__ . '/route/learn.php';

$port = (int)(getenv('PORT') ?: 8080);
$host = getenv('HOST') ?: '0.0.0.0';
$app->run(['host' => $host, 'port' => $port]);
```

No glob over `route/*.php`, no WebSocket, no CGI worker.

### Slimmed `_master.php` (companion)

Drops the main-site top nav (`_nav.php` not included). New top bar: `ZealPHP Learn` logo, "Lock vault" link (when unlocked), GitHub link. Sidebar rendered the same way as in the main repo (by each lesson page).

### Sync cadence — per release

Companion sync is folded into CLAUDE.md's "Releasing a new version" procedure, immediately after the existing scaffold sync step:

```bash
cd <zealphp-learn-path>
# (or simply run scripts/extract-learn-repo.sh from main repo)
# Edit composer.json: "sibidharan/zealphp": "^X.Y.Z"
composer update sibidharan/zealphp --with-dependencies
git add composer.json composer.lock vendor/
git commit -m "chore: refresh composer.lock + vendor for ZealPHP vX.Y.Z"
git tag -a vX.Y.Z -m "Release vX.Y.Z — tracks sibidharan/zealphp vX.Y.Z"
for remote in $(git remote); do
  git push $remote main && git push $remote vX.Y.Z
done
```

CLAUDE.md's "Companion repos — keep in sync" table gains a third row for `sibidharan/zealphp-learn`. Mid-release demo improvements still land in the main repo (and on php.zeal.ninja immediately) but do not get a new companion tag until the next coordinated release.

---

## Cross-cutting Concerns

### Security

- Vault secret never persisted plaintext. Only `hash('sha256', $secret)` is stored in `$_SESSION` and used as a filename.
- Notes file path is `storage/learn-notes/{hash}.json` where `{hash}` is the hex output of sha256 — 64 hex chars, no path-traversal risk.
- `POST /api/learn/vault` rate-limit: 10 attempts per IP per minute, via `Store::make('learn_vault_rl', ...)`. Prevents brute-force discovery of existing vault secrets.
- `OPENAI_API_KEY` only read from env. Never logged. The status endpoint exposes only whether it's present (`ai_enabled: bool`), not the value.
- Python agent escaped via `escapeshellarg` for both the script path and the base64 payload (matches `route/chat.php`).
- htmx CSRF: vault, notes, and chat POSTs require `$_SESSION['vault']` to be set, which serves as soft CSRF (an attacker with no session can't act). For Lesson 7's counter demo we accept the soft guarantee — it's a tutorial.

### Performance

- Notes endpoints use `renderStream` for list reads — streams chunks as each note's HTML is ready.
- Python agent process started fresh per chat turn. Lesson 10 explicitly addresses this and explains how to use task workers if it ever matters (it doesn't for a tutorial).
- Coroutine pattern in Lesson 10's live demo: `go() { ... } / Channel` to load notes + warm up agent path probe in parallel; sequential version for comparison. Real, measurable timing difference rendered server-side.

### Error handling

- All `/api/learn/*` endpoints return JSON `{error: "<machine_code>"}` with HTTP status matching the failure (401 vault_locked, 422 validation_failed, 429 rate_limit, 500 internal_error).
- htmx-handler endpoints that return HTML fragments use `text/html` content type so htmx swaps work; JSON errors use `application/json` and an `hx-reswap="innerHTML"` hint header so htmx can choose to swap an error block.
- The chat SSE stream surfaces errors via `event: error` and always emits `event: done` to close the stream cleanly.
- Python agent crashes: PHP detects non-zero exit code or empty stdout and emits `event: error` `data: {"error": "agent_unavailable"}`.

### Environment variables

| Var | Required | Default | Used by |
|---|---|---|---|
| `OPENAI_API_KEY` | No (mock mode without) | — | `route/learn.php`, `notes_agent.py` |
| `ZEALPHP_LEARN_AI_MODEL` | No | `gpt-4.1-mini` | `notes_agent.py` |
| `ZEALPHP_LEARN_RATE_LIMIT` | No | `30` | `route/learn.php` (chat turns/hour/IP) |
| `ZEALPHP_LEARN_MAX_NOTES` | No | `256` | `route/learn.php`, `notes_agent.py` |

Documented in `.env.example` at the repo root (which is gitignored for actual values).

---

## Testing

### In-repo (sibidharan/zealphp)

**Unit tests** — `tests/Unit/LearnVaultTest.php`:
- Hash-key derivation is deterministic for the same secret.
- Secret length validation (1 ≤ len ≤ 256).
- Note title/body limits.

**Integration tests** — `tests/Integration/LearnApiTest.php` (requires server up, just like other Integration tests):
- 401 from notes endpoints without vault session.
- `POST /api/learn/vault` sets session and 302s.
- Notes CRUD round-trips: create, list, update, delete.
- Two different vault secrets → two isolated note sets.
- Vault lock clears the session.
- `GET /api/learn/chat/status` returns expected shape.
- `POST /api/learn/chat` in mock mode emits `tool_call` and `tool_done` SSE events.
- Lesson page HTTP 200 for all 12 lesson URLs.

**No tests** for the Python agent in v1. (The real-API path is non-deterministic; the SDK is well-tested upstream.) A follow-up could add a stub-LLM Python test harness.

### Companion repo

No tests in v1 extraction. Follow-up.

---

## Risks & Open Questions

1. **Python agent process start overhead.** Each chat turn spawns `uv run`. uv caches deps so subsequent runs are fast (~200ms cold), but it's still a per-turn process spawn. Acceptable for a tutorial; addressed in Lesson 10 if asked.

2. **`flock` cross-process semantics.** PHP `flock` and Python `fcntl.flock` agree on POSIX hosts. macOS dev: same. Windows: not a target. Documented in `docs/learn-app.md`.

3. **htmx + SSE.** htmx has an `hx-ext="sse"` extension but it doesn't fit our use case (we POST then read the SSE response, vs. opening a long-lived `EventSource`). The chat handler uses raw `fetch` + ReadableStream — same as the home chat. htmx handles all the non-chat interactivity. This is explicitly explained in Lesson 7 with a callout.

4. **Mock mode reach.** Five regex-based intents (`list`, `create`, `delete`, `read`, fallback). Good enough for a demo, not a substitute for the real model. Documented prominently in the chat header when active.

5. **Session storage capacity.** Default `/var/lib/php/sessions` from CLAUDE.md is fine for a few hundred concurrent users. Beyond that, a real session backend (Redis, OpenSwoole `Store`) would be wired in. Out of scope for v1.

6. **Companion repo identity.** Confirmed name: `sibidharan/zealphp-learn`. Confirmed scope: a learn-by-running template, not a docs mirror.

---

## Acceptance Criteria

A v1 ship is considered done when:

1. Every URL in the route table below returns 200 (or the expected non-200) from a clean checkout with `php app.php` running.
2. All 12 lesson pages render with no PHP warnings/errors.
3. Lesson 8 vault flow works: enter a secret → see empty notes list → add a note → see it appear → delete it → see it gone.
4. Lesson 9 chat works in mock mode (no API key): user message "create a note titled buy milk" results in a `tool_call` card streaming in the chat AND the notes list refreshing to show the new note.
5. Lesson 9 chat works in real mode (with `OPENAI_API_KEY`): same demo behavior, plus the model can answer questions about existing notes.
6. The top-nav `Learn` link highlights as active on every `/learn/*` URL.
7. The sidebar highlights the correct lesson item on every `/learn/<slug>`.
8. Mobile (≤640px wide): sidebar collapses to a drawer, lesson content is readable, chat + notes layout reflows to single column.
9. `./vendor/bin/phpunit tests/Unit/LearnVaultTest.php` and `tests/Integration/LearnApiTest.php` pass.
10. `scripts/extract-learn-repo.sh` produces a clean target tree that, after `composer install`, runs `php app.php` and serves the same Learn experience standalone.

### Route table for acceptance check

Pages (must 200): `/learn`, `/learn/create-app`, `/learn/first-page`, `/learn/components`, `/learn/routing`, `/learn/sessions`, `/learn/htmx`, `/learn/notes`, `/learn/ai-chat`, `/learn/async`, `/learn/deployment`, `/learn/philosophy`.

API: `POST /api/learn/vault`, `POST /api/learn/vault/lock`, `GET /api/learn/notes`, `POST /api/learn/notes`, `POST /api/learn/notes/{id}`, `DELETE /api/learn/notes/{id}`, `POST /api/learn/chat`, `GET /api/learn/chat/status`, `GET /api/learn/demo/incr`.

---

## Out-of-Order Build Sequencing

The implementation plan (next document) will sequence work so that each milestone is independently mergeable and demoable. Rough order, subject to plan refinement:

1. Sidebar shell + lesson scaffolding + 12 placeholder lesson pages (so navigation works end-to-end).
2. Lesson components (`_callout`, `_lesson_header`, `_youwilllearn`, `_deepdive`, `_tryit`).
3. Vault flow + Notes API (PHP only, no AI yet). Lesson 8 becomes interactive.
4. htmx wiring for Notes + Lesson 7 counter.
5. Lesson content for Lessons 1-7 (write the actual prose + code blocks).
6. Mock-mode chat endpoint + frontend timeline UI. Lesson 9 becomes interactive in mock mode.
7. Python `notes_agent.py` + real-mode SSE proxy.
8. Lesson 9, 10, 11, 12 content.
9. PHPUnit tests.
10. Extraction script + standalone `_master.php` / `app.php` generation.
11. First companion-repo extraction + push (per next release).
