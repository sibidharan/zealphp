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

- **Superglobal-less default mode** – `App::superglobals(false)` is the shipped default for new projects: per-coroutine `RequestContext` (`$g`) replaces process-wide `$_GET` / `$_POST` / `$_SESSION` arrays, making the runtime safe to handle concurrent coroutines without cross-request leaks. See [runtime-architecture.md](runtime-architecture.md) for the lifecycle setters that compose around it (`processIsolation`, `enableCoroutine`, `hookAll`, `cgiMode`).

## Roadmap Highlights

The following initiatives are being researched or actively developed:

1. **Configurable middleware groups** – Allow route-scoped middleware stacks for targeted policies (e.g., apply authentication only to `/api/*` automatically).
2. **Improved session drivers** – Introduce coroutine-friendly session storage (Redis, custom in-memory pools) to complement the current file-based handler.
3. **Task orchestration helpers** – Higher-level APIs for scheduling recurring jobs and collecting task results.
4. **Observability toolkit** – First-class metrics, tracing hooks, and structured request logs to integrate with popular observability platforms.
5. **Developer tooling** – Command-line installer, project generator, and environment scaffolding to simplify onboarding.

Contributions aligned with the roadmap are encouraged. Open issues in the repository or submit a proposal describing the problem space, design sketch, and PSR implications.

## Contribution Guidelines

- Write tests or executable examples where feasible (`examples/` is deliberately verbose to double as documentation).
- Avoid breaking backward compatibility without a clear migration path. When necessary, document deprecations in `CHANGELOG.md`.
- Keep pull requests focused. Pair documentation updates with code changes.
- Respect the runtime design constraints described in [runtime-architecture.md](runtime-architecture.md); superglobal toggles and coroutine semantics are central to the framework’s identity.
