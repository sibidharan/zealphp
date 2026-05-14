# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- PHPStan static analysis at level 5 with `phpstan.neon` config.
- `CODE_OF_CONDUCT.md` (Contributor Covenant v2.1).
- `SUPPORT.md` documenting support channels.
- `.github/FUNDING.yml` for GitHub Sponsors.
- YAML-format issue templates with structured fields.
- Examples directory: `hello-world`, `websocket-chat`, `streaming-sse`.
- `ext-openswoole` and `ext-uopz` declared as explicit Composer requirements.

### Changed
- Composer PHP constraint widened from `~8.3.0` to `^8.3` (now supports PHP 8.4, 8.5).
- Composer `openswoole/core` constraint widened from `~22.1.5` to `^22.1.5`.
- CI workflow split into parallel jobs: validate, static-analysis, phpunit.
- Session cookies now default to `httponly: true` and auto-detect HTTPS for `secure` flag.
- Session ID regeneration now uses `random_bytes(32)` instead of `uniqid()`.
- Session directory permissions tightened from `0777` to `0700`.

### Fixed
- Added `allowed_classes => false` to all `unserialize()` calls in session and cache code (prevents object injection).
- ZealAPI now validates module/request path components and uses `realpath()` containment (prevents path traversal).
- `Response::redirect()` now blocks `javascript:`, `data:`, `vbscript:` schemes and warns on cross-origin redirects.
- CGI worker no longer passes the entire server array to child processes — uses a prefix whitelist.

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
