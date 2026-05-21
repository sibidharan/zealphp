# Apache Directive Parity — Sprint Plan

**Date:** 2026-05-21
**Source:** Apache HTTP Server 2.4 docs — `mod/core.html` + `mod/quickreference.html` (directive inventory).
**Method:** Directive-by-directive comparison against ZealPHP's current surface; pick the safe, high-parity-value gaps and run a sprint. No breaking changes, no anti-patterns, PHPStan level 10, tests green.

---

## Gap analysis — Apache directives vs ZealPHP

Status legend: ✅ done · 🟡 partial · ❌ gap · 🚫 N/A (not applicable to the runtime model).

### Already covered (no work)
| Apache directive | ZealPHP |
|---|---|
| `DocumentRoot` | ✅ `App::$document_root` / `documentRoot()` |
| `TraceEnable` | ✅ `App::$trace_enabled` / `traceEnabled()` |
| `AddDefaultCharset` / `AddCharset` | ✅ `CharsetMiddleware` + `App::$default_charset` |
| `DefaultType` (php `default_mimetype`) | ✅ `App::$default_mimetype` + `CharsetMiddleware` |
| `ServerName` / `UseCanonicalName` | ✅ `App::canonicalHost()` |
| `ServerAdmin` | ✅ `App::$server_admin` |
| `LimitRequestFields` / `LimitRequestFieldSize` / `LimitRequestLine` | ✅ `App::$limit_request_*` |
| `ErrorDocument` | ✅ status→handler registry + `App::renderError()` / custom error pages |
| `DirectoryIndex` | ✅ `App::$directory_index` + `serveDirectory()` |
| `DirectorySlash` | ✅ `App::$directory_slash` / `strip_trailing_slash` |
| `Header` (mod_headers) | ✅ `HeaderMiddleware` |
| `ExpiresActive`/`ExpiresByType`/`ExpiresDefault` (mod_expires) | ✅ `ExpiresMiddleware` |
| `AddType`/`ForceType` (mod_mime) | ✅ `MimeTypeMiddleware` |
| `<FilesMatch> Cache-Control` | ✅ `CacheControlMiddleware` |
| `AuthType Basic` + `AuthUserFile` (mod_auth_basic) | ✅ `BasicAuthMiddleware` |
| `Require ip` / `Allow,Deny` (mod_authz) | ✅ `IpAccessMiddleware` |
| `Range` family (RFC 7233) | ✅ `RangeMiddleware` |
| mod_deflate | ✅ `CompressionMiddleware` / OpenSwoole gzip |
| `RewriteRule . /index.php [L]` | ✅ `App::setFallback()` |
| `server_name` vhosts | ✅ `HostRouterMiddleware` |
| mod_substitute | ✅ `BodyRewriteMiddleware` |
| `HostnameLookups` | ✅ `App::$hostname_lookups` |
| `X-Forwarded-For` / trusted proxies | ✅ `App::$trusted_proxies` + `clientIp()` |

### Gaps — sprint candidates
| Apache directive | ZealPHP today | Sprint | Value |
|---|---|---|---|
| `ServerTokens` / `ServerSignature` | `X-Powered-By: ZealPHP + OpenSwoole` **hardcoded** (App.php:4059) — no suppress/control | **S1** | High (info-leak / security) |
| `Redirect` / `RedirectMatch` (mod_alias) | only inline `$response->redirect()`; no declarative table | **S2** | High (.htaccess migration) |
| `SetEnvIf` / `BrowserMatch` (mod_setenvif) | none | **S3** | Med (feeds other middleware) |
| `FileETag` | `ETagMiddleware` always `W/"md5"`; can't disable/tune | **S4** | Med |
| `<Location>` / `<LocationMatch>` / `<FilesMatch>` scoping | middleware applies globally; no path-scoped application | **Next sprint** | High (architectural) |

### Deferred / by-design (documented elsewhere, not this sprint)
- `getenv`/`putenv`, `mail()` — recursion/blast-radius risk (see prior spec).
- `Options Indexes` (mod_autoindex directory listing) — security default-off; low value.
- `mod_negotiation` (MultiViews / LanguagePriority) — low value.
- `MergeSlashes` / `AllowEncodedSlashes` — URL normalization; candidate for a later sprint.
- `KeepAlive*`, `TimeOut`, `EnableSendfile`, `EnableMMAP`, RLimit*, MPM — owned by OpenSwoole server config, not userland. 🚫
- `<Directory>`, `AllowOverride`, `.htaccess`, `Include` — filesystem-config model; ZealPHP config is code (`App::*`). 🚫

---

## Sprint backlog (this session)

Each item: `src/` change → unit + integration tests → PHPStan L10 → docs row + CHANGELOG. All additive, default = current behavior.

### S1 — ServerTokens / Server header control
- `App::$server_tokens` (default `'Full'`) + `App::serverTokens(?string)`.
- Controls the `X-Powered-By` header at the emission point (App.php:4059):
  - `'Full'` (default) → `X-Powered-By: ZealPHP + OpenSwoole` (current behavior, no change).
  - `'Min'` / `'Minor'` / `'Major'` → `ZealPHP` (drop OpenSwoole detail).
  - `'Prod'` → `ZealPHP`.
  - `'None'` / `''` → **omit the header entirely** (the security-hardening case).
- One config knob, read at emission. Tests: each token → expected header (integration via a route).

### S2 — RedirectMiddleware (mod_alias Redirect / RedirectMatch)
- New `src/Middleware/RedirectMiddleware.php`.
- Declarative rules: exact-prefix (`Redirect /old /new`) and regex (`RedirectMatch ^/old/(.*) /new/$1`), each with a status (301/302/307/308, default 302).
- Runs early (before routing); on match emits a redirect response without invoking the handler.
- API: `new RedirectMiddleware([['from'=>'/old','to'=>'/new','status'=>301], ['match'=>'#^/x/(.*)#','to'=>'/y/$1','status'=>302]])`.
- Tests: exact match → Location + status; regex backref; no-match passes through.

### S3 — SetEnvIfMiddleware (mod_setenvif)
- New `src/Middleware/SetEnvIfMiddleware.php`.
- Rules match an attribute (`Remote_Addr`, `Request_URI`, `Request_Method`, `User-Agent`, or any `HTTP_*` header) against a regex; on match set key=value into `$g->server` (so downstream code / middleware see it, like Apache env vars).
- API: `new SetEnvIfMiddleware([['attr'=>'User-Agent','regex'=>'#bot#i','set'=>['IS_BOT'=>'1']]])`.
- Tests: UA match sets the var in `$g->server`; non-match doesn't.

### S4 — FileETag config
- `App::$file_etag` (default `true`) + `App::fileETag(?bool)`.
- `ETagMiddleware` consults it: when `false`, skip ETag generation/304 entirely (Apache `FileETag None`).
- Tests: default still ETags; disabled → no `ETag` header, no 304.

---

## Out of scope (next sprint)
- **Path-scoped middleware** (`<Location>`/`<LocationMatch>`/`<FilesMatch>` parity): a `ScopedMiddleware` decorator wrapping any middleware to apply only when the request path matches a pattern. Higher architectural value, deserves its own design — it changes how every middleware can be composed.
- `MergeSlashes` / `AllowEncodedSlashes` URL normalization.

## Quality bar
`./vendor/bin/phpunit tests/Unit/` + `tests/Integration/` green; `./vendor/bin/phpstan analyse` level 10 zero errors; PSR-2 + `declare(strict_types=1)`; new middleware get their own unit test file; docs row in `template/pages/http.php#parity` + CHANGELOG entry. No breaking changes.
