<?php use ZealPHP\App; $active = $active ?? 'learn/async'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 25,
      'title'    => 'Async Patterns',
      'subtitle' => 'Channels, error handling, task workers, race conditions. Foundations covers the model.',
      'prev'     => ['slug' => 'learn/routing',     'title' => 'Routes & APIs'],
      'next'     => ['slug' => 'learn/deployment',  'title' => 'Ship It'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Fan-out / fan-in with go() + Channel — running N requests in parallel',
      'Buffered vs unbuffered channels and when each one is right',
      'Error handling in coroutines — exceptions don\'t propagate; design for it',
      'Task workers vs coroutines — when to reach for each',
      'Race conditions and what counts as a yield point',
    ]]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'This builds on Foundations',
      'body'    => '<p>Foundations &rarr; <a href="/learn/mental-model">The Mental Model</a> introduces the coroutine runtime — what stays warm, the per-coroutine isolation, why workers don\'t fork per request. This lesson is the layer above: patterns for using coroutines deliberately, channels for coordination, and the failure modes you hit on real apps.</p>',
    ]); ?>

    <h2>Pattern 1: fan-out, fan-in with <code>go()</code> + <code>Channel</code></h2>
    <p>
      The bread-and-butter parallel-I/O pattern. Three API calls in parallel; collect the results
      through a buffered channel; total elapsed time is the slowest call, not the sum.
    </p>
    <pre><code class="language-php">use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;

$app-&gt;route('/api/dashboard', function () {
    $ch = new Channel(3);   // buffered: 3 producers can push without blocking

    go(fn() =&gt; $ch-&gt;push(['key' =&gt; 'users',  'data' =&gt; Users::recent()]));
    go(fn() =&gt; $ch-&gt;push(['key' =&gt; 'orders', 'data' =&gt; Orders::pending()]));
    go(fn() =&gt; $ch-&gt;push(['key' =&gt; 'stats',  'data' =&gt; Stats::today()]));

    $out = [];
    for ($i = 0; $i &lt; 3; $i++) {
        $row = $ch-&gt;pop();   // blocks until something arrives
        $out[$row['key']] = $row['data'];
    }
    return $out;
});</code></pre>
    <p>
      Three coroutines, three channel pushes, three pops. The framework swaps coroutines whenever
      one of them hits a yield point (an I/O call, a <code>co::sleep()</code>, a channel
      <code>push</code>/<code>pop</code>). The worker isn&rsquo;t blocked — it can serve other
      requests in parallel even while this handler waits.
    </p>

    <h2>Buffered vs unbuffered channels</h2>
    <p>
      A <code>Channel(N)</code> buffers up to N values before producers block. <code>Channel(0)</code>
      (or just <code>Channel()</code>) is unbuffered — every push waits for a matching pop, like a
      one-slot handoff. Pick by what you actually need:
    </p>
    <table class="cmp-table">
      <thead><tr><th>Channel</th><th>Behavior</th><th>Use when</th></tr></thead>
      <tbody>
        <tr><td><code>new Channel($n)</code></td><td>Producer pushes up to <code>n</code> values without blocking; the <code>n+1</code>th push blocks until a pop frees a slot.</td><td>Fan-out where producers may finish before the consumer is ready (the dashboard example above)</td></tr>
        <tr><td><code>new Channel(0)</code></td><td>Every push waits for a paired pop &mdash; rendezvous handoff.</td><td>Strict synchronization, single-slot pipelines, back-pressure</td></tr>
      </tbody>
    </table>

    <h2>Pattern 2: error handling across <code>go()</code></h2>
    <p>
      Exceptions thrown <em>inside</em> a <code>go()</code> closure don&rsquo;t propagate to the
      handler that spawned it. The coroutine ends, the exception goes to the worker&rsquo;s log, and
      the parent waits forever if it was expecting a channel value. Always push <em>something</em>:
    </p>
    <pre><code class="language-php">function safe_fetch(Channel $ch, string $key, callable $work): void {
    go(function () use ($ch, $key, $work) {
        try {
            $ch-&gt;push(['key' =&gt; $key, 'ok' =&gt; true,  'data' =&gt; $work()]);
        } catch (\Throwable $e) {
            $ch-&gt;push(['key' =&gt; $key, 'ok' =&gt; false, 'error' =&gt; $e-&gt;getMessage()]);
        }
    });
}

// Caller treats both shapes:
$ch = new Channel(3);
safe_fetch($ch, 'users',  fn() =&gt; Users::recent());
safe_fetch($ch, 'orders', fn() =&gt; Orders::pending());
safe_fetch($ch, 'stats',  fn() =&gt; Stats::today());

$out = [];
for ($i = 0; $i &lt; 3; $i++) {
    $row = $ch-&gt;pop();
    if ($row['ok']) $out[$row['key']] = $row['data'];
    else            $out[$row['key']] = ['error' =&gt; $row['error']];
}</code></pre>
    <p>
      The trick is the channel shape carries success-or-failure. The parent never blocks on a
      coroutine that crashed silently.
    </p>

    <h2>Pattern 3: <code>co::sleep()</code> vs <code>usleep()</code></h2>
    <p>
      Inside a coroutine, <code>co::sleep(1)</code> yields the coroutine to the scheduler for 1
      second — the worker can serve other requests during that wait. <code>usleep(1_000_000)</code>
      blocks the whole worker for 1 second — no other requests get processed.
    </p>
    <pre><code class="language-php">// Bad — pins the worker for 5 seconds. Other requests queue.
$app-&gt;route('/poll', function () {
    while (!Job::isDone()) {
        usleep(500_000);
        $progress = Job::progress();
    }
    return ['done' =&gt; true];
});

// Good — yields between checks. Worker handles other requests.
$app-&gt;route('/poll', function () {
    while (!Job::isDone()) {
        co::sleep(0.5);
        $progress = Job::progress();
    }
    return ['done' =&gt; true];
});</code></pre>
    <p>
      The rule extends to every blocking call. PDO with OpenSwoole&rsquo;s
      <code>hook_flags = HOOK_ALL</code> (the default in coroutine mode) automatically becomes
      non-blocking — the framework rewrites the call to yield. <code>curl</code> requests,
      <code>file_get_contents()</code>, <code>sleep()</code> — all hooked. <em>If you call a function
      that isn&rsquo;t hooked, you block the worker.</em> Common offenders: shell-invoking helpers,
      forking helpers, calls into C extensions that don&rsquo;t cooperate. Use a task worker for
      those (next section).
    </p>

    <h2>Pattern 4: task workers for CPU-bound or hostile work</h2>
    <p>
      Coroutines win for I/O concurrency — many requests waiting on something external. They
      <em>don&rsquo;t</em> help with CPU work (PDF rendering, image transcoding, expensive computation)
      because the coroutine never yields until the work is done. Worse, if you call a non-hookable
      blocking function, you pin the worker.
    </p>
    <p>
      Task workers are separate processes managed by OpenSwoole. Dispatch a task; the calling
      coroutine yields; OpenSwoole runs the task in a worker pool; the result comes back. Your HTTP
      worker stays unblocked.
    </p>
    <pre><code class="language-php">// task/render-pdf.php — runs in a task worker, separate process
$render_pdf = function (array $args) {
    // Heavy CPU work here — won&rsquo;t block any HTTP worker
    return Pdf::render($args['template'], $args['data']);
};

// route handler — dispatches and awaits
$app-&gt;route('/api/invoice/{id}', function ($id) {
    $bytes = App::getServer()-&gt;task([
        'handler' =&gt; '/task/render-pdf',
        'args'    =&gt; ['template' =&gt; 'invoice', 'data' =&gt; Invoice::find($id)-&gt;toArray()],
    ]);
    return base64_encode($bytes);
});</code></pre>
    <p>
      Set <code>'task_worker_num' =&gt; 8</code> on <code>$app-&gt;run()</code> to provision the pool.
      Task workers run with <code>task_enable_coroutine =&gt; true</code> by default, so each task
      itself can use <code>go()</code>.
    </p>

    <h2>Pattern 5: per-coroutine context for handler state</h2>
    <p>
      Inside any handler running in coroutine mode, <code>Coroutine::getContext()</code> returns a
      per-coroutine bag &mdash; perfect for storing data you want to flow across helper calls without
      threading parameters everywhere. The framework already uses this for
      <code>RequestContext::instance()</code>; you can use it for your own scoped data.
    </p>
    <pre><code class="language-php">function track_query(string $sql, float $duration): void {
    $ctx = OpenSwoole\Coroutine::getContext();
    $ctx['queries'] = $ctx['queries'] ?? [];
    $ctx['queries'][] = ['sql' =&gt; $sql, 'duration' =&gt; $duration];
}

function dump_queries(): array {
    return OpenSwoole\Coroutine::getContext()['queries'] ?? [];
}

// Use anywhere in the request handler chain — no globals, no leakage.</code></pre>

    <h2>Race conditions: what counts as a yield point</h2>
    <p>
      A coroutine doesn&rsquo;t get pre-empted mid-statement. It only yields at known points:
    </p>
    <ul>
      <li>Hookable blocking calls (PDO, curl, file I/O, <code>sleep()</code> &mdash; with
        <code>HOOK_ALL</code> on)</li>
      <li>Channel <code>push()</code> / <code>pop()</code></li>
      <li>Explicit <code>co::sleep()</code></li>
      <li>Any <code>go()</code> spawn or <code>yield</code> inside a generator</li>
    </ul>
    <p>
      So <code>$counter++</code> on a regular variable is safe between yield points. But this is a
      race:
    </p>
    <pre><code class="language-php">// BAD — yields between read and write
$x = Cache::get('count');     // ← yields here (I/O)
$x++;                         // someone else may have incremented in between
Cache::set('count', $x);      // we overwrite their increment

// GOOD — atomic via the cache backend
Cache::incr('count');         // single atomic operation, no yield window

// GOOD — atomic via Store
Store::incr('counters', 'total', 'n');</code></pre>
    <p>
      The rule of thumb: <em>treat any expression that yields as a possible reentrance point</em>.
      If a value is touched by other requests, read it after a yield and you might be working with
      stale data.
    </p>

    <h2>Try it live</h2>
    <ul>
      <li><a href="/demo/view/streaming/ssr" target="_blank">Generator yield streaming</a> — a coroutine yielding chunks to the browser</li>
      <li><a href="/api/learn/demo/timing?mode=parallel" target="_blank">/api/learn/demo/timing?mode=parallel</a> — sequential vs parallel timing</li>
      <li><a href="/coroutines">/coroutines</a> — docs page with the parallel-vs-sequential live demo</li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'async1',
      'question' => 'Inside a coroutine handler, you call <code>usleep(1_000_000)</code>. What happens to other requests on the same worker?',
      'correct'  => 'b',
      'explain'  => '<code>usleep()</code> is a system call that blocks the whole worker process — it does not yield to the coroutine scheduler. Use <code>co::sleep(1)</code> instead, which yields and lets other coroutines on the same worker run while you wait.',
      'options'  => [
        'a' => 'They keep running — coroutines preempt the sleep.',
        'b' => 'They block too — <code>usleep()</code> pins the worker for 1 second.',
        'c' => 'They run in the task worker pool automatically.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Fan-out with <code>go()</code> + a buffered <code>Channel</code> — total time = slowest task, not sum.',
      'Exceptions don\'t cross <code>go()</code> boundaries; push a success-or-failure shape through the channel.',
      'Use <code>co::sleep()</code> not <code>usleep()</code>; the framework hooks PDO/curl/file I/O for you.',
      'Task workers are for CPU-bound or non-hookable blocking work — separate process pool, not coroutines.',
      'Watch race conditions: any yield point is a re-entrance opportunity. Use atomic primitives for shared state.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/routing"
         hx-get="/api/learn/page?slug=learn/routing" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routing">← Routes & APIs</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/deployment"
         hx-get="/api/learn/page?slug=learn/deployment" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/deployment">Ship It →</a>
    </div>
  </article>
</div>
