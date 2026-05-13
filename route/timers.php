<?php
/**
 * Timer + Counter demo routes.
 *
 * App::tick($ms, $fn)  — recurring timer per worker (uses OpenSwoole\Timer::tick)
 * App::after($ms, $fn) — one-shot timer (uses OpenSwoole\Timer::after)
 * Counter              — lock-free atomic integer shared across all workers
 *
 * Routes:
 *   GET /timers              — designed timers page
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
// Timers landing page
// ---------------------------------------------------------------------------
$app->route('/timers', ['methods' => ['GET']], function() use ($requestCounter) {
    $requestCounter->increment();
    App::render('/_master', [
        'title' => 'ZealPHP · Timers',
        'description' => 'Timers, counters, and worker metrics in ZealPHP.',
        'page' => 'timers',
        'active' => 'timers',
    ]);
});

$app->route('/timers/counter', ['methods' => ['GET']], function() use ($requestCounter, $tickCounter) {
    $requestCounter->increment();
    return [
        'requests_served' => $requestCounter->get(),
        'tick_count'      => $tickCounter->get(),
        'note'            => 'tick_count increments every 2s per HTTP worker',
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
