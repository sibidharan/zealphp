<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Store &amp; Counter</h1>
<p class="section-desc">OpenSwoole adapters for cross-worker shared memory. Must be created before <code>$app->run()</code> so all forked workers inherit the same memory segment.</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:2rem">
  <div class="card">
    <div class="card-icon">🗃️</div>
    <h3>Store — OpenSwoole\Table</h3>
    <p>Row-based shared memory with per-row spinlocks. Any worker can read/write any row concurrently. Iterate all rows across workers.</p>
  </div>
  <div class="card">
    <div class="card-icon">🔢</div>
    <h3>Counter — OpenSwoole\Atomic</h3>
    <p>Lock-free integer. Safe for concurrent increment/decrement from all workers. Useful for metrics, rate limiting, and request counting.</p>
  </div>
</div>

<?php
$demos = [
  ['store-set', 'Store — set / get / count', '/demo/store/set-get',
   <<<'PHP'
// Before app->run():
Store::make('demo_table', 128, [
    'name'  => [\OpenSwoole\Table::TYPE_STRING, 64],
    'score' => [\OpenSwoole\Table::TYPE_INT,    4],
]);

// In any route (any worker):
Store::set('demo_table', 'user_1', ['name' => 'alice', 'score' => 100]);
$row = Store::get('demo_table', 'user_1');
// → ['name' => 'alice', 'score' => 100]

echo Store::count('demo_table'); // total rows across all workers
PHP],
  ['store-incr', 'Store — atomic incr/decr', '/demo/store/incr',
   <<<'PHP'
// Atomically increment a counter column
Store::set('demo_table', 'page_hits', ['score' => 0]);
$new = Store::incr('demo_table', 'page_hits', 'score');
// → 1 (atomic, safe under concurrent workers)
PHP],
  ['counter-inc', 'Counter — increment across requests', '/demo/counter/increment',
   <<<'PHP'
// Before app->run():
$requestCounter = new Counter(0);

// In any route:
$app->route('/demo/counter/increment', function() use ($requestCounter) {
    $new = $requestCounter->increment();
    return ['total_requests' => $new, 'pid' => getmypid()];
    // Every worker shares the same atomic integer
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 style="margin:2rem 0 .5rem">Store API reference</h2>
<table class="ztable">
  <tr><th>Method</th><th>Returns</th></tr>
  <tr><td><code>Store::make($name, $maxRows, $columns)</code></td><td>OpenSwoole\Table</td></tr>
  <tr><td><code>Store::set($table, $key, $row)</code></td><td>bool</td></tr>
  <tr><td><code>Store::get($table, $key, $field?)</code></td><td>array|mixed|false</td></tr>
  <tr><td><code>Store::del($table, $key)</code></td><td>bool</td></tr>
  <tr><td><code>Store::exists($table, $key)</code></td><td>bool</td></tr>
  <tr><td><code>Store::incr($table, $key, $col, $by=1)</code></td><td>int (new value)</td></tr>
  <tr><td><code>Store::decr($table, $key, $col, $by=1)</code></td><td>int (new value)</td></tr>
  <tr><td><code>Store::count($table)</code></td><td>int</td></tr>
  <tr><td><code>Store::table($name)</code></td><td>OpenSwoole\Table (iterate with foreach)</td></tr>
</table>
</div>
</section>
