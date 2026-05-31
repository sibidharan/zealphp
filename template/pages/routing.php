<?php use ZealPHP\App; ?>

<section class="section">
<div class="container">
<h1 class="section-title">Routing &amp; Parameter Injection</h1>
<p class="section-desc">ZealPHP uses reflection to inject route parameters, <code>$request</code>, <code>$response</code>, and <code>$app</code> into handlers by name — no annotations, no containers.</p>

<!-- File-based routing -->
<h2 class="route-h2">File-based routing — just like LAMP</h2>
<p class="route-mb-1">Drop a <code>.php</code> file in <code>public/</code>. It becomes a route. No config, no registration, no framework code needed.</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>Notes</th></tr>
  <tr><td><code>public/index.php</code></td><td><code>/</code></td><td>Root route</td></tr>
  <tr><td><code>public/about.php</code></td><td><code>/about</code></td><td>Filename becomes the path (no <code>.php</code>)</td></tr>
  <tr><td><code>public/users/list.php</code></td><td><code>/users/list</code></td><td>Subdirectories work</td></tr>
  <tr><td><code>public/admin/index.php</code></td><td><code>/admin</code></td><td>Directory index</td></tr>
</table>

<p class="route-my-sm">Inside these files, everything you already know works:</p>

<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — coroutine-safe form (works in both modes)',
    'code'  => <<<'PHP'
<?php
use ZealPHP\RequestContext;

$g = RequestContext::instance();
session_start();
if (empty($g->session['user'])) { header('Location: /login'); exit; }
?>
<h1>Welcome, <?= htmlspecialchars($g->session['user']['name']) ?></h1>
<p>Your orders: <?= count($g->get['filter'] ?? []) ?> filters active</p>
PHP
]); ?>

<div class="callout info route-mt-1">
<strong>This is the migration on-ramp.</strong> Drop your existing PHP files into <code>public/</code> and they run on OpenSwoole immediately — <code>session_start()</code>, <code>header()</code>, <code>echo</code> all work unchanged via ext-zealphp overrides. The recommended form is <code>$g-&gt;session</code> / <code>$g-&gt;get</code> via <code>RequestContext::instance()</code> — works in both modes, no extension needed. With ext-zealphp, <code>$_GET</code> / <code>$_SESSION</code> are also per-coroutine safe in both modes (saved/restored on every yield/resume). See the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a> and the <a href="/migration">migration ladder</a>.
</div>

<p class="route-my-1">Same convention works for APIs — drop files in <code>api/</code>:</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>Notes</th></tr>
  <tr><td><code>api/device/list.php</code></td><td><code>/api/device/list</code></td><td>Filename match — <code>$list</code> handles all methods</td></tr>
  <tr><td><code>api/device/add.php</code></td><td><code>/api/device/add</code></td><td>Filename match — <code>$add</code> handles all methods</td></tr>
  <tr><td><code>api/users.php</code></td><td><code>/api/users</code></td><td>Per-method — <code>$get</code>/<code>$post</code>/… handle their method; others get 405</td></tr>
</table>

<p class="route-my-sm">Two conventions. <strong>Filename match</strong>: the closure variable matches the filename — all HTTP methods reach it. <strong>Per-method</strong>: define <code>$get</code>, <code>$post</code>, <code>$put</code>, <code>$delete</code>, <code>$patch</code> — each handles its method, undefined ones return 405. See <a href="/api#per-method-dispatch">/api#per-method-dispatch</a>.</p>

<?php App::render('/components/_code', [
    'label' => 'api/device/list.php — filename match (all methods)',
    'code'  => <<<'PHP'
<?php
$list = function () {
    $this->response($this->json(['devices' => []]), 200);
};
PHP
]); ?>

<p class="route-mt-1">Public files ride the <a href="/responses#return-contract">universal return contract</a> — same shapes as a route handler.</p>

<h2 class="route-h2-section">Programmatic routes</h2>
<p class="route-mb-1">When you need URL parameters, WebSocket, or middleware — use programmatic routes. File-based routing handles the rest.</p>

<!-- Route types -->
<h2 class="route-h2">Route types</h2>
<table class="ztable">
  <tr><th>Method</th><th>Example</th><th>Use when</th></tr>
  <tr><td><code>route()</code></td><td><code>/users/{id}</code></td><td>Standard URL with named segments</td></tr>
  <tr><td><code>nsRoute()</code></td><td><code>/admin/users</code></td><td>Group routes under a namespace prefix</td></tr>
  <tr><td><code>nsPathRoute()</code></td><td><code>/api/v1/users/list</code></td><td>Namespace + catch-all last segment (includes slashes)</td></tr>
  <tr><td><code>patternRoute()</code></td><td><code>/raw/(?P&lt;rest&gt;.*)</code></td><td>Full regex control</td></tr>
  <tr><td><code>ws()</code></td><td><code>/ws/chat</code></td><td>WebSocket endpoint</td></tr>
</table>

<!-- Injection cases -->
<h2 class="route-h2">Parameter injection — every case</h2>
<p class="route-mb-1-5">All panels below auto-run against the live server. The handler signature determines what gets injected.</p>

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
    return ['id' => $id, 'method' => $request->server['request_method']];
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
    return ['method' => $request->server['request_method'],
            'uri'    => $request->server['request_uri']];
});
PHP],
  ['inject-5', 'All: $id + $request + $response','/demo/inject/all/123',
   <<<'PHP'
$app->route('/full/{id}', function($id, $request, $response) {
    $response->header('X-Injected', 'yes');
    return ['id' => $id, 'method' => $request->server['request_method'],
            'response_class' => get_class($response)];
});
PHP],
  ['inject-6', 'Default param value',            '/demo/inject/defaults/abc',
   <<<'PHP'
// ZealPHP has no optional-segment syntax like {page?}.
// Express "optional" params by registering a base route with a default:
$app->route('/paged/{id}', function($id, $page = 1) {
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
<h2 class="route-h2">Live route type demos</h2>
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

<h2 class="route-h2-top">Route priority</h2>
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

<h2 class="route-h2-top">Apache parity in public/ routing</h2>
<p class="route-mb-1">The implicit <code>public/</code> routes mirror Apache+mod_php's default DocumentRoot behavior — including the subtle directives most developers don't think about until something breaks. Each is on by default and toggleable via a static flag on <code>App</code>:</p>

<table class="ztable">
<tr><th>Apache directive</th><th>ZealPHP behavior</th><th>Flag</th></tr>
<tr>
  <td><code>DocumentRoot /path</code></td>
  <td>The folder every implicit route and the static handler resolve against — defaults to <code>public/</code></td>
  <td><code>App::documentRoot('public')</code> (set before <code>App::init()</code>)</td>
</tr>
<tr>
  <td><code>DirectorySlash On</code></td>
  <td><code>/docs</code> → <code>301 /docs/</code> when <code>docs</code> is a directory under <code>public/</code></td>
  <td><code>App::$directory_slash = true</code></td>
</tr>
<tr>
  <td><code>DirectoryIndex index.php index.html index.htm</code></td>
  <td>Walks the list in order; HTML/HTM served via <code>$response-&gt;sendFile()</code> so Range and ETag still work</td>
  <td><code>App::$directory_index</code> (array)</td>
</tr>
<tr>
  <td><code>AcceptPathInfo On</code></td>
  <td><code>/api.php/users/42</code> → <code>SCRIPT_NAME=/api.php</code>, <code>PATH_INFO=/users/42</code>; rewrites <code>REQUEST_URI</code> to just the script</td>
  <td><code>App::$path_info = true</code></td>
</tr>
<tr>
  <td><code>&lt;FilesMatch "^\.&gt;"</code> deny</td>
  <td>Any URL with a dotfile component (<code>.env</code>, <code>.git/config</code>) returns 403. <code>.well-known/</code> is allow-listed per RFC 8615.</td>
  <td><code>App::$block_dotfiles = true</code></td>
</tr>
<tr>
  <td>URL traversal rejection</td>
  <td><code>%2e%2e</code>, <code>\0</code>, backslash decoded and matched BEFORE route lookup → 400</td>
  <td>always on</td>
</tr>
<tr>
  <td>Static-handler URL whitelist</td>
  <td>At boot, <code>App::$static_handler_locations</code> defaults to <code>[]</code> (empty). When empty, the framework substitutes a safe whitelist: <code>/css/ /js/ /img/ /images/ /fonts/ /assets/ /static/ /favicon.ico /robots.txt</code>. Anything outside falls through to PHP routing.</td>
  <td><code>App::$static_handler_locations</code> (set before <code>run()</code>; <code>[]</code> = use default whitelist)</td>
</tr>
<tr>
  <td><code>ErrorDocument N /path</code></td>
  <td><code>App::instance()-&gt;setErrorHandler(404, $cb)</code> registers a per-status custom page; catch-all variant: <code>setErrorHandler($cb)</code>. See <a href="/responses">Responses</a>.</td>
  <td><code>App::$error_handlers</code> (private)</td>
</tr>
<tr>
  <td><code>FileETag</code> / <code>If-None-Match</code> / <code>If-Modified-Since</code></td>
  <td><code>$response-&gt;sendFile()</code> emits weak ETag (<code>W/"mtime-size"</code>) and <code>Last-Modified</code>; matches return 304. Range request honored on the same path.</td>
  <td>always on for <code>sendFile()</code></td>
</tr>
</table>

<div class="callout info route-mt-1">
<strong>For ETag on static assets too</strong>, disable OpenSwoole's built-in static handler (<code>enable_static_handler =&gt; false</code> in <code>$app-&gt;run()</code> settings) and add a wildcard route that calls <code>$response-&gt;sendFile()</code>. The built-in handler emits <code>Last-Modified</code> only — no ETag, no Range. The trade-off is a small per-request PHP hop. See the <a href="/http#parity">Apache parity</a> section.
</div>

<h2 class="route-h2-top">Pattern routes with named regex groups</h2>
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
