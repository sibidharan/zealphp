<?php use ZealPHP\App; ?>

<section class="section" style="background:var(--bg-dark);color:var(--code-text)">
  <div class="container" style="max-width:920px">
    <h1 class="section-title" style="font-size:2rem;margin-bottom:.5rem;color:#fff">Design Trade-offs</h1>
    <p class="section-desc" style="font-size:1.05rem;max-width:760px;color:var(--text-light)">
      Every framework makes choices that cost something. Most don't show their math.
      This page is the math — what we chose, what each choice costs you,
      and what mitigation exists. Brutal honesty over marketing copy.
    </p>

    <div style="margin-top:1.75rem;padding:1rem 1.25rem;background:var(--code-bg);border:1px solid var(--border-dark);border-left:3px solid var(--accent);border-radius:var(--radius);font-size:.92rem;color:var(--text-light)">
      <strong style="color:#fff">Reading this page:</strong> each section follows the same shape —
      <em>what we chose</em>, <em>what it costs you</em>, <em>what mitigates it</em>.
      Code links point to <code>src/</code> on GitHub.
      For the version-by-version trace of how we got here, see
      <a href="https://github.com/sibidharan/zealphp/blob/master/CRITIC.md" style="color:var(--accent)">CRITIC.md</a>.
    </div>

    <!-- ─── uopz overrides ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">1. uopz overrides on PHP built-ins</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        At server boot, ZealPHP uses <code>uopz_set_return()</code> to permanently replace
        PHP built-ins like <code>header()</code>, <code>setcookie()</code>, <code>session_start()</code>,
        <code>set_error_handler()</code>, <code>http_response_code()</code>. Calls flow into per-request
        objects (<code>$g->zealphp_response</code>, <code>$g->session</code>) instead of the global PHP state
        that mod_php and FPM rely on.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> unmodified PHP-FPM-era code works. Legacy
          libraries that call <code>session_start()</code> just work; you don't rewrite them.</li>
        <li><strong style="color:#fff">What it costs:</strong> requires the <code>uopz</code> extension at
          install time (not bundled with FPM by default; one apt-get / pecl install). PHPStan can't see
          through uopz redirection — it thinks <code>header()</code> writes to a global table, when at
          runtime it writes to <code>$response->headersList</code>.</li>
        <li><strong style="color:#fff">Mitigation:</strong> 16 inline <code>@phpstan-ignore-next-line</code>
          annotations across <code>src/utils.php</code> and <code>src/Session/utils.php</code>, each with a
          one-line reason. <code>uopz</code> is checked at <code>App::init()</code> and throws if missing.</li>
      </ul>
    </div>

    <!-- ─── Dual-mode runtime ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">2. Dual-mode runtime: coroutine vs superglobals</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        <code>App::superglobals(false)</code> (recommended default) enables the coroutine scheduler,
        per-coroutine state via <code>Coroutine::getContext()</code>, and <code>HOOK_ALL</code> on PHP's
        I/O functions. <code>App::superglobals(true)</code> disables all of that and runs each request
        single-threaded with Apache-mod_php-style global state — the path that lets unmodified
        WordPress/Drupal run via the CGI bridge.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> greenfield projects get coroutines (thousands
          of concurrent requests per worker); legacy migrations get a single-runtime path with no rewrite.</li>
        <li><strong style="color:#fff">What it costs:</strong> two code paths means two surfaces for bugs.
          A mode-specific bug only fires under one config. Documentation has to explicitly mark which
          mode each guarantee applies to.</li>
        <li><strong style="color:#fff">Mitigation:</strong> coroutine mode is the documented default for
          new projects (scaffold ships it). The
          <a href="/coroutines" style="color:var(--accent)">/coroutines</a> page has a side-by-side safety
          matrix per mode. Most users never touch the superglobals flag.</li>
      </ul>
    </div>

    <!-- ─── __call proxies ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">3. <code>__call</code> proxies on HTTP wrappers</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        <code>ZealPHP\HTTP\Request</code> and <code>ZealPHP\HTTP\Response</code> wrap OpenSwoole's underlying
        request/response. Both expose <code>__call($name, $arguments)</code> so any method we haven't
        explicitly forwarded (and any future OpenSwoole-added method) is automatically proxied.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> upstream OpenSwoole versions don't require a
          framework release to expose new methods. <code>$response->newMethodFromOpenSwoole25()</code> just
          works.</li>
        <li><strong style="color:#fff">What it costs:</strong> PHPStan sees <code>call_user_func_array</code>
          returning <code>mixed</code>. Every caller of a proxied method gets a mixed-type alarm at level 9+.</li>
        <li><strong style="color:#fff">Mitigation:</strong> class-level <code>@method</code> PHPDoc on
          <code>HTTP/Request.php</code> and <code>HTTP/Response.php</code> declares the proxied signatures for the
          common methods (<code>isWritable</code>, <code>write</code>, <code>sendfile</code>, <code>getContent</code>, etc.) —
          PHPStan resolves these statically. The remaining proxy fallback has 2 inline ignores. Adding a new
          frequently-called method to the <code>@method</code> block eliminates more.</li>
      </ul>
    </div>

    <!-- ─── Reflection injection ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">4. Reflection-based route parameter injection</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        Route handlers declare their dependencies by parameter name:
      </p>
      <?php App::render('/components/_code', [
        'label' => 'Flask-style by-name injection',
        'lang' => 'php',
        'code' => <<<'PHP'
$app->route('/users/{id}', function($id, $request, $app) {
    // $id is the URL param, $request is HTTP\Request, $app is App
    return ['id' => $id];
});
PHP]); ?>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> ergonomics. Handlers feel like Express / Flask —
          no DI container, no annotations, no <code>$request</code>-as-first-param convention to remember.</li>
        <li><strong style="color:#fff">What it costs:</strong> handler signatures are unknown at static-analysis
          time. PHPStan can't tell that the closure takes <code>(string, Request, App)</code> from the route table.
          Reflection has a per-call cost too.</li>
        <li><strong style="color:#fff">Mitigation:</strong> the param map is built via <code>ReflectionFunction</code>
          once at route registration time (<code>App::buildParamMap()</code>) — zero per-request reflection. The
          dispatcher reads from the pre-built map. PHPStan ignores at the dispatch sites are documented as
          "handler param type known only at route binding."</li>
      </ul>
    </div>

    <!-- ─── CGI bridge ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">5. CGI bridge for legacy apps</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        <code>App::include($publicPath)</code> runs PHP files in a separate process via <code>proc_open</code>
        (when in superglobals mode), capturing their <code>header()</code> / <code>setcookie()</code> /
        <code>echo</code> output and stitching them into the OpenSwoole response. The file's return value also
        flows back through the <a href="/responses#return-contract" style="color:var(--accent)">universal return contract</a>.
        This is how unmodified WordPress and Drupal run on ZealPHP.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> Apache mod_php compatibility for the last mile of
          migration. <code>App::setFallback(fn() =&gt; App::include('/index.php'))</code> serves
          WordPress unmodified.</li>
        <li><strong style="color:#fff">What it costs:</strong> a process per legacy request — no coroutine
          async, no shared state, fork latency. Slow relative to a main-worker route. Adds maintenance surface
          (the bridge is 284 lines of glue in <code>src/cgi_worker.php</code>).</li>
        <li><strong style="color:#fff">Mitigation:</strong> the bridge is opt-in via <code>App::superglobals(true)</code>
          and only fires when a route falls through to <code>setFallback()</code>. New routes you write run in
          the main worker at full coroutine speed. Use the bridge for the legacy 20% you can't rewrite yet.</li>
      </ul>
    </div>

    <!-- ─── RequestContext (G) ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">6. <code>RequestContext</code> (formerly <code>G</code>) — per-coroutine, looks like a god object</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        <code>RequestContext::instance()</code> returns a per-request state container holding
        <code>$server</code>, <code>$get</code>, <code>$post</code>, <code>$cookie</code>,
        <code>$session</code>, <code>$zealphp_request</code>, <code>$zealphp_response</code>, and the rest. In
        coroutine mode it's stored in <code>Coroutine::getContext()</code> — one instance per coroutine,
        isolated. In superglobals mode it's a process singleton bridging to <code>$_GET</code> / <code>$_POST</code> / <code>$_SESSION</code>.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> a single named object for every per-request
          concern. Same shape across modes. Hyperf and Slim's <code>$_REQUEST</code>-style globals follow the
          same pattern.</li>
        <li><strong style="color:#fff">What it costs:</strong> looks like a god object on first read. Critics
          flag it before realizing that in coroutine mode it's <em>per-coroutine</em>, not process-wide.
          Frontend devs accustomed to React contexts have to map the mental model.</li>
        <li><strong style="color:#fff">Mitigation:</strong> strict <code>__set</code> rejects undeclared property
          writes in coroutine mode (typos surface immediately, not 200 requests later). <code>RequestContext::once($key, $fn)</code>
          gives a safe alternative to <code>static $cache = []</code> for user code. The
          <a href="/coroutines" style="color:var(--accent)">/coroutines</a> docs page maps the isolation
          contract per mode.</li>
      </ul>
    </div>

    <!-- ─── Discipline contract ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">7. The discipline contract for user-level statics</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        ZealPHP isolates the state <em>it</em> owns (request, response, session, $_SERVER) per coroutine. It
        does <strong>not</strong> isolate <code>static $cache = []</code> inside your handler, or
        <code>private static $instance</code> on your singleton class. Those live in worker process memory and
        survive every request boundary.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> the framework doesn't pay the cost of mediating
          every user-level static-property access. Hyperf, RoadRunner, and Laravel Octane all draw this line
          the same way.</li>
        <li><strong style="color:#fff">What it costs:</strong> a developer used to PHP-FPM (where every static
          dies at request end) can ship code that leaks across requests without noticing — until production
          memory creeps up.</li>
        <li><strong style="color:#fff">Mitigation:</strong> <code>max_request=100000</code> default in
          <code>App::run()</code> (configurable via <code>ZEALPHP_MAX_REQUEST</code>) recycles workers
          periodically. Worker-recycle access-log line surfaces the recycling in production. Opt-in
          <code>IniIsolationMiddleware</code> snapshots <code>ini_set()</code> changes per request. The
          <code>RequestContext::once()</code> helper gives you a safe primitive for memoization without
          touching <code>static</code>.</li>
      </ul>
    </div>

    <!-- ─── OpenSwoole stubs ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">8. OpenSwoole / ext-posix stub mismatches</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        <code>openswoole/ide-helper</code> and PHPStan's bundled ext stubs sometimes declare types differently
        from what the real C extension does. Examples: <code>OpenSwoole\Runtime::enableCoroutine()</code> says
        <code>bool</code>, the ext takes int flags (<code>HOOK_ALL</code> etc.). <code>posix_kill()</code> says
        always-true, the ext returns false on dead PIDs (which is exactly what the polling code is checking
        for). <code>ceil()</code> stubs widen the return to <code>float</code> when PHP 8.0+ returns int.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> IDE autocomplete still works.</li>
        <li><strong style="color:#fff">What it costs:</strong> PHPStan reports errors we can't fix in our code
          (the stubs are upstream and wrong).</li>
        <li><strong style="color:#fff">Mitigation:</strong> 9 targeted <code>ignoreErrors</code> patterns in
          <code>phpstan.neon</code>, each with a <code># reason:</code> comment explaining the specific stub-vs-ext
          mismatch. Scoped tightly to the affected file + error pattern so future real bugs aren't swallowed.</li>
      </ul>
    </div>

    <!-- ─── PHPStan 75 ignore sites ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">9. 75 inline PHPStan ignore-with-reason sites</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        ZealPHP passes PHPStan level 10 (the strictest tier, what Symfony 8+ and Laravel 12+ score at) with
        zero errors. It also has <strong>75 inline <code>@phpstan-ignore-next-line</code> annotations</strong>
        across <code>src/</code>, each annotated with a one-line reason for the design choice that makes that
        site unverifiable statically.
      </p>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem;margin-top:.5rem">
        Categories of those 75:
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li>Sections 1, 3, 4, 6, 7, 8 above account for the vast majority.</li>
        <li>The rest are array-shape boundaries where mixed flows through user-controlled session/request
          keys and gets coerced to string/int at the boundary (<code>(string)$g->session['user_id']</code>) —
          PHPStan can't prove these are safe statically; the boundary cast is the runtime contract.</li>
      </ul>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem;margin-top:.5rem">
        Run <code>grep -rn '@phpstan-ignore' src/</code> in a clone to see every site. Each one has the form
        <code>// @phpstan-ignore-next-line — &lt;reason&gt;</code>. No bare ignores.
      </p>
    </div>

    <!-- ─── max_request worker recycling ─── -->
    <div style="margin-top:2.5rem">
      <h2 style="font-size:1.3rem;margin-bottom:.75rem;color:#fff">10. <code>max_request</code> worker recycling</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.95rem">
        Workers are recycled every <code>max_request=100000</code> requests by default. After that count
        OpenSwoole gracefully exits the worker and forks a fresh one.
      </p>
      <ul style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem;padding-left:1.5rem">
        <li><strong style="color:#fff">What it buys:</strong> bounds PHP-engine state accumulation. Static
          caches in user code, leaky extension state, accidental memory ballooning — all of it caps out.
          Required for honest long-running-PHP claims.</li>
        <li><strong style="color:#fff">What it costs:</strong> the request that triggers the recycle pays a
          fork latency hit (~milliseconds). One-in-100000 requests pays the cost.</li>
        <li><strong style="color:#fff">Mitigation:</strong> configurable via <code>ZEALPHP_MAX_REQUEST</code>
          (set to 0 to disable). Recycle is visible in the access log as
          <code>[recycle] worker N exited after K requests, peak RSS X MB, uptime Ys</code> so the backstop
          isn't invisible in production.</li>
      </ul>
    </div>

    <!-- ─── Closing ─── -->
    <div style="margin-top:3rem;padding:1.5rem;background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius)">
      <h2 style="font-size:1.15rem;margin-bottom:.75rem;color:#fff">The math</h2>
      <p style="color:var(--text-light);line-height:1.7;font-size:.92rem">
        At PHPStan's strictest level (10 on PHPStan 2.x), ZealPHP has <strong>75 documented ignore sites
        across <code>src/</code></strong>. ~57 are genuine architectural design choices (uopz / <code>__call</code> /
        reflection / dual-mode). ~18 are PHPStan / stub-mismatch limitations where the upstream type
        information is wrong.
      </p>
      <p style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem">
        For framework-level critique with version-by-version traces of how these trade-offs were
        discovered, justified, or fixed, see
        <a href="https://github.com/sibidharan/zealphp/blob/master/CRITIC.md" style="color:var(--accent)">CRITIC.md</a>.
        For the lesson learned from over-categorizing the level-1 ceiling as "deliberate trade-off"
        when most of it was actually unwritten annotations, see the "Cite numbers, not categories"
        entry at the bottom of that file.
      </p>
      <p style="color:var(--text-light);line-height:1.7;font-size:.92rem;margin-top:.5rem">
        Found a design tax we haven't documented?
        <a href="https://github.com/sibidharan/zealphp/issues" style="color:var(--accent)">Open an issue</a>.
        Each release in the v0.2.x line has been driven by public technical review.
      </p>
    </div>
  </div>
</section>
