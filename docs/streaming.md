# Streaming

## Overview

ZealPHP supports four streaming patterns. They share the same underlying mechanic — flushing the response headers, then writing chunks to an open socket — but each surfaces a different API tuned to its use case.

| Pattern | Returns | Best for |
|---|---|---|
| Generator `yield` from a route handler | `\Generator` | SSR — stream HTML as coroutines resolve |
| `$response->stream($fn)` | `void` (`$fn` receives a `$write` closure) | Fine-grained chunk control |
| `$response->sse($fn)` | `void` (`$fn` receives an `$emit` closure) | Server-Sent Events to a browser `EventSource` |
| `App::renderStream($tpl, $args)` | `\Generator` | Compose streaming output across template files |

All four set `$g->_streaming = true` so `ResponseMiddleware` skips its output-buffer capture and forwards bytes to the wire immediately.

## Pattern 1: Generator yield

Return a `\Generator` from a route handler. Each `yield` is sent to the client immediately — no buffering, no waiting for the function to return. Spawn coroutines for parallel work and yield each result as it lands.

```php
use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;

$app->route('/stream/ssr', function() {
    return (function() {
        yield '<!doctype html><html><body><h1>Streaming SSR</h1>';
        yield '<div id="sk1" class="skeleton">loading users…</div>';
        yield '<div id="sk2" class="skeleton">loading posts…</div>';

        $ch = new Channel(2);
        go(function() use ($ch) { co::sleep(1); $ch->push(['id' => 'sk1', 'html' => '<ul><li>Alice</li></ul>']); });
        go(function() use ($ch) { co::sleep(2); $ch->push(['id' => 'sk2', 'html' => '<ul><li>Post 1</li></ul>']); });

        for ($i = 0; $i < 2; $i++) {
            $r = $ch->pop();
            yield "<script>document.getElementById('{$r['id']}').remove();</script>{$r['html']}";
        }
        yield '</body></html>';
    })();
});
```

The shell arrives instantly. The two fetches run in parallel — total time is `max(fetch times)`, not the sum. A complete working example lives in `examples/streaming-sse/app.php` and `route/streaming.php`.

## Pattern 2: `$response->stream($fn)`

When you want raw `write()` control — e.g., to stream binary, to pump from a generator you don't own, or to interleave logic between writes — inject `$response` and call `stream()`. The callback receives a `$write(string $chunk): bool` closure. Headers are flushed before `$fn` runs; the response is closed when `$fn` returns.

```php
$app->route('/stream/words', function($response) {
    $response->stream(function($write) {
        $write('<!doctype html><body><p>');
        foreach (explode(' ', 'streaming one word at a time') as $word) {
            usleep(150_000);          // 150ms — coroutine-aware under HOOK_ALL
            $write("<span>$word </span>");
        }
        $write('</p></body>');
    });
});
```

`$write()` returns `false` when the client has disconnected so you can break out of a long loop cleanly. Throwing inside `$fn` is caught silently — the connection is closed but the worker is not affected.

## Pattern 3: `$response->sse($fn)`

Server-Sent Events for browser-side `EventSource` clients. `sse()` sets `Content-Type: text/event-stream`, `Cache-Control: no-cache`, and `X-Accel-Buffering: no`, then delegates to `stream()`. Your callback receives an `$emit($data, $event = '', $id = '')` closure that formats one SSE message per call.

```php
use OpenSwoole\Coroutine as co;

$app->route('/events', function($response) {
    $response->sse(function($emit) {
        $emit(json_encode(['hello' => 'world']), 'open');
        for ($i = 1; $i <= 10; $i++) {
            co::sleep(1);
            $emit(json_encode(['tick' => $i, 'time' => date('H:i:s')]), 'tick', (string)$i);
        }
        $emit(json_encode(['message' => 'done']), 'done');
    });
});
```

Browser client:

```html
<script>
const es = new EventSource('/events');
es.addEventListener('tick', (e) => console.log(JSON.parse(e.data)));
es.addEventListener('done', () => es.close());
</script>
```

## Pattern 4: `App::renderStream($tpl, $args)`

`renderStream()` reads a template file and returns a `\Generator`, letting you compose streaming output across multiple files with `yield from`. The template may use any of three styles:

1. **Closure with named parameters** (cleanest — the framework injects args by name, same as route handlers):

   ```php
   <?php return function($users, $page = 1) {
       yield "<section data-page='$page'>";
       foreach ($users as $u) yield "<div>$u->name</div>";
       yield "</section>";
   };
   ```

2. **IIFE Generator** (explicit, when you want closure-style `use`):

   ```php
   <?php return (function() use ($users) {
       yield "<section>";
       foreach ($users as $u) yield "<div>$u->name</div>";
       yield "</section>";
   })();
   ```

3. **Regular echo template** — captured output is yielded as a single chunk. Use this for static fragments (heads, footers).

Compose streams across templates with `yield from`:

```php
$app->route('/users', function() {
    return (function() {
        yield from App::renderStream('shell-open', ['title' => 'Users']);
        yield from App::renderStream('users/stream', ['users' => User::all()]);
        yield from App::renderStream('shell-close');
    })();
});
```

## Headers and buffering

- **Headers flush before the first chunk.** All four patterns call `$response->flush()` internally before any body bytes are written — once a chunk is on the wire, `header()` calls become no-ops for that response.
- **`Accept-Ranges: none`** is set on streaming routes, so `RangeMiddleware` does not attempt to slice an unbounded body.
- **Reverse proxies need explicit pass-through.** nginx buffers responses by default — for SSE and SSR streaming to work end-to-end behind nginx, set:

  ```nginx
  location / {
      proxy_pass http://zealphp;
      proxy_http_version 1.1;
      proxy_buffering off;
      proxy_cache off;
  }
  ```

  ZealPHP also emits `X-Accel-Buffering: no` from `sse()` for the same reason — nginx honours that header per-response.

## Error handling

If a Generator throws mid-yield, the connection closes silently. `$response->stream()` also catches exceptions thrown inside the callback, so a single failed write does not crash the worker — but the client receives a truncated response with no indication of why.

For production code, wrap the generator body in `try`/`catch` and emit a sentinel before re-throwing or returning:

```php
return (function() {
    try {
        yield '<html><body>';
        yield from streamUsers();
        yield '</body></html>';
    } catch (\Throwable $e) {
        yield '<div class="error" data-trace-id="'.$traceId.'">Something went wrong</div>';
        elog('stream error: '.$e->getMessage(), 'error');
        // optionally rethrow if a higher layer should observe the failure
    }
})();
```

For SSE, send a dedicated `error` event before the stream ends so the JS client can react:

```php
$response->sse(function($emit) {
    try {
        // …work…
    } catch (\Throwable $e) {
        $emit(json_encode(['code' => 'INTERNAL']), 'error');
    }
});
```

## When to pick which

- **Default, cleanest** → Generator `yield`. Return a `\Generator`, write business logic top-down, parallelise with `go()` and `Channel`.
- **You need direct `write()` control** → `$response->stream()`. Useful when the bytes don't fit naturally into `yield` (binary streams, pumping from an external iterator, interleaving non-yieldable work).
- **You're streaming to a browser `EventSource`** → `$response->sse()`. The wire-format handling is already done for you.
- **You're composing fragments across multiple template files** → `App::renderStream()` with `yield from`. Each template can declare its own named parameters and stream independently.
