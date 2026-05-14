# Homepage Redesign: "The PHP Runtime for AI Web Apps"

## Goal

Reposition ZealPHP from "async PHP framework on OpenSwoole" to "the PHP runtime for AI web applications." Bold, opinionated, OSS-native. Lead with SSR streaming + performance proof, back claims with code comparisons and a live AI chat demo.

## Audience

All developers building modern web apps, positioned AI-first:
- AI app builders needing streaming, SSE, WebSocket for real-time AI UIs
- PHP devs wanting Go-level performance without leaving the ecosystem
- Full-stack solopreneurs shipping one-process backends

## Tone

Bold claims backed by proof. Confident, opinionated, OSS-proud. No corporate hedging.

---

## Page Structure

### Section 1: Hero

**File:** `template/pages/home.php` (top)

- **Headline:** `ZealPHP` with subtitle *"The PHP Runtime for AI Web Apps"*
- **Subhead:** "Stream AI responses in 5 lines. WebSocket, SSE, shared memory, task workers — one server, one process. Go-level performance, PHP simplicity."
- **Hero demo:** Split panel (left = ZealPHP streaming code, right = animated streaming output simulation). The code shows a real SSE/generator route streaming AI tokens. The right panel simulates tokens appearing word-by-word via CSS/JS animation.
- **Benchmark bar:** `67k req/s · 21ms p90 · 4 workers · 0 failures` with tagline *"Benchmarked. Not promised."*
- **CTAs:** `Get Started →` | `GitHub ↗`
- **Badges:** Keep existing (Packagist, Stars, CI, Coverage)

### Section 2: "One Server. Everything."

Visual architecture comparison:

- **Left column** — "Your AI app without ZealPHP": diagram showing Express/FastAPI + Redis + Bull/Celery + Socket.io + SSE proxy + Nginx. 5-6 boxes with connecting lines. CSS-drawn, no images.
- **Right column** — "Your AI app on ZealPHP": single box `php app.php` containing: HTTP, WebSocket, SSE, task workers, shared memory, sessions, timers.
- **Tagline:** "No Redis. No message queue. No sidecar. Your entire AI backend is one command."

### Section 3: "Why Not Just Use [X]?"

Three bold-claim blocks, each with:
1. Provocative headline
2. Side-by-side code comparison (ZealPHP left, competitor right)
3. Short explanation

**Block A — vs Node.js:**
- Headline: "Node.js needs 30 lines for what ZealPHP does in 5"
- Compare: ZealPHP generator yield streaming vs Node ReadableStream/TransformStream for AI token streaming

**Block B — vs Go:**
- Headline: "Go is fast. ZealPHP is fast AND expressive."
- Compare: ZealPHP route with param injection + `return $array` vs Go http.HandleFunc with manual parsing + json.Marshal

**Block C — vs Python FastAPI:**
- Headline: "FastAPI can't hold 10k WebSocket connections"
- Compare: ZealPHP coroutine model (workers * coroutines) vs FastAPI sync/async limitations. Architectural diagram.

### Section 4: Live AI Chat Demo

A working, embedded chat interface on the homepage:
- Text input + send button
- AI responses stream in token-by-token (SSE from ZealPHP backend)
- "Show source" toggle reveals the full route handler code
- Powered by Anthropic Claude API via Agent SDK
- Thread support (conversation context maintained)

**Backend implementation** (separate from homepage template):
- New route in `route/chat.php` or `route/demo.php`
- SSE endpoint that streams AI responses
- Uses Anthropic SDK (composer package) or raw HTTP to Claude API
- Conversation threads stored in Store (cross-worker shared memory) or coroutine context

### Section 5: Feature Grid (reframed for AI)

Keep 13-card grid structure. Rewrite copy to connect each feature to AI use cases:

| Card | New tagline |
|------|------------|
| Streaming | "Stream AI tokens as they generate. `yield` is your streaming primitive." |
| WebSocket | "Real-time agent-to-user comms. Multi-user AI sessions." |
| Store | "Share state across workers. No Redis for AI session memory." |
| Coroutines | "Fan out to multiple AI models in parallel. Merge responses." |
| Routing | "Flask-style routes. Reflection-based injection. Zero boilerplate." |
| Responses | "Return int, array, string, Generator — framework does the right thing." |
| Middleware | "CORS, ETag, compression. PSR-15 compatible. Plug in anything." |
| Sessions | "Coroutine-safe. Your existing session code just works." |
| Timers | "Schedule recurring AI tasks. Polling, cleanup, health checks." |
| HTTP | "Full HTTP/1.1. HEAD, OPTIONS, redirects, gzip — all built-in." |
| Templates | "SSR streaming templates. Compose with `yield from`." |
| ZealAPI | "Drop a PHP file in `api/`. It's a route. File-based REST." |
| Legacy Apps | "Run WordPress unmodified. CGI worker for full global scope." |

### Section 6: Quick Start + Deploy

Keep existing implementation. One addition: note about CLAUDE.md in starter project for AI-assisted development.

### Section 7: Return Conventions Table

Keep existing "Return anything, get the right response" table as-is.

### Section 8: Config Converter

Keep existing AI-powered converter tool as-is.

### Section 9: Footer CTA

New: "Build your first AI chat in 60 seconds" — compact code snippet showing the chat route + `composer create-project` command.

---

## Files to Modify

| File | Change |
|------|--------|
| `template/pages/home.php` | Full rewrite of hero, new sections 2-3, reframe feature grid copy, add footer CTA |
| `public/css/zealphp.css` | New styles for hero demo animation, architecture comparison, code comparison panels, chat demo |
| `route/demo.php` | Add AI chat SSE endpoint (or new `route/chat.php`) |
| `template/_nav.php` | No changes needed |
| `template/_master.php` | No changes needed |

## Files to Create

| File | Purpose |
|------|---------|
| `route/chat.php` | AI chat SSE streaming endpoint with thread support |
| `public/js/chat.js` | Chat UI client-side logic (SSE consumption, thread management) |

## Non-goals

- No changes to framework source (`src/`)
- No changes to other documentation pages
- No new CSS framework or build tooling — stays as single CSS file
- No external JS dependencies — vanilla JS only

## Dependencies

- Raw `curl_multi` or OpenSwoole HTTP client to Claude API for the live chat demo (no extra composer dependency)
- `ANTHROPIC_API_KEY` environment variable for the chat endpoint
- Graceful fallback if no API key is configured (show static demo instead)
