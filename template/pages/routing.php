<?php use ZealPHP\App; ?>

<section class="section">
<div class="container">
<h1 class="section-title">Routing &amp; Parameter Injection</h1>
<p class="section-desc">ZealPHP uses reflection to inject route parameters, <code>$request</code>, <code>$response</code>, and <code>$app</code> into handlers by name — no annotations, no containers.</p>

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

</div>
</section>
