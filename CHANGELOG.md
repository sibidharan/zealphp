# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.8] - 2026-05-15

### Fixed
- **PHPStan static-analysis CI failure** — after the v0.2.6 rename of `G` → `RequestContext` (with `class_alias` for runtime backward compat), PHPStan reported 90 "Call to static method instance() on an unknown class ZealPHP\G" errors because static analysis doesn't follow runtime `class_alias`. Framework-internal references are now migrated from `G::` to `RequestContext::` across `src/` (97 call sites across 18 files). The `class_alias(RequestContext::class, 'ZealPHP\\G')` registration remains untouched — user code referencing `\ZealPHP\G` or `use ZealPHP\G;` continues to work exactly as before. CI is green again at level 1 with 0 errors.

## [0.2.7] - 2026-05-15

### Fixed
- **`setrawcookie()` was over-strict** — v0.2.5's CRLF/NUL injection guard incorrectly rejected `,`, `;`, ` `, `\t`, `\013`, `\014` in raw cookie values. PHP native `setrawcookie` only rejects `\r\n\0` in the value (the response-splitting vector); the rest are legal cookie-octets that callers explicitly use the "raw" variant to pass through unchanged. The filter is now relaxed to match PHP's actual behavior. Caught by the existing `tests/Integration/ApacheParityTest::testSetRawCookieDoesNotUrlEncode` regression test (which was failing under v0.2.5/v0.2.6).

## [0.2.6] - 2026-05-15

### Changed
- **`G` renamed to `RequestContext`** — `\ZealPHP\RequestContext` is now the canonical name for what was previously `\ZealPHP\G`. The old name `\ZealPHP\G` remains available via `class_alias` for backward compatibility; existing code that references `G::instance()` or types against `\ZealPHP\G` keeps working unchanged. Source-level rename addresses the long-standing critique that the single-letter name signaled nothing about purpose.
- **Response state moved off `G` onto `Response`.** `$g->response_headers_list`, `$g->response_cookies_list`, and `$g->response_rawcookies_list` no longer exist on `G`. They live on the Response object as `$response->headersList`, `$response->cookiesList`, and `$response->rawCookiesList`. Framework internals updated. **External code that read these properties directly must migrate to `$g->zealphp_response->headersList` etc.** — the uopz `header()` / `setcookie()` overrides and the `header_remove()` / `response_headers_list()` / `apache_response_headers()` helpers continue to work unchanged.
- **Legacy Apache shim state moved off `G` onto `ZealPHP\Legacy\ApacheContext`.** `$g->apache_env` and `$g->apache_notes` no longer exist on `G`. The `apache_setenv()` / `apache_getenv()` / `apache_note()` shim functions now lazy-allocate `$g->apacheContext` (a `ZealPHP\Legacy\ApacheContext` instance) and read/write its `env` and `notes` arrays. Only matters for legacy code running through the CGI bridge.

### Removed
- **`#[AllowDynamicProperties]` attribute on `RequestContext`** — the three previously-dynamic properties (`cache_expire`, `cache_limiter`, `session_module_name`) are now declared as typed properties. Undeclared writes in coroutine mode now throw `BadMethodCallException` (catches typos like `$g->zealphp_reqeust = ...` that previously silently created a dynamic property). Superglobals mode keeps the `$GLOBALS[$key]` bridge for legacy compatibility.
- **`prefork_request_handler()` deleted** — predecessor to the CGI bridge (`App::includeFile()` / `src/cgi_worker.php`), unused since the bridge landed. Zero callers in framework, scaffold, or any documented user code. The CGI bridge is now the sole "run unmodified legacy PHP in a child process" path.

### Fixed
- **Return-by-reference autovivification on coroutine-mode `__get`.** `&$g->nonexistent` used to create a dynamic property on first read; now returns a reference to a local null without mutating state. Bounded blast (per-coroutine context) but the behavior was a footgun.
- **`debug_backtrace()` removed from `RequestContext::instance()`.** Was firing on first-instance creation per worker in superglobals mode, emitting an `elog` line with the call site. Cosmetic dev tracing, not a hot path, but unnecessary in production.
- **Redundant `isset($g->session)` check in `CoSessionManager`.** `session` is a declared typed property with default `[]` — always set. The outer `isset` was always true; only the inner `isset($g->session['__start_time'])` carried information.

## [0.2.5] - 2026-05-15

### Security
- **HTTP response splitting via `header()` override (high severity).** The uopz `header()` override did not reject `\r\n` / `\0` in header values, breaking the protection PHP native `header()` has provided since 4.4.2. Application code using `header("X-Foo: " . $userInput)` with user input containing CRLF could smuggle additional response headers (including `Set-Cookie`), enabling session fixation and cache poisoning against affected apps. **All v0.2.x releases prior to 0.2.5 are affected. Upgrade is strongly recommended.**
  - Fix: CRLF/NUL injection guards added to `header()`, `Response::header()`, `Response::redirect()`, `setcookie()`, and `setrawcookie()`.
  - Validation: matches PHP native behavior — emits `E_USER_WARNING` and returns `false` (or throws `InvalidArgumentException` for `redirect()`).
  - Cookie name char-class rules now match PHP native `setcookie`: `=,; \t\r\n\013\014\0` rejected.
  - 9 new regression tests in `tests/Unit/SecurityTest.php` covering each entry point.

## [0.2.4] - 2026-05-15

### Added
- **`max_request=100000` default** — worker recycling now enabled out of the box, bounding memory growth from long-running PHP workers (static caches, closure captures, leaky extensions). After 100k requests a worker exits cleanly and is respawned with a fresh PHP arena. Override via `ZEALPHP_MAX_REQUEST` env var or `$app->run(['max_request' => N])`. Set `0` to disable.
- **`ZEALPHP_MAX_REQUEST` env var** — documented in both `docs/deployment.md` and `template/pages/deployment.php`.

### Changed
- **Scaffold (`sibidharan/zealphp-project`) defaults to coroutine mode** — `composer create-project` now ships `app.php` with `App::superglobals(false)` explicitly set. Aligns the scaffold's default with the documented "recommended for new projects" stance. Per-request state is isolated via `Coroutine::getContext()`, eliminating the worker-state-leak class of issues for greenfield apps. Framework default (`App::$superglobals = true`) is **unchanged** for backward compatibility with existing apps; flip to `App::superglobals(true)` only when migrating unmodified legacy code that needs `$_GET`/`$_POST`/`$_SESSION` access.

## [0.2.3] - 2026-05-15

### Added
- **`SessionStartMiddleware`** — new PSR-15 middleware that eagerly starts sessions for first-time visitors. Fixes session-dependent features (counters, flash messages) silently failing on first request.
- **Lesson 5: "React vs PHP"** — new lesson comparing React+Node stack vs ZealPHP+htmx with Mermaid diagrams, comparison table, and deep dives. Positions ZealPHP as frontend-agnostic.
- **Mermaid.js diagrams** — interactive architecture diagrams in lessons 1, 5, 9, 10, 11 with click-to-expand fullscreen viewer (pinch zoom, scroll pan, trackpad-friendly).
- **AI agent HTTP API architecture** — Python agent now calls ZealPHP's HTTP endpoints with session cookie auth instead of direct SQLite. Note mutations trigger WebSocket broadcasts for live cross-tab updates.
- **Notes API JSON content negotiation** — `Accept: application/json` returns JSON; default returns HTML for htmx. New routes: `GET /api/learn/notes/{id}`, `GET /api/learn/notes/search`.
- **Event log terminal** — always-visible dark terminal on AI Chat page showing SSE (blue) and WebSocket (purple) events in real time.
- **Note card animations** — green glow on create, green flash on update, fade-out on delete. WebSocket handler skips redundant list refresh when card already exists.
- **Concept check quizzes** — inline multiple-choice questions with letter circles (A, B, C) and htmx-powered verification.
- **Inline auth error feedback** — register/login forms show errors inline via htmx (wrong password, username taken, etc.) instead of raw JSON.
- **GitHub source links** — file references in lessons link to actual source on GitHub.

### Changed
- **14-lesson tutorial** (was 13) — new "React vs PHP" lesson inserted as L5, all subsequent renumbered.
- **Pedagogical redesign** — all lessons rewritten with problem-first framing, mental models, step-by-step building, key takeaways, and challenges.
- **Lesson reorder** — htmx moved from L7→L6, routing from L5→L12, WebSocket after AI Chat. Sessions split into two lessons (Sessions + User Accounts).
- **Sidebar restructured** — 4 groups (Hello World, Interactivity, Build the App, Under the Hood) replacing the old 3-group layout.
- **Notes user bar** — avatar circle with initial letter + username replacing plain "Logged in as" text.
- **Stream demo** — increased from 5 rows to 12 rows (1.8s) for more visible streaming effect.
- **Nav label** — "Start" renamed to "Getting Started" in top navigation.

### Fixed
- **learn.css not loading on hx-boost navigation** — CSS now loaded unconditionally in `_head.php`.
- **Register/login session conflicts** — removed redundant `session_start()` + `setcookie()` that conflicted with `SessionStartMiddleware`.
- **Agent `notes_changed` event never emitted** — tool_call item.id vs tool_call_output call_id mismatch fixed by storing tool names by both IDs.
- **DELETE endpoint returning `{"ok":true}`** — now returns empty for htmx, JSON only with `Accept: application/json`.
- **Chat double-scroll** — overrode zealphp.css `chat-messages` overflow that created a second scrollable area.
- **Subtitle `&mdash;` not rendering** — use literal em dash character since `htmlspecialchars()` double-escapes HTML entities.

## [0.2.2] - 2026-05-15

### Added
- **`/learn` tutorial section** — 13-lesson guided tutorial that builds a working Notes + AI Chat app. Covers routing, components, sessions, htmx, SQLite, SSE streaming, WebSocket, and async coroutines.
- **`src/Learn/` namespace** — 6 autoloaded classes (Auth, Chat, ChatHistory, DB, Notes, WS) demonstrating proper OOP architecture.
- **8 ZealAPI endpoint files** (`api/learn/`) — register, login, logout, notes, chat, chat_status, chat_history, page.
- **Python notes agent** (`examples/agents/notes_agent.py`) — OpenAI Agents SDK with 6 function tools, SQLite-backed, SSE-streamed through PHP.
- **htmx site-wide** — `hx-boost="true"` on `<body>` for instant navigation; htmx page swap for lesson sidebar.
- **WebSocket cross-tab sync** — `App::ws('/ws/learn')` with Store-backed fd→user_id mapping and broadcast helper.
- **Chat history persistence** — SQLite `chat_history` table with ZealAPI history endpoint using `App::renderToString`.
- **24 new tests** — 16 unit (auth, notes, chat history) + 8 integration (session persistence, CRUD, user isolation, SSE consecutive).
- **Coding standards** — PSR-2, separation of concerns, OOP rules codified in CLAUDE.md and docs.
- **Cache-busting asset URLs** — `?v=<git-describe>` on all local CSS/JS.

### Fixed
- **WebSocket session support** (`src/App.php`) — `onOpen` now populates `$g->session` from the upgrade request's PHPSESSID cookie.
- **ZealAPI SSE streaming** (`src/ZealAPI.php`) — skip `ob_get_clean()` + `new Response()` when handler already sent a streaming response (`$g->_streaming` check).
- **`$_SESSION` vs `$g->session`** — all learn code uses `$g->session` (coroutine-safe); documented the gotcha in CLAUDE.md.
- **Session write on first registration** — explicit `setcookie()` + `session_write_close()` for new sessions (CoSessionManager only auto-writes when the request already had a cookie).
- **Auth::currentUser() DB verification** — checks user still exists in SQLite (stale sessions after DB wipe no longer crash with FK violations).
- **Streaming HTML token rendering** — accumulate-and-re-render pattern for partial HTML tags from character-by-character model output.
- **highlight.js after htmx swap** — `htmx:afterSettle` instead of `htmx:afterSwap` (outerHTML replaces the target, old element is detached).

### Changed
- **htmx loaded globally** (was learn-only) — enables `hx-boost` site-wide.
- **`scroll-behavior: smooth` removed globally** — htmx boost makes navigation instant; smooth scroll caused jank on lesson swaps.
- **`scrollbar-gutter: stable`** on html — prevents layout shift when scrollbar appears/disappears.

## [0.2.1] - 2026-05-14

### Added
- **Apache + mod_php parity** — comprehensive PHP-FPM-equivalent behavior: uopz overrides for session/header/cookie semantics, `public/` file routing with `.htaccess`-style fallback, error handler stack isolation, content negotiation. Six new integration test suites lock it in: `ApacheParityTest`, `ContentNegotiationTest`, `ErrorHandlersIsolationTest`, `ErrorHandlingTest`, `FallbackTest`, `PublicRoutingTest`.
- **Dedicated `/migration` page** — 5-rung migration ladder (drop-in → LAMP-style → ZealAPI → framework routes → full coroutine mode), before/after stack collapse, dedicated framing of the migration story.
- **Dedicated `/performance` page** — full Ryzen 9 7900X benchmark detail, methodology, framework-efficiency comparison.
- **Dedicated `/responses` page** — return convention reference.
- **One-line install** — `bash <(curl /install.sh)` serves `setup.sh` directly from the framework, hardened for piped execution.
- **`SecurityTest` unit suite** + PHP 8.4 added to CI matrix.
- **★ N GitHub** live star count in the sitewide navbar (client-side fetch from `api.github.com`, silent fallback when rate-limited).
- **Electric hero wordmark** — bigger size, ⚡ glyph, one-time amber lightning sweep on load (pure CSS, respects `prefers-reduced-motion`).

### Changed
- **UX labels** — "Templates" → "Components", "ZealAPI" → "REST API" in nav and feature cards. URLs `/templates` and `/api` unchanged; class `ZealAPI` still referenced in body copy where it's the actual class name.
- **Nav structure** — REST API and Legacy Apps promoted to the top row; small vertical padding so the two-row nav breathes.
- **AI Config Converter** — mode-A delegation, framework detection, broader rewrite coverage (htaccess/nginx → `app.php`).
- **`/routing` on-ramp claim** — name the superglobals-mode trade-off honestly instead of asserting "no rewrite needed."
- **`/why-zealphp`** — clarified OpenSwoole 26 + Fibers compatibility (internal `zend_fiber` context backend ≠ AMPHP/Revolt library portability).
- **Homepage** — 11-badge block removed (duplicated the README), live config converter pulled off the homepage, narrative bridge added between code demo and benchmark numbers.
- **Alpha banner** — solid amber background with dark text, non-dismissable, DeepWiki CTA inline; sets honest "v0.2.x = alpha" expectations sitewide.
- **README "Why" section** — leads with the mission, not the problem.
- **Benchmark numbers updated** — fresh Ryzen 9 7900X isolated runs (117k req/s text, 106k JSON, 50k template, 0 failures at c=200 / `-k` / 4 workers) replace v0.2.0's mixed container+Ryzen numbers.

### Fixed
- **ZealAPI infinite loop on undefined method** — calling `$this->X()` on a non-existent method used to recurse on `__call` until stack overflow. Now returns 404 with a structured error and a `did_you_mean` hint computed via levenshtein.
- **ZealAPI route order, 308 redirects, CLI stop, pid-file handling.**
- **`php app.php restart`** — now prints `Restarted (pid X, port Y)` instead of finishing silently.
- **Buttons on `.section-dark` backgrounds** — `.btn-primary` was invisible because the section-dark anchor recolor was overriding the button text color. Fixed by scoping the recolor to `a:not(.btn)`.
- **`/performance` page** was unreadable on the default light theme.
- **Alpha banner** color combo (solid amber bg + dark text) for readability.
- **Code-label readability** — killed all-caps, darkened, switched to mono.
- **PHPStan baseline cleared** — real bugs fixed, stub mismatches suppressed cleanly.
- **AI streaming hero card** — gap between the card and the bench-method bar (was visually touching).

### Documentation
- **PERF.md reproduction recipes** — three documented recipes + variance reading guide.
- **Deployment, WebSocket, Streaming guides** added; macOS install path included.
- **HN-launch de-hype pass** — neutral copy, methodology disclosure, alpha banner sitewide.
- **ZealAPI error responses + live `undefined_method` demo** documented on `/api`.

## [0.2.0] - 2026-05-14

### Added
- **HTTP Range requests (RFC 7233)** — `RangeMiddleware` with single/multi-range support and `416 Range Not Satisfiable`; `If-Range` ETag validation.
- **`$response->sendFile()`** — zero-copy file serving with Range support.
- **PSR-3 Logger** implementation (`ZealPHP\Logger`) with `TestableLogger` helper.
- **PSR-16 SimpleCache adapter** (`SimpleCacheAdapter`) over the tiered `Cache`.
- **PSR-17 HTTP factories** — Request, Response, Stream, Uri, ServerRequest, UploadedFile.
- **PSR-18 HTTP Client** (`ZealPHP\HTTP\Client`).
- **Tiered `Cache`** — memory tier (OpenSwoole `Table`) + file-tier spill; `Cache::stats()` for cross-worker hit/miss/spill counters.
- **`App::renderStream()`** — streaming templates with reflection-based param injection; `yield from` supported in public files and API handlers.
- **AI chat SSE demo** — `/ai/chat` endpoint with thread support and OpenAI Agents SDK integration.
- **AI config converter** — nginx/Apache → ZealPHP translation with split-view SSE streaming.
- **CGI worker SSE streaming**, `setrawcookie`/`header_remove`/`headers_sent` capture, `--help` output.
- **WordPress showcase repo** (`sibidharan/zealphp-wordpress`).
- **PHPStan static analysis** at level 1 (`phpstan.neon` + baseline) wired into CI.
- **OSS community files** — `CODE_OF_CONDUCT.md` (Contributor Covenant v2.1), `SUPPORT.md`, `.github/FUNDING.yml`, YAML issue templates.
- **Examples directory** — `hello-world`, `websocket-chat`, `streaming-sse` (each with `composer.json` + README).
- **Docker quickstart** in README + `docker compose up app` path.
- **ASCII architecture diagram** in README.
- Explicit `ext-openswoole` and `ext-uopz` Composer requirements.

### Changed
- **Composer PHP constraint** widened from `~8.3.0` to `^8.3` (PHP 8.4 and 8.5 now supported).
- **`openswoole/core`** constraint widened to `^22.1.5`.
- **G class** declares hot properties to bypass `__get`/`__set` magic (perf).
- **Sessions** are lazy-initialized; reflection cached per route at registration.
- **ETag middleware** switched to `xxh3` hash.
- **ResponseMiddleware** skips `ob_get_clean()` for typed returns (int, array, object, Generator).
- **Session cookies** default to `httponly: true`, `samesite: Lax`, with HTTPS auto-detection for `secure` (override via `ZEALPHP_SESSION_SECURE`).
- **Session ID regeneration** uses `bin2hex(random_bytes(32))` (was `uniqid('', true)`).
- **Session directory permissions** tightened from `0777` to `0700`.
- **CI workflow** split into parallel jobs: validate, static-analysis, phpunit.
- Homepage redesigned around AI runtime positioning, architecture comparison, and live chat demo.

### Fixed
- `unserialize()` calls in session and cache paths now pass `allowed_classes => false`; CGI worker uses an exception-class whitelist (prevents PHP object injection).
- **ZealAPI** validates module/request path components against a strict regex and uses `realpath()` containment (prevents path traversal).
- **`Response::redirect()`** throws on `javascript:`, `data:`, `vbscript:` schemes and warns on cross-origin and protocol-relative redirects.
- **CGI worker** filters child-process environment to an `HTTP_/REQUEST_/SERVER_/...` prefix whitelist instead of leaking the entire request server array.
- Navbar active pill no longer touches the navbar bottom border (symmetric `.nav-row-features` padding).
- RenderStream test warnings eliminated.

### Security
- Session, cache, and CGI deserialization paths are now safe by default against PHP object injection.
- File-based API dispatcher (ZealAPI) is no longer reachable via path-traversal URLs.
- Session cookies are `HttpOnly` and (on HTTPS) `Secure` by default.
- Session IDs use a CSPRNG.

## [0.1.1] - 2026-05-13

### Added
- Detached ZealPHP runner with PID-file management, background mode, status checks, and log tailing.
- Dedicated getting-started page and refreshed the homepage quick-start flow for the starter project and framework repo.

### Changed
- Moved request, debug, access, and server logs off the terminal and into `/tmp/zealphp` by default.
- Tightened the benchmark path so the release can report leaner OpenSwoole numbers without demo middleware noise.

## [0.1.0] - 2025-10-14

### Added
- OpenSwoole powered `App` runtime with configurable superglobal reconstruction and PSR-15 middleware support.
- File-based `ZealAPI` router that dynamically loads handlers from `api/` with automatic request, response, and app injection.
- `prefork_request_handler`, `coprocess`, and `coproc` helpers for isolating blocking work in worker processes while preserving response metadata.
- IO stream wrapper, session utilities, and examples that enable streaming HTML responses, implicit routing, and reusable application scaffolding.

### Changed
- Wrapped PHP's session, header, and cookie APIs with `uopz` so ZealPHP can virtualize global state for each OpenSwoole request.
