# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

ZealPHP is a PHP web framework library built on **OpenSwoole**. This repo is the framework itself — `app.php` and `api/`, `public/`, `route/`, `template/` are the built-in demo app / OSS website that exercises every framework feature.

## Commands

Most common tasks are wrapped in a **`Makefile`** — run `make help` to list them. They're thin wrappers over the commands below and stay in lockstep with this section. The raw commands:

```bash
# Install PHP dependencies (including PHPUnit dev dep)
composer install

# Start the dev server — serves the OSS website on :8080
php app.php

# Start with explicit HTTP worker/task worker counts
ZEALPHP_WORKERS=16 ZEALPHP_TASK_WORKERS=0 php app.php

# Unit tests (no server needed)
./vendor/bin/phpunit tests/Unit/ --testdox

# Integration tests (server must be running on :8080)
php app.php &
./vendor/bin/phpunit tests/Integration/ --testdox

# All tests
./vendor/bin/phpunit --testdox

# PHPStan static analysis — LEVEL 10, MANDATORY check alongside phpunit.
# Run this whenever you add or modify code in src/; fix issues immediately
# rather than letting them accumulate to release time. CI enforces it.
./vendor/bin/phpstan analyse --no-progress

# Install system dependencies (PHP 8.3, OpenSwoole, uopz) — requires root
sudo bash setup.sh

# Verify required extensions are loaded
php -m | grep -E 'openswoole|uopz'

# Local performance sweep (defaults to 16 workers and c=1000)
scripts/bench.sh --p1000

# Dockerized benchmark sweep
mkdir -p bench/results && docker compose run --rm --build bench

# Dockerized quad-core ZealPHP vs Node.js comparison
mkdir -p bench/results && docker compose run --rm --build compare
```

---

## Testing

PHPUnit 11 test suite lives in `tests/`. `ZEALPHP_TEST_PORT` env var sets the server port (defaults to `8080`).

### Unit tests (`tests/Unit/`) — no server needed
| File | What it tests |
|------|--------------|
| `StoreTest.php` | `Store::make`, `set/get/del`, `exists`, `incr/decr`, `count`, `iterate` |
| `CounterTest.php` | `increment/decrement/byN`, `CAS`, `reset`, `raw()` |
| `BuildParamMapTest.php` | Every parameter injection case via reflection |
| `RoutePatternTest.php` | `{param}` → regex, namespace prefix, method casing |
| `CompressionMiddlewareTest.php` | Reference compression middleware gzip/proxy-skip behavior |

### Integration tests (`tests/Integration/`) — requires `php app.php`
| File | What it tests |
|------|--------------|
| `RoutingTest.php` | All 7 injection cases + route types + 404 |
| `HttpFeaturesTest.php` | 301/302/307, HEAD, OPTIONS, cookies, CORS |
| `MiddlewareTest.php` | CORS preflight, ETag + 304, OpenSwoole gzip |
| `StreamingTest.php` | Generator SSR, `stream()`, SSE events |

`tests/TestCase.php` — base class with `http()`, `get()`, `post()`, `assertStatus()`, `assertHeader()`, `assertJsonResponse()` helpers. HEAD requests use `CURLOPT_NOBODY` for correct header parsing.

---

## Development Gotchas

- **Server restart required** for changes to `route/*.php`, `app.php`, `src/Middleware/`, and `src/App.php` — these load at startup. Template and `api/` file changes take effect immediately.
- **Multiple instances**: if testing on a non-default port (e.g., 8090 via Traefik), ensure that instance is restarted too — `php app.php restart` only restarts the default port. Use `php app.php restart -p 8090`.
- **Setting cookies in middleware**: use `$g->openswoole_response->cookie()` (raw OpenSwoole response), not the uopz `setcookie()` override — the PSR-7 response chain may not propagate cookies set via the wrapper.

---

## Architecture

### Request Lifecycle

Every inbound request flows through these layers (defined across multiple files):

1. **OpenSwoole WebSocket\Server** (`src/App.php:run()`) receives the raw request. (WebSocket\Server extends HTTP\Server — all HTTP routes still work.)
2. **CoSessionManager** (`src/Session/CoSessionManager.php`) is registered as the `onRequest` handler in coroutine mode. It initialises the session, creates `ZealPHP\HTTP\Request`/`Response` wrappers, and stores them in `G::instance()`.
3. **G singleton** (`src/G.php`) is populated with `get`, `post`, `cookie`, `server`, `files`, `session`, `zealphp_request`, `zealphp_response`, etc.
4. **PSR-15 middleware stack** (`OpenSwoole\Core\Psr\Middleware\StackHandler`) is invoked via `App::middleware()->handle($serverRequest)`.
5. **ResponseMiddleware** (inner-most layer, bottom of `src/App.php`) matches the URI against the route table, resolves handler parameters by name via reflection, calls the handler, and wraps the return value as a PSR-7 response.

### G Class — Dual-Mode Global State

`G::instance()` (`src/G.php`) is the per-request global state container. Its behaviour depends on the mode:

| Mode | `G::instance()` returns | `$_GET` / `$_SESSION` etc. |
|------|------------------------|----------------------------|
| Superglobals ON | A single process-wide singleton. `$g->get` / `$g->post` / `$g->cookie` / `$g->files` / `$g->server` / `$g->request` / `$g->session` are all **live aliases** of `$_GET` / `$_POST` / … / `$_SESSION` — the per-request handler populates `$GLOBALS['_GET']` etc. then `unset()`s the declared typed slots so reads AND writes route through the `__get`/`__set` proxy by reference. They're the **same array**: mutating `$_GET['x']` after dispatch is immediately visible via `$g->get['x']` and vice versa (session since v0.2.27; the rest since v0.2.30, issue #17). | Populated per request from OpenSwoole's `$request->*`. Use them directly OR via `$g->X` — both work, both see the same data |
| Superglobals OFF | A per-coroutine instance stored in `OpenSwoole\Coroutine::getContext()` — each coroutine has isolated state | NOT populated. Process-wide writes would race across coroutines. Use `$g->X` exclusively |

The **demo app uses `superglobals(false)` (coroutine mode)**. This is the recommended default for new projects.

**Superglobal contract (v0.2.27 + v0.2.30)** — `App::superglobals(true)` now lives up to its name. Both `$_GET` / `$_SESSION` AND `$g->get` / `$g->session` are populated per request, and **every request-input superglobal is a live alias** — `$_GET ↔ $g->get`, `$_POST ↔ $g->post`, `$_COOKIE`, `$_FILES`, `$_SERVER`, `$_REQUEST`, `$_SESSION` — writes through one are visible through the other immediately. This restores v0.1.x behaviour dropped during the Dec 2024 declared-property refactor (commits `327e180` + `900c18a`). The implementation lives in three places: `src/App.php` per-request handler (populates `$GLOBALS['_GET']` family, then `unset()`s the declared `$g->get`/`post`/`cookie`/`files`/`server`/`request` slots so they proxy by reference — v0.2.30, issue #17), `src/Session/SessionManager.php` (`unset($g->session)` after `session_start()` — v0.2.27), and `src/RequestContext.php` `__get`/`__set` (symmetric superglobal-key mapping `session ↔ _SESSION` etc.). Tests pin the contract: `tests/Integration/SuperglobalsParityTest.php` (`testGetAliasMutationCrosses`, `testSessionAliasMutationCrosses`).

**Canonical `$g` vs `$_*` parity rule** — `template/pages/coroutines.php#state-parity` is the single source of truth: **use `$g->get` / `$g->post` / `$g->cookie` / `$g->server` / `$g->session`. It works identically in both modes.** In `superglobals(true)` mode the framework also populates the matching `$_*` superglobals (v0.2.27), so code reading `$_GET` works too. In `superglobals(false)` mode the superglobals are NOT populated per request (process-wide arrays — writes leak across coroutines), so only the `$g->X` form is safe. Recipes and migration examples link to `/coroutines#state-parity` rather than restating this rule.

### Lifecycle: static config → `init()` → instance routing → `run()`

ZealPHP is one-app-per-process by design — OpenSwoole's `Server` is a process singleton (once `start()` runs, the master owns the event loop and worker pool). Multi-port within a single process is supported via `addListener()`, but those listeners share the same workers, same config, same `App` instance. Truly independent apps run as separate PHP processes via the per-port PID-file CLI (`php app.php start -p 9501` vs `-p 9502`).

That architectural reality is why configuration is exposed as **static methods on `App`**, not instance methods on `$app`. The boot pattern is "configure the framework, then boot it" — config has to be set before `App::init()` returns, when the instance doesn't yet exist:

```
┌─────────────────────┐    ┌──────────────────┐    ┌────────────────────┐    ┌──────────┐
│ Static configuration│ -> │   App::init()    │ -> │  Instance routing  │ -> │  run()   │
│ App::superglobals() │    │  Creates the     │    │  $app->route(...)  │    │  Starts  │
│ App::documentRoot() │    │  singleton       │    │  $app->addMiddl…() │    │  the     │
│ App::traceEnabled() │    │  instance,       │    │  $app->setFallba…()│    │  server  │
│ App::ignorePhpExt() │    │  binds host/port │    │  $app->ws(...)     │    │  loop    │
│ etc.                │    │                  │    │  etc.              │    │          │
└─────────────────────┘    └──────────────────┘    └────────────────────┘    └──────────┘
```

**API convention — fluent getter/setter methods.** Configurable options follow the `App::superglobals()` precedent: a no-arg call returns the current value; a one-arg call sets it. Backing static properties stay public for BC (existing `App::$ignore_php_ext = false` style code keeps working), but the documented API and the website's example code use the method form throughout. The AI config converter agent also emits the method form. Don't add instance-method shims that delegate to static — the static surface matches the process-singleton reality.

### Lifecycle modes — `superglobals` × `processIsolation` × `enableCoroutine` × `hookAll`

Historically `App::superglobals()` bundled four decisions into one flag. As of v0.2.23, each is exposed as its own fluent setter so users can mix-and-match for their workload. Each new knob defaults to `null` and resolves to "follow `App::$superglobals`" at `App::run()` time — apps that don't touch them see no behaviour change.

| Knob | Setter | `null` resolves to | What it controls |
|------|--------|--------------------|------------------|
| `App::$superglobals` | `App::superglobals(bool)` | — (no default) | `$g` storage strategy: process-wide PHP superglobals (true) vs per-coroutine `RequestContext` (false). Also picks `SessionManager` (true) vs `CoSessionManager` (false). |
| `App::$process_isolation` | `App::processIsolation(bool)` | `$superglobals` | `App::include()` dispatch: true → `cgi_worker.php` subprocess per file (Apache mod_php-style isolation, ~30-50 ms `proc_open` cost); false → in-process via `executeFile()` |
| `App::$enable_coroutine_override` | `App::enableCoroutine(bool)` | `!$superglobals` | OpenSwoole's `enable_coroutine` server setting — auto-coroutine-per-request wrapper. false → workers handle one request at a time synchronously |
| `App::$hook_all_override` | `App::hookAll(bool\|int)` | `!$superglobals` (HOOK_ALL or 0) | `OpenSwoole\Runtime::enableCoroutine($flags)` — process-wide PHP I/O hooks (curl, fopen, mysqli). PDO is **not** hooked in OpenSwoole 22.1 / 26.2 regardless |

**Supported mode matrix:**

| Mode | `superglobals` | `processIsolation` | `enableCoroutine` | `hookAll` | When to use |
|------|---------------|---------------------|--------------------|-----------|-------------|
| **Legacy CGI** (`superglobals(true)` default) | true | true | false | 0 | Unmodified WordPress / Drupal — `define()`-heavy plugins need fresh process per request |
| **Coroutine** (`superglobals(false)` default) | false | false | true | HOOK_ALL | Modern apps benefiting from concurrent coroutine I/O; OpenSwoole-native code |
| **Mixed-mode / Symfony** | true | **false** | false | 0 | Symfony / Laravel on ZealPHP — real `$_SESSION` needed, but no per-include CGI fork cost. Sequential request handling per worker → no race risk on superglobals |
| **In-process + sync** | true | false | false | 0 | Same shape as Mixed-mode — the "scheduler off, no CGI" combo |
| **Coroutine without HOOK_ALL** | false | false | true | 0 | Per-request coroutine isolation but no auto I/O hooks (e.g. testing, custom hooks) |

**Unsafe combinations** — `App::run()` **throws `RuntimeException` at boot** (v0.2.27+). Pre-v0.2.27 these emitted a `[lifecycle]` warning to `debug.log` but didn't refuse; in practice the warning was invisible and the race-prone configuration is how cross-request state-leak bugs ship to production. Fail loud, fail fast:

- `superglobals(true) + enableCoroutine(true)` — process-wide `$_GET`/`$_POST`/`$_SESSION` arrays would race across concurrent coroutines (this is exactly the bug per-coroutine `$g` was designed to avoid).
- `superglobals(true) + hookAll(non-zero)` — hooked I/O can yield mid-request, exposing process-wide superglobal mutations to other coroutines.

Apps that need to run one of these for security-audit / debugging purposes can fork and remove the throw at `App::validateLifecycleCombination()`. The supported matrix above covers every safe configuration.

The default coupling — `null` everywhere — preserves the historical behaviour for any app that doesn't touch these knobs. The [zealphp-symfony](https://github.com/sibidharan/zealphp-symfony) bridge uses `superglobals(true) + processIsolation(false) + sessionLifecycle(false)` to get the Mixed-mode lifecycle.

### uopz Function Overrides

At startup (`src/App.php:__construct()`), `uopz_set_return()` permanently replaces PHP built-ins:

- `header()`, `headers_list()`, `setcookie()` (+ `$samesite` param), `http_response_code()` → implementations in `src/utils.php` that write to `$g->zealphp_response`
- All `session_*()` functions → implementations in `src/Session/utils.php` that read/write `$g->session` and file-based session storage in `/var/lib/php/sessions`
- **Exec family** (when `App::hookExec()` resolves to `true` — default-on in coroutine mode): the **backtick operator**, `shell_exec`, `exec`, `system`, `passthru` → route through `App::exec()` for coroutine-safe shelling-out (the backtick compiles to a `shell_exec()` call, so overriding `shell_exec` intercepts it transparently). `proc_open` / `popen` are intentionally NOT overridden — `App::rawExec()` and the CGI subprocess path rely on `proc_open`, so leaving it untouched keeps the fallback recursion-safe. Toggle with `App::hookExec(bool)`; same override family as `header()` / `session_*()`. See the Coroutine-safe exec entry under the file-execution family below.

This lets legacy PHP code call `header()`, `session_start()`, or a backtick shell-out unchanged while the framework routes those calls to the correct per-request objects (and, for exec, to the coroutine scheduler instead of blocking the worker).

**Session unserialize whitelist (v0.2.26, issue #15)** — all four `unserialize()` calls in `src/Session/utils.php` pass `['allowed_classes' => ['stdClass']]`. Scalars and arrays pass through normally. `stdClass` round-trips as a live instance (it has zero magic methods, no gadget surface — makes `json_decode($x)` results storable in `$_SESSION` without breakage). Every other class is refused — read back as `__PHP_Incomplete_Class`. Adding any class to the whitelist requires reviewing its `__wakeup` / `__unserialize` / `__destruct` magic methods first; the function-level docblock at `php_session_decode_to_array()` documents the constraint. Canonical user-facing doc: `template/pages/sessions.php#objects-in-session`.

### IOStreamWrapper

`src/IOStreamWrapper.php` replaces the `php://` stream wrapper (registered once per worker in `workerStart`). When code reads `php://input`, the wrapper instead returns `$g->zealphp_request->parent->getContent()`. Other `php://` streams are delegated to the original wrapper.

### Route Registration and Priority

Routes are registered in this order inside `App::run()` (earlier = higher priority):

1. Files from `route/*.php` (loaded at startup via `glob`)
2. Explicit routes defined in `app.php` before `$app->run()` is called
3. Implicit API routes: `nsPathRoute('api', ...)` → delegates to `ZealAPI::processApi()`
4. `.php` extension block (returns 403)
5. Implicit public file routes: `/` → `public/index.php`, `/{file}` → `public/{file}.php`, `/{dir}/{uri}` → `public/{dir}/{uri}.php`

**API handler naming rule**: `api/users/get.php` must define `$get = function(...)`. The variable name must match `basename($file, '.php')`. ZealAPI binds it as a closure with `$this` set to the `ZealAPI` instance.

**ZealAPI auth hooks (v0.2.25, issue #13)** — `$this->isAuthenticated()`, `$this->isAdmin()`, `$this->getUsername()`, and the composite `$this->requirePostAuth()` are not hardcoded. They consult three optional callbacks registered on `App`:

- `App::authChecker(?callable)` — `fn(): bool`, consumed by `isAuthenticated()`, default `false` (fail-closed).
- `App::adminChecker(?callable)` — `fn(): bool`, consumed by `isAdmin()`, default `false`.
- `App::usernameProvider(?callable)` — `fn(): ?string`, consumed by `getUsername()`, default `null`.

Wire them ONCE during boot — either in the user's `app.php` for single-app deployments, or in a platform wrapper's bootstrap (labs/Symfony bundle/etc.) so every downstream app inherits the answers without per-app glue. The framework deliberately doesn't ship a default checker — ZealPHP doesn't know about your auth system. See `template/pages/api.php#auth-hooks` for the canonical doc, `template/pages/learn/auth.php#wire-zealapi` for a worked example, and `tests/Unit/ZealApiAuthHooksTest.php` for the 15 behaviour cases pinned (defaults, callback round-trip, coercion edges, independence of the three hooks, setter introspection).

### Parameter Injection

`ResponseMiddleware` uses reflection (cached at route registration via `buildParamMap()`) to inject handler arguments by name:

| Parameter name | Injected value |
|---------------|---------------|
| `$request` | `ZealPHP\HTTP\Request` wrapper |
| `$response` | `ZealPHP\HTTP\Response` wrapper |
| `$app` | `ResponseMiddleware` instance |
| `{param}` names | Matched URL segments |
| Any other name with default | PHP default value |

Reflection is cached per route at registration time — zero reflection overhead per request.

### Middleware Stack Order

`addMiddleware()` appends to `$middleware_wait_stack`. In `run()`, that array is **reversed** before being added to `StackHandler`. Result: the last-added middleware executes first (outermost wrap), `ResponseMiddleware` always runs innermost.

**Built-in middleware** (all in `src/Middleware/`):
- `CorsMiddleware` — CORS preflight (OPTIONS + Origin) + `Access-Control-*` headers on every response
- `ETagMiddleware` — `W/"md5"` ETag on GET, returns 304 on `If-None-Match` match
- `CompressionMiddleware` — reference gzip/deflate implementation for apps that disable OpenSwoole `http_compression`; the demo app does not register it
- `RangeMiddleware` — RFC 7233 Range requests: `Accept-Ranges: bytes`, 206 single/multi-range, 416 unsatisfiable, `If-Range` ETag support
- `BodySizeLimitMiddleware` — rejects oversized request bodies with `413 Content Too Large`. nginx `client_max_body_size` / Apache `LimitRequestBody` / PHP `post_max_size` parity.
- `SessionStartMiddleware` — eagerly starts a session and sends `Set-Cookie` for new visitors. `CoSessionManager` only starts sessions when a `PHPSESSID` cookie already exists (returning visitors); without this middleware, first-time visitors get no session cookie and session state resets every request. The `secure` flag auto-detects HTTPS (via `X-Forwarded-Proto`, `HTTPS`, or port 443) — works behind Traefik/Nginx and on direct HTTP. Override with `ZEALPHP_SESSION_SECURE` env var.
- `IniIsolationMiddleware` — snapshots `ini_set()` changes per request and restores them on exit. Opt-in defense against ini-value leakage across requests on long-running workers (`ZEALPHP_INI_ISOLATE=1` or explicit registration).
- `CharsetMiddleware` — auto-appends `; charset=utf-8` (or `App::$default_charset`) to text-ish response `Content-Type` values. Apache `AddDefaultCharset` / `AddCharset` parity.
- `CacheControlMiddleware` — extension-keyed `Cache-Control: max-age=N, public` for static assets. Apache `<FilesMatch> Header set Cache-Control` parity.
- `ExpiresMiddleware` — adds legacy `Expires:` header by content type. Apache `mod_expires` (`ExpiresActive`, `ExpiresByType`, `ExpiresDefault`) parity.
- `HeaderMiddleware` — declarative response-header manipulation: `set/add/unset` with conditional variants. Apache `mod_headers` (`Header set / append / unset / add / merge`) parity.
- `RequestHeaderMiddleware` — declarative request-header `set/add/unset/edit` written into `$g->server` using the `HTTP_<NAME>` CGI convention, so handlers see the modified headers. Apache `mod_headers RequestHeader` parity.
- `ContentEncodingMiddleware` — sets response `Content-Encoding` from request URL suffixes (e.g. `.gz` → `Content-Encoding: gzip`). Apache `mod_mime AddEncoding` parity.
- `ContentLanguageMiddleware` — sets response `Content-Language` from request URL suffixes (e.g. `page.en.html` → `Content-Language: en`); multi-suffix lists accumulate comma-joined. Apache `mod_mime AddLanguage` parity.
- `BasicAuthMiddleware` — HTTP Basic Auth via htpasswd file or callback verifier. Apache `AuthType Basic` + `AuthUserFile` + `Require`, nginx `auth_basic` parity.
- `IpAccessMiddleware` — CIDR allow/deny lists with allow-first / deny-first ordering. Apache legacy `Allow from` / `Deny from`, modern `Require ip` parity. Pair with `App::clientIp()` for correct client-IP behind a proxy.
- `RefererMiddleware` — hotlink protection: refuses requests whose `Referer` header isn't in the allowed set with `403 Forbidden`. nginx `valid_referers` / `$invalid_referer` parity.
- `RateLimitMiddleware` — sliding-window request rate limiter backed by `Store` (cross-worker shared state). Returns 429 + `Retry-After`. nginx `limit_req` parity.
- `ConcurrencyLimitMiddleware` — in-flight concurrent-request cap backed by `Counter`. Returns 503 when full. nginx `limit_conn` parity.
- `BlockPhpExtMiddleware` — refuses `*.php` URLs with 404 for apps that want extensionless URLs as the only public surface. Apache `RewriteRule \.php$ - [F]` parity.
- `MergeSlashesMiddleware` — collapses runs of consecutive slashes in the request path to a single slash before routing (internal rewrite, no redirect). Apache `MergeSlashes On` / nginx `merge_slashes` parity.
- `MimeTypeMiddleware` — sets/overrides `Content-Type` on non-static responses by URL extension or pattern. Apache `AddType` / `ForceType` parity.
- `BodyRewriteMiddleware` — single-line regex substitution on response body. Apache `mod_substitute` parity (multi-line variants on the roadmap).
- `SetEnvIfMiddleware` — sets request "environment" variables in `$g->server` when an attribute (`Remote_Addr`, header, URI, etc.) matches a regex. Apache `mod_setenvif` parity.
- `RedirectMiddleware` — declarative URL redirects (prefix + regex shapes, first match short-circuits). Apache `mod_alias` (`Redirect` / `RedirectMatch`) parity.
- `ReturnMiddleware` — unconditionally returns a fixed status/body (handler never runs); pair with `ScopedMiddleware` for path-scoped responses. nginx `return` directive parity.
- `ScopedMiddleware` — wraps another middleware so it runs only when the request path matches a literal prefix, regex, or files predicate. Apache `<Location>` / `<LocationMatch>` / `<Files>` container parity.
- `HostRouterMiddleware` — dispatches per-host routes inside one ZealPHP instance. nginx `server_name a.com b.com` parity; for true isolation prefer one process per host behind a real proxy.

**Server-level configurability** (Apache `httpd.conf` parity — static `App::$*` properties + fluent setters, set BEFORE `App::init()`):
- `App::$document_root` (default `'public'`) + `App::documentRoot()` — Apache `DocumentRoot` equivalent.
- `App::$trace_enabled` (default `false`, security-first) + `App::traceEnabled()` — Apache `TraceEnable Off`. XST defence.
- `App::$default_charset` (default `'utf-8'`) + `App::defaultCharset()` — consumed by `CharsetMiddleware`.
- `App::$strip_trailing_slash` + `App::stripTrailingSlash()` — inverse of `App::$directory_slash`; 301 non-directory URIs ending in `/` to the no-slash form.
- `App::$server_admin` + `App::serverAdmin()` — Apache `ServerAdmin`; surfaced on built-in 500 error pages.
- `App::$canonical_name` + `App::$use_canonical_name` + `App::canonicalHost()` — Apache `ServerName` + `UseCanonicalName`; controls host source for absolute redirects.
- `App::$hostname_lookups` (default `false`) — Apache `HostnameLookups`; reverse-DNS populates `$g->server['REMOTE_HOST']`. Off by default (perf cost).
- `App::$trusted_proxies` (CIDR list) + `App::clientIp()` — walks `X-Forwarded-For` only if `REMOTE_ADDR` is in the trusted list. **Critical for production deploys behind Traefik / Caddy / nginx.**
- `App::$access_log_format` — Apache `LogFormat` / `CustomLog` equivalent. Tokens: `%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i" %D` and friends.
- `App::$limit_request_fields`, `App::$limit_request_field_size`, `App::$limit_request_line` — Apache `LimitRequestFields` family.
- `App::tryInclude($publicPath)` — variant of `App::include()` that returns `null` (instead of `403`) when the file is missing, so callers can chain extension-resolver patterns without conflating "not found" with "security violation".

### HTTP Protocol Features

| Feature | How |
|---------|-----|
| HEAD method | Auto-mapped to GET in `ResponseMiddleware`; body stripped, `Content-Length` preserved |
| OPTIONS method | Returns 204 + `Allow:` header listing all methods for that URI |
| Redirects 301/307/308 | `$response->redirect($url, $status)` |
| Cookie SameSite | `setcookie()` override accepts `$samesite` param |
| Gzip compression | OpenSwoole `http_compression` is enabled by default in `App::run()`; do not also register `CompressionMiddleware` |
| Range requests | `RangeMiddleware` for buffered responses (single + multi-range, RFC 7233); `$response->sendFile()` for zero-copy file serving with Range; streaming paths send `Accept-Ranges: none` |
| HTTP/2 | Pass `'enable_http2' => true` to `$app->run()` (requires TLS) |

### SSR Streaming

Four streaming patterns via `src/HTTP/Response.php` and `ResponseMiddleware`:

| Pattern | How | When to use |
|---------|-----|-------------|
| **Generator `yield`** | Return `\Generator`; each `yield $string` sent immediately | SSR — stream HTML shell, yield sections as coroutines resolve |
| **`App::renderStream()`** | Returns `\Generator`; template declares params, framework injects by name | Streaming from template files — compose with `yield from` |
| **`$response->stream($fn)`** | `$fn` receives `$write(string)` closure; headers flushed before `$fn` runs | Fine-grained streaming control |
| **`$response->sse($fn)`** | `$fn` receives `$emit($data, $event, $id)` — formats SSE wire protocol | Server-Sent Events for JS `EventSource` |

### File-execution family

The first four methods share a single private core (`App::executeFile()`) that runs the file, captures output, and applies the universal return contract. They differ only on (a) path resolution and (b) what the wrapper does with the result. The fifth — `App::fragment()` (v0.2.24) — runs *inside* a template and marks a named region the framework can extract by name. Canonical reference: `template/pages/templates.php#file-execution-family`.

| Method | Path resolved from | Returns | Notes |
|--------|--------------------|---------|-------|
| `App::render($tpl, $args)` | `template/` (with `.php` suffix) | `mixed` — full return contract | **BC:** templates with no explicit `return` (the pattern in every `public/*.php`) have their captured output echoed back — every existing `App::render('_master', …)` call site keeps working unchanged. Explicit returns (int/array/string/Generator/Closure) flow back to the caller without echo. |
| `App::renderToString($tpl, $args)` | `template/` | `string` | Coerces every shape (Generator consumed, Closure invoked, scalar cast). |
| `App::renderStream($tpl, $args)` | `template/` | `\Generator` | Yields whatever the template returned, chunk-by-chunk. |
| `App::include($publicPath, $args = [])` | `public/` (Apache document-root convention — leading `/` optional) | `mixed` — full return contract, never echoed | Apache parity: auto-populates `$_SERVER['PHP_SELF']`, `SCRIPT_NAME`, `SCRIPT_FILENAME` for the included file (mod_php does the same). Applies `includeCheck()` so traversal outside `public/` is refused (returns `403` via the universal contract). In `superglobals(true)` mode dispatches to the CGI subprocess; in coroutine mode runs in-process. `App::includeFile()` is the deprecated alias. |
| `App::fragment($name, $fn)` (v0.2.24) | N/A — called *inside* a template, not on a path | `void`. The closure's return rides the full return contract when the fragment is extracted (the parent `App::render()` propagates it back through `ResponseMiddleware`). | The htmx-essay "template fragment" pattern. Mark named regions inside any template; `App::render('page', $args)` either runs every `App::fragment()` inline (no selector → full page) or extracts just one region (`$args['fragment'] = 'name'`). State carried in `$g->memo['_fragment']` (save+restored across nested renders). Missing fragment → HTTP 404 — no silent fallback. First match wins on repeated names. |

The 4 implicit-route call sites in `src/App.php` (`serveDirectory()`, implicit `/`, implicit `/{file}`, implicit `/{dir}/{uri}`) all collapse to one-line `return App::include('/...')` calls — `include()` owns the `$_SERVER` preamble and the result-coercion shape that `ResponseMiddleware` consumes.

### Template Rendering

`App::render() / renderToString() / renderStream()` are three of the five members of the [file-execution family](#file-execution-family) — see that table for the full method comparison (the other two are `App::include()` for `public/`-rooted files and `App::fragment()` for in-template named regions).

**Streaming templates** — template returns a Closure with named parameters; framework injects by name (same as route handlers):

```php
// template/users/stream.php — streaming template
<?php return function($users, $page = 1) {
    yield "<section>";
    foreach ($users as $user) {
        yield "<div>{$user->name}</div>";
    }
    yield "</section>";
};

// Route handler — compose streams
$app->route('/users', fn() => (function() {
    yield from App::renderStream('shell-open', ['title' => 'Users']);
    yield from App::renderStream('users/stream', ['users' => User::all()]);
    yield from App::renderStream('shell-close');
})());
```

`renderStream()` supports three template styles:
1. `return function($var) { yield ...; };` — Closure with param injection (cleanest)
2. `return (function() use ($var) { yield ...; })();` — IIFE Generator (explicit)
3. Regular echo template — captured output yielded as one chunk

**Universal return contract** — one contract, every entry point. Route handler, fallback, error handler, `App::render() / renderToString() / renderStream() / include()`, public file, API closure, streaming-template Closure — every one of them rides the same return-shape mapping. Canonical home: `template/pages/responses.php#return-contract`. This table mirrors that one **verbatim** — any change to return-value handling MUST update both in lock-step. The shared private core that implements this is `App::executeFile()`.

| The handler / file does | Core sees | ResponseMiddleware emits |
|-------------------------|-----------|--------------------------|
| `echo "html"; // no explicit return` | `"html"` (buffered) | 200 + HTML body |
| `return 404;` | `404` (int) | 404 status, empty body |
| `return ['ok' => true];` | `['ok' => true]` (array) | 200 + JSON (`Content-Type: application/json`) |
| `return "explicit html";` | `"explicit html"` (string) | HTML body |
| `echo "shell"; return "body";` | `"shellbody"` (concatenated) | HTML body (wire order preserved) |
| `return (function() { yield ...; })();` | `\Generator` | SSR stream — each `yield` flushed |
| `return function($req) { yield ...; };` | `\Closure` (param-injected when invoked) | SSR stream after invocation |
| `echo "header"; return (function() { yield ...; })();` | `\Generator` wrapping `"header"` + delegated yields | Streamed in source order |
| `return new Response($body, 200);` | `ResponseInterface` | PSR-7 response used directly (output buffer ignored) |

**Valid HTTP status codes.** When the contract says `int = HTTP status`, the int must be in the range **100–599** (RFC 7230). ZealPHP supports every IANA-registered code in that range, including the long-tail ones (`418`, `421`, `423`, `425`, `451`, `507`, `511`, etc.).

| You return | What happens |
|------------|--------------|
| `return 0;` / `-1;` / `42;` / `999;` (out of range) | Coerced to **500 Internal Server Error** with a warning logged via `elog()`. Matches Apache HTTP server behaviour. Grep `/tmp/zealphp/debug.log` (or `ZEALPHP_DEBUG_LOG`) for `Invalid HTTP status code returned:` to surface these in production. |
| `return 1;` (special case) | PHP's `include` returns `1` by default when a file has no explicit `return`. Inside `App::include() / render() / renderToString() / renderStream()`, a `1` return is treated as "no explicit return" — the framework surfaces the buffered echo as the response body instead of trying to set HTTP status 1. The same return from a plain route handler DOES get treated as a status. If you ever explicitly mean "return 1 as a status," return `100` instead. |
| `return null;` | "No status override, no body override" — the response defaults to `200` with whatever body the framework computed. |
| 600–999 | Technically in RFC 7230's three-digit range but have no defined meaning. Currently pass through without 500 coercion. |

Non-standard codes have empty reason phrases on the wire (`HTTP/1.1 451\r\n` without "Unavailable For Legal Reasons"). Browsers don't display reason phrases, so this is cosmetic. Canonical home: `template/pages/responses.php#status-range`.

**Rescuing codes OpenSwoole's native list rejects.** OpenSwoole 22.1.5's single-arg `$response->status($code)` silently downgrades certain IANA codes (notably **425 Too Early** and **451 Unavailable For Legal Reasons**) to `HTTP/1.1 200 OK` — its C-side whitelist predates RFCs 8470 and 7725. The framework works around this internally via `App::emitStatus()` which uses the **two-arg** form `$response->status($code, $reason)`, threading the IANA reason phrase from `REASON_PHRASES`. Every IANA-registered status in 100–599 emits correctly. Wired in at the response-emission boundary in `App::run()`'s OnRequest handler (one place — not in user-facing API). Niche nginx-extension codes (444 Connection Closed Without Response, 499 Client Closed Request) aren't in `REASON_PHRASES` and may still downgrade; add them if your app needs them.

**Yield from everywhere** — Generators work in all contexts:
- Route handlers: `return (function() { yield ...; })();`
- Public files: `public/feed.php` returns a Generator → framework streams it
- API handlers: `$get = function() { return (function() { yield ...; })(); };`
- Templates via `renderStream()`: `return function($items) { yield ...; };`
- Files dispatched via `App::include()`: same — the file's `return` value flows through the same contract.

`$g->_streaming = true` is set by `stream()`/`sse()` so `ResponseMiddleware` knows to skip `ob_get_clean()`.

### Legacy App Support (CGI Worker)

`App::include($publicPath, $args = [])` is one of the five members of the [file-execution family](#file-execution-family). It runs PHP files from `public/` through the framework:
- **Coroutine mode** (`superglobals(false)`): in-process via the shared `App::executeFile()` core.
- **Superglobals mode** (`superglobals(true)`): dispatches to a CGI subprocess via `proc_open` for true global-scope isolation. This is how unmodified WordPress/Drupal runs on ZealPHP.

In both modes the file's return value flows back through the universal return contract — `return 404;` sets the status, `return ['ok'=>true];` emits JSON, `return (function(){ yield ...; })();` streams. The subprocess path serialises the return value over the stderr metadata channel.

`App::includeFile()` is kept as a deprecated alias for `App::include()` — the WordPress showcase and existing scaffolds still call it. No runtime warning; the rename is surfaced in CHANGELOG.

`App::setFallback(callable)` registers a catch-all handler for unmatched routes — replaces Apache's `.htaccess` `RewriteRule . /index.php [L]`.

**CGI worker** (`src/cgi_worker.php`) captures `header()`, `setcookie()`, `setrawcookie()`, `header_remove()`, `headers_list()`, `http_response_code()`, `headers_sent()` via uopz. SSE streaming works in CGI mode via `flush()` override. As of v0.2.20, also captures the included file's return value and threads it back as JSON metadata so the universal return contract works across the process boundary too (Closure-return param injection is the one documented limitation — reflection doesn't survive the pipe).

**Custom CGI backends — host any language, in ALL modes.** `App::cgiMode('proc'|'fork'|'fcgi')` sets the strategy for `.php`. To serve other languages register a per-extension backend: `App::registerCgiBackend('.py', ['mode'=>'proc'|'fcgi', 'interpreter'=>'/usr/bin/python3', 'exec_paths'=>['/cgi-bin'], 'address'=>'127.0.0.1:9001', 'fcgi_params'=>[...]])`. CGI dispatch is **no longer gated on process-isolation** — a registered non-`.php` extension is dispatched through its backend in **coroutine mode too** (the `proc` path uses coroutine-aware `proc_open` / `Coroutine\System::exec()` that yields, supports POST stdin, and streams; the `.php` fast path is unchanged). The interpreter's RFC 3875 CGI response (headers + blank line + body, `Status:` pseudo-header) is read off stdout via `App::cgiInterpreterResponse()` — Apache `mod_cgi` parity.
- **`exec_paths` = the ExecCGI scope** (Apache `Options +ExecCGI` default-off parity): a registered extension only executes under those URL prefixes. A stray/uploaded file outside the scope is NEITHER executed NOR served as source — returns **403**.
- **`App::cgiScriptAlias('/cgi-bin', ['mode'=>'proc'])`** — Apache `ScriptAlias` parity: any file under the prefix is executable regardless of extension. URL-level implicit routing IS wired — `GET /cgi-bin/anything.sh` runs `public/cgi-bin/anything.sh` via the alias's backend with no per-extension registration required. A parallel loop next to the per-extension implicit-route loop registers a `patternRoute('#^/<prefix>/(?P<rest>.+?)/?$#', ...)` for every `cgiScriptAlias()`; `App::include()` still applies the ExecCGI gate via `resolveCgiBackend()`.
- **Implicit URL parity:** implicit routes are registered per-registered-extension, so `GET /cgi-bin/report.py` runs `public/cgi-bin/report.py` via the `.py` backend with no explicit route.
- **`App::resolveCgiBackend($absPath, $urlPath)`** returns `['backend'=>[...], 'mayExecute'=>bool]` — `mayExecute` is the ExecCGI gate (true under a ScriptAlias prefix or a backend's `exec_paths`; false → 403). Canonical docs: `docs/fastcgi-backends.md`, `template/pages/legacy-apps.php#cgi-backends`.

**Coroutine-safe exec.** `App::exec(string $cmd, ?float $timeout=null): array{output,code,signal}` — yields via `Coroutine\System::exec()` inside a coroutine, falls back to blocking `App::rawExec()` (built on `proc_open`, recursion-safe) outside one. `App::rawExec(string $cmd): ?string` is the explicit blocking escape hatch. `App::hookExec(?bool)` / `App::$hook_exec` (default-on in coroutine mode) toggles the uopz override of the backtick / `shell_exec` / `exec` / `system` / `passthru` family — see the uopz Function Overrides section above.

### CLI Management

```
php app.php                        # Start with defaults (port 8080)
php app.php start -p 9501 -d      # Start daemonized on port 9501
php app.php stop                   # Stop default server (port 8080)
php app.php stop -p 9501          # Stop server on port 9501
php app.php restart                # Stop + restart
php app.php status                 # Check if running (shows pid + port)
php app.php status -p 9501        # Check server on port 9501
php app.php logs                   # Tail all log files (Ctrl+C to stop)
php app.php logs --access          # Tail only access.log
php app.php logs --access --debug  # Tail access + debug logs
php app.php --help                 # All options
```

Flags: `-p PORT`, `-H HOST`, `-w WORKERS`, `-d` (daemonize), `--task-workers N`, `--pid-file PATH`

Log filters: `--access`, `--debug`, `--server`, `--zlog` (use with `logs` command, combine to tail specific logs)

PID files: `/tmp/zealphp/zealphp_{port}.pid` — one per port, supports multiple apps on different ports. Use `-p` with `stop`/`status`/`restart` to target a specific instance.

Duplicate-start detection: if a server is already running on the same port, `start` (or bare `php app.php`) prints the PID and exits cleanly instead of crashing.

Log files default to `/tmp/zealphp/` — `access.log`, `debug.log`, `zlog.log`, `server.log`. All configurable via `ZEALPHP_*` env vars. Logging is fully async via coroutine channels (zero request impact).

The shell script `scripts/zealphp.sh` is an optional higher-level wrapper. All commands work directly via `php app.php`.

### WebSocket

`App::ws($path, $onMessage, $onOpen, $onClose)` registers a WebSocket endpoint.

- Server switched from `HTTP\Server` to `WebSocket\Server` (backward-compatible; all HTTP routes still work)
- Per-worker `$wsFdMap` tracks `fd → path`; cleaned up in `onClose`
- `onMessage` handler **silently drops PING (9), PONG (10), CONTINUATION (0)** frames — only TEXT (1) and BINARY (2) reach route handlers
- `onShutdown` sends WebSocket CLOSE frame 1001 (Going Away) to all connections
- `App::onWorkerStart(callable $fn)` — register per-worker startup hook (timers, warmup, etc.)
- `App::onWorkerStop(callable $fn)` — register per-worker shutdown hook; runs in the worker before exit (recycle/graceful/reload), the reliable place to flush per-worker state (fires on OpenSwoole's signal-driven stop, unlike `register_shutdown_function`)
- `getClientList` must be paginated in chunks of 100 (OpenSwoole hard limit)

### OpenSwoole Adapters

**`Store` (`src/Store.php`)** — `OpenSwoole\Table` wrapper for cross-worker shared memory.
- Must be created **before** `$app->run()` (master process, shared on fork)
- `Store::make($name, $maxRows, $columns)` — column types: `TYPE_INT`, `TYPE_FLOAT`, `TYPE_STRING`
- `Store::set/get/del/exists/incr/decr/count/table/names()`

**`Counter` (`src/Counter.php`)** — `OpenSwoole\Atomic` wrapper for lock-free cross-worker integer.
- Must be created before `$app->run()`
- `increment($by=1)`, `decrement($by=1)`, `get()`, `set()`, `reset()`, `compareAndSet($expected, $new)`

**Pluggable backends (v0.2.39)** — `Store` and `Counter` are backend-agnostic. `Store::defaultBackend('redis')` (or env var `ZEALPHP_STORE_BACKEND=redis`) flips storage from local `OpenSwoole\Table`/`Atomic` to a Redis/Valkey cluster — every existing `Store::make/set/get/incr/count` and `new Counter(N)` call works unchanged. Use it when you need **cross-node shared state** or **persistence across restarts**. The Table default stays the hot-path choice (nanosecond reads, lock-free); flip to Redis only when you actually need the cross-node guarantee.
- **API surface** — `Store::TYPE_INT/_FLOAT/_STRING` (backend-neutral; old `OpenSwoole\Table::TYPE_*` still works for BC). `Store::defaultBackend(?string $kind, string|array $conn)` and `Counter::defaultBackend(...)`. Connection accepts a Redis URL string (`redis://[:pass@]host:6379/0`, `valkey://...` accepted as alias, unix sockets supported) or an array `['url'=>..., 'pool_size'=>8, 'prefix'=>'zealstore']`; defaults read `ZEALPHP_REDIS_URL` env (default `redis://127.0.0.1:6379`).
- **Client lib** — auto-detects phpredis (preferred when `ext-redis` is loaded) or predis (pure-PHP fallback; shipped as a dev dep). The single `ZealPHP\Store\RedisClient` adapter is the only place either lib is referenced; user code never imports a phpredis/predis symbol.
- **Concurrency** — `RedisConnectionPool` is a per-worker `Coroutine\Channel` of N (default 8) clients; two coroutines sharing one socket would interleave RESP frames and corrupt the stream, so each op acquires a private client. Outside a coroutine (sync mode), a size-1 sequential fallback is used.
- **Two modes per Redis table** (chosen at `make()`): `'tracked'` (default) keeps a `{prefix}:{table}:__keys__` SET so `count()` is O(1) `SCARD` and `iterate()` is `SSCAN`; **no TTL** (expired keys can't fire `SREM` so a tracked SET would drift). `'ttl'` supports per-key expiry via `EXPIRE` — `count()`/`iterate()` use `SCAN MATCH` (O(N)). Pick one per table.
- **Bulk** — `Store::mget($table, $keys)`/`Store::mset($table, $rows)` round-trip sequentially today (pipelined wire-batching is Phase 2 once a driver-shaped Pipeline proxy lands; predis Pipeline's `hset` shape diverges from phpredis Multi's).
- **Counter CAS** — `compareAndSet` on the Redis backend uses a small Lua script (server-side atomic; one round-trip). `Counter::raw()` returns `OpenSwoole\Atomic` only on the atomic backend; throws `StoreException` on Redis (no raw equivalent there). Same for `Store::table($name)` — `null` for unknown table under Table backend, `StoreException` under Redis.
- **Boot wiring** — `App::run()` reads `ZEALPHP_STORE_BACKEND` env BEFORE workers fork and switches both Store and Counter (Counter follows Store's kind when env is set). `Counter::__construct` writes to the backend ONLY when called with an explicit `$name` or non-zero `$initial`; anonymous `new Counter(0)` is a no-op at construction (the slot defaults to 0 on first read) — critical because route/*.php often constructs counters at boot in the master process where the hooked `stream_socket_client` requires a coroutine.
- **Out of scope (Phase 1)** — tiered Table-L1 + Redis-L2 backend (Phase 2), opt-in `$opts['on_error' => 'fallback_table']` per-table degrade hook, Redis Cluster/Sentinel topologies, Redis-backed sessions.

**Pub/sub + Streams (v0.2.39)** — two public primitives on top of the pluggable backend for cross-worker AND cross-host messaging. Both require `Store::defaultBackend('redis')` (throws `StoreException` on Table).
- **Fire-and-forget pub/sub:** `Store::publish($channel, $payload): int` returns the receiver count Redis delivered to. `App::subscribe($channelOrPattern, callable($payload, $channel, ?$pattern): void)` registers a handler at boot. Channels containing `*` are PSUBSCRIBE patterns. Each worker spawns its own dedicated subscriber coroutine in `onWorkerStart` (owns one Redis connection separate from the pool — SUBSCRIBE monopolises a connection). Each received message dispatches handlers via `go()` per message so a slow handler can't block the next read. Multiple handlers per channel all fire. Throws are caught + `error_log`'d; runner survives. Messages published DURING a subscriber reconnect window are LOST — Redis pub/sub has no buffering. Use the reliable variant when at-least-once delivery matters.
- **Reliable variant via Streams:** `Store::publishReliable($stream, $payload, ?$maxLen): string` returns the Redis-generated message id. `App::subscribeReliable($stream, callable($payload, $id, $stream, $fields): bool, ?$group, $blockMs=1000, $batchSize=16)` registers a consumer-group handler. Handler return value: `true` → XACK (removed from pending); `false` or throws → leave pending (retried on reconnect). Default group name = `'zealphp-' + sha1(canonicalHost())[:8]` so all servers in a cluster share one group → round-robin distribution across machines + workers. Each handler runs in its own `go()`; XACK uses a fresh client (sharing the runner's read-client would race the socket).
- **Shutdown:** runners stop cleanly via `App::onWorkerStop` hooks the framework auto-registers when handlers are wired. Pub/sub stop publishes a sentinel to a private stop channel; Streams stop signals via Atomic + next XREADGROUP BLOCK timeout exits the loop.
- **Reconnect:** both runners do bounded exponential backoff (0.1 → 0.2 → 0.4 → … capped at 5 s) on `StoreException`. The consumer-group create on Streams is idempotent (returns false on `BUSYGROUP`).
- **Receiver count semantics:** for `Store::publish`, every WORKER (across every NODE) running a matching subscriber receives the message. So 32 workers on one node + 32 workers on a peer node = `receivers: 64` per PUBLISH. That's correct Redis pub/sub. Matches the cross-server WS routing pattern (each worker checks its local fd map and pushes only if the target client is locally owned).
- **Driver choice (both validated in v0.2.40):** Both phpredis (preferred when `ext-redis` is loaded) and predis SUBSCRIBE loops yield correctly under `Runtime::HOOK_ALL` — the production default in coroutine mode. Spike results: phpredis 775 ops/sec / 0.23 ms median publish-receive / 11 ms per-cor; predis 760 ops/sec / 0.40 ms / 23 ms. phpredis is ~2× faster on hot CRUD; pick it when you can. **Crucial nuance:** phpredis SUBSCRIBE blocks the worker WITHOUT HOOK_ALL (C-side socket read). HOOK_ALL is on by default in coroutine mode; if your app disabled it explicitly, force predis for subscribers OR re-enable HOOK_ALL. Unit tests can't exercise the phpredis SUBSCRIBE path because PHPUnit doesn't enable HOOK_ALL process-wide — the standalone spike at `scripts/spike-phpredis-subscribe.php` is the canonical validation.
- **Live demo:** `route/demo.php` registers `App::subscribe('demo:pubsub')` + `'demo:pubsub:*'` when Redis backend is active. `/demo/pubsub/publish?channel=&msg=`, `/demo/pubsub/publish-reliable?stream=&msg=`, `/demo/pubsub/log` exercise the public API end-to-end.

**Redis Cluster / Sentinel (v0.2.40 path)** — both topologies are predominantly a `Predis\Client` configuration concern. The Phase 1 `PredisDriver` accepts EITHER a `redis://` URL string OR a pre-wired `Predis\Client` instance; for Cluster construct `new Predis\Client([nodeUrls...], ['cluster' => 'redis'])`, for Sentinel `new Predis\Client([sentinelUrls...], ['replication' => 'sentinel', 'service' => 'mymaster'])` + pass it to `new PredisDriver($client)`. Inject the driver into a `RedisBackend` via its `RedisConnectionPool` of size 1 (Cluster manages connections itself; the per-worker pool isn't needed). A `Store::clusterBackend()` / `Store::sentinelBackend()` facade helper is on the v0.2.41 roadmap. phpredis users wanting Cluster/Sentinel should use `ZEALPHP_REDIS_PREFER=predis` for now — `RedisCluster` class needs a separate driver shape (deferred). Doc: `/store#cluster`.

**Phase 2 — Tiered backend + L1 invalidation (v0.2.40)** — `ZealPHP\Store\TieredBackend` pairs a `TableBackend` (L1, ns latency, bounded-staleness via `l1_ttl`) with a `RedisBackend` (L2, source of truth, cross-node). Read path: L1 first; if entry is fresh, return it; else fetch L2 + populate L1. Write path: write-through to L2 + refresh L1; `incr/decr` evicts L1 so the next read re-fetches the authoritative value. `count/iterate/names` always defer to L2. L1 schema gets a synthetic `__cached_at` INT column appended for staleness tracking; user-facing `get()` strips it. Add **Phase 3** cross-node L1 invalidation by calling `enableInvalidation()` (after `make()` for all tables) — TieredBackend publishes origin-tagged `{prefix}:__l1_invalidate:{table}` messages on every write; peer instances on other nodes evict the L1 entry sub-millisecond. Self-publishes are skipped via the origin tag. The runner SUBSCRIBE happens at start time — tables registered AFTER enableInvalidation() won't auto-subscribe (documented boot-order requirement).

**Pub/sub WebSocket helper (v0.2.40)** — `ZealPHP\WSRouter` bundles the cross-server WS routing pattern into ~5 calls: `init($serverId?, $sink?)` (one-time), `own($clientId, $fd)` from onOpen, `release($clientId)` from onClose, `sendToClient($id, $payload)` from anywhere, `broadcast($channel, $payload)` for room fan-out. Default sink does `$server->push($fd, $payload)` with an `isEstablished()` guard. Stores `client_id → server_id` in the cluster-wide `ws_owner` Store table. Each server subscribes to `ws:server:{ID}` channel; `sendToClient` looks up the owner row + PUBLISHes the routed message. Test surface: `tests/Unit/WSRouterTest.php` (6 cases for state management + delegation; full publish→handler roundtrip is integration territory because it requires `App::run` boot wiring).

**Streams XAUTOCLAIM (v0.2.40)** — `RedisClient::xautoclaim($stream, $group, $consumer, $minIdleMs, $start = '0-0', $count = 16): [string, list<{id,payload}>]`. Used to recover orphan messages from consumers that died mid-processing — a healthy peer claims the pending messages older than `$minIdleMs` ms and re-assigns them to itself. Returns `[next-cursor, entries]`; iterate the cursor until '0-0' to drain. Both PredisDriver (via `executeRaw`) and PhpredisDriver (via `xAutoClaim`) implement it. Not auto-invoked by `RedisStreams`'s runner yet — user code can drive it via the adapter directly; auto-claim policy (interval + idle threshold) lands as an opinionated default in a follow-up.

**Redis-backed sessions (v0.2.40)** — `ZealPHP\Session\Handler\StoreSessionHandler` rides whichever backend `Store::defaultBackend()` is configured with: Table for single-node, Redis for cross-node sticky-or-not-sticky LB setups, Tiered for both. Register with `StoreSessionHandler::register(int $ttl = 1440)` BEFORE `App::run()` (creates the `zealphp_sessions` Store table with `mode='ttl'` when on Redis so rows expire server-side). Pre-existing `RedisSessionHandler` (ext-redis-direct) stays — works fine for phpredis users; the new handler is for backend-agnostic + works-without-ext-redis ergonomics.

**Production hardening pass (v0.2.41)** — senior-eng review of the Redis backend surface closed 3 critical + 10 medium gaps. Default behaviour preserved across all 13 fixes (no BC break). Reference: `docs/architecture/2026-05-23-redis-backend-review.md`. Highlights:
- **C1 — FD-reuse race in `WSRouter`:** `ws_owner` table grew a `conn_id` per-connection nonce; subscriber sink verifies before push. Closes a cross-tenant data-leakage vector where a reused `$fd` (lost onClose + OpenSwoole reassignment) could receive a message intended for the previous owner. `WSRouter::own()` returns the nonce; the fd-coherence invariant (only one client per fd) is enforced on every `own()` call.
- **C2 — HMAC-signed L1 invalidations in `TieredBackend`:** optional shared secret (`ZEALPHP_TIERED_INVALIDATION_SECRET` or constructor arg) HMACs every published `__l1_invalidate` message. Receivers verify before evicting; messages without/wrong HMAC are dropped + warn-logged. Defeats the "any Redis writer DoSes the cluster's L1" attack. Default null → trust mode (BC).
- **C3 — TLS via `rediss://` / `tls://`:** `PhpredisDriver` parser recognises the scheme + threads `verify_peer=true` stream context to `\Redis::connect`. Predis already accepts `rediss://` natively. Bare `redis://` (no host) now rejected at parse time.
- **H1 — `mode='tracked' + ttl>0` throws at `RedisBackend::make()`:** silently-ignored TTL on tracked tables would drift the membership SET; surfaces at boot now.
- **H2 — `Store::getStrict($name, $key, ?$field)`:** null-on-miss variant for new code (legacy `Store::get()` keeps `=== false` BC semantics permanently).
- **H3 — pipelined `mget`/`mset` + `UNLINK` in `clear()`:** new driver primitives `mhgetall`/`mhsetWithMembership`/`unlink` use phpredis MULTI/PIPELINE and predis `$c->pipeline()` natively. Multi-second clears on 10k-key tables → sub-second; mget(100) → 1 RTT.
- **H4 — `CircuitBreakerBackend` decorator (opt-in):** 3-state (closed/open/half-open) with sliding-window threshold; reads fall back to optional secondary backend, writes throw when open. Wire via `Store::defaultBackend('redis', ['on_error' => 'fallback_table', 'breaker' => [...]])`. Default (no opt) = no decoration, throws on Redis down (current behaviour).
- **H5 — `Store::stats(): array<string,int>` per-worker counters:** `pool_acquires_total`, `pool_acquire_timeouts_total`, `pool_clients_created_total`. Pub/sub instances expose `pubsub_reconnects_total`, `pubsub_messages_received_total`, `pubsub_handler_errors_total` via `RedisPubSub::stats()`. Empty array on Table backend.
- **H6 + H7 — boot-time advisories in `App::run()`:** eager Redis ping (warn on failure), and a HOOK_ALL+phpredis+subscribers compatibility check (warn with the recipe to fix). Both surface misconfigurations before workers fork. `App::redisBootChecks()` is the testable seam.
- **H8 — `TieredBackend::existsCached()`:** stale-OK opt-in fast path; strict `exists()` always hits L2 (consistency); the new variant returns true from L1 when fresh, saves an RTT on hot paths that tolerate `$l1Ttl`-bounded staleness.
- **H9 — `PhpredisDriver::close()` logs via `elog('debug')`:** silent-swallow replaced with diagnosable failure trace.
- **H10 — `RedisPubSub` `$maxAttempts` (default 0 = unlimited):** bounded reconnect attempts for CI workers that should crash if Redis is permanently gone.

The hardening pass is documented end-to-end at `/store#production-hardening`. The senior-eng review notes + risk-by-risk mapping live at `docs/architecture/2026-05-23-redis-backend-review.md`. The plan that drove the work is at `docs/superpowers/plans/2026-05-23-redis-backend-hardening.md`.

**Three-backend Store facade (v0.2.41)** — `Store::defaultBackend()` now accepts THREE kinds. Use the class constants `Store::BACKEND_TABLE / BACKEND_REDIS / BACKEND_TIERED` (the canonical user-facing form). Bare strings (`'table'`/`'redis'`/`'tiered'`) work too for BC.
- `Store::BACKEND_TABLE` (default) — `OpenSwoole\Table` shared memory. Ns latency. Scope: ONE OpenSwoole server (cross-worker, NOT cross-machine, NOT cross-php-process). `maxRows` is a HARD CAP allocated at master fork: `RAM ≈ maxRows × (4 × Σ column sizes + ~32 B/row)`. 1M × 32-B-string column = ~280 MB. PHP_INT_MAX → OOM-killed. Use for single-node hot paths.
- `Store::BACKEND_REDIS` — Redis/Valkey via the pluggable RedisBackend. Cross-node, persistent. `maxRows` IS NOT ENFORCED (Redis is a global KV; configure server-side `maxmemory` + `maxmemory-policy` for cluster-wide bound, OR pair with `Cache::init(ttlSeconds: ...)` for per-key auto-expiry). Hot CRUD via phpredis (~ext-redis) or predis (pure PHP fallback); both drivers validated for SUBSCRIBE under HOOK_ALL.
- `Store::BACKEND_TIERED` — L1 TableBackend + L2 RedisBackend. Ns reads on L1 hit; L1 miss → fetch L2 + repopulate L1 + return. Optional `'invalidation_secret'` (or `ZEALPHP_TIERED_INVALIDATION_SECRET` env) enables HMAC-signed cross-node L1 invalidation (C2 hardening — receiver-side verify; default trust mode if null). Conn opts: `'url'/'pool_size'/'prefix'/'prefer'` (forwarded to L2) + `'l1_ttl'` (default 5s) + `'invalidation_secret'`. **Recipe:** `Store::defaultBackend(Store::BACKEND_TIERED, ['url' => '...', 'l1_ttl' => 5, 'invalidation_secret' => $secret])`. Facade builds the L1+L2 pair lazily; no Redis connection at construction time.

**Cache::getOrCompute (v0.2.41)** — `Cache::getOrCompute(string $key, callable $compute, int $ttl = 0): mixed` is the canonical read-through cache helper. Collapses the `Cache::get` → `if null compute` → `Cache::set` boilerplate to a single call. Null is cached as a valid value via internal sentinel object — distinguishes "stored null" from "miss". Pair with `Cache::init(maxRows: …, ttlSeconds: …)` for bounded growth on the Redis backend; the Table backend honours `maxRows` as a HARD cap (spills oversize/overflow to the file tier automatically). Boot-time warning emitted by `Cache::init()` if you pass non-default `$maxRows` on Redis without a `$ttlSeconds` — surfaces the chokepoint where Table's hard-cap semantic doesn't translate to Redis.

**Federated WebSocket Rooms (v0.3.0 P1.1)** — `WSRouter::room($name): Room` (`src/WS/Room.php` + state in `src/WSRouter.php`). First-class room abstraction on top of the v0.2.40 Store + pub/sub fabric. Membership stored in cluster-wide `ws_room_members` Store table (rows keyed by `{room}:{clientId}`); pushes fan out via ONE `ws:room:*` PSUBSCRIBE pattern subscriber per worker (no per-room subscriber explosion). Federation verified cross-host: server A's join is visible from server B's `Room::members()` query; broadcasts from either side reach all locally-held clients on every node. API: `$r->join/leave/isMember/size/members/push/onMessage/onPresence`. Per-worker local-membership cache populated from presence events — pushes scan only locally-owned clients (no full table iterate per message). Requires Redis backend; throws `StoreException` on Table backend (rooms inherently need pub/sub). 10 unit tests in `tests/Unit/WS/RoomTest.php`, all pass; cross-host smoke test against two Valkey-backed ZealPHP instances confirms federation works end-to-end.

**Early v0.3.0 shipped items** — 5 helpers landed before Phase 1 formal start (to close half-cooked gaps with zero technical debt):
- `App::parallel(array $tasks): array` + `App::parallelLimit(array, callable, int $concurrency): array` (P1.4) — fork-join + bounded fan-out. Sync-mode callers auto-wrap in `Coroutine::run`. Exceptions propagate to the caller (first error wins). Built on `Coroutine\Channel` (OpenSwoole 22.x doesn't ship `WaitGroup`).
- `App::onSignal(int $signal, callable $handler, bool $workerOnly = false): void` (P1.12) — register `OpenSwoole\Process::signal()` handlers from app.php. Master vs worker scoping. Common uses: SIGHUP → config reload, SIGUSR1 → stats dump.
- `App::stats(): array<string,mixed>` (P1.10 partial) — workers / store / memory / uptime aggregate. Full `/healthz` Middleware + Prometheus exposition still TBD per roadmap.
- `ZealPHP\HTTP::get/post/put/delete/request/all` + `ZealPHP\HTTPResponse` (P1.11) — typed outbound HTTP wrapper around `OpenSwoole\Coroutine\Http\Client`. JSON auto-encode on array body. Transport errors return `HTTPResponse::failed()`-true rather than throwing.
- `App::addProcess(string $name, callable $fn, int $workers = 1, bool $coroutine = true): void` (P2.1) — sidecar long-running process registration. Wired via `$server->addProcess($process)` in `App::run()` before `start()`. Each sidecar gets `cli_set_process_title("zealphp:{$name}")` and (if `$coroutine=true`) runs the callable inside `Coroutine::run` so hooked I/O yields.

**v0.3.0 roadmap** — `docs/architecture/2026-05-23-v0.3.0-roadmap.md` is the authoritative scope plan. Phase 1 (v0.3.0) closes 12 half-cooked items: WebSocket rooms (P1.1), Redis-Streams-backed queues with retry/DLQ/scheduled-enqueue (P1.2), proper Auth providers (OAuth/JWT/cookie-session, P1.3), `App::parallel`/`parallelLimit` coroutine sync (P1.4), cluster-wide cron scheduler (P1.5), CSRF middleware (P1.6), Memcached handler across Cache/Session/Store (P1.7), Cache+Store pipeline (P1.8), retire `Legacy\FastCgiClient` for `OpenSwoole\Coroutine\FastCGI\Client` (P1.9), aggregated `App::stats()` + `/healthz` + Prometheus exposition (P1.10), `HTTP::request()` outbound wrapper (P1.11), `App::onSignal()` user signal hooks (P1.12). Phase 2 (v0.4.0+) takes on `Process\Pool` sidecar workers, gRPC service helper, HTTP/2 push, Mail wrapper, GraphQL helper. **PDO/DB layer is deferred** — three-way design discussion (ORM ship vs thin helper vs userland) pending; also blocked on OpenSwoole 25.x PDO hooks. **TCP/UDP non-HTTP listeners** and **`OpenSwoole\Coroutine\MySQL/PostgreSQL`** intentionally out of scope (niche / driver-coverage concerns). When working on v0.3.0 items, the roadmap doc is the source of truth for API shape + scope; deviations get committed back to the doc, not invented at code-time.

**Backend constants — the canonical API surface (v0.2.41)** — every `defaultBackend()`-shaped call accepts the class constants:
- `Store::BACKEND_TABLE` / `Store::BACKEND_REDIS` / `Store::BACKEND_TIERED`
- `Counter::BACKEND_ATOMIC` / `Counter::BACKEND_REDIS`
- `Store::PREFER_AUTO` / `Store::PREFER_PHPREDIS` / `Store::PREFER_PREDIS`

Bare strings (`'table'`/`'redis'`/`'tiered'` / etc.) work too for BC. **Internal note for framework code:** the enum classes `StoreBackendKind`, `CounterBackendKind`, `DriverPreference`, and `CgiMode` exist as implementation detail for typed method signatures (`StoreBackendKind|string|null` in `Store::defaultBackend()`'s signature). User-facing docs, lessons, and portfolio pages should use the constants — not the enum cases — to avoid documenting three names for the same concept. The enums stay for static-analysis precision inside `src/`; they aren't part of the recommended public API.

**Timers** (via `App::tick/after/clearTimer`):
- `App::tick(int $ms, callable $fn)` — recurring per-worker timer
- `App::after(int $ms, callable $fn)` — one-shot timer
- Must be called inside a coroutine context (`onWorkerStart` or request handler)

### Task Workers

Task handlers live in `task/` (e.g., `task/backup.php`). Dispatch with:

```php
App::getServer()->task(['handler' => '/task/backup', 'args' => [...]]);
```

Task workers run in coroutine mode (`task_enable_coroutine => true` is set by default).

### AI Agent Architecture

The Python notes agent (`examples/agents/notes_agent.py`) calls ZealPHP's HTTP API with the user's `PHPSESSID` cookie — same endpoints as the frontend. This ensures note mutations trigger WebSocket broadcasts for live cross-tab updates. `Chat::real()` passes `session_id` and `api_base` in the base64 payload. The agent uses `RunContextWrapper[AgentContext]` per OpenAI Agents SDK best practices. Notes API supports JSON responses via `Accept: application/json` content negotiation.

---

## Coding Standards

### Pre-commit discipline — both checks must pass

Every change to `src/` runs the same two checks CI runs. Run them as you work, fix issues immediately rather than letting them pile up to release time (lesson from v0.2.21: 11 PHPStan errors accumulated across the parity push because middleware-builder + configurables-builder agents didn't run the analyser between commits — caught at the pre-tag sweep, took a separate fix commit to clean up).

| Check | Command | Bar |
|-------|---------|-----|
| **Unit tests** | `./vendor/bin/phpunit tests/Unit/ --testdox` | All green. New classes get their own test file. |
| **Integration tests** | `./vendor/bin/phpunit tests/Integration/ --testdox` (server up on :8080) | All green. New routes get coverage in the matching `tests/Integration/` file. |
| **PHPStan static analysis** | `./vendor/bin/phpstan analyse --no-progress` | **Level 10, zero errors.** No `@phpstan-ignore` comments, no type widening to silence errors, no `assert()` / inline `@var` overrides. Fix the underlying type problem. |
| **Patch coverage** (codecov target) | `XDEBUG_MODE=coverage ./vendor/bin/phpunit tests/Unit/ --coverage-text=/tmp/cov.txt && grep -A2 '<your touched files>' /tmp/cov.txt` | **≥ 80% of NEWLY-ADDED lines hit by tests.** Codecov's `codecov/patch` gate enforces this on every PR (lesson from PRs #44/#45: shipping ~2k new LOC with 17–69% patch coverage means tests cover the wrong lines — typically tests for already-merged classes instead of the PR's *new* lines). Write the tests for the *new* method bodies you just added; verify locally before opening the PR. pcov is preferred over xdebug for speed; `XDEBUG_MODE=coverage` works for both. |
| **Mutation testing** (Infection MSI) | `XDEBUG_MODE=coverage ./vendor/bin/infection --threads=4 --test-framework-options="--testsuite=Unit"` | **Plain MSI ≥ 88, Covered-MSI ≥ 92** (gates in `infection.json5`). When MSI drops, run `./vendor/bin/infection --filter=<your-changed-files> -s` to see escaped mutants, then add assertions that *kill* them — distinguish genuinely-equivalent mutants (logging-only, casts on already-narrowed types, branches with identical observable behaviour) and flag those with a one-line rationale rather than forcing kills. |

The PHPStan badge at `.github/badges/phpstan.json` must match `phpstan.neon`'s level. CI's `validate` job fails if they're out of sync. The Mutation MSI badge at `.github/badges/mutation.json` is refreshed via the auto-PR workflow (`mutation.yml`) on master pushes — it cannot be updated by a direct CI commit because master is branch-protected.

**Common level-10 traps in this codebase** (drawn from real v0.2.21 fixes):
- Casting `mixed` to string without a `is_scalar()` / `is_string()` / `is_object() + method_exists($_, '__toString')` guard first — PSR-7 `getServerParams()` returns `array<string, mixed>`, so any `$params['REMOTE_ADDR'] ?? ''` needs the guard before `(string)`.
- `Foo::bar() ?? default` when `Foo::bar()` has a declared non-nullable return — PHPStan flags the unreachable `??` branch.
- `is_callable($x)` when `$x` is already typed `callable` in the function signature — PHPStan complains it's always true. Either widen the parameter docblock to `mixed` if runtime validation is actually meaningful, or drop the redundant check.
- `streamFor()` and other vendor helpers without declared return types — PSR-7 wrappers under `OpenSwoole\Core\Psr\*` sometimes return `Stream|void`. Construct the target type directly instead of going through the helper.
- `is_int($result) ? ... : (string)$result` — the false branch still sees mixed; narrow with `is_scalar` or `is_null` before the cast.

### PHP Style
- Follow **PSR-2** (https://www.php-fig.org/psr/psr-2/) for all PHP code.
- Use `declare(strict_types=1)` in new `src/` classes.
- Short array syntax (`[]` not `array()`), meaningful docblocks on public APIs.

### Separation of Concerns — Hard Rules

| Rule | Rationale |
|------|-----------|
| **No inline `<script>` blocks in templates** | All JS goes to `public/js/`. Templates produce HTML only. |
| **No inline `style=` attributes or `<style>` blocks in templates** | All CSS goes to `public/css/`. Use CSS classes. |
| **No PHP function definitions in templates** (`template/`) | Templates are view-only. Extract helpers to `src/` classes. |
| **No PHP function definitions in API files** (`api/`) | API files define one closure (`$get`, `$post`, etc.) and delegate to `src/` service classes. |
| **If you need `function_exists()` guard, the function is in the wrong place** | This means it can be re-declared — put it in a class autoloaded via PSR-4 instead. |

### OOP and Autoloading
- Business logic belongs in `src/` as proper classes with constructors, autoloaded via Composer PSR-4 (`ZealPHP\` namespace).
- Use controllers/services in `src/` — not free functions scattered across route/api files.
- The `src/Learn/` namespace demonstrates the pattern: `Auth.php`, `Chat.php`, `Notes.php`, `DB.php`, `WS.php` are autoloaded classes that API and route handlers delegate to.

### Route vs API — When to Use Which

| Layer | Use for | Example |
|-------|---------|---------|
| `api/` (ZealAPI) | REST endpoints — file-based, auto-routed | `api/users/get.php` → `GET /api/users` |
| `route/` | Path-param routes, WebSocket, Store table registration, demo routes | `route/ws.php`, `route/streaming.php` |
| `app.php` | Bootstrap only — middleware, `$app->run()` | Keep thin |

**Routes are thin.** A route handler should be 1–5 lines that call a `src/` class. If a handler exceeds ~10 lines, extract the logic to a service class.

### htmx Convention
The site uses **htmx** globally. `_master.php` sets `hx-boost="true"` on `<body>`:
- Every `<a>` and `<form>` is AJAX-ified automatically (htmx swaps the `<body>`, updates `<title>`, handles history)
- Full-page navigation still works if JS is disabled (progressive enhancement)
- After each swap, `htmx:afterSettle` fires — `initPageScripts()` in `_master.php` re-runs highlight.js and demo panels
- Prefer `hx-get`/`hx-post` + `hx-target` + `hx-swap` over custom `fetch()` for standard interactions
- For server-push (streaming, real-time), use WebSocket (`App::ws()`) or SSE (`$response->sse()`)

### Separation of concerns (formerly tech debt — now enforced)

The inline `style=` / inline `<script>` tech debt was fully cleared in the Dec-2024 sweep: ~1200 inline styles → `public/css/pages/*.css`, the inline scripts (incl. `home.php`'s 4 blocks and the `_master.php` nav script) → `public/js/{,pages/}*.js`. **Keep it that way** — templates produce HTML only; CSS goes to `public/css/`, JS to `public/js/`. The single allowed `style=` left in `template/` is inside an escaped `<pre><code>` *sample* (displayed code, not a real attribute). Don't reintroduce inline styles/scripts.

---

## OSS Website

The demo app IS the ZealPHP documentation website. Run `php app.php` and browse `http://localhost:8080`.

### Template System

Single `template/_master.php` used by every page. Every `public/X.php` is 3 lines:

```php
<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP · Routing', 'page' => 'routing', 'active' => 'routing']);
```

`_master.php` reads `$page` and renders `template/pages/$page.php`.

Template structure:
```
template/
  _master.php          — Universal layout (nav + content + footer)
  _head.php            — <head> with CSS/JS links
  _nav.php             — Top navigation
  _footer.php          — Footer
  components/
    _code.php          — Syntax-highlighted code block
    _card.php          — Feature card
    _demo.php          — Split code + live output panel
  pages/               — One file per website section
    home.php, getting-started.php, routing.php, responses.php,
    coroutines.php, streaming.php, websocket.php, middleware.php,
    sessions.php, store.php, timers.php, http.php, api.php,
    templates.php, legacy-apps.php
```

CSS: `public/css/zealphp.css` — global stylesheet, CSS variables, amber accent. Page-specific styles live in `public/css/pages/{page-key}.css` (one per template, `$page` slashes→dashes); they're concatenated at boot into `public/css/pages.css` (gitignored, built in `app.php`) and loaded **eagerly** up front in `_head.php` so hx-boost navigation never flashes unstyled content. **No inline `style=`/`<script>` in templates** — the Dec-2024 sweep extracted all ~1200 inline styles + inline scripts into `public/css/pages/*.css` and `public/js/{,pages/}*.js` (the lone remaining `style=` lives inside an escaped `<pre><code>` sample). New code keeps that contract: CSS classes only, JS in `public/js/`.

**Asset cache-busting** — `ZEALPHP_ASSET_VERSION` (defined in `app.php`, used as `?v=…` on every CSS/JS tag in `_head.php`) resolves in order: (1) the **git commit short hash** read straight from `.git/HEAD` — bumps on every commit and is identical for the same commit across deploys, so caches stay valid until a real change; (2) when there's no `.git` (composer installs), the **newest mtime across `public/css` + `public/js`** — so JS-only edits still bust caches, not just `zealphp.css`; (3) boot `time()` as a last resort. There is **no build step** for assets — they're hand-written static files (only `pages.css` is concatenated at boot).

### Demo API Endpoints

`route/demo.php` — 25 live endpoints used by the website's "LIVE OUTPUT" panels:

- `/demo/inject/{case}` — every parameter injection pattern
- `/demo/route/{type}` — nsRoute, nsPathRoute, patternRoute
- `/demo/response/{method}` — json, redirect, headers, cookie
- `/demo/coroutine/{pattern}` — parallel, channel
- `/demo/store/` and `/demo/counter/` — Store + Counter demos
- `/demo/session/` — write + read session
- `/demo/middleware/` — CORS, ETag, OpenSwoole compression

---

## Examples (`examples/`)

**`examples/*.php` (root level)** — OpenSwoole implementation reference scripts (standalone, not ZealPHP API usage). Do not use as application patterns.

All ZealPHP usage examples live as first-class project files:
- Routes: `route/streaming.php`, `route/ws.php`, `route/timers.php`, `route/http_features.php`, `route/demo.php`
- Public pages: `public/*.php` (website pages)
- APIs: `api/` directory (ZealAPI pattern)
- Templates: `template/pages/*.php`

---

## Source Layout (`src/`)

| File | Role |
|------|------|
| `App.php` | Framework core: init, route registration, `run()`, `ResponseMiddleware`, file-execution family (`render()`/`renderToString()`/`renderStream()`/`include()` — all sharing a private `executeFile()` core — plus `fragment()` for in-template named regions), `setFallback()`, `tick()`/`after()`/`onWorkerStart()`, CLI `parseCliArgs()`. `includeFile()` is a deprecated alias for `include()`. |
| `cgi_worker.php` | CGI-style process for legacy apps — true global scope, uopz header/cookie capture, SSE streaming via flush() |
| `G.php` | Per-request global state; superglobals mode uses static singleton, coroutine mode uses `Coroutine::getContext()` |
| `Store.php` | `OpenSwoole\Table` adapter — cross-worker shared-memory key-value store |
| `Counter.php` | `OpenSwoole\Atomic` adapter — lock-free cross-worker integer counter |
| `ZealAPI.php` | File-based API dispatcher; extends `REST.php` |
| `REST.php` | Base class with input cleaning and response helpers |
| `utils.php` | Global functions: `prefork_request_handler`, `coprocess`, `elog`, `zlog`, `access_log`, `response_add_header`, overridden `header`/`setcookie`/`http_response_code` |
| `Session/utils.php` | Overridden `session_*` functions (file-backed, coroutine-safe) |
| `Session/CoSessionManager.php` | Per-coroutine session lifecycle (superglobals OFF) |
| `Session/SessionManager.php` | Traditional session lifecycle (superglobals ON) |
| `IOStreamWrapper.php` | `php://` stream wrapper that redirects `php://input` to request body |
| `HTTP/Request.php` | Thin wrapper around `OpenSwoole\Http\Request` |
| `HTTP/Response.php` | Thin wrapper around `OpenSwoole\Http\Response`; adds `stream()`, `sse()`, `sendFile()`, `redirect()`, `flush()` |
| `Middleware/CorsMiddleware.php` | CORS preflight + `Access-Control-*` headers |
| `Middleware/ETagMiddleware.php` | ETag generation + 304 Not Modified |
| `Middleware/CompressionMiddleware.php` | Reference gzip/deflate middleware; only use when OpenSwoole `http_compression` is disabled |
| `Middleware/RangeMiddleware.php` | RFC 7233 Range requests: Accept-Ranges, 206 single/multi-range, 416, If-Range ETag support |
| `Middleware/SessionStartMiddleware.php` | Eager session start for first-time visitors — sets `PHPSESSID` cookie on first request |
| `Middleware/IniIsolationMiddleware.php` | Snapshots `ini_set()` changes per request, restores on exit. Opt-in via `ZEALPHP_INI_ISOLATE=1`. |
| `Middleware/CharsetMiddleware.php` | Appends `; charset=utf-8` to text-ish response `Content-Type` (Apache `AddCharset` parity) |
| `Middleware/CacheControlMiddleware.php` | Extension-keyed `Cache-Control: max-age=N, public` for static assets (Apache `<FilesMatch>` parity) |
| `Middleware/ExpiresMiddleware.php` | Adds `Expires:` header by content type (Apache `mod_expires` parity) |
| `Middleware/HeaderMiddleware.php` | Declarative response-header `set/add/unset` with conditional variants (Apache `mod_headers` parity) |
| `Middleware/BasicAuthMiddleware.php` | HTTP Basic Auth — htpasswd file or callback verifier (Apache `AuthType Basic` parity) |
| `Middleware/IpAccessMiddleware.php` | CIDR allow/deny lists with allow-first / deny-first ordering (Apache `Allow from / Deny from` parity) |
| `Middleware/RateLimitMiddleware.php` | Sliding-window request rate limiter backed by `Store` (nginx `limit_req` parity) |
| `Middleware/ConcurrencyLimitMiddleware.php` | In-flight concurrent-request cap backed by `Counter` (nginx `limit_conn` parity) |
| `Middleware/BlockPhpExtMiddleware.php` | Refuses `*.php` URLs with 404 for apps wanting extensionless URLs only |
| `Middleware/MimeTypeMiddleware.php` | Sets/overrides `Content-Type` on non-static responses by extension or pattern (Apache `AddType` / `ForceType` parity) |
| `Middleware/BodyRewriteMiddleware.php` | Single-line regex substitution on response body (Apache `mod_substitute` parity) |
| `Middleware/HostRouterMiddleware.php` | Dispatches per-host routes inside one ZealPHP instance (nginx `server_name` parity) |
| `deploy/zealphp.service` | systemd service template (Type=simple, no -d) |

---

## Companion repos — keep in sync

ZealPHP has two companion repos that must stay aligned with framework releases:

| Repo | Composer name | Role |
|---|---|---|
| **Scaffold** | `sibidharan/zealphp-project` | Template for `composer create-project`. `vendor/` is gitignored as of v0.2.17 — `composer create-project` runs `composer install` automatically, so no need to ship vendor in the git tree. |
| **WordPress showcase** | `sibidharan/zealphp-wordpress` | Demonstrates unmodified WordPress on ZealPHP |

**Path discovery — never hardcode `~/zealphp-project`.** Different devs lay out their workspaces differently. Find each companion in this order, stop at the first hit:

1. Env vars: `$ZEALPHP_PROJECT_DIR`, `$ZEALPHP_WORDPRESS_DIR`
2. Sibling of main repo: `../zealphp-project`, `../zealphp-wordpress`
3. Parent's siblings: `../../zealphp-project`, `../../zealphp-wordpress`
4. Ask the user. If a companion isn't accessible, surface that in the release summary and skip cleanly — don't fail the whole release.

**Ongoing sync (independent of releases):** when adding new framework APIs, update the scaffold's `.claude/CLAUDE.md` so AI tools assisting devs after `composer create-project` see the latest API. When adding deploy artifacts (systemd units, configs), copy to the scaffold's `deploy/`.

---

## Samples & scaffolds — what to verify before each release

Every release should exercise the full sample surface to catch regressions that unit + integration tests miss (real-app boot, real-app routing, real-app behaviour under load). The Demo app at the repo root **is** the OSS website — it boots from `app.php`, exercises every framework feature, and serves as the canonical smoke test. The companion repos add framework-hosting modes.

**In-repo samples** (under `examples/` and the demo app itself):

| Sample | Path | What it proves works | Pre-release smoke-test command |
|---|---|---|---|
| **Demo app / OSS website** | `app.php` + `api/` + `public/` + `template/` + `route/` | The whole framework: routing, middleware, sessions, htmx, fragments, streaming, WebSocket, all parity middleware | `php app.php` → browse `http://localhost:8080` (every nav page renders), open DevTools → confirm 0 console errors |
| **LAMP scaffold** | `examples/lamp-scaffold/` | Drop-in replacement for an Apache + MySQL + PHP stack (the "ZealPHP can replace LAMP" story) | `cd examples/lamp-scaffold && docker compose up` (or follow that scaffold's README) |
| **Hello-world** | `examples/hello-world/` | Minimal startup; bare bones first-app onboarding | `cd examples/hello-world && php app.php` |
| **Streaming SSE** | `examples/streaming-sse/` | `$response->sse()` works end-to-end with `EventSource` clients | `cd examples/streaming-sse && php app.php` → curl SSE endpoint |
| **WebSocket chat** | `examples/websocket-chat/` | `App::ws()` + WS frame handling + per-worker fd map | `cd examples/websocket-chat && php app.php` → open two browser tabs and chat |
| **OpenSwoole reference scripts** | `examples/coroutine.php`, `examples/pnctl_*.php`, `examples/std_rdir.php`, `examples/demo_middleware.php`, `examples/test.php` | Low-level OpenSwoole patterns ZealPHP is built on; smoke-test that none break on PHP/OpenSwoole version bumps | `php examples/coroutine.php` (and each other script) — sanity check, not load test |
| **Python agents** | `examples/agents/{chat,config_converter,convert_sse,notes,streaming}_agent.py` + `examples/agents/skills/` | Cross-process integration: Python AI agents calling ZealPHP HTTP/SSE/WS APIs (e.g., notes app, chat). Verifies the public HTTP API surface from a non-PHP client. Requires `examples/agents/zealphp_reference.txt` to be current with the framework. | Each agent script — see the agent's docstring for invocation. Most need the demo server running. |

**Companion repos** (per the [Companion repos](#companion-repos--keep-in-sync) section above):

| Repo | Composer name | What to verify each release |
|---|---|---|
| **Scaffold** | `sibidharan/zealphp-project` | `composer create-project sibidharan/zealphp-project:^X.Y.Z temp-test && cd temp-test && php app.php` boots cleanly + serves the scaffold welcome page on the new tag |
| **WordPress showcase** | `sibidharan/zealphp-wordpress` | Unmodified WP loads + serves homepage + admin login works under `superglobals(true) + processIsolation(true)` (or via the new `cgi_mode = 'fcgi'` path once wired) |
| **Symfony bridge** | `sibidharan/zealphp-symfony` | A Symfony skeleton app boots + routes + sessions work via `superglobals(true) + processIsolation(false) + sessionLifecycle(false)` (Mixed-mode lifecycle) |

**Release verification checklist** (in addition to the framework's own Pre-commit gates and the CI matrix):

1. ✅ `php app.php` boots + every page in `template/pages/` renders without console errors (the OSS site is the smoke test).
2. ✅ `composer create-project sibidharan/zealphp-project:^X.Y.Z temp-test` installs cleanly with the new version.
3. ✅ Each `examples/*` scaffold's README boot command still works (PHP/OpenSwoole/uopz version-bump regression check).
4. ✅ WordPress showcase loads in both default and (if wired) `cgiMode('fcgi')` configurations.
5. ✅ Symfony bridge boots a minimal Symfony app and serves a route.
6. ✅ Live website (the deployed OSS site) shows the new version in the Quick Start panel + Docker tag — run a hard refresh.

When adding new sample apps or new examples/* directories, **add the corresponding row to the tables above** so the release checklist stays comprehensive.

---

## Releasing a new version

**Trigger phrases:** *"pump to vX.Y.Z"*, *"bump version"*, *"release vX.Y.Z"*, *"tag vX.Y.Z"*. Treat any of these as a multi-repo coordinated release — touch **every** user-visible reference to the previous version, in **every** locally-accessible companion repo. Don't leave caret refs (`^X.Y.Z`) alone "because semver handles it"; the displayed version is marketing copy and must reflect the latest release.

### Pre-flight (gate the release)

1. Working tree clean in every repo you'll touch — do **not** auto-stash; surface dirty trees to the user and stop on that repo.
2. Tests pass in the main repo: `./vendor/bin/phpunit tests/Unit/` + integration tests with server up.
3. The new tag doesn't already exist: `git tag --list 'vX.Y.Z'`.

### Main repo — bump every reference

Use `grep -rn '\bvX\.Y\.Z-1\b' --include='*.md' --include='*.php' .` (with the *previous* version) to confirm none missed. Bump these files:

| File | What to bump |
|---|---|
| `CHANGELOG.md` | Insert new `[X.Y.Z] - YYYY-MM-DD` section above the previous one. Categorize Added / Changed / Fixed / Documentation (Keep a Changelog format) |
| `README.md` | `composer create-project` example + the "How to release" tag command example |
| `template/pages/getting-started.php` | `composer create-project` snippet |
| `template/pages/home.php` | Quick Start panel install command — bump in **both** the span text and the `data-copy` attribute |
| `template/pages/deployment.php` | Docker compose image tag |
| `docs/deployment.md` | Docker `build -t` and `image:` examples |

**Do NOT touch** (release-history artifacts, must remain accurate to their era):

- Previous `[X.Y.Z]` sections in `CHANGELOG.md`
- `PERF.md` "vX.Y.Z Baseline" / "vX.Y.Z — landed-in-this-version" optimization notes
- Test/code comments referencing when a behavior was introduced (e.g. `tests/Unit/SecurityTest.php` comments)

Also remember to bump:
- `.github/badges/phpstan.json` — `"message": "level N"` must match the `level: N` in `phpstan.neon`. CI's `validate` job fails if they're out of sync. If the release didn't change the PHPStan level, leave this alone.

### Commit, tag, push (main repo)

**`master` is branch-protected** (since the v0.2.37 Scorecard hardening): direct
`git push origin master` is **rejected** (PR required + status checks + admin
enforcement). Releases go through a PR, then the tag is cut on the merged commit.

```bash
# 1. Branch + commit the version bumps
git checkout -b release/vX.Y.Z
git add -A <bumped files>
git commit -m "chore: release vX.Y.Z — <one-line summary>"

# 2. Push the branch to BOTH remotes and open the PR (github = origin1)
git push origin release/vX.Y.Z && git push origin1 release/vX.Y.Z
gh pr create --base master --head release/vX.Y.Z --title "chore: release vX.Y.Z" --body "<highlights>"

# 3. Wait for all required checks green, then merge (rebase keeps linear history)
gh pr merge <N> --rebase --delete-branch

# 4. Fast-forward local master, sync the private mirror, THEN tag the merged commit
git checkout master && git pull origin1 master --ff-only && git push origin master
git tag -a vX.Y.Z -m "Release vX.Y.Z

<bullet-point highlights of headline changes>"
# Tags are NOT branch-protected — push the tag to both remotes directly.
git push origin vX.Y.Z && git push origin1 vX.Y.Z
```

> The required PR checks are: `validate`, `static-analysis`, `phpunit (PHP 8.3)`,
> `phpunit (PHP 8.4)`, `Infection (MSI)`, `Analyze (actions)`,
> `Analyze (javascript-typescript)`, `scan`. `phpunit (PHP 8.5)` (experimental)
> and the master-only jobs (`Scorecard analysis`, `CycloneDX SBOM`) are NOT
> required. To change protection: `gh api repos/sibidharan/zealphp/branches/master/protection`.

Verify Packagist picked up the tag: `curl -sS https://repo.packagist.org/p2/sibidharan/zealphp.json | python3 -c "import json,sys; print(json.load(sys.stdin)['packages']['sibidharan/zealphp'][0]['version'])"` should return `vX.Y.Z`.

### Scaffold sync (after main tag is live on Packagist)

```bash
cd <scaffold-path>                            # discovered via the env-var/sibling chain above
# Edit composer.json: "sibidharan/zealphp": "^X.Y.Z" (bump floor, not just caret)
composer update sibidharan/zealphp --with-dependencies
git add composer.json composer.lock
git commit -m "chore: bump sibidharan/zealphp floor to ^X.Y.Z"
git tag -a vX.Y.Z -m "Release vX.Y.Z — tracks sibidharan/zealphp vX.Y.Z"
for remote in $(git remote); do
  git push $remote main && git push $remote vX.Y.Z
done
```

`vendor/` is gitignored in the scaffold as of v0.2.17 — `composer create-project sibidharan/zealphp-project` runs `composer install` after extracting, so vendor/ is recreated locally per install. Don't add it back to the commit. composer.lock IS tracked so installs are reproducible.

### WordPress sync

Usually a no-op for patch releases. WordPress's `composer.json` typically pins `"sibidharan/zealphp": "^X.Y"`, which auto-picks up `X.Y.Z+1` on next `composer update`.

If working tree is dirty: skip cleanly and surface to the user. If you decide to bump the floor explicitly (e.g., users should be on at least this patch): same flow as scaffold.

### Force-tag vs new patch tag

| Scenario | Action |
|---|---|
| Cosmetic-only follow-up *to a just-pushed tag*, no installed-behavior change (e.g., display-version typo in docs) | `git tag -f vX.Y.Z <new-sha> && git push -f <remote> vX.Y.Z`. Note in annotated message that it's a re-tag |
| Anything that changes installed behavior (code, vendored docs that ship inside the scaffold, etc.) | Cut a new patch tag `vX.Y.Z+1` instead. Force-pushing breaks downloaders who already have the tag cached |

### Final verification

1. `composer create-project sibidharan/zealphp-project temp-test` in a scratch dir installs cleanly with the new version.
2. Live website spot-check: install command, Docker tag, hero version all match the new release.
3. Packagist `p2` JSON returns the new tag for both packages.
