<?php use ZealPHP\App; $active = $active ?? 'learn/async'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 11,
      'title'    => 'Async & Coroutines',
      'subtitle' => 'OpenSwoole gives PHP go-style concurrency. Here is when it helps.',
      'prev'     => ['slug' => 'learn/websocket', 'title' => 'WebSocket'],
      'next'     => ['slug' => 'learn/deployment', 'title' => 'Deployment'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What a coroutine is and how OpenSwoole schedules them',
      'go() + Channel for parallel I/O',
      'When coroutines help and when they don\'t',
    ]]); ?>

    <h2>One request, many tasks</h2>
    <p>
      In classic PHP (php-fpm), each request is a separate process. If you need to call two APIs,
      you call them sequentially — total time = sum of both. In ZealPHP, each request is a
      <strong>coroutine</strong>. Inside that coroutine you can spawn child coroutines with <code>go()</code>
      and synchronize them with a <code>Channel</code>. Total time = max of both.
    </p>

    <h2>The pattern: go() + Channel</h2>
    <pre><code>use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;

$app-&gt;route('/parallel-demo', function() {
    $ch = new Channel(2);

    go(function() use ($ch) {
        co::sleep(0.5);                // simulate a 500ms API call
        $ch-&gt;push(['users' =&gt; 42]);
    });

    go(function() use ($ch) {
        co::sleep(0.3);                // simulate a 300ms DB query
        $ch-&gt;push(['posts' =&gt; 128]);
    });

    $results = [];
    for ($i = 0; $i &lt; 2; $i++) {
        $results[] = $ch-&gt;pop();       // blocks this coroutine, not the worker
    }

    return $results;
    // Total time: ~500ms (max), not 800ms (sum)
});</code></pre>

    <p><code>go()</code> spawns a new coroutine on the same worker. <code>$ch->pop()</code> suspends the parent coroutine until a value arrives — but the worker thread is free to handle other requests while it waits.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Where do coroutines help?',
      'body'    => '<p>Anywhere you wait on I/O: HTTP calls, database queries, file reads, DNS lookups, sleep. If two I/O tasks are independent, run them in parallel with <code>go()</code>.</p><p>Coroutines do <strong>not</strong> help with CPU-bound work. If you need to hash 10 million passwords, a coroutine won\'t make it faster — use task workers instead.</p>',
    ]); ?>

    <h2>Live demo — parallel vs. sequential</h2>
    <p>
      The endpoint <code>/api/learn/demo/timing</code> runs three 100ms sleeps either sequentially
      (~300ms total) or in parallel via <code>go() + Channel</code> (~100ms total).
    </p>
    <?php App::render('/components/_tryit', ['title' => 'Timing comparison', 'body' => <<<HTML
      <div style="display:flex;gap:1rem;margin:.75rem 0">
        <button class="counter-btn" onclick="fetchTiming('sequential', this)">Sequential</button>
        <button class="counter-btn" onclick="fetchTiming('parallel', this)">Parallel</button>
        <span id="timing-result" style="align-self:center;font-family:monospace;font-size:.9rem"></span>
      </div>
      <script>
      function fetchTiming(mode, btn) {
        document.getElementById('timing-result').textContent = '…';
        fetch('/api/learn/demo/timing?mode=' + mode)
          .then(r => r.json())
          .then(d => { document.getElementById('timing-result').textContent = d.mode + ': ' + d.elapsed_ms + 'ms'; })
          .catch(e => { document.getElementById('timing-result').textContent = 'error'; });
      }
      </script>
HTML]); ?>

    <h2>Coroutine::sleep vs. usleep</h2>
    <pre><code>co::sleep(0.5);  // yields — other coroutines run while this one sleeps
usleep(500000);  // blocks — the worker thread is stuck for 500ms</code></pre>
    <p>Always use <code>co::sleep()</code> inside coroutine contexts. The framework's HTTP handlers run inside a coroutine, so <code>co::sleep()</code> works. The one exception: inside a Generator returned from a route handler, <code>co::sleep()</code> is a no-op — use <code>usleep()</code> for artificial delays there (as we did in the <a href="/learn/components">render-stream demo</a>).</p>

    <?php App::render('/components/_deepdive', [
      'title' => 'Task workers — for CPU-bound work',
      'body'  => '<p>ZealPHP supports task workers for background jobs (e.g., sending emails, generating PDFs). Dispatch via <code>App::getServer()->task([\'handler\' => \'/task/job\', \'args\' => [...]])</code>. Task handlers live in <code>task/</code>. They run in separate processes, so they won\'t block request workers.</p>',
    ]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/ai-chat">← Add AI Chat</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/deployment">Deployment →</a>
    </div>
  </article>
</div>
