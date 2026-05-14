<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>
<section class="section">
<div class="container">
<h1 class="section-title">HTTP Protocol Features</h1>
<p class="section-desc">ZealPHP implements the full HTTP/1.1 feature set: conditional requests, content negotiation, proper method handling, and CORS.</p>

<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Feature</th><th>Status</th><th>How</th></tr>
  <tr><td>HEAD method</td><td>✅ Auto-mapped</td><td>ResponseMiddleware runs GET handler, strips body, adds Content-Length</td></tr>
  <tr><td>OPTIONS method</td><td>✅ Built-in</td><td>Returns 204 + Allow header with all methods registered for that URI</td></tr>
  <tr><td>ETag / 304</td><td>✅ Middleware</td><td>ETagMiddleware generates W/"md5", returns 304 on If-None-Match hit</td></tr>
  <tr><td>Gzip compression</td><td>✅ OpenSwoole</td><td><code>http_compression</code> handles bodies when Accept-Encoding includes gzip</td></tr>
  <tr><td>CORS</td><td>✅ Middleware</td><td>CorsMiddleware handles preflight + adds headers to every response</td></tr>
  <tr><td>Redirects 301/302/307/308</td><td>✅ Built-in</td><td><code>$response->redirect($url, $status)</code></td></tr>
  <tr><td>Cookie SameSite</td><td>✅ Built-in</td><td><code>setcookie($name, $value, ..., $samesite)</code></td></tr>
  <tr><td>HTTP/2</td><td>⚙️ Configure</td><td>Pass <code>'enable_http2' => true</code> to <code>$app->run()</code> (requires TLS)</td></tr>
  <tr><td>Range requests</td><td>✅ Middleware</td><td>RangeMiddleware handles single + multi-range (RFC 7233); <code>$response-&gt;sendFile()</code> for zero-copy file serving</td></tr>
</table>

<?php
$demos = [
  ['http-head',    'HEAD — headers only, body stripped',     'HEAD',    '/http/head-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Register GET route — HEAD works automatically:
$app->route('/http/head-test', function() {
    header('X-Custom-Header: zealphp');
    echo str_repeat('x', 2048);  // 2KB body
});

// curl -I __SITE_URL__/http/head-test
// → Content-Length: 2048 (no body)
// → X-Custom-Header: zealphp
PHP)],
  ['http-options', 'OPTIONS — Allow header for URI',          'OPTIONS', '/http/options-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
$app->route('/http/options-test', ['methods' => ['GET','POST','PUT']], fn() => '');

// curl -X OPTIONS __SITE_URL__/http/options-test -v
// → HTTP/1.1 204 No Content
// → Allow: OPTIONS, GET, HEAD, POST, PUT
PHP)],
  ['http-redirect','Redirects — 301/302/307/308',             'GET',     '/http/redirect/301',
   <<<'PHP'
// $response->redirect() sets Location + status
$app->route('/http/redirect/{code}', function($code, $response) {
    $response->redirect('/http/redirect-target', (int)$code);
});

// Auto-302 on Location header:
header('Location: https://example.com');
// ZealPHP detects Location: → sets status 302 automatically
PHP],
  ['http-range',   'Range — 206 Partial Content',             'GET',     '/http/range-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Any buffered response supports Range via RangeMiddleware:
$app->route('/http/range-test', function() {
    echo str_repeat('abcdefghij', 100);  // 1000 bytes
});

// curl -H 'Range: bytes=0-9' __SITE_URL__/http/range-test
// → HTTP/1.1 206 Partial Content
// → Content-Range: bytes 0-9/1000
// → abcdefghij
PHP)],
  ['http-sendfile', 'sendFile() — zero-copy file download',   'GET',     '/http/sendfile-test',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Serve files with zero-copy + automatic Range support:
$app->route('/download/{file}', function($file, $response) {
    $path = "/var/data/{$file}";
    $response->sendFile($path, $file);
});

// curl __SITE_URL__/http/sendfile-test
// → Content-Type: text/css
// → Accept-Ranges: bytes
//
// curl -H 'Range: bytes=0-99' __SITE_URL__/http/sendfile-test
// → HTTP/1.1 206 Partial Content
PHP)],
];
foreach ($demos as [$id, $title, $method, $url, $code]) {
    App::render('/components/_demo', ['id' => $id, 'title' => $title, 'url' => $url, 'code' => $code, 'method' => $method]);
}
?>
</div>
</section>
