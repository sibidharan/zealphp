# ZealPHP Roadmap

This roadmap outlines planned development. Items marked **[R&D]** represent research objectives suitable for grant-funded work.

**Versioning policy.** ZealPHP is in alpha. The **v0.2.x line is the security, hardening, and migration series** — all production-trust fixes (long-running PHP gotchas, isolation contracts, connection lifecycle, audit responses) ship here. New runtime features (observability, federation primitives, etc.) target v0.3 and beyond. The boundary is intentional: a user on `^0.2.x` should see no breaking changes, only safer-and-more-honest releases of the same surface.

---

## v0.2 — Security & Migration (current series)

### Already shipped

Driven by public technical review across r/PHP + #phpc Discord. Full traceability in [CRITIC.md](CRITIC.md).

- [x] **v0.2.4** — `max_request=100000` worker-recycling default; scaffold ships `App::superglobals(false)` (coroutine mode default for new projects)
- [x] **v0.2.5** — CRLF/NUL injection guards on `header()`, `Response::header()`, `redirect()`, `setcookie()`, `setrawcookie()` (response splitting)
- [x] **v0.2.6** — `G` renamed to `RequestContext` (class_alias preserves backward compat); response state moved from `RequestContext` onto `Response`; Apache shim state moved to `ZealPHP\Legacy\ApacheContext`; `#[AllowDynamicProperties]` removed; return-by-reference autovivification fixed; dead `prefork_request_handler()` deleted; redundant isset() simplified
- [x] **v0.2.7** — `setrawcookie` filter relaxed to match PHP native behavior (rejects only `\r\n\0` in raw value, not whitespace/comma/semicolon)
- [x] **v0.2.8** — Migrated 97 internal `G::` call sites to `RequestContext::` for PHPStan static-analysis compatibility (class_alias not visible at static-analysis time)
- [x] **v0.2.10** — Discipline-contract sprint: `RequestContext::once($key, $fn)` request-scoped memoization helper; `[recycle] worker N exited after K requests, peak RSS X MB` access-log line; `IniIsolationMiddleware` (opt-in via `ZEALPHP_INI_ISOLATE=1`); handler-stack reset in superglobals mode `SessionManager`; "What survives a request" docs + coroutine safety matrix + Store consistency semantics; production OPcache tuning; CRITIC.md retrospective
- [x] **v0.2.11** — Open-redirect bypass fix (leading whitespace + `javascript:` scheme escaped the v0.2.5 regex anchor; backslash protocol confusion now blocked too); 17-test `RequestContextInvariantsTest` pinning v0.2.6 architectural contracts; comprehensive website docs cleanup (env var table, migration page, sessions, middleware, README)
- [x] **v0.2.12** — Session-file corruption worker-crash fix. Three sites in `src/Session/utils.php` assigned `unserialize()` output directly to typed `RequestContext::$session`; empty/corrupted/non-array payloads triggered `TypeError` and abnormal worker exit (DoS for affected session IDs). Defensive read+decode at all three sites; `zeal_session_decode()` returns `bool` matching PHP native; 11 new regression tests

### Planned for the v0.2.x line (security + migration)

In priority order — biggest production-trust gaps first.

- [ ] **`ZealPHP\Pool\PDOPool` + `RedisPool`** with reset-on-checkout semantics. The #1 production-trust gap remaining: pooled DB / Redis connections carry session state (open transactions, `SET SESSION sql_mode`, temp tables, MULTI/SUBSCRIBE state) and can poison request N+47 when the pool wraps. Configurable reset SQL per driver (`ROLLBACK`, restore `sql_mode`, `DEALLOCATE PREPARE ALL` for MySQL; equivalent for Redis). Integration with `App::onWorkerStart` for warmup. **1-2 day design pass.**
- [ ] **Configurable middleware groups** — route-scoped middleware stacks (e.g., auth only on `/api/*`), so users don't have to write conditional logic inside global middleware
- [ ] **Redis session driver** — coroutine-friendly session storage via OpenSwoole Redis client, for multi-server deployments where file-backed sessions don't work
- [ ] **Request/response logging middleware** — structured access logs with timing, status code distribution, slow-request flagging
- [ ] **Improved error pages** — development-mode stack traces with source context; production-mode minimal pages
- [ ] **Integration-test isolation** — rate-limiter Store table reset in `setUp`, DB fixture isolation per-class, retry tolerance on rapid sequential requests. Currently 1–3 tests rotate as flaky per run; not a release blocker but worth a v0.2.x hardening pass before v0.3
- [ ] **PHP 8.4 CI flake fix** — `test_chat_consecutive_requests_work` is environmental (Xdebug + curl timeout). Drop coverage on 8.4 in CI, keep coverage on 8.3 (which is already the only Codecov uploader). ~5-line CI change
- [ ] **[R&D]** Legacy PHP migration analyzer — static analysis tool to assess existing PHP app compatibility with coroutine mode (catches `static $cache` patterns, ini_set() per-request, etc. that need the discipline contract)

### Apache + nginx parity — remaining gaps after the v0.2.21 push

The Apache / nginx parity middleware cluster and the v0.2.20 server-level-configurability cluster both shipped in v0.2.21. What's left here is the genuinely-deferred subset.

- [ ] **Directory autoindex (§11 of the v0.2.20 plan)** — opt-in `App::$autoindex` + `App::autoindex()` fluent method + `App::renderAutoindex()` + `template/_autoindex.php`. Apache `Options +Indexes` / nginx `autoindex on`. Off by default (safer). Full Apache mod_autoindex customisation surface (icons, alts, descriptions) is intentionally NOT replicated — users override the template instead.
- [ ] **`ProxyMiddleware`** — documented template using OpenSwoole's HTTP client to forward to an upstream. nginx `proxy_pass`. Deferred because the recommended pattern for production remains "use Caddy / Traefik in front" — this would ship as a reference implementation for users who can't run a separate front-proxy.
- [ ] **`BodyRewriteMiddleware` advanced cases** — multi-line patterns and streaming-response support (`mod_substitute` flag set). The v0.2.21 implementation covers single-line buffered responses, which handles 90% of real-world `Substitute` uses; multi-line / streaming variants land when demand surfaces.
- [ ] **HTTP/3 (QUIC)** — waiting on OpenSwoole upstream. Workaround today: put Caddy or another HTTP/3-capable proxy in front; ZealPHP serves over HTTP/1.1 or HTTP/2 internally.

**Explicitly NOT planned** (marked ❌ in the legacy-apps coverage matrix):

- Server-Side Includes (`Options +Includes`, `XBitHack`, `.shtml` parsing) — modern apps use templates; ZealPHP has `App::render()`
- mod_speling fuzzy URL matching — niche, security-questionable
- mod_imagemap (server-side `<map>` files) — dead ~1995
- mod_dav (WebDAV) — separate scope; use a dedicated WebDAV server
- CERN meta files (MetaDir/MetaFiles/MetaSuffix) — dead ~1996
- ISAPI directives — Windows IIS-only
- mod_lua hook directives — PSR-15 middleware is the native equivalent
- LDAP authentication built-in — document the PHP LDAP extension integration path
- HTTP Digest Auth — Basic + HTTPS is the modern recommendation

---

## v0.3 — Observability & Performance

Once the v0.2.x line has the production-trust gaps closed, v0.3 adds runtime-visibility and perf primitives.

- [ ] **[R&D]** Zero-copy streaming primitives — reduce memory overhead for AI token streaming and large SSE payloads
- [ ] **[R&D]** Coroutine isolation formal verification — prove cross-request data cannot leak between coroutines (extends the discipline contract from documentation to formal guarantee)
- [ ] **Metrics endpoint** — built-in `/metrics` with request counts, latency percentiles (p50/p95/p99), memory usage, worker recycle counter, coroutine count
- [ ] **Tracing hooks** — OpenTelemetry-compatible span creation for middleware and route handlers
- [ ] **In-process distributed locking** — `App::lock($key, $fn)` over `OpenSwoole\Atomic` for cross-worker mutex without external Redis

---

## v0.4 — Federation & Decentralization

- [ ] **[R&D]** Federation protocol primitives — WebSocket/SSE building blocks for ActivityPub and decentralized web protocols
- [ ] **[R&D]** Privacy-preserving session architecture — formally verified coroutine-isolated sessions with no shared mutable state
- [ ] **WebSocket rooms** — named broadcast groups with presence tracking
- [ ] **Binary WebSocket protocol helpers** — structured message packing/unpacking
- [ ] **CRDT primitives** — building blocks for collaborative state sync

---

## v1.0 — Production Ready

- [ ] **Stable API** — semantic versioning guarantee, no breaking changes without major version bump
- [ ] **Comprehensive documentation** — complete API reference, migration guides, deployment recipes
- [ ] **Independent security audit** — third-party review of coroutine isolation, session handling, uopz overrides, CGI bridge, connection pooling
- [ ] **Performance regression suite** — automated benchmarks in CI with leaderboard tracking
- [ ] **Multi-database support** — first-class connection management for MySQL, PostgreSQL, SQLite via coroutine clients with pool reset semantics

---

## How releases happen

Public technical review drives the v0.2.x cadence. When a credible critic surfaces a real bug, architectural smell, or trust gap, it gets:

1. Verified against the code (or pushed back on if surface-read)
2. Triaged into CRITIC.md
3. Fixed in the next patch release with a CHANGELOG entry and regression test
4. The version-by-version trace stays public so the next critic can see what shipped from prior reviews

If you're reviewing the framework and find something, please open an issue or DM. The hardening series is genuinely driven by audit input — every v0.2.x release since v0.2.4 was triggered by a community comment.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to get involved. Items without the **[R&D]** tag are great places for community contributions. The connection-pool work specifically would welcome a contributor with PDO + Swoole experience.
