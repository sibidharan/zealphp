# Environment Variables

This is the canonical reference for every `ZEALPHP_*` environment variable read by the framework. Each entry lists the exact variable name, the value the code falls back to when it is unset, its scope, and what it controls.

**Boolean (`env_flag`) semantics.** Most on/off toggles are parsed by `env_flag()` (`src/utils.php`), which treats `1` / `true` / `on` / `yes` as **true** and `0` / `false` / `off` / `no` / `none` as **false** (case-insensitive). A handful of vars use stricter rules instead (only the literal `'1'` enables/disables, or "anything not `false`/empty/`0`") — those are called out per-row.

**Precedence.** For variables that also have a CLI flag (port, host, workers, daemonize, PID file, dev hot-reload, task workers), the resolution order is **CLI flag > environment variable > `app.php` default**. The CLI-relevant subset of these is also documented in [`docs/cli.md`](cli.md). The dev hot-reload var is also covered in [`docs/hot-reload.md`](hot-reload.md).

> Variables marked **internal**, **test**, or **build/CI** are not user knobs — internal vars are IPC/runtime channels the framework sets for its own subprocesses (e.g. `ZEALPHP_REQUEST_CONTEXT`, `ZEALPHP_CWD`). Do **not** set those by hand. They live in their own [section](#internal-test--buildci-variables) at the bottom so they aren't confused with user-facing configuration.

---

## Server & CLI

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_PORT` | `8080` | user | TCP port the HTTP/WebSocket server binds to (read in `app.php` via `$envInt('ZEALPHP_PORT', 8080)`, min 1). |
| `ZEALPHP_HOST` | `0.0.0.0` | user | Bind host passed to `App::init()` (`getenv('ZEALPHP_HOST') ?: '0.0.0.0'`). |
| `ZEALPHP_WORKERS` | unset → `\ZealPHP\default_worker_count(4)` (conservative 4, capped to the cgroup CPU quota) | user | Sets the OpenSwoole `worker_num` setting (HTTP worker count); applied when set (`max(1, (int)value)`). When unset, `app.php` defaults to `\ZealPHP\default_worker_count(4)` instead of letting OpenSwoole fall back to `swoole_cpu_num()` = the **host** CPU count — which over-spawns in a cgroup-CPU-limited container (e.g. 24 workers in a 6-CPU Docker container) and OOMs. The default is `min(4, floor(cgroup_quota))`. |
| `ZEALPHP_TASK_WORKERS` | `8` | user | Sets the OpenSwoole `task_worker_num` setting (`$envInt('ZEALPHP_TASK_WORKERS', 8, 0)`, min 0 so `0` disables task workers). |
| `ZEALPHP_DAEMONIZE` | `false` | user | When truthy (`env_flag`), sets the OpenSwoole `daemonize` setting so the server runs in the background. |
| `ZEALPHP_PID_FILE` | `<log-dir>/zealphp_<port>.pid` | user | Explicit path for the server PID file (read in `app.php` and `App::resolvePidFile()` so start and stop/status agree); falls back to the resolved log dir (default `/tmp/zealphp`). |
| `ZEALPHP_DEV` | `false` (disabled) | user | Enables dev route hot-reload (workers poll `route/*.php` mtimes and call `reloadRoutes()`). `App::devReload()` treats it as enabled when it is **not** `false`, `''`, or `'0'` (note: NOT `env_flag` semantics, so `'no'`/`'off'` count as enabled). |
| `ZEALPHP_MAX_CONN` | unset → OpenSwoole `max_conn` default | user | Sets the OpenSwoole `max_conn` setting (max concurrent connections); applied only when set (`max(1, (int)value)`). |
| `ZEALPHP_MAX_COROUTINE` | unset → OpenSwoole `max_coroutine` default | user | Sets the OpenSwoole `max_coroutine` setting (max coroutines per worker); applied only when set (`max(1, (int)value)`). |
| `ZEALPHP_BACKLOG` | unset → OpenSwoole `backlog` default | user | Sets the OpenSwoole `backlog` setting (listen backlog queue length); applied only when set (`max(1, (int)value)`). |
| `ZEALPHP_REACTOR_NUM` | unset → OpenSwoole `reactor_num` default | user | Sets the OpenSwoole `reactor_num` setting (reactor thread count); applied only when set (`max(1, (int)value)`). |
| `ZEALPHP_MAX_REQUEST` | `100000` | user | Sets the OpenSwoole `max_request` setting (requests a worker handles before recycling); read in `App::run()` via `(int)(getenv('ZEALPHP_MAX_REQUEST') ?: 100000)`. |
| `ZEALPHP_TZ` | unset → no override (keeps php.ini `date.timezone`) | user | When set and non-empty, `app.php` calls `date_default_timezone_set()` with this value. |

---

## Logging

> `ZEALPHP_LOG_DIR` has a per-user fallback chain — when unset, the resolver tries `/tmp/zealphp`, then per-user XDG/temp dirs, then `./tmp/zealphp` and `./logs/zealphp`. See [`docs/cli.md`](cli.md) and [`docs/hot-reload.md`](hot-reload.md) for the log/PID directory behaviour.

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_LOG_DIR` | unset → first writable candidate (`/tmp/zealphp`, then per-user XDG/temp, then `./tmp/zealphp`, `./logs/zealphp`) | user | Explicit override for the directory ZealPHP writes logs and per-port PID files into (read in `zealphp_log_dir_candidates()` in `src/utils.php` and the PID-dir/logs resolution in `App.php`). |
| `ZEALPHP_LOG_FILE` | unset → kind-specific default under `resolve_log_dir()` (`access.log` / `zlog.log` / `debug.log`) | user | Single fallback log-file path used for every log kind when the kind-specific `*_LOG_FILE` var is unset (`log_file_for()` in `src/utils.php`). |
| `ZEALPHP_ACCESS_LOG` | `true` (also forced off when `ZEALPHP_BENCH_MODE` is on) | user | `env_flag` toggle for access logging (`access_logging_enabled()` in `src/utils.php`); `0`/`false`/`off`/`no`/`none` disables it. |
| `ZEALPHP_ACCESS_LOG_FILE` | unset → falls back to `ZEALPHP_LOG_FILE`, then `resolve_log_dir()/access.log` | user | Explicit file path for the access log (the `access` kind in `log_file_for()`). |
| `ZEALPHP_DEBUG_LOG` | unset/empty → debug logging enabled (also forced off when `ZEALPHP_BENCH_MODE` is on) | user | Toggle for debug logging (`debug_logging_enabled()` in `src/utils.php`); `0`/`false`/`off`/`no`/`none` disables it, else on (falls through to `ZEALPHP_ELOG` when unset/empty). |
| `ZEALPHP_DEBUG_LOG_FILE` | unset → falls back to `ZEALPHP_LOG_FILE`, then `resolve_log_dir()/debug.log` | user | Explicit file path for the debug log (the `debug` kind in `log_file_for()`). |
| `ZEALPHP_ZLOG_FILE` | unset → falls back to `ZEALPHP_LOG_FILE`, then `resolve_log_dir()/zlog.log` | user | Explicit file path for the zlog (the `zlog` kind in `log_file_for()`). |
| `ZEALPHP_SERVER_LOG_FILE` | unset → empty; daemonize mode defaults to `<logDir>/server.log` (the `logs` CLI command surfaces `resolve_log_dir()/server.log`) | user | Path for OpenSwoole's own server log (wired into `$settings['log_file']` in `app.php`). |
| `ZEALPHP_ELOG` | unset/empty → debug logging stays enabled | user | Legacy alias consulted by `debug_logging_enabled()` only when `ZEALPHP_DEBUG_LOG` is unset/empty; same `0`/`false`/`off`/`no`/`none` disabling semantics. |
| `ZEALPHP_LOG_ASYNC` | `true` | user | `env_flag` toggle for the async coroutine-channel log sink (`async_logging_enabled()`); when disabled, logs are written synchronously via `fopen`+`fwrite`. |
| `ZEALPHP_RECYCLE_LOG` | `true` | user | `env_flag` toggle gating the worker-recycle observability line in the server's `workerStop` handler (`App.php`); set falsey to silence the request-count/RSS/uptime line. |

---

## Lifecycle & isolation

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_SUPERGLOBALS` | `false` | user | In `app.php`, sets `App::superglobals()` (`env_flag(...,false)`) — process-wide PHP superglobals (`$g` storage) vs per-coroutine `RequestContext`; unset → coroutine mode. |
| `ZEALPHP_PROCESS_ISOLATION` | unset (no override; resolves to follow `$superglobals`) | user | Only when set+non-empty, calls `App::processIsolation(env_flag(...,false))` — per-include CGI subprocess dispatch (Apache mod_php-style) vs in-process `executeFile()`. |
| `ZEALPHP_ENABLE_COROUTINE` | unset (no override; resolves to `!$superglobals`) | user | Only when set+non-empty, calls `App::enableCoroutine(env_flag(...,true))` — toggles OpenSwoole's `enable_coroutine` auto-coroutine-per-request setting. |
| `ZEALPHP_CGI_MODE` | unset (no override; effective default is `pool`) | user | Only when set+non-empty, its raw string value is passed to `App::cgiMode($value)` — selects the `.php` CGI dispatch strategy (`pool`\|`proc`\|`fork`\|`fcgi`). `pool` is the default pre-spawned subprocess pool; `proc` uses `proc_open` per request (~30–50 ms cold start); `fork` is **experimental** (Apache MPM prefork, pcntl+posix required); `fcgi` proxies to an upstream FastCGI pool. |
| `ZEALPHP_HTTP_COMPRESSION` | `!$compressionMiddleware` (true when `CompressionMiddleware` is NOT registered, false when it is) | user | In `app.php`, sets the OpenSwoole `http_compression` server setting (`env_flag`) — toggles OpenSwoole's built-in gzip/deflate response compression. |
| `ZEALPHP_OPCACHE_ADVISORY` | unset (advisory enabled; only literal `'0'` suppresses) | user | Read in `App::opcacheLegacyBootCheck()` via `getenv()==='0'`; when `'0'`, suppresses the boot-time advisory about opcache + coroutine-legacy re-declaring `require_once`'d classes. |
| `ZEALPHP_FN_STATICS_DISABLE` | unset (isolation stays ENABLED in coroutine-legacy; only literal `'1'` disables) | user | Read in `App::mode()` via `(string)getenv()!=='1'` to set `coroutineStaticsIsolation()` — when `'1'`, disables per-coroutine isolation of function-local `static $x` (Stage 5) for raw throughput. |
| `ZEALPHP_FN_STATICS_RESET_DISABLE` | unset (reset ENABLED; any non-empty value not starting with `'0'` disables) | user | Read in ext-zealphp `zealphp_reset_request_statics()`; when set, disables the per-request reset of function-local `static $x` to their templates. |
| `ZEALPHP_CLASS_STATICS_RESET_DISABLE` | unset (reset ENABLED; any non-empty value not starting with `'0'` disables) | user | Read in ext-zealphp `zealphp_reset_request_class_statics()`; when set, disables the per-request reset of class static properties to their templates. |
| `ZEALPHP_POOL_FULL_RESET` | unset (off; only literal `'1'` enables) | user | **EXPERIMENTAL.** Read in `src/pool_worker.php` `pool_reset_request_state()`; when `'1'`, a REUSED `cgiMode('pool')` subprocess (`cgiPoolMaxRequests > 1`) runs the full coroutine-legacy reset stack (`zealphp_reset_request_rtcaches` + `_statics` + `_class_statics`) per request — letting *re-entrant* legacy apps warm-reuse a worker. Cannot make inherited-class-redeclaring apps safe (use the recycle=1 default or `cgiMode('fork')`). Inherited by the subprocess via the parent env. See `docs/architecture/2026-06-02-fork-per-request-cgi-pool.md`. |
| `ZEALPHP_GLOBALS_ISOLATION_DISABLE` | unset (isolation NOT disabled; only literal `'1'` disables) | user | Read in `App::run()` via `(string)getenv()==='1'`; when `'1'`, disables the Stage 2 per-coroutine `$GLOBALS` COW isolation even if `coroutineGlobalsIsolation(true)` was called. |
| `ZEALPHP_GLOBAL_INCLUDE` | unset (off; only literal `'1'` enables) | user | Default source for `App::globalScopeInclude()` when its flag is left `null`. When `'1'` **and** running coroutine-legacy with ext-zealphp 0.3.26+ loaded, the request entry (and its transitive `require_once` tree) runs at **true global scope** via `zealphp_require_global()`, so bare file-scope variables bind into `$GLOBALS` / `EG(symbol_table)` instead of becoming `executeFile()`-local — Stage 8, the gap that makes unmodified `require_once`-bootstrap wp-admin render in-process. The globally-scoped include does NOT receive `$g`/route params (legacy apps read request state via superglobals). Ignored outside coroutine-legacy and when the ext primitive is absent. See [runtime-architecture.md § Stage 8](runtime-architecture.md#stage-8--true-global-scope-request-include-appglobalscopeinclude). |
| `ZEALPHP_INI_ISOLATE` | `false` | user | In `app.php` (`env_flag(...,false)`); when truthy, registers `IniIsolationMiddleware` which snapshots/restores per-request `ini_set()` changes to prevent ini leakage across requests on a worker. |
| `ZEALPHP_ALLOW_COMPILE_HOOK_FILE` | unset (HOOK_FILE dropped in silentRedeclare+coroutine mode; only literal `'1'` opts back in) | user | Read in `App::run()` via `(string)getenv()!=='1'`; when `'1'`, keeps OpenSwoole's `HOOK_FILE` flag enabled under silentRedeclare+enableCoroutine (else stripped to avoid a mid-compile yield SIGSEGV/hang). |

---

## Store & backends

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_STORE_BACKEND` | unset → no override; Store/Counter keep their compiled-in default (Table/atomic) | user | Read at `App::init()` (`src/App.php:1281`): a non-empty value is passed to `Store::defaultBackend()` and `Counter::defaultBackend('redis' if 'redis' else 'atomic')`, flipping the shared-state backend (e.g. `redis`/`tiered`) before `app.php`'s `Store::make()` calls run. |
| `ZEALPHP_REDIS_URL` | `redis://127.0.0.1:6379` | user | Redis/Valkey connection URL used by `Store::redisUrlFromEnv()` (`src/Store.php:207`) and `Counter` (`src/Counter.php:241`) to build the `RedisConnectionPool` for redis/tiered backends. |
| `ZEALPHP_REDIS_PREFER` | unset → no `prefer` option emitted (driver auto-detects: phpredis when ext-redis loaded, else predis) | user | Selects the Redis client lib via `Store::poolOptsFromEnv()` (`src/Store.php:227`) / `Counter` (`src/Counter.php:254`): lowercased, accepted only as `auto`/`phpredis`/`predis` (else ignored); also read in `App::redisBootChecks()` (`src/App.php:4252`) to warn that phpredis SUBSCRIBE deadlocks without HOOK_ALL. |
| `ZEALPHP_MEMCACHED_SERVERS` | `127.0.0.1:11211` | user | Memcached server list used by `Store::memcachedServersFromEnv()` (`src/Store.php:213`) and `Counter` (`src/Counter.php:247`) when building the memcached backend. |
| `ZEALPHP_TIERED_INVALIDATION_SECRET` | unset/empty → `null` (insecure trust mode: any Redis writer can forge an L1-evict message) | user | Shared HMAC secret read by `TieredBackend::__construct()` (`src/Store/TieredBackend.php:64`) when no `invalidationSecret` ctor arg is passed; signs/verifies cross-node L1 cache-invalidation messages, dropping unsigned/mismatched ones. |

---

## Security / site / middleware

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_CORS_ORIGINS` | unset → `["*"]` (wildcard) with a one-time `elog()` warning | user | Comma-separated CORS origin allowlist read by `CorsMiddleware::resolveOriginsList()` when no explicit origins are passed to the constructor; empty/unset → wildcard `*`. |
| `ZEALPHP_SESSION_SECURE` | unset → auto-detect HTTPS (Secure flag true when `HTTPS=on`, `X-Forwarded-Proto=https`, or `SERVER_PORT=443`) | user | When set, forces the session cookie's Secure flag via `filter_var(FILTER_VALIDATE_BOOLEAN)`; unset → auto-detect HTTPS (`src/Session/utils.php`). |
| `ZEALPHP_WS_HMAC` | not read by the framework — app-supplied; effective default is no HMAC until the app wires it | user | Documented in the `WSRouter::setChannelHmacSecret()` docblock (`src/WSRouter.php:261`) as the env var an app reads in `app.php` to pass into `setChannelHmacSecret()`; the framework never calls `getenv` on it. When set, `WSRouter` signs/verifies `ws:server:*` and `ws:room:*` pub/sub publishes with an HMAC envelope. |
| `ZEALPHP_SITE_URL` | unset/empty → falls back to `ZEALPHP_SITE_HOST`, then `https://php.zeal.ninja` | user | Canonical base URL used by `site_url()` (`src/utils.php`) to build absolute URLs; trailing slash trimmed and an `https://` scheme prepended if none present. |
| `ZEALPHP_SITE_HOST` | unset → falls through to `ZEALPHP_SITE_URL` logic, ultimately `https://php.zeal.ninja` | user | Fallback canonical host used by `site_url()` only when `ZEALPHP_SITE_URL` is unset/empty; a bare host gets an `https://` scheme prepended. |
| `ZEALPHP_RATE_LIMIT_LOOPBACK` | unset → loopback requests are NOT rate-limited (skipped) | user | `RateLimitMiddleware` skips rate limiting for loopback client IPs unless this is exactly `'1'`, in which case loopback requests are also counted against the limit (useful for testing). |

---

## Demo-site & Learn app

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_DEMO_MIDDLEWARE` | `false` | user | When truthy (`env_flag`), `app.php` loads `examples/demo_middleware.php` and registers the demo `RequestLogMiddleware` + `QueryDumpMiddleware` (logging-only demo middleware). |
| `ZEALPHP_COMPRESSION_MIDDLEWARE` | `false` | user | When truthy (`env_flag`), `app.php` registers the reference `CompressionMiddleware` (intended only when OpenSwoole's built-in `http_compression` is disabled). |
| `ZEALPHP_ASSET_VERSION` | unset → resolved at boot: git commit short hash (12 chars), else newest mtime across `public/css`+`public/js`, else `time()` | build/CI | Cache-bust token defined as a PHP constant in `app.php` (only if not already defined) and appended as `?v=…` to CSS/JS asset URLs in `template/_head.php`; auto-derived rather than typically set by the user. |
| `ZEALPHP_LEARN_AI_MODEL` | `gpt-4.1-mini` | user | OpenAI model name used by the Learn demo's notes/chat AI agent; read via `getenv()` with a `?: 'gpt-4.1-mini'` fallback in `src/Learn/Chat.php` and `api/learn/chat_status.php`. |
| `ZEALPHP_LEARN_DB_PATH` | `storage/learn.db` | user | Filesystem path to the Learn demo's SQLite database (resolved relative to `ZEALPHP_ROOT` when not absolute) in `src/Learn/DB.php::path()`. |
| `ZEALPHP_LEARN_MAX_NOTES` | `256` | user | Maximum notes a user may create in the Learn demo before `Notes::create()` refuses; read with a `?: 256` fallback in `src/Learn/Notes.php`. |

---

## Internal, test & build/CI variables

These are **not** user-facing configuration. Internal vars are IPC/runtime channels the framework sets for its own subprocesses; test/build vars are set by the test suite or CI. Do not set internal vars (e.g. `ZEALPHP_REQUEST_CONTEXT`, `ZEALPHP_CWD`) by hand.

| Variable | Default | Scope | Description |
|----------|---------|-------|-------------|
| `ZEALPHP_CWD` | unset → no `chdir` (set internally to `App::$cwd`) | internal | CGI-subprocess env var set by `App` from `self::$cwd` and read by `cgi_worker.php` (`getenv('ZEALPHP_CWD')`; chdir's to it when present) so the child runs in the parent's working directory. |
| `ZEALPHP_REQUEST_CONTEXT` | `'{}'` (empty JSON object) when unset | internal | IPC channel — JSON-encoded per-request context (server/get/post/cookie/files/env) `App` passes to the CGI subprocess; `cgi_worker.php` reads it via `json_decode(getenv('ZEALPHP_REQUEST_CONTEXT') ?: '{}', true)` to rebuild superglobals. |
| `ZEALPHP_CGI_AUTOLOAD` | unset (no autoload; treated as off unless exactly `'1'`) | internal | Read in `src/cgi_worker.php` via `getenv()==='1'`; when `'1'`, the CGI subprocess requires `vendor/autoload.php`. Set internally by `App.php` (~line 5695) when `App::cgiSubprocessAutoload(true)`, not by users directly. |
| `ZEALPHP_POOL_MAX_REQUESTS` | `500` | internal | Requests a CGI pool worker handles before recycling; `src/pool_worker.php` reads it with `?: '500'`, and `WorkerPool.php` injects it into each child's env from `App::cgiPoolMaxRequests`. Normally set via the App API, not directly. |
| `ZEALPHP_FORK_SOCK` | unset (required) | internal | UNIX-socket path the `cgiMode('fork')` runner binds. `src/CGI/ForkPool.php` mints a per-worker path and injects it; `src/fork_master.php` reads it via `getenv('ZEALPHP_FORK_SOCK')` and `stream_socket_server`s on it. Set internally, never by users. |
| `ZEALPHP_FORK_MAX_CONCURRENT` | `16` | internal | Live-child cap for the fork-master (Apache MPM `MaxRequestWorkers` analog). `ForkPool` injects it from `App::$cgi_fork_max_concurrent`; `fork_master.php` reads it with `?: '16'` and spins in backpressure while at the cap. |
| `ZEALPHP_FORK_TIMEOUT` | `60` (seconds) | internal | Wall-clock budget after which the fork-master's watchdog SIGKILLs a wedged child and reclaims its concurrency slot. `ForkPool` injects it from `App::$cgi_timeout` (falling back to 60); `fork_master.php` reads it via `getenv('ZEALPHP_FORK_TIMEOUT') ?: '60'`. |
| `ZEALPHP_CGI_DEBUG_DEPRECATIONS` | unset (deprecation warnings suppressed) | user | In proc-mode CGI (`src/cgi_worker.php`), unless set to exactly `'1'` the worker suppresses `E_DEPRECATED`/`E_USER_DEPRECATED` to avoid a stderr-pipe-fill deadlock; `'1'` restores them. |
| `ZEALPHP_POOL_DEBUG_DEPRECATIONS` | unset (deprecation warnings suppressed) | user | In the CGI pool worker (`src/pool_worker.php`), unless set to exactly `'1'` the worker suppresses `E_DEPRECATED`/`E_USER_DEPRECATED` to avoid a stderr-pipe-fill deadlock; `'1'` restores them. |
| `ZEALPHP_ROOT` | repo root (`dirname(__DIR__, 2)`) when the constant is undefined | test | A PHP **constant** (NOT an env var); only `define()`d in `tests/bootstrap.php`, read via `defined()`/`constant()` in `src/Learn/DB.php` (and Learn test files) to locate the SQLite DB, falling back to repo root when undefined. |
| `ZEALPHP_LEARN_RATE_LIMIT_LOOPBACK` | unset (loopback rate-limiting bypassed) | test | When set to exactly `'1'`, re-enables Learn-demo rate limiting for loopback clients (otherwise bypassed so the integration suite can re-run without restarting); checked in `src/Learn/Auth.php::rateLimit()`. |
| `ZEALPHP_COVERAGE_DIR` | unset (coverage collection inert) | test | Directory where the running server dumps per-process `src/` code-coverage `.cov` files; coverage instrumentation in `app.php` only activates when this is a non-empty string and a coverage driver is loaded. |
| `ZEALPHP_BENCH_MODE` | `false` | build/CI | When truthy (`env_flag`), disables all logging for benchmark runs; read once via `env_flag('ZEALPHP_BENCH_MODE', false)` in `bench_mode_enabled()` (`src/utils.php`). |
| `ZEALPHP_SKIP_DOCS_BUILD` | `false` | build/CI | When truthy (`env_flag`), skips the boot-time background API-docs build in `app.php` (CI sets it so an in-flight build doesn't flake timing-sensitive integration tests). |

---

_Excluded from this reference (matched by a `ZEALPHP_*` grep but not real env vars): `ZEALPHP_VERSION` (only the `PHP_ZEALPHP_VERSION` C macro), `ZEALPHP_H` (a `php_zealphp.h` include guard), `ZEALPHP_POOL_WORKER_READY` (a stderr readiness sentinel string in `src/pool_worker.php`), `ZEALPHP_FORK_SERVER_READY` (the same kind of stderr sentinel in `src/fork_master.php`), and `ZEALPHP_HOOK_C` (a `define()` constant in an ext test fixture)._
