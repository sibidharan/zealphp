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
$app->route('/timers/oneshot', function($response) {
    $response->stream(function($write) {
        $result = new Channel(1);
        App::after(3000, fn() => $result->push('done after 3s'));
        $write($result->pop(5));
    });
});
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'SSE — /timers/sse',
    'code'  => <<<'PHP'
$app->route('/timers/sse', function($response) use ($tickCounter, $requestCounter) {
    $requestCounter->increment();
    $response->sse(function($emit) use ($tickCounter, $requestCounter) {
        $emit(json_encode(['event' => 'connected', 'tick' => $tickCounter->get()]), 'open');
        for ($i = 0; $i < 20; $i++) {
            usleep(2000000);
            $emit(json_encode([
                'tick'     => $tickCounter->get(),
                'requests' => $requestCounter->get(),
                'time'     => date('H:i:s'),
            ]), 'tick', (string)$i);
        }
        $emit(json_encode(['done' => true]), 'done');
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
  ['timer-oneshot', 'One-shot delayed task', '/timers/oneshot',
   <<<'PHP'
$app->route('/timers/oneshot', function($response) use ($requestCounter) {
    $requestCounter->increment();
    $response->stream(function($write) {
        $result = new Channel(1);
        App::after(3000, function() use ($result) {
            $result->push(['done' => true, 'time' => date('H:i:s'), 'pid' => getmypid()]);
        });
        $write($result->pop(5));
    });
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

<div class="inject-case" style="margin-top:1.5rem">
  <div class="inject-case-header"><span class="badge badge-sse">SSE</span><code>/timers/sse — Server-Sent Events</code></div>
  <div class="inject-case-body">
    <div class="demo-code">
      <pre><code class="language-php">// Connect with EventSource and stream tick events.
const es = new EventSource('/timers/sse');
es.addEventListener('tick', e => console.log(JSON.parse(e.data)));
es.addEventListener('done', () => es.close());</code></pre>
    </div>
    <div class="demo-output">
      <span class="label">LIVE OUTPUT</span>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap; padding-top:1rem; margin-bottom:.75rem">
        <button class="btn btn-primary btn-sm" type="button" onclick="startTimerSSE()">Connect EventSource</button>
        <button class="btn btn-ghost btn-sm" type="button" onclick="stopTimerSSE()">Disconnect</button>
      </div>
      <div class="sse-log" id="timer-sse-out">Click Connect to start…</div>
    </div>
  </div>
</div>

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

<script>
let timerEventSource = null;

function stopTimerSSE() {
  if (timerEventSource) {
    timerEventSource.close();
    timerEventSource = null;
  }
}

function addTimerSSELine(text, cls) {
  const log = document.getElementById('timer-sse-out');
  if (!log) return;
  const el = document.createElement('div');
  el.className = 'sse-event ' + (cls || '');
  el.textContent = new Date().toLocaleTimeString() + ' — ' + text;
  log.appendChild(el);
  log.scrollTop = log.scrollHeight;
}

function startTimerSSE() {
  stopTimerSSE();
  const log = document.getElementById('timer-sse-out');
  if (!log) return;
  log.textContent = '';
  timerEventSource = new EventSource('/timers/sse');
  timerEventSource.addEventListener('open', e => addTimerSSELine(e.data, 'open'));
  timerEventSource.addEventListener('tick', e => addTimerSSELine(e.data, 'tick'));
  timerEventSource.addEventListener('done', e => {
    addTimerSSELine(e.data, 'done');
    stopTimerSSE();
  });
  timerEventSource.onerror = () => {
    addTimerSSELine('connection closed', 'done');
    stopTimerSSE();
  };
}
</script>
