# Runtime Architecture

ZealPHP wraps OpenSwoole’s event-driven HTTP server with a framework that feels familiar to PHP developers while enabling coroutine-friendly patterns. This document highlights the moving parts that collaborate during a request, how state is isolated, and how to opt into advanced execution modes.

> **Configuration via environment:** every runtime knob below can also be driven by a `ZEALPHP_*` environment variable. The complete, code-verified list (defaults, scope, and `env_flag` semantics) lives in [`docs/environment-variables.md`](environment-variables.md); the CLI-relevant subset is in [`docs/cli.md`](cli.md).

## Bootstrapping

`App::init()` performs one-time initialization:

- Requires either **ext-zealphp** (recommended) or **uopz** to be loaded — both can intercept built-in functions such as `header()` or `setcookie()`. If neither is present, the constructor throws immediately with a message recommending `pie install sibidharan/ext-zealphp`.
- Records the current working directory and entry script so the framework can build absolute paths later.
- Prepares the PSR-15 middleware stack (`OpenSwoole\Core\Psr\Middleware\StackHandler`) with `ResponseMiddleware` as the terminal handler.
- Configures coroutine hooks if superglobals are disabled (see below).
- Overrides built-in PHP functions to route them through ZealPHP shims — using `zealphp_override()` (ext-zealphp, preferred) when available, or `uopz_set_return()` (uopz, fallback) otherwise. This ensures headers, cookies, and response codes cooperate with the PSR response pipeline. The override family covers `header()` / `headers_list()` / `setcookie()` / `http_response_code()`, all `session_*()` functions, and — when the exec hook is on — the **backtick operator**, `shell_exec`, `exec`, `system`, and `passthru` (see below).
- Optionally installs the **coroutine-safe exec hook**. When `App::hookExec()` resolves to `true` (default-on in coroutine mode), ZealPHP overrides `shell_exec`, `exec`, `system`, `passthru`, and the backtick operator (the backtick compiles to a `shell_exec()` call, so overriding `shell_exec` intercepts it transparently) at `App::exec()` — so legacy/user code that shells out becomes coroutine-safe with no source changes. Same override mechanism as above (ext-zealphp preferred, uopz fallback). `proc_open` / `popen` are intentionally **not** overridden: `App::rawExec()` and the CGI subprocess path rely on `proc_open`, so leaving it untouched keeps the fallback recursion-safe. Toggle with `App::hookExec(bool)`; pass no arg to read the resolved value. See [Coroutine-safe exec](#coroutine-safe-exec) below.

`App::run()` then constructs the OpenSwoole HTTP server, includes custom route files, registers implicit routes, wires session managers, and starts the event loop. Pass an array of OpenSwoole settings to override defaults:

```php
$app->run([
    'enable_static_handler' => false,
    'task_worker_num' => 8,
    'document_root' => __DIR__ . '/public',
]);
```

ZealPHP merges your configuration with its defaults (`enable_static_handler: true`, `task_worker_num: 4`, `pid_file: /tmp/zealphp.pid`, etc.) and forces `enable_coroutine` based on the superglobal mode.

> **Document root.** The `document_root` shown above is OpenSwoole's underlying static-handler setting. The framework-level way to set it is **`App::documentRoot('public')`** — the Apache `DocumentRoot` equivalent: the folder every implicit route and the static handler resolve against, defaulting to `public/`. Set it (like all `App::*` config) *before* `App::init()`; `App::run()` resolves `App::$document_root` into this `document_root` setting for you, so most apps use the setter and never pass `document_root` by hand. See [routing.md](routing.md) and [directory-structure.md](directory-structure.md).

## Superglobals and the `G` Container

> **`G` is an alias; `ZealPHP\RequestContext` is the class.** `G` is the short, conventional name for the per-request **G**lobal state container. The class was originally named `G`, renamed to `RequestContext` in v0.2.6, with `class_alias(RequestContext::class, 'ZealPHP\G')` (at the bottom of `RequestContext.php`) keeping the short name working forever. `G::instance()` and the `$g` variable are the everyday accessors — type against `\ZealPHP\G` or `\ZealPHP\RequestContext`, they're literally the same class. In the [API reference](/docs/api/classes/ZealPHP-RequestContext.html) it's documented under its real name, **`RequestContext`**: a runtime `class_alias` has no source declaration of its own, so phpDocumentor can't give `G` a separate page.

Traditional PHP scripts rely on `$_GET`, `$_POST`, `$_SERVER`, etc. ZealPHP emulates this behaviour while running inside an event loop by funneling state through `ZealPHP\RequestContext` (alias `G`):

- When `App::$superglobals` is **true** (default), each request reconstructs the real PHP superglobals before executing route handlers. The `G` container proxies get/set operations so legacy code “just works.”
- When `App::superglobals(false)` is called, ZealPHP stops mutating global arrays and instead uses coroutine-safe properties on the `G` instance. In this mode, OpenSwoole coroutine hooks are enabled and you can safely use `go()` from within the main request handler. Access request data through `$g = G::instance(); $g->get`, `$g->server`, etc.

`G` owns additional request-scoped values:

- `zealphp_request` / `zealphp_response` – The wrapped OpenSwoole request/response objects exposed to route and API handlers.
- `status` – The HTTP status code chosen by the handler (defaults to 200).

## Request Lifecycle

1. **Session Manager**: Each incoming request is wrapped in either `Session\SessionManager` or `Session\CoSessionManager` depending on the superglobal mode. The manager drives `session_start()`, associates the request with `G`, and ensures `session_write_close()` always runs.
2. **Middleware Stack**: The OpenSwoole request is converted to a PSR-7 request (`OpenSwoole\Core\Psr\ServerRequest::from(...)`). ZealPHP walks the middleware stack in reverse registration order until `ResponseMiddleware` executes.
3. **Route Matching**: `ResponseMiddleware` evaluates the registered routes in the order they were defined. It supports:
   - Exact routes (`$app->route('/foo', ...)`)
   - Namespaces (`$app->nsRoute('admin', '/dashboard', ...)`)
   - Path-based namespaces (`$app->nsPathRoute('api', '{module}/{action}', ...)`)
   - Regular expressions (`$app->patternRoute('/raw/(?P<rest>.*)', ...)`)
4. **Handler Invocation**: Parameters captured from the URI are injected into the handler based on argument names. ZealPHP also recognises three special parameters: `app` (the `ResponseMiddleware` instance), `request` (`ZealPHP\HTTP\Request` wrapper), and `response` (`ZealPHP\HTTP\Response` wrapper). To reach the underlying OpenSwoole server, use `App::getServer()` — it is not an injectable parameter.
5. **Response Resolution**:
   - If the handler returns an `OpenSwoole\Core\Psr\Response`, ZealPHP emits it immediately.
   - If it returns an `int`, the value becomes the HTTP status code.
   - If it returns an array/object, ZealPHP serialises it to JSON and sets `Content-Type: application/json`.
   - Otherwise buffered output (including `echo`) is sent as the response body.
   - Exceptions are caught, logged via `elog()`, and transformed into formatted stack traces when `App::$display_errors` is true.

## Prefork Execution

ZealPHP favours a single-request-per-worker model to protect superglobals. When you need to isolate work:

- `App::cgiMode('pool' | 'proc' | 'fork' | 'fcgi')` selects the per-request isolation strategy for legacy `public/*.php` files. `'pool'` (default) uses a **pre-spawned subprocess pool** (mod_php-style global isolation, ~1–3 ms warm — what unmodified WordPress/Drupal needs). `'proc'` forks a fresh PHP interpreter per request via `proc_open` + `cgi_worker.php` (~30–50 ms cold start; use when true fresh-process isolation is required every request). `'fork'` is the Apache MPM prefork runner — a fork-master forks a fresh child per request at true global scope (~1 ms fork cost, **EXPERIMENTAL**, requires `pcntl`+`posix`). `'fcgi'` forwards to an upstream php-fpm pool via `App::fcgiAddress()` — no child process at all. See [tasks-and-concurrency.md](./tasks-and-concurrency.md) for the trade-off table.
- `coprocess()` / `coproc()` create dedicated processes with coroutine support for longer-running workloads that should not block the main worker. These helpers are only available when superglobals are enabled (`coproc` throws otherwise).

### Custom CGI backends — host any language

`App::cgiMode()` sets the strategy for **`.php`** files framework-wide. To serve **other languages** — Perl, Python, Ruby, shell, or anything that speaks CGI/1.1 or FastCGI — register a per-extension backend with `App::registerCgiBackend(string $extension, array $config)` before `$app->run()`. Unregistered extensions fall back to `App::$cgi_mode`.

```php
use ZealPHP\App;

// Perl via proc (Apache `AddHandler cgi-script .pl` parity)
App::registerCgiBackend('.pl', [
    'mode'        => 'proc',
    'interpreter' => '/usr/bin/perl',
]);

// Python via FastCGI — forward to a warm Python FCGI daemon
App::registerCgiBackend('.py', [
    'mode'        => 'fcgi',
    'address'     => '127.0.0.1:9001',        // or unix:/run/python-fpm.sock
    'fcgi_params' => ['APP_ENV' => 'prod'],   // merged into the CGI env
]);

// .cgi via shebang — the OS reads the #! line, no explicit interpreter
// 'exec_paths' is the ExecCGI scope (see below) — only execute under /cgi-bin
App::registerCgiBackend('.cgi', ['mode' => 'proc', 'exec_paths' => ['/cgi-bin']]);
```

The supported `mode` values for `registerCgiBackend()`:

| `mode` | What it does | Languages |
|--------|--------------|-----------|
| `'proc'` | `proc_open` spawns the interpreter (or reads the `#!` shebang) per request — Apache CGI semantics | any (`interpreter` optional) |
| `'fork'` | Apache MPM prefork: fork-master forks a fresh child per request at true global scope (~1 ms). **EXPERIMENTAL** — requires `pcntl`+`posix`. | **`.php` only** |
| `'fcgi'` | forwards to a FastCGI daemon at `address` (php-fpm, a Python/Ruby FCGI server, …) — no per-request spawn | any FastCGI/1.0 server |
| `'pool'` | pre-spawned subprocess pool (~1–3 ms warm) | **`.php` only** — passing `'pool'` for a non-`.php` extension throws `InvalidArgumentException` |

#### Works in every lifecycle mode

CGI dispatch is **no longer gated on process-isolation**. A registered non-`.php`
extension is dispatched through its backend in **coroutine mode too** — the
`proc` path uses `OpenSwoole\Coroutine\System::exec()` (or coroutine-aware
`proc_open`), which yields to the scheduler instead of blocking the worker,
supports a POST body on the interpreter's stdin, and can stream. The `.php`
fast path is unchanged (it still uses `cgi_worker.php` under
`processIsolation(true)`, and the in-process `executeFile()` core in coroutine
mode). The `cgiInterpreterResponse()` reader parses a standard RFC 3875 CGI
response off the interpreter's stdout (headers + blank line + body, with a
`Status:` pseudo-header setting the HTTP status) — Apache `mod_cgi` parity.

#### `exec_paths` — the ExecCGI scope (default-off)

`exec_paths` lists the URL path prefixes under which a registered extension is
allowed to execute — ZealPHP's parity for Apache's `Options +ExecCGI` being
**off by default**. A file whose extension is registered but whose request URL
falls **outside** every `exec_paths` prefix is treated as a stray/uploaded
script: it is **neither executed nor served as source** — the framework returns
**403 Forbidden** (no source-leak). Omit `exec_paths` and the extension never
executes via an implicit URL (it is still reachable via `App::include()`, which
applies its own document-root containment check).

```php
// .py executes ONLY under /cgi-bin/* — an uploaded /uploads/evil.py gets 403
App::registerCgiBackend('.py', [
    'mode'       => 'proc',
    'interpreter' => '/usr/bin/python3',
    'exec_paths' => ['/cgi-bin'],
]);
```

#### Implicit URL parity

Implicit routes are registered **per registered extension**, so
`GET /cgi-bin/report.py` runs `public/cgi-bin/report.py` through the `.py`
backend with no explicit `$app->route()` — same shape as Apache serving a
script out of a `cgi-bin` directory.

#### `cgiScriptAlias()` — Apache `ScriptAlias` parity

`App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc'])` marks a URL prefix as an
executable area: any file served under it is treated as executable regardless
of its extension (`mayExecute = true` for the whole prefix). Resolution order in
`App::resolveCgiBackend($absPath, $urlPath)`: ScriptAlias prefixes first
(always executable), then the per-extension registry gated by `exec_paths`, then
an unregistered fallback (`['mode' => App::$cgi_mode]`, `mayExecute = false`).

> **Known limitation.** `cgiScriptAlias()` registers the resolution + ExecCGI
> scope, but URL-level **implicit routing is wired per-extension only**. A
> ScriptAlias-only setup (no matching per-extension backend) is reachable via
> `App::include()` but does **not** yet get an automatic `/{file}.<ext>` route.
> Pair `cgiScriptAlias()` with a `registerCgiBackend()` for the extensions you
> want auto-routed, or add an explicit route. (Follow-up.)

`App::resolveCgiBackend('/path/file.py', '/cgi-bin/file.py')` returns
`['backend' => [...], 'mayExecute' => bool]` for a given path + URL. Full
walkthrough — socket forms, `fcgi_params`, multiple upstream pools, 502/timeout
behaviour — in the [FastCGI backends guide](fastcgi-backends.md). The
framework-wide `'fcgi'` setter (`App::cgiMode('fcgi')` + `App::fcgiAddress()`)
is the "front an existing php-fpm pool" shortcut for when **every**
`public/*.php` should go to one upstream.

## Coroutine-safe exec

In vanilla OpenSwoole, shelling out (`git`, `ffmpeg`, `convert`, …) via PHP's
built-in functions would block the worker — one slow command stalls every
coroutine sharing it. ZealPHP solves this: in coroutine mode (the default),
ZealPHP's function overrides (ext-zealphp preferred, uopz fallback) intercept
`shell_exec`, `exec`, `system`, `passthru`, and the backtick operator, routing
them through `App::exec()` which yields to the scheduler instead of blocking.
Legacy code that shells out works safely with no changes.

- **`App::exec(string $cmd, ?float $timeout = null): array{output, code, signal}`**
  — coroutine-safe command execution. Inside a coroutine
  (`Coroutine::getCid() >= 0`) it yields to the scheduler via
  `OpenSwoole\Coroutine\System::exec()`; outside one (boot / CLI) it falls back
  to the blocking `App::rawExec()` path. The return shape is identical either
  way: `output` (captured stdout), `code` (exit code), `signal` (terminating
  signal, `0` if none). `$timeout` is the coroutine-mode budget in seconds
  (`null` = no timeout).
- **`App::rawExec(string $cmd): ?string`** — explicit blocking escape hatch.
  Returns captured stdout (or `null` if the process failed to start). It is
  built on `proc_open` *deliberately* — never on `shell_exec` / `exec` /
  `system` / `passthru` / `popen` — because those builtins are overridden
  (ext-zealphp / uopz) when the exec hook is on; routing through `proc_open`
  (which is **not** overridden) keeps this escape hatch recursion-safe.
- **`App::hookExec(?bool)` / `App::$hook_exec`** — toggles the transparent
  override described in [Bootstrapping](#bootstrapping). `null` (the default)
  resolves to **on in coroutine mode** (`superglobals === false`); a non-null
  value forces it on/off. When on, `shell_exec`, `exec`, `system`, `passthru`,
  and the backtick operator all route through `App::exec()`. Uses the same
  override mechanism (ext-zealphp preferred, uopz fallback) as `header()` and
  the `session_*()` shims.

> CGI backends and the exec hook work in **all** lifecycle modes. New
> ZealPHP-native code should still prefer explicit `App::exec()` / native
> coroutine handlers over shelling out, but the override means unmodified legacy
> code stops blocking the worker automatically.

## Task Workers

`$server->task([...])` dispatches background jobs to OpenSwoole task workers. ZealPHP provides a simple convention: task handlers live in `task/<name>.php` and define a closure matching the filename. Within an API or route handler, pass the handler path and arguments, then process the asynchronous response in the finish callback. See `api/swoole/task.php` and `task/backup.php` for the reference pairing.

## Middleware and PSR Integration

ZealPHP speaks PSR-7 (HTTP messages) and PSR-15 (HTTP server middleware). Custom middleware implements `Psr\Http\Server\MiddlewareInterface` and is pushed via `App::addMiddleware()`. Items are buffered until `App::run()` registers them, ensuring everything executes within the same PSR stack alongside the built-in `ResponseMiddleware`.

Common use cases:

- Authentication/authorisation checks
- CSRF validation for form endpoints
- Request/response logging
- Response header shaping (e.g., HSTS, CORS)

See [middleware-and-authentication.md](middleware-and-authentication.md) for concrete examples.

## Session Management

`SessionManager` orchestrates cookie-based sessions that mimic native PHP semantics:

- Generates IDs via `session_create_id()` unless an incoming cookie or request parameter provides one (configurable to use-only-cookies).
- Persists session state using the custom `FileSessionHandler`.
- Attaches request/response wrappers to `G` so handlers can reach `ZealPHP\HTTP\Request` and `ZealPHP\HTTP\Response`.

When superglobals are disabled, `CoSessionManager` applies the same behaviour while remaining coroutine-safe.

## Error Handling and Logging

- Use `elog($message, $level)` to emit structured logs. Levels such as `"warn"`, `"error"`, and `"task"` are used throughout the framework.
- `jTraceEx($exception)` builds Java-style stack traces for easier debugging.
- When `App::$display_errors` is false, clients receive generic `500 Internal Server Error` responses even if the server logs the detailed exception.

## Choosing Between Execution Modes

### One-call presets — `App::mode()`

The canonical way to configure the lifecycle is a single `App::mode()` call before `App::init()`. It sets both the superglobals strategy and the isolation axis in one shot:

```php
App::mode(App::MODE_COROUTINE);          // recommended default — modern apps
App::mode(App::MODE_LEGACY_CGI);         // unmodified WordPress / Drupal
App::mode(App::MODE_COROUTINE_LEGACY);   // legacy request-style PHP run concurrently (requires ext-zealphp)
App::mode(App::MODE_MIXED);              // Symfony / Laravel — real $_SESSION, no CGI fork cost
```

| Preset constant | `superglobals` | Isolation | Use for |
|---|---|---|---|
| `App::MODE_COROUTINE` (`’coroutine’`) | `false` | `Coroutine` | Modern ZealPHP apps — concurrent coroutine I/O, no superglobals |
| `App::MODE_LEGACY_CGI` (`’legacy-cgi’`) | `true` | `CgiPool` (~1–3 ms warm) | Unmodified WordPress / Drupal — `require_once`-heavy apps needing full process isolation |
| `App::MODE_COROUTINE_LEGACY` (`’coroutine-legacy’`) | `true` | `Coroutine` + full isolation stack | Legacy request-style PHP running **concurrently** — the PHP-FPM mental model under OpenSwoole. **Requires ext-zealphp.** |
| `App::MODE_MIXED` (`’mixed’`) | `true` | `None` | Symfony / Laravel — real `$_SESSION`, sequential per-worker, no CGI fork cost |

`App::mode()` is sugar over `App::superglobals()` and `App::isolation()`. For finer control, see the [Lifecycle setters](#lifecycle-setters-v0223-fine-grained-control-with-safe-by-default) section below.

> **coroutine-legacy** is the framework’s compatibility runtime: it runs traditional request-style PHP under coroutine concurrency with per-coroutine isolation of the 7 superglobals, `$GLOBALS` (including object-valued), function-local `static $x`, `ini_set()`, `require_once` re-execution, and `exit`/`die` worker survival (`define()` isolation is a separate opt-in via `App::defineIsolation(true)`). See [/coroutines#lifecycle-modes](/coroutines#lifecycle-modes) for the full isolation matrix and preload requirements.

Pick the mode that matches your application’s profile. You can call `App::mode()` (or set the individual knobs) early in `app.php` before calling `App::init()`.

## Lifecycle setters (v0.2.23+) — fine-grained control with safe-by-default

Historically `App::superglobals()` bundled four orthogonal decisions into one flag: storage strategy, include dispatch, coroutine auto-wrapping, and runtime I/O hooks. As of v0.2.23, each is exposed as its own fluent static setter so applications can mix-and-match for their workload (Symfony wants real `$_SESSION` but no per-include fork cost; testing wants per-request isolation without `HOOK_ALL`; etc.). Every new knob defaults to `null` and resolves to a `App::$superglobals`-derived default at `App::run()` time — apps that don't touch them see no behaviour change.

### The five setters

Configure these BEFORE `App::init()`. Each is a no-arg getter / one-arg setter (the same `App::superglobals()` convention).

| Setter | Signature | Default (when `null`) | Controls |
|---|---|---|---|
| `App::superglobals(bool)` | `superglobals(bool $enable = true): void` | — (explicit default `true`) | `$g` storage strategy: process-wide PHP superglobals (`true`) vs per-coroutine `RequestContext` in `Coroutine::getContext()` (`false`). Also picks `SessionManager` (true) vs `CoSessionManager` (false). |
| `App::processIsolation(bool)` | `processIsolation(?bool $on = null): bool` | follows `App::$superglobals` | `App::include()` dispatch: `true` routes each `.php` file through a subprocess (strategy chosen by `cgiMode()` — default `'pool'`, ~1–3 ms warm; `'proc'` fallback is ~30–50 ms cold — true global-scope isolation, Apache mod_php parity); `false` runs in-process through `App::executeFile()`. |
| `App::enableCoroutine(bool)` | `enableCoroutine(?bool $on = null): bool` | follows `!App::$superglobals` | OpenSwoole's `enable_coroutine` server setting — whether each inbound request is auto-wrapped in its own coroutine. `false` makes a worker handle one request at a time synchronously. |
| `App::hookAll(bool\|int\|null)` | `hookAll($on = null): int` | follows `!App::$superglobals` (`HOOK_ALL` or `0`) | `OpenSwoole\Runtime::enableCoroutine($flags)` — process-wide PHP I/O hooks that make blocking calls (fopen, fread, curl, mysqli, ...) yield to the scheduler. Accepts `true` (HOOK_ALL), `false` (0), or an explicit `int` bitmask. **`PDO_MYSQL`/`mysqli` on mysqlnd ARE coroutinized** (mysqlnd rides `php_stream`, which the stream/TCP hooks intercept — no dedicated `HOOK_PDO`); `libpq`-based `PDO_PGSQL`, Oracle/ODBC stay blocking. Hooking makes I/O non-blocking ≠ a shared connection safe across coroutines — use a per-coroutine connection/pool. |
| `App::cgiMode(string)` | `cgiMode(?string $mode = null): string` | `'pool'` | CGI dispatch strategy when `processIsolation()` is on. `'pool'` (default) — pre-spawned subprocess pool, ~1–3 ms warm, mod_php-style isolation; `'proc'` — fresh PHP per request via `proc_open` (~30–50 ms cold, full WordPress/Drupal compat); `'fork'` — Apache MPM prefork runner, fresh child per request at true global scope (~1 ms fork cost, **EXPERIMENTAL**, `.php`-only, requires `pcntl`+`posix`); `'fcgi'` (v0.2.39+) — forward to a FastCGI backend via `App::fcgiAddress()` (no child process). |

Worked examples — one line per setter:

```php
App::superglobals(false);                       // per-coroutine $g, CoSessionManager
App::processIsolation(false);                   // skip the proc_open fork in App::include()
App::enableCoroutine(true);                     // OpenSwoole auto-coroutines per request
App::hookAll(\OpenSwoole\Runtime::HOOK_ALL);    // hook curl/fopen/mysqli (not PDO)
App::cgiMode('fcgi');                           // dispatch legacy includes to php-fpm
```

### Supported mode matrix

| `App::mode()` preset | `superglobals` | `processIsolation` | `enableCoroutine` | `hookAll` | When to use |
|---|---|---|---|---|---|
| `MODE_LEGACY_CGI` | `true` | `true` (CgiPool) | `false` | `0` | Unmodified WordPress / Drupal — `define()`-heavy plugins need process isolation per request (~1–3 ms warm pool by default) |
| `MODE_COROUTINE` | `false` | `false` | `true` | `HOOK_ALL` | Modern apps benefiting from concurrent coroutine I/O; OpenSwoole-native code |
| `MODE_COROUTINE_LEGACY` | `true` | `false` | `true` | `HOOK_ALL` | Legacy request-style PHP running concurrently with per-coroutine isolation of all request-state primitives. **Requires ext-zealphp.** |
| `MODE_MIXED` | `true` | `false` | `false` | `0` | Symfony / Laravel on ZealPHP — real `$_SESSION` needed, but no per-include CGI fork cost. Sequential per worker → no race risk on superglobals |
| *(custom)* Coroutine without HOOK_ALL | `false` | `false` | `true` | `0` | Per-request coroutine isolation but no auto I/O hooks (e.g. testing, custom hooks) |

The default coupling — `null` everywhere — preserves the historical behaviour for any app that doesn't touch these knobs. The `zealphp-symfony` bridge uses `superglobals(true) + processIsolation(false) + sessionLifecycle(false)` to get the Mixed-mode lifecycle.

### Unsafe combinations — boot-time refusal (v0.2.27+)

`App::run()` invokes `App::validateLifecycleCombination()` after resolving the four knobs, and **throws `RuntimeException` at boot** for genuinely unsafe shapes:

- `superglobals(false) + enableCoroutine(false)` — **always throws** unconditionally. `CoSessionManager` requires the coroutine scheduler for per-request `RequestContext` isolation; this combination is never safe.
- `superglobals(true) + enableCoroutine(true)` — throws **only when ext-zealphp is absent**. Without it, concurrent coroutines race the process-wide `$_GET` / `$_POST` / `$_SESSION` arrays. **With ext-zealphp loaded, this combination is fully supported** — it is exactly `App::mode(App::MODE_COROUTINE_LEGACY)`, where ext-zealphp's scheduler hooks snapshot/restore superglobals per coroutine.
- `superglobals(true) + hookAll(non-zero)` — throws **only when ext-zealphp is absent**, for the same reason: hooked I/O can yield mid-request. With ext-zealphp, coroutine-legacy mode uses this combination safely.

Pre-v0.2.27 these were `elog()`'d at warn level into `/tmp/zealphp/debug.log` but didn't refuse — in practice the warning was invisible to anyone not actively reading the debug log, and the unsafe configuration is how cross-request state-leak bugs ship to production. v0.2.27 changes this to a hard throw at `App::run()` boot — fail loud, fail fast, before any request can be served against a broken contract. Apps that need a refused combination for security-audit or debugging purposes can fork and remove the throw at `App::validateLifecycleCombination()`.
