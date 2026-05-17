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
      database, no Redis, no JavaScript state — just a session.
    </p>

    <?php App::render('/components/_tryit', [
      'title' => 'Live demo: a session-backed counter',
      'body'  => '<div class="demo-panel" style="--demo-min:0">
  <div class="demo-output" style="text-align:center;padding:2.2rem 1rem">
    <div style="font-size:3rem;font-weight:700;color:#92400e" id="session-counter-value">' . (int)($g->session['lesson_counter'] ?? 0) . '</div>
    <p style="margin:.4rem 0 1rem;color:#78716c;font-size:.88rem">your session counter</p>
    <button type="button" class="btn btn-primary"
            hx-post="/api/learn/demo/session-bump"
            hx-target="#session-counter-value"
            hx-swap="innerHTML">+1</button>
  </div>
</div>
<p style="margin-top:.85rem">Click <strong>+1</strong>. Now reload the page — the number stays. Open <strong>/learn/sessions</strong> in another tab — same number there too. The browser sent a <code>PHPSESSID</code> cookie that maps to a file on the server; this lesson is just reading and writing through it.</p>',
    ]); ?>

    <h2>Step 1 — HTTP doesn't remember anything</h2>
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
      For production with multiple servers behind a load balancer, swap the file backend for Redis
      (set <code>session.save_handler = redis</code> in php.ini). For single-server apps, the file
      backend is fine — it&rsquo;s fast, it survives restart, and you can debug it with
      <code>cat</code>.
    </p>

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
    <p>
      The reason both exist: in <strong>coroutine mode</strong> (the default for new ZealPHP apps),
      every request runs in its own coroutine with its own <code>RequestContext</code>. The
      framework hooks <code>session_start()</code> and <code>$_SESSION</code> to point at <em>the
      current coroutine&rsquo;s</em> session bag — not a process-wide global. <code>$g-&gt;session</code>
      is the direct, unmediated access path; <code>$_SESSION</code> is the back-compat path that
      legacy code expects. Either works. Use whichever reads better in context.
    </p>
    <p>
      The full mental-model is in Foundations &rarr;
      <a href="/learn/mental-model">The Mental Model</a> (the "What&rsquo;s shared, what isn&rsquo;t"
      section). You don&rsquo;t need to think about it day-to-day — both access paths are wired so
      you can&rsquo;t accidentally cross sessions between requests.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Don\'t use globals or statics for per-user state',
      'body'    => '<p>Tempting: <code>UserState::$counter = 5;</code>. Don&rsquo;t. Static class properties live in the worker process — they survive across requests but are <em>shared by every user the worker handles</em>. The next request from a different user sees the same value. Always use sessions (or a database) for per-user state.</p>',
    ]); ?>

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
