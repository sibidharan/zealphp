<?php use ZealPHP\App; $active = $active ?? 'learn/routing'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 5,
      'title'    => 'Routing',
      'subtitle' => 'Four ways to register a URL. Pick the one that fits.',
      'prev'     => ['slug' => 'learn/components', 'title' => 'Components'],
      'next'     => ['slug' => 'learn/sessions', 'title' => 'Sessions & Auth'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Implicit public/ file routing — drop a file, get a URL',
      'Implicit api/ file routing — file-based REST handlers via ZealAPI',
      'Explicit routes with $app->route()',
      'Dynamic path params and method matching',
    ]]); ?>

    <h2>1. Implicit public routes</h2>
    <p>Anything in <code>public/</code> is served as a page or static asset. We covered this in Lesson 3.</p>
    <pre><code>public/index.php       → GET /
public/about.php       → GET /about
public/blog/post.php   → GET /blog/post
public/css/site.css    → GET /css/site.css</code></pre>

    <h2>2. Implicit API routes (ZealAPI)</h2>
    <p>Files under <code>api/</code> become REST endpoints. The file's basename becomes both the URL segment <em>and</em> the closure variable name:</p>
    <pre><code>// api/users/list.php
&lt;?php
${basename(__FILE__, '.php')} = function () {
    $this-&gt;response($this-&gt;json([
        ['id' =&gt; 1, 'name' =&gt; 'Alice'],
        ['id' =&gt; 2, 'name' =&gt; 'Bob'],
    ]), 200);
};
// → GET /api/users/list</code></pre>

    <?php App::render('/components/_tryit', ['title' => 'ZealAPI in action', 'body' => <<<HTML
      <p>This very page ships a real ZealAPI handler at <code>api/learn/chat_status.php</code>. URL <code>GET /api/learn/chat_status</code> maps to that file:</p>
      <pre><code>// api/learn/chat_status.php
\${basename(__FILE__, '.php')} = function () {
    \$key = (string)(getenv('OPENAI_API_KEY') ?: '');
    \$this->response(\$this->json([
        'ai_enabled' => \$key !== '',
        'mock_mode'  => \$key === '',
    ]), 200);
};</code></pre>
      <p><a class="lesson-chip" href="/api/learn/chat_status" target="_blank">Call /api/learn/chat_status →</a></p>
      <p>The variable name (<code>\$status</code>) must match the file's basename. Inside the closure, <code>\$this</code> is the ZealAPI instance with helpers like <code>response()</code>, <code>json()</code>, <code>paramsExists()</code>.</p>
HTML]); ?>

    <h2>3. Explicit routes</h2>
    <p>When you need full control — custom HTTP methods, path patterns, middleware — register an explicit route in a file under <code>route/</code> (loaded at startup):</p>
    <pre><code>// route/users.php
use ZealPHP\App;
$app = App::instance();

$app->route('/users/{id}', ['methods' =&gt; ['GET']], function($request, $response, $id) {
    return ['id' =&gt; (int)$id, 'name' =&gt; 'User ' . $id];
});

$app->route('/users', ['methods' =&gt; ['POST']], function($request, $response) {
    // POST body via $g->zealphp_request->parent->getContent()
    return ['created' =&gt; true];
});</code></pre>

    <h2>4. Namespaced routes</h2>
    <p><code>nsRoute</code> and <code>nsPathRoute</code> add a path prefix. <code>nsPathRoute</code> additionally captures everything after the prefix as a single parameter:</p>
    <pre><code>$app->nsRoute('api/v2', '/health', function() {
    return ['ok' =&gt; true];
});
// → GET /api/v2/health

$app->nsPathRoute('files', function($path) {
    return ['path' =&gt; $path];
});
// → GET /files/foo/bar/baz.txt  → ['path' =&gt; 'foo/bar/baz.txt']</code></pre>

    <h2>Parameter injection</h2>
    <p>ZealPHP injects route handler arguments by <em>name</em> via reflection (cached at registration — zero per-request overhead):</p>
    <pre><code>| Parameter name   | Injected value                       |
| ---------------- | ------------------------------------ |
| $request         | ZealPHP\HTTP\Request                 |
| $response        | ZealPHP\HTTP\Response                |
| $app             | ResponseMiddleware instance          |
| {param} captures | matched URL segment                  |
| any other        | null or the parameter's default      |</code></pre>

    <h2>Return value conventions</h2>
    <pre><code>| Return type    | Behavior                                  |
| -------------- | ----------------------------------------- |
| int            | HTTP status code (e.g. return 404)        |
| array / object | JSON-serialized, Content-Type set         |
| string         | HTML body                                 |
| Generator      | SSR streaming (each yield sent live)      |
| void + echo    | Output buffer captured                    |</code></pre>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/components">← Components</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/sessions">Sessions & Auth →</a>
    </div>
  </article>
</div>
