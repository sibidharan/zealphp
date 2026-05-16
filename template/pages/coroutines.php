<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Coroutines</h1>
<p class="section-desc">OpenSwoole coroutines are cooperative — they yield only on I/O, making parallel fetch trivial. ZealPHP enables HOOK_ALL so all PHP I/O (file, curl, PDO) becomes coroutine-aware automatically.</p>

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

<h2 style="margin:2rem 0 .5rem">How it works</h2>
<table class="ztable">
  <tr><th>Primitive</th><th>Purpose</th></tr>
  <tr><td><code>go(callable)</code></td><td>Spawn a coroutine. Runs concurrently when current coroutine yields.</td></tr>
  <tr><td><code>co::sleep(float $s)</code></td><td>Yield for N seconds without blocking the event loop.</td></tr>
  <tr><td><code>new Channel(int $capacity)</code></td><td>Buffered queue for coroutine communication. <code>push()</code> + <code>pop()</code>.</td></tr>
  <tr><td><code>usleep(int $us)</code></td><td>Coroutine-aware micro-sleep under HOOK_ALL (use for sub-second delays).</td></tr>
  <tr><td><code>OpenSwoole\Runtime::HOOK_ALL</code></td><td>Makes all PHP I/O — curl, file, PDO, sleep — yield the event loop.</td></tr>
</table>

<div class="callout info" style="margin-top:1.5rem">
  <strong>App::superglobals(false)</strong> must be called before App::init() to enable coroutine mode.
  In coroutine mode, every request runs in its own coroutine with isolated <code>RequestContext::instance()</code> state (formerly named <code>G</code>; <code>G</code> remains as a class alias for backward compatibility).
</div>

<h2 id="what-survives" style="margin:2.5rem 0 .5rem">What survives a request</h2>
<p class="section-desc">Long-running PHP changes the rules from PHP-FPM. This is the discipline contract you accept when running on ZealPHP — what the framework isolates for you, and what you have to keep clean yourself.</p>

<h3 style="margin:1.5rem 0 .5rem">Isolated per coroutine — framework handles this</h3>
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

<h3 style="margin:1.5rem 0 .5rem">NOT isolated — lives in worker process memory until the worker recycles</h3>
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

<h3 style="margin:1.5rem 0 .5rem">The discipline contract</h3>
<p>ZealPHP's per-request isolation is a <strong>discipline contract</strong>, not a runtime guarantee. The framework isolates what it owns (everything in <code>RequestContext</code>); it can't isolate what your code puts in <code>static $foo</code> or <code>private static $instance</code>. That state lives in worker process memory and survives every coroutine boundary, until the worker recycles.</p>
<p>This is the same trade-off every long-running PHP runtime makes. Hyperf and RoadRunner both ship worker recycling for exactly this reason — the surface area of state outside the framework's request-scoped object is too large to audit programmatically. The trust story is <strong>isolation + recycling, not either alone</strong>.</p>

<h3 style="margin:1.5rem 0 .5rem">The backstop — worker recycling (<code>max_request</code>)</h3>
<p>ZealPHP defaults to <code>max_request=100000</code> since <strong>v0.2.4</strong>. After a worker handles 100,000 requests, OpenSwoole sends it <code>SIGTERM</code>, drains the current request, and the manager process forks a fresh worker. <strong>All process state — static variables, accumulated closures, leaked memory, the lot — is reset to zero.</strong> The TCP listener stays open via the manager, so no requests are dropped during the handoff.</p>
<p>Tuning knobs:</p>
<table class="ztable">
  <tr><th>Knob</th><th>How to set</th><th>When to change</th></tr>
  <tr><td><code>ZEALPHP_MAX_REQUEST</code> (env var)</td><td><code>ZEALPHP_MAX_REQUEST=50000 php app.php</code></td><td>Tighter window if you know your app leaks; looser if your perf budget can't afford 100k-request fork churn</td></tr>
  <tr><td><code>$app->run(['max_request' =&gt; N])</code></td><td>Code-level override in <code>app.php</code></td><td>Same as env var, but checked in</td></tr>
  <tr><td><code>ZEALPHP_MAX_REQUEST=0</code></td><td>Env var</td><td>Disable recycling entirely (don't, unless you're benchmarking)</td></tr>
</table>

<h3 id="safety-matrix" style="margin:1.5rem 0 .5rem">Coroutine safety matrix (per mode)</h3>
<table class="ztable">
  <tr><th>Concern</th><th>Coroutine mode <br><small>(<code>App::superglobals(false)</code>, scaffold default)</small></th><th>Superglobals mode <br><small>(<code>App::superglobals(true)</code>, migration only)</small></th></tr>
  <tr>
    <td><code>$g->session</code>, <code>$g->status</code>, etc.</td>
    <td>✅ Per-coroutine, isolated</td>
    <td>⚠ Process-wide singleton; framework resets per-request, but write at your own risk</td>
  </tr>
  <tr>
    <td><code>$_GET</code>, <code>$_POST</code> direct access</td>
    <td>✅ Per-coroutine via <code>$g->get</code>/<code>$g->post</code></td>
    <td>⚠ Single-coroutine-per-request — <strong>do not</strong> <code>go()</code> inside handlers</td>
  </tr>
  <tr>
    <td><code>header()</code>, <code>setcookie()</code> via uopz</td>
    <td>✅ Writes to per-coroutine <code>$response->headersList</code></td>
    <td>⚠ Single-coroutine-per-request</td>
  </tr>
  <tr>
    <td><code>set_error_handler()</code> / <code>register_shutdown_function()</code></td>
    <td>✅ Stack lives on per-coroutine <code>RequestContext</code>, freed on coroutine end</td>
    <td>⚠ Process-wide stack — legacy code that re-registers per-request accumulates handlers until worker recycle</td>
  </tr>
  <tr>
    <td><code>go()</code> inside a request handler</td>
    <td>✅ Allowed and recommended for parallel I/O</td>
    <td>❌ Not supported — superglobals mode disables coroutine scheduling</td>
  </tr>
  <tr>
    <td><code>static $cache = []</code> in user functions</td>
    <td>❌ Survives, requires the recycling backstop</td>
    <td>❌ Same</td>
  </tr>
  <tr>
    <td><code>OpenSwoole\Table</code> mid-write atomicity</td>
    <td>Single <code>set()</code> is atomic at the C level; multi-call updates to the same row are not transactional. <code>incr</code>/<code>decr</code>/<code>compareAndSet</code> are atomic. SIGKILL mid-write may leave the row's spinlock held — graceful shutdown (including <code>max_request</code> recycle) releases cleanly. Use Store as best-effort cache, not a database.</td>
    <td>Same</td>
  </tr>
</table>

<h3 style="margin:1.5rem 0 .5rem">Common patterns</h3>
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

<div class="callout info" style="margin-top:1.5rem">
  <strong>Want to dig deeper?</strong> See <a href="/store">Store &amp; Cache</a> for shared-memory semantics, <a href="/migration">Migration</a> for the lift-and-shift path, and <a href="/deployment">Deploy</a> for production tuning (opcache settings, supervisor config, worker counts).
</div>
</div>
</section>
