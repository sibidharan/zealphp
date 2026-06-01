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
      'Why the first middleware you register is the outermost wrapper that runs first',
      'How to attach middleware to <em>one</em> route — the <code>middleware:</code> option, named aliases, and route groups',
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
    <pre><code class="language-php">use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\Middleware\SessionStartMiddleware;

$app-&gt;addMiddleware(new CorsMiddleware());
$app-&gt;addMiddleware(new ETagMiddleware());
$app-&gt;addMiddleware(new SessionStartMiddleware());</code></pre>

    <h2>Execution order</h2>
    <pre class="mermaid">graph TB
    REQ[Request arrives] --> A
    subgraph A_wrap["A  (first registered = outermost)"]
      A[A.process] --> B_in
      subgraph B_wrap["B"]
        B_in[B.process] --> C_in
        subgraph C_wrap["C  (last registered = innermost)"]
          C_in[C.process] --> RM["ResponseMiddleware<br/>match route + invoke handler"]
        end
      end
    end
    RM --> RES[Response emitted]
    style A_wrap fill:#fffbeb,stroke:#f59e0b,stroke-width:2px
    style C_wrap fill:#ecfdf5,stroke:#059669,stroke-width:2px
    style RM fill:#ecfdf5,stroke:#059669</pre>
    <p>
      You register <code>A, B, C</code>. The stack ZealPHP builds is <code>A wraps B wraps C wraps
      ResponseMiddleware</code>. At request time, <strong>A runs first.</strong> The first middleware
      you add is the outermost wrapper — same convention as Slim, Express, Laravel.
    </p>
    <p>
      This means: <em>register the outer-most concerns first, the inner-most last.</em> CORS handles
      every response including 404s — register it first. Session handling is closest to the route
      handler — register it last.
    </p>

    <h2>Writing your own</h2>
    <p>Here’s a complete rate-limit-header middleware in 10 lines:</p>
    <pre><code class="language-php">use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RateLimitHeader implements MiddlewareInterface {
    public function __construct(private int $limit = 60) {}

    public function process($request, RequestHandlerInterface $handler): ResponseInterface {
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

    <h2 id="per-route">Per-route middleware: the wrap, but scoped</h2>
    <p>
      Everything so far wraps <em>every</em> route. But "airport security on every flight" is overkill — your
      public marketing pages don't need the auth check that your <code>/admin</code> dashboard does. ZealPHP lets
      you attach middleware to a <strong>single route</strong>, or to a <strong>group</strong> of routes sharing a
      prefix. It's the same idea as Slim's route middleware, Laravel's <code>->middleware()</code>, or Hyperf's
      <code>#[Middleware]</code> attribute — named, ordered chains you opt routes into.
    </p>
    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Runs inside your app — not a reverse proxy',
      'body'    => '<p>This is the Traefik <em>vocabulary</em> (named middleware, ordered chains) on a very different runtime. A Traefik middleware lives at the edge and never sees inside your process. A ZealPHP route middleware runs <strong>inside the request lifecycle</strong> — it can read and write <code>$g</code>, touch the session, run a Redis or HTTP query on the coroutine scheduler, spawn <code>go()</code> coroutines, and short-circuit with real application logic. One caveat: per-route middleware runs <em>after</em> the router matches, so path-<em>rewriters</em> (strip/add prefix) still belong in the global, pre-match stack.</p>',
    ]); ?>

    <h3>1. The <code>middleware:</code> option</h3>
    <p>
      Pass a <code>middleware:</code> list to <code>route()</code> (also <code>nsRoute()</code>,
      <code>nsPathRoute()</code>, <code>patternRoute()</code>). Entries are middleware instances, alias strings, or
      a mix. Routes <em>without</em> the option are byte-for-byte unchanged — it's purely additive:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'route/admin.php — attach middleware to one route',
      'code'  => <<<'PHP'
<?php
use ZealPHP\App;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\Middleware\IpAccessMiddleware;

$app = App::instance();

// 'auth' + 'request-id' are named aliases (declared below);
// IpAccessMiddleware is passed as a ready instance. They combine.
$app->route('/admin/users',
    methods: ['GET'],
    middleware: ['auth', 'request-id', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    handler: fn() => \App\Models\User::all());

// A sibling route with NO middleware — proves scoping. It pays zero
// added cost and never sees the auth check above.
$app->route('/health', fn() => ['ok' => true]);
PHP,
    ]); ?>
    <p>
      You can also pass middleware via the array-options form <code>['middleware' =&gt; [...]]</code>. If you use
      both, they <strong>combine</strong>: array-option entries run first (outermost), then the named-arg entries.
    </p>

    <h3>2. Name your middleware once with <code>App::middlewareAlias()</code></h3>
    <p>
      Repeating <code>new RequestIdMiddleware()</code> at every route gets old. Register a <strong>named alias</strong>
      once at boot and reference it by string everywhere — exactly like Traefik's named middleware or Laravel's
      route-middleware aliases:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'app.php — declare aliases before $app->run()',
      'code'  => <<<'PHP'
<?php
use ZealPHP\App;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;

// A ready instance — reused as-is.
App::middlewareAlias('request-id', new RequestIdMiddleware());

// A factory callable — runs ONCE at App::run(), the resulting instance
// is shared across every request that uses the alias.
App::middlewareAlias('demo-header', fn() => new HeaderMiddleware([
    'set' => ['X-Demo-Route' => 'route-level'],
]));

// Parameterised reference, Laravel-style: 'throttle:120' calls the
// factory with the comma-split args, i.e. $factory('120').
App::middlewareAlias('throttle', fn(string $rpm = '60') => new RateLimitHeader((int) $rpm));
// ... later: middleware: ['throttle:120']
PHP,
    ]); ?>
    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'One instance serves every concurrent coroutine — keep it stateless',
      'body'    => '<p>A factory runs <strong>once</strong> at boot (single-coroutine), and the instance it returns is shared across all requests. Two requests handled concurrently hit the <em>same</em> middleware object. So never stash per-request state on the middleware (<code>$this->userId = ...</code>) — it would leak across coroutines. Per-request state goes in <code>$g</code> / <code>RequestContext</code>, which isolates per request. The built-ins are all written this way.</p>',
    ]); ?>

    <h3>3. Group routes that share a chain with <code>$app->group()</code></h3>
    <p>
      When a whole section of your app shares a prefix <em>and</em> a middleware chain — every <code>/admin/*</code>
      route needs <code>auth</code> + <code>admin-only</code> — wrap them in a group. The callback receives a
      <code>ZealPHP\RouteGroup</code> whose <code>route()</code> / <code>nsRoute()</code> / <code>group()</code>
      mirror <code>App</code>'s, prepending the prefix and the shared middleware. Groups nest:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'route/admin.php — a group, and a nested group',
      'code'  => <<<'PHP'
<?php
// group(string $prefix, array|callable $middleware = [], ?callable $registrar = null)
$app->group('/admin', ['auth', 'admin-only'], function ($g) {
    // -> GET /admin/users, wrapped by auth -> admin-only -> handler
    $g->route('/users', fn() => \App\Models\User::all());

    // Nested: /admin/audit/recent, wrapped by
    // auth -> admin-only -> audit-log -> handler
    $g->group('/audit', ['audit-log'], function ($g) {
        $g->route('/recent', fn() => \App\Models\Audit::recent());
    });
});

// Middleware may be omitted — group('/admin', fn($g) => ...) is also valid.
PHP,
    ]); ?>
    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'patternRoute() inside a group',
      'body'    => '<p>A raw regex is ambiguous to prefix, so <code>patternRoute()</code> inside a group does <strong>not</strong> auto-apply the prefix — write the full pattern. The group <em>middleware</em> still applies, though.</p>',
    ]); ?>

    <h3>4. The order, pinned crisply</h3>
    <p>The chain wraps from the outside in, and the response unwinds in reverse:</p>
    <pre><code>global  →  group  →  route  →  handler
(first-registered  (first-listed
 = outermost)       = outermost)</code></pre>
    <table class="cmp-table">
      <thead><tr><th>Layer</th><th>Order rule</th></tr></thead>
      <tbody>
        <tr><td>Global stack (<code>addMiddleware</code>)</td><td>First-registered is outermost / runs first.</td></tr>
        <tr><td>Group middleware</td><td>Wraps outside the route's own middleware.</td></tr>
        <tr><td>Route middleware</td><td>First-listed is outermost; wraps outside the handler.</td></tr>
        <tr><td>Handler</td><td>Innermost. Runs last on the way in, first on the way out.</td></tr>
      </tbody>
    </table>
    <p>
      A middleware that returns a response <em>without</em> calling <code>$handler-&gt;handle()</code> short-circuits
      everything inside it — a <code>403</code> guard or a redirect stops before the handler ever runs.
    </p>

    <h3>5. A concrete example: <code>RequestIdMiddleware</code></h3>
    <p>
      ZealPHP ships <code>ZealPHP\Middleware\RequestIdMiddleware</code> — it assigns or propagates a request
      correlation id and echoes it on the response header. It's the textbook stateless route middleware:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'using RequestIdMiddleware — id read back from the per-request memo',
      'code'  => <<<'PHP'
<?php
use ZealPHP\App;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\RequestContext;

// Constructor: (string $headerName = 'X-Request-Id', bool $trustInbound = true)
// trustInbound: propagate an inbound X-Request-Id if present, else mint a
// fresh one (bin2hex(random_bytes(16)) = 32 hex chars).
App::middlewareAlias('request-id', new RequestIdMiddleware());

$app->route('/orders/{id}',
    middleware: ['request-id'],
    handler: function ($id) {
        // The middleware stored the id in the per-request memo — read it back.
        $rid = RequestContext::once('request_id', fn() => null);
        return ['order' => $id, 'request_id' => $rid];
    });
PHP,
    ]); ?>
    <p>
      The id lands in the per-request memo, so any handler reads it with
      <code>RequestContext::once('request_id', fn() =&gt; null)</code> (or checks
      <code>RequestContext::has('request_id')</code>). Because the id lives in <code>RequestContext</code> — not on
      the middleware object — it's coroutine-safe under concurrency.
    </p>

    <h3>6. See your chains: <code>describeRoutes()</code> + the visualizer</h3>
    <p>
      Wondering which middleware actually wraps a route? <code>$app-&gt;describeRoutes()</code> returns the whole
      topology — the global chain (in execution order, ending with <code>ResponseMiddleware (router)</code>), the
      registered aliases, and every route with its resolved middleware list. It works before <em>and</em> after
      <code>run()</code>:
    </p>
    <?php App::render('/components/_code', [
      'label' => 'a route that exposes the routing + middleware topology as JSON',
      'code'  => <<<'PHP'
<?php
// route/admin.php — the demo wires this at /demo/middleware/visualize
$app->route('/__routes', fn() => $app->describeRoutes());

// Shape:
// {
//   "global":   ["CorsMiddleware", ..., "ResponseMiddleware (router)"],
//   "aliases":  ["request-id", "demo-header", ...],
//   "routes":   [{ "methods": ["GET"], "path": "/admin/users",
//                  "middleware": ["auth", "request-id", "IpAccessMiddleware"],
//                  "handler": "Closure" }, ...]
// }
PHP,
    ]); ?>
    <p>
      The website renders this as a Traefik-dashboard-style chain view at
      <a href="/middleware#visualizer" target="_blank"><code>/middleware#visualizer</code></a>.
    </p>

    <?php App::render('/components/_tryit', ['title' => 'curl the live per-route demo', 'body' => '
<p>These endpoints are live in this very app (<code>route/middleware.php</code>). Watch the headers change per route:</p>
<pre><code># Route WITH [\'request-id\',\'demo-header\'] — note BOTH headers
curl -i http://localhost:8080/demo/middleware/route-level
#   X-Request-Id: 3f9c...        (32 hex chars, minted by RequestIdMiddleware)
#   X-Demo-Route: route-level    (stamped by the demo-header alias)
#   body echoes the same request_id, read from the per-request memo

# Sibling route with NO middleware — proves scoping (no X-Demo-Route)
curl -i http://localhost:8080/demo/middleware/plain

# A guard short-circuits with 403 — the handler never runs
curl -i http://localhost:8080/demo/middleware/blocked

# A route group sharing one header middleware (X-Demo-Group)
curl -i http://localhost:8080/demo/mwgroup/alpha
curl -i http://localhost:8080/demo/mwgroup/beta

# The whole topology as JSON
curl -s http://localhost:8080/demo/middleware/visualize | jq</code></pre>
<p>Or open the rendered chain view: <a href="/middleware#visualizer" target="_blank">/middleware#visualizer</a>.</p>
    ']); ?>

    <?php App::render('/components/_concept_check', [
      'id'       => 'mw2',
      'question' => 'You write <code>$app-&gt;group(\'/admin\', [\'auth\'], fn($g) =&gt; $g-&gt;route(\'/users\', middleware: [\'rate-limit\'], handler: $h))</code>. In what order do the layers wrap the handler?',
      'correct'  => 'b',
      'explain'  => 'Group middleware wraps OUTSIDE the route\'s own middleware, which wraps outside the handler: global → group (auth) → route (rate-limit) → handler. So auth sees the request first; rate-limit runs just before the handler. The response unwinds in reverse.',
      'options'  => [
        'a' => 'rate-limit → auth → handler (route middleware is outermost)',
        'b' => 'auth → rate-limit → handler (group wraps outside route)',
        'c' => 'They run in parallel — order is undefined.',
      ],
    ]); ?>

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
      <li><a href="/demo/view/middleware/cors" target="_blank">CORS: <code>Access-Control-*</code> headers</a></li>
      <li><a href="/demo/view/middleware/etag" target="_blank">ETag: 304 Not Modified on repeat</a></li>
      <li><a href="/demo/view/middleware/compress" target="_blank">Gzip compression: <code>Content-Encoding: gzip</code></a></li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'mw1',
      'question' => 'You register middleware in the order: <code>A, B, C</code>. A request arrives. Which middleware sees the inbound request first?',
      'correct'  => 'a',
      'explain'  => 'The first-added middleware is the outermost wrapper at execution time. A wraps everything else, so A sees the request first. Register outer-most concerns (CORS, compression) first — they run first on the way in and last on the way out.',
      'options'  => [
        'a' => 'A — the first-registered middleware is the outermost wrapper.',
        'b' => 'B — it sits in the middle.',
        'c' => 'C — it was registered last.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'A middleware is a function that wraps every request: <code>(request, $handler) =&gt; response</code>.',
      'ZealPHP follows PSR-15 — same shape as Slim, Symfony, modern Laravel.',
      'Six built-ins: CORS, ETag, Compression, Range, SessionStart, IniIsolation.',
      'Register order: first-added is outermost and runs first — register CORS/compression before session/auth.',
      'Scope middleware per route with the <code>middleware:</code> option, name chains with <code>App::middlewareAlias()</code>, and share a prefix + chain with <code>$app-&gt;group()</code>.',
      'Order: global → group → route → handler (first-registered / first-listed = outermost); a guard that skips <code>$handler-&gt;handle()</code> short-circuits.',
      'Alias factories run once at boot and share one stateless instance across all coroutines — per-request state lives in <code>$g</code> / <code>RequestContext</code>.',
      'Inspect every chain with <code>$app-&gt;describeRoutes()</code> or the <a href="/middleware#visualizer">/middleware#visualizer</a> page.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/responses"
         hx-get="/api/learn/page?slug=learn/responses" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/responses">← Returning a Response</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/streaming"
         hx-get="/api/learn/page?slug=learn/streaming" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/streaming">Streaming Done Right →</a>
    </div>
  </article>
</div>
