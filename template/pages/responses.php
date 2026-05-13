<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">HTTP Responses</h1>
<p class="section-desc">ZealPHP wraps OpenSwoole's response with a clean API. Every method is coroutine-safe — no output buffering leaks across concurrent requests.</p>

<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Method</th><th>Signature</th><th>What it does</th></tr>
  <tr><td><code>json()</code></td><td><code>json($data, $status=200)</code></td><td>Sets Content-Type: application/json, encodes and ends response</td></tr>
  <tr><td><code>redirect()</code></td><td><code>redirect($url, $status=302)</code></td><td>Sets Location header + status, no body</td></tr>
  <tr><td><code>header()</code></td><td><code>header($key, $value)</code></td><td>Queues response header (sent on flush)</td></tr>
  <tr><td><code>cookie()</code></td><td><code>cookie($name, $value, ..., $samesite)</code></td><td>Sets cookie with full attributes incl. SameSite</td></tr>
  <tr><td><code>status()</code></td><td><code>status(int $code)</code></td><td>Sets HTTP status code</td></tr>
  <tr><td><code>stream()</code></td><td><code>stream(callable $fn)</code></td><td>Flush headers immediately, stream body via $write() closure</td></tr>
  <tr><td><code>sse()</code></td><td><code>sse(callable $fn)</code></td><td>Server-Sent Events — sets event-stream headers, $emit() closure</td></tr>
  <tr><td><code>end()</code></td><td><code>end(?string $data)</code></td><td>Send final body and close connection</td></tr>
</table>

<?php
$demos = [
  ['resp-json',  'json() — returns JSON with status 200', '/demo/response/json',
   <<<'PHP'
$app->route('/demo/response/json', function() {
    return ['framework' => 'ZealPHP', 'async' => true, 'time' => time()];
    // Returning an array auto-sets Content-Type: application/json
});
PHP],
  ['resp-redir', 'redirect() — 301 permanent redirect',   '/demo/response/redirect-301',
   <<<'PHP'
$app->route('/demo/response/redirect-301', function($response) {
    $response->redirect('/routing', 301);
});
PHP],
  ['resp-hdr',   'header() — custom response headers',    '/demo/response/headers',
   <<<'PHP'
$app->route('/demo/response/headers', function($response) {
    $response->header('X-Framework',  'ZealPHP');
    $response->header('X-Async',      'true');
    $response->header('Cache-Control','no-store');
    return ['headers_set' => ['X-Framework', 'X-Async', 'Cache-Control']];
});
PHP],
  ['resp-cookie','cookie() — SameSite cookie',            '/demo/response/cookie',
   <<<'PHP'
$app->route('/demo/response/cookie', function($response) {
    // Full PHP 7.3+ signature including SameSite
    $response->cookie('session_demo', 'abc123', 0, '/', '', false, true, 'Strict');
    return ['cookie_set' => 'session_demo=abc123; SameSite=Strict; HttpOnly'];
});
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<div class="callout info" style="margin-top:2rem">
  <strong>Streaming responses</strong> — stream() and sse() are covered on the
  <a href="/streaming">Streaming page</a>. They send headers immediately and bypass the PSR-7 output buffer.
</div>
</div>
</section>
