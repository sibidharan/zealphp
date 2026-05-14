<?php use ZealPHP\App; ?>

<section class="section">
<div class="container">
<h1 class="section-title">Routing &amp; Parameter Injection</h1>
<p class="section-desc">ZealPHP uses reflection to inject route parameters, <code>$request</code>, <code>$response</code>, and <code>$app</code> into handlers by name — no annotations, no containers.</p>

<!-- File-based routing -->
<h2 style="margin:2rem 0 .5rem">File-based routing — just like LAMP</h2>
<p style="margin-bottom:1rem">Drop a <code>.php</code> file in <code>public/</code>. It becomes a route. No config, no registration, no framework code needed.</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>Notes</th></tr>
  <tr><td><code>public/index.php</code></td><td><code>/</code></td><td>Root route</td></tr>
  <tr><td><code>public/about.php</code></td><td><code>/about</code></td><td>Filename becomes the path (no <code>.php</code>)</td></tr>
  <tr><td><code>public/users/list.php</code></td><td><code>/users/list</code></td><td>Subdirectories work</td></tr>
  <tr><td><code>public/admin/index.php</code></td><td><code>/admin</code></td><td>Directory index</td></tr>
</table>

<p style="margin:.75rem 0">Inside these files, everything you already know works:</p>

<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — plain PHP, nothing new',
    'code'  => <<<'PHP'
<?php
session_start();
if (!$_SESSION['user']) { header('Location: /login'); exit; }
?>
<h1>Welcome, <?= htmlspecialchars($_SESSION['user']['name']) ?></h1>
<p>Your orders: <?= count($_GET['filter'] ?? []) ?> filters active</p>
PHP
]); ?>

<div class="callout info" style="margin-top:1rem">
<strong>This is the migration on-ramp.</strong> Move your existing PHP files into <code>public/</code> and they run on OpenSwoole immediately — <code>session_start()</code>, <code>header()</code>, <code>$_GET</code>, <code>$_POST</code>, <code>echo</code> all work unchanged via uopz overrides. No rewrite needed.
</div>

<p style="margin:1rem 0">Same convention works for APIs — drop files in <code>api/</code>:</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>HTTP method</th></tr>
  <tr><td><code>api/users/get.php</code></td><td><code>GET /api/users</code></td><td>Filename = method</td></tr>
  <tr><td><code>api/users/post.php</code></td><td><code>POST /api/users</code></td><td>Filename = method</td></tr>
  <tr><td><code>api/orders/get.php</code></td><td><code>GET /api/orders</code></td><td>Directory = resource</td></tr>
</table>

<p style="margin-top:1rem">Public files support the same return conventions as framework routes — return an <code>array</code> for JSON, a <code>Generator</code> for streaming, an <code>int</code> for a status code, or just <code>echo</code>. See <a href="/responses">Responses</a>.</p>

<h2 style="margin:2.5rem 0 .5rem">Programmatic routes</h2>
<p style="margin-bottom:1rem">When you need URL parameters, WebSocket, or middleware — use programmatic routes. File-based routing handles the rest.</p>

<!-- Route types -->
<h2 style="margin:2rem 0 .5rem">Route types</h2>
<table class="ztable">
  <tr><th>Method</th><th>Example</th><th>Use when</th></tr>
  <tr><td><code>route()</code></td><td><code>/users/{id}</code></td><td>Standard URL with named segments</td></tr>
  <tr><td><code>nsRoute()</code></td><td><code>/admin/users</code></td><td>Group routes under a namespace prefix</td></tr>
  <tr><td><code>nsPathRoute()</code></td><td><code>/api/v1/users/list</code></td><td>Namespace + catch-all last segment (includes slashes)</td></tr>
  <tr><td><code>patternRoute()</code></td><td><code>/raw/(?P&lt;rest&gt;.*)</code></td><td>Full regex control</td></tr>
  <tr><td><code>ws()</code></td><td><code>/ws/chat</code></td><td>WebSocket endpoint</td></tr>
</table>

<!-- Injection cases -->
<h2 style="margin:2rem 0 .5rem">Parameter injection — every case</h2>
<p style="margin-bottom:1.5rem">All panels below auto-run against the live server. The handler signature determines what gets injected.</p>

<?php
$cases = [
  ['inject-1', 'URL param only',                '/demo/inject/url/42',
   <<<'PHP'
$app->route('/users/{id}', function($id) {
    return ['id' => $id];
});
PHP],
  ['inject-2', 'URL param + $request',          '/demo/inject/url-request/99',
   <<<'PHP'
$app->route('/users/{id}', function($id, $request) {
    return ['id' => $id, 'method' => $request->server['REQUEST_METHOD']];
});
PHP],
  ['inject-3', 'URL param + $response',         '/demo/inject/url-response/7',
   <<<'PHP'
$app->route('/users/{id}', function($id, $response) {
    $response->header('X-User-Id', $id);
    return ['id' => $id, 'response_class' => get_class($response)];
});
PHP],
  ['inject-4', '$request only',                  '/demo/inject/request-only',
   <<<'PHP'
$app->route('/info', function($request) {
    return ['method' => $request->server['REQUEST_METHOD'],
            'uri'    => $request->server['REQUEST_URI']];
});
PHP],
  ['inject-5', 'All: $id + $request + $response','/demo/inject/all/123',
   <<<'PHP'
$app->route('/full/{id}', function($id, $request, $response) {
    $response->header('X-Injected', 'yes');
    return ['id' => $id, 'method' => $request->server['REQUEST_METHOD'],
            'response_class' => get_class($response)];
});
PHP],
  ['inject-6', 'Default param value',            '/demo/inject/defaults/abc',
   <<<'PHP'
$app->route('/paged/{id}/{page?}', function($id, $page = 1) {
    return ['id' => $id, 'page' => $page];  // page defaults to 1
});
PHP],
  ['inject-7', 'Default overridden by URL',      '/demo/inject/defaults/abc/5',
   <<<'PHP'
// Same handler — page is 5 from URL
$app->route('/paged/{id}/{page}', function($id, $page = 1) {
    return ['id' => $id, 'page' => $page];
});
PHP],
];
foreach ($cases as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<!-- Route type demos -->
<h2 style="margin:2rem 0 .5rem">Live route type demos</h2>
<?php
$routeTypes = [
  ['rt-1', 'nsRoute — /demo/route/ns/items',                '/demo/route/ns/items',
   '$app->nsRoute(\'demo\', \'/route/ns/items\', function() {' . "\n" .
   '    return [\'route_type\' => \'nsRoute\', \'prefix\' => \'demo\'];' . "\n" .
   '});'],
  ['rt-2', 'nsPathRoute — catches full path after prefix',   '/demo/route/ns-path/api/v1/users/list',
   '$app->nsPathRoute(\'demo/route/ns-path\', \'{path}\', function($path) {' . "\n" .
   '    return [\'route_type\' => \'nsPathRoute\', \'captured\' => $path];' . "\n" .
   '});'],
  ['rt-3', 'patternRoute — regex match',                     '/demo/route/pattern',
   '$app->patternRoute(\'/demo/route/pattern\', function() {' . "\n" .
   '    return [\'route_type\' => \'patternRoute\'];' . "\n" .
   '});'],
];
foreach ($routeTypes as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 style="margin-top:2.5rem">Route priority</h2>
<p>Routes are matched in this order — the first match wins. Earlier in the list = higher priority:</p>

<table class="ztable">
<tr><th>#</th><th>Source</th><th>Loaded</th></tr>
<tr><td>1</td><td>Files in <code>route/*.php</code></td><td>At server startup (auto-included via <code>glob</code>)</td></tr>
<tr><td>2</td><td>Explicit <code>$app->route()</code> in <code>app.php</code></td><td>Before <code>$app->run()</code></td></tr>
<tr><td>3</td><td>Implicit API: <code>/api/{module}/{request}</code></td><td>Inside <code>$app->run()</code></td></tr>
<tr><td>4</td><td>Implicit public files: <code>/</code>, <code>/{file}</code>, <code>/{dir}/{uri}</code></td><td>Inside <code>$app->run()</code></td></tr>
<tr><td>5</td><td>Fallback handler (if <code>setFallback()</code> registered)</td><td>When nothing else matches</td></tr>
</table>

<div class="callout info">
<strong>Override implicit routes</strong> by placing a file in <code>route/</code>. For example, to customize <code>/admin/users</code> instead of letting it auto-resolve to <code>public/admin/users.php</code>, define an explicit route in <code>route/admin.php</code> — it loads first and takes precedence.
</div>

<h2 style="margin-top:2.5rem">Pattern routes with named regex groups</h2>
<p><code>patternRoute</code> accepts any regex with named capture groups (PCRE <code>(?P&lt;name&gt;...)</code> syntax). Captured names are injected as handler parameters:</p>

<?php App::render('/components/_code', [
    'label' => 'Named capture group → handler parameter',
    'code'  => <<<'PHP'
// Match any URL starting with /raw/
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function($rest) {
    echo "You requested: $rest";
    return 202;
});

// Multiple groups
$app->patternRoute('/blog/(?P<year>\d{4})/(?P<slug>[a-z-]+)', function($year, $slug) {
    return ['year' => $year, 'slug' => $slug];
});

// Block .php extension entirely
$app->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
    $response->status(403);
    $response->write("403 Forbidden");
});
PHP]); ?>

</div>
</section>
