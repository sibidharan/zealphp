<?php use ZealPHP\App; $active = $active ?? 'learn/routing'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 12,
      'title'    => 'Routes & APIs',
      'subtitle' => 'Four routing patterns. Pick the one that fits your use case.',
      'prev'     => ['slug' => 'learn/websocket', 'title' => 'Real-Time Sync'],
      'next'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Four ways to register URLs: implicit public, ZealAPI, explicit routes, namespaced',
      'Dynamic path parameters like /users/{id}',
      'How ZealPHP injects parameters by name via reflection',
      'Return value conventions: int, array, string, Generator',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      So far, you've used implicit routing: drop a file in <code>public/</code>, get a URL. That
      covers pages. You've also used ZealAPI files in <code>api/</code> for REST endpoints. But what
      about URLs like <code>/users/42</code>? Or restricting a route to POST only? Or versioning
      an API?
    </p>
    <p>ZealPHP has four routing patterns. You've already used two &mdash; here are all four.</p>

    <h2>1. Implicit public routes</h2>
    <p>You learned this in Lesson 3. Files in <code>public/</code> become URLs automatically:</p>
    <pre><code>public/index.php       &rarr; GET /
public/about.php       &rarr; GET /about
public/blog/post.php   &rarr; GET /blog/post</code></pre>
    <p><strong>Use for:</strong> Pages the user visits. Simple, zero config.</p>

    <h2>2. Implicit API routes (ZealAPI)</h2>
    <p>You used this in Lesson 8. Files in <code>api/</code> become REST endpoints:</p>
    <pre><code class="language-php">// api/learn/notes.php &rarr; GET/POST /api/learn/notes
$notes = function () {
    $u = Auth::currentUser();
    // ... handle GET (list) and POST (create)
};</code></pre>
    <p>The closure variable name (<code>$notes</code>) must match the filename. Inside, <code>$this</code> is the ZealAPI instance with helpers like <code>response()</code>, <code>json()</code>.</p>
    <p><strong>Use for:</strong> REST endpoints. One file per resource, auto-routed.</p>

    <h2>3. Explicit routes</h2>
    <p>When you need path parameters or method restrictions, register explicit routes in a file under <code>route/</code>:</p>
    <pre><code class="language-php">// route/users.php
$app->route('/users/{id}', ['methods' => ['GET']], function($id) {
    return ['id' => (int)$id, 'name' => 'User ' . $id];
});

$app->route('/users', ['methods' => ['POST']], function($request) {
    return ['created' => true];
});</code></pre>
    <p><strong>Use for:</strong> Dynamic URLs, method-specific routes, WebSocket handlers, Store table registration.</p>

    <h2>4. Namespaced routes</h2>
    <p><code>nsRoute</code> and <code>nsPathRoute</code> add a URL prefix:</p>
    <pre><code class="language-php">$app->nsRoute('api/v2', '/health', function() {
    return ['ok' => true];
});
// &rarr; GET /api/v2/health

$app->nsPathRoute('files', function($path) {
    return ['path' => $path];
});
// &rarr; GET /files/foo/bar/baz.txt &rarr; $path = 'foo/bar/baz.txt'</code></pre>
    <p><strong>Use for:</strong> API versioning, catch-all paths (file serving, proxy).</p>

    <h2>Parameter injection</h2>
    <p>
      ZealPHP injects route handler arguments <strong>by name</strong> via reflection. The reflection
      result is cached at route registration &mdash; zero per-request overhead:
    </p>
    <pre><code>| Parameter name | Injected value                    |
| -------------- | --------------------------------- |
| $request       | ZealPHP\HTTP\Request              |
| $response      | ZealPHP\HTTP\Response             |
| $app           | ResponseMiddleware instance       |
| {param} names  | Matched URL segments              |
| any other      | null or the parameter's default   |</code></pre>
    <p>This means parameter <em>order doesn't matter</em>. <code>function($id, $request)</code> and <code>function($request, $id)</code> both work.</p>

    <h2>Return value conventions</h2>
    <pre><code>| Return type    | Behavior                             |
| -------------- | ------------------------------------ |
| int            | HTTP status code (e.g. return 404)   |
| array / object | JSON-serialized, Content-Type set    |
| string         | HTML body                            |
| Generator      | SSR streaming (each yield sent live) |
| void + echo    | Output buffer captured               |</code></pre>

    <?php App::render('/components/_tryit', ['title' => 'Live routing demos', 'body' => <<<HTML
      <p>This site uses all four routing patterns. Explore them:</p>
      <ul>
        <li><a href="/api/learn/chat_status" target="_blank">/api/learn/chat_status</a> &mdash; ZealAPI endpoint (implicit API)</li>
        <li><a href="/api/learn/demo/greeting?name=World" target="_blank">/api/learn/demo/greeting?name=World</a> &mdash; Explicit route</li>
        <li><a href="/api/learn/demo/timing?mode=parallel" target="_blank">/api/learn/demo/timing?mode=parallel</a> &mdash; Explicit route returning JSON</li>
      </ul>
HTML]); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'route1',
      'question' => 'You need a REST endpoint at /api/products that handles GET and POST. Which routing pattern should you use?',
      'correct'  => 'b',
      'explain'  => 'ZealAPI (api/ directory) is designed for REST endpoints. Create api/products.php and it auto-routes to /api/products.',
      'options'  => [
        'a' => 'A file in public/api/products.php',
        'b' => 'A file in api/products.php (ZealAPI)',
        'c' => 'An explicit route in route/products.php',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Four routing patterns: implicit public, ZealAPI, explicit, namespaced &mdash; each for a different use case',
      'Path parameters (<code>{id}</code>) are injected by name &mdash; order doesn\'t matter',
      'Reflection is cached at registration &mdash; zero per-request cost',
      'Return type determines response format: array &rarr; JSON, string &rarr; HTML, Generator &rarr; streaming',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/websocket"
         hx-get="/api/learn/page?slug=learn/websocket" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/websocket">&larr; Real-Time Sync</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/async"
         hx-get="/api/learn/page?slug=learn/async" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/async">Async &amp; Coroutines &rarr;</a>
    </div>
  </article>
</div>
