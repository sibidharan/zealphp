<?php use ZealPHP\App; $active = $active ?? 'learn/middleware'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 10,
      'title'    => 'Middleware: The Wrap',
      'subtitle' => 'Middleware is airport security between the gate and the plane. You don\'t write it for every flight. You nod at the TSA agent.',
      'prev'     => ['slug' => 'learn/responses', 'title' => 'Returning a Response'],
      'next'     => ['slug' => 'learn/streaming', 'title' => 'Streaming Done Right'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What PSR-15 middleware actually is (in 30 seconds)',
      'The six built-ins ZealPHP ships with — and when each one matters',
      'How to write a custom middleware in about 10 lines',
      'Why the registration order is reversed when the stack actually runs',
    ]]); ?>

    <h2>What middleware is</h2>
    <p>
      A middleware is a function that gets the request before your handler, and gets the response
      after. It can short-circuit the request (returning 401 before authentication even reaches your
      route), add a header to every response (compression, CORS, ETag), measure timing, log,
      anything that should apply to <em>many</em> routes rather than one.
    </p>
    <p>
      ZealPHP uses the <strong>PSR-15</strong> middleware shape. A middleware implements:
    </p>
    <pre><code class="language-php">public function process(
    ServerRequestInterface $request,
    RequestHandlerInterface $handler
): ResponseInterface;</code></pre>
    <p>
      You either return a response yourself (short-circuit) or call <code>$handler-&gt;handle($request)</code>
      to delegate to the next middleware in the chain. Same shape Slim, Symfony, Laravel (via adapters),
      and most modern PHP frameworks use.
    </p>

    <h2>The six built-ins</h2>
    <p>ZealPHP ships these out of the box. Most apps register the first four.</p>
    <table class="cmp-table">
      <thead><tr><th>Middleware</th><th>What it does</th><th>Configure with</th></tr></thead>
      <tbody>
        <tr><td><code>CorsMiddleware</code></td><td>Preflight (OPTIONS) handling and <code>Access-Control-*</code> headers on every response.</td><td>Constructor args or <code>ZEALPHP_CORS_ORIGINS</code></td></tr>
        <tr><td><code>ETagMiddleware</code></td><td>Weak ETag on GET responses; returns 304 on <code>If-None-Match</code> match.</td><td>None</td></tr>
        <tr><td><code>CompressionMiddleware</code></td><td>gzip/deflate when client supports it. Skip if OpenSwoole’s built-in compression is on.</td><td>None</td></tr>
        <tr><td><code>RangeMiddleware</code></td><td>RFC 7233 Range requests: 206 Partial Content, multi-range, <code>If-Range</code>.</td><td>None</td></tr>
        <tr><td><code>SessionStartMiddleware</code></td><td>Eager session start for first-time visitors. Without this, only returning visitors get sessions.</td><td><code>ZEALPHP_SESSION_SECURE</code> for HTTPS override</td></tr>
        <tr><td><code>IniIsolationMiddleware</code></td><td>Snapshots <code>php.ini</code> changes per request so <code>ini_set()</code> doesn’t leak.</td><td><code>ZEALPHP_INI_ISOLATE=1</code></td></tr>
      </tbody>
    </table>
    <p>
      Register them in <code>app.php</code> before <code>$app-&gt;run()</code>:
    </p>
    <pre><code class="language-php">$app-&gt;addMiddleware(new CorsMiddleware());
$app-&gt;addMiddleware(new ETagMiddleware());
$app-&gt;addMiddleware(new SessionStartMiddleware());</code></pre>

    <h2>Order is reversed at execution time</h2>
    <p>
      You register <code>A, B, C</code>. The stack ZealPHP builds is <code>C wraps B wraps A wraps
      ResponseMiddleware</code>. At request time, <strong>C runs first.</strong> The last middleware
      you add is the outermost wrapper — same convention as Slim, Express, Laravel.
    </p>
    <p>
      This means: <em>register the inner-most concerns first, the outer-most last.</em> Session
      handling is closest to the route handler — register early. CORS handles every response
      including 404s — register last.
    </p>

    <h2>Writing your own</h2>
    <p>Here’s a complete rate-limit-header middleware in 10 lines:</p>
    <pre><code class="language-php">use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitHeader implements MiddlewareInterface {
    public function __construct(private int $limit = 60) {}

    public function process($request, RequestHandlerInterface $handler) {
        $response = $handler-&gt;handle($request);
        return $response
            -&gt;withHeader('X-RateLimit-Limit', (string)$this-&gt;limit)
            -&gt;withHeader('X-RateLimit-Remaining', (string)($this-&gt;limit - 1));
    }
}

// in app.php
$app-&gt;addMiddleware(new RateLimitHeader(100));</code></pre>
    <p>
      The pattern: call <code>$handler-&gt;handle()</code>, modify the returned response, return it.
      For short-circuit behavior (e.g., auth that returns 401 before the route runs), build a response
      yourself with <code>new \OpenSwoole\Core\Psr\Response(...)</code> and return it without calling
      <code>$handler-&gt;handle()</code> at all.
    </p>

    <h2>When NOT to write middleware</h2>
    <p>
      If the logic applies to <em>one route</em>, it goes in the handler. If it’s really
      tangled with request-specific data, it goes in the handler. If you find yourself reaching for
      middleware to validate a single form, you’ve over-engineered — just validate in the
      handler and return 422.
    </p>
    <p>
      Middleware shines for <strong>cross-cutting concerns</strong>: things every route benefits from
      (CORS, logging, sessions) or things you can turn on/off as a feature flag (compression,
      rate-limiting, auth-required gates).
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Don\'t both register Compression and OpenSwoole http_compression',
      'body'    => '<p>OpenSwoole has built-in gzip via the <code>http_compression</code> server option (enabled by default in <code>App::run()</code>). The reference <code>CompressionMiddleware</code> exists for apps that disable that option. Running both compresses bytes twice — bad. Pick one.</p>',
    ]); ?>

    <h2>Try it live</h2>
    <p>
      The demo app registers CORS + ETag + Session-start + Range. See the headers in action:
    </p>
    <ul>
      <li><a href="/demo/middleware/cors">/demo/middleware/cors</a> — <code>Access-Control-*</code> headers</li>
      <li><a href="/demo/middleware/etag">/demo/middleware/etag</a> — <code>ETag</code> + 304 on repeat</li>
      <li><a href="/demo/middleware/compress">/demo/middleware/compress</a> — <code>Content-Encoding: gzip</code></li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'mw1',
      'question' => 'You register middleware in the order: <code>A, B, C</code>. A request arrives. Which middleware sees the inbound request first?',
      'correct'  => 'c',
      'explain'  => 'The last-added middleware is the outermost wrapper at execution time. C wraps everything else, so C sees the request first. Same convention as Slim/Express/Laravel: register inner-most concerns first.',
      'options'  => [
        'a' => 'A — it was registered first.',
        'b' => 'B — it sits in the middle.',
        'c' => 'C — the last-added middleware is the outermost wrapper.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'A middleware is a function that wraps every request: <code>(request, $handler) =&gt; response</code>.',
      'ZealPHP follows PSR-15 — same shape as Slim, Symfony, modern Laravel.',
      'Six built-ins: CORS, ETag, Compression, Range, SessionStart, IniIsolation.',
      'Register order is <em>reversed</em> at execution: last-added runs first (outermost).',
      'Middleware is for cross-cutting concerns — per-route logic still belongs in the handler.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/responses"
         hx-get="/api/learn/page?slug=learn/responses" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/responses">← Returning a Response</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/streaming"
         hx-get="/api/learn/page?slug=learn/streaming" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/streaming">Streaming Done Right →</a>
    </div>
  </article>
</div>
