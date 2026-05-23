<?php use ZealPHP\App; $active = $active ?? 'learn/store'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 12,
      'title'    => 'Sharing State: Store & Counter',
      'subtitle' => 'Workers don\'t share memory. Workers share a fridge.',
      'prev'     => ['slug' => 'learn/streaming',  'title' => 'Streaming Done Right'],
      'next'     => ['slug' => 'learn/components', 'title' => 'Layouts & Components'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why each worker has its own heap — and why that\'s usually a feature',
      'How <code>Store</code> gives you typed shared memory across all workers',
      'When <code>Counter</code> beats <code>Store</code> for atomic integer state',
      'How this compares to Redis — and when to still reach for Redis',
    ]]); ?>

    <h2>The workers don’t share a heap</h2>
    <p>
      ZealPHP runs N worker processes (default: number of CPU cores). Each worker is a separate
      OS process with its own PHP heap. A static class property in one worker is invisible to the
      other workers. Same for any object you create — it lives in <em>one</em> worker’s
      memory.
    </p>
    <p>
      That’s usually fine. Most request state belongs to one request, handled by one worker
      — isolation prevents cross-talk. But sometimes you genuinely want shared state: a hit
      counter, a rate-limiter, a cache of expensive lookups, a session of active WebSocket rooms.
      You need a fridge: a place outside the kitchens where every cook can drop something in and
      every cook can take something out.
    </p>

    <h2>Store: typed shared memory</h2>
    <p>
      <code>ZealPHP\Store</code> wraps OpenSwoole’s shared-memory <code>Table</code>. You
      declare a schema, the framework allocates a fixed-size block in shared memory (mmap), and
      every worker can read/write rows by key:
    </p>
    <pre><code class="language-php">// In app.php, BEFORE $app-&gt;run():
use ZealPHP\Store;

Store::make('rate_limits', 10000, [
    'count' =&gt; [Store::TYPE_INT,    4],
    'reset' =&gt; [Store::TYPE_INT,    4],
    'note'  =&gt; [Store::TYPE_STRING, 64],
]);</code></pre>
    <p>Once registered, any worker can interact with the table by name:</p>
    <pre><code class="language-php">// In a route handler — this works from any worker:
Store::set('rate_limits', $userIp, [
    'count' =&gt; 1,
    'reset' =&gt; time() + 60,
    'note'  =&gt; 'api',
]);

$row = Store::get('rate_limits', $userIp);          // read whole row
$count = Store::get('rate_limits', $userIp, 'count'); // read one field
$now  = Store::incr('rate_limits', $userIp, 'count'); // atomic +1
$gone = Store::del('rate_limits', $userIp);
$how_many = Store::count('rate_limits');</code></pre>

    <h2>Lifecycle: make tables BEFORE run()</h2>
    <p>
      Shared memory is allocated in the master process, then inherited by every worker on fork.
      That means <strong>you must call <code>Store::make()</code> before <code>$app-&gt;run()</code></strong>.
      If you try to make a table inside a request handler, only the current worker sees it —
      the others have no idea it exists.
    </p>
    <p>
      Same applies to <code>Counter</code> (next section). The rule of thumb: <em>anything that
      should outlive a single request, register in</em> <code>app.php</code> <em>at boot.</em>
    </p>

    <h2>Counter: lock-free atomic integers</h2>
    <p>
      For the common case of "I just need to count something across workers," <code>Counter</code>
      is a simpler primitive — one integer, atomic operations, no table schema:
    </p>
    <pre><code class="language-php">use ZealPHP\Counter;

// In app.php, before run():
$visits = new Counter(0); // initial value

// In a handler (or any worker):
$visits-&gt;increment();           // atomic +1, returns new value
$visits-&gt;decrement(2);          // atomic -2
$visits-&gt;get();                 // read current value
$visits-&gt;set(1000);             // overwrite
$ok = $visits-&gt;compareAndSet(1000, 0); // atomic CAS — reset only if value is exactly 1000</code></pre>
    <p>
      Under the hood, <code>Counter</code> wraps <code>OpenSwoole\Atomic</code>. Lock-free, no
      kernel hop, no syscall per operation — faster than incrementing a Redis key by a couple
      of orders of magnitude.
    </p>

    <h2>Store vs Counter vs sessions vs Redis</h2>
    <table class="cmp-table">
      <thead><tr><th>Tool</th><th>Best for</th><th>Lives where</th></tr></thead>
      <tbody>
        <tr><td><code>Counter</code></td><td>One global integer (visits, queue depth, retry count)</td><td>Shared memory, in-process</td></tr>
        <tr><td><code>Store</code></td><td>Keyed rows with typed columns (rate-limit tables, room state, hot caches)</td><td>Shared memory, in-process</td></tr>
        <tr><td>Sessions</td><td>Per-user data (cart, login state, preferences)</td><td>Disk files, keyed by cookie</td></tr>
        <tr><td>Redis</td><td>State that must survive a deploy / span multiple hosts</td><td>Network</td></tr>
      </tbody>
    </table>
    <p>
      The first three are free — you don’t pay an extra-service tax. Redis is for the
      cases where in-process shared memory isn’t enough: multi-machine deployments, hot data
      that must outlive a restart, queues consumed by external workers. <em>Don’t reach for
      Redis when Store will do.</em>
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Tables are fixed-size',
      'body'    => '<p>Shared memory is allocated up front. <code>Store::make(\'name\', 10000, ...)</code> reserves space for 10,000 rows. Try to insert a 10,001st and you get a write failure (and a warning). Plan capacity at boot. For unbounded sets, fall back to Redis or a database.</p>',
    ]); ?>

    <h2>A complete example: per-IP rate limit</h2>
    <pre><code class="language-php">// app.php
Store::make('rate', 100000, [
    'count' =&gt; [Store::TYPE_INT, 4],
    'reset' =&gt; [Store::TYPE_INT, 4],
]);

$app-&gt;route('/api/expensive', function ($request) {
    $ip   = $request-&gt;server['remote_addr'];
    $now  = time();
    $row  = Store::get('rate', $ip);

    if (!$row || $row['reset'] &lt; $now) {
        Store::set('rate', $ip, ['count' =&gt; 1, 'reset' =&gt; $now + 60]);
        return ['ok' =&gt; true, 'remaining' =&gt; 9];
    }
    if ($row['count'] &gt;= 10) return 429;
    $new = Store::incr('rate', $ip, 'count');
    return ['ok' =&gt; true, 'remaining' =&gt; 10 - $new];
});</code></pre>
    <p>
      Ten requests per minute per IP, shared across all workers, with zero external infrastructure.
      The same logic with Redis is more lines, more failure modes, and one more service to keep
      alive at 3 AM.
    </p>

    <h2>Try it live</h2>
    <ul>
      <li><a href="/demo/view/store/set-get" target="_blank">Store: write → read across workers</a></li>
      <li><a href="/demo/view/store/incr" target="_blank">Store: atomic increment</a> — refresh in another tab to confirm</li>
      <li><a href="/demo/view/counter/increment" target="_blank">Counter: lock-free atomic int</a></li>
      <li><a href="/store">/store</a> — full reference docs</li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'store1',
      'question' => 'You write <code>Store::make(\'cache\', 100, [...])</code> inside a route handler. The next request hits a different worker. What happens when that worker calls <code>Store::get(\'cache\', $key)</code>?',
      'correct'  => 'c',
      'explain'  => 'Shared memory is allocated in the master process before workers fork. A table created inside one request handler only exists in that one worker. The other workers don’t know about it. Always call <code>Store::make()</code> in <code>app.php</code> before <code>$app-&gt;run()</code>.',
      'options'  => [
        'a' => 'It reads the value the first handler stored — tables are shared.',
        'b' => 'It silently returns <code>null</code> — the table exists but is empty.',
        'c' => 'It errors — the table doesn\'t exist in this worker\'s view.',
      ],
    ]); ?>

    <h2 id="step-redis">Going cross-node: pluggable Redis backend</h2>
    <p>
      Workers in one process tree share <code>Store</code> via shared memory. Two ZealPHP servers
      on different machines don&rsquo;t &mdash; their <code>Store</code> instances are completely
      separate. When you actually need cross-node visibility (a chat app where users hit different
      load-balanced servers, an admin dashboard summing counters from N hosts), flip
      <code>Store</code> to the Redis backend with one line. Use the <strong>enum</strong> form for
      type-safety + IDE autocomplete:
    </p>
    <pre><code class="language-php">use ZealPHP\Store;

// app.php — before $app->run()
Store::defaultBackend(Store::BACKEND_REDIS);                  // ZEALPHP_REDIS_URL env
// or explicit:
Store::defaultBackend(Store::BACKEND_REDIS, [
    'url' => 'redis://cache.internal:6379/0',
]);

// Bare string still works for BC (also: ZEALPHP_STORE_BACKEND=redis env):
Store::defaultBackend(Store::BACKEND_REDIS);

// Every existing Store::set / get / incr / count call now routes to Redis.
// Counter::defaultBackend follows automatically when the env var is used.</code></pre>
    <p>
      Backend-pluggable means every existing handler keeps working unchanged. The trade-off:
      Redis is ~50&micro;s loopback vs <code>OpenSwoole\Table</code>&rsquo;s ~ns &mdash; orders of
      magnitude slower, but cross-node and persistent (with AOF/RDB). Pick Table for ns hot paths,
      Redis when you need the cross-node guarantee. Both phpredis (preferred when
      <code>ext-redis</code> is loaded) and predis SUBSCRIBE loops yield correctly under
      <code>HOOK_ALL</code> &mdash; either driver is production-validated.
    </p>

    <h3 id="step-tiered">Want both? Tiered backend (L1 Table + L2 Redis)</h3>
    <p>
      <code>Store::BACKEND_TIERED</code> pairs a TableBackend (L1, ns latency, bounded-staleness
      via <code>l1_ttl</code>) with a RedisBackend (L2, source of truth, cross-node). Reads return
      L1 if fresh, else fetch L2 + populate L1. Writes write-through to L2 + refresh L1. Optional
      HMAC-signed cross-node L1 invalidation keeps every node&rsquo;s L1 in sync sub-millisecond.
    </p>
    <pre><code class="language-php">Store::defaultBackend(Store::BACKEND_TIERED, [
    'url'                 =&gt; 'redis://cache:6379',
    'l1_ttl'              =&gt; 5,                              // L1 freshness window (seconds)
    'invalidation_secret' =&gt; getenv('ZEALPHP_TIERED_INVALIDATION_SECRET') ?: null,
]);</code></pre>
    <p>
      The <code>invalidation_secret</code> is shared across every node in the cluster so peers
      verify each other&rsquo;s evictions (without it, anyone with Redis access could DoS the
      cluster&rsquo;s L1). Same secret everywhere, or peers reject each other&rsquo;s messages.
      See <a href="/store#backends">/store#backends</a> for the full comparison table +
      <a href="/store#backend-memory">/store#backend-memory</a> for the Table RAM-cost math.
    </p>

    <h3 id="step-cache-getorcompute"><code>Cache::getOrCompute</code> &mdash; the canonical read-through helper</h3>
    <p>
      Cache &mdash; Store&rsquo;s higher-level cousin with a memory tier + file tier &mdash; ships
      a <code>getOrCompute()</code> helper that collapses the "miss-then-compute-then-store"
      boilerplate into one call:
    </p>
    <pre><code class="language-php">// Before:
$users = Cache::get('users:active');
if ($users === null) {
    $users = DB::select(...);     // expensive
    Cache::set('users:active', $users, ttl: 60);
}

// After:
$users = Cache::getOrCompute('users:active', fn() =&gt; DB::select(...), ttl: 60);</code></pre>
    <p>
      Null is cached as a valid stored value (sentinel-based miss detection &mdash; "stored null"
      is distinct from "no key"). Useful when a lookup legitimately returns null and you don&rsquo;t
      want to re-execute it on every request.
    </p>

    <h2 id="step-pubsub">Cross-node messaging: pub/sub + Streams</h2>
    <p>
      Redis backend also unlocks two new public primitives for cross-worker AND cross-host
      messaging. Both are no-ops on the Table backend (they throw a clear
      <code>StoreException</code>):
    </p>
    <pre><code class="language-php">// Fire-and-forget pub/sub (best-effort, ~0.5ms loopback)
App::onPubSub('chat:room:42', function (string $payload, string $channel) {
    // every worker on every server with this handler runs the callback
    // — perfect for fan-out broadcast to local WebSocket fds.
});
$receivers = Store::publish('chat:room:42', json_encode($message));

// Reliable at-least-once via Redis Streams (consumer groups)
App::onReliableMessage('orders', function (string $payload, string $id, string $stream): bool {
    return processOrder($payload); // true → XACK; false/throw → leave pending
});
$messageId = Store::publishReliable('orders', json_encode($order));</code></pre>
    <p>
      Pick <code>publish</code> for cache invalidation, WebSocket fan-out, presence beats &mdash;
      drops are tolerable. Pick <code>publishReliable</code> for command/event sourcing, work
      queues, anything that must not drop. Both require the Redis backend; pattern channels
      (<code>'chat:*'</code>) and pattern handlers (PSUBSCRIBE) work transparently. See
      <a href="/store#pubsub">/store#pubsub</a> for the full comparison and the production driver
      note.
    </p>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Workers don’t share PHP heaps — isolation by default, sharing by opt-in.',
      '<code>Store::make()</code> allocates a typed, fixed-size table in shared memory — every worker can read/write rows by key.',
      '<code>Counter</code> is a lock-free atomic integer for the simple "one global counter" case.',
      'Allocate both <em>before</em> <code>$app-&gt;run()</code> so the master shares them on fork.',
      'Need cross-node? One line: <code>Store::defaultBackend(Store::BACKEND_REDIS)</code> — every existing handler routes to Redis with zero changes.',
      'Cross-node messaging: <code>Store::publish</code> + <code>App::onPubSub</code> for fire-and-forget, <code>Store::publishReliable</code> + <code>App::onReliableMessage</code> for at-least-once.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/streaming"
         hx-get="/api/learn/page?slug=learn/streaming" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/streaming">← Streaming Done Right</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/components">Layouts &amp; Components →</a>
    </div>
  </article>
</div>
