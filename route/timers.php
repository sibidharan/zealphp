<?php
/**
 * Timer + Counter demo routes
 *
 * App::tick($ms, $fn)  — recurring timer per worker (uses OpenSwoole\Timer::tick)
 * App::after($ms, $fn) — one-shot timer (uses OpenSwoole\Timer::after)
 * Counter              — lock-free atomic integer shared across all workers
 *
 * Routes:
 *   GET /timers              — hub page
 *   GET /timers/counter      — JSON dump of all counters
 *   GET /timers/sse          — SSE stream of the tick counter (updates every 2s)
 *   GET /timers/oneshot      — trigger a one-shot 3s delayed task
 *   GET /timers/metrics      — worker-level metrics from Store (Table)
 */

use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Store;

$app = App::instance();

// ---------------------------------------------------------------------------
// Shared counters — created before $server->start() so all workers share them
// ---------------------------------------------------------------------------
$requestCounter = new Counter(0);   // total HTTP requests served
$tickCounter    = new Counter(0);   // incremented by the tick timer in each worker

// Per-worker metric table
Store::make('worker_metrics', 64, [
    'pid'      => [\OpenSwoole\Table::TYPE_INT,    4],
    'requests' => [\OpenSwoole\Table::TYPE_INT,    8],
    'ticks'    => [\OpenSwoole\Table::TYPE_INT,    8],
]);

// Each worker registers a 2-second tick timer on startup
App::onWorkerStart(function($server, $workerId) use ($tickCounter) {
    $pid = getmypid();
    Store::set('worker_metrics', (string)$workerId, ['pid' => $pid, 'requests' => 0, 'ticks' => 0]);

    App::tick(2000, function() use ($workerId, $tickCounter) {
        $tickCounter->increment();
        Store::incr('worker_metrics', (string)$workerId, 'ticks');
    });
});

// ---------------------------------------------------------------------------
// Middleware-style request counting via a route hook
// ---------------------------------------------------------------------------
$app->route('/timers', ['methods' => ['GET']], function() use ($requestCounter) {
    $requestCounter->increment();
    echo <<<'HTML'
    <!doctype html><html><head><meta charset="utf-8"><title>ZealPHP Timers</title>
    <style>body{font-family:system-ui;max-width:800px;margin:2rem auto;padding:0 1rem}
    table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:.6rem .8rem}th{background:#f4f4f4}
    code{background:#f0f0f0;padding:1px 5px;border-radius:3px}a{color:#0070f3}</style></head><body>
    <h1>Timer &amp; Counter Demos</h1>
    <table>
      <tr><th>Feature</th><th>Route</th><th>What it shows</th></tr>
      <tr><td>Atomic counter</td><td><a href="/timers/counter">/timers/counter</a></td><td>Cross-worker request + tick counts</td></tr>
      <tr><td>SSE tick stream</td><td><a href="/timers/sse">/timers/sse</a></td><td>Live counter via Server-Sent Events</td></tr>
      <tr><td>One-shot timer</td><td><a href="/timers/oneshot">/timers/oneshot</a></td><td>App::after() — fires once after 3s</td></tr>
      <tr><td>Worker metrics</td><td><a href="/timers/metrics">/timers/metrics</a></td><td>Per-worker pid/requests/ticks from Store</td></tr>
    </table>
    <p><code>curl -N http://localhost:8080/timers/sse</code> to see the live tick stream.</p>
    </body></html>
    HTML;
});

$app->route('/timers/counter', ['methods' => ['GET']], function() use ($requestCounter, $tickCounter) {
    $requestCounter->increment();
    return [
        'requests_served' => $requestCounter->get(),
        'tick_count'      => $tickCounter->get(),
        'note'            => 'tick_count increments every 2s per worker (so 24 workers = +24 every 2s)',
    ];
});

$app->route('/timers/sse', ['methods' => ['GET']], function($response) use ($tickCounter, $requestCounter) {
    $requestCounter->increment();
    $response->sse(function($emit) use ($tickCounter, $requestCounter) {
        $emit(json_encode(['event' => 'connected', 'tick' => $tickCounter->get()]), 'open');
        for ($i = 0; $i < 20; $i++) {      // stream for ~40s
            usleep(2000000);               // 2s — matches tick interval
            $emit(json_encode([
                'tick'     => $tickCounter->get(),
                'requests' => $requestCounter->get(),
                'time'     => date('H:i:s'),
            ]), 'tick', (string)$i);
        }
        $emit(json_encode(['done' => true]), 'done');
    });
});

$app->route('/timers/oneshot', ['methods' => ['GET']], function($response) use ($requestCounter) {
    $requestCounter->increment();
    $response->stream(function($write) {
        $write('<html><body><pre>');
        $write("Scheduling a one-shot task in 3 seconds...\n");

        $result = new \OpenSwoole\Coroutine\Channel(1);

        App::after(3000, function() use ($result) {
            $result->push(['done' => true, 'time' => date('H:i:s'), 'pid' => getmypid()]);
        });

        $write("Waiting for App::after(3000, ...) to fire...\n");
        $data = $result->pop(5);   // wait up to 5s
        $write($data ? json_encode($data, JSON_PRETTY_PRINT) . "\n" : "timed out\n");
        $write("</pre></body></html>");
    });
});

$app->route('/timers/metrics', ['methods' => ['GET']], function() use ($requestCounter, $tickCounter) {
    $requestCounter->increment();
    $workers = [];
    foreach (Store::table('worker_metrics') as $id => $row) {
        $workers[(int)$id] = $row;
    }
    ksort($workers);
    return [
        'totals'  => ['requests' => $requestCounter->get(), 'ticks' => $tickCounter->get()],
        'workers' => $workers,
    ];
});
