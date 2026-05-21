# Apache Parity Audit — ZealPHP vs httpd 2.5.1

**Date:** 2026-05-21
**Method:** Source-to-source diff. A full Apache httpd **2.5.1-dev** tree was cloned to `/tmp/httpd-audit` and ten subsystems of ZealPHP's HTTP/static/auth surface were diffed against the real C implementation, function by function. Ten parallel auditors each produced an evidence-cited report (`.omc/research/apache-audit/NN-*.md`); every finding cites the Apache function (`file:line`) and the ZealPHP `file:line`/test. The five highest-severity findings were independently re-verified against source by the audit lead before inclusion here.

**Why this audit exists:** ZealPHP claims Apache+mod_php parity. The prior conformance work (`STANDARDS.md` §"Apache httpd core-logic diff", commit `2f38323`) covered the request-smuggling surface well but only seven concerns at a high level. This audit goes deeper to find the edge cases Apache's 25-year-old codebase learned to handle that a younger implementation can silently miss.

**Verdict (honest):** ZealPHP has **strong parity on the smuggling/framing surface** and on several middleware behaviors, but **does not have full Apache parity**. The gaps cluster in four areas: (1) HTTP conditional-request semantics (preconditions largely unimplemented), (2) content-type/encoding resolution (single-suffix only), (3) several **security edge cases** Apache hardened long ago (symlink escape, double-encoded traversal, multi-range DoS), and (4) request-limit directives that are declared but **not enforced**. None of these are smuggling vectors, but several are real correctness or security gaps that legacy apps and CDNs depend on.

---

## Severity-ranked master gap register

Legend: 🔴 Critical · 🟠 High · 🟡 Medium · ⚪ Low. "Owner" = where the fix lives: **ZP** = ZealPHP code, **OS** = OpenSwoole parser (ZealPHP claims conformance but can't intercept at the wire), **design** = deliberate out-of-scope.

### 🔴 Critical

| # | Gap | Apache behaviour (cite) | ZealPHP today | Owner | Lane |
|---|-----|------------------------|---------------|-------|------|
| C1 | **Symlink escape via static handler** — a symlink under a static prefix (`/css`,`/js`,`/img`) pointing outside docroot is served by OpenSwoole's C handler before any PHP guard runs. `includeCheck()` (App.php:2304) does a plain `strpos` docroot-prefix test with **no `realpath()`**, so it misses symlink escape even on the PHP path. | `resolve_symlink()` checks every path segment, `FollowSymLinks` off by default (request.c:552-610) | No segment-wise symlink resolution; static handler path untested (`StaticServingConformanceTest` only exercises PHP routing) | ZP | 10 |
| C2 | **Multi-range DoS (CVE-2011-3192 class)** — `RangeMiddleware.php:74` `explode(',', …)` then unbounded `foreach`; thousands of range specs each allocate a multipart part → memory exhaustion. | Caps at **200 ranges** (byterange_filter.c:59, enforced :466) | No count cap, no test | ZP | 6 |
| C3 | **mod_mime single-suffix only** — `pathinfo(PATHINFO_EXTENSION)` (MimeTypeMiddleware.php:65, Response.php:308) returns only the rightmost suffix. `document.html.gz` → type for `gz`, no `Content-Encoding`. | `find_ct` walks ALL suffixes left-to-right, accumulating type+encoding+language+charset (mod_mime.c:891-1007) | Rightmost extension only | ZP | 8 |

### 🟠 High

| # | Gap | Apache behaviour (cite) | ZealPHP today | Owner | Lane |
|---|-----|------------------------|---------------|-------|------|
| H1 | **If-Match / If-Unmodified-Since / 412 entirely absent** — grep of `src/` returns zero matches. REST PUT/PATCH/DELETE optimistic-locking silently overwrites concurrent changes. | `ap_meets_conditions` evaluates both, returns 412 (http_protocol.c:589-605) | Not implemented anywhere | ZP | 5,7 |
| H2 | **Double-encoded traversal not rejected** — `rawurldecode()` called once (App.php:4639); `%252e%252e` → `%2e%2e`, passes the `..` regex. | Two normalize passes (request.c:214-216, 269-274) | Single decode; test accepts 404 and masks the gap | ZP | 10 |
| H3 | **`LimitRequestFields` (count) never enforced** — stored in `App::$limit_request_fields`, code comment (App.php:284) admits "advisory." | 400 when exceeded (protocol.c:930-940) | No per-request header-count check | ZP | 2 |
| H4 | **`LimitRequestFieldSize` is a no-op** — OpenSwoole's `http_header_buffer_size` rejected at boot (App.php:3748-3755), so the setter does nothing; only OpenSwoole's fixed default applies. | Enforced, configurable | Configured value ignored | ZP/OS | 2 |
| H5 | **`LimitRequestLine` advisory only, no 414** — declared `8190` (App.php:300) but unenforceable; OpenSwoole exposes no request-line cap. | Hard 414 (protocol.c:1388-1395) | No 414 ever emitted | ZP/OS | 1 |
| H6 | **Chunked uploads bypass body-size cap** — `BodySizeLimitMiddleware` skips requests with no `Content-Length` (docblock :26). | `LimitRequestBody` enforced against chunked byte count via `limit_used` (http_filters.c:671-686) | Only OpenSwoole `package_max_length` transport cap protects | ZP | 3 |
| H7 | **ETag namespace split** — `ETagMiddleware` hashes body (xxh3); `sendFile()` uses `W/"mtime-size"`; same URL gets disjoint ETags depending on serve path → CDN revalidation breaks. Also: middleware must buffer entire body to hash (Apache never does for static). | inode/mtime/size, zero I/O (util_etag.c) | Two incompatible schemes | ZP | 5 |
| H8 | **TRACE-when-enabled is a hollow stub** — `traceEnabled(true)` drops the 405 block but there's no echo body, no `Content-Type: message/http`, no 413 guard (App.php:4679-4684). | `ap_send_http_trace()` echoes request, sets type, guards size (http_filters.c:1048) | Disables block, serves nothing meaningful | ZP | 4 |

### 🟡 Medium

| # | Gap | Apache behaviour (cite) | ZealPHP today | Owner | Lane |
|---|-----|------------------------|---------------|-------|------|
| M1 | **No path normalization** — `//admin//`, `/./admin` reach the route table unmodified; can bypass pattern-matched security routes. | `ap_normalize_path()` collapses `//`, removes `/./`, merge_slashes on (util.c:491-598) | Only checks `\0`,`\\`,`/../` post-decode | ZP | 1 |
| M2 | **`sendFile()` ignores multi-range** — regex `^bytes=(\d*)-(\d*)$` matches single spec only; multi-range file download degrades to full 200 instead of 206 multipart. | Full multipart (byterange_filter.c:480-504) | Single-range only | ZP | 6 |
| M3 | **If-Range HTTP-date path missing** — only ETag comparison; date-valued If-Range with no response ETag silently applies the range (RFC 7233 §3.2 violation). | `ap_condition_if_range()` parses dates, 1-min skew rule (http_protocol.c:524-558) | ETag only (RangeMiddleware.php:66-70) | ZP | 6 |
| M4 | **Unknown methods → 404, not 501** — extension methods (e.g. `FOOBAR`) get 404. | 501 Not Implemented for M_INVALID (protocol.c:1253-1258) | 404 | ZP | 4 |
| M5 | **`OPTIONS *` → 204, not 200** — server-wide probe (RFC 9110 §9.3.7) should be a 200 pong. | 200, no body (http_core.c:336-340) | 204 + Allow:OPTIONS | ZP | 4 |
| M6 | **HEAD body not stripped on error/stream paths** — `defaultErrorResponse()` (App.php:2940-3020) and the Generator streaming path (4530-4540) don't check `REQUEST_METHOD`. | Body stripped for HEAD universally | Normal responses OK; error/stream paths leak body | ZP | 4 |
| M7 | **`If-None-Match: *` wildcard not matched** — always 200 for dynamic content; should 304 (GET) / 412 (non-GET). | Wildcard honored | Not handled (ETagMiddleware) | ZP | 5,7 |
| M8 | **Weak vs strong comparison wrong** — ETagMiddleware uses exact string equality (`W/"abc"` ≠ `"abc"`); Range+If-None-Match needs strong compare (RFC 7232) but uses weak → possible corrupt partial. | `ap_find_etag_weak`/`_strong` (util.c:1557) | Exact-string only | ZP | 5,7 |
| M9 | **`%2F` (encoded slash) silently decoded** — `rawurldecode()` decodes to `/` (undocumented `AllowEncodedSlashes On`). | Forbidden by default → 404 (util.c:1947-1950, request.c:259-264) | Decoded transparently | ZP | 1 |
| M10 | **ENOTDIR → 404, not 403** — Apache returns 403 ("deny rather than assume not found"). Also `includeCheck()` lacks `is_file()` guard → device/FIFO nodes in docroot pass. | 403 for ENOTDIR / non-REG types (request.c:1244-1292) | 404 / no type guard | ZP | 10 |
| M11 | **No `Content-Encoding`/`Content-Language` from extension** — no AddEncoding/AddLanguage equivalent anywhere in `src/`. | mod_mime.c:938-964 | Absent | ZP | 8 |
| M12 | **Dotfile basename rule inverted** — `pathinfo('.png')` returns `png`, so a MIME type is assigned to a hidden file. | Leading dots skipped; `.png` = hidden, no type (mod_mime.c:874-887) | Type assigned | ZP | 8 |
| M13 | **Plaintext htpasswd rejected only by accident** — `-p`/ALG_PLAIN entries fall to `crypt()` which happens to fail on Linux; no explicit guard, no test. The "NEVER accepted" docblock relies on misbehaviour. | Explicit handling | Accidental rejection | ZP | 9 |
| M14 | **Untested OpenSwoole framing behaviours** — unknown/non-chunked TE (Apache 400, http_core.c:303-311), chunk-size overflow (Apache 413, http_filters.c:222-226), premature chunked EOF (Apache 400). ZealPHP claims conformance but has no tests. | Strict 400/413 | Behaviour unknown | OS | 3 |

### ⚪ Low / informational

| # | Gap | Note | Lane |
|---|-----|------|------|
| L1 | obs-fold, NUL-in-header, header-token validation | OpenSwoole-owned; ZealPHP can't intercept at wire; undocumented | 2 |
| L2 | No bcrypt per-connection result cache | Perf only (Apache util.c:3520) | 9 |
| L3 | DES crypt 8-char silent truncation, no test | Legacy hash | 9 |
| L4 | No 505 HTTP-Version-Not-Supported path; no fragment/userinfo URI rejection | Low severity / OS-owned | 1 |
| L5 | Invalid spec in multi-range header skipped, not whole-header-rejected | RFC 7233 §2.1 says ignore entire header | 6 |

---

## What's solid (verified parity — do not regress)

- **Request-smuggling surface**: CL+TE, duplicate CL, bare-LF, invalid-hex chunk-size, leading-zero chunk-size — all rejected, all pinned in `Http1FramingConformanceTest`, all match Apache strict mode. (Lane 3)
- **APR1 (`$apr1$`) htpasswd**: byte-interleave matches `apr_md5_encode` exactly; pinned by 5 `openssl passwd -apr1` oracle vectors (commit `c9bee93`). (Lane 9)
- **Single-range 206, suffix/open-end ranges, 416 + `Content-Range: bytes */size`, multipart framing**: correct and tested. (Lane 6)
- **404-vs-403 for the PHP routing path, dotfile block, `/../` rejection**: correct. (Lane 10)
- **Basic Auth is intentionally stricter than Apache** on trailing junk after the base64 token and realm quote-escaping (security-positive divergences). (Lane 9)
- **IANA status registry, IMF-fixdate, cookie conformance**: exhaustively pinned (pre-existing, `STANDARDS.md`).

---

## Recommended remediation order (by risk/effort)

1. **C1 symlink escape** + **M10 is_file guard** — add `realpath()` containment in `includeCheck()` and verify the static-handler path; this is the only finding with a confidentiality-breach shape. *(security, small)*
2. **H2 double-encoded traversal** — decode-until-stable (or reject `%25` in path), tighten the test off `404`-accepts. *(security, small)*
3. **C2 multi-range cap** — bound range count (match Apache's 200) in `RangeMiddleware` + `sendFile`. *(DoS, small)*
4. **H6 chunked body cap** — enforce `LimitRequestBody` against decoded chunked bytes. *(DoS, medium)*
5. **H1/M7/M8 preconditions** — implement `ap_meets_conditions` precedence (If-Match → If-Unmodified-Since → If-None-Match → If-Modified-Since), wildcard, weak/strong compare in one shared evaluator. *(correctness, medium)*
6. **C3/M11/M12 mod_mime multi-suffix** — left-to-right suffix walk emitting type+encoding+language; fix dotfile rule. *(correctness, medium)*
7. **H3/H4/H5 request limits** — enforce `LimitRequestFields` in PHP; document FieldSize/RequestLine as OpenSwoole-governed and stop advertising them as configurable, OR upstream the OpenSwoole options. *(honesty + hardening, small)*
8. **H7 ETag unification, H8 TRACE, M2/M3 sendFile range/date, M4/M5 method codes, M6 HEAD strip** — correctness polish. *(medium)*

**Honesty note for `STANDARDS.md` / marketing:** until H3/H4/H5 are resolved, the `LimitRequest*` knobs should not be presented as enforced Apache-parity config — they are currently advisory or no-ops. This was the exact lesson the request-smuggling work already internalized; the limit directives need the same candor.

---

## Per-lane source reports

Full evidence-cited tables (every row cites Apache `file:line` + ZealPHP `file:line`/test) live in `.omc/research/apache-audit/`:

| Lane | Report | Apache source diffed |
|------|--------|----------------------|
| 1 | `01-request-line-uri.md` | `server/protocol.c`, `server/util.c` |
| 2 | `02-header-parsing.md` | `server/protocol.c` |
| 3 | `03-body-chunked-clte.md` | `modules/http/http_filters.c` |
| 4 | `04-methods-405-options-trace.md` | `modules/http/http_protocol.c` |
| 5 | `05-etag.md` | `server/util_etag.c` |
| 6 | `06-byte-range.md` | `modules/http/byterange_filter.c` |
| 7 | `07-conditional-requests.md` | `modules/http/http_protocol.c` (ap_meets_conditions) |
| 8 | `08-mod-mime.md` | `modules/http/mod_mime.c` |
| 9 | `09-htpasswd-basicauth.md` | `support/passwd_common.c`, `modules/aaa/mod_auth_basic.c` |
| 10 | `10-directory-walk-static.md` | `server/request.c`, `server/core.c` |

**Scope honesty:** this audit covered the HTTP request/response core, static serving, ETag/range/conditional handling, content-type resolution, and Basic Auth. It did **not** re-audit the ~137 bundled httpd modules already declared out-of-scope in `STANDARDS.md` §"Apache non-support register" (mod_rewrite full scope, WebDAV, LDAP/digest auth, mod_proxy, etc.) — those remain deliberately unsupported with documented substitutes.
