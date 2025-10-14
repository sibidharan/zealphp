# Changelog

All notable changes to this project will be documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2025-10-14
- Introduced the OpenSwoole powered `App` runtime with configurable superglobal reconstruction and PSR-15 middleware support.
- Added the file-based `ZealAPI` router that dynamically loads handlers from `api/` with automatic request, response, and app injection.
- Implemented `prefork_request_handler`, `coprocess`, and `coproc` helpers for isolating blocking work in worker processes while preserving response metadata.
- Wrapped PHP's session, header, and cookie APIs with `uopz` so ZealPHP can virtualize global state for each OpenSwoole request.
- Added the IO stream wrapper, session utilities, and examples that enable streaming HTML responses, implicit routing, and reusable application scaffolding.

