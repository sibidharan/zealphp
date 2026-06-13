<?php use ZealPHP\App; $active = $active ?? 'learn/sessions'; $g = \ZealPHP\G::instance(); ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 16,
      'title'    => 'Sessions',
      'subtitle' => 'Build a feature that survives a reload. Build it once; it works in two tabs.',
      'prev'     => ['slug' => 'learn/htmx', 'title' => 'Forms & htmx'],
      'next'     => ['slug' => 'learn/auth', 'title' => 'User Accounts'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'How a server remembers something about a returning visitor — and what cookies have to do with it',
      'Write to <code>$_SESSION</code>, read it back next request',
      'Where ZealPHP actually keeps the data on disk (you can <code>ls</code> it)',
      '<code>$_SESSION</code> vs <code>$g-&gt;session</code> — same data, two access points, one reason',
    ]]); ?>

    <h2>What you're building</h2>
    <p>
      A tiny session-backed feature: a counter that <strong>survives a reload</strong> and
      <strong>follows you across tabs</strong>. Hit the button, see it go up. Open another tab, see
      the same number. Close the browser and come back — still the same number. All with no
      database, no Redis on a single node, no JavaScript state — just a session.
    </p>

    <?php App::render('/components/_tryit', [
      'title' => 'Live demo: a session-backed counter (cross-tab synced)',
      'body'  => '<div class="lsess-center">' .
                 App::renderToString('/components/_session_counter', ['n' => (int)($g->session['lesson_counter'] ?? 0)]) .
                 '<div data-session-counter-status class="ws-counter-status lsess-counter-status">starting…</div>' .
                 '<p class="lsess-popup-line">' .
                 '<a href="/demo/view/sessions/counter" target="_blank" rel="opener">Open this counter in a popup &rarr;</a>' .
                 ' &middot; open it in multiple windows and watch them all update from any click.' .
                 '</p>' .
                 '</div>' .
                 '<p>htmx posts <code>+1</code> from the clicking tab. The server increments the session counter and broadcasts the new HTML over a WebSocket scoped to your <code>PHPSESSID</code> (<a href="/learn/websocket">Lesson 19, Real-Time Sync</a> teaches the broadcast pattern). Every other tab in this browser receives the broadcast and swaps in the same button. Reload, close the browser, come back — the number persists because the session file does.</p>',
    ]); ?>

    <h2>Step 1 — HTTP doesn't remember anything</h2>
    <pre class="mermaid">sequenceDiagram
    participant B as Browser
    participant SW as ZealPHP
    participant FS as Session file on disk
    Note over B: First visit, no cookie
    B->>SW: GET /page
    SW->>SW: session_start(), mint new id
    SW->>FS: create sess_abc123
    SW-->>B: response + Set-Cookie PHPSESSID
    Note over B: Second visit, cookie present
    B->>SW: GET /page + Cookie PHPSESSID
    SW->>FS: read sess_abc123
    FS-->>SW: lesson_counter = 5
    SW->>SW: handler increments to 6
    SW->>FS: write sess_abc123
    SW-->>B: response (counter shows 6)</pre>
    <p>
      Each HTTP request stands alone. The server doesn&rsquo;t know that <em>you</em> are the same
      visitor who reloaded the page two seconds ago — it just sees a new request. So how does the
      counter above stay at the same value across reloads?
    </p>
    <p>
      Two pieces. First, your browser sends a <strong>cookie</strong> on every request:
      <code>PHPSESSID</code>. It&rsquo;s a random string the server picked the first time you visited
      and asked the browser to remember. Open DevTools → Application → Cookies and you&rsquo;ll see
      yours. Second, the server keeps a <strong>file</strong> indexed by that random string with
      whatever data it wants to remember about you.
    </p>
    <p>
      Cookie + file. That&rsquo;s the entire mechanism.
    </p>

    <h2>Step 2 — Write to the session</h2>
    <p>
      The route that powers the <code>+1</code> button above is four lines:
    </p>
    <pre><code class="language-php">// route/learn.php (excerpt)
$app-&gt;route('/api/learn/demo/session-bump', ['methods' =&gt; ['POST']], function () {
    $g = G::instance();
    $g-&gt;session['lesson_counter'] = ($g-&gt;session['lesson_counter'] ?? 0) + 1;
    return (string) $g-&gt;session['lesson_counter'];   // returned to htmx as text
});</code></pre>
    <p>
      Read the current value (default 0), add 1, store it back. The framework saves the session
      automatically at the end of the request. The next request from the same browser — same
      <code>PHPSESSID</code> cookie — gets the new value.
    </p>

    <h2>Step 3 — Where the data actually lives</h2>
    <p>
      ZealPHP stores session files on disk by default. You can list them:
    </p>
    <pre><code class="language-bash">$ ls -la /var/lib/php/sessions/ | head -5
sess_ab12cd34ef56gh78ij9kl0mnopqrst
sess_qrst7890uvwxyz1234567890abcdef
...

$ cat /var/lib/php/sessions/sess_ab12cd34...
lesson_counter|i:5;cart|a:2:{...}</code></pre>
    <p>
      Each file is named <code>sess_&lt;session_id&gt;</code>. The contents are PHP&rsquo;s native
      serialization format. ZealPHP&rsquo;s <code>session_start()</code> override reads that file
      into <code>$_SESSION</code> for you at the start of a request, and writes it back at the end.
      Same convention vanilla PHP uses since the 90s.
    </p>
    <p>
      For production with multiple servers behind a load balancer, swap the backend in code
      (not in php.ini — ZealPHP overrides all <code>session_*()</code> calls and does not consult
      <code>session.save_handler</code> in php.ini). Register a handler
      <em>before</em> <code>App::run()</code>:
    </p>
    <pre><code class="language-php">// Cross-node: Redis WATCH/MULTI optimistic locking + 3-way merge on conflict
App::sessionHandler('redis');   // or: session_set_save_handler(new RedisSessionHandler(), true);

// Backend-agnostic (Table / Redis / Tiered — follows Store::defaultBackend())
StoreSessionHandler::register(ttl: 1440);   // TTL in seconds

// Single-server, concurrent-safe, no Redis: opt into the Table-backed handler
App::sessionHandler('table');

// Default when you call NOTHING: the framework inline FILE path (every mode) —
// safe for sequential workers, but under coroutine concurrency pick 'table'/'redis'.</code></pre>
    <p>
      For single-server apps, <code>App::sessionHandler('table')</code> gives you a coroutine-safe
      store with no Redis dependency — fast, survives restart, and the files are debuggable
      with <code>cat</code>. Swap to <code>RedisSessionHandler</code> when you add a second server.
      (Leaving it unset uses the plain file path, which is last-writer-wins under concurrency.)
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Concurrent writes are safe — pick a handler',
      'body'    => '<p>ZealPHP&rsquo;s session handlers protect you from the same-session write race (two coroutines flushing different keys to the same <code>PHPSESSID</code>):</p>' .
                   '<ul>' .
                   '<li><strong>TableSessionHandler</strong> (coroutine-mode default) — optimistic CAS versioning + recursive leaf-level 3-way merge. No Redis required. Single-node.</li>' .
                   '<li><strong>RedisSessionHandler</strong> — Redis <code>WATCH</code>/<code>MULTI</code> optimistic locking, retries with a 3-way merge on conflict. Cross-node.</li>' .
                   '<li><strong>StoreSessionHandler</strong> — backend-agnostic: Table, Redis, or Tiered — follows whatever <code>Store::defaultBackend()</code> is set to.</li>' .
                   '<li><strong>File backend</strong> — read-merge-write under an exclusive <code>flock(LOCK_EX)</code>.</li>' .
                   '</ul>' .
                   '<p>Per-coroutine <code>RequestContext</code> isolation keeps each request&rsquo;s view private; the handler&rsquo;s merge layer resolves conflicts when two coroutines commit to the <em>same</em> session id simultaneously.</p>',
    ]); ?>

    <h2>Step 4 — Why <code>$_SESSION</code> AND <code>$g-&gt;session</code>?</h2>
    <p>
      You&rsquo;ll see both used. They point at the same data; they exist for two different reasons.
    </p>
    <table class="cmp-table">
      <thead><tr><th>Access</th><th>Available in</th><th>What it is</th></tr></thead>
      <tbody>
        <tr>
          <td><code>$_SESSION['x']</code></td>
          <td>Anywhere PHP runs — including unmodified WordPress/Drupal</td>
          <td>The classic PHP superglobal. ZealPHP&rsquo;s <code>session_start()</code> populates it.</td>
        </tr>
        <tr>
          <td><code>$g-&gt;session['x']</code></td>
          <td>ZealPHP-aware code (route handlers, API closures, services)</td>
          <td>A reference into the same data via <code>RequestContext</code> — coroutine-safe by construction.</td>
        </tr>
      </tbody>
    </table>
    <h3>Why this works — and what would break without it</h3>
    <p>
      Sessions are the <em>one</em> classic PHP superglobal that survives unchanged in coroutine
      mode. That’s not an accident — the framework intercepts every <code>session_*()</code> call
      (<code>session_start</code>, <code>session_id</code>, <code>session_destroy</code>,
      <code>session_write_close</code>, <code>session_regenerate_id</code>) via the
      <strong>ext-zealphp</strong> extension at startup. Those overrides live in <code>src/Session/utils.php</code>
      and route every call to the current coroutine&rsquo;s <code>RequestContext</code> instead of a
      process-wide <code>$_SESSION</code> global. The Mental Model lesson covers
      <a href="/learn/mental-model#why-we-even-need-a-coroutine-mode">why that matters for
      <code>$_GET</code> / <code>$_POST</code> too</a>.
    </p>
    <p>Without that interception, here&rsquo;s the bug you&rsquo;d see in production:</p>
    <ul>
      <li>Request A calls <code>session_start()</code> for user 1, writes
        <code>$_SESSION['user_id'] = 1</code>, then yields on a DB query.</li>
      <li>Request B arrives at the same worker, calls <code>session_start()</code> for user 2.
        Without the override this would clobber the shared <code>$_SESSION</code> array with user
        2&rsquo;s data.</li>
      <li>Request A resumes, reads <code>$_SESSION['user_id']</code>, gets <code>2</code>, and
        returns user 2&rsquo;s shopping cart to user 1.</li>
    </ul>
    <p>
      That’s the exact race the framework prevents. Sessions are safe in both modes because the
      framework went out of its way to keep <code>session_start()</code> and <code>$_SESSION</code>
      semantics working. <strong>For everything else request-scoped — query strings, form bodies,
      cookies, file uploads — use <code>$g-&gt;X</code> in coroutine mode.</strong> Reading
      <code>$_GET</code> directly in coroutine mode returns <code>null</code> (not a leaked value),
      which makes the mistake loud instead of silent.
    </p>
    <p>
      The reason both <code>$_SESSION</code> and <code>$g-&gt;session</code> exist: in
      <strong>coroutine mode</strong> (the default for new ZealPHP apps), every request runs in its
      own coroutine with its own <code>RequestContext</code>. <code>$g-&gt;session</code> is the
      direct, unmediated access path; <code>$_SESSION</code> is the back-compat path that legacy
      code expects. Either works. Use whichever reads better in context.
    </p>
    <p>
      The full mental-model is in Foundations &rarr;
      <a href="/learn/mental-model">The Mental Model</a> (the "What&rsquo;s shared, what isn&rsquo;t"
      section). You don&rsquo;t need to think about it day-to-day — both access paths are wired so
      you can&rsquo;t accidentally cross sessions between requests.
    </p>
    <p>
      Which session manager runs depends on the lifecycle mode set by
      <a href="/coroutines#lifecycle-modes"><code>App::mode()</code></a>:
      in coroutine mode (<code>App::mode(App::MODE_COROUTINE)</code>) each request gets a
      <strong><code>CoSessionManager</code></strong> with its own per-coroutine
      <code>RequestContext</code>; in <code>legacy-cgi</code> and <code>mixed</code> modes
      (<code>App::mode(App::MODE_MIXED)</code>) the shared-process
      <strong><code>SessionManager</code></strong> runs instead — <em>except</em>
      <code>App::MODE_COROUTINE_LEGACY</code> mode, which keeps real superglobals but
      keeps <code>CoSessionManager</code> because coroutines remain on. Either way
      <code>$g-&gt;session</code> is the safe access path.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Don\'t use globals or statics for per-user state',
      'body'    => '<p>Tempting: <code>UserState::$counter = 5;</code>. Don&rsquo;t. Static class properties live in the worker process — they survive across requests but are <em>shared by every user the worker handles</em>. The next request from a different user sees the same value. Always use sessions (or a database) for per-user state.</p>',
    ]); ?>

    <h3 class="lsess-h3">First-visit cookie: redirects work after <code>session_start()</code> too</h3>
    <p>
      In a long-running OpenSwoole process, "set cookie on first
      <code>session_start()</code>" is more subtle than it looks. Until v0.2.24,
      this pattern would silently break for first-time visitors:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'OAuth handoff — broken in &lt;= v0.2.23 for first-time visitors',
      'code'  => <<<'PHP'
$app->route('/oauth/start', function($request, $response, $g) {
    session_start();
    $g->session['oauth_state']    = bin2hex(random_bytes(16));
    $g->session['code_verifier']  = bin2hex(random_bytes(32));

    // Redirect to the OAuth provider. The Set-Cookie MUST go out with
    // this 302 — otherwise the callback request arrives with no PHPSESSID,
    // the framework starts a fresh session, and oauth_state is lost.
    return $response->redirect('https://provider.example/oauth/authorize?...', 302);
});
PHP,
    ]); ?>
    <p>
      <strong>Symptom:</strong> the redirect went out, but with no
      <code>Set-Cookie</code> header. On the callback hit, the client had no
      <code>PHPSESSID</code>, the framework minted a new session, and the
      <code>oauth_state</code> stashed in the original request was gone &mdash;
      OAuth would always fail the state check.
    </p>
    <p>
      <strong>Fix (v0.2.24, PR <a href="https://github.com/sibidharan/zealphp/pull/12" target="_blank" rel="noopener">#12</a>):</strong>
      <code>session_start()</code> now auto-emits <code>Set-Cookie</code> when
      the request had no incoming <code>PHPSESSID</code>. Idempotent (only fires
      once per request), respects <code>session.use_cookies = 0</code>, and
      skips when the response is already flushed. Nothing changes on the user
      side &mdash; handlers that do <code>session_start()</code> + write +
      <code>redirect()</code> just work now, exactly the way they would under
      mod_php.
    </p>
    <p>
      Two visitor paths covered:
    </p>
    <ul class="lsess-list">
      <li><strong>First-time visitor</strong> (no incoming cookie) &mdash; the
        new auto-emit fires inside <code>session_start()</code>, redirect carries
        the cookie, callback finds the session.</li>
      <li><strong>Returning visitor</strong> (already has a cookie) &mdash;
        <code>CoSessionManager</code> handles the refresh as before,
        <code>session_start()</code> sees the cookie already present and skips the
        emit. Still exactly one <code>Set-Cookie</code> on the wire (was two
        before in some edge cases).</li>
    </ul>

    <h2>What you built</h2>
    <p>
      A counter that survives a reload, follows you across tabs, and uses no database — just
      <code>$g-&gt;session</code> and a file on disk. Sessions are <em>the</em> primitive for
      anything per-user that doesn&rsquo;t need to outlive the cookie&rsquo;s lifetime: cart
      contents, dark-mode preferences, flash messages, "you were viewing this page" breadcrumbs.
    </p>
    <p>
      The next lesson uses sessions to remember <em>who</em> a user is — user accounts, register/login,
      password hashing — all built on the same primitive.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'sess1',
      'question' => 'You set <code>$_SESSION[\'cart\'] = [...]</code> in one request handler. The same user sends a second request. How does ZealPHP know to load the same cart back?',
      'correct'  => 'a',
      'explain'  => 'The browser sends a <code>PHPSESSID</code> cookie on every request after the first. ZealPHP uses that cookie value to look up the right session file on disk and load it into <code>$_SESSION</code> for the new request. Without the cookie, the server has no way to associate a request with a previous session.',
      'options'  => [
        'a' => 'The browser sends the <code>PHPSESSID</code> cookie; the server looks up the matching file on disk.',
        'b' => 'The server fingerprints the user by IP address.',
        'c' => 'OpenSwoole keeps the session in shared memory per IP.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Sessions = a cookie (<code>PHPSESSID</code>) + a server-side store keyed by it. Cookie identifies, store remembers.',
      'Default store is a file under <code>/var/lib/php/sessions/</code>. <code>cat</code>-able, debuggable, survives restart.',
      'Set <code>$g-&gt;session[\'key\']</code> in any handler; read in any handler from the same browser. ZealPHP saves at end of request.',
      '<code>$_SESSION</code> and <code>$g-&gt;session</code> reference the same data — pick by readability. Both are coroutine-safe.',
      'Per-user state goes in a session. Per-app state goes in <code>Store</code> (Foundations &rarr; <a href="/learn/store">Sharing State</a>).',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/htmx"
         hx-get="/api/learn/page?slug=learn/htmx" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/htmx">← Forms & htmx</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/auth"
         hx-get="/api/learn/page?slug=learn/auth" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/auth">User Accounts →</a>
    </div>
  </article>
</div>
