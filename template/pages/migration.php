<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container" style="max-width:960px">

<h1 class="section-title">Migrate your PHP codebase to async</h1>
<p class="section-desc">
  Bring your existing code along. <code>session_start()</code>, <code>header()</code>,
  <code>$_GET</code>, <code>$_POST</code>, <code>echo</code> — all overridden via uopz to
  work inside the coroutine runtime, so the migration ladder starts with "drop your
  app in and run <code>php app.php</code>" rather than "rewrite for an event loop."
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. The before/after stack collapse                             -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:2.5rem">From several services to one process</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1rem">
  <div class="qs-block" style="padding:1.25rem 1.5rem">
    <h3 style="margin:0 0 .85rem;font-size:1rem">Typical PHP stack today</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>Nginx / Apache (front-end)</li>
      <li>PHP-FPM (cold start every request)</li>
      <li>Redis (sessions, cache, pub/sub)</li>
      <li>Socket.io / Ratchet (WebSocket)</li>
      <li>Supervisor / cron (background jobs)</li>
      <li>SSE proxy or browser polling</li>
    </ul>
    <p style="margin:.75rem 0 0;color:#a8a29e;font-size:.78rem">6 services, 6 failure points, 6 sets of config.</p>
  </div>
  <div class="qs-block" style="padding:1.25rem 1.5rem;border-color:var(--accent)">
    <h3 style="margin:0 0 .85rem;font-size:1rem;color:var(--accent)">Same app on ZealPHP</h3>
    <div style="text-align:center;margin:.5rem 0 1rem">
      <code style="font-size:1.05rem;color:var(--accent);background:rgba(245,158,11,.1);padding:.4rem .8rem;border-radius:6px">php app.php</code>
    </div>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>HTTP + WebSocket + SSE built in</li>
      <li>Coroutine-safe sessions (no Redis)</li>
      <li>Shared memory across workers (Store, Counter)</li>
      <li>Task workers (no cron / supervisor)</li>
      <li>Persistent connections, no cold starts</li>
      <li>WordPress via the CGI bridge — <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase</a></li>
    </ul>
    <p style="margin:.75rem 0 0;color:#a8a29e;font-size:.78rem">Not every stack fits. Depends on app — see "When migration won't help" below.</p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. The migration ladder                                        -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">The migration ladder — go at your own pace</h2>

<p style="margin-bottom:1.25rem">
  Each rung is functional on its own. Stop at the rung that gives you enough
  upside without forcing changes you're not ready for. Most real migrations
  stay between rungs 1 and 3 for months before reaching 4.
</p>

<div style="display:grid;gap:.75rem">

<?php
$rungs = [
  [
    'n'    => '0',
    'title' => 'Drop in your entire app, unchanged',
    'code'  => 'App::superglobals(true); $app->setFallback(fn() => App::includeFile(\'index.php\'));',
    'desc'  => 'Most existing PHP apps — WordPress, Drupal, custom legacy code — run unchanged on OpenSwoole through the CGI worker bridge. No code edits required to start serving requests faster.',
    'wins'  => 'Persistent process, no per-request boot. Sub-millisecond TTFB on cached routes.',
    'gives_up' => 'Coroutines, WebSocket, SSE — you\'re still bound by the global-state model.',
  ],
  [
    'n'    => '1',
    'title' => 'Write LAMP-style PHP in <code>public/</code>',
    'code'  => 'public/about.php → /about     ·     public/users/list.php → /users/list',
    'desc'  => 'File-based routing. <code>$_GET</code>, <code>session_start()</code>, <code>echo</code> — everything you know works. No new mental model.',
    'wins'  => 'Add new endpoints without leaving the LAMP idiom your team already uses.',
    'gives_up' => 'Nothing — this is purely additive.',
  ],
  [
    'n'    => '2',
    'title' => 'Add REST APIs in <code>api/</code>',
    'code'  => 'api/users/get.php → GET /api/users     ·     api/users/post.php → POST /api/users',
    'desc'  => 'Drop a PHP file, get a REST endpoint. ZealAPI auto-routes by filename and HTTP method. Zero config, zero framework boilerplate.',
    'wins'  => 'Replace your "PHP file behind nginx" API layer with structured endpoints in 5 lines each.',
    'gives_up' => 'Still synchronous — handlers run sequentially. Fine for I/O-light endpoints.',
  ],
  [
    'n'    => '3',
    'title' => 'Use framework routes for new features',
    'code'  => '$app->route(\'/ws/chat\', ...); $response->sse(...); yield $html;',
    'desc'  => 'WebSocket, SSE streaming, coroutines — available when you\'re ready, not forced upfront. Mix file-based pages with programmatic routes in the same app.',
    'wins'  => 'Real-time features without spinning up a separate Node/Go service. Stream AI responses, push live updates, run background coroutines.',
    'gives_up' => 'Still allows blocking calls inside individual handlers — coroutine isolation is opt-in at rung 4.',
  ],
  [
    'n'    => '4',
    'title' => 'Full coroutine mode',
    'code'  => 'App::superglobals(false);   // thousands of concurrent requests per worker',
    'desc'  => 'Replace <code>$_GET</code>/<code>$_SESSION</code> globals with <code>G::instance()</code>. Each coroutine gets its own context; one worker handles thousands of concurrent requests without blocking.',
    'wins'  => 'Peak throughput. <a href="/performance">117k req/s on 4 workers</a> — Express on the same box does 20k.',
    'gives_up' => 'You must avoid blocking I/O outside coroutine-hooked extensions, and any code that mutates global state needs a per-coroutine equivalent.',
    'highlight' => true,
  ],
];
foreach ($rungs as $r):
  $border = !empty($r['highlight']) ? 'border-color:var(--accent)' : '';
?>
  <div class="qs-block" style="padding:1rem 1.25rem;<?= $border ?>">
    <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start">
      <span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:rgba(245,158,11,.18);color:var(--accent);font-size:.85rem;font-weight:700;flex-shrink:0"><?= $r['n'] ?></span>
      <div>
        <div style="font-weight:700;font-size:1rem;margin-bottom:.4rem"><?= $r['title'] ?></div>
        <code style="display:block;font-size:.78rem;color:#fde68a;background:rgba(245,158,11,.08);padding:.4rem .6rem;border-radius:4px;margin-bottom:.5rem"><?= $r['code'] ?></code>
        <p style="margin:0 0 .4rem;font-size:.88rem;line-height:1.6"><?= $r['desc'] ?></p>
        <p style="margin:.25rem 0 0;font-size:.82rem;line-height:1.5"><strong style="color:var(--accent)">Wins:</strong> <?= $r['wins'] ?></p>
        <p style="margin:.15rem 0 0;font-size:.82rem;line-height:1.5;color:#a8a29e"><strong>Trade-off:</strong> <?= $r['gives_up'] ?></p>
      </div>
    </div>
  </div>
<?php endforeach; ?>

</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. How the compatibility bridge actually works                 -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">How the compatibility bridge works</h2>

<p>
  PHP-FPM gives you fresh superglobals (<code>$_GET</code>, <code>$_SESSION</code>),
  fresh <code>header()</code>, fresh <code>session_start()</code> on every request.
  OpenSwoole is one long-running process — those functions would normally collide
  across requests. ZealPHP fixes that via three mechanisms:
</p>

<ul style="line-height:1.8;margin-top:.5rem">
  <li>
    <strong>uopz function overrides.</strong> At server boot, <code>header()</code>,
    <code>setcookie()</code>, <code>http_response_code()</code>, and the
    <code>session_*()</code> family are replaced with implementations that read/write
    a per-request <code>G::instance()</code> object. Your <code>header('Location: /foo')</code>
    routes to the right OpenSwoole response without you knowing.
  </li>
  <li>
    <strong>Stream-wrapper redirection.</strong>
    <code>php://input</code> is rewired to return the current request body,
    not stdin. Legacy code that does <code>file_get_contents('php://input')</code>
    in a JSON API handler works unchanged.
  </li>
  <li>
    <strong>CGI worker bridge.</strong> When <code>App::superglobals(true)</code> +
    <code>setFallback()</code> are in use, requests that don't match a framework
    route are forwarded to a CGI-style child process via <code>proc_open</code> —
    full process isolation, just like mod_php. That's how WordPress runs.
  </li>
</ul>

<p style="margin-top:.75rem">
  Net effect — at rung 0 and 1, your code can't tell it's running on OpenSwoole.
  At rungs 3 and 4, you opt into the coroutine model where it pays off.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. When migration is a good fit (and when it isn't)            -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin-top:3rem">When migration is a good fit</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:1rem">
  <div class="qs-block" style="padding:1.25rem 1.5rem;border-color:var(--accent)">
    <h3 style="margin:0 0 .75rem;font-size:1rem;color:var(--accent)">Good fit</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>✓ You're already on PHP and the team knows it</li>
      <li>✓ You want WebSocket / SSE / streaming without a separate Node service</li>
      <li>✓ You have I/O-bound endpoints (DB, HTTP fetches) — coroutines fan them out</li>
      <li>✓ You hit PHP-FPM bottlenecks (request rate, cold start latency, FPM pool tuning)</li>
      <li>✓ You want long-lived sessions or pub/sub without Redis</li>
      <li>✓ You want to keep <code>session_start()</code> + <code>header()</code> + <code>echo</code> — not rewrite for an event loop</li>
    </ul>
  </div>
  <div class="qs-block" style="padding:1.25rem 1.5rem">
    <h3 style="margin:0 0 .75rem;font-size:1rem">Probably wrong fit</h3>
    <ul style="list-style:none;padding:0;margin:0;font-size:.88rem;line-height:1.8">
      <li>✗ Workload is purely CPU-bound — coroutines don't help, just buy more cores</li>
      <li>✗ App relies on extensions OpenSwoole's runtime hooks don't cover (rare, but exists)</li>
      <li>✗ You'd accept a full rewrite anyway — Go/Rust/Elixir give bigger ceilings if you can pay the cost</li>
      <li>✗ Hard requirement for shared-nothing per-request memory (PHP-FPM's strongest guarantee)</li>
      <li>✗ Production team can't accept alpha (v0.2.x) stability — wait for v1.0</li>
    </ul>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. Closing CTAs                                                -->
<!-- ────────────────────────────────────────────────────────────── -->

<div style="text-align:center;margin-top:3rem">
  <a href="/getting-started" class="btn btn-primary">Start the migration →</a>
  <a href="/legacy-apps" class="btn btn-outline" style="margin-left:.5rem">Legacy apps (WordPress) →</a>
  <a href="/why-zealphp" class="btn btn-outline" style="margin-left:.5rem">Why ZealPHP →</a>
</div>

<p style="text-align:center;margin-top:1.5rem;color:#a8a29e;font-size:.85rem">
  Performance: <a href="/performance">117K req/s text · 106K JSON · 50K templated</a> at rung 4 (full coroutine mode).<br>
  WordPress + custom CMS migrations: see the <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase repo</a>.
</p>

</div>
</section>
