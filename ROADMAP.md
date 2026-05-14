# ZealPHP Roadmap

This roadmap outlines planned development. Items marked **[R&D]** represent research objectives suitable for grant-funded work.

## v0.2 — Migration & Middleware

- [ ] **[R&D]** Legacy PHP migration analyzer — static analysis tool to assess existing PHP app compatibility with coroutine mode
- [ ] Configurable middleware groups — route-scoped middleware stacks (e.g., auth only on `/api/*`)
- [ ] Redis session driver — coroutine-friendly session storage via OpenSwoole Redis client
- [ ] Request/response logging middleware — structured access logs with timing
- [ ] Improved error pages — development-mode stack traces with source context

## v0.3 — Observability & Performance

- [ ] **[R&D]** Zero-copy streaming primitives — reduce memory overhead for AI token streaming and large SSE payloads
- [ ] **[R&D]** Coroutine isolation formal verification — prove cross-request data cannot leak between coroutines
- [ ] Metrics endpoint — built-in `/metrics` with request counts, latency percentiles, memory usage
- [ ] Tracing hooks — OpenTelemetry-compatible span creation for middleware and route handlers
- [ ] Connection pooling — managed database and HTTP client connection pools per worker

## v0.4 — Federation & Decentralization

- [ ] **[R&D]** Federation protocol primitives — WebSocket/SSE building blocks for ActivityPub and decentralized web protocols
- [ ] **[R&D]** Privacy-preserving session architecture — formally verified coroutine-isolated sessions with no shared mutable state
- [ ] WebSocket rooms — named broadcast groups with presence tracking
- [ ] Binary WebSocket protocol helpers — structured message packing/unpacking

## v1.0 — Production Ready

- [ ] Stable API — semantic versioning guarantee, no breaking changes without major version bump
- [ ] Comprehensive documentation — complete API reference, migration guides, deployment recipes
- [ ] Security audit — independent review of coroutine isolation, session handling, and uopz overrides
- [ ] Performance regression suite — automated benchmarks in CI
- [ ] Multi-database support — connection management for MySQL, PostgreSQL, SQLite via coroutine clients

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to get involved. Items without the [R&D] tag are great places for community contributions.
