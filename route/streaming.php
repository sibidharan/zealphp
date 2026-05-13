<?php
/**
 * SSR Streaming routes
 *
 * Demonstrates ZealPHP's three streaming APIs:
 *   1. Generator yield   — /stream/ssr
 *   2. $response->stream() — /stream/words
 *   3. $response->sse()    — /stream/events
 *
 * The browser demo hub lives at /streaming (public/streaming.php).
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;

$app = App::instance();

// ---------------------------------------------------------------------------
// 1. Generator SSR
//    Yields the HTML shell immediately, spawns two parallel coroutines that
//    simulate DB/API latency, then streams each section as it resolves.
//    Total time = max(fetch times), not sum.
// ---------------------------------------------------------------------------
$app->route('/stream/ssr', function() {
    $start = microtime(true);

    return (function() use ($start) {
        yield <<<HTML
        <!doctype html><html lang="en"><head>
          <meta charset="utf-8">
          <title>ZealPHP · Generator SSR</title>
          <style>
            *{box-sizing:border-box}
            body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem;background:#f8f9fa}
            h1{color:#1a1a2e}.badge{display:inline-block;background:#e0f0ff;color:#005;border-radius:4px;padding:2px 8px;font-size:.8rem;vertical-align:middle}
            .card{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:1.2rem;margin:1rem 0;box-shadow:0 1px 3px rgba(0,0,0,.07)}
            .skeleton{height:80px;background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200%;animation:shimmer 1.2s infinite;border-radius:6px;margin:.5rem 0}
            @keyframes shimmer{0%{background-position:200%}100%{background-position:-200%}}
            .tag{font-size:.75rem;color:#666;margin-top:.5rem}
            nav{margin-bottom:1.5rem}nav a{margin-right:1rem;color:#0070f3;text-decoration:none}
          </style>
        </head><body>
          <nav><a href="/streaming">← Hub</a><a href="/stream/words">Words demo</a><a href="/stream/events">SSE</a></nav>
          <h1>Generator SSR <span class="badge">yield</span></h1>
          <p>Shell arrived instantly. Two sections stream in as coroutines resolve (parallel, not sequential).</p>
          <div class="skeleton" id="sk1"></div>
          <div class="skeleton" id="sk2"></div>
        HTML;

        // Parallel coroutine "fetches"
        $ch = new Channel(2);

        go(function() use ($ch) {
            co::sleep(1);
            $ch->push(['id' => 'sk1', 'title' => 'Users', 'delay' => '1s',
                'body' => '<ul><li>Alice — admin</li><li>Bob — editor</li><li>Charlie — viewer</li></ul>']);
        });

        go(function() use ($ch) {
            co::sleep(2);
            $ch->push(['id' => 'sk2', 'title' => 'Recent Posts', 'delay' => '2s',
                'body' => '<ul><li>ZealPHP SSR Streaming</li><li>OpenSwoole Coroutines</li><li>PHP 8.3 Fibers</li></ul>']);
        });

        for ($i = 0; $i < 2; $i++) {
            $r = $ch->pop();
            $elapsed = round(microtime(true) - $start, 2);
            yield <<<HTML
            <script>document.getElementById('{$r['id']}')?.remove();</script>
            <div class="card">
              <h2>{$r['title']}</h2>{$r['body']}
              <p class="tag">resolved in {$elapsed}s (simulated {$r['delay']} fetch)</p>
            </div>
            HTML;
        }

        $total = round(microtime(true) - $start, 2);
        yield "<p><em>Page complete in {$total}s — both fetches ran in parallel.</em></p></body></html>";
    })();
});

// ---------------------------------------------------------------------------
// 2. stream() callback — word-by-word streaming
//    Shows fine-grained write() control and parallel coroutines inside
//    the stream callback.
// ---------------------------------------------------------------------------
$app->route('/stream/words', function($response) {
    $response->stream(function($write) {
        $write(<<<'HTML'
        <!doctype html><html lang="en"><head>
          <meta charset="utf-8"><title>ZealPHP · stream()</title>
          <style>
            body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem;background:#f8f9fa}
            h1{color:#1a1a2e}.badge{display:inline-block;background:#fff0d0;color:#630;border-radius:4px;padding:2px 8px;font-size:.8rem;vertical-align:middle}
            .word{opacity:0;animation:pop .25s forwards}.card{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:1rem;margin:1rem 0}
            @keyframes pop{to{opacity:1}}
            nav{margin-bottom:1.5rem}nav a{margin-right:1rem;color:#0070f3;text-decoration:none}
          </style>
        </head><body>
          <nav><a href="/streaming">← Hub</a><a href="/stream/ssr">Generator SSR</a><a href="/stream/events">SSE</a></nav>
          <h1>stream() callback <span class="badge">$response->stream()</span></h1>
          <p>Words stream in every 150ms via <code>$write()</code>:</p><p>
        HTML);

        $sentence = 'ZealPHP delivers true SSR streaming on top of OpenSwoole coroutines without blocking a single worker thread.';
        foreach (explode(' ', $sentence) as $word) {
            usleep(150000); // 150 ms — usleep is coroutine-aware under HOOK_ALL
            $write("<span class='word'>$word </span>");
        }

        $write('</p><div class="card"><h2>Parallel fetch inside stream()</h2>');

        $ch = new Channel(3);
        go(function() use ($ch) { co::sleep(0.4); $ch->push('Fast (0.4s)'); });
        go(function() use ($ch) { co::sleep(0.8); $ch->push('Medium (0.8s)'); });
        go(function() use ($ch) { co::sleep(1.2); $ch->push('Slow (1.2s)'); });

        for ($i = 0; $i < 3; $i++) {
            $write('<p>✓ ' . $ch->pop() . ' resolved</p>');
        }

        $write('</div><p><em>Stream complete.</em></p></body></html>');
    });
});

// ---------------------------------------------------------------------------
// 3. SSE endpoint
//    Sends 10 tick events (1s apart) then a done event.
//    Consumed by /streaming (EventSource JS) and /stream/sse-client.
// ---------------------------------------------------------------------------
$app->route('/stream/events', function($response) {
    $response->sse(function($emit) {
        $emit(json_encode(['message' => 'connected', 'time' => date('H:i:s')]), 'open');

        for ($i = 1; $i <= 10; $i++) {
            co::sleep(1);
            $emit(json_encode([
                'tick'    => $i,
                'time'    => date('H:i:s'),
                'message' => "Tick $i of 10",
            ]), 'tick', (string)$i);
        }

        $emit(json_encode(['message' => 'stream complete']), 'done');
    });
});
