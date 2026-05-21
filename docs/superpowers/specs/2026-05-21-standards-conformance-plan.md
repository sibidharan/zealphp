# Standards Conformance — Test Plan & Program

**Date:** 2026-05-21
**Goal:** Move ZealPHP from "behaviors are tested by example" to **provable, documented, gated standards conformance.** Every relevant RFC/standard is (1) populated in code, (2) documented in `STANDARDS.md` with the clause it satisfies, and (3) proven by an exhaustive, spec-citing conformance test that runs in CI. Edge cases are validated, not assumed.

**Why:** A framework earns trust by *demonstrating* conformance, not asserting it. This plan makes "we follow the standard" a checkable claim.

---

## Principles

1. **Cite the clause.** Every conformance test references the RFC/section (or IANA registry) it validates, in the test docblock and ideally the assertion message.
2. **Exhaustive over representative.** Where a registry/enumeration exists (IANA status codes, methods), test the *whole* set, not a sample.
3. **Authoritative source of truth.** Validate against the primary source (IANA registry, RFC ABNF), captured as a fixture with a dated provenance comment so drift is visible.
4. **Gate it.** Conformance tests run in the normal PHPUnit suite (CI-blocking). Coverage floor + mutation score guard the *strength* of the tests, not just their presence.
5. **Document the deltas.** Where ZealPHP deviates (e.g. an OpenSwoole limitation, a reserved code), the deviation is documented in `STANDARDS.md` with the reason — honesty over false claims.

---

## Standards inventory (relevant to an HTTP/PHP framework)

Legend: **Impl** = implemented in ZealPHP · **Test** = conformance test status · **Doc** = STANDARDS.md entry.

### HTTP core
| Standard | Scope | Impl | Conformance test plan |
|---|---|---|---|
| **IANA HTTP Status Code Registry** (RFC 9110 §15) | Every assigned 1xx–5xx code → correct description; reserved/unused handled | ✅ `REASON_PHRASES` + `emitStatus()` | **Exhaustive**: assert table == IANA assigned set, each phrase == IANA description; reserved (306/418) and unassigned ranges handled explicitly; out-of-range (0/600+) → 500 coercion. **(this wave)** |
| **RFC 9110 §15 status coercion** (RFC 7230 three-digit) | int return 100–599 valid; out-of-range → 500 | ✅ `coerceStatus` | Boundary tests: 99→500, 100 ok, 599 ok, 600 pass-through-or-coerce, negative→500. **(extend existing)** |
| **RFC 9110 §13 / 7232 — Conditional requests** | ETag, If-None-Match, If-Modified-Since, 304, weak/strong | ✅ `ETagMiddleware` | 304 on match, 200 + ETag on miss, weak `W/` form, If-None-Match `*`, method scoping (GET/HEAD only). **(this program)** |
| **RFC 9110 §14 / 7233 — Range requests** | `Range`, 206, `Content-Range`, 416, multipart/byteranges, `If-Range`, `Accept-Ranges` | ✅ `RangeMiddleware` + `sendFile` | single range, multi-range multipart, suffix range, 416 + `Content-Range: bytes */len`, unsatisfiable, `Accept-Ranges: none` on streams. **(this program)** |
| **RFC 9110 §9 — Methods** | GET/HEAD/POST/PUT/DELETE/OPTIONS/TRACE; HEAD≡GED w/o body; OPTIONS `Allow` | ✅ ResponseMiddleware | HEAD strips body keeps Content-Length; OPTIONS 204 + `Allow`; TRACE disabled (XST). **(extend)** |
| **RFC 9110 §10.2.2 / IMF-fixdate** | `Date`, `Expires`, `Last-Modified` format (RFC 5322 / IMF) | ✅ ExpiresMiddleware | Header value matches IMF-fixdate ABNF (`Sun, 06 Nov 1994 08:49:37 GMT`). **(this program)** |
| **RFC 9112 — HTTP/1.1 messaging** | status line, reason phrase emission, chunked | ✅ OpenSwoole + `emitStatus` | reason-phrase two-arg emission for codes OpenSwoole's C list drops (425/451). **(extend existing emitStatus tests)** |

### Auth, cookies, URI
| Standard | Scope | Impl | Conformance test plan |
|---|---|---|---|
| **RFC 7617 — HTTP Basic Auth** | `WWW-Authenticate: Basic realm=`, 401, base64 `user:pass` | ✅ `BasicAuthMiddleware` | challenge header format, base64 decode edge cases, realm quoting, 401 vs pass. **(this program)** |
| **RFC 6265 — Cookies** | `Set-Cookie` attributes, `SameSite`, `Secure`, `HttpOnly`, `Max-Age`/`Expires`, encoding | ✅ `setcookie` override | attribute serialization order, SameSite=None requires Secure, value encoding, `__Host-`/`__Secure-` prefixes (doc). **(this program)** |
| **RFC 3986 — URI** | path normalization, percent-encoding, dot-segment & null-byte rejection | ✅ ResponseMiddleware traversal guard | `%2e%2e`, `%00`, backslash, encoded traversal rejected with 400; reserved/unreserved handling. **(this program)** |
| **RFC 4648 — Base64** | used by Basic auth | ✅ | covered under RFC 7617. |

### Content, compression, negotiation
| Standard | Scope | Impl | Conformance test plan |
|---|---|---|---|
| **IANA charset / RFC 9110 §8.3 Content-Type** | `; charset=`, default type | ✅ `CharsetMiddleware` | textish prefix list, charset append idempotence, default_mimetype. **(extend existing)** |
| **RFC 1952/1951 — gzip/deflate** | `Content-Encoding`, `Vary` | ✅ OpenSwoole + `CompressionMiddleware` | gzip selected on `Accept-Encoding`, deflate fallback, proxy-skip. **(extend existing)** |
| **WHATWG Fetch — CORS** | preflight, `Access-Control-*` | ✅ `CorsMiddleware` | preflight 204 + allow headers, origin echo, credentials. **(extend existing)** |
| **WHATWG HTML — Server-Sent Events** | `text/event-stream`, `data:`/`event:`/`id:` framing | ✅ `Response::sse` | wire format framing, `\n\n` record separator. **(this program)** |
| **RFC 6455 — WebSocket** | opcode handling, CLOSE 1001, frame types | ✅ `App::ws` | TEXT/BINARY dispatch, PING/PONG/CONTINUATION drop, CLOSE on shutdown. **(integration exists; cite RFC)** |

### Quality gates (the "how we know it stays conformant")
| Gate | Tool | Bar |
|---|---|---|
| Static analysis | PHPStan | level 10, zero errors (existing) |
| Line coverage floor | Codecov | **≥ 80%**, fail on >1% drop (`codecov.yml` — this program) |
| **Mutation score** | **Infection** | establish baseline MSI on `src/Middleware` + `src/HTTP`; target ≥ 80% MSI, ≥ 90% covered-MSI (this program) |
| Perf regression | `bench/` | a CI smoke with a req/s floor so throughput can't silently regress (this program) |
| Secrets / CVE / CodeQL | gitleaks / composer audit / CodeQL | existing |

---

## Execution waves

**Wave 1 (this session):**
- This plan doc + `STANDARDS.md` skeleton.
- **IANA status conformance test** (exhaustive) + fix `413`/`422` phrases to RFC 9110 names; document `418` (RFC 2324 reserved).
- `codecov.yml` coverage floor.
- **Infection** config + baseline MSI on `src/Middleware`.

**Wave 2:**
- Conditional (9110 §13), Range (9110 §14), Cookies (6265), Basic auth (7617), URI (3986), IMF-date conformance suites — each citing clauses.
- Perf-regression CI smoke.

**Wave 3:**
- SSE/WebSocket/CORS/compression conformance hardening; raise Infection MSI target; expand `STANDARDS.md` to 100% of the inventory with the proving test named per row.

Every wave: PHPStan L10, all tests green, CHANGELOG, `STANDARDS.md` rows filled, no breaking changes (reason-phrase alignment to RFC 9110 is advisory-only — documented).

---

## Deviations register (honesty)
- **418 I'm a teapot** — IANA "(Unused)"; ZealPHP keeps the RFC 2324 phrase as a recognized extension. Documented.
- **425/451** — OpenSwoole 22.x C status list drops them; ZealPHP emits via the two-arg `status($code, $reason)` workaround. Documented + tested.
- **PHP_SAPI constant** — cannot be redefined (uopz); `php_sapi_name()` override only. Documented.
- **HTTP/2 reason phrases** — HTTP/2 drops reason phrases by design (RFC 9113); ZealPHP's phrases apply to HTTP/1.1 emission.
