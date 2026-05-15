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

ZealPHP integrates with the following PSR specifications:

- **PSR-3 (Logging)** – `ZealPHP\Log\Logger` extends `Psr\Log\AbstractLogger` with level filtering, message interpolation, and exception context. Routes output through the existing `log_write()` infrastructure. Accepts any `$minLevel` to suppress lower-severity messages.
- **PSR-4 (Autoloading)** – Implementation via Composer; required for IDE autocompletion and package interoperability.
- **PSR-7 (HTTP Messages)** – `OpenSwoole\Core\Psr\ServerRequest` and `OpenSwoole\Core\Psr\Response` underlie ZealPHP’s request/response lifecycle. Handlers can return any PSR-7-compatible response.
- **PSR-15 (HTTP Server Request Handlers)** – Middleware pipeline is built upon PSR-15, allowing third-party middleware to slot in without modification.
- **PSR-16 (Simple Cache)** – `ZealPHP\Cache\SimpleCacheAdapter` wraps the static `Cache` class with full `CacheInterface` compliance, including `getMultiple`/`setMultiple`/`deleteMultiple`, `DateInterval` TTL support, and key validation.
- **PSR-17 (HTTP Factories)** – Six factory classes in `ZealPHP\HTTP\Factory\` (`RequestFactory`, `ResponseFactory`, `StreamFactory`, `UriFactory`, `ServerRequestFactory`, `UploadedFileFactory`) wrap OpenSwoole’s PSR-7 implementations.
- **PSR-18 (HTTP Client)** – `ZealPHP\HTTP\Client` implements `ClientInterface` using curl. Automatically coroutine-aware via OpenSwoole’s runtime hooks. Configurable timeout, SSL verification, and redirect following.

## Documentation Expectations

Treat Markdown files in `docs/` as canonical documentation. When proposing changes, update relevant documents in tandem with code, including diagrams or sequence descriptions where helpful. Keep language vendor-neutral and focus on practical guidance for engineering teams.

## Release Management

- Tag library (`sibidharan/zealphp`) and starter project (`sibidharan/zealphp-project`) in lockstep.
- Ensure `composer install` passes without warnings *before* publishing a release.
- After tagging, trigger Packagist webhooks so the new version is indexed promptly.

## Roadmap Highlights

The following initiatives are being researched or actively developed:

1. **Superglobal-less default mode** – Move towards coroutine-first deployments by default, potentially replacing PHP superglobals with ZealPHP-native abstractions. Requires exhaustive testing of session and request isolation.
2. **Configurable middleware groups** – Allow route-scoped middleware stacks for targeted policies (e.g., apply authentication only to `/api/*` automatically).
3. **Improved session drivers** – Introduce coroutine-friendly session storage (Redis, custom in-memory pools) to complement the current file-based handler.
4. **Task orchestration helpers** – Higher-level APIs for scheduling recurring jobs and collecting task results.
5. **Observability toolkit** – First-class metrics, tracing hooks, and structured request logs to integrate with popular observability platforms.
6. **Developer tooling** – Command-line installer, project generator, and environment scaffolding to simplify onboarding.

Contributions aligned with the roadmap are encouraged. Open issues in the repository or submit a proposal describing the problem space, design sketch, and PSR implications.

## Contribution Guidelines

- Write tests or executable examples where feasible (`examples/` is deliberately verbose to double as documentation).
- Avoid breaking backward compatibility without a clear migration path. When necessary, document deprecations in `CHANGELOG.md`.
- Keep pull requests focused. Pair documentation updates with code changes.
- Respect the runtime design constraints described in [runtime-architecture.md](runtime-architecture.md); superglobal toggles and coroutine semantics are central to the framework’s identity.
