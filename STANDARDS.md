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

## PSR Interoperability (PHP-FIG)

The PHP-FIG standards ZealPHP implements, one row per PSR: the implementing class
(or composer config), the conformance level, and the test that proves it. Each
listed PSR has a class in `src/` implementing the corresponding interface and is
pinned by a CI test. The upstream interface packages `psr/log`, `psr/http-message`,
`psr/simple-cache`, `psr/http-factory`, and `psr/http-client` are direct
`composer.json` dependencies.

| Standard | Implementing class / config | Level | Proving test |
|---|---|---|---|
| **PSR-3** Logger | `ZealPHP\Log\Logger` (extends `Psr\Log\AbstractLogger`) | Behavioral | `tests/Unit/LoggerTest.php` |
| **PSR-4** Autoloading | composer `autoload.psr-4` `ZealPHP\` → `src/` | Behavioral | composer autoload (CI `composer install`) |
| **PSR-7** HTTP Message | `ZealPHP\HTTP\LazyServerRequest implements ServerRequestInterface` | Behavioral | `tests/Unit/HTTP/LazyServerRequestTest.php` |
| **PSR-15** Server Request Handlers | `ZealPHP\Middleware\Pipeline\MiddlewareFrame` + `src/Middleware/*` (`implements Psr\Http\Server\MiddlewareInterface`) | Behavioral | `tests/Unit/Middleware/Pipeline/MiddlewareFrameTest.php` |
| **PSR-16** Simple Cache | `ZealPHP\Cache\SimpleCacheAdapter implements CacheInterface` | Behavioral | `tests/Unit/SimpleCacheAdapterTest.php` |
| **PSR-17** HTTP Factories | `ZealPHP\HTTP\Factory\*` (Request / Response / ServerRequest / Stream / UploadedFile / Uri) | Behavioral | `tests/Unit/HttpFactoryTest.php` |
| **PSR-18** HTTP Client | `ZealPHP\HTTP\Client implements ClientInterface` | Behavioral | `tests/Unit/HttpClientTest.php` |

> **PSR-11** (Container) is **not** implemented — ZealPHP ships no
> `Psr\Container\ContainerInterface` and does not depend on `psr/container`.
> It is intentionally excluded from the list above.

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

## Apache httpd core-logic diff (verified against source)

The core HTTP/1.1 request-handling logic was diffed against the **Apache httpd 2.5.x
(trunk)** source — request-line tokenizer, header reader, Host enforcement, CL/TE
smuggling resolution, the 405/`Allow` path, and the 404-vs-403 file rule. Each row
cites the httpd function we compared against and the ZealPHP implementation + proving
test. Where httpd's *default* behaviour and ZealPHP's behaviour agree, it's marked ✅;
intentional differences are called out.

| Concern | httpd (default, strict) behaviour | ZealPHP behaviour | Match |
|---|---|---|---|
| **Missing `Host` on HTTP/1.1** | `ap_check_request_header` → `HTTP_BAD_REQUEST` (`server/protocol.c`, the `proto_num >= HTTP/1.1 && !Host` guard) | `ResponseMiddleware` Host guard → **400** before routing; HTTP/1.0 exempt | ✅ |
| **`Content-Length` + `Transfer-Encoding`** | `h1_post_read_request` (`modules/http/http_core.c`): chunked+CL → drop CL + force `Connection: close`; non-final/unknown TE → **400** + close (RFC 7230 §3.3.3) | CL+TE → **4xx** (rejected, connection not reused) — pinned in `Http1FramingConformanceTest` | ✅ (we reject; httpd's drop-CL variant is the more lenient of its two RFC-permitted options) |
| **Invalid `Content-Length` syntax** | `ap_parse_strict_length` → **400** (`server/protocol.c`) | OpenSwoole parser rejects malformed CL → **4xx** | ✅ |
| **Bare-LF line ending** | strict default (`AP_GETLINE_CRLF`): bare-LF → `APR_EINVAL` → **400**; tolerated only under `HttpProtocolOptions Unsafe` | bare-LF request → **not 200** (rejected) — pinned in framing suite | ✅ |
| **Duplicate same-name headers** | `apr_table_compress(..., MERGE)` — comma-joined per RFC 9110 §5.3 | OpenSwoole merges duplicate headers at the parser | ✅ |
| **obs-fold (header line folding)** | accepted + folded to a single SP (RFC 9112 §5.2 permits; fold-before-first-header → 400) | OpenSwoole handles folding at the parser layer | ⚠️ delegated to OpenSwoole parser (not independently asserted) |
| **405 Method Not Allowed** | `default_handler` (`server/core.c`): non-GET/POST on a static file → `HTTP_METHOD_NOT_ALLOWED`; `make_allow` builds `Allow` from the method mask; `ap_send_error_response` attaches `Allow` on 405/501 | route matches URI but not method → **405** + `Allow` (GET implies HEAD; OPTIONS always listed); implicit doc-root routes scoped to GET/POST | ✅ (RFC 9110 §15.5.6 — `HttpFeaturesTest`, `AppPipelineExtraTest`) |
| **404 vs 403 for files** | ENOENT → **404** (`default_handler`); EACCES / disallowed symlink / ENOTDIR / non-regular → **403** (`ap_directory_walk`, `server/request.c`) | nonexistent `.php`/file → **404**; existing-but-blocked → **403**; symlink escaping doc-root → 403/404, never leaks ([#25], `StaticServingConformanceTest`) | ✅ |
| **`Expect: 100-continue`** | sets `expecting_100`; other Expect values → **417** | server-level (OpenSwoole) — not app-layer; documented gap | ⚠️ |

### Default request limits — httpd constants vs ZealPHP knobs

| Limit | httpd default (`include/httpd.h`) | Directive | ZealPHP | Enforced? |
|---|---|---|---|---|
| Request line | **8190** bytes | `LimitRequestLine` | `App::$limit_request_line` (advisory) + OpenSwoole `package_max_length` transport cap | ⚠️ **Not at app layer** — OpenSwoole's C parser owns request-line framing; the knob is documentation-only, no independent 414. |
| Per-header field size | **8190** bytes | `LimitRequestFieldSize` | `App::$limit_request_field_size` (advisory) | ⚠️ **Not at app layer** — `http_header_buffer_size` is not passed to OpenSwoole at boot; the knob has no runtime effect. |
| Header field count | **100** | `LimitRequestFields` | `App::$limit_request_fields` | ✅ **Enforced** — `ResponseMiddleware` counts `HTTP_*` fields per request and returns **400** over the limit (Apache `ap_get_mime_headers_core` parity). `0` = unlimited. |
| Leading blank lines | 10 | (compile-time) | OpenSwoole parser | OpenSwoole-owned |
| Request body | **0 = unlimited** | `LimitRequestBody` | `BodySizeLimitMiddleware` (opt-in) + OpenSwoole `package_max_length` | ✅ **Enforced → 413**, including **chunked / no-Content-Length** uploads (measures the decoded buffered body; `package_max_length` remains the outer transport cap). |

Honest enforcement note: of the three Apache-named request-header limits, only **`LimitRequestFields` is enforced at the ZealPHP layer** (400 on excess fields). `LimitRequestLine` and `LimitRequestFieldSize` are **OpenSwoole-governed** — the C parser owns wire-level framing and ZealPHP cannot intercept it, so those two knobs are advisory documentation, not active limits. `LimitRequestBody` is enforced (413) by the opt-in `BodySizeLimitMiddleware`, now covering chunked uploads as well.

### OpenSwoole-governed surfaces (parity ceiling)

ZealPHP runs behind OpenSwoole's C HTTP server. Two request surfaces are decided by
that C layer *before* the PHP pipeline runs, so framework-layer Apache parity cannot
reach them. Both are documented honestly here and pinned by integration tests:

| Surface | Apache behaviour | OpenSwoole/ZealPHP reality | Safe? |
|---|---|---|---|
| **Unrecognised HTTP method** | 501 Not Implemented (`protocol.c:1253`) | OpenSwoole's parser rejects the verb with **400** before PHP runs. ZealPHP keeps an `App::KNOWN_METHODS`→501 guard as defense-in-depth, but it is unreachable for verbs OpenSwoole refuses. | ✅ refused, never processed |
| **TRACE** | configurable; `TraceEnable Off` → 405 | OpenSwoole rejects TRACE with **400** before PHP. The framework's 405-default and opt-in `traceEnabled(true)` echo handler are unreachable. | ✅ XST-safe (never echoed) |
| **Static-handler prefixes** (`/css`, `/js`, `/img` via `enable_static_handler`) | served by httpd with `ap_normalize_path` + `AllowEncodedSlashes off` + `FollowSymLinks` checks | OpenSwoole's C static handler serves matching files **directly**, bypassing the PHP-layer path normalization (M1), encoded-slash rejection (M9) and realpath symlink-containment (C1). Those guards apply to **PHP-routed** paths only. | ⚠️ keep static dirs symlink-free / no user-controlled filenames, or narrow `App::$static_handler_locations` / disable `enable_static_handler` to route through PHP |

The PHP-layer guards (path normalization, `%2F` rejection, double-decoded-traversal
400, realpath docroot-containment) are real and enforced for every **PHP-routed**
request — proven in `tests/Integration/StaticServingConformanceTest.php`. The
static-handler bypass is the documented trade for OpenSwoole's zero-copy static
serving; see `docs/apache-parity-audit.md`.

## Apache non-support register (what httpd has that ZealPHP does not)

httpd ships **~137 bundled modules**. ZealPHP is an application framework on a single
long-running OpenSwoole process, not a general-purpose reverse proxy / static web server,
so large swaths of httpd's surface are deliberately out of scope. Honest register of what
we do **not** provide, with the reason and the recommended substitute:

| httpd capability (module) | Status in ZealPHP | Substitute / rationale |
|---|---|---|
| **Reverse/forward proxy** (`mod_proxy`, `_balancer`, `_http`, `_ajp`, `_scgi`, `_uwsgi`, `_connect`, `_express`, `_hcheck`) — incl. **ProxyPass** | ❌ not implemented | Front ZealPHP with a real proxy (Traefik / nginx / Caddy). ZealPHP is the origin, not the gateway. |
| **TLS termination** (`mod_ssl`, `mod_md` ACME) | ❌ not in-process | Terminate TLS at the proxy. OpenSwoole *can* do TLS (`enable_http2`+certs) but production deploys terminate upstream; ACME is the proxy's job. |
| **WebDAV** (`mod_dav`, `_dav_fs`, `_dav_lock`) | ❌ not implemented | Out of scope (app framework, not a file server). |
| **CGI / FastCGI execution** (`mod_cgi`, `mod_cgid`, `mod_proxy_fcgi`) | ❌ no FastCGI gateway | ZealPHP *is* the PHP runtime (OpenSwoole workers), so PHP-FPM-style FastCGI is unnecessary. The CGI-worker subprocess (`cgi_worker.php`) is for legacy global-scope isolation, not a FastCGI server. |
| **WebSocket proxying** (`mod_proxy_wstunnel`) | ❌ (not as a proxy) | ZealPHP serves WebSocket natively (`App::ws()`); it doesn't *proxy* upstream WS. |
| **`mod_rewrite`** (full rewrite engine, `RewriteCond`/`RewriteRule`, regex rewrite maps) | ⚠️ partial | `App::setFallback()` + `MergeSlashesMiddleware` + `RedirectMiddleware`/`ReturnMiddleware` cover the common cases; no general per-request rewrite DSL. |
| **`.htaccess` / `<Directory>` per-dir config** | ❌ by design | Single-process, code-config model — config is PHP at boot, not per-directory files. Documented on the HTTP parity page. |
| **Content negotiation** (`mod_negotiation`, `Accept-Language`/type-map) | ❌ not automatic | Negotiate in the handler (`Accept` is in `$g->server`). |
| **SSI** (`mod_include`) | ❌ | Use templates (`App::render()`). |
| **On-the-fly content filters** (`mod_sed`, `mod_ext_filter`, `mod_brotli`) | ⚠️ partial | `BodyRewriteMiddleware` (mod_substitute parity, single-line); gzip/deflate via OpenSwoole `http_compression`; no brotli. |
| **Disk/socache caching** (`mod_cache`, `_cache_disk`, `_socache_*`) | ❌ no built-in HTTP cache | `Store` (shared memory) for app-level caching; HTTP caching headers via `CacheControlMiddleware`/`ExpiresMiddleware`/`ETagMiddleware` (let the proxy cache). |
| **LDAP / DBD auth** (`mod_authnz_ldap`, `mod_dbd`, `mod_authn_dbm`) | ❌ | Pluggable auth hooks (`App::authChecker()` etc.) — wire any backend in app code. |
| **Digest / form / bearer / JWT auth** (`mod_auth_digest`, `_form`, `_bearer`, `mod_autht_jwt`) | ⚠️ Basic only | `BasicAuthMiddleware` (RFC 7617). Bearer/JWT/digest are app-layer concerns. |
| **`mod_reqtimeout`** (slow-loris read timeout) | ❌ **known gap** | No app-level read-timeout; a slow header drip is currently accepted. Tracked in the testing roadmap (slowhttptest) — needs an OpenSwoole socket read-timeout. |
| **`mod_ratelimit`** (bandwidth throttle, KiB/s) | ❌ (rate ≠ bandwidth) | `RateLimitMiddleware` limits *request rate* (nginx `limit_req` parity), not output *bandwidth*. |
| **`mod_remoteip`** | ✅ equivalent | `App::$trusted_proxies` + `App::clientIp()` (X-Forwarded-For trust). |
| **`mod_log_config` / `mod_log_json`** | ✅ equivalent | `App::$access_log_format` (Apache `LogFormat` tokens). |
| **`mod_lua`** (embedded scripting) | n/a | ZealPHP *is* the scripting layer (PHP). |
| **`mod_session*`** (server-side session framework) | ✅ equivalent | Native session layer (file + Redis handlers, coroutine-safe). |

The directives ZealPHP **does** match are catalogued in the
[Apache / nginx directive parity](#apache--nginx-directive-parity) section above and the
`template/pages/http.php#parity` matrix; this register is the honest complement — the
deliberate non-goals of an application framework that expects a real proxy in front.

---

## Quality gates (how it stays conformant)

| Gate | Tool | Bar | Where |
|---|---|---|---|
| Static analysis | PHPStan | **level 10**, zero errors | `phpstan.neon`, CI |
| Line coverage floor | Codecov | **≥ 80%** project + patch | `codecov.yml`, CI |
| Mutation score (test effectiveness) | Infection | **Covered-MSI 95%** / plain MSI 91% (gates 92 / 88) | `infection.json5`, `.github/workflows/mutation.yml` |
| Perf regression | ApacheBench | req/s floor (catastrophe detector) | `scripts/perf_smoke.sh`, `.github/workflows/perf.yml` |
| Secrets / CVE / code scanning | gitleaks / composer audit / CodeQL | clean | CI |
| Supply-chain posture | OpenSSF Scorecard | published (badge) + SARIF to code-scanning | `.github/workflows/scorecard.yml` |
| Dependency transparency | CycloneDX SBOM | `build/sbom.cdx.json` artifact (CycloneDX 1.5) | `.github/workflows/sbom.yml` |

### Why mutation score is 95%, not 100% — the equivalent-mutant blocker

Covered-MSI sits at **95%**: of ~1763 covered mutants, 1680 are killed and **83 survive**.
Every one of the 83 is a *provably-equivalent mutant* — a source mutation that produces
**semantically identical behaviour**, so by construction **no test can kill it** (there is
no input for which the original and mutant differ). 100% MSI is therefore mathematically
unreachable by adding tests; it could only be reported by annotating these lines with
`@infection-ignore` — which this project deliberately does **not** do, because a clean
"95% with an honest equivalent-mutant register" beats a "100%" propped up by ignore
pragmas. The recurring equivalence classes:

| Equivalence class | Example | Why no test distinguishes it |
|---|---|---|
| Redundant cast under a type guard | `(int) $header` after `ctype_digit($header)`; `(string) $k` array key | the guard / PHP's key-normalization already guarantees the type → cast is a no-op |
| Redundant cast under a declared return type | `(string) $ip` in a `: string` method | PHP coerces the return value identically with or without the cast |
| Buffer-capped `substr`/`min` | `min(16, $i)` → `min(17, $i)` on a 16-byte MD5 | `substr` caps at the string length → identical bytes |
| Empty-iteration loop bound | `for(...; $i > 0; ...)` → `$i >= 0` where the extra pass appends `''` | the extra iteration is a no-op on the accumulator |
| Unreachable defensive branch | `filemtime()/fopen()/gzencode() === false` → `=== true` | the function never returns `true`; both forms skip the dead branch |
| Equivalent allow-all configs | `['*']` → `[]` in an IP/host allow-list | both make the allow-check pass everything |
| Stacked `case`/`default` fallthrough | dropping `case 'set':` when it shares `default:`'s body | control reaches the same body |
| `explode($s, $d, 2)` → `, 3)` with 2-var destructure | `[$a, $b] = explode(...)` | only the first two parts bind; the limit is invisible |

Each surviving mutant is catalogued with its proof in the per-file test docblocks and the
commit messages for the `tests/Unit/*MiddlewareTest.php` mutation-hardening series. The
gate floor is 92 (3 points under the measured 95) so it bites against regression without
flapping.

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

## Advanced testing roadmap — four tools, four failure classes

Mutation testing (Infection) hardens the *code*. Three cousins harden the rest of
the HTTP stack — each attacks a different layer, zero overlap. Harnesses live under
`scripts/fuzz/` + `tests/gabbi/`; full write-up in [`docs/fuzzing.md`](docs/fuzzing.md);
`.github/workflows/fuzz.yml` runs them in CI.

| Layer | Tool | What it does | Status |
|---|---|---|---|
| **Code** (test strength) | **Infection** | mutates the source AST, checks tests catch it (MSI gate) | ✅ in CI |
| **Parser** (mutation) | **Radamsa** | mutates a seed corpus of valid requests (`scripts/fuzz/corpus/`, derived from the framing fixtures), pipes mangled variants at the socket → every mutant must get a clean 4xx/parse, never a hang or 500-with-trace | ✅ **ran** — `scripts/fuzz/radamsa_run.sh`: 500 mutations → 0 hangs, 0 stack-trace leaks (parser robust); gated in `fuzz.yml` |
| **Contract** (readable cases) | **Gabbi** | declarative YAML request→expected-response fixtures (Host, 405+Allow, dotfile-403, OPTIONS, 404, JSON) | ✅ **ran** — `tests/gabbi/conformance.yaml`: 7/7 pass; gated in `fuzz.yml` |
| **Reactor** (DoS survival) | **slowhttptest** | slowloris / slow-body drip → proves one stuck client can't starve the coroutine reactor (`mod_reqtimeout` parity) | ⚠️ **ran — confirms a known gap**: `scripts/fuzz/slowhttptest.sh` — 50 slow conns held open for the full 30 s, `closed=0` (OpenSwoole has no per-request read-timeout). Informational in CI; fix needs an OpenSwoole read-timeout. |
| **Parser** (wire robustness) | **http-garden** (differential) | sends crafted requests to ZealPHP *and* Apache/nginx, diffs the parse → divergence *is* the smuggling bug; Apache as the oracle | ⏭️ planned — **requires Docker** (absent in the current CI image); approach + the ZealPHP target Dockerfile/config documented in `docs/fuzzing.md` §4 |

Sequencing (credibility-per-hour): Radamsa + Gabbi landed first (cheapest, run in CI); slowhttptest documents the reactor gap; **http-garden vs Apache** is the next step once a Docker-enabled runner is available.
