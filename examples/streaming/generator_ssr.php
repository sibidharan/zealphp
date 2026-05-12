<?php
/**
 * Generator SSR Streaming
 *
 * The route handler is a generator function.  ZealPHP detects the Generator
 * return value in ResponseMiddleware and streams each yielded string to the
 * client via OpenSwoole's write() — no special API needed in the handler.
 *
 * Pattern mirrors React's renderToPipeableStream:
 *   1. Yield the HTML shell immediately (browser starts rendering)
 *   2. Launch coroutines for data fetching
 *   3. Yield each section as its coroutine resolves
 *   4. Yield the closing HTML
 *
 * curl -N --no-buffer http://localhost:8080/examples/generator-ssr
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;

$_ssr_start = microtime(true);

return (function() use ($_ssr_start) {
    // ── 1. Shell — sent to browser immediately ────────────────────────────
    yield <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>ZealPHP Generator SSR</title>
      <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 1rem 0; }
        .loading { color: #999; font-style: italic; }
        .badge { display:inline-block; background:#e0f0ff; color:#005; border-radius:4px; padding:2px 8px; font-size:.8rem; }
      </style>
    </head>
    <body>
      <h1>ZealPHP SSR Streaming <span class="badge">Generator</span></h1>
      <p>Shell sent instantly. Sections stream in as coroutines resolve.</p>
    HTML;

    // ── 2. Launch parallel "data fetches" ────────────────────────────────
    $ch = new Channel(2);

    go(function() use ($ch) {
        co::sleep(1);   // simulate a 1s DB query
        $ch->push([
            'section' => 'users',
            'html'    => '<ul>' . implode('', array_map(
                fn($u) => "<li>$u</li>",
                ['Alice', 'Bob', 'Charlie']
            )) . '</ul>',
        ]);
    });

    go(function() use ($ch) {
        co::sleep(2);   // simulate a slower 2s API call
        $ch->push([
            'section' => 'stats',
            'html'    => '<table border="1" cellpadding="6">
                            <tr><th>Metric</th><th>Value</th></tr>
                            <tr><td>Requests/s</td><td>14,927</td></tr>
                            <tr><td>p90 latency</td><td>4 ms</td></tr>
                          </table>',
        ]);
    });

    // ── 3. Stream each section as it arrives ─────────────────────────────
    for ($i = 0; $i < 2; $i++) {
        $result = $ch->pop();
        yield sprintf(
            '<div class="card"><h2>%s</h2>%s<p class="badge">streamed at %.2fs</p></div>',
            ucfirst($result['section']),
            $result['html'],
            microtime(true) - $_ssr_start
        );
    }

    // ── 4. Closing HTML ──────────────────────────────────────────────────
    yield '<p><em>Done. Total time: ' . round(microtime(true) - $_ssr_start, 2) . 's</em></p>';
    yield '</body></html>';
})();
