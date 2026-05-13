<?php use ZealPHP\App; ?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>PHP at the <span>speed of Go</span></h1>
    <p>ZealPHP is an async PHP framework built on OpenSwoole.<br>
       Coroutines, SSR streaming, WebSocket, and zero blocking — all in PHP.</p>
    <div class="cta">
      <a href="/routing" class="btn btn-primary">Get Started →</a>
      <a href="https://github.com/sibidharan/zealphp" class="btn btn-outline" target="_blank">GitHub ↗</a>
    </div>
    <div class="bench">
      <div class="bench-stat"><div class="num">wrk</div><div class="label">Benchmark runner</div></div>
      <div class="bench-stat"><div class="num">1K</div><div class="label">Concurrency sweep</div></div>
      <div class="bench-stat"><div class="num">env</div><div class="label">Worker count</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">Blocking calls</div></div>
    </div>
  </div>
</section>

<!-- Quick start -->
<section class="section" style="background:var(--bg-dark);color:#e2e8f0">
  <div class="container">
    <h2 style="color:#fff;margin-bottom:.5rem">Quick Start</h2>
    <p style="color:#94a3b8;margin-bottom:1.5rem">Three lines to a running async server.</p>
    <div class="quickstart">
      <div><span class="comment"># Install</span></div>
      <div><span class="cmd">composer require sibidharan/zealphp</span></div>
      <div>&nbsp;</div>
      <div><span class="comment"># app.php</span></div>
      <div><span class="php">&lt;?php</span></div>
      <div>use ZealPHP\App;</div>
      <div>&nbsp;</div>
      <div>App::superglobals(false);</div>
      <div>$app = App::init('0.0.0.0', 8080);</div>
      <div>&nbsp;</div>
      <div>$app->route('/hello/{name}', function($name) {</div>
      <div>&nbsp;&nbsp;&nbsp;&nbsp;return ['hello' => $name, 'framework' => 'ZealPHP'];</div>
      <div>});</div>
      <div>&nbsp;</div>
      <div>$app->run();</div>
      <div>&nbsp;</div>
      <div><span class="comment"># Start</span></div>
      <div><span class="cmd">php app.php</span></div>
      <div><span class="comment"># → http://localhost:8080/hello/world</span></div>
    </div>
  </div>
</section>

<!-- Feature grid -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Everything you need</h2>
    <p class="section-desc">Every feature is a live running example — click any card to explore.</p>
    <div class="feature-grid">
      <?php
      $features = [
        ['⚡', 'Routing',    'Flask-style routes with URL params, namespaces, and regex patterns. Reflection-based parameter injection.', '/routing',    'route(), nsRoute()'],
        ['📦', 'Responses',  'json(), redirect(), stream(), sse(), header(), cookie() — all coroutine-safe.', '/responses',  'HTTP\Response'],
        ['🔀', 'Coroutines', 'go() + Channel for parallel async IO. Thousands of concurrent requests on a single worker.', '/coroutines', 'OpenSwoole'],
        ['📡', 'Streaming',  'SSR streaming via Generator yield, stream() callback, and Server-Sent Events.', '/streaming',  'SSR · SSE'],
        ['🔌', 'WebSocket',  'Real-time bi-directional with rooms, auth, binary frames, and heartbeat.', '/ws',          'App::ws()'],
        ['🛡️', 'Middleware', 'CORS, ETag/304, and custom PSR-15 middleware in any order.', '/middleware', 'PSR-15'],
        ['🗄️', 'Sessions',  'Coroutine-safe sessions replacing all native session_*() functions via uopz.', '/sessions',   'uopz hooks'],
        ['🗃️', 'Store',     'Cross-worker shared memory via OpenSwoole\\Table. Lock-free atomic counters.', '/store',      'Store · Counter'],
        ['⏱️', 'Timers',    'App::tick/after for recurring tasks. Per-worker via onWorkerStart.', '/timers',      'Timer'],
        ['🌐', 'HTTP',      'HEAD, OPTIONS, 301/307 redirects, CORS, ETag, gzip — full HTTP/1.1.', '/http',       'HTTP/1.1'],
        ['🔗', 'ZealAPI',   'File-based REST endpoints. Drop a PHP file in api/ and it becomes a route.', '/api',        'implicit routes'],
      ];
      foreach ($features as [$icon, $title, $body, $href, $badge]) {
        App::render('/components/_card', compact('icon', 'title', 'body', 'href', 'badge'));
      }
      ?>
    </div>
  </div>
</section>

<!-- Why ZealPHP -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Why ZealPHP?</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;margin-top:1.5rem">
      <?php
      $why = [
        ['🚀', 'No blocking',        'Every I/O call — file, DB, HTTP — yields to the event loop. OpenSwoole HOOK_ALL makes existing PHP libraries async automatically.'],
        ['🧵', 'True coroutines',    'Not fake async with callbacks. Real coroutines with go() + Channel. Write synchronous code that runs concurrently.'],
        ['🔧', 'PHP you already know','Superglobals, sessions, headers — all work via uopz overrides. Migrate existing apps without rewriting everything.'],
        ['📐', 'PSR standards',      'PSR-7 request/response, PSR-15 middleware. Drop in any PSR-15 middleware package.'],
        ['📊', 'Benchmarked',        'Built on OpenSwoole for high-concurrency PHP services, with repeatable wrk/ab scripts for publishing ZealPHP-specific results.'],
        ['🔓', 'Open source',        'MIT licensed. Maintained by the community. Built on OpenSwoole, one of PHP\'s most battle-tested async runtimes.'],
      ];
      foreach ($why as [$icon, $title, $body]):
      ?>
      <div class="card">
        <div class="card-icon"><?= $icon ?></div>
        <h3><?= $title ?></h3>
        <p><?= $body ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
