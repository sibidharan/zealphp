# Tasks and Concurrency

ZealPHP embraces OpenSwoole’s asynchronous primitives to help you build responsive applications that scale across CPU cores. This document outlines the concurrency toolbox provided by the framework and when to use each option.

> **Lifecycle safety (v0.2.27).** `App::run()` validates the configured lifecycle combination at boot. `superglobals(true) + enableCoroutine(true)` and `superglobals(true) + hookAll(non-zero)` throw `RuntimeException` **only when ext-zealphp is absent** — with ext-zealphp loaded, those two combinations are the supported `App::mode(App::MODE_COROUTINE_LEGACY)` shape (per-coroutine superglobal isolation via ext-zealphp's scheduler hooks). `superglobals(false) + enableCoroutine(false)` always throws. See [runtime-architecture.md](runtime-architecture.md) for the full mode matrix.

## Concurrency lifecycle — `App::mode()` and `App::isolation()`

ZealPHP's concurrency model is selected once, before `App::run()`, via the one-call preset `App::mode(string)`. The four presets cover the most common shapes:

| Preset | Typical use |
|--------|-------------|
| `App::mode(App::MODE_COROUTINE)` | Modern ZealPHP apps — per-request coroutine concurrency, `$g` state isolated per coroutine. Recommended default. |
| `App::mode(App::MODE_LEGACY_CGI)` | Unmodified WordPress / Drupal — each request runs in an isolated subprocess via the pre-spawned CGI pool. |
| `App::mode(App::MODE_COROUTINE_LEGACY)` | Legacy request-style PHP run **concurrently** — modern Composer apps (Symfony, Laravel, Slim) and procedural code that needs per-coroutine isolation of `$_GET`/`$_SESSION`/`$GLOBALS`/function statics/`require_once`/conditional re-declaration. **Requires ext-zealphp.** (`define()` isolation is a separate opt-in via `App::defineIsolation(true)`, not part of the preset.) |
| `App::mode(App::MODE_MIXED)` | Symfony / Laravel bridge — real `$_SESSION`, sequential per-worker, no CGI fork cost. |

`App::isolation(string)` is the lower-level single-axis knob that the presets drive; its values are `App::ISOLATION_COROUTINE`, `ISOLATION_CGI_POOL`, `ISOLATION_CGI_PROC`, `ISOLATION_CGI_FCGI`, and `ISOLATION_NONE`. Use `App::mode()` for the common cases and reach for `App::isolation()` only when mixing axes.

For the full mode matrix, coroutine-legacy isolation stack, and preload requirements (cold-concurrent autoload race), see [/coroutines#lifecycle-modes](/coroutines#lifecycle-modes).

## Coroutines with `go()` and `co::run()`

OpenSwoole exposes coroutine APIs that allow non-blocking HTTP clients, database drivers, and timers. ZealPHP enables coroutine hooks automatically when you disable superglobals:

```php
use ZealPHP\App;

App::superglobals(false); // enable coroutine mode
$app = App::init();
$app->run();
```

Inside route or API handlers, you can now call `go()`:

```php
use OpenSwoole\Coroutine\Channel;

$app->route('/fanout', function () {
    $urls = ['https://example.com', 'https://php.net'];
    $channel = new Channel(count($urls));

    foreach ($urls as $url) {
        go(function () use ($url, $channel) {
            $channel->push(['url' => $url, 'body' => file_get_contents($url)]);
        });
    }

    $responses = [];
    while ($result = $channel->pop()) {
        $responses[] = $result;
    }

    return $responses;
});
```

This pattern mirrors `examples/coroutine.php` and `api/php/coroutine_test.php`, which fetch remote resources in parallel.

For the common fork-join case, the framework ships higher-level helpers that handle Channel setup, error propagation, and sync-mode wrapping automatically:

```php
// Run all tasks in parallel; returns results in input order.
$results = App::parallel([
    fn() => file_get_contents('https://example.com'),
    fn() => file_get_contents('https://php.net'),
]);

// Bounded fan-out — process $items with at most $concurrency coroutines in flight.
$pages = App::parallelLimit($urls, fn($url) => file_get_contents($url), concurrency: 8);
```

`App::parallel()` and `App::parallelLimit()` auto-wrap callers outside a coroutine context in `Coroutine::run()`, so they work in both coroutine and sync modes. The first thrown exception propagates to the caller.

## Background Processes with `coproc()` / `coprocess()`

`coproc()` spawns a process with coroutine support and returns its buffered output. It is a lighter-weight alternative to task workers for ad-hoc parallelism:

```php
use function ZealPHP\coproc;

$html = coproc(function () {
    echo render_pdf_preview(); // expensive work
});

// $html contains the captured output
```

Requirements:

- Only available when superglobals are enabled. Attempting to call it in coroutine mode throws an exception. The reason: `coproc()` forks a child process, designed *before* per-coroutine `RequestContext` (`$g`) existed — it relies on copying process-wide superglobals into the child. Under `superglobals(false)` each coroutine already has isolated state, so `coproc()` is both redundant (use `go()` for parallelism) and unsafe (the fork would race the parent's process-wide superglobals at the exact moment the framework is *not* maintaining them).
- For per-request process isolation of legacy `public/*.php` files in superglobals mode, see `App::cgiMode('pool'|'proc'|'fcgi')` instead — that's the supported path now. `'pool'` (the default) uses a pre-spawned warm subprocess pool (~1–3 ms); `'proc'` spawns a fresh `proc_open` process per request (~30–50 ms cold); `'fcgi'` forwards to an external FastCGI upstream such as php-fpm.
- Data passed to the closure must be serialisable; resources such as database connections should be re-created inside the child process.

## Task Workers via `$server->task()`

OpenSwoole supports dedicated task workers for asynchronous jobs that should outlive the request scope. ZealPHP conventions:

- Task handlers are stored in `task/<name>.php` and define a closure variable matching the filename.
- Enqueue a task from any route or API handler:

```php
$task = function ($request, $response, OpenSwoole\HTTP\Server $server) {
    $server->task([
        'handler' => '/task/backup',
        'args' => ['customer-id' => 42],
    ], -1, function ($server, $taskId, $payload) {
        $result = unserialize($payload['result']);
        echo "Backup response: " . $result->getBody();
    });
};
```

Reference implementations live in `api/swoole/task.php` and `task/backup.php`, which demonstrate serialising an `OpenSwoole\Core\Psr\Response` inside the task and reading it back in the finish callback.

### Best Practices for Task Workers

- Pass simple arrays or scalars through `$server->task()`; serialise complex objects explicitly.
- Handle errors inside the task and return structured payloads so the finish callback can react accordingly.
- Configure `task_worker_num` in `App::run()` settings or the OpenSwoole config array to match your workload.

## Choosing the Right Concurrency Tool

| Scenario | Recommended Tool | Notes |
|----------|------------------|-------|
| Non-blocking IO when superglobals are disabled | `go()` coroutines | Ensure drivers support OpenSwoole hooks. |
| Long-running, blocking work in superglobals mode | `coproc()` / `coprocess()` | Forks a child process; inherits environment snapshot; no shared memory. |
| Per-request isolation of legacy `public/*.php` files | `App::cgiMode('pool'\|'proc'\|'fcgi')` | `'pool'` (default, ~1–3 ms warm pool) gives mod_php-style global isolation; `'proc'` spawns a fresh process per request (~30–50 ms cold); `'fcgi'` forwards to an upstream FPM pool. |
| Fire-and-forget asynchronous work | `$server->task()` | Task workers run outside the request context. |

## Coordination and Shared State

- `ZealPHP\G` does not automatically share state across processes or coroutines. Pass required data explicitly.
- When using sessions, ensure that task workers open their own session if they need session data—workers do not inherit the parent session automatically.
- Prefer immutable data structures or explicit message passing when coordinating work between coroutines.

## Monitoring and Debugging

- Use `elog()` to annotate concurrency boundaries (e.g., logging when a task starts and finishes).
- Inspect OpenSwoole metrics (task worker count, coroutine stats) via `$server->stats()`; expose them through a dedicated route in `route/metrics.php`.
- Gracefully handle `OpenSwoole\ExitException` in child processes; ZealPHP already maps exit status to HTTP codes, but additional logging is helpful during development.
