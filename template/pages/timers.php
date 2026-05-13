<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Timers</h1>
<p class="section-desc">Server-level recurring tasks via <code>OpenSwoole\Timer</code>. Each worker runs its own timers — use <code>App::onWorkerStart()</code> to register them.</p>

<?php App::render('/components/_code', [
    'label' => 'Recurring timer in every worker',
    'code'  => <<<'PHP'
// In app.php (before run()):
$tickCounter = new Counter(0);

App::onWorkerStart(function($server, $workerId) use ($tickCounter) {
    // Starts 1 timer per worker; N workers = N timers, all incrementing
    App::tick(2000, function() use ($tickCounter) {
        $tickCounter->increment();
    });
});

// One-shot timer from a route:
$app->route('/delayed', function($response) {
    $response->stream(function($write) {
        $result = new Channel(1);
        App::after(3000, fn() => $result->push('done after 3s'));
        $write($result->pop(5));
    });
});
PHP]); ?>

<?php
$demos = [
  ['timer-counter', 'Counter incremented by tick timers', '/timers/counter',
   <<<'PHP'
// tick_count = total increments across all workers and 2s intervals
App::onWorkerStart(function($server, $workerId) use ($tickCounter) {
    App::tick(2000, fn() => $tickCounter->increment());
});
$app->route('/timers/counter', function() use ($requestCounter, $tickCounter) {
    $requestCounter->increment();
    return ['requests_served' => $requestCounter->get(), 'tick_count' => $tickCounter->get()];
});
PHP],
  ['timer-metrics', 'Per-worker metrics via Store', '/timers/metrics',
   <<<'PHP'
Store::make('worker_metrics', 64, [
    'pid'      => [\OpenSwoole\Table::TYPE_INT, 4],
    'ticks'    => [\OpenSwoole\Table::TYPE_INT, 8],
]);
App::onWorkerStart(function($server, $workerId) use ($tickCounter) {
    $pid = getmypid();
    Store::set('worker_metrics', (string)$workerId, ['pid' => $pid, 'ticks' => 0]);
    App::tick(2000, function() use ($workerId, $tickCounter) {
        $tickCounter->increment();
        Store::incr('worker_metrics', (string)$workerId, 'ticks');
    });
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 style="margin:2rem 0 .5rem">Timer API</h2>
<table class="ztable">
  <tr><th>Method</th><th>When to use</th></tr>
  <tr><td><code>App::tick(int $ms, callable $fn)</code></td><td>Recurring task — runs every $ms milliseconds in this worker</td></tr>
  <tr><td><code>App::after(int $ms, callable $fn)</code></td><td>One-shot — fires once after $ms milliseconds</td></tr>
  <tr><td><code>App::clearTimer(int $id)</code></td><td>Cancel a tick/after timer by its returned ID</td></tr>
  <tr><td><code>App::onWorkerStart(callable $fn)</code></td><td>Register a callback called when each worker starts — right place for timers</td></tr>
</table>

<div class="callout warn" style="margin-top:1rem">
  <strong>Must be called inside a coroutine context.</strong>
  <code>App::tick()</code> works inside <code>onWorkerStart</code> callbacks and route handlers, but not at the global PHP scope (before the server starts).
</div>
</div>
</section>
