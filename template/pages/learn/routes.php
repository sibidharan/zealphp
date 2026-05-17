<?php use ZealPHP\App; $active = $active ?? 'learn/routes'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 6,
      'title'    => 'How Routes Work',
      'subtitle' => 'From URL to handler. The four ways ZealPHP finds your code, the priority order between them, and why <code>api/</code> exists.',
      'prev'     => ['slug' => 'learn/project-structure', 'title' => 'Project Structure'],
      'next'     => ['slug' => 'learn/lifecycle',         'title' => 'A Request\'s Journey'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'How starting a server makes every file in public/ become a URL automatically',
      'When to use route/ vs api/ vs public/ — three different routing styles',
      'The execution priority — which route wins when two could match',
      'Why ZealPHP needs ApIs as a separate convention, and how auto-delivery works',
      'How to add a fallback for unmatched URLs',
    ]]); ?>

    <h2>Routes are just <code>.htaccess</code> in disguise</h2>
    <p>
      On Apache, <code>.htaccess</code> tells the server "for this URL pattern, run that file." On
      nginx, <code>location</code> blocks do the same job. ZealPHP throws both of them out and lets
      the <strong>filesystem</strong> do the routing — but the mental model is identical.
    </p>
    <p>
      A request walks in. The framework asks: <em>do I have a route for this URL?</em> It checks
      four sources, in a defined order. The first match wins. The handler runs. Response goes out.
    </p>

    <h2>Step 0 — start the server</h2>
    <p>
      Before any routing happens, you start the server. Three lines is enough:
    </p>
    <pre><code class="language-php">&lt;?php
require_once __DIR__ . '/vendor/autoload.php';
ZealPHP\App::init('0.0.0.0', 8080)->run();</code></pre>
    <p>
      Run <code>php app.php</code>. The server binds port 8080, forks workers (one per CPU core),
      and waits for requests. From this moment, every <code>.php</code> file in <code>public/</code>
      is already a URL. No restart, no config — drop in a file, hit refresh.
    </p>

    <h2>The four routing styles</h2>

    <h3>1. <code>public/</code> — filesystem routing (Apache parallel)</h3>
    <p>
      Drop <code>public/about.php</code>, get <code>GET /about</code>. Drop <code>public/blog/post.php</code>,
      get <code>GET /blog/post</code>. The URL mirrors the file path; <code>.php</code> is stripped.
      Same convention WordPress, Drupal, and any Apache+PHP app already use.
    </p>
    <pre><code>public/index.php       → GET /
public/about.php       → GET /about
public/blog/post.php   → GET /blog/post
public/css/site.css    → GET /css/site.css   (static files served as-is)</code></pre>
    <p>
      Use it for: marketing pages, docs, anything that&rsquo;s "show this HTML for that URL."
    </p>

    <h3>2. <code>api/</code> — JSON endpoints with parameter injection</h3>
    <p>
      The same filesystem idea, but each file defines a <em>closure</em> (named to match the file)
      that the framework invokes with named-parameter injection. The filename becomes the last URL
      segment — it&rsquo;s NOT the HTTP method:
    </p>
    <pre><code>api/users/list.php           → /api/users/list
api/users/create.php         → /api/users/create
api/devices/new/id.php       → /api/devices/new/id
api/learn/login.php          → /api/learn/login</code></pre>
    <p>
      Inside each file you define one closure whose variable name matches <code>basename($file, '.php')</code>:
    </p>
    <pre><code class="language-php">// api/users/list.php
$list = function ($app, $request, $response) {
    // $app is the ZealAPI instance; $this also works (Closure::bind)
    return User::all();  // array → auto-JSON encoded
};</code></pre>
    <p>
      The implicit <code>/api/*</code> dispatcher (registered in <code>src/App.php</code>) listens for
      <strong>all four HTTP verbs — GET, POST, PUT, DELETE — on the same URL</strong>, then loads
      the matching file and invokes the closure. If your handler cares about the method, read it
      yourself:
    </p>
    <pre><code class="language-php">// api/users/list.php — handle GET + POST in one file
$list = function ($request) {
    $method = $request->server['REQUEST_METHOD'];
    return match ($method) {
        'GET'  => User::all(),
        'POST' => User::create($request->post),
        default => 405,  // Method Not Allowed
    };
};</code></pre>
    <p>
      Why a separate convention from <code>public/</code>? Because <code>api/</code> files get
      parameter injection (<code>$app</code>, <code>$request</code>, <code>$response</code>,
      <code>$server</code>), a <code>ZealAPI</code> base class with helpers like
      <code>$this-&gt;paramsExists()</code>, and automatic JSON encoding for array/object returns.
      <code>public/</code> files are plain PHP scripts that echo their own output. Pick
      <code>api/</code> when you want "structured JSON endpoint with helpers";
      <code>public/</code> when you want "render HTML for this URL."
    </p>

    <h3>3. <code>route/</code> — explicit routes with URL parameters</h3>
    <p>
      Sometimes you need a URL the filesystem can&rsquo;t express:
      <code>/users/{id}</code>, <code>/admin/anything-here</code>, a WebSocket endpoint, a route
      where the path captures regex. That&rsquo;s what <code>route/</code> is for. Files in this
      directory are <code>include</code>d at startup and register routes programmatically:
    </p>
    <pre><code class="language-php">// route/users.php
$app->route('/users/{id}', function ($id, $response) {
    return User::find($id) ?: 404;
});

$app->route('/users/{id}/avatar', function ($id, $response) {
    return $response->sendFile("storage/avatars/{$id}.png");
});

// route/admin.php — namespace prefix
$app->nsRoute('admin', '/users', fn() => Admin::userList());

// route/ws.php — WebSocket
$app->ws('/chat', $onMessage, $onOpen, $onClose);</code></pre>
    <p>
      Use it for: anything dynamic. URL params, WebSocket, namespace prefixes, regex routes,
      programmatic registration. One file can register many routes.
    </p>

    <h3>4. <code>app.php</code> — inline routes for one-offs</h3>
    <p>
      For a handful of trivial routes that don&rsquo;t warrant a file, register them right in the
      bootstrap:
    </p>
    <pre><code class="language-php">// app.php — before $app->run()
$app->route('/health', fn() => ['ok' => true]);
$app->route('/version', fn() => ['version' => '1.0.0']);
$app->run();</code></pre>
    <p>
      Keep this small. If <code>app.php</code> grows past ten inline routes, move them to a file in
      <code>route/</code>.
    </p>

    <h2>The execution order — first match wins</h2>
    <p>
      When two routing styles <em>could</em> match the same URL, priority kicks in. Routes are
      checked in this order:
    </p>
    <ol>
      <li>
        <strong>Explicit routes from <code>app.php</code></strong> (registered before
        <code>$app-&gt;run()</code> in your own code).
      </li>
      <li>
        <strong>Routes from <code>route/*.php</code></strong> (auto-<code>include</code>d at the top of
        <code>$app-&gt;run()</code>).
      </li>
      <li>
        <strong>The <code>/api/*</code> namespace</strong> → handed off to <code>ZealAPI::processApi()</code>,
        which loads the matching <code>api/&lt;module&gt;/&lt;segment&gt;.php</code> (the URL segments
        pick the file — same file handles all HTTP methods).
      </li>
      <li>
        <strong>Dotfile + raw-<code>.php</code>-URL guards</strong> → URLs containing <code>.git/</code>,
        <code>.env</code>, <code>.htaccess</code>, or ending in <code>.php</code> return 403.
        <code>.well-known/</code> is allowed (RFC 8615).
      </li>
      <li>
        <strong>Implicit <code>public/</code> file lookup</strong> → <code>/</code> → <code>public/index.php</code>,
        <code>/foo</code> → <code>public/foo.php</code>, <code>/dir/file</code> → <code>public/dir/file.php</code>.
      </li>
      <li>
        <strong>Fallback or 404</strong> → if you registered one with <code>App::setFallback(...)</code>,
        it runs. Otherwise: 404.
      </li>
    </ol>
    <p>
      The practical consequence: <em>explicit beats convention.</em> If you register
      <code>$app-&gt;route('/about', ...)</code> in <code>route/</code>, that closure wins over
      <code>public/about.php</code> &mdash; even though the public file would have served too.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Two ways to read the priority chain',
      'body'    => '<p>From the framework&rsquo;s side, this is registration order: anything registered first wins when two routes match. From your side, the practical rule is simpler — <em>handwritten code beats convention</em>. Explicit <code>$app-&gt;route()</code> overrides every implicit file route. Use this to override a single legacy path while keeping the rest of <code>public/</code> intact.</p>',
    ]); ?>

    <h2>How <code>/api/*</code> is auto-delivered</h2>
    <p>
      The framework registers two implicit catch-all routes for the <code>api</code> namespace:
    </p>
    <pre><code class="language-php">$this->nsPathRoute('api', '{module}/{rquest}', [...]);  // /api/users/list
$this->nsPathRoute('api', '{rquest}', [...]);          // /api/healthcheck</code></pre>
    <p>
      Both delegate to <code>ZealAPI::processApi($module, $request)</code>. That class walks the
      <code>api/</code> directory, finds the matching <code>api/&lt;module&gt;/&lt;request&gt;.php</code>
      file, <code>include</code>s it (which must define a single closure whose variable name matches
      <code>basename($file, '.php')</code>), and invokes the closure with parameter injection.
    </p>
    <p>
      The result: any method on <code>/api/users/list</code> &mdash; <code>GET</code>,
      <code>POST</code>, <code>PUT</code>, <code>DELETE</code> &mdash; auto-runs
      <code>api/users/list.php</code>&rsquo;s <code>$list</code> closure. The framework does NOT
      dispatch by HTTP verb; if your handler cares about the method, read
      <code>$request-&gt;server['REQUEST_METHOD']</code> inside the closure. You never register an
      API route by hand &mdash; the framework builds the two catch-all dispatchers once at boot.
    </p>

    <h2>When nothing matches — fallback or 404</h2>
    <p>
      For unmatched URLs, ZealPHP&rsquo;s default is a 404. If you want different behavior &mdash; for
      example, hand every unmatched URL to a WordPress installation &mdash; register a fallback:
    </p>
    <pre><code class="language-php">// app.php
App::setFallback(function () {
    // App::include() resolves the path relative to public/ (Apache DocumentRoot convention).
    App::include('/wordpress/index.php');
});</code></pre>
    <p>
      This is the equivalent of Apache&rsquo;s <code>RewriteRule . /index.php [L]</code>. It&rsquo;s
      what makes ZealPHP a drop-in runtime for legacy apps: the fallback hands every URL the framework
      doesn&rsquo;t recognize off to your legacy router.
    </p>

    <h2>Quick reference</h2>
    <table class="cmp-table">
      <thead><tr><th>I want to serve…</th><th>Put the file in…</th><th>URL becomes</th></tr></thead>
      <tbody>
        <tr><td>A static page</td><td><code>public/about.php</code></td><td><code>/about</code></td></tr>
        <tr><td>A CSS or image asset</td><td><code>public/css/site.css</code></td><td><code>/css/site.css</code></td></tr>
        <tr><td>A JSON API endpoint</td><td><code>api/users/list.php</code> (closure named <code>$list</code>)</td><td><code>/api/users/list</code> (any HTTP method)</td></tr>
        <tr><td>A URL with a parameter</td><td><code>route/users.php</code> via <code>$app-&gt;route('/users/{id}', ...)</code></td><td><code>/users/42</code></td></tr>
        <tr><td>A WebSocket endpoint</td><td><code>route/ws.php</code> via <code>$app-&gt;ws('/chat', ...)</code></td><td><code>ws://host/chat</code></td></tr>
        <tr><td>A pattern that captures regex</td><td><code>route/legacy.php</code> via <code>$app-&gt;patternRoute('/post/(\d+)', ...)</code></td><td><code>/post/123</code></td></tr>
        <tr><td>A catch-all for unmatched URLs</td><td><code>app.php</code> via <code>App::setFallback(...)</code></td><td>anything not matched above</td></tr>
      </tbody>
    </table>

    <?php App::render('/components/_concept_check', [
      'id'       => 'routes1',
      'question' => 'You have <code>public/about.php</code> (renders a marketing page) and you also register <code>$app-&gt;route("/about", fn() =&gt; "from explicit")</code> in <code>route/marketing.php</code>. A request hits <code>GET /about</code>. What runs?',
      'correct'  => 'a',
      'explain'  => 'Explicit routes from <code>route/*.php</code> are registered before the implicit public-file lookup, and the framework matches first-registered-first. The explicit closure wins, so the response body is "from explicit". To get the public file back, remove the explicit route — there’s no priority flag.',
      'options'  => [
        'a' => 'The closure from <code>route/marketing.php</code> — explicit beats convention.',
        'b' => 'The public file <code>public/about.php</code> — files always win.',
        'c' => 'A 500 — the framework can&rsquo;t pick.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Three filesystem conventions: <code>public/</code> for pages, <code>api/</code> for JSON endpoints, <code>route/</code> for anything dynamic.',
      'Inline <code>$app-&gt;route()</code> calls in <code>app.php</code> are for one-offs — move to <code>route/</code> once you have more than a handful.',
      'Priority order: explicit (app.php) → route/ → /api/* → dotfile/.php guards → public/ → fallback.',
      'The <code>api/</code> tree is auto-dispatched via <code>ZealAPI::processApi()</code>: <code>api/users/list.php</code> (closure named <code>$list</code>) auto-handles <code>/api/users/list</code> for every HTTP method — the handler reads <code>$request-&gt;server[\'REQUEST_METHOD\']</code> if it cares.',
      'Use <code>App::setFallback()</code> to catch unmatched URLs — perfect for hosting legacy apps under a ZealPHP front door.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/project-structure"
         hx-get="/api/learn/page?slug=learn/project-structure" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/project-structure">← Project Structure</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/lifecycle"
         hx-get="/api/learn/page?slug=learn/lifecycle" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/lifecycle">A Request's Journey →</a>
    </div>
  </article>
</div>
