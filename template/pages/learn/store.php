<?php use ZealPHP\App; $active = $active ?? 'learn/store'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 10,
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
    'count' =&gt; ['type' =&gt; Store::TYPE_INT,    'size' =&gt; 4],
    'reset' =&gt; ['type' =&gt; Store::TYPE_INT,    'size' =&gt; 4],
    'note'  =&gt; ['type' =&gt; Store::TYPE_STRING, 'size' =&gt; 64],
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
    'count' =&gt; ['type' =&gt; Store::TYPE_INT, 'size' =&gt; 4],
    'reset' =&gt; ['type' =&gt; Store::TYPE_INT, 'size' =&gt; 4],
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
      <li><a href="/demo/store/">/demo/store/</a> — <code>Store</code> in action</li>
      <li><a href="/demo/counter/">/demo/counter/</a> — <code>Counter</code> incrementing on every request</li>
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

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Workers don’t share PHP heaps — isolation by default, sharing by opt-in.',
      '<code>Store::make()</code> allocates a typed, fixed-size table in shared memory — every worker can read/write rows by key.',
      '<code>Counter</code> is a lock-free atomic integer for the simple "one global counter" case.',
      'Allocate both <em>before</em> <code>$app-&gt;run()</code> so the master shares them on fork.',
      'Use Redis only when you outgrow in-process: multi-host, surviving restarts, external consumers.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/streaming"
         hx-get="/api/learn/page?slug=learn/streaming" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/streaming">← Streaming Done Right</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/components">Layouts &amp; Components →</a>
    </div>
  </article>
</div>
