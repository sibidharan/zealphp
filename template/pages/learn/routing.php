<?php use ZealPHP\App; $active = $active ?? 'learn/routing'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 22,
      'title'    => 'Routes & APIs: Advanced Patterns',
      'subtitle' => 'Regex, namespace groups, fallbacks, and the route-ordering edge cases. Foundations covers the basics.',
      'prev'     => ['slug' => 'learn/tictactoe', 'title' => 'Tic-Tac-Toe'],
      'next'     => ['slug' => 'learn/async',     'title' => 'Async & Coroutines'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Regex routes for patterns the filesystem can\'t express',
      'nsRoute vs nsPathRoute — when each one is the right fit',
      'App::setFallback() for hosting unmodified legacy apps under ZealPHP',
      'The .php-extension toggle and when to flip it',
      'Route-ordering edge cases — what wins when two routes could match',
    ]]); ?>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'This builds on Foundations',
      'body'    => '<p>Foundations &rarr; <a href="/learn/routes">How Routes Work</a> covers <code>public/</code> file routing, <code>api/</code> auto-dispatch, basic <code>$app-&gt;route()</code>, the priority chain, and parameter injection. This lesson is the next layer: regex, namespace tricks, and edge cases you hit on real apps.</p>',
    ]); ?>

    <h2>Regex routes — <code>patternRoute()</code></h2>
    <p>
      When you need to capture URL segments by regex (not by simple
      <code>{name}</code> placeholders), reach for <code>patternRoute()</code>. The whole path
      after the namespace prefix is matched against a regex; captures land in your handler in order.
    </p>
    <pre><code class="language-php">// Match /blog/2026/05 — year + month with strict format
$app-&gt;patternRoute('/blog/(\d{4})/(\d{2})', ['methods' =&gt; ['GET']],
    function ($year, $month) {
        return Post::byMonth((int)$year, (int)$month);
    }
);

// Match /file/anything-with-slashes/here.txt
$app-&gt;patternRoute('/file/(.+\.(?:txt|md|json))', ['methods' =&gt; ['GET']],
    function ($path, $response) {
        return $response-&gt;sendFile(__DIR__ . '/storage/' . $path);
    }
);</code></pre>
    <p>
      Use it when <code>{name}</code> can&rsquo;t express the constraint (digits-only, fixed length,
      file extensions, etc.). Otherwise prefer <code>$app-&gt;route()</code> with named placeholders —
      cleaner signatures and the framework injects by name.
    </p>

    <h2>Namespace routes — <code>nsRoute</code> vs <code>nsPathRoute</code></h2>
    <p>
      Namespaces let you group routes under a URL prefix without repeating it in every handler:
    </p>
    <table class="cmp-table">
      <thead><tr><th>Helper</th><th>What it does</th><th>Best for</th></tr></thead>
      <tbody>
        <tr>
          <td><code>nsRoute('admin', '/users', ...)</code></td>
          <td>Matches exactly <code>/admin/users</code>. <code>{param}</code> captures a single segment (no slashes).</td>
          <td>Conventional REST under a prefix: <code>/admin/users</code>, <code>/admin/orders</code></td>
        </tr>
        <tr>
          <td><code>nsPathRoute('admin', '/users/{path}', ...)</code></td>
          <td>The final <code>{path}</code> swallows the rest of the URL — slashes and all.</td>
          <td>Catch-all under a prefix: forwarding a sub-app, a legacy router, a CMS</td>
        </tr>
      </tbody>
    </table>
    <pre><code class="language-php">// nsRoute — strict, single-segment captures
$app-&gt;nsRoute('admin', '/users/{id}', fn($id) =&gt; Admin::userById($id));
// Matches /admin/users/42, /admin/users/abc.
// Does NOT match /admin/users/42/orders — {id} can't contain a slash.

// nsPathRoute — last param catches everything
$app-&gt;nsPathRoute('admin', '{path}', function ($path) {
    return Admin::handleAnything($path);
});
// Matches /admin/anything, /admin/users/42/orders, /admin/very/deep/path.
// $path is "anything" or "users/42/orders" or "very/deep/path".</code></pre>
    <p>
      Under the hood, the implicit <code>/api/*</code> handler uses <code>nsPathRoute('api',
      '{module}/{rquest}', ...)</code> — that&rsquo;s how <code>api/users/list.php</code> gets dispatched
      from <code>GET /api/users/list</code>.
    </p>

    <h2>The fallback — <code>App::setFallback()</code></h2>
    <p>
      Unmatched URLs return 404 by default. Register a fallback to do something else first &mdash; the
      classic use case is hosting an unmodified legacy app (WordPress, Drupal, anything that does its
      own routing) <em>behind</em> a ZealPHP server that owns most URLs.
    </p>
    <pre><code class="language-php">// app.php — before $app-&gt;run()

App::setFallback(function () {
    // Hand any URL we don't explicitly own to WordPress's front controller.
    App::includeFile(__DIR__ . '/wordpress/index.php');
});</code></pre>
    <p>
      The fallback is the Apache <code>RewriteRule . /index.php [L]</code> equivalent. You can put
      framework routes (your modern endpoints) in <code>route/</code> and <code>api/</code>, and let
      the fallback hand everything else off to the legacy app. This is the pattern the WordPress
      showcase uses — <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank">github.com/sibidharan/zealphp-wordpress</a>.
    </p>

    <h2>The <code>.php</code> extension toggle</h2>
    <p>
      By default, ZealPHP strips <code>.php</code> from URLs: <code>public/about.php</code> serves at
      <code>/about</code>, and a direct request to <code>/about.php</code> returns 403. You can flip
      this when you need raw PHP URLs (some legacy apps generate links with <code>.php</code> in them
      and you don&rsquo;t want to rewrite them):
    </p>
    <pre><code class="language-php">// app.php — flip BEFORE App::init() / before $app-&gt;run()
App::$ignore_php_ext = false;  // accept /about.php as a valid URL

// Defaults to true (strip the extension). Set to false to keep .php in URLs.</code></pre>
    <p>
      Most new apps leave it at the default. Flip when you&rsquo;re porting an app whose own HTML
      hard-codes <code>.php</code> links.
    </p>

    <h2>Route ordering edge cases</h2>
    <p>
      Foundations &rarr; <a href="/learn/routes">routes</a> showed the priority chain. Here&rsquo;s
      what bites in practice:
    </p>
    <ul>
      <li><strong>Two explicit routes for the same URL</strong> — first one registered wins. The
        second silently never runs. Add a temporary <code>elog()</code> in your handler if a route
        seems "ignored."</li>
      <li><strong>A pattern that overlaps with <code>/api/*</code></strong> — if you register
        <code>$app-&gt;route('/api/health', ...)</code> in <code>route/health.php</code>, it wins
        over the implicit ZealAPI dispatcher because <code>route/*.php</code> files load before the
        implicit catch-all. Useful for overriding one API endpoint without touching <code>api/</code>.</li>
      <li><strong>A <code>{name}</code> param that captures a literal you wanted</strong> — if you
        register both <code>/users/{id}</code> and <code>/users/new</code>, registration order
        decides. Register the literal first so it shadows the pattern; otherwise <code>"new"</code>
        gets captured as <code>$id</code>.</li>
      <li><strong>Trailing slashes</strong> — <code>/about</code> and <code>/about/</code> are
        treated as different URLs unless your route pattern includes <code>/?</code>. The implicit
        public-file route does include this; custom routes typically don&rsquo;t.</li>
    </ul>

    <h2>Try it live</h2>
    <ul>
      <li><a href="/demo/view/inject/all/42" target="_blank">Regex/pattern capture in action</a> — see how URL segments land in handler args</li>
      <li><a href="/api/learn/demo/timing?mode=parallel" target="_blank">Implicit /api/* dispatch</a> — one route serving many <code>api/*.php</code> files</li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'routing-adv-1',
      'question' => 'You register <code>$app-&gt;nsRoute(\'admin\', \'/users/{id}\', ...)</code>. Does it match <code>/admin/users/42/posts</code>?',
      'correct'  => 'b',
      'explain'  => '<code>nsRoute</code>\'s <code>{id}</code> placeholder captures a single segment — it does not match across slashes. <code>/admin/users/42/posts</code> has an extra <code>/posts</code> segment after <code>42</code>, so the route does not match. Use <code>nsPathRoute</code> if you want the final param to swallow everything including slashes.',
      'options'  => [
        'a' => 'Yes — <code>{id}</code> captures <code>42/posts</code>.',
        'b' => 'No — <code>{id}</code> captures one segment only; <code>/posts</code> is extra.',
        'c' => 'Yes — but only if you set <code>ignore_php_ext</code>.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Use <code>patternRoute()</code> when <code>{name}</code> placeholders can\'t express the constraint.',
      '<code>nsRoute</code> captures single segments; <code>nsPathRoute</code> swallows slashes — pick by what your URL shape needs.',
      '<code>App::setFallback()</code> is the equivalent of Apache\'s catch-all rewrite — great for hosting legacy apps.',
      'First-registered-wins among route/*.php and explicit routes — register literals before patterns when they overlap.',
      'Foundations: <a href="/learn/routes">How Routes Work</a> covers <code>public/</code>, <code>api/</code>, and the basic priority chain.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/tictactoe"
         hx-get="/api/learn/page?slug=learn/tictactoe" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/tictactoe">← Tic-Tac-Toe</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/async"
         hx-get="/api/learn/page?slug=learn/async" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/async">Async & Coroutines →</a>
    </div>
  </article>
</div>
