<?php use ZealPHP\App; $active = $active ?? 'learn/mental-model'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 4,
      'title'    => 'The Mental Model',
      'subtitle' => 'Apache that drank coffee and decided to stay. The mental model in one lesson.',
      'prev'     => ['slug' => 'learn/first-page',       'title' => 'Your First Page'],
      'next'     => ['slug' => 'learn/project-structure','title' => 'Project Structure'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why traditional PHP throws everything away after each request — and what that costs you',
      'What stays warm in ZealPHP (and what doesn\'t)',
      'The four modes — selected with <code>App::mode()</code> — and which one you should use',
      'The Apache→ZealPHP swap table: what changes in your code, and what doesn\'t',
    ]]); ?>

    <h2>The fish problem</h2>
    <p>
      Traditional PHP is a goldfish. Every HTTP request, a brand-new fish is born — your autoloader
      boots, your config parses, your DB connection opens, your routing table compiles. The fish
      lives for 50 milliseconds, answers one request, and dies. The next request gets a new fish.
    </p>
    <p>
      This is beautiful in one way: no request can corrupt another. It is wasteful in every other way.
      You re-pay the boot cost on every request. You can’t hold an open WebSocket. You can’t
      share anything in memory. You can’t run a background task without a separate process. Apache
      and php-fpm built an entire ecosystem of side-cars — Redis, queue workers, supervisord,
      Nginx — to paper over this constraint.
    </p>
    <p>
      The constraint is the runtime model, not the language. PHP the language is perfectly capable of
      keeping a process alive. It just never did, because the request-per-process model became the
      default the same way QWERTY became the default — by being there first.
    </p>

    <h2>ZealPHP keeps the fish alive</h2>
    <p>
      ZealPHP runs on <strong>OpenSwoole</strong>, a PHP extension that gives PHP an event loop and an
      HTTP server. The process boots once. Workers fork. They live for thousands of requests. Your
      autoloader is hot. Your opcode cache is warm. Your routing table is already compiled. The
      request walks in, your handler runs, the response goes out. No teardown.
    </p>
    <p>What’s shared between requests (cheap):</p>
    <ul>
      <li>The Composer autoloader — class maps already resolved</li>
      <li>Opcode cache — your PHP files are already bytecode</li>
      <li>Class metadata — reflection results, attribute reads</li>
      <li>Route table, middleware stack — registered once at <code>$app-&gt;run()</code></li>
      <li><code>Store</code> tables and <code>Counter</code>s — shared memory across all workers</li>
      <li>WebSocket connections, timers, background coroutines</li>
    </ul>
    <p>What’s <em>not</em> shared between requests (intentionally isolated):</p>
    <ul>
      <li>The current request and response objects (a fresh <code>RequestContext</code> per request)</li>
      <li><code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code> — wired to the current request only</li>
      <li>Per-request session data — looked up by cookie, just like Apache</li>
    </ul>
    <p>
      The trick is making this work without you noticing. <code>header()</code>, <code>setcookie()</code>,
      <code>session_start()</code> — all the PHP built-ins you know — quietly route to
      <em>this</em> request’s response object, not a global one. You write code that looks like
      Apache PHP, but every primitive is per-request-aware under the hood.
    </p>

    <h2>The Apache→ZealPHP swap table</h2>
    <p>
      If you’ve written PHP on Apache or PHP-FPM, almost nothing changes in the code you write.
      What changes is what runs underneath it.
    </p>
    <div class="swap-table">
      <table class="cmp-table">
        <thead><tr><th>Thing</th><th>Apache / PHP-FPM</th><th>ZealPHP</th></tr></thead>
        <tbody>
          <tr><td>Routing</td><td><code>.htaccess</code> + <code>RewriteRule</code></td><td>File in <code>public/</code> or <code>$app-&gt;route()</code></td></tr>
          <tr><td>Sessions</td><td><code>session_start()</code></td><td><code>session_start()</code> — same call, same files</td></tr>
          <tr><td>Headers</td><td><code>header('X: Y')</code></td><td><code>header('X: Y')</code> — same call</td></tr>
          <tr><td>Templates</td><td>Plain <code>.php</code> files with <code>include</code></td><td>Plain <code>.php</code> files with <code>App::render()</code></td></tr>
          <tr><td>Database</td><td>PDO / mysqli</td><td>PDO / mysqli — unchanged</td></tr>
          <tr><td>WebSocket</td><td>Bolt on Node.js / Ratchet</td><td><code>$app-&gt;ws()</code> — same process</td></tr>
          <tr><td>Shared cache</td><td>Redis</td><td><code>Store::make()</code> — same process</td></tr>
          <tr><td>Background tasks</td><td>Cron, supervisord, queue worker</td><td><code>App::tick()</code>, task workers — same process</td></tr>
          <tr><td>Server</td><td>Apache + PHP-FPM + Nginx</td><td><code>php app.php</code></td></tr>
        </tbody>
      </table>
    </div>
    <p>
      The first three rows are the entire migration story for 80% of apps. <em>You don’t rewrite
      your code; you change what serves it.</em>
    </p>

    <h2 id="why-we-even-need-a-coroutine-mode">Why we even need a coroutine mode</h2>
    <p>
      The swap table is almost the whole story. The 20% it doesn’t cover is one specific category of
      bug, and it’s worth understanding before we describe the modes — because once you see it, the
      two modes read as <em>the answer</em>, not just a configuration option.
    </p>
    <p>
      Classic PHP gives you <code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code>,
      <code>$_SERVER</code>, <code>$_COOKIE</code>, <code>$_FILES</code> "for free". The SAPI
      (Apache, PHP-FPM) populates them at the start of each request, and the process dies at the
      end. There is no chance one request’s <code>$_GET</code> can leak into another, because
      there <em>is</em> no other request in this process — the next one gets a brand-new fish.
    </p>
    <p>
      OpenSwoole has no per-request SAPI. Workers live for thousands of requests. Two coroutines on
      the same worker share the same process address space — including any <code>$GLOBALS['_GET']</code>
      array sitting there. If a handler reads <code>$_GET['id']</code> while another coroutine just
      wrote a different <code>$_GET['id']</code>, you read the wrong one:
    </p>
    <pre class="mermaid">sequenceDiagram
    participant W as Worker (process)
    participant A as Coroutine A
    participant B as Coroutine B
    A->>W: $_GET = ['id' => 5]
    A-->>W: yield (await DB)
    B->>W: $_GET = ['id' => 9]
    Note over W: $_GET is process-wide<br/>both coroutines see it
    W-->>A: resume
    A->>A: read $_GET['id']  →  9  (B's data!)</pre>
    <p>
      That’s the trap. Naive PHP-FPM code that reads <code>$_GET</code> assuming "this is
      <em>my</em> request’s data" silently picks up another request’s data the moment two coroutines
      overlap. The bug is intermittent, traffic-dependent, and impossible to reproduce locally with
      one user clicking around — which is the worst kind of bug to debug in production.
    </p>
    <p><strong>ZealPHP solves it with four different modes</strong> (configured via <code>App::mode()</code> or the <code>App::MODE_*</code> constants):</p>
    <ul>
      <li>
        <strong>Coroutine mode (<code>App::MODE_COROUTINE</code>).</strong> The recommended default for new apps. The
        superglobals are simply <em>not populated</em>. Use <code>$g->get</code>, <code>$g->post</code>,
        <code>$g->cookie</code>, <code>$g->server</code>, <code>$g->files</code> instead — these live on
        the coroutine’s own context object via <code>Coroutine::getContext()</code>, so concurrent
        coroutines can’t see each other’s state. Reading <code>$_GET['id']</code> in coroutine mode
        returns <code>null</code>, which is loud and obviously wrong — not silently-wrong like a race.
      </li>
      <li>
        <strong>Coroutine-Legacy mode (<code>App::MODE_COROUTINE_LEGACY</code>).</strong> <strong>Experimental, but incredibly powerful!</strong>
        It keeps real <code>$_GET</code>, <code>$_POST</code>, and friends populated per request <em>and</em> runs concurrently.
        It isolates the seven superglobals, <code>$GLOBALS</code>, and function statics per coroutine via <strong>ext-zealphp</strong>.
        This gives you the ultimate superpower: <em>you can just write classic PHP syntax</em>, but enjoy the blistering concurrency and performance of coroutines.
      </li>
      <li>
        <strong>Mixed mode (<code>App::MODE_MIXED</code>).</strong> The in-process drop-in PHP-FPM equivalent.
        Native <code>$_GET</code>/<code>$_SESSION</code> are populated normally, handling one request at a time per warm worker.
        No fastCGI hop, no separate web server. Safe, traditional, shared-nothing per request.
      </li>
      <li>
        <strong>Legacy CGI mode (<code>App::MODE_LEGACY_CGI</code>).</strong> Dispatch through a warm, pre-spawned PHP worker pool (fork, pool, proc, fcgi).
        True isolated address spaces, resident between requests (~1-3 ms warm). It's designed so <code>define()</code>-heavy code like
        unmodified WordPress or Drupal — any code base whose mental model is "I am the only PHP script running" — works without a line changing.
      </li>
    </ul>
    <p>
      Sessions are the one exception that works in <em>all</em> modes via <code>$_SESSION</code> as
      well as <code>$g->session</code>. The framework intercepts every <code>session_*()</code> call
      via ext-zealphp at startup so legacy <code>session_start()</code> code routes to a per-request session
      bag instead of a shared global. See <a href="/learn/sessions">Lesson 16: Sessions</a> for the
      mechanics.
    </p>

    <h2>The four modes</h2>
    <p>
      ZealPHP has four runtime modes, all selected with one call to <code>App::mode()</code> using the <code>App::MODE_*</code> constants.
      New apps default to <strong>coroutine mode</strong> (<code>App::MODE_COROUTINE</code>) —
      that’s the one the framework demo and every <code>composer create-project</code> scaffold ship
      with.
    </p>
    <div class="lmm-modes" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
      <div>
        <h4 class="lmm-mode-title lmm-mode-title-coro">1. Coroutine Mode</h4>
        <pre class="mermaid">graph TD
    R[Request] --> CO[Coroutine spawned]
    CO --> CTX["RequestContext on coroutine context"]
    CTX --> H[Your handler]
    H --> RES[Response]
    style CO fill:#fffbeb,stroke:#f59e0b,stroke-width:2px
    style CTX fill:#ecfdf5,stroke:#059669</pre>
        <p class="lmm-mode-caption">Per-coroutine state · concurrent · isolated</p>
      </div>
      <div>
        <h4 class="lmm-mode-title lmm-mode-title-super">2. Coroutine-Legacy Mode</h4>
        <pre class="mermaid">graph TD
    R[Request] --> CO[Coroutine spawned]
    CO --> CTX["ext-zealphp isolates global state"]
    CTX --> H["Classic PHP syntax ($_GET, global $x)"]
    H --> RES[Response]
    style CO fill:#fffbeb,stroke:#f59e0b,stroke-width:2px
    style CTX fill:#ecfdf5,stroke:#059669
    style H fill:#fef2f2,stroke:#f87171</pre>
        <p class="lmm-mode-caption">Concurrent · Classic PHP syntax powered by ext-zealphp</p>
      </div>
      <div style="margin-top: 1rem;">
        <h4 class="lmm-mode-title lmm-mode-title-super">3. Mixed Mode</h4>
        <pre class="mermaid">graph TD
    R[Request] --> W[Warm Worker Process]
    W --> G["true $_GET, $_POST, $_SESSION"]
    G --> H[Legacy PHP code]
    H --> RES[Response]
    style W fill:#fef2f2,stroke:#f87171
    style G fill:#fef2f2,stroke:#f87171</pre>
        <p class="lmm-mode-caption">In-process sequential · PHP-FPM drop-in</p>
      </div>
      <div style="margin-top: 1rem;">
        <h4 class="lmm-mode-title lmm-mode-title-super">4. Legacy-CGI Mode</h4>
        <pre class="mermaid">graph TD
    R[Request] --> CGI[Pre-warmed subprocess pool]
    CGI --> G["true $_GET, $_POST, $_SESSION"]
    G --> H[Legacy PHP code]
    H --> RES[Response]
    style CGI fill:#fef2f2,stroke:#f87171
    style G fill:#fef2f2,stroke:#f87171</pre>
        <p class="lmm-mode-caption">Pooled CGI worker · WordPress / Drupal compatibility</p>
      </div>
    </div>
    <p>
      <strong>Coroutine mode</strong> is faster, cleaner (no global state leaking between requests), and lets you
      use real concurrency inside a request — fan out three DB queries in parallel, wait on all
      three, return the result.
    </p>
    <p>
      <strong>Coroutine-Legacy mode</strong> (experimental) is the superpower. It gives you the blistering concurrent
      performance of coroutines but lets you <em>write classic PHP syntax</em>. You just write <code>$_GET</code>,
      <code>$_POST</code>, and <code>global $x</code>, and the C-extension isolates them per-coroutine for you! We will show both side by side in the lessons.
    </p>
    <p>
      <strong>Mixed mode</strong> keeps native <code>$_GET</code>/<code>$_SESSION</code> by handling one request
      at a time per warm worker in-process. <strong>Legacy-CGI mode</strong> adds full process isolation
      by dispatching each <code>.php</code> through a warm, pre-spawned worker pool (fork/pool/proc/fcgi).
      These are your bridges for migrating legacy apps without rewriting them.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Which mode is this app in?',
      'body'    => '<p>Open <code>app.php</code>. Look near the top — if you see <code>App::mode(App::MODE_COROUTINE)</code>, you’re in coroutine mode. To write classic PHP syntax but keep coroutine concurrency, switch to <code>App::mode(App::MODE_COROUTINE_LEGACY)</code>. To run legacy code completely unchanged, switch to <code>App::MODE_MIXED</code> or <code>App::MODE_LEGACY_CGI</code>. ZealPHP takes care of the rest.</p>',
    ]); ?>

    <h2>What this means for you, in practice</h2>
    <p>
      Three things change once you internalize this model:
    </p>
    <ol>
      <li>
        <strong>You don’t pay boot cost per request.</strong> A 50 ms autoloader walk
        doesn’t happen 1,000 times a second — it happens once per worker, at startup.
        Your routes feel instant because the framework has nothing to set up before calling your
        handler.
      </li>
      <li>
        <strong>You can share memory.</strong> The next request from the same user, or any user, can
        read what the previous one wrote — through <code>Store::make()</code> or
        <code>Counter</code>. No Redis. No serialization round-trip. Just a typed table that lives in
        shared memory.
      </li>
      <li>
        <strong>You think twice about globals.</strong> Mutating a static class property in one
        request will leak into the next request handled by the same worker. ZealPHP boxes
        <code>$_GET</code> and friends per-request, but it doesn’t police your own static state.
        The rule is: anything request-specific goes in <code>$g</code> (RequestContext) or as handler
        parameters. Static state is for config, not data.
      </li>
    </ol>
    <p>
      Everything else — your routes, templates, sessions, error pages, PDO calls — is the
      same PHP you already write. ZealPHP modernizes the runtime; it doesn’t modernize you.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'mental1',
      'question' => 'You set a static class property in one request handler. The next request hits the same worker. What does it see?',
      'correct'  => 'b',
      'explain'  => 'Workers persist across requests, so static class properties survive between requests handled by the same worker. That\'s why ZealPHP keeps per-request state in $g (RequestContext) — never in static class properties or globals you control.',
      'options'  => [
        'a' => 'A fresh, uninitialized value (PHP re-initializes statics per request).',
        'b' => 'The value the previous request left there.',
        'c' => 'It depends on your current <code>App::mode()</code>.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Traditional PHP throws away the entire runtime after each request. ZealPHP keeps it.',
      'Workers boot once, live for thousands of requests, and isolate per-request state via <code>RequestContext</code>.',
      'Headers, cookies, sessions, and templates work exactly like Apache PHP — the framework wires them to the right request.',
      'Coroutine mode is the default for new apps. Coroutine-legacy mode lets you keep classic PHP syntax with blistering concurrency.',
      'Anything request-specific belongs in <code>$g</code> or handler parameters — never in your own static class state.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/first-page"
         hx-get="/api/learn/page?slug=learn/first-page" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/first-page">← Your First Page</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/project-structure"
         hx-get="/api/learn/page?slug=learn/project-structure" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/project-structure">Project Structure →</a>
    </div>
  </article>
</div>
