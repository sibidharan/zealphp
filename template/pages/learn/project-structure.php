<?php use ZealPHP\App; $active = $active ?? 'learn/project-structure'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 5,
      'title'    => 'Project Structure',
      'subtitle' => 'Six directories and a single bootstrap. Here\'s where each kind of code goes.',
      'prev'     => ['slug' => 'learn/mental-model', 'title' => 'The Mental Model'],
      'next'     => ['slug' => 'learn/routes',       'title' => 'How Routes Work'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What lives in each ZealPHP directory — and why the convention exists',
      'How to bootstrap a server in three lines',
      'The decision rule: route vs api vs public vs src',
      'How PSR-4 autoloading wires src/ to your namespace',
    ]]); ?>

    <h2>The shape of a ZealPHP app</h2>
    <p>
      A fresh app (from <code>composer create-project sibidharan/zealphp-project</code>) lays out
      like this:
    </p>
    <pre><code>my-app/
├── app.php                ← bootstrap: middleware + $app->run()
├── composer.json          ← autoload "App\\" => "src/"
├── public/                ← drop-in pages — filesystem is the router
│   ├── index.php
│   ├── about.php          → GET /about
│   └── css/site.css       → GET /css/site.css (static)
├── api/                   ← file-based REST API (auto-routed)
│   └── users/
│       ├── get.php        → GET  /api/users
│       └── post.php       → POST /api/users
├── route/                 ← explicit routes — loaded at startup
│   ├── ws.php             ← WebSocket endpoints
│   ├── streaming.php      ← $app->route() with custom paths
│   └── timers.php         ← App::tick() / after()
├── template/              ← view templates (rendered via App::render)
│   ├── _master.php
│   ├── components/
│   └── pages/
└── src/                   ← your business logic — PSR-4 autoloaded
    ├── Auth.php
    └── Notes.php</code></pre>

    <h2>The three-line server</h2>
    <p>
      Every ZealPHP app begins with one file. Here&rsquo;s the minimum viable bootstrap:
    </p>
    <pre><code class="language-php">&lt;?php
require_once __DIR__ . '/vendor/autoload.php';

ZealPHP\App::init('0.0.0.0', 8080)->run();</code></pre>
    <p>
      <code>App::init()</code> creates the server. <code>$app-&gt;run()</code> starts the event loop
      and blocks until you stop it. Add files to <code>public/</code> and they become URLs immediately.
      No more configuration is required.
    </p>

    <h2>A realistic bootstrap</h2>
    <p>
      Real apps add middleware, the occasional inline route, maybe a <code>Store</code> table.
      <code>app.php</code> stays under 50 lines for most apps:
    </p>
    <pre><code class="language-php">&lt;?php
require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\Store;
use ZealPHP\Middleware\{CorsMiddleware, ETagMiddleware, SessionStartMiddleware};

App::superglobals(false);                          // coroutine mode (default for new apps)

$app = App::init('0.0.0.0', 8080);

$app->addMiddleware(new CorsMiddleware());          // outermost
$app->addMiddleware(new ETagMiddleware());
$app->addMiddleware(new SessionStartMiddleware());  // innermost (closest to handler)

// Shared-memory tables MUST be made before run() so workers inherit them.
Store::make('rate_limits', 10000, [
    'count' => [\OpenSwoole\Table::TYPE_INT, 4],
    'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
]);

// Inline route — fine for one-off endpoints. Most routes live in route/ or api/.
$app->route('/health', fn() => ['ok' => true]);

$app->run();</code></pre>
    <p>
      Everything above <code>$app-&gt;run()</code> runs <em>once</em>, in the master process, before
      workers fork. Use it for setup that the whole server depends on: middleware, shared tables,
      timer registration. Anything per-request goes in a handler.
    </p>

    <h2>The six directories — what goes where</h2>

    <h3><code>public/</code> — pages, by filesystem</h3>
    <p>
      Drop a <code>.php</code> file in <code>public/</code>, get a URL. <code>public/about.php</code>
      responds at <code>/about</code>. Static files (CSS, JS, images) work the same way. This is the
      Apache-style convention: filesystem = router.
    </p>
    <p>
      <code>public/</code> is your <strong>document root</strong> — the Apache <code>DocumentRoot</code>
      equivalent that every implicit route and the static handler resolve against. It&rsquo;s the
      <em>default</em>; point it at a different folder with <code>App::documentRoot('…')</code> before
      <code>App::init()</code>.
    </p>

    <h3><code>api/</code> — REST endpoints, by method</h3>
    <p>
      File-based REST: <code>api/users/get.php</code> → <code>GET /api/users</code>. Inside the file
      you define one closure named after the HTTP method (<code>$get</code>, <code>$post</code>, etc.).
      The framework auto-binds it with parameter injection. Cleanest pattern for JSON APIs.
    </p>

    <h3><code>route/</code> — explicit registrations</h3>
    <p>
      Files in <code>route/</code> are loaded at startup. Use this for anything that doesn&rsquo;t
      fit the filesystem convention: WebSocket endpoints (<code>$app-&gt;ws()</code>), URL params
      (<code>/users/{id}</code>), namespaced groups (<code>$app-&gt;nsRoute('admin', ...)</code>),
      or background timers (<code>App::tick()</code>). Each file registers many routes.
    </p>

    <h3><code>template/</code> — views only</h3>
    <p>
      Templates render HTML. Call them with <code>App::render('pages/about', ['title' =&gt; ...])</code>.
      The <strong>hard rule</strong>: no business logic, no inline <code>&lt;script&gt;</code> blocks,
      no <code>style=</code> attributes. Templates produce HTML; everything else lives in <code>src/</code>
      or <code>public/js/</code>.
    </p>

    <h3><code>src/</code> — your business logic</h3>
    <p>
      Classes with proper namespaces, autoloaded via PSR-4. Your route handlers and API files should
      delegate here. A 50-line handler is a smell &mdash; extract a service class.
    </p>
    <pre><code class="language-json">// composer.json
{
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}</code></pre>
    <p>
      With that, <code>src/Auth.php</code> defining <code>App\Auth</code> is reachable from anywhere
      as <code>new \App\Auth()</code>. Run <code>composer dump-autoload</code> after adding new
      classes for the first time.
    </p>

    <h3><code>app.php</code> — bootstrap, nothing else</h3>
    <p>
      Keep it thin. Middleware registration, <code>Store::make()</code> calls, the occasional inline
      route. If <code>app.php</code> grows past ~100 lines, you&rsquo;re putting business logic where
      it doesn&rsquo;t belong &mdash; move it.
    </p>

    <h2>Where do I put X?</h2>
    <table class="cmp-table">
      <thead><tr><th>I want to…</th><th>Put it in…</th></tr></thead>
      <tbody>
        <tr><td>Add a marketing page like <code>/pricing</code></td><td><code>public/pricing.php</code></td></tr>
        <tr><td>Add a static CSS / JS file</td><td><code>public/css/</code> or <code>public/js/</code></td></tr>
        <tr><td>Add a JSON API endpoint like <code>GET /api/users</code></td><td><code>api/users/get.php</code></td></tr>
        <tr><td>Add a URL-param route like <code>/users/{id}</code></td><td><code>route/users.php</code> with <code>$app-&gt;route(...)</code></td></tr>
        <tr><td>Add a WebSocket endpoint</td><td><code>route/ws.php</code> with <code>$app-&gt;ws(...)</code></td></tr>
        <tr><td>Add a background timer</td><td><code>route/timers.php</code> using <code>App::tick()</code></td></tr>
        <tr><td>Add a reusable HTML fragment</td><td><code>template/components/_card.php</code></td></tr>
        <tr><td>Add a layout used by many pages</td><td><code>template/_master.php</code> or a new layout file</td></tr>
        <tr><td>Add a class like <code>AuthService</code></td><td><code>src/AuthService.php</code> (namespaced)</td></tr>
        <tr><td>Add new middleware</td><td><code>src/Middleware/MyMiddleware.php</code>, register in <code>app.php</code></td></tr>
      </tbody>
    </table>

    <?php App::render('/components/_callout', [
      'variant' => 'deep',
      'title'   => 'Restart vs reload — what picks up changes immediately?',
      'body'    => '<p>Files in <code>template/</code>, <code>api/</code>, and <code>public/</code> reload on every request — edit, refresh, done. Files in <code>app.php</code>, <code>route/</code>, <code>src/App.php</code>, and <code>src/Middleware/</code> load at startup and need <code>php app.php restart</code> to pick up changes. <code>src/</code> classes used inside handlers reload through the autoloader, but bootstrap-time wiring (middleware, route registration) is frozen until restart.</p>',
    ]); ?>

    <h2>The autoloader is shared across requests</h2>
    <p>
      One of ZealPHP&rsquo;s quiet wins: the Composer autoloader runs <em>once</em>, when the worker
      starts. Every request after that gets your classes already mapped &mdash; no per-request
      file scan, no opcache warmup penalty. The cost of having a 500-file codebase drops to "the
      classes you actually use this request."
    </p>
    <p>
      This is why we lean on PSR-4 hard. The framework is built so the autoloader does its work
      once and then steps out of the way.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'struct1',
      'question' => 'You want to add a <code>POST /api/login</code> endpoint that returns JSON. Where does it go?',
      'correct'  => 'b',
      'explain'  => 'The api/ tree is file-based REST: api/login/post.php with a single $post closure. Parameter injection works the same as anywhere else. Service logic (password check, session creation) lives in src/, not in the api file.',
      'options'  => [
        'a' => '<code>public/api/login.php</code>',
        'b' => '<code>api/login/post.php</code>',
        'c' => '<code>src/Auth/Login.php</code>',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Bootstrap is one file: <code>app.php</code>. Three lines minimum; under 100 for most real apps.',
      '<code>public/</code> for filesystem-routed pages, <code>api/</code> for REST, <code>route/</code> for explicit routes, <code>template/</code> for views, <code>src/</code> for business logic.',
      'Routes and APIs should be thin — delegate to <code>src/</code> classes via PSR-4 autoloading.',
      'Everything before <code>$app->run()</code> happens once per worker. Everything inside a handler happens per request.',
      'Edits to <code>template/</code>, <code>api/</code>, <code>public/</code> reload on next request. Edits to <code>app.php</code>, <code>route/</code>, framework middleware need a restart.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/mental-model"
         hx-get="/api/learn/page?slug=learn/mental-model" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/mental-model">← The Mental Model</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/routes"
         hx-get="/api/learn/page?slug=learn/routes" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/routes">How Routes Work →</a>
    </div>
  </article>
</div>
