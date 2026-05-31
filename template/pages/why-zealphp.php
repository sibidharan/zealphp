<?php use ZealPHP\App; ?>

<section class="section why-section">
  <div class="container why-container">
    <h1 class="section-title why-title">Why ZealPHP?</h1>
    <p class="section-desc why-desc">
      PHP powers ~71% of the web (per <a href="https://w3techs.com/technologies/details/pl-php" target="_blank" rel="noopener">W3Techs</a>) — always as a worker behind a C-based HTTP server.<br>
      ZealPHP runs PHP as the HTTP server itself: coroutine-native, always-on, WebSocket/SSE/timers first-class.
    </p>

    <div class="why-proof">
      <strong class="why-proof-strong">Live production proof:</strong> <a href="https://labs.selfmade.ninja" class="why-proof-link">Selfmade Ninja Labs</a> runs the same PHP/MongoDB codebase on Apache <em>and</em> ZealPHP in production. Two servers, one volume, zero downtime during migration. <a href="/case-studies/sna-labs" class="why-proof-link-bold">Read the migration case study →</a>
    </div>

    <div class="why-block">
      <h2 class="why-h2">The problem</h2>
      <p class="why-prose">
        In the traditional model (PHP-FPM, mod_php), the HTTP server is C — nginx or Apache.
        PHP is the worker that bridges in via FastCGI and exits per-request.
        Every request starts from scratch — no shared state, no persistent connections,
        no coroutines. Building a WebSocket server, streaming AI responses, or running
        background tasks requires leaving PHP entirely for Node.js, Go, or Python.
        ZealPHP collapses this: the HTTP server IS PHP, long-lived, with a coroutine per request.
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
            all work unchanged via ext-zealphp. Drop files in <code>api/</code> and they become REST endpoints.
            Many traditional PHP patterns — including WordPress — run through the CGI worker bridge in compatibility mode, with documented limits. Migrate at your own pace — file by file, feature by feature.
          </p>
        </div>
        <div class="why-card">
          <h3 class="why-card-title">PHP as the application server</h3>
          <p class="why-card-body">
            HTTP, WebSocket, task workers, timers, shared memory, sessions — in one PHP application server,
            started with <code>php app.php</code>. On one node you can skip the Redis/Supervisor/cron tier;
            for cross-node deploys, ZealPHP's Store + pub/sub flip to a Redis/Valkey backend with one line of config.
            Front it with nginx/Caddy/Traefik in production for TLS + horizontal scaling.
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
      <h2 id="vs-raw-openswoole" class="why-h2">ZealPHP vs raw OpenSwoole &mdash; engine vs harness</h2>
      <p class="why-octane-intro">
        The most common HN-shaped question: <em>&ldquo;it&rsquo;s just OpenSwoole &mdash; why the extra layer?&rdquo;</em>
        Same shape as &ldquo;it&rsquo;s just Node http &mdash; why Express?&rdquo; You can write raw <code>onRequest</code>
        handlers and ship them; people do. The catch is that every project that does ends up re-inventing
        the same 12 things. <a href="https://openswoole.com/" target="_blank" rel="noopener">OpenSwoole</a> is the engine.
        ZealPHP is the harness that lets you steer it without re-implementing the glue.
      </p>
      <div class="why-engine-grid">
        <div class="why-engine-col why-engine-col-engine">
          <h3 class="why-engine-col-title">OpenSwoole gives you</h3>
          <p class="why-engine-col-tag">the runtime &mdash; raw power</p>
          <ul class="why-engine-list">
            <li>HTTP server + WebSocket\Server primitives (<code>onRequest</code>, <code>onMessage</code>, <code>onOpen</code>, <code>onClose</code>)</li>
            <li>Coroutines: <code>go()</code>, <code>Channel</code>, <code>WaitGroup</code>, <code>Coroutine::getContext()</code></li>
            <li><code>Atomic</code> + <code>Table</code> &mdash; lock-free shared memory across workers</li>
            <li><code>Coroutine\Http\Client</code> + DNS + sleep + file I/O hooks</li>
            <li><code>OpenSwoole\Runtime::enableCoroutine(HOOK_ALL)</code> &mdash; PHP I/O yields the reactor automatically</li>
            <li><code>Process\Pool</code> + master/manager/worker lifecycle</li>
            <li>Timers: <code>Timer::tick()</code>, <code>Timer::after()</code></li>
            <li><code>Process</code> for sub-process fork + IPC pipes</li>
            <li>FastCGI coroutine client (v22.1+)</li>
          </ul>
        </div>
        <div class="why-engine-col why-engine-col-harness">
          <h3 class="why-engine-col-title">ZealPHP adds on top</h3>
          <p class="why-engine-col-tag">the harness &mdash; usable surface</p>
          <ul class="why-engine-list">
            <li><strong>Routing</strong> &mdash; <code>route()</code> + <code>nsRoute</code> + <code>nsPathRoute</code> + <code>patternRoute</code> with reflection-based parameter injection (<a href="/routing">/routing</a>)</li>
            <li><strong>PSR-15 middleware stack</strong> &mdash; 18 built-ins (CORS, ETag, Range, Compression, RateLimit, BasicAuth, HostRouter, ScopedMiddleware, &hellip;) covering common Apache mod_*  / nginx behaviors (<a href="/middleware">/middleware</a>)</li>
            <li><strong><code>ext-zealphp</code> overrides</strong> &mdash; <code>session_start()</code>, <code>header()</code>, <code>setcookie()</code>, <code>http_response_code()</code>, <code>headers_list()</code>, the entire <code>session_*()</code> family, <code>flush()</code>, <code>apache_request_headers()</code>, <code>is_uploaded_file()</code> all just work, routing to per-request state instead of mutating process globals</li>
            <li><strong>Coroutine-safe sessions</strong> &mdash; <code>CoSessionManager</code> with per-coroutine isolation, no <code>$_SESSION</code> races across concurrent requests (<a href="/sessions">/sessions</a>)</li>
            <li><strong>Templating</strong> &mdash; <code>App::render</code> / <code>renderToString</code> / <code>renderStream</code> / <code>include</code> / <code>fragment</code> &mdash; htmx-style named regions, streaming-Generator output, sub-template composition (<a href="/templates">/templates</a>)</li>
            <li><strong>Universal return contract</strong> &mdash; <code>int</code> = status, <code>array</code> = JSON, <code>Generator</code> = SSE/SSR stream, <code>string</code> = HTML, <code>Closure</code> = param-injected stream &mdash; one contract across route handler, public file, API closure, fallback, error handler, render(), include() (<a href="/responses#return-contract">/responses#return-contract</a>)</li>
            <li><strong>ZealAPI</strong> &mdash; file-based REST: drop <code>api/device/list.php</code> &rarr; <code>/api/device/list</code> auto-routes; auth hooks (<code>authChecker</code>, <code>adminChecker</code>, <code>usernameProvider</code>) (<a href="/api">/api</a>)</li>
            <li><strong>CGI worker bridge</strong> &mdash; <code>cgiMode('pool' | 'proc' | 'fcgi')</code> dispatches legacy <code>public/*.php</code> files with true global-scope isolation (<code>'pool'</code> is the default: pre-spawned warm subprocess pool, ~1&ndash;3&thinsp;ms; <code>'proc'</code> is the fresh-process fallback, ~30&ndash;50&thinsp;ms) (<a href="/legacy-apps">/legacy-apps</a>)</li>
            <li><strong>Pluggable Store + Counter backends</strong> &mdash; one API, three backends: Table (single-node, nanoseconds), Redis/Valkey (cross-node + persistence, ~ms), Tiered (L1 Table + L2 Redis with HMAC-signed cross-node L1 invalidation) (<a href="/store">/store</a>)</li>
            <li><strong>Cross-host messaging</strong> &mdash; <code>Store::publish</code> / <code>App::subscribe</code> for fire-and-forget pub/sub, <code>publishReliable</code> / <code>subscribeReliable</code> for Streams-backed at-least-once delivery via consumer groups, <code>WSRouter</code> for cross-server WebSocket routing, first-class WS rooms with federated membership (<a href="/pubsub">/pubsub</a>)</li>
            <li><strong>Stream wrapper for <code>php://input</code></strong> &mdash; legacy <code>file_get_contents('php://input')</code> in JSON APIs just works in long-running workers</li>
            <li><strong>CLI tooling</strong> &mdash; <code>php app.php start/stop/restart/status/logs</code> + daemonization + per-port PID files + log filters (<code>--access</code>, <code>--debug</code>, <code>--server</code>, <code>--zlog</code>)</li>
            <li><strong>Coroutine-safe superglobals &amp; lifecycle presets</strong> &mdash; <code>App::mode(App::MODE_COROUTINE_LEGACY)</code> runs legacy request-style PHP concurrently: <code>$_GET</code> / <code>$_POST</code> / <code>$_SESSION</code>, <code>$GLOBALS</code>, function statics, and <code>require_once</code> state all isolate per coroutine via ext-zealphp; <code>define()</code> isolation is available as a separate opt-in via <code>App::defineIsolation(true)</code>. One-call lifecycle presets: <code>App::mode(App::MODE_COROUTINE)</code> (modern default), <code>App::mode(App::MODE_LEGACY_CGI)</code>, <code>App::mode(App::MODE_MIXED)</code>. (<a href="/coroutines#lifecycle-modes">/coroutines#lifecycle-modes</a>)</li>
          </ul>
        </div>
      </div>
      <p class="why-octane-outro">
        <strong>When raw OpenSwoole is the right choice:</strong> you&rsquo;re building a custom binary-protocol server (your own message broker, database driver, ASR pipeline), you can&rsquo;t install custom PHP extensions (locked-down shared host), or you&rsquo;re explicitly building <em>another</em> framework. For everything else &mdash; HTTP, WebSocket, SSE, REST APIs, web apps with sessions, AI-streaming endpoints &mdash; the harness saves you weeks per project and keeps the migration door open to existing PHP code.
      </p>
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
            <li class="why-fit-item">High-concurrency APIs — workloads where the I/O-concurrency-per-worker model pays off (measure before you commit)</li>
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
            <li class="why-fit-item">Need byte-for-byte Apache/nginx config replacement — ZealPHP covers the common .htaccess/nginx.conf patterns (rewrite, headers, auth, rate limit, MIME, etc.) but is not a drop-in replacement for every directive</li>
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
        Headline numbers: 117k req/s text, 106k JSON, 50k templated with 4 HTTP workers under the full PSR-15 middleware stack — 0 failures across 150k requests. Numbers are from one benchmark setup; real-world performance depends on payload size, I/O, OS limits, and tuning. Reproduce in 60s with <code class="why-bench-inline-code">scripts/bench_vs_express.sh</code>. Full methodology, latency percentiles, caveats: <a href="/performance">/performance</a>.
      </p>
      <div class="bench-method why-bench-method">
        <strong>Method</strong> &nbsp;|&nbsp;
        4 workers, full middleware (CORS + ETag + sessions + PSR-7 routing), <code class="why-bench-inline-code">ab -n 50000 -c 200 -k</code>
        &nbsp;|&nbsp;
        <a href="https://github.com/sibidharan/zealphp/blob/master/PERF.md" target="_blank" rel="noopener">PERF.md</a>
        &nbsp;|&nbsp;
        <a href="https://github.com/sibidharan/zealphp/blob/master/scripts/bench_vs_express.sh" target="_blank" rel="noopener">reproduce locally</a>
      </div>
    </div>

    <div class="why-cta">
      <h2 class="why-cta-title">Ready to try it?</h2>
      <p class="why-cta-desc">From zero to running server in 60 seconds.</p>
      <a href="/getting-started" class="btn btn-primary why-cta-btn">Get started →</a>
    </div>
  </div>
</section>
