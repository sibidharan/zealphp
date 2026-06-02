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

<h2 id="cache" class="store-h2-section">Cache — general-purpose key-value with TTL</h2>
<p class="store-lead">Tiered cache built on Store. Memory tier (fast, cross-worker) + file tier (persistent, survives restarts). No Redis needed for most apps.</p>

<div class="callout info">
  <strong>Cache follows whichever Store backend is configured.</strong> Internally <code>Cache::*</code>
  delegates to <code>Store::set</code>/<code>get</code>/<code>del</code>/<code>iterate</code> against
  an internal <code>__cache</code> table. Flip the backend and Cache follows automatically:
  <ul>
    <li><code>Store::defaultBackend(Store::BACKEND_TABLE)</code> &rarr; in-memory shared via OpenSwoole\Table (single-server, ns latency)</li>
    <li><code>Store::defaultBackend(Store::BACKEND_REDIS)</code> &rarr; cross-node Redis/Valkey</li>
    <li><code>Store::defaultBackend(Store::BACKEND_TIERED)</code> &rarr; L1 Table + L2 Redis (bounded-staleness fast read + cluster-wide truth)</li>
    <li><code>Store::defaultBackend(Store::BACKEND_MEMCACHED)</code> &rarr; flat KV cache over Memcached</li>
  </ul>
  The Cache file tier (<code>.cache/</code> directory) is a SEPARATE layer that works alongside whichever
  Store backend you've picked &mdash; it persists across restarts; Store memory state doesn't.
</div>

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
    <td><code>Counter</code> (<code>OpenSwoole\Atomic</code>) — <code>compareAndSet</code>, <code>increment</code>, <code>decrement</code>, <code>reset</code>, <code>get</code>, <code>set</code></td>
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

<h2 class="store-h2-section" id="backends">Pluggable backends — Table / Redis / Tiered / Memcached</h2>
<p class="store-lead-tight">As of v0.2.39, <code>Store</code> and <code>Counter</code> are <strong>backend-agnostic</strong>. Four backends ship in-tree; pick by scope. Every <code>Store::set/get/incr/count</code> call works unchanged across all four.</p>

<div class="code-block">
<pre><code class="language-php">use ZealPHP\Store;

// Recommended — type-safe enum (IDE autocomplete + refactor-safe):
Store::defaultBackend(Store::BACKEND_TABLE);                                // default
Store::defaultBackend(Store::BACKEND_REDIS, 'redis://cache.internal:6379'); // cross-node
Store::defaultBackend(Store::BACKEND_TIERED, [                              // ns reads + cross-node truth
    'url'                 =&gt; 'redis://cache.internal:6379',
    'l1_ttl'              =&gt; 5,                              // L1 freshness window (seconds)
    'invalidation_secret' =&gt; getenv('ZEALPHP_TIERED_INVALIDATION_SECRET') ?: null,
]);

// Bare strings still work for BC (also: ZEALPHP_STORE_BACKEND=redis|tiered env):
Store::defaultBackend(Store::BACKEND_REDIS);
Store::defaultBackend(Store::BACKEND_TIERED, 'redis://cache:6379');</code></pre>
</div>

<table class="store-compare-tbl store-mt-1">
  <thead><tr><th>Backend</th><th>Latency</th><th>Cross-node</th><th>Persistence</th><th>Bounded growth</th><th>When to pick</th></tr></thead>
  <tbody>
    <tr>
      <td><code>Store::BACKEND_TABLE</code> (default)</td>
      <td>~ns (lock-free shared memory)</td>
      <td>No &mdash; one OpenSwoole server</td>
      <td>No &mdash; volatile</td>
      <td>HARD: <code>maxRows</code> at <code>make()</code></td>
      <td>Single-node hot path. Millions of ops/sec. 95% of apps.</td>
    </tr>
    <tr>
      <td><code>Store::BACKEND_REDIS</code></td>
      <td>~tens of &micro;s local, ~ms cross-node</td>
      <td>Yes &mdash; any number of nodes</td>
      <td>Yes (Redis AOF/RDB)</td>
      <td>Server-side <code>maxmemory</code> + <code>maxmemory-policy</code></td>
      <td>Horizontal scaling, persistent state, existing Redis infra.</td>
    </tr>
    <tr>
      <td><code>Store::BACKEND_TIERED</code></td>
      <td>~ns on L1 hit, ~ms on L1 miss (L2)</td>
      <td>Yes (via L2)</td>
      <td>Yes (via L2)</td>
      <td>L1 capped by Table; L2 via Redis policy</td>
      <td>Hot keys with ns reads + cross-node visibility for cold keys + tolerate <code>l1_ttl</code>-bounded staleness.</td>
    </tr>
    <tr>
      <td><code>Store::BACKEND_MEMCACHED</code></td>
      <td>~sub-ms local</td>
      <td>Yes &mdash; any number of nodes</td>
      <td>No &mdash; volatile</td>
      <td>Server-side <code>max_memory</code> LRU eviction</td>
      <td>Flat KV cache over an existing Memcached cluster; no pub/sub or Streams support.</td>
    </tr>
  </tbody>
</table>

<p class="store-lead-tight store-mt-1"><strong>Two table modes</strong> at <code>make()</code>: <code>'tracked'</code> (default) keeps a membership SET so <code>count()</code> is O(1); <code>'ttl'</code> supports per-key expiry but <code>count()</code> falls back to O(N) <code>SCAN</code>. Pick one per table. Mixing tracked + ttl throws at boot (v0.2.41 hardening — H1). The connection pool is per-worker (default 8 clients) &mdash; concurrent coroutines never share a socket.</p>

<p class="store-lead-tight store-mt-1"><strong>Client lib</strong>: auto-detects phpredis (preferred when <code>ext-redis</code> is loaded) or predis (pure-PHP fallback, shipped as a dev dep). User code never imports a phpredis/predis symbol &mdash; the single <code>ZealPHP\Store\RedisClient</code> adapter is the only place either lib is referenced.</p>

<h3 id="backend-scope">Backend scope — what "single process" really means</h3>
<p class="store-lead-tight"><code>OpenSwoole\Table</code> is shared memory allocated by the master process BEFORE fork, inherited by all worker processes of that server. So the Table backend is:</p>
<ul class="store-col-list">
  <li>Shared across <strong>coroutines</strong> in one worker ✓</li>
  <li>Shared across <strong>workers</strong> in one OpenSwoole server ✓ (the whole point)</li>
  <li>NOT shared across two <code>php app.php</code> invocations on the same machine ✗ (different mmap segments)</li>
  <li>NOT shared across <strong>machines</strong> ✗</li>
</ul>
<p class="store-lead-tight">For cross-server state, use Redis or Tiered. For cross-tab/cross-tenant WS routing where state must survive node death, see <a href="/pubsub#ws-routing">/pubsub#ws-routing</a>.</p>

<h3 id="backend-memory">Memory math for the Table backend</h3>
<p class="store-lead-tight"><code>maxRows</code> is allocated UP FRONT at the OpenSwoole master fork, not lazily. Empirically (PHP 8.3 + OpenSwoole 22.x):</p>
<table class="store-compare-tbl">
  <thead><tr><th>Rows</th><th>Schema</th><th>Allocated</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td>1,024</td><td>1 × STRING(32)</td><td>~290 KB</td><td>Hash overhead dominates at small sizes</td></tr>
    <tr><td>1,000,000</td><td>1 × STRING(32)</td><td>~280 MB</td><td>Roughly 280 B/row including overcommit + per-row mutex/metadata</td></tr>
    <tr><td><code>PHP_INT_MAX</code></td><td>any</td><td>OOM-killed</td><td>No artificial cap; the kernel decides</td></tr>
  </tbody>
</table>
<p class="store-lead-tight"><strong>Rule of thumb:</strong> <code>RAM ≈ maxRows × (4 × Σ column sizes + ~32 B/row)</code>. The 4× factor is OpenSwoole's open-addressed-hash overcommit. For <code>Cache::init(4096)</code> with the default 8 KB value column: ~130 MB reserved per worker server. When you'd say "tens of millions of rows", flip to Redis (server-side bound) or Tiered (L1 stays small, L2 grows).</p>

<h3 id="backend-cache-asymmetry">Cache::init &amp; the <code>maxRows</code> chokepoint</h3>
<p class="store-lead-tight"><code>Cache::init($maxRows, $cacheDir, $gcIntervalMs, $ttlSeconds)</code> behaves differently across backends &mdash; flagged at boot when misconfigured (v0.2.41 hardening):</p>
<ul class="store-col-list">
  <li><strong>Table backend:</strong> <code>$maxRows</code> is a HARD CAP. Cache spills oversize / overflow to the file tier automatically.</li>
  <li><strong>Redis backend:</strong> <code>$maxRows</code> has NO equivalent &mdash; Redis is a global KV store. Pair with <code>$ttlSeconds</code> for per-key auto-expiry, OR configure Redis-server <code>maxmemory</code> + <code>maxmemory-policy allkeys-lru</code> for cluster-wide bound. If you pass a non-default <code>$maxRows</code> without a TTL on the Redis backend, <code>Cache::init()</code> emits a one-line warning telling you so.</li>
  <li><strong>Tiered backend:</strong> L1 honours <code>$maxRows</code> (Table); L2 (Redis) ignores it as above.</li>
</ul>
<div class="code-block">
<pre><code class="language-php">// Recommended Redis-backed Cache config — bounded by per-key TTL:
Cache::init(
    maxRows:    4096,      // HARD CAP on Table; informational on Redis
    cacheDir:   '/var/cache',
    ttlSeconds: 3600,      // On Redis → mode='ttl' auto-expiry
);

// Read-through pattern — canonical helper (no more get-then-compute boilerplate):
$users = Cache::getOrCompute("users:active", fn() =&gt; DB::select(...), ttl: 60);
// First call computes + caches; subsequent calls within TTL short-circuit to the cached read.
// Null is cached as a valid value (sentinel-based miss detection).</code></pre>
</div>

<h2 class="store-h2-section" id="pubsub">Pub/sub + Streams (cross-node messaging)</h2>
<p class="store-lead-tight">Two public primitives on top of the Redis backend for cross-worker AND cross-host messaging. Both require <code>Store::defaultBackend(Store::BACKEND_REDIS)</code>.</p>

<div class="code-block">
<pre><code class="language-php">// Fire-and-forget pub/sub
App::subscribe('chat:room:42', function (string $payload, string $channel) {
    // runs in every worker that's registered; routes to your local fd map
});
$receivers = Store::publish('chat:room:42', json_encode($message));

// Reliable variant via Redis Streams (at-least-once via consumer groups)
App::subscribeReliable('orders', function (string $payload, string $id, string $stream): bool {
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

<div class="callout info store-mt-1" id="phpredis-pubsub-caveat">
  <strong>Driver choice (both validated as of v0.2.40).</strong> Both phpredis (preferred when <code>ext-redis</code> is loaded) and predis (pure-PHP fallback) SUBSCRIBE loops yield correctly under <code>OpenSwoole\Runtime::HOOK_ALL</code> &mdash; the production default in coroutine mode. Empirical results <a href="https://github.com/sibidharan/zealphp/blob/master/docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md" target="_blank">(spike doc)</a>:
  <ul>
    <li><strong>predis:</strong> 760 ops/sec aggregate, 0.40 ms PUBLISH receive median.</li>
    <li><strong>phpredis:</strong> 775 ops/sec aggregate, 0.23 ms PUBLISH receive median, ~2&times; faster on hot CRUD per-op (11 ms vs 23 ms for 50-RTT batches).</li>
  </ul>
  <strong>Pick phpredis when you can</strong> &mdash; it&rsquo;s faster across the board. Force predis only if you can&rsquo;t install <code>ext-redis</code>:
  <div class="code-block">
<pre><code class="language-php">Store::defaultBackend(Store::BACKEND_REDIS, [
    'url'    => 'redis://cache:6379/0',
    'prefer' => Store::PREFER_PREDIS,   // or Phpredis (default when ext loaded)
]);
// Or via env: ZEALPHP_REDIS_PREFER=predis</code></pre>
  </div>
  <strong>One nuance to remember:</strong> phpredis SUBSCRIBE blocks the worker WITHOUT HOOK_ALL (C-side socket read). HOOK_ALL is on by default in coroutine mode (<code>App::superglobals(false)</code>); if you&rsquo;ve disabled it explicitly, force predis for subscribers OR re-enable HOOK_ALL.
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

<h2 class="store-h2-section" id="production-hardening">Production hardening (v0.2.41)</h2>
<p class="store-lead-tight">
  A senior-eng review of the v0.2.39/v0.2.40 Redis backend surface flagged 3 critical + 10 medium issues — all closed in this pass. Each gets a brief description + the opt-in recipe. Default behaviour is unchanged; nothing on this page is a BC break.
</p>

<h3 id="ph-tls">C3 — TLS via <code>rediss://</code></h3>
<p class="store-lead-tight">
  Cross-region or untrusted-network deployments need encrypted Redis connections. Both drivers now recognise <code>rediss://</code> (and the <code>tls://</code> alias). <code>verify_peer + verify_peer_name</code> are on by default.
</p>
<div class="code-block">
<pre><code class="language-php">Store::defaultBackend(Store::BACKEND_REDIS, 'rediss://cache.prod:6380/0');
// or  ZEALPHP_REDIS_URL=rediss://cache.prod:6380/0</code></pre>
</div>
<p class="store-lead-tight">Bare <code>redis://</code> (no host) is now rejected at parse time — surfaces misconfig at boot instead of after silently defaulting to <code>127.0.0.1</code>.</p>

<h3 id="ph-fd-race">C1 — WSRouter FD-reuse race fix</h3>
<p class="store-lead-tight">
  The previous cross-server WS routing pattern was vulnerable to a FD-reuse race: when client A's <code>onClose</code> was lost (kernel reaped the fd, OpenSwoole reassigned it to client B), an in-flight publish to A could land on B's connection — a cross-tenant data-leakage vector.
</p>
<p class="store-lead-tight">
  The fix is a per-connection 16-byte hex nonce stored in <code>ws_owner.conn_id</code> + <code>WSRouter::$localFds[clientId]['conn_id']</code>. <code>WSRouter::sendToClient()</code> carries the nonce in the publish payload; the subscriber on the owning server verifies it matches the local <code>conn_id</code> before pushing. Stale entries with the same fd are evicted on <code>own()</code> (the fd-coherence invariant).
</p>
<p class="store-lead-tight">No API change for callers — <code>WSRouter::own($clientId, $fd)</code> still works exactly as before; it now returns the generated <code>conn_id</code> for tests + debug.</p>

<h3 id="ph-hmac">C2 — HMAC-signed L1 invalidation</h3>
<p class="store-lead-tight">
  <code>TieredBackend</code> publishes invalidation messages on <code>{prefix}:__l1_invalidate:{table}</code>. Before this fix, anyone with Redis write access could forge invalidations and DoS L1 across the cluster. Now: optional shared secret signs every outbound message; receivers verify before evicting.
</p>
<div class="code-block">
<pre><code class="language-php">// Same secret on every node:
$tiered = new TieredBackend(
    l1: new TableBackend(),
    l2: new RedisBackend(/* … */),
    invalidationSecret: getenv('ZEALPHP_TIERED_INVALIDATION_SECRET') ?: null,
);
// Or via env: ZEALPHP_TIERED_INVALIDATION_SECRET=&lt;32-byte-random-hex&gt;</code></pre>
</div>
<p class="store-lead-tight">No secret set → trust mode (any peer message accepted; preserves the v0.2.40 default). When set, messages without a matching truncated HMAC-SHA256 are silently dropped + <code>elog</code>'d at warn level. Same secret on every node, or peers will reject each other's invalidations.</p>

<h3 id="ph-validation">H1 — tracked + TTL combo throws at <code>make()</code></h3>
<p class="store-lead-tight">
  Pre-v0.2.41 silently ignored TTL on tracked-mode tables (the membership SET would have drifted). Now it throws at <code>make()</code> with a clear message — fail fast at boot, not silently after the first expiry.
</p>

<h3 id="ph-getstrict">H2 — <code>Store::getStrict()</code> for new code</h3>
<p class="store-lead-tight">
  The legacy <code>Store::get()</code> returns <code>false</code> on miss for BC. New code that wants ??-style fallbacks safely with stored falsy values (0, '', '0') uses the strict variant — returns null on miss.
</p>
<div class="code-block">
<pre><code class="language-php">// Legacy — keep using === false to detect misses:
$row = Store::get('users', $id);
if ($row === false) { /* miss */ }

// New code — null on miss, value otherwise:
$row = Store::getStrict('users', $id);
$hits = Store::getStrict('users', $id, 'hits') ?? 0;   // 0-stored value preserved</code></pre>
</div>

<h3 id="ph-pipeline">H3 — pipelined bulk ops + UNLINK</h3>
<p class="store-lead-tight">
  <code>Store::mget</code>, <code>Store::mset</code>, and <code>Store::clear</code> on the Redis backend now run in a single MULTI/EXEC pipeline instead of N sequential round-trips. <code>clear()</code> uses <code>UNLINK</code> (non-blocking, Redis 4.0+) so multi-second clears on 10k+-row tables drop to sub-second.
</p>
<p class="store-lead-tight">No API change — existing call sites get the speedup automatically.</p>

<h3 id="ph-breaker">H4 — Circuit breaker (opt-in graceful degradation)</h3>
<p class="store-lead-tight">
  When Redis is degraded, every <code>Store</code> call previously hit the 5-second pool acquire timeout. The <code>CircuitBreakerBackend</code> decorator adds three-state fail-fast: <em>closed</em> (normal) → <em>open</em> (skip primary) → <em>half-open</em> (one probe). Reads can fall back to a Table backend; writes throw (no fallback semantics for writes — they'd diverge on recovery).
</p>
<div class="code-block">
<pre><code class="language-php">// Opt in via the connection opts:
Store::defaultBackend(Store::BACKEND_REDIS, [
    'url'      =&gt; 'redis://cache:6379',
    'on_error' =&gt; 'fallback_table',   // wraps with CircuitBreakerBackend
    'breaker'  =&gt; [
        'failure_threshold'   =&gt; 5,   // failures within window to trip
        'failure_window_sec'  =&gt; 10,  // sliding window
        'open_seconds'        =&gt; 30,  // cooldown before half-open probe
    ],
]);

// Inspect state for ops dashboards:
$b = Store::defaultBackend();
echo $b-&gt;state();  // 'closed' | 'open' | 'half-open'</code></pre>
</div>
<p class="store-lead-tight">Default (no opt) — no decoration, throws on Redis down (the v0.2.40 behaviour). Reads use the fallback when OPEN; writes always surface the failure to the caller.</p>

<h3 id="ph-stats">H5 — <code>Store::stats()</code> operational visibility</h3>
<p class="store-lead-tight">
  Per-worker counter snapshot — pool acquires, pool acquire timeouts, pool clients created. Pub/sub instances expose <code>pubsub_reconnects_total</code>, <code>pubsub_messages_received_total</code>, <code>pubsub_handler_errors_total</code> via <code>RedisPubSub::stats()</code>. Wire to your monitoring of choice (Prometheus, statsd, plain log line).
</p>
<div class="code-block">
<pre><code class="language-php">// Plumb into a health endpoint:
$app->route('/health/store', fn() =&gt; Store::stats());
// → ["pool_acquires_total":1247, "pool_clients_created_total":8, ...]</code></pre>
</div>

<h3 id="ph-boot-checks">H6 + H7 — boot-time advisories</h3>
<ul class="store-col-list">
  <li><strong>H6 (ping):</strong> when <code>ZEALPHP_STORE_BACKEND=redis</code>, <code>App::run()</code> PINGs the pool once at boot. Failure → loud <code>error_log</code> in the master before workers fork. Misconfigured URL surfaces immediately, not after the first request 5 seconds in.</li>
  <li><strong>H7 (HOOK_ALL + phpredis):</strong> phpredis SUBSCRIBE blocks the worker without <code>OpenSwoole\Runtime::HOOK_ALL</code> — the production default. If you've explicitly disabled HOOK_ALL AND have <code>App::subscribe</code> handlers registered AND phpredis is the resolved driver, boot emits a warning telling you to either re-enable HOOK_ALL or set <code>ZEALPHP_REDIS_PREFER=predis</code>.</li>
</ul>

<h3 id="ph-exists-cached">H8 — <code>TieredBackend::existsCached()</code></h3>
<p class="store-lead-tight">
  The strict <code>exists()</code> always hits L2 for consistency. <code>existsCached()</code> returns true when L1 has a fresh entry (within <code>$l1Ttl</code>); otherwise defers to L2. Use on hot paths where "probably exists" + <code>$l1Ttl</code>-bounded staleness is acceptable — saves a Redis round-trip.
</p>

<h3 id="ph-misc">H9 + H10 — operational debug</h3>
<ul class="store-col-list">
  <li><strong>H9:</strong> <code>PhpredisDriver::close()</code> exceptions now route through <code>elog</code> at debug level instead of being swallowed silently — diagnosable disconnect bugs.</li>
  <li><strong>H10:</strong> <code>RedisPubSub</code> takes an optional <code>$maxAttempts</code> param (default 0 = unlimited, preserving the existing eventually-reconnect behaviour). Set to N&gt;0 to fail loudly after N consecutive reconnect cycles — useful for CI workers that should crash if Redis disappears.</li>
</ul>

<p class="store-lead-tight store-mt-1">Full review notes + the original risk-by-risk mapping: <a href="https://github.com/sibidharan/zealphp/blob/master/docs/architecture/2026-05-23-redis-backend-review.md" target="_blank">docs/architecture/2026-05-23-redis-backend-review.md</a>.</p>

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
