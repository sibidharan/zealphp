<?php use ZealPHP\App; $active = $active ?? 'learn/sessions'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 7,
      'title'    => 'Sessions',
      'subtitle' => 'HTTP has no memory. Sessions fix that. But async PHP changes the rules.',
      'prev'     => ['slug' => 'learn/htmx', 'title' => 'Forms & htmx'],
      'next'     => ['slug' => 'learn/auth', 'title' => 'User Accounts'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Why HTTP is stateless and what that means for your app',
      'How sessions give the server a memory across requests',
      'The critical difference between $_SESSION and $g->session in ZealPHP',
      'Build a session-backed feature that persists across page loads',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Click the counter button from the last lesson. Now open a new tab and visit the same page.
      Your count is gone. <strong>HTTP is stateless</strong> — every request is a stranger.
      The server doesn't know that the person who clicked 5 times is the same person loading the page now.
    </p>
    <p>
      This is by design. HTTP was built for documents, not applications. But you're building an application.
      You need the server to <em>remember</em> who someone is across requests.
    </p>

    <h2>Sessions: name badges for the web</h2>
    <p>
      Think of a store where customers wear masks — the clerk can't tell them apart. Sessions work
      like <strong>name badges</strong>:
    </p>
    <ol>
      <li>First visit: the server hands the browser a <strong>name badge</strong> (a cookie containing a session ID)</li>
      <li>Next visit: the browser wears the badge. The server reads the ID and opens a <strong>personal file folder</strong> with that visitor's data</li>
      <li>The badge is just an ID — the actual data stays on the server, safe from tampering</li>
    </ol>

    <h2>Sessions in traditional PHP</h2>
    <pre><code class="language-php">session_start();
$_SESSION['name'] = 'Alice';
echo $_SESSION['name']; // "Alice" — even across page loads</code></pre>
    <p>
      <code>session_start()</code> reads the session cookie, loads the session file, and populates
      <code>$_SESSION</code>. At the end of the request, PHP writes <code>$_SESSION</code> back to disk.
      The data persists.
    </p>

    <h2>The ZealPHP twist: coroutines</h2>
    <p>
      In traditional PHP, each request runs in its own process. <code>$_SESSION</code> is safe because
      no two requests share the same process at the same time.
    </p>
    <p>
      ZealPHP is different. <strong>One worker process handles many requests simultaneously</strong> using
      coroutines. If two users make requests at the same time, they run in the same process. If both
      wrote to <code>$_SESSION</code>, their data would <strong>leak between requests</strong>.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'The golden rule',
      'body'    => '<p>In ZealPHP coroutine mode, use <code>$g->session</code> instead of <code>$_SESSION</code>. The <code>G::instance()</code> object is per-coroutine — each request gets its own isolated copy. <code>$_SESSION</code> is process-global and will leak between concurrent requests.</p>',
    ]); ?>

    <pre><code class="language-php">// ZealPHP coroutine mode — the right way
$g = \ZealPHP\G::instance();
$g->session['name'] = 'Alice';
echo $g->session['name']; // "Alice" — per-coroutine, safe</code></pre>

    <p>
      The API is almost identical. <code>$g->session</code> behaves like <code>$_SESSION</code>, but it's
      scoped to the current coroutine. When the request ends, the framework writes it to a session file
      (just like PHP does), and the next request with the same cookie picks it up.
    </p>

    <?php App::render('/components/_deepdive', [
      'title' => 'How does session_start() work in ZealPHP?',
      'body'  => '<p>ZealPHP uses the <code>uopz</code> extension to override <code>session_start()</code>, <code>session_destroy()</code>, and friends at boot time. When your code calls <code>session_start()</code>, ZealPHP\'s replacement reads the session cookie from the current request\'s <code>G::instance()</code>, loads the session data from file, and populates <code>$g->session</code>. At end of request, it writes back. This means <code>session_start()</code> still works — but it writes to the per-coroutine <code>$g->session</code>, not to the global <code>$_SESSION</code>.</p>',
    ]); ?>

    <h2>Putting it together</h2>
    <p>Here's how the counter from Lesson 5 persists across requests — it stores the count in the session:</p>
    <pre><code class="language-php">// The counter endpoint (route/learn.php)
$app->route('/api/learn/demo/incr', function ($request, $response) {
    session_start();
    $g = G::instance();
    $g->session['demo_counter'] = ($g->session['demo_counter'] ?? 0) + 1;
    return App::renderToString('/components/_counter_button', [
        'n' => $g->session['demo_counter'],
    ]);
});</code></pre>
    <p>The counter now survives page reloads, tab closures, and even server restarts (because the session is written to disk).</p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'sess1',
      'question' => 'Why can\'t ZealPHP use $_SESSION directly in coroutine mode?',
      'correct'  => 'c',
      'explain'  => '$_SESSION is process-global. In coroutine mode, multiple requests share the same process simultaneously. Writing to $_SESSION would leak data between concurrent requests.',
      'options'  => [
        'a' => 'PHP doesn\'t support $_SESSION anymore',
        'b' => '$_SESSION is too slow for async',
        'c' => '$_SESSION is process-global and would leak between concurrent requests',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'HTTP is stateless — sessions bridge the gap by giving each visitor a cookie + server-side storage',
      'In ZealPHP, use <code>$g->session</code> instead of <code>$_SESSION</code> (per-coroutine isolation)',
      '<code>session_start()</code> still works — uopz redirects it to the coroutine-safe implementation',
      'Session data persists to disk — survives page reloads and server restarts',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/htmx"
         hx-get="/api/learn/page?slug=learn/htmx" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/htmx">← Forms &amp; htmx</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/auth"
         hx-get="/api/learn/page?slug=learn/auth" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/auth">User Accounts →</a>
    </div>
  </article>
</div>
