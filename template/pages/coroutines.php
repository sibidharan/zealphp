<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Coroutines</h1>
<p class="section-desc">OpenSwoole coroutines are cooperative — they yield only on I/O, making parallel fetch trivial. ZealPHP enables HOOK_ALL so most PHP I/O (file, curl, sleep) becomes coroutine-aware automatically.</p>

<?php
$demos = [
  ['co-parallel', 'Parallel fetch — 3 coroutines in 1s not 3s', '/demo/coroutine/parallel',
   <<<'PHP'
$app->route('/demo/coroutine/parallel', function() {
    $ch    = new Channel(3);
    $start = microtime(true);

    go(fn() => [$ch->push(simulated_fetch('users',  1))]);
    go(fn() => [$ch->push(simulated_fetch('orders', 1))]);
    go(fn() => [$ch->push(simulated_fetch('stats',  1))]);

    $results = [];
    for ($i = 0; $i < 3; $i++) $results[] = $ch->pop();

    return ['results' => $results, 'elapsed_s' => round(microtime(true) - $start, 3)];
    // All 3 run in parallel → ~1s total, not 3s
});
PHP],
  ['co-channel', 'Channel — producer/consumer pattern', '/demo/coroutine/channel',
   <<<'PHP'
$app->route('/demo/coroutine/channel', function() {
    $ch = new Channel(1); // buffer of 1

    go(function() use ($ch) {
        co::sleep(1);
        $ch->push(['value' => 42, 'from' => 'producer coroutine']);
    });

    $result = $ch->pop(); // blocks until producer pushes
    return ['received' => $result, 'pattern' => 'producer/consumer'];
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 class="coro-h2">How it works</h2>
<table class="ztable">
  <tr><th>Primitive</th><th>Purpose</th></tr>
  <tr><td><code>go(callable)</code></td><td>Spawn a coroutine. Runs concurrently when current coroutine yields.</td></tr>
  <tr><td><code>co::sleep(float $s)</code></td><td>Yield for N seconds without blocking the event loop.</td></tr>
  <tr><td><code>new Channel(int $capacity)</code></td><td>Buffered queue for coroutine communication. <code>push()</code> + <code>pop()</code>.</td></tr>
  <tr><td><code>usleep(int $us)</code></td><td>Coroutine-aware micro-sleep under HOOK_ALL (use for sub-second delays).</td></tr>
  <tr><td><code>OpenSwoole\Runtime::HOOK_ALL</code></td><td>Makes most PHP I/O — curl, file, sleep — yield the event loop. PDO is <strong>not</strong> hooked in OpenSwoole 22.1&ndash;26.2.</td></tr>
</table>

<div class="callout info coro-mt">
  <strong>App::superglobals(false)</strong> must be called before App::init() to enable coroutine mode.
  In coroutine mode, every request runs in its own coroutine with isolated <code>RequestContext::instance()</code> state (formerly named <code>G</code>; <code>G</code> remains as a class alias for backward compatibility).
</div>

<h2 id="state-parity" class="coro-h2-section"><code>$g</code> vs <code>$_*</code> — request state in both modes</h2>
<div class="callout info coro-callout">
  <p class="coro-m-0"><strong>One-line rule.</strong> Use <code>$g-&gt;get</code>, <code>$g-&gt;post</code>, <code>$g-&gt;cookie</code>, <code>$g-&gt;server</code>, <code>$g-&gt;session</code>, <code>$g-&gt;files</code> (where <code>$g = RequestContext::instance()</code>). It works identically in both <code>App::superglobals(true)</code> and <code>App::superglobals(false)</code>. Reserve <code>$_GET</code>, <code>$_POST</code>, <code>$_SERVER</code>, <code>$_SESSION</code>, <code>$_COOKIE</code>, <code>$_FILES</code> for legacy code that you can't change — and only when you're in superglobals mode.</p>
</div>

<p>The two forms diverge on one axis: how the framework populates them per request.</p>

<table class="ztable coro-mb">
  <tr><th>Mode</th><th><code>$g-&gt;get</code> / <code>$g-&gt;post</code> / …</th><th><code>$_GET</code> / <code>$_POST</code> / …</th></tr>
  <tr>
    <td><code>App::superglobals(true)</code><br><small>legacy / migration mode</small></td>
    <td>✅ Bridged to <code>$GLOBALS['_GET']</code> etc. on each request — both forms read &amp; write the same backing array, so they're observationally equivalent.</td>
    <td>✅ Repopulated per request by the framework. Safe because each worker runs one request at a time (coroutine scheduler is disabled in this mode).</td>
  </tr>
  <tr>
    <td><code>App::superglobals(false)</code><br><small>coroutine mode (recommended default)</small></td>
    <td>✅ A per-coroutine typed property on <code>RequestContext</code>, stored on <code>Coroutine::getContext()</code>. Isolated per request even when thousands run concurrently in the same worker.</td>
    <td>❌ <strong>NOT populated per request.</strong> They're process-wide arrays; the framework does not write to them under coroutine mode. Reads see whatever the last writer left behind; writes leak across coroutines.</td>
  </tr>
</table>

<p class="coro-mt-half"><strong>Why the <code>$g</code> form is always the right answer:</strong> the rule "use <code>$g-&gt;X</code>" composes correctly regardless of which mode you flip to next. If you write a route today against <code>$g-&gt;get['id']</code>, that handler still works the day you decide to migrate from <code>superglobals(true)</code> to coroutine mode — no rewrite. The <code>$_GET</code> form silently breaks at that boundary.</p>

<?php App::render('/components/_code', [
    'label' => 'The same code, both modes',
    'code'  => <<<'PHP'
use ZealPHP\RequestContext;

$app->route('/article/{id}', function ($id) {
    $g = RequestContext::instance();

    // Recommended — always works in both modes:
    $g->get['id'] = $id;
    $g->server['PHP_SELF'] = '/article.php';

    // Legacy equivalent (works in superglobals(true) only):
    //     $_GET['id'] = $id;
    //     $_SERVER['PHP_SELF'] = '/article.php';

    return App::include('/article.php');
});
PHP]); ?>

<p>Pages that touch request state link here rather than restating this rule: <a href="/legacy-apps">Legacy Apps</a> (the Apache rewrite recipes), <a href="/sessions">Sessions</a>, <a href="/routing">Routing</a>, and the <a href="/api">API layer</a>.</p>

<h2 id="lifecycle-modes" class="coro-h2-section">Lifecycle modes — four knobs, six supported combinations</h2>

<p><code>App::superglobals()</code> used to bundle four decisions into one flag. As of v0.2.23 each is exposed as its own fluent setter so you can mix-and-match. Each new knob defaults to <code>null</code> and resolves to "follow <code>App::$superglobals</code>" at <code>App::run()</code> time — apps that don't touch them see no behaviour change.</p>

<table class="ztable coro-mb">
  <tr><th>Knob</th><th>Setter</th><th><code>null</code> resolves to</th><th>What it controls</th></tr>
  <tr>
    <td><code>$superglobals</code></td>
    <td><code>App::superglobals(bool)</code></td>
    <td>— (no default)</td>
    <td><code>$g</code> storage strategy: process-wide PHP superglobals (true) vs per-coroutine <code>RequestContext</code> (false). Also picks <code>SessionManager</code> (true) vs <code>CoSessionManager</code> (false).</td>
  </tr>
  <tr>
    <td><code>$process_isolation</code></td>
    <td><code>App::processIsolation(bool)</code></td>
    <td><code>$superglobals</code></td>
    <td><code>App::include()</code> dispatch: true → subprocess per file via <code>cgiMode()</code> backend (default <code>'pool'</code> — warm FPM-style worker pool, ~1-3 ms per dispatch; <code>'proc'</code> — fresh <code>proc_open</code> per request, ~30-50 ms; <code>'fcgi'</code> — forward to upstream FPM). false → in-process via <code>executeFile()</code> (sub-ms, no isolation).</td>
  </tr>
  <tr>
    <td><code>$enable_coroutine_override</code></td>
    <td><code>App::enableCoroutine(bool)</code></td>
    <td><code>!$superglobals</code></td>
    <td>OpenSwoole's <code>enable_coroutine</code> server setting — auto-coroutine-per-request wrapper. <code>false</code> → workers handle one request at a time synchronously.</td>
  </tr>
  <tr>
    <td><code>$hook_all_override</code></td>
    <td><code>App::hookAll(bool|int)</code></td>
    <td><code>!$superglobals</code> (HOOK_ALL or 0)</td>
    <td><code>OpenSwoole\Runtime::enableCoroutine($flags)</code> — process-wide PHP I/O hooks (curl, fopen, mysqli). PDO is <strong>not</strong> hooked in OpenSwoole 22.1 / 26.2 regardless.</td>
  </tr>
  <tr>
    <td><code>$session_lifecycle</code></td>
    <td><code>App::sessionLifecycle(bool)</code></td>
    <td><code>true</code></td>
    <td>Whether <code>SessionManager</code> / <code>CoSessionManager</code> drive the per-request session lifecycle (session_start, cookie emission, session_write_close). Set to <code>false</code> when another framework (Symfony's <code>NativeSessionStorage</code> via the <a href="https://github.com/sibidharan/zealphp-symfony">zealphp-symfony</a> bridge) owns sessions.</td>
  </tr>
</table>

<h3 class="coro-h3">Supported mode matrix</h3>

<table class="ztable coro-mb">
  <tr><th>Mode</th><th><code>superglobals</code></th><th><code>processIsolation</code></th><th><code>enableCoroutine</code></th><th><code>hookAll</code></th><th>When to use</th></tr>
  <tr><td><strong>Legacy CGI</strong><br><small>default when <code>superglobals(true)</code></small></td><td>true</td><td>true</td><td>false</td><td>0</td><td>Unmodified WordPress / Drupal — <code>define()</code>-heavy plugins need a fresh process per request.</td></tr>
  <tr><td><strong>Coroutine</strong><br><small>default when <code>superglobals(false)</code></small></td><td>false</td><td>false</td><td>true</td><td>HOOK_ALL</td><td>Modern apps benefiting from concurrent coroutine I/O; OpenSwoole-native code.</td></tr>
  <tr><td><strong>Mixed-mode / Symfony</strong></td><td>true</td><td><strong>false</strong></td><td>false</td><td>0</td><td>Symfony / Laravel on ZealPHP — real <code>$_SESSION</code> needed, but no per-include CGI fork cost. Sequential request handling per worker → no race risk on superglobals.</td></tr>
  <tr><td>In-process + sync</td><td>true</td><td>false</td><td>false</td><td>0</td><td>Same shape as Mixed-mode — the "scheduler off, no CGI" combo.</td></tr>
  <tr><td>Coroutine without HOOK_ALL</td><td>false</td><td>false</td><td>true</td><td>0</td><td>Per-request coroutine isolation but no auto I/O hooks (e.g. testing, custom hooks).</td></tr>
  <tr><td><strong>Coroutine + Process Isolation</strong><br><small>the "best of both worlds" hybrid — opt in via explicit <code>processIsolation(true)</code></small></td><td>false</td><td><strong>true</strong></td><td>true</td><td>HOOK_ALL</td><td>Modern app with coroutine concurrency in route handlers / API / middleware, but occasional legacy isolated PHP (a WordPress plugin endpoint, a heritage <code>define()</code>-heavy script). Parent runs coroutines + dispatches concurrently to different pool workers; each <code>public/*.php</code> still gets full subprocess isolation with real superglobals inside. See <a href="#coroutine-isolation-hybrid">the hybrid explainer</a> below.</td></tr>
</table>

<div class="callout warn coro-mb">
  <strong>Unsafe combinations</strong> — <code>App::run()</code> <strong>throws <code>RuntimeException</code> at boot</strong> (v0.2.27+; pre-v0.2.27 these emitted invisible warnings, now fail loud, fail fast):
  <ul class="coro-warn-list">
    <li><code>superglobals(true) + enableCoroutine(true)</code> — process-wide <code>$_GET</code> / <code>$_POST</code> / <code>$_SESSION</code> arrays would race across concurrent coroutines (the bug per-coroutine <code>$g</code> was designed to avoid).</li>
    <li><code>superglobals(true) + hookAll(non-zero)</code> — hooked I/O can yield mid-request, exposing process-wide superglobal mutations to other concurrent coroutines.</li>
  </ul>
  <p class="coro-mb">Note: both throws are gated on <code>superglobals(true)</code>. The Coroutine + Process Isolation hybrid (<code>sg=false + pi=true + ec=true + ha=HOOK_ALL</code>) is <strong>fully supported</strong> — see the matrix row above and the explainer below.</p>
</div>

<h3 id="coroutine-isolation-hybrid" class="coro-h3">Mode 6 — Coroutine + Process Isolation (the hybrid)</h3>

<p>The two refused combos above are both gated on <code>superglobals(true)</code>. With <code>superglobals(false)</code> the parent already uses per-coroutine <code>$g</code>, so neither race exists — which means you can legally combine <strong>coroutine concurrency at the parent</strong> with <strong>per-request subprocess isolation</strong>:</p>

<pre><code class="language-php">App::superglobals(false);     // per-coroutine $g (safe — no race)
App::processIsolation(true);  // public/*.php → CGI pool subprocess
App::enableCoroutine(true);   // parent runs coroutines (resolved from null)
App::hookAll(\OpenSwoole\Runtime::HOOK_ALL); // hooks pipe I/O (resolved from null)
// cgiMode('pool') is the default since v0.2.41 — warm FPM-style worker pool
</code></pre>

<p>What you get:</p>
<ul>
  <li><strong>Parent worker</strong>: N concurrent coroutines in flight, each with its own <code>$g</code> context. Routes, API, middleware run at full coroutine speed.</li>
  <li><strong>App::include('/wp-login.php')</strong>: the parent pops a pool worker from its <code>Coroutine\Channel</code>, writes the request frame over stdin, and <strong>yields on the pipe read</strong> (HOOK_ALL hooks it). The scheduler runs other coroutines while the subprocess executes.</li>
  <li><strong>Multiple coroutines dispatch in parallel</strong>: each coroutine pops a different pool worker (channel queue), up to <code>cgiPoolSize</code> (default 4). True request-level concurrency through the CGI path.</li>
  <li><strong>Inside the subprocess</strong>: real <code>$_GET</code> / <code>$_POST</code> / <code>$_SERVER</code> / <code>$_COOKIE</code> / <code>$_REQUEST</code> populated per request, reset to clean state between requests. Full global-scope isolation per request — <code>define()</code> calls in a WordPress plugin don't leak across requests.</li>
</ul>

<p>What you do NOT get (honest caveat):</p>
<ul>
  <li>Coroutines do <strong>not</strong> run INSIDE the pool subprocess — each subprocess handles one request at a time, sequentially. To scale CGI concurrency, raise <code>cgiPoolSize()</code>, not enable a scheduler inside the subprocess (that would re-introduce the superglobals-race bug at a different layer).</li>
  <li>This is <strong>not the default</strong>. You must explicitly set <code>App::processIsolation(true)</code> — otherwise <code>processIsolation</code> resolves from <code>null</code> to follow <code>sg=false</code> → <code>pi=false</code> (no isolation). The defaults assume you either want full coroutine speed (no isolation) OR full superglobal compat (Legacy CGI mode); the hybrid is for the modern-mostly-with-legacy-pockets case.</li>
</ul>

<p>Pinned by 13 lifecycle tests + 12 cgiPool tests at <code>tests/Unit/LifecycleModesMatrixTest.php</code> and <code>tests/Unit/CGI/CgiPoolDispatchTest.php</code>. See also <a href="/legacy-apps#pool-buys">"What cgiMode('pool') buys you"</a> on the legacy-apps page for measured pool overhead.</p>

<p>The default coupling — <code>null</code> everywhere — preserves the historical behaviour for any app that doesn't touch these knobs. The <a href="https://github.com/sibidharan/zealphp-symfony">zealphp-symfony</a> bridge uses <code>superglobals(true) + processIsolation(false) + sessionLifecycle(false)</code> to get the Mixed-mode lifecycle.</p>

<h2 id="what-survives" class="coro-h2-section">What survives a request</h2>
<p class="section-desc">Long-running PHP changes the rules from PHP-FPM. This is the discipline contract you accept when running on ZealPHP — what the framework isolates for you, and what you have to keep clean yourself.</p>

<h3 class="coro-h3">Isolated per coroutine — framework handles this</h3>
<p>In coroutine mode (<code>App::superglobals(false)</code>, scaffold default since v0.2.4), <code>RequestContext::instance()</code> returns an instance stored on <code>Coroutine::getContext($cid)</code>. It's allocated when the coroutine starts and freed when it ends. Every field on it is per-request:</p>
<table class="ztable">
  <tr><th>Field</th><th>Purpose</th></tr>
  <tr><td><code>$g->get</code>, <code>$g->post</code>, <code>$g->cookie</code>, <code>$g->files</code>, <code>$g->server</code>, <code>$g->request</code></td><td>Request inputs — populated by the session manager on request entry</td></tr>
  <tr><td><code>$g->session</code></td><td>Session data — loaded from the file-backed store on entry, written back on exit</td></tr>
  <tr><td><code>$g->status</code></td><td>HTTP status code being prepared</td></tr>
  <tr><td><code>$g->zealphp_request</code>, <code>$g->zealphp_response</code></td><td>PSR-7 request/response wrappers</td></tr>
  <tr><td><code>$response->headersList</code>, <code>$response->cookiesList</code>, <code>$response->rawCookiesList</code></td><td>Outbound headers/cookies pending emission (on the Response object since v0.2.6)</td></tr>
  <tr><td><code>$g->error_handlers_stack</code>, <code>$g->exception_handlers_stack</code>, <code>$g->shutdown_functions</code></td><td>Handler stacks pushed via <code>set_error_handler()</code> / <code>register_shutdown_function()</code> — freed when the coroutine ends, so legacy code that re-registers per-request can't accumulate handlers</td></tr>
  <tr><td><strong>Any local variable inside your handler</strong></td><td>Stack-allocated, dies when the handler returns. Safe.</td></tr>
</table>

<h3 class="coro-h3">NOT isolated — lives in worker process memory until the worker recycles</h3>
<p>The following survive every coroutine boundary and every request boundary. The framework cannot isolate them. Treat them as worker-lifetime state.</p>
<table class="ztable">
  <tr><th>Pattern</th><th>Why it leaks</th><th>What to do</th></tr>
  <tr>
    <td><code>function foo() { static $cache = []; ... }</code></td>
    <td>Static-in-function lives in the function's symbol table, which is process-scoped.</td>
    <td>Don't use it for request-scoped data. Use a local variable or a property on <code>$g</code>.</td>
  </tr>
  <tr>
    <td><code>class MyService { private static $instance; }</code></td>
    <td>Class-level statics live on the class, which is loaded once per worker.</td>
    <td>Treat any class static as cross-request state. Singletons are worker-lifetime.</td>
  </tr>
  <tr>
    <td><code>OpenSwoole\Table</code> rows (via <code>Store</code>)</td>
    <td>By design — Store is cross-worker shared memory. That's its purpose.</td>
    <td>OK to use, but never store per-request data here. Use it for counters, caches, rate-limit windows.</td>
  </tr>
  <tr>
    <td>Closures captured by <code>App::tick()</code> / <code>App::after()</code> / <code>App::onWorkerStart()</code></td>
    <td>By design — these fire outside any request. Whatever they capture lives until the worker recycles.</td>
    <td>Capture configuration/handles, not per-request state.</td>
  </tr>
  <tr>
    <td><code>ini_set('date.timezone', ...)</code> and friends</td>
    <td>Mutates process state. PHP doesn't reset it between requests.</td>
    <td>Set globally at boot (in <code>app.php</code> before <code>App::run()</code>) or accept that the change is sticky. Don't <code>ini_set()</code> per request.</td>
  </tr>
  <tr>
    <td>OPcache compiled bytecode</td>
    <td>Process-wide. Deploys need a worker restart (or <code>php app.php restart</code>) for the new code to load.</td>
    <td>See the deploy guide. <code>opcache.validate_timestamps=0</code> + restart-on-deploy is the production pattern.</td>
  </tr>
  <tr>
    <td>Pooled DB / Redis connection state</td>
    <td>A pool keeps connections alive across requests. <code>BEGIN</code> without <code>COMMIT</code>, <code>SET SESSION sql_mode</code>, <code>CREATE TEMPORARY TABLE</code> all survive on the connection.</td>
    <td>If you pool, always reset on checkout: <code>ROLLBACK</code>, restore <code>sql_mode</code>, deallocate prepares. (A <code>ZealPHP\Pool</code> helper with this baked in is on the v0.3 roadmap.)</td>
  </tr>
</table>

<h3 class="coro-h3">The discipline contract</h3>
<p>ZealPHP's per-request isolation is a <strong>discipline contract</strong>, not a runtime guarantee. The framework isolates what it owns (everything in <code>RequestContext</code>); it can't isolate what your code puts in <code>static $foo</code> or <code>private static $instance</code>. That state lives in worker process memory and survives every coroutine boundary, until the worker recycles.</p>
<p>This is the same trade-off every long-running PHP runtime makes. Hyperf and RoadRunner both ship worker recycling for exactly this reason — the surface area of state outside the framework's request-scoped object is too large to audit programmatically. The trust story is <strong>isolation + recycling, not either alone</strong>.</p>

<h3 class="coro-h3">The backstop — worker recycling (<code>max_request</code>)</h3>
<p>ZealPHP defaults to <code>max_request=100000</code> since <strong>v0.2.4</strong>. After a worker handles 100,000 requests, OpenSwoole sends it <code>SIGTERM</code>, drains the current request, and the manager process forks a fresh worker. <strong>All process state — static variables, accumulated closures, leaked memory, the lot — is reset to zero.</strong> The TCP listener stays open via the manager, so no requests are dropped during the handoff.</p>
<p>Tuning knobs:</p>
<table class="ztable">
  <tr><th>Knob</th><th>How to set</th><th>When to change</th></tr>
  <tr><td><code>ZEALPHP_MAX_REQUEST</code> (env var)</td><td><code>ZEALPHP_MAX_REQUEST=50000 php app.php</code></td><td>Tighter window if you know your app leaks; looser if your perf budget can't afford 100k-request fork churn</td></tr>
  <tr><td><code>$app->run(['max_request' =&gt; N])</code></td><td>Code-level override in <code>app.php</code></td><td>Same as env var, but checked in</td></tr>
  <tr><td><code>ZEALPHP_MAX_REQUEST=0</code></td><td>Env var</td><td>Disable recycling entirely (don't, unless you're benchmarking)</td></tr>
</table>

<h3 id="safety-matrix" class="coro-h3">Safety matrix (per mode)</h3>
<p class="coro-note">The two modes are different runtimes, not different settings on the same runtime. Coroutine mode runs OpenSwoole's coroutine scheduler with <code>HOOK_ALL</code> enabled — every request is its own coroutine, many in flight per worker. Superglobals mode <strong>disables the coroutine scheduler entirely</strong> (<code>enable_coroutine = false</code> on the OpenSwoole server) and runs each request synchronously in the worker process, one at a time per worker — Apache MPM-prefork semantics. Implicit file routes (legacy <code>.php</code> drops) in superglobals mode additionally run through the CGI bridge (<code>App::include()</code> → <code>src/cgi_worker.php</code> via <code>proc_open</code>) for true global-scope process isolation. See the <a href="/templates#file-execution-family">file-execution family</a> for the five entry points and the <a href="/responses#return-contract">universal return contract</a> for the shared return semantics.</p>
<table class="ztable">
  <tr><th>Concern</th><th>Coroutine mode <br><small>(<code>App::superglobals(false)</code>, scaffold default)</small></th><th>Superglobals mode <br><small>(<code>App::superglobals(true)</code>, migration only)</small></th></tr>
  <tr>
    <td>Concurrency model</td>
    <td>Coroutine scheduler enabled, <code>HOOK_ALL</code> active; thousands of concurrent requests per worker, blocking I/O yields the event loop</td>
    <td>❌ Coroutine scheduler disabled (<code>enable_coroutine = false</code>); each worker handles <strong>one request at a time</strong>, blocking</td>
  </tr>
  <tr>
    <td>Implicit file routes (legacy <code>public/*.php</code>)</td>
    <td>Run in the worker process directly</td>
    <td>Run through the <strong>CGI bridge</strong> (<code>proc_open</code> child) — true global-scope isolation per request</td>
  </tr>
  <tr>
    <td><code>$g->session</code>, <code>$g->status</code>, etc.</td>
    <td>✅ Per-coroutine via <code>Coroutine::getContext()</code>, isolated</td>
    <td>⚠ Process-wide singleton; framework resets per-request, but write at your own risk</td>
  </tr>
  <tr>
    <td><code>$_GET</code>, <code>$_POST</code> direct access<br><small>(see <a href="#state-parity">the parity rule</a>)</small></td>
    <td>❌ <strong>Not populated per request</strong> in coroutine mode. Use <code>$g-&gt;get</code> / <code>$g-&gt;post</code> instead — per-coroutine, isolated.</td>
    <td>⚠ Process-wide superglobals, repopulated per-request by the framework. Same address space as PHP-FPM's mental model — one request at a time, no interleaving risk. <code>$g-&gt;get</code> / <code>$g-&gt;post</code> still work too — they bridge to the same arrays.</td>
  </tr>
  <tr>
    <td><code>header()</code>, <code>setcookie()</code> via uopz</td>
    <td>✅ Writes to per-coroutine <code>$response->headersList</code></td>
    <td>⚠ Writes to the single in-flight request's response — synchronous, no cross-request bleed because requests don't overlap in a worker</td>
  </tr>
  <tr>
    <td><code>set_error_handler()</code> / <code>register_shutdown_function()</code></td>
    <td>✅ Stack lives on per-coroutine <code>RequestContext</code>, freed on coroutine end</td>
    <td>⚠ Process-wide stack; legacy code that re-registers per-request <em>would</em> accumulate handlers, but <code>SessionManager</code> explicitly resets the stacks at request entry (fixed in v0.2.10)</td>
  </tr>
  <tr>
    <td><code>go()</code> inside a request handler</td>
    <td>✅ Allowed and recommended for parallel I/O</td>
    <td>❌ Not supported — the coroutine scheduler isn't running. Spawn a child via <code>coprocess()</code> / <code>coproc()</code> if you need async work in this mode.</td>
  </tr>
  <tr>
    <td><code>static $cache = []</code> in user functions</td>
    <td>❌ Survives across coroutines until worker recycles — requires the <code>max_request</code> backstop</td>
    <td>❌ Same — survives across requests until worker recycles</td>
  </tr>
  <tr>
    <td><code>OpenSwoole\Table</code> mid-write atomicity</td>
    <td>Single <code>set()</code> is atomic at the C level; multi-call updates to the same row are not transactional. <code>incr</code> / <code>decr</code> / <code>compareAndSet</code> are atomic. SIGKILL mid-write may leave the row's spinlock held — graceful shutdown (including <code>max_request</code> recycle) releases cleanly. Use Store as best-effort cache, not a database.</td>
    <td>Same</td>
  </tr>
</table>

<h3 class="coro-h3">Common patterns</h3>
<table class="ztable">
  <tr><th>I want to…</th><th>Do this</th><th>Not this</th></tr>
  <tr>
    <td>Cache something for the duration of one request</td>
    <td><code>$cache = []</code> as a local variable, or property on <code>$g</code></td>
    <td><code>static $cache = []</code> inside a function</td>
  </tr>
  <tr>
    <td>Share state across requests in the same worker</td>
    <td>Class-level static, but reset/clear at known points — or <code>Store</code> with explicit row expiry</td>
    <td>Class static that grows unbounded</td>
  </tr>
  <tr>
    <td>Share state across all workers</td>
    <td><code>Store</code> (<code>OpenSwoole\Table</code>) or <code>Counter</code> (<code>OpenSwoole\Atomic</code>)</td>
    <td>Class static (each worker has its own copy)</td>
  </tr>
  <tr>
    <td>Run a one-time init when a worker starts</td>
    <td><code>App::onWorkerStart(function() { ... })</code></td>
    <td>Boot-time singleton + first-request init race</td>
  </tr>
  <tr>
    <td>Schedule a recurring task</td>
    <td><code>App::tick($ms, $fn)</code> inside <code>onWorkerStart</code></td>
    <td>Sleep loop in a request handler</td>
  </tr>
</table>

<div class="callout info coro-mt">
  <strong>Want to dig deeper?</strong> See <a href="/store">Store &amp; Cache</a> for shared-memory semantics, <a href="/migration">Migration</a> for the lift-and-shift path, and <a href="/deployment">Deploy</a> for production tuning (opcache settings, supervisor config, worker counts).
</div>
</div>
</section>
