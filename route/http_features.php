<?php
/**
 * HTTP Protocol Features — Demo routes
 *
 * Demonstrates: redirects, CORS, HEAD, OPTIONS, ETag, compression, cookies.
 * WebSocket demo lives in route/ws.php.
 * All routes are at /http/* so they don't clash with existing routes.
 */

use ZealPHP\App;
use ZealPHP\G;

$app = App::instance();

// ---------------------------------------------------------------------------
// 1. Redirects — 301, 302, 307, 308
// ---------------------------------------------------------------------------
$app->route('/http/redirect/{code}', ['methods' => ['GET']], function($code, $response) {
    $codes = ['301' => 'Moved Permanently', '302' => 'Found',
              '307' => 'Temporary Redirect', '308' => 'Permanent Redirect'];
    $status = isset($codes[$code]) ? (int)$code : 302;
    $response->redirect('/http/redirect-target?from=' . $code, $status);
});

$app->route('/http/redirect-target', ['methods' => ['GET']], function() {
    $g = G::instance();
    echo '<h2>Redirect landed here ✓</h2>';
    echo '<p>from=' . htmlspecialchars($g->get['from'] ?? '?') . '</p>';
    echo '<p><a href="/http">← Back</a></p>';
});

// ---------------------------------------------------------------------------
// 2. CORS — returns JSON so browser JS can fetch it cross-origin
//    CorsMiddleware must be registered in app.php for headers to appear.
// ---------------------------------------------------------------------------
$app->route('/http/cors-data', ['methods' => ['GET', 'POST']], function() {
    return ['message' => 'CORS works', 'time' => date('H:i:s'), 'server' => 'ZealPHP'];
});

// ---------------------------------------------------------------------------
// 3. HEAD — same route as GET, body is stripped automatically
//    curl -I http://localhost:8080/http/head-test
// ---------------------------------------------------------------------------
$app->route('/http/head-test', ['methods' => ['GET']], function() {
    header('X-Custom-Header: zealphp');
    header('Content-Type: text/plain');
    echo str_repeat('x', 2048); // 2KB body — HEAD strips this, only headers sent
});

// ---------------------------------------------------------------------------
// 4. OPTIONS — returns 204 + Allow header automatically
//    curl -X OPTIONS http://localhost:8080/http/options-test -v
// ---------------------------------------------------------------------------
$app->route('/http/options-test', ['methods' => ['GET', 'POST', 'PUT']], function() {
    return ['ok' => true];
});

// ---------------------------------------------------------------------------
// 5. ETag / 304 — add ETagMiddleware in app.php to enable
//    First request gets ETag header.
//    curl -H 'If-None-Match: <etag>' → 304
// ---------------------------------------------------------------------------
$app->route('/http/etag-test', ['methods' => ['GET']], function() {
    // Static content → same ETag every time → 304 on repeated requests
    return ['data' => 'This content never changes', 'version' => '1.0.0'];
});

// ---------------------------------------------------------------------------
// 6. Compression — add CompressionMiddleware in app.php to enable
//    curl --compressed http://localhost:8080/http/compress-test
// ---------------------------------------------------------------------------
$app->route('/http/compress-test', ['methods' => ['GET']], function() {
    // ~4KB of compressible text — well above the 1KB threshold
    $lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ';
    echo '<html><body><pre>' . str_repeat($lorem, 72) . '</pre></body></html>';
});

// ---------------------------------------------------------------------------
// 7. Cookie SameSite — now supported via setcookie() and $response->cookie()
// ---------------------------------------------------------------------------
$app->route('/http/cookie-test', ['methods' => ['GET']], function($response) {
    // Via override setcookie() — now passes SameSite
    setcookie('legacy_cookie', 'value', 0, '/', '', false, true, 'Strict');

    // Via Response::cookie() — always had SameSite
    $response->cookie('modern_cookie', 'value', 0, '/', '', false, true, 'Lax');

    echo '<h2>Cookies set ✓</h2>';
    echo '<p>Check response headers for Set-Cookie with SameSite attribute.</p>';
});

// ---------------------------------------------------------------------------
// 8. Hub page listing all demos
// ---------------------------------------------------------------------------
$app->route('/http', ['methods' => ['GET']], function() {
    echo <<<'HTML'
    <!doctype html><html lang="en"><head><meta charset="utf-8">
    <title>ZealPHP · HTTP Features</title>
    <style>
      body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto;padding:0 1rem}
      h1{color:#1a1a2e} table{border-collapse:collapse;width:100%}
      td,th{border:1px solid #ddd;padding:.6rem .9rem;text-align:left}
      th{background:#f4f4f4} code{background:#f0f0f0;padding:1px 5px;border-radius:3px}
      a{color:#0070f3}
    </style></head><body>
    <h1>HTTP Protocol Feature Demos</h1>
    <table>
      <tr><th>Feature</th><th>URL</th><th>curl command</th></tr>
      <tr><td>301 Redirect</td><td><a href="/http/redirect/301">/http/redirect/301</a></td><td><code>curl -I http://localhost:8080/http/redirect/301</code></td></tr>
      <tr><td>302 Redirect</td><td><a href="/http/redirect/302">/http/redirect/302</a></td><td><code>curl -L http://localhost:8080/http/redirect/302</code></td></tr>
      <tr><td>307 Redirect</td><td><a href="/http/redirect/307">/http/redirect/307</a></td><td><code>curl -I http://localhost:8080/http/redirect/307</code></td></tr>
      <tr><td>CORS data</td><td><a href="/http/cors-data">/http/cors-data</a></td><td><code>curl -H "Origin: http://other.com" http://localhost:8080/http/cors-data -v</code></td></tr>
      <tr><td>HEAD</td><td>—</td><td><code>curl -I http://localhost:8080/http/head-test</code></td></tr>
      <tr><td>OPTIONS</td><td>—</td><td><code>curl -X OPTIONS http://localhost:8080/http/options-test -v</code></td></tr>
      <tr><td>ETag / 304</td><td><a href="/http/etag-test">/http/etag-test</a></td><td><code>curl -D - http://localhost:8080/http/etag-test</code></td></tr>
      <tr><td>Gzip compression</td><td><a href="/http/compress-test">/http/compress-test</a></td><td><code>curl --compressed http://localhost:8080/http/compress-test -w "%{size_download}"</code></td></tr>
      <tr><td>Cookie SameSite</td><td><a href="/http/cookie-test">/http/cookie-test</a></td><td><code>curl -v http://localhost:8080/http/cookie-test 2>&1 | grep Set-Cookie</code></td></tr>
      <tr><td>WebSocket echo</td><td><a href="/ws">/ws</a></td><td><code>wscat -c ws://localhost:8080/ws/echo</code></td></tr>
    </table>
    </body></html>
    HTML;
});
