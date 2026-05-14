<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">HTTP Responses</h1>
<p class="section-desc">ZealPHP wraps OpenSwoole's response with a clean API. Every method is coroutine-safe — no output buffering leaks across concurrent requests.</p>

<h2>Return Value Conventions</h2>
<p>What you return from a route handler determines the response — no boilerplate needed:</p>
<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Return type</th><th>Behavior</th><th>Example</th></tr>
  <tr><td><code>int</code></td><td>HTTP status code (empty body)</td><td><code>return 404;</code> <code>return 201;</code> <code>return 403;</code></td></tr>
  <tr><td><code>array</code> / <code>object</code></td><td>JSON-serialized, <code>Content-Type: application/json</code> set</td><td><code>return ['id' => 42, 'name' => 'alice'];</code></td></tr>
  <tr><td><code>string</code></td><td>Sent as response body (HTML)</td><td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td></tr>
  <tr><td><code>Generator</code></td><td>SSR streaming — each <code>yield</code> sent immediately</td><td><code>yield '&lt;head&gt;...'; yield $content;</code></td></tr>
  <tr><td><code>void</code> + <code>echo</code></td><td>Output buffer captured via <code>ob_get_clean()</code></td><td><code>echo "Hello"; echo " World";</code></td></tr>
  <tr><td><code>ResponseInterface</code></td><td>PSR-7 response used directly</td><td><code>return new Response($body, 200);</code></td></tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'All return patterns in one glance',
    'code'  => <<<'PHP'
// Status code
$app->route('/not-found', fn() => 404);

// JSON (array or object)
$app->route('/api/user/{id}', fn($id) => ['id' => $id, 'name' => 'alice']);

// HTML string
$app->route('/hello', fn() => '<h1>Hello World</h1>');

// Generator streaming
$app->route('/stream', fn() => (function() {
    yield '<html><body>';
    yield '<h1>Streamed!</h1>';
    yield '</body></html>';
})());

// Echo (output buffering)
$app->route('/echo', function() {
    echo '<div>This is captured</div>';
    echo '<div>by output buffering</div>';
});
PHP]); ?>

<h2>Response Object Methods</h2>
<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Method</th><th>Signature</th><th>What it does</th></tr>
  <tr><td><code>json()</code></td><td><code>json($data, $status=200)</code></td><td>Sets Content-Type: application/json, encodes and ends response</td></tr>
  <tr><td><code>redirect()</code></td><td><code>redirect($url, $status=302)</code></td><td>Sets Location header + status, no body</td></tr>
  <tr><td><code>header()</code></td><td><code>header($key, $value)</code></td><td>Queues response header (sent on flush)</td></tr>
  <tr><td><code>cookie()</code></td><td><code>cookie($name, $value, ..., $samesite)</code></td><td>Sets cookie with full attributes incl. SameSite</td></tr>
  <tr><td><code>status()</code></td><td><code>status(int $code)</code></td><td>Sets HTTP status code</td></tr>
  <tr><td><code>stream()</code></td><td><code>stream(callable $fn)</code></td><td>Flush headers immediately, stream body via $write() closure</td></tr>
  <tr><td><code>sse()</code></td><td><code>sse(callable $fn)</code></td><td>Server-Sent Events — sets event-stream headers, $emit() closure</td></tr>
  <tr><td><code>sendFile()</code></td><td><code>sendFile($path, $filename='')</code></td><td>Zero-copy file serving with Range support; sets Content-Disposition when filename given</td></tr>
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

<h2 style="margin-top:2.5rem">PSR-7 Response objects</h2>
<p>Return a PSR-7 <code>Response</code> directly when you need full control over status, headers, and body in one shot. The output buffer is ignored.</p>

<?php App::render('/components/_code', [
    'label' => 'Return new Response(...)',
    'code'  => <<<'PHP'
use OpenSwoole\Core\Psr\Response;
use ZealPHP\G;

$app->route('/coglobal/set/session', ['methods' => ['GET', 'POST']], function($name) {
    // This echo is IGNORED when a Response object is returned
    echo "Hello World";

    $g = G::instance();
    $g->session['name'] = $name;

    return new Response(
        'Session set',           // body
        300,                     // status
        'success',               // reason phrase
        ['Content-Type' => 'text/plain', 'X-Test' => 'test']
    );
});
PHP]); ?>

<h2 style="margin-top:2.5rem">Bypass the output buffer</h2>
<p>Use <code>$response->status()</code> + <code>$response->write()</code> when you want to send the response immediately and skip output buffering entirely.</p>

<?php App::render('/components/_code', [
    'label' => '$response->write() takes precedence',
    'code'  => <<<'PHP'
$app->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
    $response->status(403);
    $response->write("403 Forbidden");
    // No return needed — write() ends the response. Output buffer ignored.
});
PHP]); ?>

<h2 style="margin-top:2.5rem">Utility functions</h2>
<p>Free functions that work in any route handler — no need to grab <code>$response</code> first:</p>

<table class="ztable">
<tr><th>Function</th><th>Equivalent to</th></tr>
<tr><td><code>response_set_status(int $code)</code></td><td><code>$response->status($code)</code></td></tr>
<tr><td><code>response_add_header(string $name, string $value)</code></td><td><code>$response->header($name, $value)</code></td></tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'Utility functions in action',
    'code'  => <<<'PHP'
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;

$app->route('/api/created', function() {
    response_add_header('Location', '/api/items/42');
    response_set_status(201);
    return ['id' => 42, 'created' => true];
});
PHP]); ?>

<h2 style="margin-top:2.5rem">Custom error pages — Apache <code>ErrorDocument</code></h2>
<p>Register a handler for any 4xx/5xx status. Fires whenever the framework or a route emits that status. Return values follow the same conventions as regular routes — <code>string</code> for HTML, <code>array</code> for JSON, <code>Generator</code> for streaming, void+echo for output buffer capture.</p>

<?php App::render('/components/_code', [
    'label' => 'Status-specific + catch-all handlers',
    'code'  => <<<'PHP'
use ZealPHP\App;

$app = App::instance();

// Status-specific
$app->setErrorHandler(404, function($status) {
    return App::renderToString('error/404', ['status' => $status]);
});

$app->setErrorHandler(500, function($exception) {
    return [
        'error'    => 'Internal Server Error',
        'trace_id' => uniqid('e_'),
    ];
});

// Catch-all — fires when no status-specific handler matches
$app->setErrorHandler(function($status, $exception) {
    http_response_code($status);
    echo "<pre>Error $status</pre>";
});
PHP]); ?>

<table class="ztable" style="margin-top:1rem">
<tr><th>Param</th><th>Value</th></tr>
<tr><td><code>$status</code></td><td>The HTTP status being rendered (<code>int</code>).</td></tr>
<tr><td><code>$exception</code></td><td>The caught <code>\Throwable</code> for 500-from-throw paths; <code>null</code> otherwise.</td></tr>
<tr><td><code>$request</code> / <code>$response</code></td><td>Wrappers around the OpenSwoole request/response.</td></tr>
</table>

<p style="margin-top:1rem">A handler that itself throws is caught — the framework falls through to the default body for the <strong>original</strong> status (not 500). Recursion is guarded by <code>G-&gt;error_render_depth</code>.</p>

<p style="margin-top:.5rem">Sites where the handler fires:</p>
<ul style="margin-left:1.2rem">
  <li><code>return 404;</code> from any route handler (or 4xx/5xx int return).</li>
  <li>Uncaught <code>\Throwable</code> from a route handler — 500.</li>
  <li><code>exit(1)</code> / <code>die(1)</code> — 500.</li>
  <li>URL-encoded traversal — 400.</li>
  <li>Dotfile requests, <code>.php</code> direct access, includeCheck rejection — 403.</li>
  <li>Unmatched URL with no fallback — 404.</li>
</ul>

<h2 style="margin-top:2.5rem">Default error pages — content negotiation</h2>
<p>When no custom handler is registered, the framework emits HTML by default and JSON when the client sends <code>Accept: application/json</code>:</p>

<?php App::render('/components/_code', [
    'label' => 'JSON envelope',
    'code'  => <<<'JSON'
{
  "error": {
    "status": 500,
    "message": "Internal Server Error",
    "trace": "RuntimeException: boom at ...\n at App.{closure}(...)"
  }
}
JSON]); ?>

<p style="margin-top:1rem"><code>trace</code> is populated only when <code>App::$display_errors</code> is true. Custom handlers override negotiation entirely — user intent trumps <code>Accept</code>.</p>

<h2 style="margin-top:2.5rem">Per-coroutine error handlers</h2>
<p><code>set_error_handler</code>, <code>set_exception_handler</code>, <code>register_shutdown_function</code>, and <code>error_reporting()</code> are process-global in vanilla PHP — one coroutine's call would catch every other's errors. ZealPHP isolates them per request via <code>G</code>:</p>

<?php App::render('/components/_code', [
    'label' => 'Per-coroutine handler — fires only for THIS request',
    'code'  => <<<'PHP'
$app->route('/process', function() {
    register_shutdown_function(function() {
        zlog('request finished', 'info');
    });

    set_error_handler(function($severity, $msg, $file, $line) {
        // captures warnings/notices in THIS coroutine only
        return true;
    }, E_WARNING | E_NOTICE);

    // ... handler body ...
});
PHP]); ?>

<p style="margin-top:1rem">A native process-level handler installed at boot delegates to the active coroutine's <code>G</code> stack. <code>register_shutdown_function</code>'s queue is drained AFTER the route returns and BEFORE the PSR response is emitted — so shutdown functions can still <code>echo</code> or call <code>http_response_code()</code> and have those land in the wire response.</p>

<div class="callout info" style="margin-top:1rem">
For the full mechanism (boot-order trick, exception-handler integration in <code>dispatchRoute</code>'s catch, recursion guard, source-line references), see <a href="https://github.com/sibidharan/zealphp/blob/master/docs/error-handling.md"><code>docs/error-handling.md</code></a>.
</div>

</div>
</section>
