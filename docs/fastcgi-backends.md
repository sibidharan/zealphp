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

FastCGI mode requires `App::superglobals(true)` + `App::processIsolation(true)`
(the CGI dispatch path). In coroutine mode, write native handlers instead.

---

## Framework-wide: front one upstream pool

`App::cgiMode('fcgi')` routes **every** `public/*.php` file to a single
upstream FastCGI server set by `App::fcgiAddress()`:

```php
use ZealPHP\App;

App::superglobals(true);
App::processIsolation(true);
App::cgiMode('fcgi');                      // 'proc' (default) | 'fork' | 'fcgi'
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
| `mode` | `'proc'` \| `'fork'` \| `'fcgi'` | **yes** | Dispatch strategy. `'fork'` is `.php`-only (it clones the warm PHP worker). |
| `address` | `string` | **for `fcgi`** | Upstream socket — `host:port` or `unix:/path.sock`. Throws if missing in `fcgi` mode. |
| `interpreter` | `string` | no | For `proc` mode — the binary to exec (e.g. `/usr/bin/perl`). Omit to rely on the file's `#!` shebang. |
| `fcgi_params` | `array<string,string>` | no | Extra CGI environment variables merged into the FastCGI `PARAMS` record. nginx `fastcgi_param` parity. |

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
(default `'proc'`). Inspect what a path resolves to:

```php
$backend = App::resolveCgiBackend('/var/www/app/report.py');
// ['mode' => 'fcgi', 'address' => '127.0.0.1:9001', 'fcgi_params' => ['APP_ENV' => 'prod']]
```

---

## What gets sent upstream

For each request routed to an `fcgi` backend, ZealPHP builds the CGI/1.1
environment (via `App::buildCgiEnv()` — the same RFC 3875 variables Apache and
nginx send: `SCRIPT_FILENAME`, `REQUEST_METHOD`, `QUERY_STRING`, the
`HTTP_*` headers, etc.), merges your `fcgi_params`, reads the request body as
the FastCGI `STDIN` stream, and forwards it via the bundled FastCGI client
(`ZealPHP\Legacy\FastCgiClient` — a standalone FCGI 1.0 implementation:
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
`cgiMode('proc')` (fresh interpreter per request) or `cgiMode('fork')` (warm
fork) instead — see [runtime-architecture.md](runtime-architecture.md) and
[legacy-apps](apache-parity.md).

---

## Reference

- Framework-wide setter: `App::cgiMode()` + `App::fcgiAddress()`
- Per-extension registry: `App::registerCgiBackend()` / `App::resolveCgiBackend()`
- FastCGI client: `ZealPHP\Legacy\FastCgiClient` (in the [API reference](/docs/api/classes/ZealPHP-Legacy-FastCgiClient.html))
- Runnable demo: `examples/multi-lang-cgi/`
- Related: [tasks-and-concurrency.md](tasks-and-concurrency.md) (the CGI-mode trade-off table), [runtime-architecture.md](runtime-architecture.md) (lifecycle setters)
