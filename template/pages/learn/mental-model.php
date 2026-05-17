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
      'The two modes — superglobals vs coroutine — and which one you should use',
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

    <h2>The two modes</h2>
    <p>
      ZealPHP has two runtime modes. New apps default to <strong>coroutine mode</strong> —
      that’s the one your <code>app.php</code> declares with <code>App::superglobals(false)</code>.
      The other one, <strong>superglobals mode</strong>, exists for one reason: to run unmodified
      WordPress or Drupal without touching their source.
    </p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1.25rem 0">
      <div>
        <h4 style="text-align:center;margin:0 0 .5rem;color:var(--accent,#f59e0b);font-size:.82rem;text-transform:uppercase;letter-spacing:.05em">Coroutine mode (default)</h4>
        <pre class="mermaid">graph TD
    R[Request] --> CO[Coroutine spawned]
    CO --> CTX["RequestContext on coroutine context"]
    CTX --> H[Your handler]
    H --> RES[Response]
    style CO fill:#fffbeb,stroke:#f59e0b,stroke-width:2px
    style CTX fill:#ecfdf5,stroke:#059669</pre>
        <p style="text-align:center;color:#78716c;font-size:.82rem;margin:.35rem 0 0">Per-coroutine state · concurrent · isolated</p>
      </div>
      <div>
        <h4 style="text-align:center;margin:0 0 .5rem;color:#78716c;font-size:.82rem;text-transform:uppercase;letter-spacing:.05em">Superglobals mode</h4>
        <pre class="mermaid">graph TD
    R[Request] --> CGI[CGI subprocess]
    CGI --> G["true $_GET, $_POST, $_SESSION"]
    G --> H[Legacy PHP code]
    H --> RES[Response]
    style CGI fill:#fef2f2,stroke:#f87171
    style G fill:#fef2f2,stroke:#f87171</pre>
        <p style="text-align:center;color:#78716c;font-size:.82rem;margin:.35rem 0 0">Per-request fork · WordPress / Drupal compatibility</p>
      </div>
    </div>
    <p>
      Coroutine mode is the one you want for new code. It’s faster (no fork per request),
      cleaner (no global state leaking between requests), and the only mode where you get to use
      real concurrency inside a request — fan out three DB queries in parallel, wait on all
      three, return the result.
    </p>
    <p>
      Superglobals mode forks a CGI worker per request, so it pays a millisecond or two of overhead.
      In return, <em>any</em> PHP code that mutates <code>$_SESSION</code> directly, sets globals,
      modifies <code>ini_set()</code>, or assumes “this script runs alone” works unchanged.
      It’s the bridge for migrating legacy apps without rewriting them.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Which mode is this app in?',
      'body'    => '<p>Open <code>app.php</code>. Look near the top — if you see <code>App::superglobals(false)</code>, you’re in coroutine mode (this is the default for the framework demo and any <code>composer create-project</code> scaffold). To run legacy code unchanged, flip to <code>App::superglobals(true)</code> and drop the file into <code>public/</code>. ZealPHP takes care of the rest.</p>',
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
        'c' => 'It depends on whether <code>App::superglobals(false)</code> is set.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Traditional PHP throws away the entire runtime after each request. ZealPHP keeps it.',
      'Workers boot once, live for thousands of requests, and isolate per-request state via <code>RequestContext</code>.',
      'Headers, cookies, sessions, and templates work exactly like Apache PHP — the framework wires them to the right request.',
      'Coroutine mode is the default for new apps. Superglobals mode exists to run legacy code unchanged.',
      'Anything request-specific belongs in <code>$g</code> or handler parameters — never in your own static class state.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/first-page"
         hx-get="/api/learn/page?slug=learn/first-page" hx-target=".lesson-content"
         hx-swap="outerHTML show:.lesson-content:top" hx-push-url="/learn/first-page">← Your First Page</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/project-structure"
         hx-get="/api/learn/page?slug=learn/project-structure" hx-target=".lesson-content"
         hx-swap="outerHTML show:.lesson-content:top" hx-push-url="/learn/project-structure">Project Structure →</a>
    </div>
  </article>
</div>
