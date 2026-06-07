# FastCGI Backends

ZealPHP can forward requests to any server that speaks the **FastCGI 1.0**
protocol — php-fpm, HHVM, a Python/Ruby/Perl FastCGI daemon, or your own
implementation — instead of running the target file in-process. This is the
`'fcgi'` CGI mode: ZealPHP becomes the HTTP / WebSocket / coroutine front
layer, and the upstream FastCGI server is the runtime that actually executes
the script. It's the same role nginx's `fastcgi_pass` and Apache's
`mod_proxy_fcgi` play, built into the framework.

There are two ways to wire it: **framework-wide** (every `public/*.php` goes
to one upstream) and **per-extension** (different file types route to
different backends). Both are configured before `$app->run()`.

---

## When to use FastCGI mode

| Situation | Use |
|-----------|-----|
| You have a tuned php-fpm pool you don't want to retire | Framework-wide `cgiMode('fcgi')` — front the pool, keep its sizing/observability |
| Mixed-language app — `.php` + `.py` + `.pl` side by side | Per-extension `registerCgiBackend()` with an `'fcgi'` backend per language |
| Want warm-interpreter performance without ZealPHP's per-request fork | An external FastCGI pool keeps interpreters warm across requests (~1–3 ms) |
| New ZealPHP-native code | **Don't** use FastCGI — write native routes / coroutines; FastCGI is for hosting *external* runtimes |

CGI dispatch works in **every lifecycle mode** — including coroutine mode. A
registered non-`.php` extension is dispatched through its backend regardless of
`superglobals` / `processIsolation`; in coroutine mode the `proc` path yields to
the scheduler (via coroutine-aware `proc_open` / `Coroutine\System::exec()`)
instead of blocking the worker, and `fcgi` forwarding is non-blocking too. The
`.php` fast path still uses `cgi_worker.php` under `processIsolation(true)` and
the in-process `executeFile()` core in coroutine mode. New ZealPHP-native code
should still prefer native handlers — FastCGI is for hosting *external* runtimes
— but you no longer have to be in Legacy CGI mode to register a backend.

---

## Framework-wide: front one upstream pool

`App::cgiMode('fcgi')` routes **every** `public/*.php` file to a single
upstream FastCGI server set by `App::fcgiAddress()`:

```php
use ZealPHP\App;

App::superglobals(true);
App::processIsolation(true);
App::cgiMode('fcgi');                      // 'pool' (default) | 'proc' | 'fork' | 'fcgi'
App::fcgiAddress('127.0.0.1:9000');        // php-fpm's default TCP listener

$app = App::init('0.0.0.0', 8080);
$app->run();
```

The address accepts either a **TCP socket** (`host:port`) or a **Unix domain
socket** (`unix:/path/to.sock`):

```php
App::fcgiAddress('127.0.0.1:9000');             // TCP
App::fcgiAddress('unix:/run/php/php-fpm.sock');  // Unix socket (lower overhead, same host)
```

Throughput in this mode equals whatever your upstream pool delivers minus one
local socket hop — ZealPHP doesn't run PHP at all for these files.

---

## Per-extension: register a backend per file type

`App::registerCgiBackend(string $extension, array $config)` maps a file
extension to a backend. Call it once per extension before `$app->run()`. The
`$config` array:

| Key | Type | Required | Meaning |
|-----|------|----------|---------|
| `mode` | `'pool'` \| `'proc'` \| `'fork'` \| `'fcgi'` | **yes** | Dispatch strategy. `'pool'` is `.php`-only (pre-spawned warm subprocess pool, ~1–3 ms). `'proc'` spawns a fresh process per request via `proc_open` (~30–50 ms). `'fork'` is `.php`-only — Apache MPM prefork style: a fork-master forks a fresh child per request at true global scope (~1 ms fork cost, **EXPERIMENTAL**, requires `pcntl`+`posix`). `'fcgi'` forwards to an external FastCGI upstream. |
| `address` | `string` | **for `fcgi`** | Upstream socket — `host:port` or `unix:/path.sock`. Throws if missing in `fcgi` mode. |
| `interpreter` | `string` | no | For `proc` mode — the binary to exec (e.g. `/usr/bin/perl`). Omit to rely on the file's `#!` shebang. |
| `fcgi_params` | `array<string,string>` | no | Extra CGI environment variables merged into the FastCGI `PARAMS` record. nginx `fastcgi_param` parity. |
| `exec_paths` | `array<int,string>` | no | **ExecCGI scope.** URL path prefixes (e.g. `['/cgi-bin']`) under which this extension is allowed to execute via an implicit URL. A request for a registered extension **outside** every prefix is neither executed nor served as source — it returns **403** (Apache `Options +ExecCGI` default-off parity). Omit to disable implicit-URL execution entirely (the file stays reachable via `App::include()`). |

### Python via a FastCGI daemon

```php
App::registerCgiBackend('.py', [
    'mode'        => 'fcgi',
    'address'     => '127.0.0.1:9001',           // your Python FCGI server
    'fcgi_params' => ['APP_ENV' => 'prod'],      // injected into the CGI env
]);
// GET /report.py → forwarded to the FastCGI server at 127.0.0.1:9001
```

Any FastCGI/1.0 server works — e.g. a `flup`-based Python WSGI-to-FCGI bridge,
a Ruby `fcgi` daemon, or a Perl `FCGI` process manager. ZealPHP only needs the
address; the daemon owns the runtime + its own worker pool.

### Multiple upstreams in one app

Different extensions can point at different servers — mix languages, or split
load across pools:

```php
App::registerCgiBackend('.php', ['mode' => 'fcgi', 'address' => 'unix:/run/php/php-fpm.sock']);
App::registerCgiBackend('.py',  ['mode' => 'fcgi', 'address' => '127.0.0.1:9001']);
App::registerCgiBackend('.rb',  ['mode' => 'fcgi', 'address' => '127.0.0.1:9002']);
```

Unregistered extensions fall back to the framework-wide `App::$cgi_mode`
(default `'pool'` — the warm subprocess pool). Inspect what a path resolves to:

```php
$resolved = App::resolveCgiBackend('/var/www/app/report.py', '/cgi-bin/report.py');
// ['backend' => ['mode' => 'fcgi', 'address' => '127.0.0.1:9001', ...], 'mayExecute' => true]
```

`resolveCgiBackend()` takes the absolute path **and** the request URL path,
returning `['backend' => [...], 'mayExecute' => bool]`. `mayExecute` is the
ExecCGI gate: `true` for any path under a `cgiScriptAlias()` prefix or under one
of the backend's `exec_paths`; `false` otherwise (the dispatcher then returns
403 rather than executing or leaking source).

### ScriptAlias-style executable areas

`App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc'])` is the Apache `ScriptAlias`
parity: every file served under the URL prefix is treated as executable
regardless of extension. It takes the same `mode` / `interpreter` / `address` /
`fcgi_params` config as `registerCgiBackend()`.

```php
App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3']);
```

> **Implicit URL routing is automatic for ScriptAlias prefixes.** `run()`
> registers a `patternRoute('#^<prefix>/(?P<rest>.+?)/?$#', …)` for every
> `cgiScriptAlias()` prefix (`registerImplicitRoutes()`), so a ScriptAlias-only
> setup — no matching `registerCgiBackend()` — gets an automatic GET/POST route:
> `cgiScriptAlias('/cgi-bin', ['mode' => 'proc'])` alone makes `GET /cgi-bin/hello.sh`
> work (the file runs via its `#!` shebang). A per-extension backend is only needed
> when you want a specific interpreter (e.g. `.py` → python3).

---

## What gets sent upstream

For each request routed to an `fcgi` backend, ZealPHP builds the CGI/1.1
environment (via `App::buildCgiEnv()` — the same RFC 3875 variables Apache and
nginx send: `SCRIPT_FILENAME`, `REQUEST_METHOD`, `QUERY_STRING`, the
`HTTP_*` headers, etc.), merges your `fcgi_params`, reads the request body as
the FastCGI `STDIN` stream, and forwards it via the bundled FastCGI client
(`ZealPHP\CGI\FastCgiClient` — a standalone FCGI 1.0 implementation:
`BEGIN_REQUEST` → `PARAMS` → `STDIN` → `STDOUT`/`STDERR` → `END_REQUEST`). The
upstream's response status, headers, and body are applied to the ZealPHP
response.

`HTTP_PROXY` is **never** forwarded (httpoxy / CVE-2016-5385 mitigation) —
same as Apache's `mod_cgi`.

---

## Errors and timeouts

- **Upstream down / connection refused** → ZealPHP returns **502 Bad Gateway**,
  the same shape Apache and nginx emit when their FastCGI upstream is
  unreachable. The framework logs the `FastCgiException` via `elog()`.
- **Slow upstream** → bounded by `App::$cgi_timeout` (default 60 s). Override
  with `App::$cgi_timeout = 120;` before `App::init()`.

---

## What does NOT speak FastCGI

`fcgi` mode requires a FastCGI/1.0 server on the other end. It is **not** an
HTTP reverse proxy — you can't point `address` at an HTTP backend. For
HTTP-upstream proxying, put a real proxy (nginx, Caddy, Traefik) in front of
ZealPHP, or use a coroutine HTTP client from within a native handler.

For per-request *process* isolation of legacy PHP (no external server), use
`cgiMode('pool')` (warm FPM-style subprocess pool — the default),
`cgiMode('proc')` (fresh interpreter per request via `proc_open`), or
`cgiMode('fork')` (Apache MPM prefork style — see below) — see
[runtime-architecture.md](runtime-architecture.md) and
[Apache parity](apache-parity.md).

### `cgiMode('fork')` — Apache MPM prefork runner (EXPERIMENTAL)

`cgiMode('fork')` is an **experimental** `.php`-only CGI strategy that mirrors
Apache's MPM prefork model: a long-lived fork-master process
(`src/fork_master.php`) binds a UNIX socket and forks a **fresh child per
request**. The child runs the target `.php` file at true global scope
(no "Cannot redeclare class" — identical correctness to `proc_open`), captures
the response, then hard-exits. The fork-master recycles and waits for the next
request.

**Cost:** ~1 ms fork overhead per request — significantly faster than `proc`'s
~30–50 ms `proc_open` cold start, while still providing full process isolation
for unmodified WordPress and other `require_once`-heavy legacy apps.

**Requirements:** `pcntl` and `posix` extensions must be present in the PHP build.

**Tunables:**

| Setting | Default | Meaning |
|---------|---------|---------|
| `App::$cgi_fork_max_concurrent` | `16` | Maximum live child processes. `ForkPool::dispatch()` returns **503** when the limit is reached. |

**How to enable:**

```php
App::cgiMode('fork');   // framework-wide for all public/*.php
// — or per-extension —
App::registerCgiBackend('.php', ['mode' => 'fork']);
```

> **Note:** `cgiMode('fork')` is reachable only via `App::cgiMode('fork')` or
> `registerCgiBackend('.php', ['mode' => 'fork'])`. There is no `App::mode()`
> preset or `App::isolation()` shortcut for it. It is `.php`-only — passing
> `'fork'` for a non-`.php` extension is not supported.

Backed by `src/CGI/ForkPool.php` + `src/fork_master.php`; dispatched via
`Dispatcher::cgiFork()`. Design doc:
`docs/architecture/2026-06-02-fork-per-request-cgi-pool.md`.

---

## Reference

- Framework-wide setter: `App::cgiMode()` + `App::fcgiAddress()`
- Per-extension registry: `App::registerCgiBackend()` / `App::resolveCgiBackend()`
- FastCGI client: `ZealPHP\CGI\FastCgiClient` (in the [API reference](/docs/api/classes/ZealPHP-CGI-FastCgiClient.html))
- Runnable demo: `examples/multi-lang-cgi/`
- Related: [tasks-and-concurrency.md](tasks-and-concurrency.md) (the CGI-mode trade-off table), [runtime-architecture.md](runtime-architecture.md) (lifecycle setters)
