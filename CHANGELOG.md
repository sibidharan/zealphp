# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
