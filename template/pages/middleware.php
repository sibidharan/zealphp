<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>
<section class="section">
<div class="container">
<h1 class="section-title">Middleware</h1>
<p class="section-desc">ZealPHP uses PSR-15 middleware. Add with <code>$app->addMiddleware()</code>. The last added runs outermost (first to process request, last to process response).</p>

<h2 style="margin:1.5rem 0 .5rem">Built-in middleware</h2>
<table class="ztable" style="margin-bottom:2rem">
  <tr><th>Class</th><th>Constructor</th><th>What it does</th></tr>
  <tr><td><code>CorsMiddleware</code></td><td><code>($origins, $methods, $headers, $credentials, $maxAge)</code></td><td>CORS preflight + Access-Control headers on every response</td></tr>
  <tr><td><code>ETagMiddleware</code></td><td>(none)</td><td>Generates <code>W/"md5"</code> ETag, returns 304 on cache hit</td></tr>
  <tr><td><code>CompressionMiddleware</code></td><td><code>($minLength=1024, $level=6, $skipProxiedRequests=false)</code></td><td>Reference gzip/deflate middleware; runtime compression is handled by OpenSwoole by default</td></tr>
  <tr><td><code>RangeMiddleware</code></td><td>(none)</td><td>RFC 7233 Range requests — adds <code>Accept-Ranges: bytes</code>, returns 206 with sliced body for single or multi-range, 416 for unsatisfiable</td></tr>
</table>

<?php
App::render('/components/_code', [
    'label' => 'app.php — middleware registration order',
    'code'  => <<<'PHP'
$app->addMiddleware(new CorsMiddleware());         // outermost — handles preflight
$app->addMiddleware(new ETagMiddleware());         // generates ETag
$app->addMiddleware(new AuthMiddleware());         // your custom middleware
// ResponseMiddleware is always innermost (built-in)
PHP]);
?>

<h2 style="margin:2rem 0 .5rem">Live demos</h2>
<?php
$demos = [
  ['mw-cors', 'CORS — Access-Control-Allow-Origin on every response', '/demo/middleware/cors',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// Add middleware once in app.php:
$app->addMiddleware(new CorsMiddleware(['*']));

// Hit any endpoint with Origin header:
// curl -H "Origin: http://app.test" __SITE_URL__/demo/middleware/cors
// → Access-Control-Allow-Origin: *
PHP)],
  ['mw-etag', 'ETag / 304 — conditional GET', '/demo/middleware/etag',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// ETagMiddleware auto-generates W/"md5(body)" on GET
// Second request with If-None-Match: <etag> → 304 Not Modified

// First hit:
// curl -D - __SITE_URL__/http/etag-test
// → ETag: W/"abc..."
// Second hit:
// curl -H 'If-None-Match: W/"abc..."' __SITE_URL__/http/etag-test
// → HTTP/1.1 304 Not Modified (empty body)
PHP)],
  ['mw-comp', 'Compression — gzip when Accept-Encoding: gzip', '/demo/middleware/compress',
   str_replace('__SITE_URL__', $siteUrl, <<<'PHP'
// OpenSwoole handles runtime compression by default.
// Keep CompressionMiddleware only as a reference if you disable http_compression.
// curl --compressed __SITE_URL__/http/compress-test
// → Content-Encoding: gzip  (body is compressed)
PHP)],
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
