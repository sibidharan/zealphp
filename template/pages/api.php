<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">ZealAPI — File-Based REST</h1>
<p class="section-desc">Drop a PHP file in <code>api/</code> and it becomes an endpoint automatically. The file defines a closure named after the HTTP method. <code>$this</code> inside the closure is the ZealAPI instance.</p>

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

<h2 style="margin:1.5rem 0 .5rem">Routing convention</h2>
<table class="ztable" style="margin-bottom:2rem">
  <tr><th>File</th><th>Variable</th><th>Endpoint</th></tr>
  <tr><td><code>api/users/get.php</code></td><td><code>$get = function(){}</code></td><td><code>GET /api/users/get</code></td></tr>
  <tr><td><code>api/orders/create.php</code></td><td><code>$create = function(){}</code></td><td><code>POST /api/orders/create</code></td></tr>
  <tr><td><code>api/device/list.php</code></td><td><code>$list = function(){}</code></td><td><code>GET /api/device/list</code></td></tr>
  <tr><td><code>api/php/sapi_name.php</code></td><td><code>$sapi_name = function(){}</code></td><td><code>GET /api/php/sapi_name</code></td></tr>
</table>

<h2 style="margin:1.5rem 0 .5rem">Live ZealAPI endpoints</h2>
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

<div class="callout info" style="margin-top:1.5rem">
  <strong>$this in closures</strong> — ZealAPI uses <code>Closure::bind()</code> to set <code>$this</code> to the ZealAPI instance.
  This gives you access to <code>$this->_request</code>, <code>$this->_response</code>, and helper methods like
  <code>$this->paramsExists(['id', 'name'])</code>.
</div>
</div>
</section>
