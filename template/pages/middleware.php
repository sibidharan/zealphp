<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Middleware</h1>
<p class="section-desc">ZealPHP uses PSR-15 middleware. Add with <code>$app->addMiddleware()</code>. The last added runs outermost (first to process request, last to process response).</p>

<h2 style="margin:1.5rem 0 .5rem">Built-in middleware</h2>
<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Class</th><th>Constructor</th><th>What it does</th></tr>
  <tr><td><code>CorsMiddleware</code></td><td><code>($origins, $methods, $headers, $credentials, $maxAge)</code></td><td>CORS preflight + Access-Control headers on every response</td></tr>
  <tr><td><code>ETagMiddleware</code></td><td>(none)</td><td>Generates <code>W/"md5"</code> ETag, returns 304 on cache hit</td></tr>
  <tr><td><code>CompressionMiddleware</code></td><td><code>($minLength=1024, $level=6)</code></td><td>gzip/deflate when Accept-Encoding present + body &gt; threshold</td></tr>
</table>

<?php
App::render('/components/_code', [
    'label' => 'app.php — middleware registration order',
    'code'  => <<<'PHP'
$app->addMiddleware(new CorsMiddleware());         // outermost — handles preflight
$app->addMiddleware(new ETagMiddleware());         // generates ETag
$app->addMiddleware(new CompressionMiddleware());  // compresses response body
$app->addMiddleware(new AuthMiddleware());         // your custom middleware
// ResponseMiddleware is always innermost (built-in)
PHP]);
?>

<h2 style="margin:2rem 0 .5rem">Live demos</h2>
<?php
$demos = [
  ['mw-cors', 'CORS — Access-Control-Allow-Origin on every response', '/demo/middleware/cors',
   <<<'PHP'
// Add middleware once in app.php:
$app->addMiddleware(new CorsMiddleware(['*']));

// Hit any endpoint with Origin header:
// curl -H "Origin: http://app.test" http://localhost:8080/demo/middleware/cors
// → Access-Control-Allow-Origin: *
PHP],
  ['mw-etag', 'ETag / 304 — conditional GET', '/demo/middleware/etag',
   <<<'PHP'
// ETagMiddleware auto-generates W/"md5(body)" on GET
// Second request with If-None-Match: <etag> → 304 Not Modified

// First hit:
// curl -D - http://localhost:8080/http/etag-test
// → ETag: W/"abc..."
// Second hit:
// curl -H 'If-None-Match: W/"abc..."' http://localhost:8080/http/etag-test
// → HTTP/1.1 304 Not Modified (empty body)
PHP],
  ['mw-comp', 'Compression — gzip when Accept-Encoding: gzip', '/demo/middleware/compress',
   <<<'PHP'
// CompressionMiddleware kicks in for responses > 1024 bytes
// curl --compressed http://localhost:8080/http/compress-test
// → Content-Encoding: gzip  (body is compressed)
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 style="margin:2rem 0 .5rem">Custom middleware</h2>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start    = microtime(true);
        $response = $handler->handle($request);       // call inner stack
        $elapsed  = round((microtime(true) - $start) * 1000, 2);
        response_add_header('X-Response-Time', "$elapsed ms");
        return $response;
    }
}

// Register:
$app->addMiddleware(new TimingMiddleware());
PHP]); ?>
</div>
</section>
