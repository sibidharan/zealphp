<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container vsfpm-container">

<h1 class="section-title">vs. PHP-FPM</h1>
<p class="section-desc">ZealPHP replaces Apache + PHP-FPM with a single OpenSwoole process. Per-request, here's what that actually costs you.</p>

<div class="bench-method vsfpm-tldr">
  <strong>TL;DR</strong> &nbsp;|&nbsp;
  The apples-to-apples comparison is <strong>Mixed-mode</strong> — ZealPHP with process isolation OFF (<code>superglobals(true) + processIsolation(false) + enableCoroutine(false)</code>). That's PHP-FPM's <em>exact</em> execution model: one request at a time per warm worker, native <code>$_GET</code>/<code>$_POST</code>/<code>$_SESSION</code>, in-process — minus the FastCGI socket hop and minus the separate web server (the HTTP server is built in). Same model, fewer moving parts, faster. <strong>Coroutine mode</strong> (the default) goes further — sub-millisecond, thousands of concurrent connections — but it's a <em>different</em> execution model, so it's the bonus path, not the FPM comparison. The <strong>legacy CGI bridge</strong> (<code>processIsolation(true)</code>) exists only for unmodified WordPress/Drupal and pays a ~30–50 ms <code>proc_open</code> cost today; the v0.3.0 persistent worker pool brings it to FPM parity.
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. The two architectures, side by side                         -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">The two architectures</h2>

<div class="vsfpm-arch-grid">

  <div class="qs-block vsfpm-arch-card">
    <h3 class="vsfpm-arch-title-muted">Apache + PHP-FPM</h3>
    <pre class="vsfpm-arch-pre"><code>browser
  ↓  TCP
Apache (or nginx)
  ↓  FastCGI / Unix socket
PHP-FPM pool master
  ↓  pick idle worker
PHP-FPM worker process
  ↓  load opcache, restart $_GET/$_POST
your script.php
  ↓  return body
PHP-FPM worker (idle again)
  ↓  back via FCGI
Apache → browser</code></pre>
    <p class="vsfpm-arch-note-muted">Two processes per request (httpd + fpm worker), at least one socket hop, FCGI framing on every body chunk.</p>
  </div>

  <div class="qs-block vsfpm-arch-card-accent">
    <h3 class="vsfpm-arch-title-accent">ZealPHP — Mixed-mode (FPM-equivalent)</h3>
    <pre class="vsfpm-arch-pre"><code>browser
  ↓  TCP
OpenSwoole HTTP server (master + workers)
  ↓  pick idle worker (one request at a time)
ResponseMiddleware (in-process, no fork)
  ↓  $_GET/$_POST/$_SESSION populated, warm interpreter
your script.php  (in-process include)
  ↓  return body
OpenSwoole writes response on the same socket
  ↓
browser</code></pre>
    <p class="vsfpm-arch-note-light">Same execution model as FPM — sequential per warm worker, native superglobals — but <strong>no FastCGI socket hop and no separate web server</strong>. The interpreter is pre-loaded; nothing forks per request. Flip on <code>enableCoroutine(true)</code> (the default) and the same worker handles thousands of concurrent connections — the bonus fast path, covered below.</p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. Cost matrix                                                 -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">Per-request cost matrix</h2>

<p class="vsfpm-lead">Three stacks, three workloads. Costs are per-request, with the request body kept small so we're measuring the framework, not the bandwidth.</p>

<p class="vsfpm-lead-muted">The <strong>Mixed-mode</strong> column is the apples-to-apples FPM comparison (same sequential execution model). Coroutine mode is the different-model bonus path.</p>

<table class="ztable">
  <tr>
    <th class="vsfpm-th-left">Workload</th>
    <th class="vsfpm-right">Apache + PHP-FPM</th>
    <th class="vsfpm-right">ZealPHP Mixed-mode<br><small>(FPM-equivalent)</small></th>
    <th class="vsfpm-right">ZealPHP coroutine<br><small>(bonus path)</small></th>
    <th class="vsfpm-right">ZealPHP legacy CGI</th>
  </tr>
  <tr>
    <td>JSON endpoint (no DB)</td>
    <td class="vsfpm-right">~1–3 ms (FCGI hop)</td>
    <td class="vsfpm-cell-accent">~1 ms (in-process, no hop)</td>
    <td class="vsfpm-right">&lt; 0.1 ms (coroutine)</td>
    <td class="vsfpm-right">~30–50 ms (proc_open)</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td>Static template render</td>
    <td class="vsfpm-right">~1–3 ms + opcache</td>
    <td class="vsfpm-cell-accent">~1 ms (warm interpreter)</td>
    <td class="vsfpm-right">~0.1 ms</td>
    <td class="vsfpm-right">~30–50 ms (proc_open)</td>
  </tr>
  <tr>
    <td>Legacy app (native <code>$_SESSION</code>)</td>
    <td class="vsfpm-right">~40–80 ms (mod_php)</td>
    <td class="vsfpm-cell-accent">same, in-process, no fork</td>
    <td class="vsfpm-right">use <code>$g-&gt;X</code> (no superglobals)</td>
    <td class="vsfpm-right">~40–80 ms + ~30 ms bridge</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td>SSE stream (long-lived)</td>
    <td class="vsfpm-right">1 worker pinned</td>
    <td class="vsfpm-right">1 worker pinned (scheduler off)</td>
    <td class="vsfpm-cell-accent">1 coroutine — worker free</td>
    <td class="vsfpm-right">1 child process pinned</td>
  </tr>
  <tr>
    <td>WebSocket connection</td>
    <td class="vsfpm-right">Not supported</td>
    <td class="vsfpm-cell-accent">Native — same process</td>
    <td class="vsfpm-cell-accent">Native — same process</td>
    <td class="vsfpm-right">Native (handler in coroutine)</td>
  </tr>
</table>

<p class="vsfpm-note-muted">
  Note the honest trade-off: in Mixed-mode the coroutine scheduler is off, so a long-lived SSE stream pins its worker exactly like FPM. That's the one place coroutine mode clearly wins — and why it's worth flipping on for new code that does streaming or concurrent I/O.
</p>

<p class="vsfpm-note-muted">
  Ranges, not point measurements — actual numbers depend on kernel, CPU, opcache state, and which exact FPM tuning you've done. The bench script at <code>scripts/bench_vs_fpm.sh</code> runs the JSON workload on the local box; reproduce before quoting.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. The CGI bridge — why it exists, what it costs               -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">Why Apache/FPM don't pay this cost (and how the CGI bridge will catch up)</h2>

<p class="vsfpm-prose">
  The first version of this page implied <strong>"~30–50 ms is the same order of magnitude as FPM"</strong>. That was wrong, and worth correcting honestly. FPM's per-request cost is closer to <strong>~1–3 ms</strong> — an order of magnitude less than our current CGI bridge. The reason isn't that FPM is doing anything magical; it's that Apache + FPM are <em>not spawning a PHP interpreter per request</em> — and neither is ZealPHP's in-process <strong>Mixed-mode</strong>. Only the opt-in CGI bridge does. Here's the full startup-cost spectrum:
</p>

<table class="ztable">
  <tr>
    <th class="vsfpm-th-left">Stack</th>
    <th class="vsfpm-th-left">PHP interpreter lifecycle</th>
    <th class="vsfpm-right">Per-request startup cost</th>
  </tr>
  <tr>
    <td><strong>Apache + mod_php</strong></td>
    <td>PHP loaded INTO the Apache worker as a shared library. Interpreter already in memory when request arrives.</td>
    <td class="vsfpm-cell-amber">~0 ms (no spawn)</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td><strong>nginx + PHP-FPM</strong></td>
    <td>Pool of pre-forked, long-lived PHP workers. Each handles M requests then recycles (<code>pm.max_requests</code>). nginx hands the request to an idle worker via FastCGI socket.</td>
    <td class="vsfpm-cell-amber">~1–3 ms (FCGI handshake)</td>
  </tr>
  <tr>
    <td><strong>ZealPHP Mixed-mode</strong> — <code>processIsolation(false)</code></td>
    <td>In-process include into the warm, long-lived OpenSwoole worker. No fork, no exec — the interpreter is already in memory, exactly like mod_php. This is the FPM-equivalent execution model with native <code>$_SESSION</code> (v0.2.27).</td>
    <td class="vsfpm-cell-amber">~0 ms (no spawn)</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td><strong>ZealPHP CGI bridge — <code>cgiMode('proc')</code></strong> (default)</td>
    <td><code>proc_open</code> spawns a <strong>fresh PHP interpreter per request</strong>. Kernel fork + exec + PHP startup + opcache check + autoload + include + execute.</td>
    <td class="vsfpm-cell-danger">~30–50 ms</td>
  </tr>
  <tr>
    <td><strong>ZealPHP CGI bridge — <code>cgiMode('fork')</code></strong> (v0.2.29)</td>
    <td><code>OpenSwoole\Process</code> forks the <strong>already-booted worker</strong> (copy-on-write). No exec, no PHP startup, no autoload — the interpreter + classmap + opcache are inherited. Runs in function scope.</td>
    <td class="vsfpm-cell-amber">~5 ms (≈5× faster)</td>
  </tr>
</table>

<p class="vsfpm-prose-mt">
  So the bridge cost isn't because legacy code is slow — it's because the default <code>'proc'</code> mode pays PHP's startup cost on every request. Apache and FPM amortise that startup by keeping the interpreter alive. <strong>Fork mode does most of that today</strong> (warm fork, no re-exec); a fully warm + global-scope pool is the v0.3.0 finish line.
</p>

<div class="vsfpm-callout-amber">
  <strong class="vsfpm-callout-amber-title">Available now — <code>cgiMode('fork')</code> (v0.2.29)</strong>
  <p class="vsfpm-callout-amber-body">
    <code>App::cgiMode('fork')</code> forks the warm worker per request via <code>OpenSwoole\Process</code> instead of <code>proc_open</code>-ing a cold PHP. Measured ~5× faster (814 vs 160 req/s on the trivial probe below). It keeps full per-request isolation — <code>define()</code>, classes, <code>ini_set()</code>, and even <code>die()</code>/<code>exit()</code> die with the child, never the worker. <strong>Caveat:</strong> the file runs in the fork closure's function scope, so a bare top-level <code>$x</code> isn't visible via <code>global $x</code> — unmodified WordPress/Drupal (<code>global $wpdb;</code>) still need <code>'proc'</code>. Fork mode targets "modernised legacy" apps that read request state through superglobals.
  </p>
  <p class="vsfpm-callout-amber-foot">
    <strong class="vsfpm-foot-light">Roadmap — built-in CGI worker pool (v0.3.0):</strong> a pool of <em>persistent</em> global-scope PHP subprocesses spawned at server start (warm interpreter, reset between requests, recycle after N like FPM <code>pm.max_requests</code>). That gets both warmth AND true global scope — ~1–3 ms <em>and</em> unmodified WordPress. Tracking: <a href="https://github.com/sibidharan/zealphp/issues" target="_blank">github.com/sibidharan/zealphp/issues</a>.
  </p>
</div>

<h2 class="vsfpm-h2">Until v0.3.0 — what the CGI bridge buys you today</h2>

<p class="vsfpm-prose">
  Even with the per-request startup hit, the bridge has an honest place: it exists so <strong>unmodified WordPress, Drupal, and other <code>define()</code>-heavy code that assumes a fresh process per request just works.</strong> Set <code>App::superglobals(true)</code>, ZealPHP turns OFF the coroutine scheduler and switches <code>App::include()</code> to dispatch each legacy <code>public/*.php</code> file through a child process — by default via <code>proc_open</code> (<a href="https://github.com/sibidharan/zealphp/blob/master/src/cgi_worker.php"><code>src/cgi_worker.php</code></a>, true global scope, ~30–50 ms), or via <code>cgiMode('fork')</code> (warm fork, ~5 ms, function scope). Apache prefork MPM semantics either way.
</p>

<p class="vsfpm-prose-mt-07">
  The trade-off is honest: <strong>you pay 30–50 ms only on the legacy file</strong>. Everything else stays in-process at sub-millisecond cost:
</p>

<ul class="vsfpm-list">
  <li>Routes you define via <code>$app-&gt;route()</code> run in-process — sub-millisecond, no bridge.</li>
  <li>ZealAPI endpoints (<code>api/*.php</code>) run in-process — no bridge.</li>
  <li>Middleware (CORS, ETag, sessions, rate limit) runs in-process — no bridge.</li>
  <li>WebSocket, SSE, streaming, timers — all in-process — no bridge.</li>
  <li>You can opt individual routes back into coroutine mode via the <a href="/coroutines#lifecycle-modes">lifecycle setters</a> (v0.2.23).</li>
</ul>

<p class="vsfpm-prose-mt-07">
  In other words: <strong>you pay the bridge cost only on the legacy files</strong>. Everything new you write runs on the fast path. With FPM, every request — new code, old code, an API health check, a static asset proxy — pays the FCGI hop.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3b. The simplest fix: don't use the bridge — be FPM             -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">The simplest fix today: Mixed-mode = an FPM pool, minus the operations</h2>

<p class="vsfpm-prose">
  Here's the part most people miss: <strong>if your legacy app doesn't actually need fresh-process-per-request isolation, you don't need the CGI bridge at all.</strong> Turn off process isolation and run the workers synchronously — ZealPHP becomes a PHP-FPM pool that happens to have an HTTP server built in. No <code>proc_open</code>, no FastCGI socket, no nginx in front. Zero bridge cost, available now (no waiting for the v0.3.0 worker pool).
</p>

<?php App::render('/components/_code', [
  'lang' => 'php',
  'code' => '<?php
// app.php — ZealPHP configured as a PHP-FPM-equivalent pool
use ZealPHP\App;

App::superglobals(true);      // $_GET / $_POST / $_SESSION populated (v0.2.27)
App::processIsolation(false); // no proc_open per include — saves ~30-50ms/req
App::enableCoroutine(false);  // one request at a time per worker (FPM semantics)
App::hookAll(0);              // blocking I/O stays blocking (no scheduler)

$app = App::init(\'0.0.0.0\', 8080);
$app->run();

// Run with a worker count, exactly like pm.max_children:
//   ZEALPHP_WORKERS=32 php app.php
',
]); ?>

<table class="ztable">
  <tr><th>PHP-FPM</th><th>ZealPHP Mixed-mode</th></tr>
  <tr><td><code>pm.max_children = 32</code></td><td><code>ZEALPHP_WORKERS=32</code></td></tr>
  <tr class="vsfpm-row-tint"><td>Worker handles one request at a time</td><td><code>enableCoroutine(false)</code> — scheduler off</td></tr>
  <tr><td>Worker process stays warm between requests</td><td>OpenSwoole workers are long-lived</td></tr>
  <tr class="vsfpm-row-tint"><td>No PHP fork per request (interpreter pre-loaded)</td><td><code>processIsolation(false)</code> — in-process include, <strong>~0 ms</strong></td></tr>
  <tr><td><code>pm.max_requests = 500</code> (recycle for hygiene)</td><td>OpenSwoole <code>max_request</code> setting</td></tr>
  <tr class="vsfpm-row-tint"><td><code>$_GET</code> / <code>$_SESSION</code> native</td><td>Populated per request (v0.2.27)</td></tr>
  <tr><td>Needs nginx/Apache in front for HTTP</td><td class="vsfpm-cell-accent">HTTP server built in — no front proxy needed</td></tr>
</table>

<p class="vsfpm-prose-mt">
  This is the apples-to-apples FPM comparison and ZealPHP wins it cleanly: <strong>same per-request execution model, same worker semantics, but no FastCGI socket hop and no separate web server to run.</strong> The 30–50 ms CGI bridge cost was never mandatory — it's the price of <em>true process isolation per request</em>, which only WordPress/Drupal-class apps with <code>define()</code> collisions actually need.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3b'. Alternative path: front an existing FPM pool                -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">Or: keep your FPM pool — let ZealPHP front it</h2>

<p class="vsfpm-prose">
  If you've already invested in a tuned php-fpm pool (sized for your workload, hooked into your observability stack) and don't want to retire it, ZealPHP can speak FastCGI to it directly. Set <code>App::cgiMode('fcgi')</code> + <code>App::fcgiAddress(...)</code>, and every <code>public/*.php</code> file gets forwarded to the upstream pool over the same wire protocol nginx's <code>fastcgi_pass</code> and Apache's <code>mod_proxy_fcgi</code> use. ZealPHP becomes the HTTP / WebSocket / coroutine layer; php-fpm remains the PHP runtime.
</p>

<?php App::render('/components/_code', [
  'lang' => 'php',
  'code' => '<?php
// app.php — front an existing php-fpm pool instead of running PHP in-process
use ZealPHP\App;

App::superglobals(true);
App::processIsolation(true);
App::cgiMode(\'fcgi\');                   // \'proc\' (default) | \'fork\' | \'fcgi\'
App::fcgiAddress(\'127.0.0.1:9000\');     // or \'unix:/run/php/php-fpm.sock\'

$app = App::init(\'0.0.0.0\', 8080);
$app->run();
',
]); ?>

<p class="vsfpm-prose-mt">
  Throughput in this mode equals whatever your FPM pool delivers minus one local socket hop — we don't run PHP at all. That's why the measured table below intentionally <em>omits</em> the <code>'fcgi'</code> row: the answer is "ask your FPM pool" and depends on <code>pm.max_children</code>, the file under load, and Unix-socket vs TCP. Full walkthrough + per-extension form (mix <code>.py</code> + <code>.pl</code> backends in the same app) lives at <a href="/legacy-apps#cgi-mode-fcgi" class="vsfpm-link-accent">/legacy-apps#cgi-mode-fcgi</a>.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3c. Measured: process isolation on vs off                       -->
<!-- ────────────────────────────────────────────────────────────── -->

<p class="vsfpm-prose-mt">
  How much does that <code>proc_open</code> fork actually cost? On this box, turning process isolation off takes the same legacy file from <strong>160 req/s to 21,964 req/s</strong>, with nothing else changed — and if you do need isolation, <code>cgiMode('fork')</code> recovers ~5× of it (160 → 814 req/s) by forking the warm worker instead of cold-starting PHP. The full measured breakdown (Apache mod_php, ZealPHP coroutine, Mixed-mode, fork CGI, and proc CGI, all on one machine) is in the <a href="#measured-four-ways">measured table below</a>.
</p>

<div class="vsfpm-callout-slate">
  <strong class="vsfpm-callout-slate-title">When you still need the CGI bridge</strong>
  <p class="vsfpm-callout-slate-body">
    Mixed-mode reuses the worker's PHP heap across requests, so <code>define()</code>, declared classes, and <code>ini_set()</code> changes from request N persist into request N+1 on the same worker. For unmodified WordPress / Drupal that re-<code>define()</code> constants every request, you need <code>processIsolation(true)</code> (the CGI bridge) so each request gets a clean interpreter — OR lean on OpenSwoole <code>max_request</code> recycling + <a href="/middleware">IniIsolationMiddleware</a>. (SNA Labs took a different route — full coroutine mode on dev with an async Rust MongoDB driver; see the <a href="/case-studies/sna-labs">case study</a>.)
  </p>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. PHP-FPM worker count vs ZealPHP worker count                -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">"PHP-FPM has N workers — does ZealPHP need more?"</h2>

<p class="vsfpm-prose">
  No. Workers mean different things in the two architectures.
</p>

<table class="ztable">
  <tr>
    <th>Concept</th>
    <th>PHP-FPM</th>
    <th>ZealPHP</th>
  </tr>
  <tr>
    <td>What a "worker" handles</td>
    <td>One request at a time, start to finish</td>
    <td>Many concurrent coroutines (hundreds), interleaved</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td>Right worker count</td>
    <td>~CPU cores × 2, or higher for I/O-heavy apps to avoid blocking</td>
    <td>~CPU cores. I/O concurrency comes from coroutines, not extra workers</td>
  </tr>
  <tr>
    <td>What happens on slow I/O</td>
    <td>Worker blocked until I/O returns — concurrent requests queue</td>
    <td>Coroutine yields — worker handles the next request immediately</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td>Memory cost per worker</td>
    <td>Full PHP runtime per worker (~50–200 MB each)</td>
    <td>Full PHP runtime per worker — but you need fewer workers</td>
  </tr>
</table>

<p class="vsfpm-prose-mt-08">
  The intuition: an FPM pool of 64 workers can handle 64 concurrent requests. A ZealPHP setup with 8 workers and the coroutine scheduler can handle thousands of concurrent connections — most of them yielding on I/O at any given moment. The benchmark page (<a href="/performance">/performance</a>) confirms it: 4 workers, 116k req/s through the full middleware stack.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. When FPM is the right choice                                -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">When PHP-FPM is still the right answer</h2>

<p class="vsfpm-prose">
  Be honest about it — there are cases where Apache + PHP-FPM is the safer pick today:
</p>

<ul class="vsfpm-list">
  <li>You're running off-the-shelf code that you can't modify and don't want to touch — a Drupal shop with 40 contrib modules, a WordPress site with 60 plugins. The CGI bridge runs them, but FPM is the path of least surprise.</li>
  <li>Your only PHP extensions are blocking ones with no coroutine equivalent. PDO is the canonical example (see the <a href="/case-studies/sna-labs#phase-4-mongo">SNA Labs case study</a> for how we hit this with MongoDB and built a replacement). If every database call blocks, the coroutine scheduler can't help you — and you might as well run FPM.</li>
  <li>You're allergic to <code>uopz</code>. ZealPHP requires it; FPM doesn't. If your hosting environment forbids it, FPM wins by default.</li>
</ul>

<p class="vsfpm-prose-mt-07">
  ZealPHP's pitch is not "always faster than FPM." It's: <strong>same compatibility, plus a fast path for new code, plus WebSocket and SSE and timers without leaving PHP.</strong>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 6. Production proof                                            -->
<!-- ────────────────────────────────────────────────────────────── -->

<div class="vsfpm-proof">
  <h3 class="vsfpm-proof-title">Production proof point</h3>
  <p class="vsfpm-proof-body">
    Selfmade Ninja Labs runs the same PHP codebase on both Apache (mod_php) and ZealPHP from a single Docker container, sharing one volume, one MongoDB instance, one Redis. Same source tree. 41 migration commits, 806 files touched, zero downtime, including a custom Rust MongoDB extension to replace the blocking PECL driver. <a href="/case-studies/sna-labs" class="vsfpm-link-accent-strong">Read the case study →</a>
  </p>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 7. Reproduce locally                                           -->
<!-- ────────────────────────────────────────────────────────────── -->

<!-- SYNC: this table mirrors /performance "Legacy-file serving". Any change
     to the numbers must update BOTH in lock-step. -->
<h2 id="measured-four-ways" class="vsfpm-h2">Measured: five ways to serve the same legacy file</h2>

<p class="vsfpm-prose">
  These are <strong>real numbers</strong>, not illustrative. Same machine, same trivial <code>public/probe.php</code> (<code>echo "ok"</code>), 4 workers each, <code>ab -n 3000 -c 20</code>. The only thing that changes is which server / lifecycle mode serves the file. This is specifically the <em>legacy-file-serving</em> path (implicit <code>public/*.php</code> routing) — the workload that matters when you're migrating an existing app, not ZealPHP's native-route fast path.
</p>

<table class="ztable">
  <tr>
    <th class="vsfpm-th-left">Stack</th>
    <th class="vsfpm-th-left">How the file runs</th>
    <th class="vsfpm-right">req/s</th>
    <th class="vsfpm-right">ms/req</th>
  </tr>
  <tr>
    <td><strong>Apache + mod_php</strong></td>
    <td>Interpreter loaded in-process, warm</td>
    <td class="vsfpm-cell-amber-bold">40,861</td>
    <td class="vsfpm-right">0.49</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td><strong>ZealPHP coroutine</strong> (default)</td>
    <td>In-process include, coroutine-per-request</td>
    <td class="vsfpm-cell-accent">34,159</td>
    <td class="vsfpm-right">0.59</td>
  </tr>
  <tr>
    <td><strong>ZealPHP Mixed-mode</strong><br><small><code>processIsolation(false)</code></small></td>
    <td>In-process include, sequential per worker</td>
    <td class="vsfpm-cell-accent">21,964</td>
    <td class="vsfpm-right">0.91</td>
  </tr>
  <tr class="vsfpm-row-tint">
    <td><strong>ZealPHP fork CGI</strong><br><small><code>processIsolation(true)</code> + <code>cgiMode('fork')</code></small></td>
    <td>OpenSwoole\Process forks the warm worker (COW)</td>
    <td class="vsfpm-cell-accent">814</td>
    <td class="vsfpm-right">24.6</td>
  </tr>
  <tr>
    <td><strong>ZealPHP legacy CGI</strong><br><small><code>processIsolation(true)</code> + <code>cgiMode('proc')</code> (default)</small></td>
    <td><code>proc_open</code> spawns fresh PHP per request</td>
    <td class="vsfpm-cell-danger-bold">160</td>
    <td class="vsfpm-cell-danger">124.4</td>
  </tr>
</table>

<p class="vsfpm-note-muted">
  Intel i9-14900K · PHP 8.3 · 4 workers each · <code class="vsfpm-inline-code">ab -n 3000 -c 20</code> (legacy-CGI row at <code>-n 1500</code>; it's slow). Apache served <code>/probe.php</code>; ZealPHP served <code>/probe</code> (extensionless implicit route). PHP-FPM wasn't installed on this box — Apache+mod_php is the warm-interpreter baseline and is actually <em>faster</em> than FPM would be (mod_php is in-process; FPM adds a FastCGI socket hop). Reproduce with <code>scripts/bench_vs_fpm.sh</code>.
</p>

<h3 class="vsfpm-h3-accent">What these numbers actually say</h3>

<ul class="vsfpm-list">
  <li><strong>Don't isolate if you don't have to.</strong> Both in-process modes (coroutine 34k, Mixed-mode 22k) are within striking distance of Apache mod_php (41k) and orders of magnitude past either isolated mode. If your legacy app doesn't need a fresh process per request, run Mixed-mode and pay nothing.</li>
  <li><strong>If you DO need isolation, <code>cgiMode('fork')</code> is ~5× faster than the proc_open default.</strong> 814 vs 160 req/s (24.6 ms vs 124 ms) — same isolation, same superglobals, but the fork inherits the warm interpreter + autoloader + opcache via copy-on-write instead of cold-starting a fresh PHP per request. Trade-off: fork mode runs the file in function scope, so bare <code>global $wpdb</code> wiring needs <code>cgiMode('proc')</code>; superglobal-driven "modernised legacy" apps use fork.</li>
  <li><strong>The proc_open bridge is the outlier, by 40–250×.</strong> 160 req/s vs everything else. That gap is <em>entirely</em> per-request PHP startup + autoload. It's the price of true global-scope isolation for unmodified WordPress/Drupal; the v0.3.0 warm worker pool (warm <em>and</em> global scope) is the planned fix.</li>
  <li><strong>Honest finding: Apache mod_php (41k) edges out ZealPHP on this trivial echo.</strong> For a no-I/O, no-middleware legacy file, a mature in-process C SAPI is hard to beat. ZealPHP's win shows up elsewhere — native routes (<a href="/performance">/performance</a>), coroutine I/O concurrency, WebSocket, SSE, and not needing a separate web server at all. We're not going to pretend otherwise.</li>
</ul>

<h2 class="vsfpm-h2-tight">Reproduce locally</h2>

<p class="vsfpm-prose">
  <code>scripts/bench_vs_fpm.sh</code> benches every row of the table above. It always hits the ZealPHP coroutine endpoint (<code>ZEAL_URL</code>); point the other knobs at instances you've started to fill in the rest:
</p>

<ul class="vsfpm-list">
  <li><code>MIXED_URL=</code> — the apples-to-apples row: a ZealPHP instance running <code>superglobals(true) + processIsolation(false) + enableCoroutine(false)</code> (the PHP-FPM-equivalent in-process model).</li>
  <li><code>FPM_URL=</code> — Apache/nginx + PHP-FPM serving the same trivial file.</li>
  <li><code>FORK_CGI_URL=</code> — a ZealPHP instance with <code>cgiMode('fork')</code> (warm-fork CGI bridge).</li>
  <li><code>LEGACY_CGI_URL=</code> — a ZealPHP instance with <code>cgiMode('proc')</code> (the default proc_open bridge).</li>
</ul>

<p class="vsfpm-prose-mt-07">
  It probes each first and skips with a setup hint if it can't reach one — it won't auto-install Apache/FPM.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 8. Next steps                                                  -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 class="vsfpm-h2">Where to go next</h2>

<ul class="vsfpm-next-list">
  <li><a href="/migration" class="vsfpm-link-accent">Migration guide</a> — incremental porting from FPM</li>
  <li><a href="/legacy-apps" class="vsfpm-link-accent">Legacy apps</a> — how the CGI bridge actually works under the hood</li>
  <li><a href="/coroutines#lifecycle-modes" class="vsfpm-link-accent">Lifecycle modes</a> — mix-and-match coroutine + legacy in the same app</li>
  <li><a href="/case-studies/sna-labs" class="vsfpm-link-accent">SNA Labs case study</a> — full production migration write-up</li>
  <li><a href="/performance" class="vsfpm-link-accent">Benchmarks</a> — full methodology, every CSV linked</li>
</ul>

</div>
</section>
