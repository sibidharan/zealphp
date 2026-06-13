<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container mig-container">

<h1 class="section-title">Migrate your PHP codebase to async</h1>
<p class="section-desc">
  Bring your existing code along. <code>session_start()</code>, <code>header()</code>,
  <code>$_GET</code>, <code>$_POST</code>, <code>echo</code> — all overridden via ext-zealphp to
  work inside the coroutine runtime, so the migration ladder starts with "drop your
  app in and run <code>php app.php</code>" rather than "rewrite for an event loop."
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. The before/after stack collapse                             -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="mig-h2-mt-lg">From several services to one PHP application server</h2>

<div class="mig-stack-grid">
  <div class="qs-block mig-qs-pad">
    <h3 class="mig-qs-h3">Typical PHP stack today</h3>
    <ul class="mig-list-plain">
      <li>Nginx / Apache (front-end)</li>
      <li>PHP-FPM (cold start every request)</li>
      <li>Redis (sessions, cache, pub/sub)</li>
      <li>Socket.io / Ratchet (WebSocket)</li>
      <li>Supervisor / cron (background jobs)</li>
      <li>SSE proxy or browser polling</li>
    </ul>
    <p class="mig-stack-note">Each tier is mature in isolation, but the per-feature wiring lives across several services and config files.</p>
  </div>
  <div class="qs-block mig-qs-pad-accent">
    <h3 class="mig-qs-h3-accent">Same app on ZealPHP</h3>
    <div class="mig-cmd-center">
      <code class="mig-cmd-pill">php app.php</code>
    </div>
    <ul class="mig-list-plain">
      <li>HTTP + WebSocket + SSE built in</li>
      <li>Coroutine-safe sessions (single-node, file-backed; Redis-backed handler available)</li>
      <li>Shared memory across workers (Store, Counter)</li>
      <li>Task workers (no cron / supervisor)</li>
      <li>Persistent connections, no cold starts</li>
      <li>WordPress via the CGI bridge — <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase</a></li>
    </ul>
    <p class="mig-stack-note">Not every stack fits. Depends on app — see "When migration won't help" below.</p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. The migration ladder                                        -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="mig-h2-mt-xl">The migration ladder — go at your own pace</h2>

<p class="mig-ladder-intro">
  Each rung is functional on its own. With ext-zealphp, coroutines work at
  every rung — the ladder is about which framework features you adopt, not
  about unlocking concurrency. Stop at the rung that gives you enough upside
  without forcing changes you're not ready for.
</p>

<div class="mig-ladder-grid">

<?php
$rungs = [
  [
    'n'    => '0',
    'title' => 'Drop in your entire app, unchanged',
    'code'  => 'App::mode(App::MODE_COROUTINE_LEGACY); $app->setFallback(fn() => App::include(\'/index.php\'));',
    'desc'  => 'Drop your PHP files into <code>public/</code> (the document root — configurable via <code>App::documentRoot()</code>). <code>setFallback()</code> catches every URL and routes it through your existing <code>index.php</code>, just like Apache\'s <code>RewriteRule . /index.php [L]</code>. With ext-zealphp, <code>$_GET</code>/<code>$_SESSION</code> are per-coroutine safe, so coroutines work out of the box. Apps that use <code>define()</code> heavily (WordPress/Drupal) can opt into <code>processIsolation(true)</code> for the CGI bridge. See <a href="/legacy-apps#limitations">documented limits</a> for complex apps.',
    'wins'  => 'Persistent process, no per-request boot. Sub-millisecond TTFB on cached routes. Coroutines + superglobals together — no code rewrites needed.',
    'gives_up' => 'Apps with <code>define()</code> need <code>App::mode(App::MODE_LEGACY_CGI)</code> (the legacy CGI bridge). Dispatch goes through the warm worker pool — <code>cgiMode(\'pool\')</code>, the default, ~1–3 ms warm because the PHP interpreter stays resident — or <code>cgiMode(\'fcgi\')</code> to forward to an external FastCGI / php-fpm pool. Without ext-zealphp, running coroutines with superglobals throws a <code>RuntimeException</code> at boot — install ext-zealphp (<code>pie install zealphp/ext</code>) or use <code>App::mode(App::MODE_COROUTINE)</code> for coroutine concurrency without it.',
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
    'title' => 'Add new endpoints alongside your app',
    'code'  => '$app->route(\'/api/v2/users\', fn($request) => [...]);  // new endpoint, old app untouched',
    'desc'  => 'Your migrated app keeps its existing routes via <code>setFallback()</code>. New features go through framework routes or the file-based <code>api/</code> layer — no need to rewrite existing endpoints.',
    'wins'  => 'Extend your app with new API endpoints, WebSocket handlers, or SSE streams without touching legacy code.',
    'gives_up' => 'Nothing — purely additive. Old routes still flow through the fallback.',
  ],
  [
    'n'    => '3',
    'title' => 'Use framework routes for new features',
    'code'  => '$app->route(\'/ws/chat\', ...); $response->sse(...); yield $html;',
    'desc'  => 'WebSocket, SSE streaming, coroutines — available when you\'re ready, not forced upfront. Mix file-based pages with programmatic routes in the same app.',
    'wins'  => 'Real-time features without spinning up a separate Node/Go service. Stream AI responses, push live updates, run background coroutines.',
    'gives_up' => 'Blocking I/O inside handlers still blocks the worker unless <code>HOOK_ALL</code> is enabled (default in coroutine mode). Use coroutine-aware drivers for DB/HTTP.',
  ],
  [
    'n'    => '4',
    'title' => 'Full coroutine mode',
    'code'  => 'App::mode(\'coroutine\');  // modern default: per-coroutine $g isolation + HOOK_ALL non-blocking I/O',
    'desc'  => '<code>App::mode(\'coroutine\')</code> is the modern, recommended preset — <code>superglobals(false)</code> + per-coroutine <code>$g</code>/<code>RequestContext</code> isolation + <code>HOOK_ALL</code> non-blocking I/O, no extension required. Read input via <code>$g->get</code> / <code>$g->post</code> / <code>$g->session</code>. If you have legacy request-style code that reads real <code>$_GET</code>/<code>$_SESSION</code> and you want it to run under coroutine concurrency, <code>App::mode(\'coroutine-legacy\')</code> is the <strong>experimental</strong> compatibility runtime (requires ext-zealphp; it isolates the seven superglobals (S1), <code>$GLOBALS</code> (S2), function-local statics (S5a), and <code>require_once</code> state (S7) per coroutine). See <a href="/coroutines#lifecycle-modes">lifecycle modes</a> for the full preset matrix.',
    'wins'  => 'Peak throughput. <a href="/performance">117k req/s on 4 workers</a> — Express on the same box does 20k. Thousands of concurrent connections per worker, sub-millisecond TTFB.',
    'gives_up' => 'Blocking I/O outside coroutine-hooked extensions still blocks the worker. Use <code>HOOK_ALL</code> and coroutine-aware drivers. <code>coroutine-legacy</code> is experimental and needs ext-zealphp.',
    'highlight' => true,
  ],
];
foreach ($rungs as $r):
  $rungClass = !empty($r['highlight']) ? 'qs-block mig-rung mig-rung-accent' : 'qs-block mig-rung';
?>
  <div class="<?= $rungClass ?>">
    <div class="mig-rung-row">
      <span class="mig-rung-num"><?= $r['n'] ?></span>
      <div>
        <div class="mig-rung-title"><?= $r['title'] ?></div>
        <code class="mig-rung-code"><?= $r['code'] ?></code>
        <p class="mig-rung-desc"><?= $r['desc'] ?></p>
        <p class="mig-rung-wins"><strong class="mig-rung-wins-label">Wins:</strong> <?= $r['wins'] ?></p>
        <p class="mig-rung-tradeoff"><strong>Trade-off:</strong> <?= $r['gives_up'] ?></p>
      </div>
    </div>
  </div>
<?php endforeach; ?>

</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. How the compatibility bridge actually works                 -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="mig-h2-mt-xl">How the compatibility bridge works</h2>

<p>
  PHP-FPM gives you fresh superglobals (<code>$_GET</code>, <code>$_SESSION</code>),
  fresh <code>header()</code>, fresh <code>session_start()</code> on every request.
  OpenSwoole is one long-running process — those functions would normally collide
  across requests. ZealPHP fixes that via three mechanisms:
</p>

<ul class="mig-bridge-list">
  <li>
    <strong>ext-zealphp function overrides (53 functions).</strong> At server boot, <code>header()</code>,
    <code>setcookie()</code>, <code>http_response_code()</code>, the
    <code>session_*()</code> family, exec functions, and more are replaced with implementations that read/write
    a per-request <code>G::instance()</code> object. Your <code>header('Location: /foo')</code>
    routes to the right OpenSwoole response without you knowing.
  </li>
  <li>
    <strong>Per-coroutine superglobal isolation (S1).</strong>
    ext-zealphp hooks into OpenSwoole's yield/resume/close scheduler callbacks so
    <code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code> are saved and restored
    on every context switch. <code>superglobals(true) + enableCoroutine(true)</code> just works —
    legacy code and coroutine concurrency in the same process.
  </li>
  <li>
    <strong>Stream-wrapper redirection.</strong>
    <code>php://input</code> is rewired to return the current request body,
    not stdin. Legacy code that does <code>file_get_contents('php://input')</code>
    in a JSON API handler works unchanged.
  </li>
  <li>
    <strong>CGI worker bridge (opt-in).</strong> When <code>processIsolation(true)</code>
    is set (<code>App::mode(App::MODE_LEGACY_CGI)</code>), each public <code>.php</code> file is
    dispatched through a configurable isolation backend — full process isolation for
    <code>define()</code>-heavy apps like WordPress/Drupal. Four strategies are available:
    <code>cgiMode('pool')</code> (default) keeps a pre-spawned warm worker pool resident in
    memory, ~1–3 ms warm dispatch; <code>cgiMode('proc')</code> spawns a new subprocess per
    request via <code>proc_open</code>, ~30–50 ms cold start; <code>cgiMode('fork')</code>
    is the <em>experimental</em> Apache MPM prefork runner — a long-lived fork-master forks
    a fresh child per request (~1 ms fork cost), giving true fresh-process correctness
    for unmodified WordPress without the <code>proc_open</code> overhead (requires
    <code>pcntl</code> + <code>posix</code>; set via <code>App::cgiMode('fork')</code> — no
    <code>App::mode()</code> preset); <code>cgiMode('fcgi')</code> forwards to an external
    FastCGI / php-fpm pool. This is opt-in, not the default — most apps don't need it.
  </li>
</ul>

<p class="mig-p-mt-sm">
  Net effect — at every rung, your code can't tell it's running on OpenSwoole.
  ext-zealphp makes <code>$_GET</code>/<code>$_SESSION</code> coroutine-safe from day one;
  you opt into higher rungs when you want framework features like WebSocket and SSE.
</p>

<h2 class="mig-h2-mt-xl">The drop-in PHP-FPM equivalent: <code>App::mode(App::MODE_MIXED)</code></h2>
<p>
  If you want the closest apples-to-apples swap for a PHP-FPM deployment,
  <code>App::mode(App::MODE_MIXED)</code> is it. It gives you native <code>$_GET</code>,
  <code>$_POST</code>, <code>$_SESSION</code> populated per request, one request at a
  time per warm worker — the exact execution model PHP-FPM / mod_php use — but
  <strong>in-process</strong>, with no FastCGI socket hop and no separate web server
  to bridge HTTP↔FastCGI. The HTTP server is built in. The worker is already warm, so
  there's no per-request interpreter startup either.
</p>
<p>
  Under the hood it expands to <code>superglobals(true) + processIsolation(false)</code>
  with the session lifecycle handled per request — but the preset is the recommended
  surface. Because each worker handles one request at a time, there's no coroutine race
  on the superglobals to worry about: it's the same shared-nothing-per-request mental
  model you already have, minus the FastCGI plumbing. This is the drop-in FPM
  replacement story. When you're ready for concurrency, move up to
  <code>App::mode(App::MODE_COROUTINE)</code>.
</p>

<h2 class="mig-h2-mt-xl">Apache+mod_php parity reference</h2>
<p>What ZealPHP emulates so legacy apps run unchanged. Most of this is invisible — these rows exist to answer "does X work?" without a code-dive.</p>

<h3 class="mig-h3-sub">Function overrides (via ext-zealphp)</h3>
<table class="ztable">
  <tr><th>Apache+mod_php function</th><th>ZealPHP behavior</th></tr>
  <tr><td><code>header()</code>, <code>header_remove()</code>, <code>headers_list()</code>, <code>headers_sent()</code></td><td>Per-request via <code>$response-&gt;headersList</code> on the Response wrapper (<code>$g-&gt;zealphp_response</code>). Supports <code>header("HTTP/1.1 404 Not Found")</code> status-line form and the optional <code>$http_response_code</code> param. CRLF/NUL in values rejected to prevent response splitting.</td></tr>
  <tr><td><code>setcookie()</code>, <code>setrawcookie()</code></td><td>Per-request via <code>$response-&gt;cookiesList</code> / <code>rawCookiesList</code>. <code>setrawcookie</code> preserves the raw value (no urlencoding). Cookie name char-class matches PHP native (rejects <code>=,; \t\r\n\013\014\0</code>).</td></tr>
  <tr><td><code>http_response_code()</code></td><td>Per-request via <code>G-&gt;status</code>.</td></tr>
  <tr><td><code>flush()</code>, <code>ob_flush()</code>, <code>ob_end_flush()</code></td><td>Switch the response into streaming mode — buffer pushed to OpenSwoole's <code>$response-&gt;write()</code>, flips <code>G-&gt;_streaming = true</code>.</td></tr>
  <tr><td><code>apache_request_headers()</code>, <code>getallheaders()</code></td><td>Return canonical (hyphen-capitalized) request headers from the OpenSwoole request.</td></tr>
  <tr><td><code>apache_response_headers()</code></td><td>Returns currently-set outbound headers.</td></tr>
  <tr><td><code>apache_setenv()</code>, <code>apache_getenv()</code>, <code>apache_note()</code></td><td>Per-request scratch tables in <code>G-&gt;apacheContext</code> (<code>ZealPHP\Legacy\ApacheContext</code>, lazy-allocated).</td></tr>
  <tr><td><code>virtual()</code></td><td>Returns <code>false</code> — internal subrequests aren't supported in this model.</td></tr>
  <tr><td><code>set_time_limit()</code></td><td>No-op success. OpenSwoole owns the worker/coroutine timeout.</td></tr>
  <tr><td><code>ignore_user_abort()</code>, <code>connection_status()</code>, <code>connection_aborted()</code></td><td>Per-request; reads <code>$response-&gt;isWritable()</code> for connection state.</td></tr>
  <tr><td><code>is_uploaded_file()</code>, <code>move_uploaded_file()</code></td><td>Whitelist of <code>$_FILES['*']['tmp_name']</code> — same security guarantees as mod_php.</td></tr>
  <tr><td><code>session_*()</code> (18 functions)</td><td>Coroutine-safe session lifecycle via <code>CoSessionManager</code>; files in <code>/var/lib/php/sessions</code>.</td></tr>
  <tr><td><code>set_error_handler()</code>, <code>set_exception_handler()</code>, <code>register_shutdown_function()</code>, <code>error_reporting()</code></td><td>Per-coroutine via <code>G</code> stacks. A native dispatcher installed at boot delegates to the active coroutine's handler stack — isolated despite PHP's process-global semantics. See <a href="/responses">Responses</a>.</td></tr>
</table>

<h3 class="mig-h3-sub"><code>public/</code> routing (DocumentRoot behavior)</h3>
<table class="ztable">
  <tr><th>Apache directive</th><th>ZealPHP</th></tr>
  <tr><td><code>DirectoryIndex index.php index.html index.htm</code></td><td>Same fallback order via <code>App::$directory_index</code>. HTML/HTM served via <code>$response-&gt;sendFile()</code> with ETag + Range.</td></tr>
  <tr><td><code>DirectorySlash On</code></td><td><code>/foo</code> → 301 <code>/foo/</code> when <code>foo</code> is a directory.</td></tr>
  <tr><td><code>AcceptPathInfo On</code></td><td><code>/script.php/extra</code> exposes <code>PATH_INFO=/extra</code>; rewrites <code>REQUEST_URI</code>.</td></tr>
  <tr><td><code>&lt;FilesMatch "^\.&gt;"</code> deny</td><td>Dotfile URLs return 403 (<code>.well-known/</code> allow-listed per RFC 8615).</td></tr>
  <tr><td><code>RewriteRule . /index.php [L]</code> <small>(catch-all, internal)</small></td><td><code>App::setFallback(fn() =&gt; App::include('/index.php'))</code>. URL stays whatever the user typed; body, status, headers, Generator return all preserved (see the <a href="/responses#return-contract">universal return contract</a>).</td></tr>
  <tr><td><code>RewriteRule ^old$ /new [L]</code> <small>(specific, internal — no <code>[R]</code>)</small></td><td><code>$app-&gt;route('/old', fn() =&gt; App::include('/new.php'))</code>. Same in-process include; user still sees <code>/old</code> in the URL bar. <strong>Don't use <code>header('Location:&nbsp;…')</code> here</strong> — that would expose the internal target.</td></tr>
  <tr><td><code>RewriteRule ^old$ /new [R=301,L]</code> <small>(external)</small></td><td><code>$app-&gt;route('/old', fn($response) =&gt; $response-&gt;redirect('/new', 301))</code>. Browser does a fresh request; URL bar changes. Use <code>R=302</code> for temporary.</td></tr>
  <tr><td><code>ErrorDocument 404 /custom.php</code></td><td><code>App::setErrorHandler(404, $cb)</code>. Catch-all variant: <code>setErrorHandler($cb)</code>. Handlers fire for every 4xx/5xx site in the framework.</td></tr>
  <tr><td><code>FileETag</code> / conditional GET</td><td><code>$response-&gt;sendFile()</code> emits weak ETag + <code>Last-Modified</code>; evaluates all four conditional headers in <code>ap_meets_conditions()</code> order — <code>If-Match</code> / <code>If-Unmodified-Since</code> → 412, <code>If-None-Match</code> / <code>If-Modified-Since</code> → 304.</td></tr>
</table>

<p class="mig-p-mt">Deeper detail (boot-order tricks, recursion guards, per-coroutine isolation mechanism, source-line references): <a href="/http#parity">Apache parity</a> and <a href="https://github.com/sibidharan/zealphp/blob/master/docs/error-handling.md"><code>docs/error-handling.md</code></a>.</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. When migration is a good fit (and when it isn't)            -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="mig-h2-mt-xl">When migration is a good fit</h2>

<div class="mig-stack-grid">
  <div class="qs-block mig-qs-pad-accent">
    <h3 class="mig-fit-h3-accent">Good fit</h3>
    <ul class="mig-list-plain">
      <li>✓ You're already on PHP and the team knows it</li>
      <li>✓ You want WebSocket / SSE / streaming without a separate Node service</li>
      <li>✓ You have I/O-bound endpoints (DB, HTTP fetches) — coroutines fan them out</li>
      <li>✓ You hit PHP-FPM bottlenecks (request rate, cold start latency, FPM pool tuning)</li>
      <li>✓ You want cross-worker pub/sub without Redis on one node; cross-node deploys flip to Redis with one line</li>
      <li>✓ You want to keep <code>session_start()</code> + <code>header()</code> + <code>echo</code> — not rewrite for an event loop</li>
    </ul>
  </div>
  <div class="qs-block mig-qs-pad">
    <h3 class="mig-fit-h3">Probably wrong fit</h3>
    <ul class="mig-list-plain">
      <li>✗ Workload is purely CPU-bound — coroutines don't help, just buy more cores</li>
      <li>✗ App relies on extensions OpenSwoole's runtime hooks don't cover (rare, but exists)</li>
      <li>✗ You'd accept a full rewrite anyway — Go/Rust/Elixir give bigger ceilings if you can pay the cost</li>
      <li>✗ Hard requirement for shared-nothing per-request memory (PHP-FPM's strongest guarantee)</li>
      <li>✗ Production team can't accept alpha (v0.3.x) stability — wait for v1.0</li>
      <li>✗ You need byte-for-byte Apache/nginx config replacement — ZealPHP covers the common .htaccess / nginx.conf patterns but isn't a drop-in for every directive</li>
    </ul>
  </div>
</div>

</div>
</section>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4.5. Live config converter                                     -->
<!-- ────────────────────────────────────────────────────────────── -->

<section class="section">
<div class="container mig-container">
<h2 class="section-title">Convert your existing config</h2>
<p class="section-desc">Paste your Apache <code>.htaccess</code> or nginx config — AI converts it to a working <code>app.php</code> in real-time. The same engine that bridges the migration ladder above.</p>

<div class="converter-split mig-converter-split">
  <div class="mig-converter-left">
    <div class="mig-converter-bar">
      <span>Apache / nginx config</span>
      <select id="convert-preset" class="mig-converter-preset">
        <option value="">— paste your own —</option>
        <option value="wordpress">WordPress .htaccess</option>
        <option value="nginx-cms">nginx CMS</option>
        <option value="redirects">Redirect rules</option>
      </select>
    </div>
    <textarea id="convert-input" class="mig-converter-input" placeholder="Paste your .htaccess or nginx server { } config here..."></textarea>
    <div class="mig-converter-actions">
      <button id="convert-btn" onclick="runConvert()" class="mig-converter-btn">Convert →</button>
      <span id="convert-status" class="mig-converter-status"></span>
    </div>
  </div>
  <div>
    <div class="mig-converter-bar">
      <span>ZealPHP app.php</span>
      <button onclick="copyOutput()" class="mig-converter-copy">Copy</button>
    </div>
    <pre id="convert-output" class="mig-converter-output"><span class="mig-converter-placeholder">// Output will appear here...</span></pre>
    <div class="mig-converter-foot">
      Rate limit: 5 conversions per 10 minutes · Powered by gpt-5.4-mini · <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/config_converter.py" target="_blank">Source</a> · <a href="/legacy-apps">More on legacy apps →</a>
    </div>
  </div>
</div>

</div>
</section>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. Closing CTAs                                                -->
<!-- ────────────────────────────────────────────────────────────── -->

<section class="section section-dark">
<div class="container mig-container">

<div class="mig-cta-center">
  <a href="/getting-started" class="btn btn-primary">Start the migration →</a>
  <a href="/legacy-apps" class="btn btn-outline mig-cta-spaced">Legacy apps (WordPress) →</a>
  <a href="/why-zealphp" class="btn btn-outline mig-cta-spaced">Why ZealPHP →</a>
</div>

<p class="mig-closing-note">
  Performance: <a href="/performance">117K req/s text · 106K JSON · 50K templated</a> (coroutine mode, available at every rung with ext-zealphp).<br>
  WordPress + custom CMS migrations: see the <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank" rel="noopener">showcase repo</a>.
</p>

</div>
</section>
