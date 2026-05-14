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

<h2 style="margin:2.5rem 0 .5rem">Cache — general-purpose key-value with TTL</h2>
<p style="color:var(--text-muted);margin-bottom:1.5rem">Tiered cache built on Store. Memory tier (fast, cross-worker) + file tier (persistent, survives restarts). No Redis needed for most apps.</p>

<div class="code-block">
<pre><code class="language-php">// Before $app->run():
Cache::init();

// Anywhere (any worker):
Cache::set('user:42', $profileArray, ttl: 300);   // any PHP value, auto-serialized
$profile = Cache::get('user:42');                  // memory first, file fallback
Cache::has('user:42');                             // TTL-aware existence check
Cache::del('user:42');                             // removes from both tiers
Cache::flush();                                    // clear everything</code></pre>
</div>

<table class="ztable" style="margin-top:1rem">
  <tr><th>Method</th><th>Returns</th><th>Notes</th></tr>
  <tr><td><code>Cache::init($maxRows?, $cacheDir?, $gcIntervalMs?)</code></td><td>void</td><td>Call before <code>$app->run()</code>. Defaults: 4096 rows, <code>.cache/</code>, 60s GC</td></tr>
  <tr><td><code>Cache::set($key, $value, ttl: $seconds)</code></td><td>bool</td><td>Write-through to both tiers. <code>ttl: 0</code> = no expiry</td></tr>
  <tr><td><code>Cache::get($key, $default?)</code></td><td>mixed</td><td>Memory first, file fallback. Returns <code>$default</code> on miss</td></tr>
  <tr><td><code>Cache::del($key)</code></td><td>bool</td><td>Removes from both tiers</td></tr>
  <tr><td><code>Cache::has($key)</code></td><td>bool</td><td>Checks without deserializing. Respects TTL</td></tr>
  <tr><td><code>Cache::flush()</code></td><td>void</td><td>Clears all entries from both tiers</td></tr>
  <tr><td><code>Cache::count()</code></td><td>int</td><td>Memory tier count only</td></tr>
</table>

<div class="callout info" style="margin-top:1.5rem">
  <strong>How it works:</strong> Values are serialized and written to both tiers. Memory tier uses Store (OpenSwoole\Table) — 8KB max per value, values larger than 8KB automatically spill to file-only. File tier writes to <code>.cache/{hash}.cache</code> with TTL header. Expired entries are cleaned lazily on read + a periodic GC sweep every 60s on worker 0.
</div>

<h2 style="margin:2.5rem 0 .5rem">When to use Redis / Valkey</h2>
<p style="color:var(--text-muted);margin-bottom:1rem">Store and Cache cover most single-server apps. Here's when you'll need an external cache.</p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">
  <div>
    <h3 style="color:var(--success);margin-bottom:.75rem">Built-in Cache is great for</h3>
    <ul style="font-size:.9rem;line-height:1.8;color:var(--text-muted)">
      <li>Single-server deployments (most apps)</li>
      <li>Caching API responses, config, computed values</li>
      <li>Rate limiting and request counting</li>
      <li>Session-adjacent data (preferences, feature flags)</li>
      <li>Apps with &lt; 100k cache entries</li>
    </ul>
  </div>
  <div>
    <h3 style="color:var(--danger);margin-bottom:.75rem">Move to Redis / Valkey when you need</h3>
    <ul style="font-size:.9rem;line-height:1.8;color:var(--text-muted)">
      <li><strong>Multi-server shared state</strong> — Cache is per-server only</li>
      <li><strong>Large datasets</strong> — memory tier caps at 4096 rows, 8KB/value</li>
      <li><strong>Pub/Sub messaging</strong> — no built-in publish/subscribe between workers or servers</li>
      <li><strong>Data structures</strong> — sorted sets, streams, Lua scripting</li>
      <li><strong>Crash-safe persistence</strong> — Redis AOF/RDB vs best-effort files</li>
      <li><strong>Eviction policies</strong> — no LRU/LFU, full table spills to file</li>
      <li><strong>Transactions</strong> — no MULTI/EXEC, per-row spinlocks only</li>
    </ul>
  </div>
</div>

</div>
</section>
