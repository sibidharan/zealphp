# Standards and Roadmap

ZealPHP positions itself as a modern PHP framework that blends the productivity of classic PHP with the scalability of OpenSwoole. This document captures the coding standards the project adheres to, interoperability guarantees, and the forward-looking roadmap that guides ongoing development.

## Coding Standards

### Style and Formatting
- **PSR-2** (https://www.php-fig.org/psr/psr-2/) — the enforced coding standard for all PHP files. Short array syntax, strict type declarations in new `src/` classes, meaningful docblocks for public APIs.
- **Autoloading** – PSR-4 via Composer. Classes are namespaced under `ZealPHP\*` with directory structures that mirror namespaces (`src/App.php`, `src/Session/SessionManager.php`, etc.).
- **Templates** – Stick to native PHP templates with short open tags (`<?`). No third-party template engines.
- **Logging** – Use `ZealPHP\elog()` for structured logging with context prefixes and severity levels.
- **Error Handling** – Throw typed exceptions within the framework, catch them at the edges, and convert them into PSR responses or JSON payloads via `$this->die()`.

### Separation of Concerns
- **No inline JavaScript in templates** — all JS must live in `public/js/`. Templates produce HTML; behavior is loaded via `<script src>`.
- **No inline CSS in templates** — no `style=` attributes, no `<style>` blocks. All styles go in `public/css/`. Use CSS classes.
- **No PHP function definitions in templates** — templates are view-only. Extract logic to `src/` classes.
- **No PHP function definitions in API files** — API handler files define one closure and delegate to `src/` service classes.
- **`function_exists()` guard = wrong placement** — the function belongs in a class autoloaded via PSR-4.
- **Routes are thin** — 1–5 lines calling `src/` classes. Business logic never lives in `route/` files.
- **Prefer `api/` (ZealAPI) over `route/`** for REST endpoints. Use `route/` only for path-param routes, WebSocket, Store tables.

### OOP Architecture
- Business logic in `src/` as proper classes with constructors, autoloaded via Composer PSR-4.
- Reference: `src/Learn/` namespace — `Auth.php`, `Chat.php`, `Notes.php`, `DB.php`, `WS.php`.

### htmx Convention
The site uses htmx globally with `hx-boost="true"` on `<body>` for automatic AJAX navigation with progressive enhancement. Prefer htmx attributes (`hx-get`, `hx-post`, `hx-target`, `hx-swap`) over custom `fetch()`. Use WebSocket or SSE for server-push.

### Known Tech Debt
Legacy demo pages contain ~600 inline `style=` attributes and 10+ inline `<script>` blocks (worst: `home.php`, `performance.php`, `why-zealphp.php`). When modifying these files, extract inline JS/CSS to external files rather than adding more.

## PSR Interoperability

ZealPHP implements PSR-3, PSR-4, PSR-7, PSR-11, PSR-15, PSR-16, PSR-17, and PSR-18 — see [STANDARDS.md](../STANDARDS.md) for the complete interoperability table (proving tests, conformance level, implementing class per PSR).

## Documentation Expectations

Treat Markdown files in `docs/` as canonical documentation. When proposing changes, update relevant documents in tandem with code, including diagrams or sequence descriptions where helpful. Keep language vendor-neutral and focus on practical guidance for engineering teams.

## Release Management

- Tag library (`sibidharan/zealphp`) and starter project (`sibidharan/zealphp-project`) in lockstep.
- Ensure `composer install` passes without warnings *before* publishing a release.
- After tagging, trigger Packagist webhooks so the new version is indexed promptly.

## Shipped Highlights

- **Superglobal-less default mode** – `App::superglobals(false)` is the shipped default for new projects: per-coroutine `RequestContext` (`$g`) replaces process-wide `$_GET` / `$_POST` / `$_SESSION` arrays, making the runtime safe to handle concurrent coroutines without cross-request leaks.

- **One-call lifecycle presets — `App::mode()` and `App::isolation()`** – The current public lifecycle surface folds the underlying four knobs (`processIsolation`, `enableCoroutine`, `hookAll`, `cgiMode`) into two orthogonal axes. `App::isolation(Isolation|string)` picks the request-isolation strategy (one of `App::ISOLATION_COROUTINE`, `ISOLATION_CGI_POOL`, `ISOLATION_CGI_PROC`, `ISOLATION_CGI_FCGI`, `ISOLATION_NONE`). `App::mode(string)` sets both axes in one call via named presets:

  | Preset constant | String | Use for |
  |---|---|---|
  | `App::MODE_COROUTINE` | `'coroutine'` | Modern ZealPHP apps — the scaffold default |
  | `App::MODE_LEGACY_CGI` | `'legacy-cgi'` | Unmodified WordPress/Drupal (`require_once` apps) |
  | `App::MODE_COROUTINE_LEGACY` | `'coroutine-legacy'` | Legacy request-style PHP run **concurrently** — Composer apps (Symfony/Laravel/Slim) and WordPress-style code with per-coroutine isolation. **Requires ext-zealphp.** |
  | `App::MODE_MIXED` | `'mixed'` | Real `$_SESSION`, sequential workers, no CGI fork cost (Symfony bridge) |

  The four fine-grained setters remain available as low-level overrides underneath these presets. See [runtime-architecture.md](runtime-architecture.md) for details.

- **Coroutine-legacy compatibility runtime** – `App::mode(App::MODE_COROUTINE_LEGACY)` turns ZealPHP into a compatibility runtime: traditional request-style PHP (the PHP-FPM "fresh state per request" mental model) runs under OpenSwoole coroutine concurrency with per-coroutine isolation of the 7 superglobals, `header()`/`setcookie()` response state, `$GLOBALS`/`global $x` (including object-valued), function-local `static $x`, and `require_once`/`include_once` re-execution. `define()` isolation is a separate opt-in via `App::defineIsolation(true)` — it is NOT part of the preset. Requires **ext-zealphp**. See [runtime-architecture.md](runtime-architecture.md) and the `/coroutines#lifecycle-modes` reference.

- **Coroutine-friendly session drivers** – Multiple session handler implementations ship in `src/Session/Handler/`: `FileSessionHandler`, `TableSessionHandler` (CAS + 3-way merge for concurrent-safe writes), `RedisSessionHandler` (WATCH/MULTI optimistic locking), `StoreSessionHandler` (backend-agnostic: Table/Redis/Tiered), and `CoroutineMemorySessionHandler`. Register via `StoreSessionHandler::register($ttl)` or `TableSessionHandler::register()` before `App::run()`. For `RedisSessionHandler`, use `App::sessionHandler('redis')` or `session_set_save_handler(new RedisSessionHandler(), true)` — it has no static `register()` helper.

- **Route-scoped middleware** – `ScopedMiddleware` wraps any other middleware so it runs only when the request path matches a literal prefix, regex, or files predicate — Apache `<Location>` / `<LocationMatch>` / `<Files>` container parity.

## Roadmap Highlights

The following initiatives are being researched or actively developed:

1. **Task orchestration helpers** – Higher-level APIs for scheduling recurring jobs and collecting task results.
2. **Observability toolkit** – First-class metrics, tracing hooks, and structured request logs to integrate with popular observability platforms.
3. **Developer tooling** – Command-line installer, project generator, and environment scaffolding to simplify onboarding.

Contributions aligned with the roadmap are encouraged. Open issues in the repository or submit a proposal describing the problem space, design sketch, and PSR implications.

## Contribution Guidelines

- Write tests or executable examples where feasible (`examples/` is deliberately verbose to double as documentation).
- Avoid breaking backward compatibility without a clear migration path. When necessary, document deprecations in `CHANGELOG.md`.
- Keep pull requests focused. Pair documentation updates with code changes.
- Respect the runtime design constraints described in [runtime-architecture.md](runtime-architecture.md); superglobal toggles and coroutine semantics are central to the framework’s identity.
