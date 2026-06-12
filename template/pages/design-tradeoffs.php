<?php use ZealPHP\App; ?>

<section class="section tradeoffs-section">
  <div class="container tradeoffs-container">
    <h1 class="section-title tradeoffs-title">Design Trade-offs</h1>
    <p class="section-desc tradeoffs-desc">
      Every framework makes choices that cost something. Most don't show their math.
      This page is the math — what we chose, what each choice costs you,
      and what mitigation exists. Brutal honesty over marketing copy.
    </p>

    <div class="tradeoffs-reading">
      <strong class="tradeoffs-strong-light">Reading this page:</strong> each section follows the same shape —
      <em>what we chose</em>, <em>what it costs you</em>, <em>what mitigates it</em>.
      Code links point to <code>src/</code> on GitHub.
      For the version-by-version trace of how we got here, see
      <a href="https://github.com/sibidharan/zealphp/blob/master/CRITIC.md" class="tradeoffs-link">CRITIC.md</a>.
    </div>

    <!-- ─── ext-zealphp function overrides ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">1. ext-zealphp: function overrides on PHP built-ins</h2>
      <p class="tradeoffs-p">
        At server boot, ZealPHP uses <code>ext-zealphp</code> (our own C extension) to
        permanently replace PHP built-ins like <code>header()</code>, <code>setcookie()</code>,
        <code>session_start()</code>, <code>set_error_handler()</code>, <code>http_response_code()</code>.
        Calls flow into per-request objects (<code>$g->zealphp_response</code>, <code>$g->session</code>)
        instead of the global PHP state that mod_php and FPM rely on.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> unmodified PHP-FPM-era code works. Legacy
          libraries that call <code>session_start()</code> just work; you don't rewrite them.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> requires the <code>ext-zealphp</code>
          extension at install time (<code>pie install zealphp/ext</code> or build from source).
          PHPStan can't see through the redirection &mdash; it thinks <code>header()</code> writes to a global table,
          when at runtime it writes to <code>$response->headersList</code>.</li>
        <li><strong class="tradeoffs-strong-light">Design:</strong> allowlist-only (53 functions), no class manipulation,
          no constant overrides, no general-purpose API. <code>MSHUTDOWN</code> auto-restores all originals.
          The legacy <code>uopz</code> extension is supported as a fallback for existing installs.</li>
      </ul>
    </div>

    <!-- ─── Dual-mode runtime ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">2. Dual-mode runtime: coroutine vs superglobals</h2>
      <p class="tradeoffs-p">
        <code>App::superglobals(false)</code> (recommended default) uses per-coroutine state via
        <code>$g-&gt;get</code> / <code>$g-&gt;session</code> (<code>Coroutine::getContext()</code>).
        <code>App::superglobals(true)</code> populates <code>$_GET</code>/<code>$_POST</code>/<code>$_SESSION</code>
        per request &mdash; with ext-zealphp, these are <strong>per-coroutine safe</strong> (saved/restored
        on every yield/resume — S1), so legacy code works with full coroutine concurrency. Without ext-zealphp,
        superglobals mode runs sequentially (one request at a time per worker). The lifecycle is now
        described by two orthogonal axes with a one-call preset:
        <code>App::mode()</code> (constants: <code>App::MODE_COROUTINE</code>,
        <code>App::MODE_LEGACY_CGI</code>, <code>App::MODE_COROUTINE_LEGACY</code>,
        <code>App::MODE_MIXED</code>) sets both axes in one call.
        <code>App::isolation()</code> (constants: <code>ISOLATION_COROUTINE</code>,
        <code>ISOLATION_CGI_POOL</code>, <code>ISOLATION_CGI_PROC</code>,
        <code>ISOLATION_CGI_FCGI</code>, <code>ISOLATION_NONE</code>) folds the
        <code>processIsolation()</code> / <code>enableCoroutine()</code> / <code>hookAll()</code> /
        <code>cgiMode()</code> cross-product into one value. The fine-grained setters still work
        unchanged underneath. See
        <a href="/coroutines#lifecycle-modes" class="tradeoffs-link">/coroutines#lifecycle-modes</a>
        for the full preset matrix and per-mode safety guarantees.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> greenfield projects get coroutines (thousands
          of concurrent requests per worker); legacy migrations get a single-runtime path with no rewrite; the
          four-knob split lets one binary serve a "supported mode matrix" (coroutine, legacy CGI, mixed-mode/Symfony,
          coroutine-without-hooks) instead of a single take-it-or-leave-it switch.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> multiple code paths means multiple surfaces
          for bugs. A mode-specific bug only fires under one config. Documentation has to explicitly mark which
          mode each guarantee applies to.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> coroutine mode is the documented default for
          new projects (scaffold ships it). With <code>ext-zealphp</code> (v0.3.0+), <strong>all mode
          combinations are safe</strong> &mdash; the extension provides per-coroutine superglobal
          save/restore (S1), so <code>superglobals(true) + enableCoroutine(true)</code> just works.
          Without ext-zealphp, the legacy constraint applies: unsafe combinations throw
          <code>RuntimeException</code> at boot. The
          <a href="/coroutines" class="tradeoffs-link">/coroutines</a> page has a side-by-side safety
          matrix per mode. Most users never touch any flag.</li>
      </ul>
    </div>

    <!-- ─── __call proxies ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">3. <code>__call</code> proxies on HTTP wrappers</h2>
      <p class="tradeoffs-p">
        <code>ZealPHP\HTTP\Request</code> and <code>ZealPHP\HTTP\Response</code> wrap OpenSwoole's underlying
        request/response. Both expose <code>__call($name, $arguments)</code> so any method we haven't
        explicitly forwarded (and any future OpenSwoole-added method) is automatically proxied.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> upstream OpenSwoole versions don't require a
          framework release to expose new methods. <code>$response->newMethodFromOpenSwoole25()</code> just
          works.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> PHPStan sees <code>call_user_func_array</code>
          returning <code>mixed</code>. Every caller of a proxied method gets a mixed-type alarm at level 9+.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> class-level <code>@method</code> PHPDoc on
          <code>HTTP/Request.php</code> and <code>HTTP/Response.php</code> declares the proxied signatures for the
          common methods (<code>isWritable</code>, <code>write</code>, <code>sendfile</code>, <code>getContent</code>, etc.) —
          PHPStan resolves these statically. The remaining proxy fallback has 2 inline ignores. Adding a new
          frequently-called method to the <code>@method</code> block eliminates more.</li>
      </ul>
    </div>

    <!-- ─── Reflection injection ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">4. Reflection-based route parameter injection</h2>
      <p class="tradeoffs-p">
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
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> ergonomics. Handlers feel like Express / Flask —
          no DI container, no annotations, no <code>$request</code>-as-first-param convention to remember.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> handler signatures are unknown at static-analysis
          time. PHPStan can't tell that the closure takes <code>(string, Request, App)</code> from the route table.
          Reflection has a per-call cost too.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> the param map is built via <code>ReflectionFunction</code>
          once at route registration time (<code>App::buildParamMap()</code>) — zero per-request reflection. The
          dispatcher reads from the pre-built map. PHPStan ignores at the dispatch sites are documented as
          "handler param type known only at route binding."</li>
      </ul>
    </div>

    <!-- ─── CGI bridge ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">5. CGI bridge for legacy apps and non-PHP scripts</h2>
      <p class="tradeoffs-p">
        ZealPHP ships a genuine Apache-<code>mod_cgi</code>-class bridge, not a toy fork-per-request. It runs
        legacy PHP <em>and</em> non-PHP scripts (Python, Perl, anything with a shebang or interpreter) with a
        full RFC 3875 CGI/1.1 environment, then stitches their output into the OpenSwoole response. The
        executed script's return value (for PHP) flows back through the
        <a href="/responses#return-contract" class="tradeoffs-link">universal return contract</a>. Three things
        make it real:
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">Four dispatch modes</strong> (<code>App::cgiMode()</code>):
          <code>'pool'</code> (default) uses a warm, pre-spawned PHP worker pool — the interpreter stays
          resident in memory, mod_php-style isolation, ~1&#8211;3&nbsp;ms per request, configurable via
          <code>cgiPoolSize()</code> / <code>cgiPoolMaxRequests()</code>; <code>'proc'</code> spawns a fresh
          <code>proc_open</code> subprocess per request (~30&#8211;50&nbsp;ms cold start — recursion-safe fallback
          for cases where fresh-process semantics are needed without a pre-spawned pool);
          <code>'fork'</code> (<strong>experimental</strong>) is an Apache MPM prefork runner — a long-lived
          fork-master forks a fresh child per request at true global scope (~1&nbsp;ms fork cost, requires
          <code>pcntl</code> + <code>posix</code>), giving unmodified-WordPress correctness without the
          <code>proc_open</code> cold-start tax; <code>'fcgi'</code>
          forwards to an external php-fpm / FastCGI pool via the bundled <code>FastCgiClient</code> (no per-request
          spawn at all). Per-extension backends register via <code>App::registerCgiBackend('.py', &hellip;)</code>.</li>
        <li><strong class="tradeoffs-strong-light">ScriptAlias + ExecCGI scope.</strong>
          <code>App::cgiScriptAlias('/cgi-bin', &hellip;)</code> (Apache <code>ScriptAlias</code> parity) and per-backend
          <code>exec_paths</code> (Apache <code>ExecCGI</code> parity) gate <em>which</em> URLs may execute. A stray
          script outside its declared exec scope is <strong>neither executed nor leaked as source &mdash; it returns
          <code>403</code></strong> (the security hole Apache leaves when <code>ExecCGI</code> is off).</li>
        <li><strong class="tradeoffs-strong-light">URL parity.</strong> <code>GET /cgi-bin/report.py</code> runs the
          script &mdash; implicit routes are auto-registered for every CGI extension, registered <em>before</em> the
          generic public-file routes so they win. Full RFC 3875 env (httpoxy-stripped: the client
          <code>Proxy:</code> header never reaches <code>HTTP_PROXY</code>), POST body piped to script stdin,
          <code>Status:</code>-header parsing, streaming / SSE pass-through, and a <code>CGIScriptTimeout</code>
          (<code>App::$cgi_timeout</code>, Apache parity).</li>
      </ul>
      <p class="tradeoffs-p-sm">
        Beyond the file bridge, <code>App::exec()</code> runs shell commands coroutine-safely through
        <code>OpenSwoole\Coroutine\System::exec()</code> (yields to the scheduler instead of blocking the worker),
        and a transparent ext-zealphp override of the <code>shell_exec</code> / <code>exec</code> / <code>system</code> /
        <code>passthru</code> family (and the backtick operator, which compiles to <code>shell_exec</code>) routes
        legacy/user code through it with zero source changes (on by default in coroutine mode,
        <code>App::hookExec()</code> to override).
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> the last mile of migration runs unmodified.
          <code>App::setFallback(fn() =&gt; App::include('/index.php'))</code> serves WordPress as-is; a polyglot
          <code>/cgi-bin</code> of Python/Perl reports runs without a separate web server; and <code>'fcgi'</code> mode
          lets ZealPHP front an existing php-fpm pool with no fork cost at all.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> the bridge is real maintenance surface
          (<code>src/cgi_worker.php</code> + the CGI env/dispatch glue in <code>src/App.php</code>).
          The warm <code>'pool'</code> default adds ~1&#8211;3&nbsp;ms dispatch overhead vs. a native route &mdash;
          the interpreter stays resident, so there's no per-request startup tax. If you'd rather front an
          existing php-fpm pool, <code>'fcgi'</code> mode forwards to it with zero spawn cost.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> CGI-script execution (non-PHP via
          ScriptAlias / registered extensions) works in <em>any</em> lifecycle mode &mdash; it isn't bolted to
          <code>processIsolation</code>. For PHP, <code>.php</code> only goes through the subprocess in isolation mode;
          in coroutine mode it runs in-process at full speed. Use <code>'pool'</code> (the default warm path)
          for legacy PHP isolation, <code>'fcgi'</code> to front an existing php-fpm pool with zero spawn cost,
          and keep native routes for everything you've already modernized &mdash; use the bridge for the
          legacy slice you can't rewrite yet.</li>
      </ul>
    </div>

    <!-- ─── RequestContext (G) ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">6. <code>RequestContext</code> (formerly <code>G</code>) — per-coroutine, looks like a god object</h2>
      <p class="tradeoffs-p">
        <code>RequestContext::instance()</code> returns a per-request state container holding
        <code>$server</code>, <code>$get</code>, <code>$post</code>, <code>$cookie</code>,
        <code>$session</code>, <code>$zealphp_request</code>, <code>$zealphp_response</code>, and the rest. In
        coroutine mode it's stored in <code>Coroutine::getContext()</code> — one instance per coroutine,
        isolated. In superglobals mode it's a process singleton bridging to <code>$_GET</code> / <code>$_POST</code> / <code>$_SESSION</code>.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> a single named object for every per-request
          concern. Same shape across modes. Hyperf and Slim's <code>$_REQUEST</code>-style globals follow the
          same pattern.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> looks like a god object on first read. Critics
          flag it before realizing that in coroutine mode it's <em>per-coroutine</em>, not process-wide.
          Frontend devs accustomed to React contexts have to map the mental model.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> strict <code>__set</code> rejects undeclared property
          writes in coroutine mode (typos surface immediately, not 200 requests later). <code>RequestContext::once($key, $fn)</code>
          gives a safe alternative to <code>static $cache = []</code> for user code. The
          <a href="/coroutines" class="tradeoffs-link">/coroutines</a> docs page maps the isolation
          contract per mode.</li>
      </ul>
    </div>

    <!-- ─── Discipline contract ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">7. The discipline contract for user-level statics</h2>
      <p class="tradeoffs-p">
        ZealPHP isolates the state <em>it</em> owns (request, response, session, $_SERVER — S1) per coroutine. It
        does <strong>not</strong> isolate <code>static $cache = []</code> inside your handler, or
        <code>private static $instance</code> on your singleton class. Those live in worker process memory and
        survive every request boundary.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> the framework doesn't pay the cost of mediating
          every user-level static-property access. Hyperf, RoadRunner, and Laravel Octane all draw this line
          the same way.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> a developer used to PHP-FPM (where every static
          dies at request end) can ship code that leaks across requests without noticing — until production
          memory creeps up.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> <code>max_request=100000</code> default in
          <code>App::run()</code> (configurable via <code>ZEALPHP_MAX_REQUEST</code>) recycles workers
          periodically. Worker-recycle access-log line surfaces the recycling in production. Opt-in
          <code>IniIsolationMiddleware</code> snapshots <code>ini_set()</code> changes per request. The
          <code>RequestContext::once()</code> helper gives you a safe primitive for memoization without
          touching <code>static</code>.</li>
      </ul>
    </div>

    <!-- ─── OpenSwoole stubs ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">8. OpenSwoole / ext-posix stub mismatches</h2>
      <p class="tradeoffs-p">
        <code>openswoole/ide-helper</code> and PHPStan's bundled ext stubs sometimes declare types differently
        from what the real C extension does. Examples: <code>OpenSwoole\Runtime::enableCoroutine()</code> says
        <code>bool</code>, the ext takes int flags (<code>HOOK_ALL</code> etc.). <code>posix_kill()</code> says
        always-true, the ext returns false on dead PIDs (which is exactly what the polling code is checking
        for). <code>ceil()</code> stubs widen the return to <code>float</code> when PHP 8.0+ returns int.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> IDE autocomplete still works.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> PHPStan reports errors we can't fix in our code
          (the stubs are upstream and wrong).</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> 9 targeted <code>ignoreErrors</code> patterns in
          <code>phpstan.neon</code>, each with a <code># reason:</code> comment explaining the specific stub-vs-ext
          mismatch. Scoped tightly to the affected file + error pattern so future real bugs aren't swallowed.</li>
      </ul>
    </div>

    <!-- ─── PHPStan 75 ignore sites ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">9. 75 inline PHPStan ignore-with-reason sites</h2>
      <p class="tradeoffs-p">
        ZealPHP passes PHPStan level 10 (the strictest tier, what Symfony 8+ and Laravel 12+ score at) with
        zero errors. It also has <strong>75 inline <code>@phpstan-ignore-next-line</code> annotations</strong>
        across <code>src/</code>, each annotated with a one-line reason for the design choice that makes that
        site unverifiable statically.
      </p>
      <p class="tradeoffs-p-sm">
        Categories of those 75:
      </p>
      <ul class="tradeoffs-list">
        <li>Sections 1, 3, 4, 6, 7, 8 above account for the vast majority.</li>
        <li>The rest are array-shape boundaries where mixed flows through user-controlled session/request
          keys and gets coerced to string/int at the boundary (<code>(string)$g->session['user_id']</code>) —
          PHPStan can't prove these are safe statically; the boundary cast is the runtime contract.</li>
      </ul>
      <p class="tradeoffs-p-sm">
        Run <code>grep -rn '@phpstan-ignore' src/</code> in a clone to see every site. Each one has the form
        <code>// @phpstan-ignore-next-line — &lt;reason&gt;</code>. No bare ignores.
      </p>
    </div>

    <!-- ─── max_request worker recycling ─── -->
    <div class="tradeoffs-block">
      <h2 class="tradeoffs-h2">10. <code>max_request</code> worker recycling</h2>
      <p class="tradeoffs-p">
        Workers are recycled every <code>max_request=100000</code> requests by default. After that count
        OpenSwoole gracefully exits the worker and forks a fresh one.
      </p>
      <ul class="tradeoffs-list">
        <li><strong class="tradeoffs-strong-light">What it buys:</strong> bounds PHP-engine state accumulation. Static
          caches in user code, leaky extension state, accidental memory ballooning — all of it caps out.
          Required for honest long-running-PHP claims.</li>
        <li><strong class="tradeoffs-strong-light">What it costs:</strong> the request that triggers the recycle pays a
          fork latency hit (~milliseconds). One-in-100000 requests pays the cost.</li>
        <li><strong class="tradeoffs-strong-light">Mitigation:</strong> configurable via <code>ZEALPHP_MAX_REQUEST</code>
          (set to 0 to disable). Recycle is visible in the access log as
          <code>[recycle] worker N exited after K requests, peak RSS X MB, uptime Ys</code> so the backstop
          isn't invisible in production.</li>
      </ul>
    </div>

    <!-- ─── Closing ─── -->
    <div class="tradeoffs-closing">
      <h2 class="tradeoffs-closing-h2">The math</h2>
      <p class="tradeoffs-closing-p">
        At PHPStan's strictest level (10 on PHPStan 2.x), ZealPHP has <strong>75 documented ignore sites
        across <code>src/</code></strong>. ~57 are genuine architectural design choices (ext-zealphp / <code>__call</code> /
        reflection / dual-mode). ~18 are PHPStan / stub-mismatch limitations where the upstream type
        information is wrong.
      </p>
      <p class="tradeoffs-closing-p-sm">
        For framework-level critique with version-by-version traces of how these trade-offs were
        discovered, justified, or fixed, see
        <a href="https://github.com/sibidharan/zealphp/blob/master/CRITIC.md" class="tradeoffs-link">CRITIC.md</a>.
      </p>
      <p class="tradeoffs-closing-p-sm">
        Found a design tax we haven't documented?
        <a href="https://github.com/sibidharan/zealphp/issues" class="tradeoffs-link">Open an issue</a>.
        Each release in the v0.2.x line has been driven by public technical review.
      </p>
    </div>
  </div>
</section>
