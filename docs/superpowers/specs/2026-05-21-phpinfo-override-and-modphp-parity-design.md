# phpinfo() Override + Apache/mod_php Parity — Design Spec

**Date:** 2026-05-21
**Status:** Approved for phpinfo implementation; parity scan + docs are a roadmap deliverable.
**Author:** brainstormed with Sibidharan

---

## Goal

Two intertwined objectives:

1. **Task 1 — Override `phpinfo()`** so that, like Apache + mod_php, a request through ZealPHP renders a beautiful self-contained HTML page instead of the CLI SAPI's plain-text `key => value` dump.
2. **Apache + mod_php parity** — a full parity scan documenting how Apache+mod_php behaves vs. how ZealPHP behaves, what we have already achieved, where we still lag, and an explicit rule: **where Apache+mod_php works, ZealPHP must work natively; any remaining gap is documented on the relevant docs page.**

Hard constraints (from the goal): **no breaking changes, no new anti-patterns, follow all project standards, all tests green, PHPStan level 10 clean.**

---

## Context — what already exists

ZealPHP runs under the PHP **CLI SAPI** inside OpenSwoole and uses `uopz` to override PHP built-ins so legacy code behaves as under mod_php. The existing override surface:

- `src/utils.php` — `header()`, `header_remove()`, `headers_list()`, `headers_sent()`, `http_response_code()`, `setcookie()`, `setrawcookie()`, `flush()`/`ob_*`, `set_time_limit()`, `connection_status()`/`connection_aborted()`, `is_uploaded_file()`, `move_uploaded_file()`, `set_error_handler()`/`set_exception_handler()`/`register_shutdown_function()`, `error_reporting()`, plus the Apache family `apache_request_headers()`, `getallheaders()`, `apache_response_headers()`, `apache_setenv()`/`apache_getenv()`, `apache_note()`, `virtual()`.
- `src/Session/utils.php` — the full `session_*()` family.
- `src/App.php` `__construct()` (≈ lines 474–522) registers every `uopz_set_return()` override.
- `src/Middleware/*` — directive-parity middleware: `Cors`, `ETag`, `Compression`, `Range`, `SessionStart`, `IniIsolation`, `Charset`, `CacheControl`, `Expires`, `Header`, `BasicAuth`, `IpAccess`, `RateLimit`, `ConcurrencyLimit`, `BlockPhpExt`, `MimeType`, `BodyRewrite`, `HostRouter`.
- Server-level configurables on `App::*` (DocumentRoot, TraceEnable, default charset, ServerAdmin, ServerName/UseCanonicalName, trusted proxies, log format, LimitRequest* family, etc.).

`phpinfo()` is the conspicuous gap: it is **not** overridden, so under CLI SAPI it emits `text/plain` `key => value` lines. The existing `/phpinfo` route ([app.php](../../../app.php)) renders [template/app/phpinfo.php](../../../template/app/phpinfo.php), which wraps a bare `phpinfo()` in `<pre>` — i.e. the "stupid CLI info."

---

## Decisions locked (from clarifying questions)

| Decision | Choice |
|---|---|
| Session scope | Implement `phpinfo()` override **now**; deliver the parity scan as a roadmap + docs plan. |
| Render approach | **Hybrid** — structured data for the sections we control, native-text capture for module specifics. |
| Gating | **Match mod_php — no gate.** `phpinfo()` always renders; not exposing it in prod is the dev's responsibility (the `/phpinfo` route already carries that warning). |
| "Server API" row | Render **`ZealPHP (OpenSwoole <ver>)`**. The `php_sapi_name()` global override itself is a separate roadmap item. |

---

## Part A — `phpinfo()` HTML override (implement this session)

### A.1 Components & boundaries

| Unit | Responsibility | Depends on |
|---|---|---|
| `ZealPHP\Diagnostics\PhpInfo` (new, `src/Diagnostics/PhpInfo.php`) | Pure renderer. `public static function render(int $flags = INFO_ALL): string` returns a complete self-contained HTML document for the requested `INFO_*` sections. No echo, no global writes — testable in isolation. | `$g` (request data), boot-captured module text cache, PHP introspection fns |
| `\ZealPHP\phpinfo(int $flags = INFO_ALL): true` (new, `src/utils.php`) | The uopz override target. Echoes `PhpInfo::render($flags)`, returns `true` (native return contract). | `PhpInfo::render()` |
| Registration line in `src/App.php` `__construct()` | `\uopz_set_return('phpinfo', \Closure::fromCallable('\ZealPHP\phpinfo'), true);` | uopz |
| Boot-capture in `src/App.php` `__construct()` | Before installing the override, run native `phpinfo(INFO_MODULES)` under `ob_start()` once; stash text in a static cache (`PhpInfo::primeModuleText(string)` or equivalent). | native phpinfo (still original at this point) |

### A.2 The recursion trap and how we avoid it

Once `phpinfo()` is overridden globally, calling native `phpinfo()` from inside the renderer recurses. Per-request `uopz_unset_return()`/re-set would **race across coroutines** (global state) — unacceptable.

**Solution: capture-once-at-boot.** Loaded extensions and their compiled module config do not change per request. In `__construct()`, *before* the override is installed, capture native `phpinfo(INFO_MODULES)` output once per worker and cache the text. Zero per-request cost, zero recursion, no coroutine race.

### A.3 What "hybrid" means concretely (robustness over brittle parsing)

- **Modules / Extensions** → **primarily structured**: `get_loaded_extensions()` + `ini_get_all($ext)` (typed local/global/access per directive, version-stable) + `phpversion($ext)`. The boot-captured native text is parsed **leniently** only for free-form per-module rows that ini cannot reach (e.g. "GD Version", "cURL SSL backend", "libxml Version") — this is the "never miss anything" guarantee. **If a module's text block fails to parse, fall back to rendering that block verbatim in a `<pre>`** inside the module section. Graceful degradation; never a fatal.
- **General / PHP Core / Configuration** → structured: `phpversion()`, `php_uname()`, `zend_version()`, `ini_get_all()` (core directives). The **Server API** row renders `ZealPHP (OpenSwoole <ver>)`.
- **Environment / PHP Variables / HTTP Headers** → live per-request from `$g->server` / `$g->get` / `$g->post` / `$g->cookie` (coroutine-safe; not stale process globals).

### A.4 `INFO_*` flag parity

`render()` honors the bitmask exactly like native:

| Constant | Section emitted |
|---|---|
| `INFO_GENERAL` | System, build, SAPI, config-file paths |
| `INFO_CONFIGURATION` | Local/master directive values (core) |
| `INFO_MODULES` | Per-extension config + free-form details |
| `INFO_ENVIRONMENT` | `$_ENV` / `getenv()` |
| `INFO_VARIABLES` | `$g->server` / `$g->get` / `$g->post` / `$g->cookie` |
| `INFO_CREDITS`, `INFO_LICENSE` | Static blocks |
| `INFO_ALL` | All of the above |

`phpinfo(INFO_GENERAL)` emits only the General section, etc.

### A.5 Output & safety

- **Self-contained HTML document** with an embedded `<style>` block (amber-accent, consistent with the docs aesthetic), exactly like native `phpinfo()`. This is required because the override can be invoked from **any** app on ZealPHP (WordPress, Symfony, the docs site), not just our pages — it cannot depend on `public/css/zealphp.css` being present.
- **This does not violate the "no inline styles in templates" rule.** That rule governs `template/*` view files. `PhpInfo` is framework diagnostic output generated in `src/`, mirroring how real `phpinfo()` is fully self-contained. The spec records this rationale so reviewers don't flag it as an anti-pattern.
- **Every emitted value is `htmlspecialchars()`-escaped.** Env vars, headers, and cookies can contain markup; escaping prevents the diagnostics page from becoming an injection vector.
- **No gating** — always renders (mod_php parity).

### A.6 Touch list

- **New:** `src/Diagnostics/PhpInfo.php`
- **New override fn + registration:** `src/utils.php` (`\ZealPHP\phpinfo`), `src/App.php` (boot-capture + `uopz_set_return`)
- **Update:** [template/app/phpinfo.php](../../../template/app/phpinfo.php) — remove the `<pre>` wrapper; the override now emits a full HTML document and the wrapper would corrupt it.
- **Tests:**
  - `tests/Unit/PhpInfoTest.php` — `render()` returns non-empty HTML; honors `INFO_*` flags (e.g. `INFO_GENERAL` omits the Variables section); HTML-escapes injected values; includes the `ZealPHP (OpenSwoole …)` SAPI label; well-formed `<table>` structure; lenient parser fallback path produces a `<pre>` block rather than throwing.
  - Integration case (`tests/Integration/`) — `GET /phpinfo` → `200`, `Content-Type: text/html`, body contains `<table`, `PHP Version`, and `ZealPHP`.
- **PHPStan level 10:** carefully type `ini_get_all()` (`array<string, array<string, mixed>>`) and `get_loaded_extensions()` (`list<string>`) returns; guard every `mixed` before casting to `string`; no `@phpstan-ignore`, no `assert()`/inline `@var` silencing.

### A.7 Non-goals for Part A

- No change to `php_sapi_name()` / `PHP_SAPI` (roadmap item B.1).
- No auth/gating mechanism (matches mod_php).
- No new `App::phpinfoEnabled()` toggle (rejected — would diverge from mod_php parity for no requested benefit; YAGNI).

---

## Part B — Apache + mod_php parity scan (roadmap + docs deliverable)

**Governing principle (from the goal): where Apache + mod_php works, ZealPHP must work natively. Every remaining gap must be documented on the relevant docs page.**

### B.1 Parity matrix — PHP web-SAPI built-ins

| # | Feature | mod_php behavior | ZealPHP today | Status | Fix vector |
|---|---|---|---|---|---|
| 1 | `phpinfo()` | HTML tables | CLI text dump | **Closing now (Part A)** | uopz override |
| 2 | `php_sapi_name()` / `PHP_SAPI` | `apache2handler` | `cli` | **Gap — High** | uopz override → configurable SAPI string |
| 3 | `filter_input()` / `filter_input_array()` (`INPUT_GET/POST/COOKIE/SERVER`) | reads SAPI tables | reads empty CLI tables, not `$g` | **Gap — High** | uopz override reading `$g->get/post/cookie/server` |
| 4 | `$_SERVER['REQUEST_TIME']` / `REQUEST_TIME_FLOAT` | set | not populated | **Gap — Med** | `$_SERVER` builder addition |
| 5 | `$_SERVER['REQUEST_SCHEME']` | `http`/`https` | not populated | **Gap — Med** | builder; derive from HTTPS / `X-Forwarded-Proto` |
| 6 | `$_SERVER['GATEWAY_INTERFACE']` | `CGI/1.1` | not populated | **Gap — Low** | builder constant |
| 7 | `$_SERVER['REMOTE_PORT']` / `SERVER_PROTOCOL` | always set | sparse / OpenSwoole-dependent | **Gap — Low/Med** | ensure population from OpenSwoole request |
| 8 | `getenv()` / `putenv()` | per-request CGI env | process env, not request-scoped | **Gap — Med** | uopz override + per-request env table |
| 9 | `mail()` | sendmail integration | native (needs sendmail in PATH) | **Gap — Med** | override → configurable transport/callback |
| 10 | `header_register_callback()` | fires before headers flush | not overridden | **Gap — Low** | uopz override hooked into response emission |
| 11 | `get_browser()` | browscap | not overridden | **Gap — Low** | override / config (low value) |
| 12 | `error_log()` destination | Apache error log | stderr / php.ini path | **Gap — Low** | optional override → `elog()` |
| 13 | `header()` / `setcookie()` / `setrawcookie()` (incl. SameSite) | — | overridden | **Native parity ✓** | — |
| 14 | `http_response_code()` (incl. repeat calls) | — | overridden | **Native parity ✓** | — |
| 15 | `session_*()` family | — | fully overridden | **Native parity ✓** | — |
| 16 | `apache_request_headers()` / `getallheaders()` / `apache_response_headers()` / `apache_setenv/getenv/note()` | — | overridden | **Native parity ✓** | — |

### B.2 Parity matrix — php.ini directive enforcement

| Directive | mod_php | ZealPHP today | Status / plan |
|---|---|---|---|
| `post_max_size` | 413 on oversize body | not enforced (OpenSwoole `package_max_length` is the real limit) | **Gap — Med** → middleware enforcing the configured limit; document the OpenSwoole setting relationship |
| `upload_max_filesize` | rejects oversize upload | not enforced | **Gap — Med** → middleware |
| `max_input_vars` | truncates beyond limit | not enforced (default 1000 rarely hit) | **Gap — Low** → middleware (optional) |
| `max_execution_time` | kills script | no-op (`set_time_limit()` documented no-op) | **By design** → use OpenSwoole coroutine timeouts; document on relevant page |
| `default_mimetype` | fallback Content-Type | not used (CharsetMiddleware covers typical case) | **Gap — Low** → document; optional middleware |
| `auto_prepend_file` / `auto_append_file` | auto-include per request | unsupported (boot-time path; per-request context) | **By design — document workaround** (manual include at entry point / fallback handler) |

### B.3 Parity matrix — Apache server features

| Feature | mod_php / Apache | ZealPHP | Status |
|---|---|---|---|
| `virtual()` internal subrequest | internal request to URI | logs warning, returns false | **By design — document** (single-process model; refactor to `App::include()` / route call) |
| `mod_rewrite` catch-all | `.htaccess RewriteRule . /index.php [L]` | `App::setFallback()` | **Native parity ✓** |
| `DocumentRoot`, `TraceEnable`, `ServerAdmin`, `ServerName`/`UseCanonicalName`, `LimitRequest*`, `CustomLog`/`LogFormat`, trusted proxies / `X-Forwarded-For` | httpd.conf directives | `App::*` configurables | **Native parity ✓** |
| `mod_headers`, `mod_expires`, `AddCharset`, `AddType`/`ForceType`, `<FilesMatch> Cache-Control`, `AuthType Basic`+`AuthUserFile`, `Allow/Deny`/`Require ip`, Range, `mod_substitute`, `server_name` vhosts | directives | dedicated middleware | **Native parity ✓** (opt-in registration) |

### B.4 Docs publishing plan (the "add it along our Apache parity content" deliverable)

1. **New dedicated page: `template/pages/apache-parity.php`** (+ `public/apache-parity.php` 3-line render shim + nav entry in `template/_nav.php`). Content:
   - "How Apache + mod_php works" primer (request lifecycle, SAPI, per-request globals, directive model).
   - The full parity matrix from B.1–B.3, grouped by category, with an explicit **Native ✓ / Via middleware / Gap (documented) / By design** badge per row.
   - The governing rule stated up front: *where Apache+mod_php works, ZealPHP works natively; documented gaps are listed here with their workaround.*
   - Cross-links to `vs-fpm.php`, `legacy-apps.php`, `migration.php`, `middleware.php`.
2. **Per-page gap callouts** — each documented gap also gets a short note on its most relevant existing page so a reader hits it in context:
   - `max_execution_time` no-op + coroutine-timeout guidance → `coroutines.php` / `legacy-apps.php`.
   - `auto_prepend_file`/`auto_append_file` workaround → `legacy-apps.php`.
   - `virtual()` unsupported + refactor guidance → `legacy-apps.php`.
   - `post_max_size`/`upload_max_filesize` vs OpenSwoole body limit → `legacy-apps.php` / `http.php`.
   - `filter_input()` / `php_sapi_name()` caveats until override lands → `migration.php`.
   - phpinfo() HTML parity (once shipped) → `http.php` or the new parity page.
3. Standards for the docs work: **no inline `<script>`/`style=` in the new page** (extract any JS/CSS to `public/js`/`public/css` per project rules); follow the `_master.php` page convention.

### B.5 Suggested execution order (each its own spec→plan→implement cycle)

1. **phpinfo() override** (Part A) — this session.
2. **`apache-parity.php` docs page + per-page gap callouts** (B.4) — turns the scan into living docs; documents every current gap immediately, satisfying "no gaps undocumented."
3. **High-severity overrides:** `php_sapi_name()`/`PHP_SAPI`, `filter_input*()`.
4. **`$_SERVER` completeness:** `REQUEST_TIME(_FLOAT)`, `REQUEST_SCHEME`, `GATEWAY_INTERFACE`, `REMOTE_PORT`/`SERVER_PROTOCOL`.
5. **Med overrides:** `getenv/putenv` request-scoping, `mail()` transport, `post_max_size`/`upload_max_filesize` middleware.
6. **Low/optional:** `header_register_callback()`, `get_browser()`, `default_mimetype`, `error_log()` routing.

Every override item ships with: unit + integration tests, PHPStan level-10 clean, a CHANGELOG entry, and (where user-visible) a docs note. No breaking changes — all overrides are additive and default to mod_php-equivalent behavior.

---

## Testing & quality bar (applies to all parts)

- `./vendor/bin/phpunit tests/Unit/ --testdox` — green; new classes get their own test file.
- `./vendor/bin/phpunit tests/Integration/ --testdox` (server on :8080) — green; new routes/behaviors covered.
- `./vendor/bin/phpstan analyse --no-progress` — **level 10, zero errors**, no ignores/asserts/inline-var silencing.
- PSR-2; `declare(strict_types=1)` in new `src/` classes; short array syntax; docblocks on public APIs.
- Business logic in `src/` classes (the `ZealPHP\Diagnostics` namespace), not free functions in route/template files.

---

## Open questions / risks

- **Module-text parser brittleness** (accepted): mitigated by structured-first design + verbatim `<pre>` fallback. If a future PHP version reshapes `phpinfo(INFO_MODULES)` text, the structured data still renders; only free-form extras degrade to raw text.
- **Boot-capture cost**: one `ob`-buffered native `phpinfo()` per worker at construct — negligible, one-time.
- **Docs scope**: the parity page is content-heavy; B.4 may itself decompose into "page scaffold" then "fill matrix" tasks during planning.
