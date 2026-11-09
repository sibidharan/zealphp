<?php
/**
 * SSR Streaming routes
 *
 * Demonstrates ZealPHP's three streaming APIs:
 *   1. Generator yield   — /stream/ssr
 *   2. $response->stream() — /stream/words
 *   3. $response->sse()    — /stream/events
 *
 * The browser demo hub lives at /streaming (public/streaming.php). The demo
 * pages' markup is in template/demos/streaming/; each streamed piece is a
 * component, so chunks still flush one by one.
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
        yield App::component('/demos/streaming/ssr-shell');

        // Parallel coroutine "fetches"
        $ch = new Channel(2);

        go(function() use ($ch) {
            co::sleep(1);
            $ch->push(['id' => 'sk1', 'title' => 'Users', 'delay' => '1s',
                'items' => ['Alice — admin', 'Bob — editor', 'Charlie — viewer']]);
        });

        go(function() use ($ch) {
            co::sleep(2);
            $ch->push(['id' => 'sk2', 'title' => 'Recent Posts', 'delay' => '2s',
                'items' => ['ZealPHP SSR Streaming', 'OpenSwoole Coroutines', 'PHP 8.3 Fibers']]);
        });

        for ($i = 0; $i < 2; $i++) {
            $section = $ch->pop();
            $section['elapsed'] = round(microtime(true) - $start, 2);
            yield App::component('/demos/streaming/ssr-section', $section);
        }

        yield App::component('/demos/streaming/ssr-end', ['total' => round(microtime(true) - $start, 2)]);
    })();
});

// ---------------------------------------------------------------------------
// 2. stream() callback — word-by-word streaming
//    Shows fine-grained write() control and parallel coroutines inside
//    the stream callback.
// ---------------------------------------------------------------------------
$app->route('/stream/words', function($response) {
    $response->stream(function($write) {
        $write((string) App::component('/demos/streaming/words-shell'));

        $sentence = 'ZealPHP delivers true SSR streaming on top of OpenSwoole coroutines without blocking a single worker thread.';
        foreach (explode(' ', $sentence) as $word) {
            usleep(150000); // 150 ms — usleep is coroutine-aware under HOOK_ALL
            $write((string) App::component('/demos/streaming/word', ['word' => $word]));
        }

        $write((string) App::component('/demos/streaming/words-parallel'));

        $ch = new Channel(3);
        go(function() use ($ch) { co::sleep(0.4); $ch->push('Fast (0.4s)'); });
        go(function() use ($ch) { co::sleep(0.8); $ch->push('Medium (0.8s)'); });
        go(function() use ($ch) { co::sleep(1.2); $ch->push('Slow (1.2s)'); });

        for ($i = 0; $i < 3; $i++) {
            $write((string) App::component('/demos/streaming/resolved', ['label' => $ch->pop()]));
        }

        $write((string) App::component('/demos/streaming/words-end'));
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

// ---------------------------------------------------------------------------
// 4. echo / print interleaved with yield
//    Inside a generator body, echo and print follow the universal return
//    contract just like yield — streamed to the client in source order, not
//    leaked to the server's stdout. This endpoint emits exactly "ABCDE".
// ---------------------------------------------------------------------------
$app->route('/stream/echo-yield', function() {
    return (function() {
        echo 'A';        // buffered, flushed before the next yield
        yield 'B';
        print 'C';       // print behaves like echo
        yield 'D';
        echo 'E';        // trailing output after the final yield
    })();
});

// ---------------------------------------------------------------------------
// 5. echo BEFORE returning a generator
//    A handler that echoes and then returns a generator: the pre-generator
//    output is prepended to the stream (source order preserved). Emits "PREGEN".
// ---------------------------------------------------------------------------
$app->route('/stream/pre-echo', function() {
    echo 'PRE';
    return (function() {
        yield 'G';
        yield 'EN';
    })();
});
