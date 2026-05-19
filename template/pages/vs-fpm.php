<?php use ZealPHP\App; ?>

<section class="section section-dark">
<div class="container" style="max-width:900px">

<h1 class="section-title">vs. PHP-FPM</h1>
<p class="section-desc">ZealPHP replaces Apache + PHP-FPM with a single OpenSwoole process. Per-request, here's what that actually costs you.</p>

<div class="bench-method" style="margin-top:1.5rem">
  <strong>TL;DR</strong> &nbsp;|&nbsp;
  In coroutine mode, ZealPHP routes a request in microseconds — there's no fork, no IPC, no nginx hop. In legacy CGI mode (the one that runs unmodified WordPress), you pay a per-include fork cost that's comparable to FPM's per-request handoff cost. The difference: with ZealPHP you only pay it for the legacy file; routing, middleware, API, WebSocket, and streaming stay in-process.
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 1. The two architectures, side by side                         -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">The two architectures</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

  <div class="qs-block" style="padding:1.1rem 1.3rem">
    <h3 style="margin:0 0 .5rem;color:#94a3b8;font-size:1.05rem">Apache + PHP-FPM</h3>
    <pre style="background:rgba(0,0,0,.35);padding:.7rem;border-radius:6px;font-size:.78rem;line-height:1.4;margin:.4rem 0;overflow-x:auto"><code>browser
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
    <p style="margin:.5rem 0 0;color:#94a3b8;font-size:.82rem">Two processes per request (httpd + fpm worker), at least one socket hop, FCGI framing on every body chunk.</p>
  </div>

  <div class="qs-block" style="padding:1.1rem 1.3rem;border-left:3px solid var(--accent)">
    <h3 style="margin:0 0 .5rem;color:var(--accent);font-size:1.05rem">ZealPHP — coroutine mode</h3>
    <pre style="background:rgba(0,0,0,.35);padding:.7rem;border-radius:6px;font-size:.78rem;line-height:1.4;margin:.4rem 0;overflow-x:auto"><code>browser
  ↓  TCP
OpenSwoole HTTP server (master + workers)
  ↓  pick idle worker, spawn coroutine
ResponseMiddleware (in-process)
  ↓  reflection-cached param injection
your route handler / public file
  ↓  return body
OpenSwoole writes response on the same socket
  ↓
browser</code></pre>
    <p style="margin:.5rem 0 0;color:#cbd5e1;font-size:.82rem">One process, one socket, request-to-handler in microseconds. Worker is back on the next request immediately (the coroutine yields, doesn't block the worker).</p>
  </div>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 2. Cost matrix                                                 -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Per-request cost matrix</h2>

<p style="color:#cbd5e1;margin-bottom:1rem">Three stacks, three workloads. Costs are per-request, with the request body kept small so we're measuring the framework, not the bandwidth.</p>

<table class="ztable">
  <tr>
    <th style="text-align:left">Workload</th>
    <th style="text-align:right">Apache + PHP-FPM</th>
    <th style="text-align:right">ZealPHP coroutine</th>
    <th style="text-align:right">ZealPHP legacy CGI</th>
  </tr>
  <tr>
    <td>JSON endpoint (no DB)</td>
    <td style="text-align:right">~1–3 ms (FCGI hop)</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">&lt; 0.1 ms (in-process)</td>
    <td style="text-align:right">~30–50 ms (proc_open)</td>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td>Static template render</td>
    <td style="text-align:right">~1–3 ms + opcache</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">~0.1 ms</td>
    <td style="text-align:right">~30–50 ms (proc_open)</td>
  </tr>
  <tr>
    <td>WordPress home (warm)</td>
    <td style="text-align:right">~40–80 ms (mod_php)</td>
    <td style="text-align:right">N/A — needs legacy mode</td>
    <td style="text-align:right">~40–80 ms + ~30 ms bridge</td>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td>SSE stream (long-lived)</td>
    <td style="text-align:right">1 worker pinned</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">1 coroutine — worker free</td>
    <td style="text-align:right">1 child process pinned</td>
  </tr>
  <tr>
    <td>WebSocket connection</td>
    <td style="text-align:right">Not supported</td>
    <td style="text-align:right;color:var(--accent);font-weight:700">Native — same process</td>
    <td style="text-align:right">Native (handler in coroutine)</td>
  </tr>
</table>

<p style="margin:.7rem 0 0;color:#94a3b8;font-size:.85rem">
  Ranges, not point measurements — actual numbers depend on kernel, CPU, opcache state, and which exact FPM tuning you've done. The bench script at <code>scripts/bench_vs_fpm.sh</code> runs the JSON workload on the local box; reproduce before quoting.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 3. The CGI bridge — why it exists, what it costs               -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">The CGI bridge is the price of compatibility</h2>

<p style="color:#cbd5e1;line-height:1.65">
  When you set <code>App::superglobals(true)</code>, ZealPHP turns OFF the coroutine scheduler and switches <code>App::include()</code> to dispatch each legacy <code>public/*.php</code> file through a child process via <code>proc_open</code> (<a href="https://github.com/sibidharan/zealphp/blob/master/src/cgi_worker.php"><code>src/cgi_worker.php</code></a>). That's exactly how Apache's prefork MPM runs PHP. It exists for one reason: <strong>unmodified WordPress, Drupal, and other <code>define()</code>-heavy code that assumes a fresh process per request just works.</strong>
</p>

<p style="color:#cbd5e1;line-height:1.65;margin-top:.7rem">
  This adds ~30–50 ms <code>proc_open</code> latency per legacy file. That's the same order of magnitude as FPM's per-request overhead — and you're getting roughly the same thing: process isolation per request. The honest framing is: <strong>for legacy code, ZealPHP's CGI bridge costs about what FPM costs, not less.</strong> What changes is everything around it:
</p>

<ul style="color:#cbd5e1;line-height:1.7;font-size:.95rem;margin-top:.5rem;padding-left:1.5rem">
  <li>Routes you define via <code>$app-&gt;route()</code> run in-process — sub-millisecond, no bridge.</li>
  <li>ZealAPI endpoints (<code>api/*.php</code>) run in-process — no bridge.</li>
  <li>Middleware (CORS, ETag, sessions, rate limit) runs in-process — no bridge.</li>
  <li>WebSocket, SSE, streaming, timers — all in-process — no bridge.</li>
  <li>You can opt individual routes back into coroutine mode via the <a href="/coroutines#lifecycle-modes">lifecycle setters</a> (v0.2.23).</li>
</ul>

<p style="color:#cbd5e1;line-height:1.65;margin-top:.7rem">
  In other words: <strong>you pay FPM-equivalent cost only on the legacy files</strong>. Everything new you write runs on the fast path. With FPM, every request — new code, old code, an API health check, a static asset proxy — pays the FCGI hop.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 4. PHP-FPM worker count vs ZealPHP worker count                -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">"PHP-FPM has N workers — does ZealPHP need more?"</h2>

<p style="color:#cbd5e1;line-height:1.65">
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
  <tr style="background:rgba(255,255,255,.02)">
    <td>Right worker count</td>
    <td>~CPU cores × 2, or higher for I/O-heavy apps to avoid blocking</td>
    <td>~CPU cores. I/O concurrency comes from coroutines, not extra workers</td>
  </tr>
  <tr>
    <td>What happens on slow I/O</td>
    <td>Worker blocked until I/O returns — concurrent requests queue</td>
    <td>Coroutine yields — worker handles the next request immediately</td>
  </tr>
  <tr style="background:rgba(255,255,255,.02)">
    <td>Memory cost per worker</td>
    <td>Full PHP runtime per worker (~50–200 MB each)</td>
    <td>Full PHP runtime per worker — but you need fewer workers</td>
  </tr>
</table>

<p style="color:#cbd5e1;line-height:1.65;margin-top:.8rem">
  The intuition: an FPM pool of 64 workers can handle 64 concurrent requests. A ZealPHP setup with 8 workers and the coroutine scheduler can handle thousands of concurrent connections — most of them yielding on I/O at any given moment. The benchmark page (<a href="/performance">/performance</a>) confirms it: 4 workers, 116k req/s through the full middleware stack.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 5. When FPM is the right choice                                -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">When PHP-FPM is still the right answer</h2>

<p style="color:#cbd5e1;line-height:1.65">
  Be honest about it — there are cases where Apache + PHP-FPM is the safer pick today:
</p>

<ul style="color:#cbd5e1;line-height:1.7;font-size:.95rem;margin-top:.5rem;padding-left:1.5rem">
  <li>You're running off-the-shelf code that you can't modify and don't want to touch — a Drupal shop with 40 contrib modules, a WordPress site with 60 plugins. The CGI bridge runs them, but FPM is the path of least surprise.</li>
  <li>Your only PHP extensions are blocking ones with no coroutine equivalent. PDO is the canonical example (see the <a href="/case-studies/sna-labs#phase-4-mongo">SNA Labs case study</a> for how we hit this with MongoDB and built a replacement). If every database call blocks, the coroutine scheduler can't help you — and you might as well run FPM.</li>
  <li>You're allergic to <code>uopz</code>. ZealPHP requires it; FPM doesn't. If your hosting environment forbids it, FPM wins by default.</li>
</ul>

<p style="color:#cbd5e1;line-height:1.65;margin-top:.7rem">
  ZealPHP's pitch is not "always faster than FPM." It's: <strong>same compatibility, plus a fast path for new code, plus WebSocket and SSE and timers without leaving PHP.</strong>
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 6. Production proof                                            -->
<!-- ────────────────────────────────────────────────────────────── -->

<div style="margin:3rem 0 0;padding:1.2rem 1.4rem;background:rgba(251,191,36,.05);border:1px solid rgba(251,191,36,.25);border-left:3px solid var(--accent);border-radius:var(--radius)">
  <h3 style="margin:0 0 .5rem;color:#fde68a;font-size:1.1rem">Production proof point</h3>
  <p style="margin:0;color:#cbd5e1;line-height:1.6">
    Selfmade Ninja Labs runs the same PHP codebase on both Apache (mod_php) and ZealPHP from a single Docker container, sharing one volume, one MongoDB instance, one Redis. Same source tree. 41 migration commits, 806 files touched, zero downtime, including a custom Rust MongoDB extension to replace the blocking PECL driver. <a href="/case-studies/sna-labs" style="color:var(--accent);font-weight:600">Read the case study →</a>
  </p>
</div>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 7. Reproduce locally                                           -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Reproduce locally</h2>

<p style="color:#cbd5e1;line-height:1.65">
  Don't take the numbers above on faith. <code>scripts/bench_vs_fpm.sh</code> runs the JSON-endpoint workload through both stacks on the same machine and prints the cost delta. Requires Apache + PHP-FPM installed locally; the script tells you what's missing if not.
</p>

<?php App::render('/components/_code', [
  'lang' => 'bash',
  'code' => '# Same machine, three stacks (illustrative shape — actual numbers
# vary by kernel, CPU, opcache state, FPM tuning, etc.)
$ scripts/bench_vs_fpm.sh

== ZealPHP coroutine (port 8080) ==
Requests per second:   ~100k+  [#/sec]   (no FCGI hop, in-process)

== Apache + PHP-FPM (port 8081) ==
Requests per second:   ~10–20k [#/sec]   (FCGI hop + opcache hit)

== ZealPHP legacy CGI bridge (port 8082) ==
Requests per second:   ~5–10k  [#/sec]   (proc_open per include)

Reproducer: scripts/bench_vs_fpm.sh (run it on your own box)
',
]); ?>

<p style="color:#94a3b8;line-height:1.6;font-size:.88rem;margin-top:.7rem">
  The shape is what matters: coroutine mode wins comfortably because there's no FCGI hop; the legacy CGI bridge runs <em>slower</em> than FPM because <code>proc_open</code> is more expensive than FastCGI's pooled-worker handoff (FPM was specifically engineered to keep workers warm and minimise per-request fork). The bridge exists for compatibility, not speed — if a legacy file is on a hot path, port it to coroutine mode and the cost vanishes. Actual req/s numbers depend on your hardware and tuning; run the script before quoting.
</p>

<!-- ────────────────────────────────────────────────────────────── -->
<!-- 8. Next steps                                                  -->
<!-- ────────────────────────────────────────────────────────────── -->

<h2 style="margin:3rem 0 1rem">Where to go next</h2>

<ul style="color:#cbd5e1;line-height:1.8;font-size:.95rem">
  <li><a href="/migration" style="color:var(--accent)">Migration guide</a> — incremental porting from FPM</li>
  <li><a href="/legacy-apps" style="color:var(--accent)">Legacy apps</a> — how the CGI bridge actually works under the hood</li>
  <li><a href="/coroutines#lifecycle-modes" style="color:var(--accent)">Lifecycle modes</a> — mix-and-match coroutine + legacy in the same app</li>
  <li><a href="/case-studies/sna-labs" style="color:var(--accent)">SNA Labs case study</a> — full production migration write-up</li>
  <li><a href="/performance" style="color:var(--accent)">Benchmarks</a> — full methodology, every CSV linked</li>
</ul>

</div>
</section>
