# Standards Conformance

ZealPHP aims to behave like a standards-compliant HTTP server, not approximately.
This document is the **honest, checkable catalogue** of the standards we follow:
what's implemented, the test that *proves* it, and the conformance level. Where
we deviate, it's listed — claims you can verify beat claims you can't.

**Conformance levels:**
- **Exhaustive** — validated against the full enumeration / authoritative registry (e.g. every IANA status code), not a sample.
- **Behavioral** — the spec's required behaviors are pinned by tests citing the clause; not an exhaustive conformance suite.
- **Documented** — implemented and documented; conformance suite is on the roadmap (see `docs/superpowers/specs/2026-05-21-standards-conformance-plan.md`).

How we keep it true: every test below runs in CI (PHPUnit). PHPStan level 10,
an 80% coverage floor (`codecov.yml`), mutation testing (`infection.json5`), and
a perf-regression smoke (`scripts/perf_smoke.sh`) guard against silent erosion.

---

## HTTP core (RFC 9110 / 9111 / 9112, IANA registries)

| Standard / clause | ZealPHP | Level | Proving test |
|---|---|---|---|
| **IANA HTTP Status Code Registry** (RFC 9110 §15) | `App::$REASON_PHRASES` + `emitStatus()` / `reasonPhrase()` | **Exhaustive** | `tests/Unit/IanaStatusConformanceTest.php` — every assigned 1xx–5xx code ↔ exact IANA description, both directions, + coercion boundaries |
| **Status coercion** — three-digit 100–599 (RFC 9110 §15) | `App::coerceStatusCode()` (out-of-range → 500, Apache parity) | **Exhaustive** | `IanaStatusConformanceTest::testStatusCoercionBoundaries`, `AppStaticHelpersTest` |
| **Message framing & request smuggling** (RFC 9112 §6–§7) | OpenSwoole http parser; ZealPHP owns the conformance claim | **Exhaustive (raw-socket)** | `tests/Integration/Http1FramingConformanceTest.php` — matrix below |
| **Reason-phrase emission** (RFC 9112 §3.1.2) | `emitStatus()` two-arg form for codes OpenSwoole's C list drops (425/451) | Behavioral | `AppStaticHelpersTest`, `tests/Integration/FileExecutionContractTest.php` |
| **Conditional requests** — ETag, `If-None-Match`, 304 (RFC 9110 §13, §8.8) | `ETagMiddleware` (weak `W/` ETag) | Behavioral | `tests/Unit/Middleware/ETagMiddlewareTest.php`, `tests/Integration/PublicRoutingTest.php` |
| **Range requests** — `Range`, 206, `Content-Range`, 416, `Accept-Ranges`, `If-Range` (RFC 9110 §14 / RFC 7233) | `RangeMiddleware` + `Response::sendFile()` | Behavioral | `tests/Unit/RangeMiddlewareTest.php`, `tests/Integration/HttpFeaturesTest.php` |
| **Methods** — HEAD≡GET sans body, OPTIONS `Allow`, TRACE off (RFC 9110 §9) | `ResponseMiddleware` | Behavioral | `tests/Integration/HttpFeaturesTest.php` |
| **Redirects** — 301/302/307/308 + `Location` (RFC 9110 §15.4, §10.2.2) | `Response::redirect()`, auto-302 on `Location` | Behavioral | `tests/Integration/HttpFeaturesTest.php` |
| **Content-Type / charset** (RFC 9110 §8.3, `default_mimetype`) | `CharsetMiddleware` + `App::$default_mimetype` | Behavioral | `tests/Unit/Middleware/CharsetMiddlewareTest.php` |
| **IMF-fixdate** — HTTP date: real day/month tokens, literal GMT, UTC round-trip (RFC 9110 §5.6.7) | `ExpiresMiddleware` (`Expires`) | **Exhaustive** | `tests/Unit/ImfDateConformanceTest.php` |

### HTTP/1.1 framing & request-smuggling results (RFC 9112 §6–§7)

The smuggling surface, probed with raw sockets (curl can't emit malformed
framing) and pinned by `Http1FramingConformanceTest`. The bar: an ambiguous
message is **never** processed as a normal 200 — it is rejected (4xx or the
connection is dropped).

| Vector | Result | Verdict |
|---|---|---|
| `Content-Length` + `Transfer-Encoding: chunked` (§6.1) | **4xx** (400/404 by build) | ✅ rejected, never smuggled |
| Duplicate `Content-Length` (§6.3) | connection closed | ✅ rejected |
| Bare-LF line endings (§2.2 requires CRLF) | connection closed | ✅ rejected |
| Invalid (non-hex) chunk size (§7.1) | connection closed | ✅ rejected |
| Oversized header block (~70 KB) | **4xx** (400/404 by build) | ✅ size-limited, never unbounded |
| Well-formed chunked body | dechunked → normal response | ✅ correct |
| HTTP/1.1 **missing `Host`** (§3.2) | **400** (framework guard) | ✅ rejected |
| HTTP/1.0 without `Host` | accepted | ✅ correct (1.0 exempt) |
| Header value with CR/LF/NUL (response splitting) | rejected (`header()`/`setcookie()` guard) | ✅ no splitting |
| Static-path traversal (encoded/double-encoded/backslash/null-byte) | 400/404, confined to docroot | ✅ no escape |
| Dotfiles (`.env`/`.git`/`.htaccess`/`.ssh`) | 403/404, never served | ✅ |
| Directory with no index | 403/404, no listing (autoindex off) | ✅ |

Proving tests: `Http1FramingConformanceTest`, `StaticServingConformanceTest`, `ResponseSplittingConformanceTest`, `PublicRoutingTest`.

**OpenSwoole-parser deviations (documented, not framework-fixable):**
- **`%00` in the URI** — OpenSwoole truncates the request target at the null byte *before* the framework sees it, then serves the truncated path (e.g. `/css/x.css%00.txt` → `/css/x.css`). It stays inside the document root and all access guards (dotfile, `.php`-block, traversal) still apply, so it is **not** a path-escape — but it returns 200 on the truncated path rather than Apache's 400. Upstream behavior.
- **Duplicate `Host`** — OpenSwoole merges repeated headers, so a duplicate `Host` can't be distinguished/rejected at the framework layer (missing `Host` *is* rejected, above).
- **`431`/`414`** — an oversized header block or over-long request target is rejected with a generic 4xx (400/404 by build), not the specific `431`/`414` codes.
- **`Expect: 100-continue`** and **keep-alive/slowloris timeouts** are governed by OpenSwoole server settings, not the framework; not yet covered by the conformance suite (roadmap).

**Known leniencies (documented, not smuggling vectors):**
- `Host : value` (whitespace before the colon, RFC 9112 §5.1 says reject) is accepted. Not a smuggling vector — the field still parses unambiguously.
- A very large *count* of header fields (≈2000) is accepted; the per-block *byte* limit (above) is the effective bound.

**HTTP/2** is currently `enable_http2 => true` (OpenSwoole/nghttp2-backed). Conformance is **not yet proven** — the roadmap is to run `h2spec` and publish the score (expected to match Apache mod_http2's nghttp2 baseline, the same handful of upstream-known failures). Until then HTTP/2 is *Documented*, not *Exhaustive*.

## Auth, cookies, URI

| Standard / clause | ZealPHP | Level | Proving test |
|---|---|---|---|
| **HTTP Basic Auth** — `WWW-Authenticate: Basic realm=`, 401 (RFC 7617) | `BasicAuthMiddleware` (htpasswd + callback) | Behavioral | `tests/Unit/Middleware/BasicAuthMiddlewareTest.php`, `BasicAuthFileTest.php` |
| **Cookies** — cookie-name token rule (§4.1.1), CRLF/NUL injection defense, attribute propagation, `SameSite`/`Secure`/`HttpOnly` (RFC 6265) | `setcookie()` override (7-arg + samesite) | **Exhaustive** | `tests/Unit/Rfc6265CookieConformanceTest.php` + `tests/Integration/HttpFeaturesTest.php` |
| **URI** — dot-segment / null-byte / encoded-traversal rejection (RFC 3986) | `ResponseMiddleware` pre-routing guard → 400 | **Exhaustive** | `tests/Unit/SecurityTest.php`, `tests/Unit/ResponseMiddlewarePipelineTest.php`, `tests/Integration/PublicRoutingTest.php::testStaticPathTraversalIsRejected` (live, incl. static path) |
| **Static file serving** — extension→MIME, `Last-Modified`, ETag, conditional 304, Range, `DirectoryIndex`, traversal-hardened | `Response::sendFile()` + implicit public routes + `App::$directory_index` | Behavioral | `tests/Integration/PublicRoutingTest.php` (sendFile ETag/304/Range, DirectoryIndex, traversal) |

## Content, compression, real-time

| Standard / clause | ZealPHP | Level | Proving test |
|---|---|---|---|
| **gzip / deflate** — `Content-Encoding` (RFC 1952 / 1951) | OpenSwoole `http_compression` + `CompressionMiddleware` | Behavioral | `tests/Unit/CompressionMiddlewareTest.php`, `tests/Integration/MiddlewareTest.php` |
| **CORS** (WHATWG Fetch) | `CorsMiddleware` (preflight 204 + `Access-Control-*`) | Behavioral | `tests/Unit/Middleware/CorsMiddlewareTest.php`, integration |
| **Server-Sent Events** (WHATWG HTML) | `Response::sse()` wire framing | Behavioral | `tests/Integration/StreamingTest.php` |
| **WebSocket** — opcodes, CLOSE 1001 (RFC 6455) | `App::ws()` | Behavioral | `tests/Integration/WebSocketTest.php` |

## Apache / nginx directive parity
See [`template/pages/http.php#parity`](template/pages/http.php) for the full matrix
(headers, expires, cache-control, mime, auth, ip-access, range, rate/conn limits,
redirects, setenvif, scoped middleware, merge-slashes, body-size, referer, return,
ServerTokens, FileETag, …) — each backed by a middleware unit test.

---

## Quality gates (how it stays conformant)

| Gate | Tool | Bar | Where |
|---|---|---|---|
| Static analysis | PHPStan | **level 10**, zero errors | `phpstan.neon`, CI |
| Line coverage floor | Codecov | **≥ 80%** project + patch | `codecov.yml`, CI |
| Mutation score (test effectiveness) | Infection | minMSI 70 / covered-MSI 85 (ratcheting) | `infection.json5`, `.github/workflows/mutation.yml` |
| Perf regression | ApacheBench | req/s floor (catastrophe detector) | `scripts/perf_smoke.sh`, `.github/workflows/perf.yml` |
| Secrets / CVE / code scanning | gitleaks / composer audit / CodeQL | clean | CI |

---

## Deviations register (honesty)

- **418 "I'm a teapot"** — IANA lists "(Unused)"; ZealPHP keeps the RFC 2324 phrase as a recognized extension. Pinned as an explicit extension in `IanaStatusConformanceTest`.
- **425 / 451** — OpenSwoole 22.x's C status list silently downgrades these to 200 via the one-arg `status()` call; ZealPHP emits them via the two-arg `status($code, $reason)` workaround in `emitStatus()`.
- **`PHP_SAPI` constant** — cannot be redefined (`uopz` refuses); `php_sapi_name()` is overridable, the constant is not. Documented.
- **HTTP/2 reason phrases** — HTTP/2 (RFC 9113) drops reason phrases by design; ZealPHP's phrases apply to HTTP/1.1 emission.
- **By-design non-parity** — `virtual()`, `auto_prepend_file`/`auto_append_file`, `max_execution_time`, `<Directory>`/`.htaccess` are not implemented (single-process / code-config model); documented with workarounds on the HTTP parity page.

## Roadmap (conformance suites being upgraded Behavioral → Exhaustive)
RFC 9110 §13 conditional, §14 Range, RFC 6265 cookie-attribute serialization,
RFC 7617 challenge ABNF, RFC 3986 percent-encoding edge cases, and IMF-fixdate
(`Date`/`Expires`) — see the conformance plan spec for the full program.
