# nginx + Apache Deep-Edge Parity Audit — ZealPHP

**Date:** 2026-05-21
**Method:** Source-to-source diff. nginx **1.31.1** (`/tmp/nginx-audit`) and Apache httpd **2.5.1** (`/tmp/httpd-audit`) were cloned and 14 subsystems of ZealPHP's migration surface diffed against the real C implementation by 14 parallel Opus auditors. Every finding cites the upstream function (`file:line`) and the ZealPHP `file:line`/test. Companion to `docs/apache-parity-audit.md` (the HTTP-core Apache audit, fixed in PR #38).

**Why:** ZealPHP advertises nginx-parity middleware (`limit_req`, `limit_conn`, `client_max_body_size`, `valid_referers`, `server_name`, `merge_slashes`, `return`, `add_header`, `gzip`) and Apache parity. This audit stress-tests whether the **migration story holds for edge cases** a real `nginx.conf` / `.htaccess` exercises.

**Verdict (honest):** the common path works and is tested, but **the migration story has real gaps** in three shapes: (1) **algorithmic/keying divergence** (rate-limit window vs leaky-bucket, conn-limit global vs per-key), (2) **missing dimensions** (server_name wildcards/regex, location precedence, `RewriteCond`, `try_files`), and (3) **genuine bugs** (referer wildcard over-match, rate-limit ignoring proxy IP, body-limit `0` meaning, mod_expires caching errors, Vary overwrite, error-header leak). Several are security-relevant. None are smuggling vectors.

> **Already fixed by PR #38** (don't re-fix): double-encoded-traversal `%252e%252e` 400 (worker-6 N3), chunked body-size enforcement (worker-3 N2). Those lanes audited `master`, which predates the merge.

---

## Genuine bugs (not just missing features) — fix candidates

| # | Bug | Where | Severity | Lane |
|---|-----|-------|----------|------|
| B1 | **Referer `example.*` over-matches** — `str_starts_with($host,"example.")` lets **`example.evil.com`** pass the allow-list | `RefererMiddleware.php:102` | 🔴 security | 4 |
| B2 | **Rate-limit ignores proxy IP** — reads raw `REMOTE_ADDR`, not `App::clientIp()`; every client behind Traefik/nginx shares one bucket | `RateLimitMiddleware.php` (clientIp) | 🟠 | 1 |
| B3 | **`BodySizeLimitMiddleware(0)` rejects everything** instead of "unlimited" (nginx short-circuits on 0) | `BodySizeLimitMiddleware.php` `process()` | 🟠 | 3 |
| B4 | **mod_expires stamps `max-age` on error responses** — a 404 for `/missing.css` gets `Cache-Control: max-age=2628000, public`; CDNs cache the error | `ExpiresMiddleware`/`CacheControlMiddleware` | 🟠 | 14 |
| B5 | **Compression `Vary` overwrites** — `withHeader('Vary')` drops CORS's `Vary: Origin` instead of merging | `CompressionMiddleware.php:82` | 🟡 | 13 |
| B6 | **Error response leaks prior headers** — `defaultErrorResponse` doesn't clear `$g->zealphp_response` headers; `Content-Type: application/pdf` from a failed handler leaks onto the 500 | `App.php:2940` | 🟡 | 12 |
| B7 | **Referer `~regex` case-sensitive** — nginx always compiles `NGX_REGEX_CASELESS`; ours doesn't (inverts allow/deny) | `RefererMiddleware.php:82` | 🟡 | 4 |
| B8 | **IPv6 Host mis-parse** — `[::1]:80` first colon treated as port separator | `HostRouterMiddleware.php` | 🟡 | 5 |
| B9 | **RedirectMiddleware QSA drops query** when target already contains `?` (Apache merges with `&`) | `RedirectMiddleware.php:68-71` | 🟡 | 11 |
| B10 | **Rate-limit/`@preg_match` fail-open + silent suppression** — Store-full fails open with no log; malformed referer regex silently suppressed | `RateLimitMiddleware`, `RefererMiddleware.php:82` | 🟡 | 1,4 |

These are the highest-value outputs of the audit — each is a small, localized fix. **B1, B4, B6 are the security/correctness priorities.**

---

## Severity-ranked gap register

### 🔴 Critical (algorithmic / keying divergence — migration breaks silently)

| Gap | nginx/Apache (cite) | ZealPHP | Lane |
|-----|--------------------|---------|------|
| **Rate-limit algorithm** — leaky-bucket, ms-precision drain, `burst=`/`nodelay`/`delay=` | `ngx_http_limit_req_module.c:454` | fixed-window counter (hard reset every N s → boundary thundering-herd); no burst/nodelay/dry-run; 429 not nginx's 503 | 1 |
| **conn-limit is global, not per-key** — one abusive client 503s everyone | `ngx_http_limit_conn_module.c:226-287` (per-key rbtree) | single global `Atomic` counter; per-IP impossible without a `Store`-backed keyed impl | 2 |
| **`RewriteCond` entirely absent** — 35+ `%{VAR}`, file-tests (`-f -d -s`), OR-chains | `mod_rewrite.c` | none; every conditional `.htaccess` rule must be hand-ported to PHP | 11 |
| **`add_header` status-conditional default** — nginx skips on 4xx/5xx unless `always` | `ngx_http_headers_filter.c:247` | applies to ALL responses (acts like `always`); zero non-200 coverage | 8 |
| **mod_expires dual-header atomicity** — one rule emits BOTH `Expires` + `Cache-Control` | `mod_expires.c:390-439` | split across two decoupled middleware; can diverge / one missing | 14 |

### 🟠 High (missing dimension — common configs don't port)

| Gap | nginx/Apache | ZealPHP | Lane |
|-----|--------------|---------|------|
| **server_name trailing-wildcard (`www.*`) + regex (`~^…`)** | `ngx_hash.c:147`, `core_module.c:4542` | only exact + leading-wildcard (`*.x`) | 5 |
| **location match precedence** (`=` > `^~` > regex-in-order > longest-prefix) | `ngx_http_core_find_location` | flat first-registration-wins; no modifiers/longest-prefix | 9 |
| **`try_files` chain** (`$uri $uri/ /index.php?$args`, named `@loc`, `=404`) | `ngx_http_core_try_files` | only the final leg via `setFallback()`; not first-class | 9 |
| **`valid_referers server_names` token** — auto-populate from vhost names | `ngx_http_referer_module.c:513` | absent; manual enumeration | 4 |
| **ErrorDocument internal-redirect + external-URL forms** + `REDIRECT_*` env | `http_request.c:167-201` | only a PHP callable; no sub-request, no `REDIRECT_STATUS` | 12 |
| **mod_deflate ETag `-gzip` suffix / gzip ETag-weakening** — cache coherence | `mod_deflate.c:862`, `gzip_filter_module.c:293` | ETag passed through unchanged on compress (RFC 7232 §2.1) | 13,10 |
| **`Accept-Encoding: q=0` not refused** — compresses what client refused | `mod_deflate.c:788`, `core_module.c:2265` | `str_contains($accept,'gzip')`, no q-parse (CompressionMiddleware opt-in path) | 10,13 |
| **mod_expires `M` (modification-time) base** | `mod_expires.c:407` | only access-time | 14 |
| **`%2F%2F` not merged** (WAF-bypass surface) + `/a/./b` dot-segment not resolved | `ngx_http_parse.c:1426-1468` | regex on raw encoded path; `.` passes through | 6 |

### 🟡 Medium / ⚪ Low
- Missing-Host / duplicate-Host / invalid-Host → 400 (nginx `ngx_http_request.c`); ZealPHP falls through (lane 5).
- `return 444` (close, no response) + bare-URL implicit-302 form (lane 7).
- `merge_slashes`/`limit_req`/`limit_conn` lack dry-run + configurable status (lanes 1,2).
- RewriteMap (7 types), `[P]` proxy, `[C]`/`[S]`/`[N]` flags — by-design (use upstream proxy) (lane 11).
- `add_trailer` / HTTP trailers, per-location header inheritance — architectural (lane 8).
- `Accept-Ranges` not cleared on compress (lane 10); `display_errors=true` unsafe default (lane 12); IPv6/trailing-dot host normalization (lane 5).

---

## What's solid (verified parity)
- **conn-limit decrement-on-all-paths** — `try/finally` fires after exceptions/streaming/SSE/sendFile; no counter leak, unit-tested (lane 2).
- **Referer none/blocked/server_names(exact)/regex classes**, **leading-wildcard server_name**, **exact/case-insensitive/port-strip Host match** (lanes 4,5).
- **Redirect 301/302/307/308 external redirects** + regex backrefs + multi-rule ordering, 11 tests (lane 7).
- **Front-controller pattern** (`RewriteCond !-f !-d → index.php`) via `App::setFallback()` (lane 11).
- **OpenSwoole native gzip** is the default compression path (`http_compression: true`) — the CompressionMiddleware gaps only bite operators who opt into the reference middleware (lane 10).

---

## Recommended follow-up (by risk/effort)

**Wave 1 — bug fixes (small, high-value):** B1 referer over-match + B7 regex-case (security), B4 mod_expires error-suppression + clamp, B6 error-header clear, B2 rate-limit `clientIp()`, B3 body-limit `0`=unlimited, B5 Vary-merge, B8 IPv6 host, B9 QSA merge, B10 fail-open logging. All localized to single middleware files; each gets a unit test.

**Wave 2 — keying/algorithm (medium):** per-key `limit_conn` (Store-backed) + `limit_req` burst/nodelay + configurable status/dry-run; server_name trailing-wildcard + regex; `add_header` status-conditional default (with an `always` opt-out).

**Wave 3 — architectural (larger):** `try_files` first-class API + location precedence modifiers; mod_expires dual-header unification; ErrorDocument internal-redirect form. `RewriteCond`/RewriteMap/`[P]` stay documented-as-unsupported (port to PHP / upstream proxy) — that's the honest migration boundary.

**Docs:** the migration guides should state plainly what does NOT port (full `RewriteCond`/RewriteMap, `try_files` precedence, per-key limits pre-Wave-2) so users aren't surprised — same candor as `STANDARDS.md`'s OpenSwoole-governed table.

---

## Per-lane reports
nginx: `.omc/research/nginx-audit/01..10-*.md` · Apache-deep: `.omc/research/apache-deep-audit/11..14-*.md`. Every row cites upstream `file:line` + ZealPHP `file:line`/test.

| Lane | Report | Upstream diffed |
|------|--------|-----------------|
| 1 | 01-limit-req | `ngx_http_limit_req_module.c` |
| 2 | 02-limit-conn | `ngx_http_limit_conn_module.c` |
| 3 | 03-client-max-body-size | `ngx_http_core_module.c` + `ngx_http_request_body.c` |
| 4 | 04-valid-referers | `ngx_http_referer_module.c` |
| 5 | 05-server-name | `ngx_http_core_module.c` + `ngx_http_request.c` + `ngx_hash.c` |
| 6 | 06-merge-slashes-uri | `ngx_http_parse.c` |
| 7 | 07-return-rewrite | `ngx_http_rewrite_module.c` |
| 8 | 08-add-header | `ngx_http_headers_filter_module.c` |
| 9 | 09-location-tryfiles | `ngx_http_core_module.c` |
| 10 | 10-gzip | `ngx_http_gzip_filter_module.c` |
| 11 | 11-mod-rewrite | `modules/mappers/mod_rewrite.c` |
| 12 | 12-errordocument | `http_request.c` + `http_protocol.c` |
| 13 | 13-mod-deflate | `modules/filters/mod_deflate.c` |
| 14 | 14-mod-expires | `modules/metadata/mod_expires.c` |
