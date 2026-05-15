<?php use ZealPHP\App; $active = $active ?? 'learn/async'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 13,
      'title'    => 'Async & Coroutines',
      'subtitle' => 'Run I/O in parallel. One API, two functions, 3x speedup.',
      'prev'     => ['slug' => 'learn/routing', 'title' => 'Routes & APIs'],
      'next'     => ['slug' => 'learn/deployment', 'title' => 'Ship It'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why sequential I/O wastes time',
      'The go() + Channel pattern for parallel work',
      'When coroutines help (I/O) and when they don\'t (CPU)',
      'Task workers for CPU-bound jobs',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Your app needs to call two APIs before rendering a page. Sequentially: 500ms + 300ms = 800ms.
      But the two calls don't depend on each other. What if you could run them at the same time and
      wait only for the slower one?
    </p>

    <h2>The mental model</h2>
    <p>
      Coroutines are like <strong>juggling</strong>. You throw ball 1 (API call 1) and while it's in
      the air, you throw ball 2 (API call 2). You catch them as they come down. You never waited idle
      &mdash; while one ball was airborne, your hands were busy throwing the next.
    </p>
    <p>
      <code>go()</code> is throwing a ball. <code>$ch->pop()</code> is catching it.
    </p>

    <h2>The pattern: go() + Channel</h2>
    <pre><code class="language-php">use OpenSwoole\Coroutine\Channel;

$app->route('/dashboard', function() {
    $ch = new Channel(2);

    go(function() use ($ch) {
        co::sleep(0.5);                // simulate 500ms API call
        $ch->push(['users' => 42]);
    });

    go(function() use ($ch) {
        co::sleep(0.3);                // simulate 300ms DB query
        $ch->push(['posts' => 128]);
    });

    $results = [];
    for ($i = 0; $i < 2; $i++) {
        $results[] = $ch->pop();       // blocks THIS coroutine, not the worker
    }
    return $results;
    // Total: ~500ms (max), not 800ms (sum)
});</code></pre>
    <p>
      <code>go()</code> spawns a new coroutine on the same worker. <code>$ch->pop()</code> suspends
      the parent coroutine until a value arrives &mdash; but the worker thread is free to handle other
      requests while it waits.
    </p>

    <h2>Live demo: sequential vs parallel</h2>
    <p>
      The endpoint below runs three 100ms sleeps. Sequential: ~300ms. Parallel via <code>go() + Channel</code>: ~100ms.
    </p>
    <?php App::render('/components/_tryit', ['title' => 'Timing comparison', 'body' => <<<HTML
      <div style="display:flex;gap:1rem;margin:.75rem 0">
        <button class="counter-btn" onclick="fetchTiming('sequential', this)">Sequential</button>
        <button class="counter-btn" onclick="fetchTiming('parallel', this)">Parallel</button>
        <span id="timing-result" style="align-self:center;font-family:monospace;font-size:.9rem"></span>
      </div>
      <script>
      function fetchTiming(mode, btn) {
        document.getElementById('timing-result').textContent = 'running...';
        fetch('/api/learn/demo/timing?mode=' + mode)
          .then(r => r.json())
          .then(d => { document.getElementById('timing-result').textContent = d.mode + ': ' + d.elapsed_ms + 'ms'; })
          .catch(() => { document.getElementById('timing-result').textContent = 'error'; });
      }
      </script>
HTML]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'When coroutines help',
      'body'    => '<p><strong>I/O-bound work:</strong> HTTP calls, database queries, file reads, DNS lookups, <code>co::sleep()</code>. If two I/O tasks are independent, run them in parallel.</p><p><strong>NOT CPU-bound work.</strong> Coroutines don\'t make computation faster &mdash; they make <em>waiting</em> cheaper. For CPU-heavy work (image processing, PDF generation), use task workers instead.</p>',
    ]); ?>

    <h2>co::sleep() vs usleep()</h2>
    <pre><code class="language-php">co::sleep(0.5);  // yields &mdash; other coroutines run while this one sleeps
usleep(500000);  // blocks &mdash; the worker thread is stuck for 500ms</code></pre>
    <p>Always use <code>co::sleep()</code> inside coroutine contexts. The one exception: inside a Generator returned from a route handler, <code>co::sleep()</code> is a no-op &mdash; use <code>usleep()</code> for artificial delays there.</p>

    <?php App::render('/components/_deepdive', [
      'title' => 'Task workers for CPU-bound jobs',
      'body'  => '<p>ZealPHP supports task workers for background jobs (sending emails, generating PDFs, crunching data). Dispatch via <code>App::getServer()->task([\'handler\' => \'/task/job\', \'args\' => [...]])</code>. Task handlers live in <code>task/</code>. They run in separate processes, so they won\'t block request workers.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      '<code>go()</code> spawns a coroutine; <code>Channel</code> synchronizes results',
      'Parallel I/O: total time = max(tasks), not sum(tasks)',
      'Coroutines help with I/O (network, file, DB) &mdash; not CPU computation',
      'Use task workers for CPU-heavy work that would block request handling',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/routing"
         hx-get="/api/learn/page?slug=learn/routing" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routing">&larr; Routes &amp; APIs</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/deployment"
         hx-get="/api/learn/page?slug=learn/deployment" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/deployment">Ship It &rarr;</a>
    </div>
  </article>
</div>
