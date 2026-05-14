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
// 6. Compression — handled by OpenSwoole http_compression or ZealPHP's CompressionMiddleware
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
// 8. Range requests — single + multi-range via RangeMiddleware
//    curl -H 'Range: bytes=0-9' http://localhost:8080/http/range-test
// ---------------------------------------------------------------------------
$app->route('/http/range-test', ['methods' => ['GET']], function() {
    header('Content-Type: text/plain');
    echo str_repeat('abcdefghij', 100); // 1000 bytes of predictable content
});

// ---------------------------------------------------------------------------
// 9. sendFile — zero-copy file serving with Range
//    curl http://localhost:8080/http/sendfile-test
//    curl -H 'Range: bytes=0-99' http://localhost:8080/http/sendfile-test
// ---------------------------------------------------------------------------
$app->route('/http/sendfile-test', ['methods' => ['GET']], function($response) {
    $response->sendFile(__DIR__ . '/../public/css/zealphp.css');
});
