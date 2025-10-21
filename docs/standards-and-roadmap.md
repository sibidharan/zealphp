# Standards and Roadmap

ZealPHP positions itself as a modern PHP framework that blends the productivity of classic PHP with the scalability of OpenSwoole. This document captures the coding standards the project adheres to, interoperability guarantees, and the forward-looking roadmap that guides ongoing development.

## Coding Standards

- **Autoloading** – PSR-4 via Composer. Classes are namespaced under `ZealPHP\*` with directory structures that mirror namespaces (`src/App.php`, `src/Session/SessionManager.php`, etc.).
- **Code Style** – Follow PSR-12 for PHP files outside legacy examples. Use short array syntax, strict type declarations where possible, and meaningful docblocks for public APIs.
- **Templates** – Stick to native PHP templates with short open tags (`<?`). Avoid introducing third-party template engines unless the feature is isolated and optional.
- **Logging** – Use `ZealPHP\elog()` for structured logging. Prefix messages with context (e.g., `[auth] user login failed`) and choose severity levels (`info`, `warn`, `error`, `task`).
- **Error Handling** – Throw typed exceptions within the framework, catch them at the edges, and convert them into PSR responses or JSON payloads via `$this->die()`.

## PSR Interoperability

ZealPHP integrates with the following PSR specifications:

- **PSR-3 (Logging)** – While a full PSR-3 logger is not yet bundled, the logging utilities are designed to be wrapped by Monolog or similar adapters.
- **PSR-4 (Autoloading)** – Implementation via Composer; required for IDE autocompletion and package interoperability.
- **PSR-7 (HTTP Messages)** – `OpenSwoole\Core\Psr\ServerRequest` and `OpenSwoole\Core\Psr\Response` underlie ZealPHP’s request/response lifecycle. Handlers can return any PSR-7-compatible response.
- **PSR-15 (HTTP Server Request Handlers)** – Middleware pipeline is built upon PSR-15, allowing third-party middleware to slot in without modification.

Future compliance targets:

- **PSR-6/16 (Caching)** – Provide cache abstractions backed by Redis or memory stores suitable for coroutine environments.
- **PSR-18 (HTTP Client)** – Offer optional adapters that leverage OpenSwoole coroutine clients.

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
