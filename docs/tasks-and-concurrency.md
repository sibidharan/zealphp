# Tasks and Concurrency

ZealPHP embraces OpenSwoole’s asynchronous primitives to help you build responsive applications that scale across CPU cores. This document outlines the concurrency toolbox provided by the framework and when to use each option.

> **Lifecycle safety (v0.2.27).** `App::run()` refuses to start when an unsafe mode combination is configured — specifically `superglobals(true) + enableCoroutine(true)` or `superglobals(true) + hookAll(non-zero)`, because concurrent coroutines would race process-wide `$_GET` / `$_POST` / `$_SESSION` arrays. See the "Lifecycle setters" section of [runtime-architecture.md](runtime-architecture.md) for the full mode matrix and the boot-time refusal contract.

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
- For per-request process isolation of legacy `public/*.php` files in superglobals mode, see `App::cgiMode('proc'|'fork'|'fcgi')` instead — that's the supported path now (Apache mod_php-style isolation, with `'fork'` being ~5× faster via warm `OpenSwoole\Process` fork and `'fcgi'` proxying to an upstream php-fpm pool).
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
| Per-request isolation of legacy `public/*.php` files | `App::cgiMode('proc'\|'fork'\|'fcgi')` | Replaces the old `prefork_request_handler()`. `'proc'` is mod_php-style global isolation; `'fork'` is ~5× faster via warm process fork; `'fcgi'` forwards to an upstream FPM pool. |
| Fire-and-forget asynchronous work | `$server->task()` | Task workers run outside the request context. |

## Coordination and Shared State

- `ZealPHP\G` does not automatically share state across processes or coroutines. Pass required data explicitly.
- When using sessions, ensure that task workers open their own session if they need session data—workers do not inherit the parent session automatically.
- Prefer immutable data structures or explicit message passing when coordinating work between coroutines.

## Monitoring and Debugging

- Use `elog()` to annotate concurrency boundaries (e.g., logging when a task starts and finishes).
- Inspect OpenSwoole metrics (task worker count, coroutine stats) via `$server->stats()`; expose them through a dedicated route in `route/metrics.php`.
- Gracefully handle `OpenSwoole\ExitException` in child processes; ZealPHP already maps exit status to HTTP codes, but additional logging is helpful during development.
