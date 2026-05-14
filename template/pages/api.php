<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">ZealAPI — File-Based REST</h1>
<p class="section-desc">Drop a PHP file in <code>api/</code> and it becomes an endpoint automatically. The file defines a closure named after the HTTP method. <code>$this</code> inside the closure is the ZealAPI instance.</p>

<h2>How it works</h2>

<?php App::render('/components/_code', [
    'label' => 'api/users/get.php → GET /api/users/get',
    'code'  => <<<'PHP'
<?php
// File: api/users/get.php
// Endpoint: GET /api/users/get
// The variable name MUST match basename($file, '.php') → 'get'

use ZealPHP\G;

$get = function() {
    $g = G::instance();
    return [
        'users'  => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']],
        'method' => $g->server['REQUEST_METHOD'],
        'query'  => $g->get,
    ];
};
PHP]); ?>

<h2>File naming convention</h2>
<table class="ztable">
  <tr><th>File</th><th>Variable</th><th>Endpoint</th><th>HTTP method</th></tr>
  <tr><td><code>api/users/get.php</code></td><td><code>$get</code></td><td><code>GET /api/users/get</code></td><td>GET</td></tr>
  <tr><td><code>api/users/create.php</code></td><td><code>$create</code></td><td><code>POST /api/users/create</code></td><td>POST</td></tr>
  <tr><td><code>api/users/update.php</code></td><td><code>$update</code></td><td><code>PUT /api/users/update</code></td><td>PUT</td></tr>
  <tr><td><code>api/users/delete.php</code></td><td><code>$delete</code></td><td><code>DELETE /api/users/delete</code></td><td>DELETE</td></tr>
  <tr><td><code>api/data/list.php</code></td><td><code>$list</code></td><td><code>GET /api/data/list</code></td><td>GET</td></tr>
</table>

<div class="callout info">
The variable name <strong>must match</strong> the filename (without <code>.php</code>). <code>api/users/get.php</code> defines <code>$get = function() { ... };</code>. ZealAPI binds it as a Closure with <code>$this</code> set to the ZealAPI instance.
</div>

<h2>Return value conventions</h2>
<p>Same as route handlers — return type determines the response:</p>
<table class="ztable">
<tr><th>Return</th><th>Result</th></tr>
<tr><td><code>array</code> / <code>object</code></td><td>JSON-serialized with <code>Content-Type: application/json</code></td></tr>
<tr><td><code>string</code></td><td>Sent as-is (HTML or plain text)</td></tr>
<tr><td><code>int</code></td><td>HTTP status code</td></tr>
<tr><td><code>Generator</code></td><td>SSR streaming — each yield sent immediately</td></tr>
<tr><td><code>ResponseInterface</code></td><td>PSR-7 response used directly</td></tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'api/products/list.php — return array for JSON',
    'code'  => <<<'PHP'
<?php
$list = function() {
    return [
        'products' => [
            ['id' => 1, 'name' => 'Widget', 'price' => 9.99],
            ['id' => 2, 'name' => 'Gadget', 'price' => 24.99],
        ],
        'total' => 2,
    ];
};
// → {"products": [...], "total": 2}
PHP]); ?>

<h2>Parameter injection</h2>
<p>API handlers get the same parameter injection as route handlers:</p>

<?php App::render('/components/_code', [
    'label' => 'Magic parameters: $request, $response, $app, $server',
    'code'  => <<<'PHP'
<?php
$get = function($request, $response) {
    // $request  → ZealPHP\HTTP\Request (incoming request)
    // $response → ZealPHP\HTTP\Response (outgoing response)
    // $this     → ZealAPI instance (via Closure::bind)

    $id = $this->_request->get['id'] ?? null;
    if (!$id) return 400;

    return ['user' => User::find($id)];
};
PHP]); ?>

<h2>Streaming from APIs</h2>
<p>API handlers can return Generators for streaming responses:</p>

<?php App::render('/components/_code', [
    'label' => 'api/feed/stream.php — streaming JSON array',
    'code'  => <<<'PHP'
<?php
$stream = function() {
    return (function() {
        yield '{"events":[';
        $first = true;
        foreach (Event::cursor() as $event) {
            if (!$first) yield ',';
            yield json_encode($event->toArray());
            $first = false;
        }
        yield ']}';
    })();
};
PHP]); ?>

<h2>$this methods (ZealAPI instance)</h2>
<table class="ztable">
<tr><th>Property / Method</th><th>Description</th></tr>
<tr><td><code>$this->_request</code></td><td>The raw OpenSwoole HTTP request</td></tr>
<tr><td><code>$this->_response</code></td><td>The raw OpenSwoole HTTP response</td></tr>
<tr><td><code>$this->paramsExists(['id', 'name'])</code></td><td>Check required params exist in GET/POST</td></tr>
<tr><td><code>$this->response($data, $status)</code></td><td>Send response with status code</td></tr>
<tr><td><code>$this->die($exception)</code></td><td>Handle exception and send error response</td></tr>
<tr><td><code>$this->get_request_method()</code></td><td>Returns GET, POST, PUT, DELETE</td></tr>
<tr><td><code>$this->setContentType($type)</code></td><td>Set response content type</td></tr>
</table>

<h2>Live ZealAPI endpoints</h2>
<?php
$demos = [
  ['api-sapi', 'GET /api/php/sapi_name — returns SAPI name', '/api/php/sapi_name', <<<'PHP'
// api/php/sapi_name.php
$sapi_name = function() {
    return ['sapi' => php_sapi_name(), 'async' => true];
};
PHP],
  ['api-get',  'GET /api/php/get — dump GET params',          '/api/php/get?demo=zealapi&works=true', <<<'PHP'
// api/php/get.php
$get = function() {
    $g = G::instance();
    return ['query_params' => $g->get, 'async' => php_sapi_name() === 'cli'];
};
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2>Implicit public/ file serving</h2>
<p>Files in <code>public/</code> are served automatically — no route definition needed:</p>

<table class="ztable">
<tr><th>File</th><th>URL</th><th>How</th></tr>
<tr><td><code>public/index.php</code></td><td><code>/</code></td><td>Root route</td></tr>
<tr><td><code>public/about.php</code></td><td><code>/about</code></td><td>Filename → path (no <code>.php</code>)</td></tr>
<tr><td><code>public/admin/index.php</code></td><td><code>/admin/</code></td><td>Directory index</td></tr>
<tr><td><code>public/admin/users.php</code></td><td><code>/admin/users</code></td><td>Nested path</td></tr>
<tr><td><code>public/css/style.css</code></td><td><code>/css/style.css</code></td><td>Static file (served by OpenSwoole directly)</td></tr>
</table>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1.5rem 0">
<div>
<?php App::render('/components/_code', [
    'label' => 'public/about.php — 3-line page',
    'code'  => <<<'PHP'
<?php use ZealPHP\App;
App::render('_master', [
    'title' => 'About Us',
    'page'  => 'about',
]);
PHP]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — streaming page',
    'code'  => <<<'PHP'
<?php
use ZealPHP\App;
// Return a Generator → streams to browser
return (function() {
    yield App::renderToString('shell-open',
        ['title' => 'Dashboard']);
    yield "<h1>Dashboard</h1>";
    yield App::renderToString('shell-close');
})();
PHP]); ?>
</div>
</div>

<p>Public files can return <strong>Generators</strong> for streaming, <strong>arrays</strong> for JSON, or just <code>echo</code> for buffered output — same return conventions as route handlers.</p>

<div class="callout info">
<strong>Static files</strong> (CSS, JS, images, fonts) in <code>public/</code> are served directly by OpenSwoole's <code>enable_static_handler</code> — they never hit PHP. Only <code>.php</code> files are processed by ZealPHP.
</div>

<h2>Task workers</h2>
<p>Task workers run CPU-intensive or background work without blocking HTTP workers. Dispatch tasks from any request handler; task handlers live in <code>task/</code>.</p>

<?php App::render('/components/_code', [
    'label' => 'task/backup.php — define a task handler',
    'code'  => <<<'PHP'
<?php
// File: task/backup.php
// The variable name must match basename → 'backup'

use function ZealPHP\elog;

$backup = function($db_name, $output_dir) {
    elog("Starting backup of $db_name to $output_dir");

    // Heavy work here — runs in task worker, not HTTP worker
    $file = "$output_dir/$db_name-" . date('Ymd-His') . ".sql";
    exec("mysqldump $db_name > $file");

    return ['status' => 'done', 'file' => $file];
};
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'Dispatch from a route handler',
    'code'  => <<<'PHP'
use ZealPHP\App;

$app->route('/admin/backup', ['methods' => ['POST']], function() {
    // Dispatch to task worker (non-blocking)
    App::getServer()->task([
        'handler' => '/task/backup',
        'args'    => ['my_database', '/backups'],
    ]);

    return ['queued' => true, 'message' => 'Backup started in background'];
});
PHP]); ?>

<h3>Task worker configuration</h3>
<?php App::render('/components/_code', [
    'label' => 'Enable task workers in app.php',
    'code'  => <<<'PHP'
$app->run([
    'task_worker_num' => 4,            // 4 dedicated task workers
    'task_enable_coroutine' => true,   // Coroutines in task workers (default)
]);
PHP]); ?>

<table class="ztable">
<tr><th>Concept</th><th>Detail</th></tr>
<tr><td>Handler naming</td><td>File <code>task/backup.php</code> defines <code>$backup = function(...) { ... }</code></td></tr>
<tr><td>Dispatch</td><td><code>App::getServer()->task(['handler' => '/task/backup', 'args' => [...]])</code></td></tr>
<tr><td>Return value</td><td>Received in the <code>finish</code> callback (logged by default)</td></tr>
<tr><td>Coroutines</td><td>Task workers run in coroutine mode — <code>go()</code>, channels, async I/O all work</td></tr>
<tr><td>Blocking safety</td><td>Tasks run in separate worker processes — CPU-bound work doesn't block HTTP</td></tr>
</table>

<div class="callout warn">
<strong>Default: 0 task workers.</strong> Set <code>task_worker_num</code> in <code>$app->run()</code> if you use task dispatch. Without task workers, <code>$server->task()</code> will fail silently.
</div>

</div>
</section>
