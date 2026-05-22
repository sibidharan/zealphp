<?php use ZealPHP\App; ?>

<section class="section why-section">
  <div class="container why-container">
    <h1 class="section-title why-title">Why ZealPHP?</h1>
    <p class="section-desc why-desc">
      PHP powers 77% of the web. Its execution model is what needs upgrading.<br>
      ZealPHP brings coroutine-native, real-time server architecture to PHP.
    </p>

    <div class="why-proof">
      <strong class="why-proof-strong">Live production proof:</strong> <a href="https://labs.selfmade.ninja" class="why-proof-link">Selfmade Ninja Labs</a> runs the same PHP/MongoDB codebase on Apache <em>and</em> ZealPHP in production. Two servers, one volume, zero downtime during migration. <a href="/case-studies/sna-labs" class="why-proof-link-bold">Read the migration case study →</a>
    </div>

    <div class="why-block">
      <h2 class="why-h2">The problem</h2>
      <p class="why-prose">
        PHP's traditional request-per-process model (PHP-FPM, mod_php) is fundamentally
        incompatible with real-time, high-concurrency, and streaming use cases.
        Every request starts from scratch — no shared state, no persistent connections,
        no coroutines. Building a WebSocket server, streaming AI responses, or running
        background tasks requires leaving PHP entirely for Node.js, Go, or Python.
      </p>
      <p class="why-prose-spaced">
        Existing async PHP solutions are either too low-level (raw Swoole, ReactPHP, AMPHP),
        framework-locked (Laravel Octane), or not native PHP (FrankenPHP, RoadRunner).
        None provide a full-stack, coroutine-native framework with a migration path for existing PHP apps.
      </p>
    </div>

    <div class="why-block">
      <h2 class="why-h2">ZealPHP's approach</h2>
      <div class="why-cards">
        <div class="why-card">
          <h3 class="why-card-title">Coroutine-native, not event-loop</h3>
          <p class="why-card-body">
            Write synchronous-looking code. Under the hood, every I/O call (file, curl, PDO, sleep)
            yields the event loop via OpenSwoole's coroutine hooks. Thousands of concurrent requests
            per worker, zero callback hell.
          </p>
        </div>
        <div class="why-card">
          <h3 class="why-card-title">Full-stack framework, not a library</h3>
          <p class="why-card-body">
            Routing, PSR-15 middleware, templating with streaming, WebSocket, SSE, shared memory,
            task workers, sessions — all integrated. Write <code>$app->route()</code> and ship.
            Not 12 packages wired together.
          </p>
        </div>
        <div class="why-card">
          <h3 class="why-card-title">Legacy PHP bridge — LAMP-style file routing</h3>
          <p class="why-card-body">
            Drop <code>.php</code> files in <code>public/</code> and they route automatically — just like Apache.
            <code>session_start()</code>, <code>header()</code>, <code>$_GET</code>, <code>echo</code>
            all work unchanged via uopz. Drop files in <code>api/</code> and they become REST endpoints.
            Many existing PHP apps — including WordPress sites — run unchanged through the CGI worker bridge. Migrate at your own pace — file by file, feature by feature.
          </p>
        </div>
        <div class="why-card">
          <h3 class="why-card-title">Single-process deployment</h3>
          <p class="why-card-body">
            HTTP server, WebSocket server, task workers, timers, shared memory, sessions —
            all in one <code>php app.php</code>. No Nginx, no Redis, no Supervisor, no cron.
            Deploy a systemd service and you're done.
          </p>
        </div>
      </div>
    </div>

    <div class="why-block">
      <h2 class="why-h2-wide">Competitive landscape</h2>
      <p class="why-note">
        Every project below serves a different need. This comparison is about where ZealPHP fits — not about which is "best."
      </p>
      <div class="why-table-scroll">
        <table class="ztable why-compare-table">
          <tr>
            <th class="why-compare-th">Project</th>
            <th class="why-compare-th">Model</th>
            <th class="why-compare-th">Routing</th>
            <th class="why-compare-th">WebSocket</th>
            <th class="why-compare-th">Streaming</th>
            <th class="why-compare-th">Shared Memory</th>
            <th class="why-compare-th">Legacy PHP</th>
          </tr>
          <tr class="why-zealphp-row">
            <td class="why-zealphp-cell">ZealPHP</td>
            <td>Coroutine</td>
            <td>Built-in</td>
            <td>Built-in</td>
            <td>yield / SSE / stream()</td>
            <td>Store + Counter</td>
            <td>CGI worker</td>
          </tr>
          <tr>
            <td>ReactPHP</td>
            <td>Event loop</td>
            <td>Manual</td>
            <td>Via packages</td>
            <td>Manual</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>AMPHP</td>
            <td>Fiber</td>
            <td>Manual</td>
            <td>Via packages</td>
            <td>Manual</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>FrankenPHP</td>
            <td>Go worker</td>
            <td>Via framework</td>
            <td>Via framework</td>
            <td>Via framework</td>
            <td>No</td>
            <td>Partial</td>
          </tr>
          <tr>
            <td>RoadRunner</td>
            <td>Go worker</td>
            <td>Via framework</td>
            <td>Go plugin</td>
            <td>Via framework</td>
            <td>No</td>
            <td>No</td>
          </tr>
          <tr>
            <td>Laravel Octane</td>
            <td>Swoole/RR</td>
            <td>Laravel</td>
            <td>Via packages</td>
            <td>Limited</td>
            <td>Limited</td>
            <td>No</td>
          </tr>
          <tr>
            <td>Raw Swoole</td>
            <td>Coroutine</td>
            <td>Manual</td>
            <td>Manual</td>
            <td>Manual</td>
            <td>Table / Atomic</td>
            <td>No</td>
          </tr>
        </table>
      </div>
    </div>

    <div class="why-block">
      <h2 id="vs-octane" class="why-h2">ZealPHP vs Laravel Octane</h2>
      <p class="why-octane-intro">Two different problems:</p>
      <ul class="why-octane-list">
        <li class="why-octane-list-item"><strong class="why-strong-white">Laravel Octane</strong> accelerates an existing Laravel application by serving it from a long-running Swoole / RoadRunner / FrankenPHP worker. If you're on Laravel and want it faster, use Octane.</li>
        <li><strong class="why-strong-white">ZealPHP</strong> is a framework-agnostic layer over OpenSwoole. Routing, middleware, WebSocket, SSE, shared memory, timers, and the legacy PHP bridge are exposed as first-class primitives — no Laravel kernel in between.</li>
      </ul>
      <p class="why-octane-outro">If you have a Laravel app, Octane is the right tool. If you're starting fresh, migrating non-Laravel PHP, or need lower-level coroutine primitives, ZealPHP is built for that.</p>
    </div>

    <div class="why-block">
      <h2 class="why-h2">The migration ladder</h2>
      <p class="why-ladder-intro">
        You don't have to learn a framework to start. Drop files in a folder. Upgrade when you need to.
      </p>
      <div class="why-ladder">
        <div class="why-ladder-step">
          <span class="why-ladder-num">0.</span>
          <span class="why-ladder-text"><code>setFallback()</code> — your entire existing app runs unchanged on OpenSwoole</span>
        </div>
        <div class="why-ladder-step">
          <span class="why-ladder-num">1.</span>
          <span class="why-ladder-text"><code>public/*.php</code> — LAMP-style file routing. <code>$_GET</code>, <code>session_start()</code>, <code>echo</code> just work</span>
        </div>
        <div class="why-ladder-step">
          <span class="why-ladder-num">2.</span>
          <span class="why-ladder-text"><code>api/*.php</code> — drop a file, get a REST endpoint. ZealAPI auto-routes by filename</span>
        </div>
        <div class="why-ladder-step">
          <span class="why-ladder-num">3.</span>
          <span class="why-ladder-text"><code>$app->route()</code> — WebSocket, SSE, streaming when you're ready</span>
        </div>
        <div class="why-ladder-step">
          <span class="why-ladder-num">4.</span>
          <span class="why-ladder-text-final"><code>superglobals(false)</code> — full coroutine mode, thousands of concurrent requests</span>
        </div>
      </div>
    </div>

    <div class="why-block">
      <h2 class="why-h2">When to use ZealPHP</h2>
      <div class="why-fit-grid">
        <div>
          <h3 class="why-fit-good">Good fit</h3>
          <ul class="why-fit-list">
            <li class="why-fit-item">AI/LLM apps with streaming responses</li>
            <li class="why-fit-item">Real-time dashboards and live updates</li>
            <li class="why-fit-item">WebSocket apps (chat, collaboration)</li>
            <li class="why-fit-item">High-concurrency APIs (10k+ req/s)</li>
            <li class="why-fit-item">Migrating large PHP codebases to async</li>
            <li class="why-fit-item">LAMP-style PHP devs who want async without learning a framework</li>
            <li class="why-fit-item">Single-process deployments (no infra complexity)</li>
          </ul>
        </div>
        <div>
          <h3 class="why-fit-bad">Not the right fit</h3>
          <ul class="why-fit-list">
            <li class="why-fit-item">Already invested in Laravel ecosystem</li>
            <li class="why-fit-item">Need shared hosting (requires CLI access)</li>
            <li class="why-fit-item">Building a custom protocol server</li>
            <li class="why-fit-item">Committed to AMPHP / Revolt / ReactPHP — Fiber libraries that drive Revolt's event loop, a separate scheduler from OpenSwoole's reactor (an app picks one)</li>
          </ul>
          <p class="why-fit-note">
            <strong class="why-fit-note-strong">Note:</strong>
            <a href="https://openswoole.com/article/openswoole-26-2-released" target="_blank" rel="noopener" class="why-fit-note-link">OpenSwoole 26.2</a> uses PHP's <code>zend_fiber</code> API as its coroutine-context backend (better Xdebug interop) — but Fiber-driven libraries like AMPHP / Revolt still run on a separate scheduler from Swoole's reactor.
          </p>
        </div>
      </div>
    </div>

    <div class="why-block">
      <h2 class="why-h2">Benchmarks</h2>
      <p class="why-bench-intro">
        Numbers below are from one benchmark setup on a single machine. Real-world performance depends on payload size, I/O, OS limits, and tuning. Reproduce them yourself before you trust them.
      </p>
      <div class="bench-method why-bench-method">
        <strong>Method</strong> &nbsp;|&nbsp;
        4 workers, full middleware (CORS + ETag + sessions + PSR-7 routing), <code class="why-bench-inline-code">ab -n 50000 -c 200 -k</code>
        &nbsp;|&nbsp;
        <a href="https://github.com/sibidharan/zealphp/blob/master/PERF.md" target="_blank" rel="noopener">PERF.md</a>
        &nbsp;|&nbsp;
        <a href="https://github.com/sibidharan/zealphp/blob/master/scripts/bench_vs_express.sh" target="_blank" rel="noopener">reproduce locally</a>
      </div>
      <div class="bench why-bench">
        <div class="bench-stat"><div class="num">117k</div><div class="label">req/s text</div><div class="sub">avg 1.7 ms</div></div>
        <div class="bench-stat"><div class="num">106k</div><div class="label">req/s JSON</div><div class="sub">avg 1.9 ms</div></div>
        <div class="bench-stat"><div class="num">50k</div><div class="label">req/s template</div><div class="sub">avg 4.0 ms</div></div>
        <div class="bench-stat"><div class="num">0</div><div class="label">failures</div><div class="sub">/ 150k reqs</div></div>
      </div>
      <p class="why-bench-reproduce">
        Don't trust our numbers — run it yourself:
        <code class="why-bench-reproduce-code">scripts/bench_vs_express.sh</code>
      </p>
    </div>

    <div class="why-cta">
      <h2 class="why-cta-title">Ready to try it?</h2>
      <p class="why-cta-desc">From zero to running server in 60 seconds.</p>
      <a href="/getting-started" class="btn btn-primary why-cta-btn">Get started →</a>
    </div>
  </div>
</section>
