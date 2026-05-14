# Apache + mod_php Parity

ZealPHP targets verbatim Apache+mod_php behavior so that unmodified legacy applications (WordPress, Drupal, classic PHP) run unchanged. This document covers the parity layers: uopz function overrides, public/ file-based routing, static-file handling, and the per-coroutine isolation that keeps mod_php's process-global behaviors from leaking across concurrent requests in an OpenSwoole worker.

For error handling (custom `ErrorDocument`, `set_error_handler`, `register_shutdown_function`, content negotiation), see [error-handling.md](error-handling.md).

---

## Why parity matters

mod_php runs one PHP request per process (or per child); state is naturally isolated. OpenSwoole runs many coroutines per process — a single set of native PHP globals is shared. A naive port loses three properties legacy code silently depends on:

1. **Per-request global state.** `$_GET`, `$_SESSION`, headers, cookies all become process-shared and leak between requests.
2. **Apache-specific built-ins.** `apache_request_headers()`, `getallheaders()`, `virtual()`, `apache_setenv()` are undefined under the CLI SAPI and crash legacy code with "Call to undefined function."
3. **Per-request handler lifecycle.** `set_error_handler`, `register_shutdown_function`, `error_reporting()` are process-global in PHP — one coroutine's call leaks into every other.

ZealPHP closes all three via uopz overrides backed by per-coroutine state in `G`.

## Function overrides (uopz)

`App::__construct()` installs uopz overrides on every built-in that legacy PHP code uses to interact with the HTTP boundary or the PHP error machinery. Each override delegates to a namespaced implementation in [`src/utils.php`](../src/utils.php) that mutates per-request `G` state instead of touching process globals.

### Always present in CLI/OpenSwoole — overridden directly via uopz

| Built-in | Routes to | Backing state |
|---|---|---|
| `header()` | `\ZealPHP\header()` | `G->response_headers_list` |
| `header_remove()` | `\ZealPHP\header_remove()` | `G->response_headers_list` |
| `headers_list()` | `\ZealPHP\headers_list()` | `G->response_headers_list` |
| `headers_sent()` | `\ZealPHP\headers_sent()` | `G->openswoole_response->isWritable()` |
| `setcookie()` | `\ZealPHP\setcookie()` | `G->response_cookies_list` |
| `setrawcookie()` | `\ZealPHP\setrawcookie()` | `G->response_rawcookies_list` |
| `http_response_code()` | `\ZealPHP\http_response_code()` | `G->status` |
| `flush()` | `\ZealPHP\flush()` | writes buffer to `G->openswoole_response`, flips `G->_streaming = true` |
| `ob_flush()` / `ob_end_flush()` | namespaced equivalents | identical to `flush()` |
| `ob_implicit_flush()` | namespaced no-op | — (ZealPHP buffers per request; the call is accepted without side effect) |
| `set_time_limit()` | namespaced no-op | — (OpenSwoole owns coroutine/worker timeouts) |
| `ignore_user_abort()` | namespaced | `G->ignore_user_abort_state` |
| `connection_status()` / `connection_aborted()` | namespaced | inspects `G->openswoole_response->isWritable()` |
| `output_add_rewrite_var()` / `output_reset_rewrite_vars()` | namespaced no-op | — |
| `is_uploaded_file()` | namespaced | whitelist of `tmp_name` paths from `G->files` |
| `move_uploaded_file()` | namespaced | `is_uploaded_file()` check + rename/copy+unlink fallback |
| `session_*` (18 functions) | `\ZealPHP\Session\zeal_session_*` | `G->session`, `G->session_params` |
| `set_error_handler` / `restore_error_handler` | namespaced | `G->error_handlers_stack` |
| `set_exception_handler` / `restore_exception_handler` | namespaced | `G->exception_handlers_stack` |
| `register_shutdown_function()` | namespaced | `G->shutdown_functions` queue |
| `error_reporting()` | namespaced | `G->error_reporting_level` |

### Apache-only built-ins — conditional global shims

`apache_*` functions, `getallheaders()`, and `virtual()` are defined by PHP only under mod_php. uopz cannot override what doesn't exist, so they're defined at global namespace by [`src/apache_shims.php`](../src/apache_shims.php) — composer's `files` autoload loads it once per worker boot. Each shim is wrapped in `if (!function_exists(...))` so it's a no-op when the host SAPI already provides the real function.

| Built-in | Delegates to | Source |
|---|---|---|
| `apache_request_headers()` | `\ZealPHP\apache_request_headers()` | `G->zealphp_request->parent->header` (canonicalized) |
| `getallheaders()` | alias of above | — |
| `apache_response_headers()` | `\ZealPHP\apache_response_headers()` | `G->response_headers_list` |
| `apache_setenv()` / `apache_getenv()` | namespaced | `G->apache_env` |
| `apache_note()` | namespaced | `G->apache_notes` |
| `virtual()` | namespaced no-op (returns `false`) | logs "unsupported" once |

The CGI worker ([`src/cgi_worker.php`](../src/cgi_worker.php)) has its own self-contained set of these for legacy files run via `App::includeFile()` in superglobals mode. The subprocess runs in a fresh PHP CLI process so native `session_*` works natively there — no override needed.

### Boot order — process-level error dispatcher before uopz

`App::__construct()` installs ONE native `set_error_handler` and `set_exception_handler` **before** the first `uopz_set_return()` call. After uopz takes over, user-space `set_error_handler()` calls go to the namespaced override that records in `G`. Real PHP errors raised by the engine still flow through the bootstrap handler, which reads the current coroutine's `G` stack — giving per-coroutine isolation despite PHP's single global handler.

```php
// Top of App::__construct() — captured BEFORE uopz overrides
self::$initial_error_reporting = \error_reporting();

\set_error_handler(static function ($severity, $message, $file, $line) {
    $g = G::instance();
    $level = $g->error_reporting_level ?? App::$initial_error_reporting;
    if (!($severity & $level)) return true;          // suppressed
    $stack = $g->error_handlers_stack;
    if (!empty($stack)) {
        [$callable, $levels] = $stack[count($stack) - 1];
        if ($severity & $levels) return (bool)$callable($severity, $message, $file, $line);
    }
    return false;                                    // PHP default
});
```

`error_reporting()` is read inside this native dispatcher and is also per-coroutine — setting it in one request doesn't filter another's errors.

---

## Public/ file-based routing

ZealPHP's implicit routes in [`src/App.php`](../src/App.php) reproduce Apache's default DocumentRoot behavior, with safety hardening on top.

### Implicit route table (registered in order)

| Pattern | Resolves to | Apache equivalent |
|---|---|---|
| `/\..*` 403 | dotfile block (allow `.well-known/`) | `<FilesMatch "^\.ht"> Require all denied </FilesMatch>` + dotfile convention |
| `/.*\.php` 403 | (when `App::$ignore_php_ext = true`) reject explicit `.php` URLs | `RewriteRule \.php$ - [F]` |
| `/` | `public/index.php` | `DirectoryIndex index.php` |
| `/{file}/?` | `public/{file}.php`, then `public/{file}/index.*` | `MultiViews` + `DirectoryIndex` |
| `/{dir}/{uri}/?` | `public/{dir}/{uri}.php`, then directory index | nested directory walk |
| (fallback) | `setFallback()` handler or `renderError(404)` | `.htaccess` `RewriteRule . /index.php [L]` |

### Apache parity additions

| Apache directive | ZealPHP implementation | Flag |
|---|---|---|
| `DirectorySlash On` | `/foo` → 301 `/foo/` when foo is a directory | `App::$directory_slash = true` |
| `DirectoryIndex index.php index.html index.htm` | walks `App::$directory_index` array; `.html`/`.htm` go through `$response->sendFile()` so Range/ETag work | `App::$directory_index` (array) |
| `AcceptPathInfo On` | `/script.php/extra/path` exposes `PATH_INFO=/extra/path`, rewrites `REQUEST_URI` to `/script.php` | `App::$path_info = true` |
| `<FilesMatch "^\.">` deny | every implicit route + dedicated pattern route refuses any URL with a dotfile component | `App::$block_dotfiles = true` |
| URL-decoded traversal rejection | `parse_url + rawurldecode` checked for `..`, `\0`, backslash before route matching → 400 | always on |
| Static handler URL whitelist | OpenSwoole's `enable_static_handler` is constrained to `App::$static_handler_locations` (default: `/css`, `/js`, `/img`, `/fonts`, `/assets`, `/static`, `/favicon.ico`, `/robots.txt`) | `App::$static_handler_locations` |

The static-handler whitelist is the real defense against `/.env` or `/.git/config` being served. OpenSwoole's built-in handler doesn't honor `<FilesMatch>` rules; by restricting it to known asset prefixes, anything outside the whitelist falls through to PHP routing, where dotfile and traversal checks fire.

### `serveDirectory()` helper

[`App::serveDirectory($relDir, $urlPrefix)`](../src/App.php) is the shared body of both implicit routes' `is_dir()` branches:

1. Apply DirectorySlash 301 if URI has no trailing slash.
2. Walk `App::$directory_index` until a file is found.
3. `.php` entries run via `App::includeFile()` (supports Generator streaming).
4. Non-`.php` entries (HTML, HTM) go via `$response->sendFile()` so Range and ETag still work.
5. Return `false` if nothing matches — caller falls through to fallback / 404.

### `sendFile()` conditional GET

[`Response::sendFile()`](../src/HTTP/Response.php) emits weak ETag (`W/"mtime-size"`) plus `Last-Modified`, and honors `If-None-Match` and `If-Modified-Since` headers:

- Match on `If-None-Match: W/"...";`, `If-None-Match: *`, or `If-None-Match` stripped of weak prefix → 304.
- Match on `If-Modified-Since` ≥ file mtime → 304.
- Range request preserved (single + multi-range, RFC 7233) — but `If-Range` is honored only inside `RangeMiddleware`'s buffered path, not in `sendFile` itself.

### `App::includeCheck()` hardening

```
- abs_file must start with cwd/public  (path traversal cage)
- no path component may begin with '.' (dotfile block) unless flag disabled
```

Both checks are necessary: traversal cage prevents `/foo/../etc/passwd`-style escapes; dotfile rule prevents serving `public/wp/.env` even when the path is within document root.

---

## Static file handling

By default OpenSwoole serves static files via its built-in handler — that's why `/css/zealphp.css` works without any PHP route. The handler emits `Last-Modified` only — no ETag, no Range support. Two consequences:

- **For ETag/Range on static files**, disable `enable_static_handler` and add a wildcard PHP route that calls `$response->sendFile($abs)`. The handler in `sendFile` adds all conditional GET headers and Range handling.
- **For dotfile protection**, the `static_handler_locations` whitelist (set by ZealPHP defaults) restricts the built-in handler to safe URL prefixes. Dotfiles fall through to PHP routing where the dotfile block fires.

The trade-off: zero-copy `sendfile()` on a sub-process boundary is faster than PHP→OpenSwoole bytes, so for purely static CSS/JS/images the built-in handler is the right default. For asset CDNs and Range-heavy workloads, route through PHP.

---

## CGI subprocess for legacy apps

When `App::$superglobals = true`, `App::includeFile($path)` spawns `php cgi_worker.php $path` via `proc_open` — true global scope, native sessions, isolated request lifecycle. The CGI worker has its own uopz overrides ([`src/cgi_worker.php`](../src/cgi_worker.php)) for:

- header / header_remove / headers_list / headers_sent
- setcookie / setrawcookie / http_response_code
- flush / ob_flush / ob_end_flush / ob_implicit_flush
- is_uploaded_file / move_uploaded_file

Plus self-contained global shims for `apache_*`, `getallheaders`, `virtual` (since composer's autoloader isn't loaded in the subprocess).

**Session functions are NOT overridden in CGI** — each subprocess gets a real PHP process with native `$_SESSION` semantics. The main worker overrides 18 `session_*` functions because there it shares one PHP process across many coroutines; the CGI worker doesn't.

The CGI worker communicates a two-channel protocol back to the parent:
- **stderr line 1** = JSON metadata (status, headers, cookies) sent FIRST.
- **stdout** = body bytes streamed (supports SSE, chunked output).

The parent's `cgiInclude()` reads metadata, forwards headers/cookies to its OpenSwoole response, then proxies stdout. See `App::cgiInclude()` in [`src/App.php`](../src/App.php).

---

## Per-coroutine state isolation

| State | Per-process (mod_php) | Per-coroutine in ZealPHP via | Override site |
|---|---|---|---|
| `$_GET` / `$_POST` / `$_SERVER` / `$_SESSION` | yes | `G` proxy (superglobals=false) | `App::superglobals(false)` |
| Headers / cookies / status | yes | `G->response_headers_list` etc. | uopz overrides |
| Error handler | yes (single) | `G->error_handlers_stack` | uopz override + native bootstrap dispatcher |
| Exception handler | yes | `G->exception_handlers_stack` | uopz + dispatchRoute catch |
| Shutdown functions | per process | `G->shutdown_functions` queue | uopz + on('request') drain |
| `error_reporting()` level | per process | `G->error_reporting_level` | uopz + native dispatcher reads G |
| Apache notes / env | per process | `G->apache_env`, `G->apache_notes` | namespaced impls |

For details on the error/exception/shutdown flow specifically, see [error-handling.md](error-handling.md).

---

## Verification

Two integration test suites cover this surface:

- [`tests/Integration/ApacheParityTest.php`](../tests/Integration/ApacheParityTest.php) — `apache_request_headers`, `getallheaders`, `header_remove`, `headers_sent`, `setrawcookie`, `ob_flush`, status-line forms, `apache_setenv`/`note`, upload-file helpers, safe stubs.
- [`tests/Integration/PublicRoutingTest.php`](../tests/Integration/PublicRoutingTest.php) — DirectorySlash 301, DirectoryIndex `.html` fallback, dotfile block (root + subdir), well-known passthrough, URL-decoded traversal 400, null byte 400, PATH_INFO exposure, sendFile ETag, conditional GET (If-None-Match + If-Modified-Since), Range request 206.

Manual smoke against a running server:

```bash
curl -I http://localhost:8080/css/zealphp.css                                # ETag, Last-Modified
curl -I http://localhost:8080/parity-test/sub-dir                            # 301 → /parity-test/sub-dir/
curl -I http://localhost:8080/.env                                           # 403
curl -I 'http://localhost:8080/%2e%2e/foo'                                   # 400
curl http://localhost:8080/api.php/users/42                                  # PATH_INFO routed to api.php
```

## Out of scope

- **`trigger_error(E_USER_ERROR)` halting the script** — would require uopz on `trigger_error` itself.
- **Async-error capture** from `App::tick`/`App::after` timers — they run outside a request context, so renderError doesn't apply.
- **`ini_set('display_errors', ...)` per request** — process-wide flag; use `App::$display_errors`.
- **Apache `<Directory>` ACL semantics** — ZealPHP ships sensible defaults rather than a configurable ACL grammar.
- **`apache_child_terminate()`, `apache_lookup_uri()`, `apache_reset_timeout()`** — exotic functions; no real app uses them.
