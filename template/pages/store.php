<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Store &amp; Counter</h1>
<p class="section-desc">OpenSwoole adapters for cross-worker shared memory. Must be created before <code>$app->run()</code> so all forked workers inherit the same memory segment.</p>

<div class="store-grid">
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
    'name'  => [Store::TYPE_STRING, 64],
    'score' => [Store::TYPE_INT,    4],
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

<h2 class="store-h2">Store API reference</h2>
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

<h2 class="store-h2-section">Cache — general-purpose key-value with TTL</h2>
<p class="store-lead">Tiered cache built on Store. Memory tier (fast, cross-worker) + file tier (persistent, survives restarts). No Redis needed for most apps.</p>

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

<table class="ztable store-mt-1">
  <tr><th>Method</th><th>Returns</th><th>Notes</th></tr>
  <tr><td><code>Cache::init($maxRows?, $cacheDir?, $gcIntervalMs?)</code></td><td>void</td><td>Call before <code>$app->run()</code>. Defaults: 4096 rows, <code>.cache/</code>, 60s GC</td></tr>
  <tr><td><code>Cache::set($key, $value, ttl: $seconds)</code></td><td>bool</td><td>Write-through to both tiers. <code>ttl: 0</code> = no expiry</td></tr>
  <tr><td><code>Cache::get($key, $default?)</code></td><td>mixed</td><td>Memory first, file fallback. Returns <code>$default</code> on miss</td></tr>
  <tr><td><code>Cache::del($key)</code></td><td>bool</td><td>Removes from both tiers</td></tr>
  <tr><td><code>Cache::has($key)</code></td><td>bool</td><td>Checks without deserializing. Respects TTL</td></tr>
  <tr><td><code>Cache::flush()</code></td><td>void</td><td>Clears all entries from both tiers</td></tr>
  <tr><td><code>Cache::count()</code></td><td>int</td><td>Memory tier count only</td></tr>
</table>

<div class="callout info store-mt">
  <strong>How it works:</strong> Values are serialized and written to both tiers. Memory tier uses Store (OpenSwoole\Table) — 8KB max per value, values larger than 8KB automatically spill to file-only. File tier writes to <code>.cache/{hash}.cache</code> with TTL header. Expired entries are cleaned lazily on read + a periodic GC sweep every 60s on worker 0.
</div>

<h2 id="consistency" class="store-h2-section">Consistency semantics — what's atomic, what isn't</h2>
<p class="store-lead-tight">Store is shared memory, not a database. The atomicity guarantees are narrow and important to understand before reaching for it in production.</p>

<table class="ztable">
  <tr><th>Operation</th><th>Atomicity</th><th>Notes</th></tr>
  <tr>
    <td><code>$table->set($key, $row)</code> — single call updating multiple fields in one row</td>
    <td>✅ <strong>Atomic</strong> at the C level (per-row spinlock)</td>
    <td>Readers see either the old row or the new row, never a partial update.</td>
  </tr>
  <tr>
    <td><code>$table->get($key)</code> / <code>$table->get($key, 'field')</code></td>
    <td>✅ Atomic read of one row</td>
    <td>Acquires the row lock briefly; safe under concurrent writes.</td>
  </tr>
  <tr>
    <td>Two <code>$table->set($key, ['a' =&gt; 1])</code> + <code>$table->set($key, ['b' =&gt; 2])</code> calls on the same row</td>
    <td>❌ <strong>Not transactional</strong> across calls</td>
    <td>Each call is atomic individually, but a reader between them sees a half-applied update. Combine into a single <code>set()</code> with both fields if order matters.</td>
  </tr>
  <tr>
    <td><code>$table->incr($key, 'field', $by)</code>, <code>$table->decr($key, 'field', $by)</code></td>
    <td>✅ Atomic — no read-then-write race</td>
    <td>Use these for counters. Don't do <code>get</code> + <code>set</code>.</td>
  </tr>
  <tr>
    <td><code>Counter</code> (<code>OpenSwoole\Atomic</code>) — <code>compareAndSet</code>, <code>add</code>, <code>sub</code>, <code>get</code>, <code>set</code></td>
    <td>✅ Lock-free atomic on a 32/64-bit integer</td>
    <td>For single-value cross-worker counters, prefer this over Store.</td>
  </tr>
</table>

<h3 class="store-h3">What happens on worker crash mid-write</h3>
<p>The honest answer: it depends on how the worker died.</p>
<table class="ztable">
  <tr><th>Crash type</th><th>Effect</th></tr>
  <tr>
    <td><strong>Graceful shutdown</strong> — SIGTERM, including the <code>max_request</code> recycle</td>
    <td>✅ Worker drains current request, releases all row locks normally, exits clean. Manager forks a fresh worker. No corruption.</td>
  </tr>
  <tr>
    <td><strong>SIGKILL / OOM kill / segfault mid-<code>set()</code></strong></td>
    <td>⚠ The row's spinlock <strong>may be left held</strong>. OpenSwoole doesn't have robust mutex-on-holder-death recovery. Other workers will spin waiting on that row until the server is fully restarted. Rare in practice (single-row writes are nanoseconds) but possible under adversarial load.</td>
  </tr>
  <tr>
    <td><strong>Server hard kill (<code>kill -9</code> on the master)</strong></td>
    <td>Shared memory segment is destroyed entirely when the last attached process exits. Fresh segment on next start. No state survives, but no corruption either.</td>
  </tr>
</table>

<div class="callout warn store-mt-1">
  <strong>Rule of thumb:</strong> treat Store as a <strong>best-effort, fast, single-server cache</strong>, not as a database. For ACID needs (transactions, durability, multi-row consistency), use Postgres / MySQL / Redis with explicit transaction semantics. Store's job is to make &lt; 5µs reads possible across workers — that's it.
</div>

<h2 class="store-h2-section" id="backends">Pluggable backends — Table (default) + Redis/Valkey</h2>
<p class="store-lead-tight">As of v0.2.39, <code>Store</code> and <code>Counter</code> are <strong>backend-agnostic</strong>. The default <code>OpenSwoole\Table</code>/<code>Atomic</code> backend stays your hot path (nanosecond reads, lock-free). When you need <strong>cross-node shared state</strong> or <strong>persistence across restarts</strong>, flip to Redis/Valkey with one line in <code>app.php</code> &mdash; every <code>Store::set/get/incr/count</code> call works unchanged.</p>

<div class="code-block">
<pre><code class="language-php">// app.php &mdash; one-line switch
Store::defaultBackend('redis');                                    // ZEALPHP_REDIS_URL env
// or:
Store::defaultBackend('redis', 'redis://cache.internal:6379/1');   // explicit URL
// or set ZEALPHP_STORE_BACKEND=redis in the environment before App::run()</code></pre>
</div>

<table class="store-compare-tbl store-mt-1">
  <thead><tr><th>Backend</th><th>Latency</th><th>Cross-node</th><th>Persistence</th><th>When to pick</th></tr></thead>
  <tbody>
    <tr>
      <td><code>'table'</code> (default)</td>
      <td>~ns (in-memory, lock-free)</td>
      <td>No &mdash; per process tree</td>
      <td>No &mdash; volatile</td>
      <td>Single-node hot path. Millions of ops/sec. 95% of apps.</td>
    </tr>
    <tr>
      <td><code>'redis'</code></td>
      <td>~tens of &micro;s local, ~ms cross-node</td>
      <td>Yes &mdash; any number of nodes</td>
      <td>Yes (Redis AOF/RDB)</td>
      <td>Horizontal scaling, persistent state, existing Redis infra.</td>
    </tr>
  </tbody>
</table>

<p class="store-lead-tight store-mt-1"><strong>Two table modes</strong> at <code>make()</code>: <code>'tracked'</code> (default) keeps a membership SET so <code>count()</code> is O(1); <code>'ttl'</code> supports per-key expiry but <code>count()</code> falls back to O(N) <code>SCAN</code>. Pick one per table. The connection pool is per-worker (default 8 clients) &mdash; concurrent coroutines never share a socket.</p>

<p class="store-lead-tight store-mt-1"><strong>Client lib</strong>: auto-detects phpredis (preferred when <code>ext-redis</code> is loaded) or predis (pure-PHP fallback, shipped as a dev dep). User code never imports a phpredis/predis symbol &mdash; the single <code>ZealPHP\Store\RedisClient</code> adapter is the only place either lib is referenced.</p>

<h2 class="store-h2-section" id="pubsub">Pub/sub + Streams (cross-node messaging)</h2>
<p class="store-lead-tight">Two public primitives on top of the Redis backend for cross-worker AND cross-host messaging. Both require <code>Store::defaultBackend(Store::BACKEND_REDIS)</code>.</p>

<div class="code-block">
<pre><code class="language-php">// Fire-and-forget pub/sub
App::onPubSub('chat:room:42', function (string $payload, string $channel) {
    // runs in every worker that's registered; routes to your local fd map
});
$receivers = Store::publish('chat:room:42', json_encode($message));

// Reliable variant via Redis Streams (at-least-once via consumer groups)
App::onReliableMessage('orders', function (string $payload, string $id, string $stream): bool {
    $ok = processOrder($payload);
    return $ok; // true → XACK; false/throw → leave pending
});
$messageId = Store::publishReliable('orders', json_encode($order));</code></pre>
</div>

<table class="store-compare-tbl store-mt-1">
  <thead><tr><th>Primitive</th><th>Latency</th><th>Durability</th><th>Delivery</th><th>When to pick</th></tr></thead>
  <tbody>
    <tr>
      <td><code>Store::publish</code></td>
      <td>~0.5 ms loopback</td>
      <td>None (fire-and-forget)</td>
      <td>Best-effort &mdash; lost during subscriber reconnect window</td>
      <td>Cache invalidation, WebSocket fan-out, presence beats &mdash; drops are tolerable, speed matters.</td>
    </tr>
    <tr>
      <td><code>Store::publishReliable</code></td>
      <td>~1&ndash;2 ms (XADD + ACK)</td>
      <td>AOF/RDB-backed</td>
      <td>At-least-once via consumer groups</td>
      <td>Command/event sourcing, work queues, must-not-drop business events.</td>
    </tr>
  </tbody>
</table>

<div class="callout warn store-mt-1" id="phpredis-pubsub-caveat">
  <strong>Driver caveat (production-relevant).</strong> phpredis is the preferred driver when <code>ext-redis</code> is loaded &mdash; it's faster than predis for hot CRUD paths. <strong>However, the SUBSCRIBE + HOOK_ALL coroutine spike has so far only been benched against predis</strong> (see <a href="https://github.com/sibidharan/zealphp/blob/master/docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md" target="_blank">spike result</a> &mdash; predis subscribe yields cleanly under OpenSwoole HOOK_ALL, sub-millisecond delivery confirmed across hosts). phpredis's subscribe loop is C-side and has not been validated in this configuration. Production deployments using pub/sub subscribers should either:
  <ol>
    <li><strong>Force predis</strong> for subscribers until the phpredis spike is re-run in your environment:
      <div class="code-block">
<pre><code class="language-php">// Per-instance:
Store::defaultBackend(Store::BACKEND_REDIS, [
    'url'    => 'redis://cache:6379/0',
    'prefer' => Store::PREFER_PREDIS,
]);

// Or via env (deployment-time switch):
ZEALPHP_REDIS_PREFER=predis</code></pre>
      </div>
    </li>
    <li><strong>Bench phpredis under HOOK_ALL load</strong> in staging first &mdash; the spike script (<code>scripts/spike-predis-subscribe.php</code>) is parameterised so swapping the client lib is a small change.</li>
  </ol>
  Hot CRUD paths (HGETALL, INCRBY, mget, etc.) are unaffected &mdash; phpredis vs predis is purely a perf trade-off there, both work correctly under HOOK_ALL.
</div>

<p class="store-lead-tight store-mt-1"><strong>Receiver count semantics:</strong> <code>Store::publish</code> delivers ONE copy to every worker (across every node) running a matching subscriber. So 32 workers per node &times; 2 nodes = <code>receivers: 64</code> for one PUBLISH. That's correct Redis pub/sub &mdash; matches the cross-server WebSocket routing pattern where each worker owns a subset of fds.</p>

<h2 class="store-h2-section" id="demo">Live demo &mdash; this very server</h2>
<p class="store-lead-tight">Each button below fires a real HTTP request against the running ZealPHP instance. Output panel shows the JSON the server returned. Most useful with <code>ZEALPHP_STORE_BACKEND=redis</code>; on the default Table backend the pub/sub buttons surface a clean <code>StoreException</code> error in JSON.</p>
<div class="store-demo-panel">
  <h3>Try it</h3>
  <p class="store-demo-panel-lead">Each click rolls a fresh random message so you can see the receiver count + message ID change.</p>
  <div class="store-demo-controls">
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-roundtrip">Set + Get + Incr (round-trip)</button>
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-publish">Publish (fire-and-forget)</button>
    <button class="btn btn-primary btn-sm" type="button" data-action="store-demo-publish-reliable">Publish (Streams &mdash; reliable)</button>
    <button class="btn btn-ghost btn-sm"   type="button" data-action="store-demo-pubsub-log">Read pubsub log</button>
  </div>
  <pre class="demo-json-pane">Click a button above to fire a request. The response JSON will land here.</pre>
  <p class="store-demo-hint">Endpoints: <code>/demo/store-roundtrip</code>, <code>/demo/pubsub/publish</code>, <code>/demo/pubsub/publish-reliable</code>, <code>/demo/pubsub/log</code>. See <a href="/pubsub">/pubsub</a> for the multi-tab walkthrough.</p>
</div>

<h2 class="store-h2-section" id="cluster">Redis Cluster / Sentinel</h2>
<p class="store-lead-tight">For HA topologies that span multiple Redis nodes (Cluster) or use a failover monitor (Sentinel), drive the driver with a pre-wired <code>Predis\Client</code> instead of a URL string. Predis natively supports both topologies via its constructor parameters — ZealPHP&rsquo;s adapter accepts the prebuilt client and uses it as-is for the connection pool.</p>
<div class="code-block">
<pre><code class="language-php">use Predis\Client as PredisClient;
use ZealPHP\Store\{RedisBackend, RedisConnectionPool, PredisDriver};

// === Cluster (3-node, key-slot routing) ===
$cluster = new PredisClient(
    ['tcp://node1:7000', 'tcp://node2:7000', 'tcp://node3:7000'],
    ['cluster' => 'redis'],
);
// Wire as the Store backend (pool of 1 because Cluster manages its own connections):
$backend = new RedisBackend(
    new RedisConnectionPool(
        url: 'unused-for-cluster',
        size: 1,
        opts: ['prefer' => Store::PREFER_PREDIS],   // phpredis Cluster needs RedisCluster — future v0.2.41
    ),
);
// Or simpler — bypass the URL+pool layer:
$driver = new PredisDriver($cluster);

// === Sentinel (master/slave failover) ===
$sentinel = new PredisClient(
    ['tcp://sentinel1:26379', 'tcp://sentinel2:26379'],
    ['replication' => 'sentinel', 'service' => 'mymaster'],
);
$driver = new PredisDriver($sentinel);</code></pre>
</div>
<p class="store-lead-tight store-mt-1">First-class Store-facade integration (a Store::clusterBackend() / Store::sentinelBackend() helper) is on the v0.2.41 roadmap. Today's path is to construct the Predis\Client + PredisDriver directly and inject into a RedisBackend. phpredis users wanting Cluster/Sentinel should currently use predis (set <code>ZEALPHP_REDIS_PREFER=predis</code>); the <code>RedisCluster</code> phpredis class needs a separate driver shape.</p>

<h2 class="store-h2-section">When to use Redis / Valkey</h2>
<p class="store-lead-tight">Store and Cache cover most single-server apps. Here's when you'll need an external cache.</p>

<div class="store-grid-tight">
  <div>
    <h3 class="store-col-good">Built-in Cache is great for</h3>
    <ul class="store-col-list">
      <li>Single-server deployments (most apps)</li>
      <li>Caching API responses, config, computed values</li>
      <li>Rate limiting and request counting</li>
      <li>Session-adjacent data (preferences, feature flags)</li>
      <li>Apps with &lt; 100k cache entries</li>
    </ul>
  </div>
  <div>
    <h3 class="store-col-bad">Move to Redis / Valkey when you need</h3>
    <ul class="store-col-list">
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
