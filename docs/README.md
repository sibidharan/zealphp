# ZealPHP Documentation

Welcome to the official documentation set for ZealPHP, a coroutine-aware PHP framework built on top of OpenSwoole. These guides expand on the repository README and the hosted reference site to give new contributors and product teams a structured, end-to-end view of how to work inside the framework.

## How to Use These Guides

- **Start with the essentials**: follow `getting-started.md` to set up the toolchain, enable required extensions, and boot the runtime.
- **Understand the shape of a project**: `directory-structure.md` and `runtime-architecture.md` describe what ships with the framework, how the request lifecycle works, and how state is managed safely.
- **Build product features**: use `routing.md`, `api-layer.md`, `templates-and-rendering.md`, and `middleware-and-authentication.md` to implement routes, API contracts, HTML streaming, and cross-cutting policies such as authentication.
- **Adopt concurrency patterns safely**: `tasks-and-concurrency.md` covers coroutines, prefork helpers, task workers, and the rules around superglobal emulation.
- **Align on standards and roadmap**: `standards-and-roadmap.md` documents the PSR interfaces we implement today, the conventions the core team expects, and upcoming changes that may affect compatibility.

Each topic is self-contained and written from the perspective of a senior engineer onboarding a new product team. Code snippets use the same primitives that ship in `examples/`, `api/`, and `route/` so you can copy them directly into production code.

## Quick Reference

- Minimum PHP: 8.3 with `openswoole` 22.1.x and `uopz`
- Entrypoint: `app.php` boots the HTTP server and wires middleware, implicit routes, and session managers
- Framework namespacing: all core symbols live under `ZealPHP\*` and follow PSR-4 autoloading via Composer
- Development ergonomics: use `App::superglobals(true)` for legacy style superglobals or disable them to opt in to coroutine-first mode

> **Tip:** Every Markdown file in this directory is intended to be published as part of the canonical ZealPHP documentation set. Keep phrasing factual and vendor-neutral so that it scales beyond the core repository.
