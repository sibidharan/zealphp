<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>
<section class="section">
<div class="container">
<h1 class="section-title">Middleware</h1>
<p class="section-desc">ZealPHP uses PSR-15 middleware, globally with <code>$app->addMiddleware()</code> or <a href="#per-route">per-route</a> with the <code>middleware:</code> option. The <strong>first</strong> added (or listed) runs outermost — first to process the request, last to process the response. Middleware always returns a <code>Psr\Http\Message\ResponseInterface</code> — handlers inside use the <a href="/responses#return-contract">universal return contract</a>; <code>ResponseMiddleware</code> coerces handler returns to PSR-7 before your middleware sees them. See the <a href="#visualizer">live middleware visualizer</a> below for a picture of every route's chain.</p>

<h2 class="mw-h2">Built-in middleware</h2>
<table class="ztable mw-table">
  <tr><th>Class</th><th>Apache / nginx parity</th><th>What it does</th></tr>
  <tr><td><a href="#cors"><code>CorsMiddleware</code></a></td><td>n/a (modern browser feature)</td><td>CORS preflight + Access-Control headers on every response</td></tr>
  <tr><td><a href="#etag"><code>ETagMiddleware</code></a></td><td>Apache <code>FileETag</code>, nginx <code>etag on</code></td><td>Generates <code>W/"md5"</code> ETag, returns 304 on cache hit</td></tr>
  <tr><td><a href="#compression"><code>CompressionMiddleware</code></a></td><td>Apache <code>mod_deflate</code>, nginx <code>gzip on</code></td><td>Reference gzip/deflate; runtime compression is handled by OpenSwoole by default</td></tr>
  <tr><td><a href="#range"><code>RangeMiddleware</code></a></td><td>HTTP/1.1 RFC 7233 (universally expected)</td><td><code>Accept-Ranges: bytes</code>; 206 for single/multi-range; 416 for unsatisfiable</td></tr>
  <tr><td><a href="#session-start"><code>SessionStartMiddleware</code></a></td><td>n/a (PHP-native sessions)</td><td>Eagerly starts a session and sends <code>Set-Cookie</code> for new visitors</td></tr>
  <tr><td><a href="#request-id"><code>RequestIdMiddleware</code></a></td><td>n/a (request correlation)</td><td>Assigns/propagates <code>X-Request-Id</code>; stores it in the per-request memo so handlers can read it</td></tr>
  <tr><td><a href="#ini-isolation"><code>IniIsolationMiddleware</code></a></td><td>n/a (long-running runtime concern)</td><td>Snapshots and restores <code>ini_set()</code> changes per request</td></tr>
  <tr><td><a href="#charset"><code>CharsetMiddleware</code></a></td><td>Apache <code>AddDefaultCharset</code> / <code>AddCharset</code></td><td>Appends <code>; charset=utf-8</code> to text-ish response <code>Content-Type</code></td></tr>
  <tr><td><a href="#cache-control"><code>CacheControlMiddleware</code></a></td><td>Apache <code>&lt;FilesMatch&gt; Header set Cache-Control</code></td><td>Extension-keyed <code>Cache-Control: max-age=N, public</code> for static assets</td></tr>
  <tr><td><a href="#expires"><code>ExpiresMiddleware</code></a></td><td>Apache <code>mod_expires</code>, nginx <code>expires 30d</code></td><td>Adds <code>Expires:</code> header by content type</td></tr>
  <tr><td><a href="#header"><code>HeaderMiddleware</code></a></td><td>Apache <code>mod_headers</code> (<code>Header set/add/unset</code>)</td><td>Declarative response-header manipulation with conditional variants</td></tr>
  <tr><td><a href="#basic-auth"><code>BasicAuthMiddleware</code></a></td><td>Apache <code>AuthType Basic</code>, nginx <code>auth_basic</code></td><td>HTTP Basic Auth: htpasswd file or callback verifier</td></tr>
  <tr><td><a href="#ip-access"><code>IpAccessMiddleware</code></a></td><td>Apache <code>Allow from / Deny from</code></td><td>CIDR allow/deny lists with allow-first or deny-first ordering</td></tr>
  <tr><td><a href="#rate-limit"><code>RateLimitMiddleware</code></a></td><td>nginx <code>limit_req</code></td><td>Sliding-window request rate limiter backed by <code>Store</code> (cross-worker)</td></tr>
  <tr><td><a href="#concurrency-limit"><code>ConcurrencyLimitMiddleware</code></a></td><td>nginx <code>limit_conn</code></td><td>In-flight concurrent-request cap backed by <code>Counter</code></td></tr>
  <tr><td><a href="#block-php-ext"><code>BlockPhpExtMiddleware</code></a></td><td>Apache <code>RewriteRule ^(.+)\.php$ - [F]</code></td><td>Refuses <code>*.php</code> URLs with 404 (for extensionless-only public surfaces)</td></tr>
  <tr><td><a href="#mime-type"><code>MimeTypeMiddleware</code></a></td><td>Apache <code>AddType</code> / <code>ForceType</code></td><td>Sets/overrides <code>Content-Type</code> on non-static responses by extension or pattern</td></tr>
  <tr><td><a href="#body-rewrite"><code>BodyRewriteMiddleware</code></a></td><td>Apache <code>mod_substitute</code> (<code>Substitute s/x/y/</code>)</td><td>Single-line regex substitution on response body</td></tr>
  <tr><td><a href="#host-router"><code>HostRouterMiddleware</code></a></td><td>nginx <code>server_name a.com b.com</code></td><td>Dispatches per-host routes inside one ZealPHP instance</td></tr>
  <tr><td><a href="#content-encoding"><code>ContentEncodingMiddleware</code></a></td><td>Apache <code>mod_mime AddEncoding</code></td><td>Sets <code>Content-Encoding</code> from URL file suffixes (e.g. <code>.gz</code>, <code>.br</code>)</td></tr>
  <tr><td><a href="#content-language"><code>ContentLanguageMiddleware</code></a></td><td>Apache <code>mod_mime AddLanguage</code></td><td>Sets <code>Content-Language</code> from URL file suffixes (e.g. <code>.en</code>, <code>.fr</code>)</td></tr>
  <tr><td><a href="#merge-slashes"><code>MergeSlashesMiddleware</code></a></td><td>Apache <code>MergeSlashes On</code>, nginx <code>merge_slashes</code></td><td>Collapses runs of consecutive slashes in the request path before routing</td></tr>
  <tr><td><a href="#request-header"><code>RequestHeaderMiddleware</code></a></td><td>Apache <code>mod_headers RequestHeader</code></td><td><code>set</code> / <code>append</code> / <code>unset</code> on inbound request headers before handlers run</td></tr>
  <tr><td><a href="#return"><code>ReturnMiddleware</code></a></td><td>nginx <code>return</code> directive</td><td>Unconditionally returns a fixed response — pair with <code>ScopedMiddleware</code></td></tr>
  <tr><td><a href="#scoped"><code>ScopedMiddleware</code></a></td><td>Apache <code>&lt;Location&gt;</code> / <code>&lt;LocationMatch&gt;</code> containers</td><td>Apply another middleware only to matching request paths</td></tr>
  <tr><td><a href="#set-env-if"><code>SetEnvIfMiddleware</code></a></td><td>Apache <code>mod_setenvif</code> / <code>BrowserMatch</code></td><td>Set request "env" vars in <code>$g->server</code> when a request attribute matches a regex</td></tr>
  <tr><td><a href="#body-size-limit"><code>BodySizeLimitMiddleware</code></a></td><td>nginx <code>client_max_body_size</code>, Apache <code>LimitRequestBody</code></td><td>Rejects oversized request bodies with <code>413 Content Too Large</code></td></tr>
  <tr><td><a href="#redirect"><code>RedirectMiddleware</code></a></td><td>Apache <code>mod_alias</code> (<code>Redirect</code> / <code>RedirectMatch</code>)</td><td>Declarative URL redirects — prefix and regex rules, first match short-circuits</td></tr>
  <tr><td><a href="#referer"><code>RefererMiddleware</code></a></td><td>nginx <code>valid_referers</code> / <code>$invalid_referer</code></td><td>Hotlink protection — refuses requests whose <code>Referer</code> is not in the allowed set</td></tr>
  <tr><td><a href="#csrf"><code>CsrfMiddleware</code></a></td><td>n/a (framework-level)</td><td>Double-submit CSRF protection for state-mutating requests</td></tr>
  <tr><td><a href="#health-check"><code>HealthCheckMiddleware</code></a></td><td>n/a (ops concern)</td><td>Short-circuits on health-check paths (default <code>/healthz</code>); returns 200/503 JSON</td></tr>
  <tr><td><code>LocationHeaderMiddleware</code></td><td>n/a (proxy port rewrite)</td><td>Rewrites the port in an outbound <code>Location</code> header to a configured value — useful behind a non-standard-port proxy. Note: zero live registrations in the built-in app; wire it manually if you need port-rewriting behind a proxy.</td></tr>
</table>

<?php
App::render('/components/_code', [
    'label' => 'app.php — middleware registration order',
    'code'  => <<<'PHP'
$app->addMiddleware(new CorsMiddleware());         // outermost — handles preflight
$app->addMiddleware(new ETagMiddleware());         // generates ETag
$app->addMiddleware(new CustomAuthMiddleware());   // your custom middleware
// ResponseMiddleware is always innermost (built-in)
PHP]);
?>

<p class="mw-note">Server-level Apache directives map to <code>App::$*</code> static properties + fluent setters (e.g., <code>App::clientIp()</code>, <code>App::canonicalHost()</code>, <code>App::$trusted_proxies</code>, <code>App::$access_log_format</code>). See <a href="/legacy-apps">legacy-apps</a> for the full server-level configurability matrix.</p>

<h2 id="per-route" class="mw-h2">Per-route middleware</h2>
<p>Global middleware wraps <em>every</em> request. When a policy belongs to a handful of routes — auth on <code>/admin</code>, a rate limit on one endpoint, a correlation id on your job API — attach it <strong>per route</strong> instead. The reference point here is <a href="https://hyperf.io" rel="noopener">Hyperf</a> (a Swoole app server with <code>#[Middleware]</code> on routes and per-coroutine context), not Traefik. Traefik is an L7 edge proxy that forwards to backends and never runs your code; ZealPHP per-route middleware competes with Slim / Laravel / Hyperf <em>route</em> middleware. We borrow Traefik's <em>vocabulary</em> — named middleware, ordered chains — on top of Hyperf's coroutine runtime model.</p>
<p class="mw-note">The differentiator: ZealPHP middleware runs <strong>inside</strong> the request lifecycle. It can read/write <code>$g</code>, touch the session, run a Store/Redis query, spawn <code>go()</code> coroutines, and short-circuit with real application logic — none of which an edge proxy can do. Because per-route middleware runs <em>after</em> route matching, path-rewriters (Traefik <code>StripPrefix</code> / <code>AddPrefix</code> / <code>ReplacePath</code>) stay <em>global / pre-match</em>; auth, headers, rate-limit, redirect, IP-allow-list, and compression are clean per-route fits.</p>

<h3 class="mw-h3">The <code>middleware:</code> route option</h3>
<p>Every route registrar — <code>route()</code>, <code>nsRoute()</code>, <code>nsPathRoute()</code>, <code>patternRoute()</code> — accepts a <code>middleware:</code> list of <code>MiddlewareInterface</code> instances and/or alias strings. It's purely additive and backward-compatible: routes <em>without</em> <code>middleware:</code> are byte-for-byte unchanged (a zero-cost fast path).</p>
<?php App::render('/components/_code', [
    'label' => 'Per-route middleware — instances and/or alias strings',
    'code'  => <<<'PHP'
use ZealPHP\Middleware\IpAccessMiddleware;

$app->route('/admin/users',
    methods: ['GET'],
    middleware: ['auth', 'request-id', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    handler: fn() => User::all(),
);

// Two ways to declare middleware — they COMBINE.
// Array-option entries run first (outermost), then named-arg entries.
$app->route('/reports',
    ['middleware' => ['audit-log']],     // array option  → outermost
    handler: fn() => Report::all(),
    middleware: ['request-id'],          // named arg     → inner of the two
);
PHP]); ?>

<h3 class="mw-h3">Named aliases — <code>App::middlewareAlias()</code></h3>
<p>Register a short name once, reference it from any route by string. Pass a <strong>ready instance</strong> (reused as-is) or a <strong>factory callable</strong> that returns a <code>MiddlewareInterface</code>. Factories run <strong>once at <code>App::run()</code></strong> (boot, single-coroutine); the resulting instance is <strong>shared</strong> across every request that uses the alias. A parameterised reference like <code>'throttle:120'</code> calls the factory with the comma-split args (<code>fn('120')</code>) — the Laravel <code>'throttle:60,1'</code> shape.</p>
<?php App::render('/components/_code', [
    'label' => 'app.php — register aliases before $app->run()',
    'code'  => <<<'PHP'
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware};

App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware(htpasswdFile: __DIR__ . '/.htpasswd'));
App::middlewareAlias('admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));
App::middlewareAlias('throttle',   fn($n = '60') => new RateLimitMiddleware(limit: (int)$n));

$app->route('/api/heavy', middleware: ['throttle:120'], handler: fn() => Heavy::run());
PHP]); ?>
<p class="mw-note"><strong>Stateless contract:</strong> one alias instance serves every concurrent coroutine, so middleware objects must hold <em>no per-request state</em>. Put request-scoped data in <code>$g</code> (the request context / memo), never on the middleware instance — exactly how <code>RequestIdMiddleware</code> stashes its id in <code>$g->memo['request_id']</code>.</p>
<p class="mw-note"><strong>Path-sensitive guards:</strong> the router matches on the <em>normalized</em> path (collapsed <code>//</code>, decoded traversal, <code>AllowEncodedSlashes</code>), which it writes to <code>$g->server['REQUEST_URI']</code>. A per-route middleware that keys off the URL should read <code>$g->server['REQUEST_URI']</code> rather than <code>$request->getUri()->getPath()</code> — the PSR-7 request still carries the original, un-normalized path.</p>

<h3 class="mw-h3">Route groups — <code>$app->group()</code></h3>
<p>Share a prefix and a middleware chain across a block of routes. The callback receives a <code>ZealPHP\RouteGroup</code> whose <code>route()/nsRoute()/nsPathRoute()/patternRoute()/group()</code> mirror <code>App</code>'s — prepending the prefix and prepending the group's shared middleware. Group middleware wraps <strong>outside</strong> each route's own middleware, which wraps outside the handler. Groups nest. The middleware list may be omitted entirely: <code>group('/admin', fn($g) => ...)</code>.</p>
<?php App::render('/components/_code', [
    'label' => 'Nested route groups',
    'code'  => <<<'PHP'
$app->group('/admin', ['auth', 'admin-only'], function ($g) {
    $g->route('/users', fn() => User::all());

    $g->group('/audit', ['audit-log'], function ($g) {   // → /admin/audit/recent
        $g->route('/recent', fn() => Audit::recent());
    });
});
PHP]); ?>
<p class="mw-note"><code>patternRoute()</code> inside a group does <strong>not</strong> auto-apply the prefix (a raw regex is ambiguous to prefix) — but the group's shared middleware <em>does</em> still apply.</p>

<h3 id="path-scoped" class="mw-h3">Path-scoped middleware — <code>App::when()</code></h3>
<p>The centralized, "think like Traefik" way to apply middleware to a <em>slice of URLs</em> — and the one mechanism that also covers the <a href="/api">ZealAPI</a> layer. Every request (a route handler <em>or</em> an <code>api/**.php</code> file) flows through the same stack, and <code>api/admin/x.php</code> is reached by the URL <code>/api/admin/x</code> — so you scope by path and it just works. There is <strong>no separate "api middleware"</strong>.</p>
<?php App::render('/components/_code', [
    'label' => 'app.php — scope a chain to a URL path (routes AND api, one registry)',
    'code'  => <<<'PHP'
App::when('/',           ['request-id']);          // every request
App::when('/admin',      ['auth', 'admin-only']);  // /admin and /admin/*  (routes)
App::when('/api/admin',  ['auth']);                // api/admin/*.php endpoints
App::when('/api/admin/users/delete', ['audit']);   // a single api endpoint
App::when('#^/api/v\d+/#', new CorsMiddleware());  // a PCRE scope
PHP]); ?>
<p><strong>Scope syntax:</strong> a literal <strong>path prefix</strong> by default (matched on segment boundaries — <code>/admin</code> matches <code>/admin</code> and <code>/admin/x</code> but not <code>/administrators</code>); a <strong>PCRE</strong> when the string starts with <code>#</code>. <code>'/'</code> matches everything. It accepts instances, alias strings (incl. <code>'throttle:120'</code>), or a list, and composes in <strong>registration order — first registered is outermost</strong>. It runs after path normalization and after CORS/OPTIONS handling, so a <code>when</code> auth guard never blocks a preflight.</p>
<p class="mw-note"><strong>Co-located alternative — an api file's own <code>$middleware</code>:</strong> an <code>api/**.php</code> file can declare its middleware inline (read like <code>$get</code>/<code>$post</code>), which runs <strong>innermost</strong> — after any <code>App::when</code> scope, closest to the handler.</p>
<?php App::render('/components/_code', [
    'label' => 'api/admin/users/delete.php — per-file guard, co-located with the handler',
    'code'  => <<<'PHP'
$middleware = ['confirm-token'];   // runs after App::when('/api/admin')['auth']

$delete = function () {
    return ['deleted' => true];
};
PHP]); ?>

<h3 class="mw-h3">Ordering</h3>
<p>One rule, pinned crisply: <strong>first-registered (or first-listed) is outermost</strong> — it processes the request first and the response last.</p>
<p class="mw-order-chain"><code>global</code> &rarr; <code>App::when</code> &rarr; <code>group</code> / <code>route</code> &rarr; <code>api in-file</code> &rarr; <code>handler</code></p>
<p>Within each band, the first entry you add/list is the outer wrap. The response unwinds in reverse. A middleware that returns <em>without</em> calling the handler (a 403, a redirect) short-circuits the chain before the handler runs. This is consistent with the global stack: OpenSwoole's <code>StackHandler::add()</code> prepends, and the <code>array_reverse</code> at <code>run()</code> means the <strong>first</strong> middleware you add is outermost — the first to run.</p>

<h3 class="mw-h3">Coroutine-safety status</h3>
<p>Per-route middleware rides <em>on</em> ZealPHP's coroutine-safety substrate, so what's safe depends on what each middleware touches:</p>
<table class="ztable mw-table">
  <tr><th>Status</th><th>Middleware / pattern</th></tr>
  <tr><td><strong>Coroutine-safe now</strong></td><td><code>RateLimitMiddleware</code> + <code>ConcurrencyLimitMiddleware</code> (backed by <code>Store</code> / <code>Counter</code> shared memory)</td></tr>
  <tr><td><strong>Feasible now</strong></td><td>ForwardAuth, request-level CircuitBreaker, Retry — on hooked backends (the <code>ZealPHP\HTTP</code> coroutine client, <code>Store</code>, the pooled Redis client)</td></tr>
  <tr><td><strong>Blocked</strong></td><td>DB-backed auth/session middleware — waits on the per-coroutine DB connection pool. <code>pdo_pgsql</code> still blocks the worker (needs a native Postgres coroutine client)</td></tr>
</table>

<h3 id="visualizer" class="mw-h3">Live middleware visualizer</h3>
<p><code>$app-&gt;describeRoutes()</code> returns the whole topology — the <code>global</code> chain (ending with <code>ResponseMiddleware (router)</code>), the <code>App::when</code> path scopes, the registered <code>aliases</code>, and every route's <code>methods</code> / <code>path</code> / <code>middleware</code> / <code>handler</code>. It works before <strong>and</strong> after <code>run()</code>; the demo serves it live at <code>GET /demo/middleware/visualize</code>. Below is <em>this</em> server rendering it — think like Traefik's dashboard, but for your own in-process routes.</p>
<?php App::render('/components/_code', [
    'label' => 'describeRoutes() — the visualizer feed',
    'code'  => <<<'PHP'
$map = $app->describeRoutes();
// [
//   'global'  => ['CorsMiddleware', 'ETagMiddleware', 'ResponseMiddleware (router)'],
//   'aliases' => ['auth', 'request-id', 'throttle'],
//   'when'    => [['scope' => '/api/admin', 'middleware' => ['BasicAuthMiddleware']]],
//   'routes'  => [
//     ['methods' => ['GET'], 'path' => '/admin/users',
//      'middleware' => ['auth', 'request-id', 'IpAccessMiddleware'], 'handler' => 'Closure'],
//   ],
// ]
PHP]); ?>
<?php
$mwvApp  = App::instance();
$mwvDesc = $mwvApp !== null ? $mwvApp->describeRoutes() : ['global' => [], 'aliases' => [], 'when' => [], 'routes' => []];
$mwvRouter = 'ResponseMiddleware (router)';
$mwvGlobal = array_values(array_filter($mwvDesc['global'], fn($m) => $m !== $mwvRouter));
$mwvWithMw = array_values(array_filter($mwvDesc['routes'], fn($r) => $r['middleware'] !== []));
$mwvH = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="mwv-block">
  <h4 class="mwv-h4">Global stack <span class="mwv-sub">every request</span></h4>
  <div class="mwv-flow mwv-flow-global">
    <span class="mwv-node mwv-node-req">Request</span><span class="mwv-arrow">&rarr;</span>
    <?php foreach ($mwvGlobal as $mw): ?><span class="mwv-chip mwv-chip-global"><?= $mwvH($mw) ?></span><span class="mwv-arrow">&rarr;</span><?php endforeach; ?>
    <span class="mwv-node mwv-node-router"><?= $mwvH($mwvRouter) ?></span><span class="mwv-arrow">&rarr;</span>
    <span class="mwv-node mwv-node-handler">handler</span><span class="mwv-arrow mwv-arrow-back">&crarr;</span>
    <span class="mwv-node mwv-node-res">Response</span>
  </div>

  <?php if ($mwvDesc['when'] !== []): ?>
    <h4 class="mwv-h4">Path scopes <span class="mwv-sub">App::when() — first registered = outermost</span></h4>
    <div class="mwv-routes">
      <?php foreach ($mwvDesc['when'] as $w): ?>
        <div class="mwv-flow mwv-flow-route">
          <code class="mwv-path"><?= $mwvH($w['scope']) ?></code><span class="mwv-arrow">&rarr;</span>
          <?php foreach ($w['middleware'] as $mw): ?><span class="mwv-chip mwv-chip-route"><?= $mwvH($mw) ?></span><span class="mwv-arrow">&rarr;</span><?php endforeach; ?>
          <span class="mwv-node mwv-node-handler">matched handler</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($mwvWithMw !== []): ?>
    <h4 class="mwv-h4">Per-route chains <span class="mwv-sub"><?= count($mwvWithMw) ?> route<?= count($mwvWithMw) === 1 ? '' : 's' ?></span></h4>
    <div class="mwv-routes">
      <?php foreach ($mwvWithMw as $route): ?>
        <div class="mwv-flow mwv-flow-route">
          <?php foreach ($route['methods'] as $m): ?><span class="mwv-method mwv-method-<?= $mwvH(strtolower($m)) ?>"><?= $mwvH($m) ?></span><?php endforeach; ?>
          <code class="mwv-path"><?= $mwvH($route['path']) ?></code><span class="mwv-arrow">&rarr;</span>
          <?php foreach ($route['middleware'] as $mw): ?><span class="mwv-chip mwv-chip-route"><?= $mwvH($mw) ?></span><span class="mwv-arrow">&rarr;</span><?php endforeach; ?>
          <span class="mwv-node mwv-node-handler"><?= $mwvH($route['handler']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($mwvDesc['aliases'] !== []): ?>
    <h4 class="mwv-h4">Aliases</h4>
    <div class="mwv-aliases"><?php foreach ($mwvDesc['aliases'] as $a): ?><span class="mwv-chip mwv-chip-alias"><?= $mwvH($a) ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <h4 class="mwv-h4">All routes <span class="mwv-sub"><?= count($mwvDesc['routes']) ?> total</span></h4>
  <input type="text" id="mwv-filter" class="mwv-filter" placeholder="Filter routes by path…" autocomplete="off">
  <table class="ztable mwv-table" id="mwv-table">
    <thead><tr><th>Methods</th><th>Path</th><th>Route middleware</th><th>Handler</th></tr></thead>
    <tbody>
      <?php foreach ($mwvDesc['routes'] as $route): ?>
        <tr class="mwv-row<?= $route['middleware'] !== [] ? ' mwv-row-mw' : '' ?>">
          <td><?= $mwvH(implode(', ', $route['methods'])) ?></td>
          <td><code><?= $mwvH($route['path']) ?></code></td>
          <td><?= $route['middleware'] === [] ? '<span class="mwv-dash">&mdash;</span>' : $mwvH(implode(' → ', $route['middleware'])) ?></td>
          <td class="mwv-handler"><?= $mwvH($route['handler']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h2 class="mw-h2-demos">Live demos</h2>
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

<h2 class="mw-h2-ref">Per-middleware reference</h2>

<h3 id="cors" class="mw-h3"><code>CorsMiddleware</code></h3>
<p>CORS preflight (OPTIONS + <code>Origin</code>) plus <code>Access-Control-*</code> headers on every response. There is no Apache/nginx parity here — CORS is a modern browser concern, not a server-config item.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CorsMiddleware;

$app->addMiddleware(new CorsMiddleware(
    origins:     ['https://app.example.com', 'https://admin.example.com'],
    methods:     ['GET', 'POST', 'PUT', 'DELETE'],
    headers:     ['Content-Type', 'Authorization'],
    credentials: true,
    maxAge:      86400,
));
PHP]); ?>

<h3 id="etag" class="mw-h3"><code>ETagMiddleware</code></h3>
<p>Generates <code>W/"md5(body)"</code> on GET responses; returns <code>304 Not Modified</code> when the client's <code>If-None-Match</code> matches. Apache parity: <code>FileETag</code>. nginx parity: <code>etag on;</code> + <code>if_modified_since</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ETagMiddleware;

$app->addMiddleware(new ETagMiddleware());
PHP]); ?>

<h3 id="compression" class="mw-h3"><code>CompressionMiddleware</code></h3>
<p>Reference gzip/deflate body compression. <strong>OpenSwoole's <code>http_compression</code> is enabled by default</strong> — only register this middleware if you've disabled it. Apache parity: <code>mod_deflate</code>. nginx parity: <code>gzip on;</code> + <code>gzip_types</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CompressionMiddleware;

// Only register if you've turned off OpenSwoole's http_compression.
$app->addMiddleware(new CompressionMiddleware(
    minLength:           1024,   // do not compress small bodies
    level:               6,      // 1..9 — same as zlib
    skipProxiedRequests: true,   // skip when Via header is present
));
PHP]); ?>

<h3 id="range" class="mw-h3"><code>RangeMiddleware</code></h3>
<p>RFC 7233 Range requests. Adds <code>Accept-Ranges: bytes</code>, returns <code>206 Partial Content</code> for single or multi-range requests, <code>416</code> for unsatisfiable ranges, and honors <code>If-Range</code> ETag pinning. Required for video seeking and resumable downloads. nginx serves this automatically; ZealPHP needs the middleware registered.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RangeMiddleware;

$app->addMiddleware(new RangeMiddleware());
// Now: curl -r 0-1023 /video.mp4 → 206 Partial Content (first 1024 bytes)
PHP]); ?>

<h3 id="session-start" class="mw-h3"><code>SessionStartMiddleware</code></h3>
<p>Eagerly starts a session and sends <code>Set-Cookie: PHPSESSID=...</code> for first-time visitors. Without it, <code>CoSessionManager</code> only starts sessions when a <code>PHPSESSID</code> cookie already exists — so first-time visitors see no session cookie and session state resets every request. The <code>secure</code> flag auto-detects HTTPS via <code>X-Forwarded-Proto</code>, <code>HTTPS</code>, or port 443 — works behind Traefik/Nginx and on direct HTTP. Override with <code>ZEALPHP_SESSION_SECURE</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\SessionStartMiddleware;

$app->addMiddleware(new SessionStartMiddleware());
PHP]); ?>

<h3 id="request-id" class="mw-h3"><code>RequestIdMiddleware</code></h3>
<p>Assigns every request a correlation id and echoes it on the response (default header <code>X-Request-Id</code>), so one request can be traced across logs, downstream services, and the client. With <code>trustInbound: true</code> (the default), an id already set by an upstream proxy is propagated; otherwise a fresh 32-hex-char id is minted (<code>bin2hex(random_bytes(16))</code>). The id is also written to the per-request memo, so handlers read it via <code>RequestContext::once('request_id', fn() =&gt; null)</code> / <code>RequestContext::has('request_id')</code>. The kind of edge concern you'd usually add at the proxy — expressed as an in-process middleware your handlers can also see. Stateless and coroutine-safe: the id lives in <code>$g</code> (request context), never on the shared middleware instance.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\RequestContext;

// Global — every request gets a correlation id
$app->addMiddleware(new RequestIdMiddleware());

// Or per-route via an alias (see "Per-route middleware" below)
App::middlewareAlias('request-id', fn() => new RequestIdMiddleware());
$app->route('/api/job', middleware: ['request-id'], handler: function () {
    $id = RequestContext::once('request_id', fn() => null);  // read it in the handler
    return ['job' => 'queued', 'request_id' => $id];
});

// Custom header / always mint a fresh id (ignore inbound)
$app->addMiddleware(new RequestIdMiddleware('X-Correlation-Id', trustInbound: false));
PHP]); ?>

<h3 id="ini-isolation" class="mw-h3"><code>IniIsolationMiddleware</code></h3>
<p>Snapshots <code>ini_set()</code> changes (<code>timezone</code>, <code>error_reporting</code>, <code>display_errors</code>, <code>memory_limit</code>, etc.) at request start and restores them on exit. Opt-in defence against ini-value leakage across requests on long-running workers — see <a href="/coroutines#what-survives">what survives a request</a>. Enable with <code>ZEALPHP_INI_ISOLATE=1</code> or by registering it explicitly. This is a framework PSR-15 middleware for setups <em>without</em> ext-zealphp; coroutine-legacy isolates <code>ini_set()</code> per coroutine natively at the ext level (the <code>S9g</code> isolation stage), so you don't need this middleware there.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\IniIsolationMiddleware;

// Default — snapshots a curated key list
$app->addMiddleware(new IniIsolationMiddleware());

// Or pass an explicit list to track
$app->addMiddleware(new IniIsolationMiddleware([
    'date.timezone', 'memory_limit', 'error_reporting',
]));
PHP]); ?>

<h3 id="charset" class="mw-h3"><code>CharsetMiddleware</code></h3>
<p>Auto-appends <code>; charset=utf-8</code> to text-ish response <code>Content-Type</code> values that don't already declare a charset. Reads <code>App::$default_charset</code> (settable via <code>App::defaultCharset('utf-8')</code>). Apache parity: <code>AddDefaultCharset utf-8</code> + <code>AddCharset utf-8 .css .js .html</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CharsetMiddleware;

App::defaultCharset('utf-8');  // optional — utf-8 is the default
$app->addMiddleware(new CharsetMiddleware());
// → text/html → text/html; charset=utf-8 (only if not already set)
PHP]); ?>

<h3 id="cache-control" class="mw-h3"><code>CacheControlMiddleware</code></h3>
<p>Extension-based <code>Cache-Control: max-age=N, public</code> for static-asset responses. Apache parity: <code>&lt;FilesMatch "\.(css|js|jpg|png)$"&gt; Header set Cache-Control "max-age=2628000"</code>. nginx parity: <code>location ~* \.(css|js)$ { expires 30d; }</code> partial.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CacheControlMiddleware;

$app->addMiddleware(new CacheControlMiddleware([
    'css'   => ['max-age' => 2628000, 'public' => true],     // 1 month
    'js'    => ['max-age' => 2628000, 'public' => true],
    'jpg'   => ['max-age' => 31536000, 'public' => true],   // 1 year
    'png'   => ['max-age' => 31536000, 'public' => true],
    'woff2' => ['max-age' => 31536000, 'public' => true, 'immutable' => true],
]));
PHP]); ?>

<h3 id="expires" class="mw-h3"><code>ExpiresMiddleware</code></h3>
<p>Adds the legacy HTTP/1.0 <code>Expires:</code> header by content type. Pairs with <code>CacheControlMiddleware</code> for full Apache <code>mod_expires</code> parity. nginx parity: <code>expires 30d;</code> in a <code>location</code> block.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ExpiresMiddleware;

$app->addMiddleware(new ExpiresMiddleware([
    'image/jpeg'             => '+1 year',
    'image/png'              => '+1 year',
    'text/css'               => '+1 month',
    'application/javascript' => '+1 month',
    '__default'              => '+1 hour',
]));
PHP]); ?>

<h3 id="header" class="mw-h3"><code>HeaderMiddleware</code></h3>
<p>Declarative response-header manipulation: <code>set</code> (overwrite), <code>add</code> (append), <code>unset</code> (remove). Conditional variants run only on specific status codes or content types. Apache parity: <code>Header set / append / unset / add / merge</code> (mod_headers).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HeaderMiddleware;

$app->addMiddleware(new HeaderMiddleware([
    'set' => [
        'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains; preload',
        'X-Content-Type-Options'    => 'nosniff',
        'X-Frame-Options'           => 'SAMEORIGIN',
        'Referrer-Policy'           => 'strict-origin-when-cross-origin',
    ],
    'add'   => ['Link' => '</css/zealphp.css>; rel=preload; as=style'],
    'unset' => ['X-Powered-By'],
]));
PHP]); ?>

<h3 id="basic-auth" class="mw-h3"><code>BasicAuthMiddleware</code></h3>
<p>HTTP Basic Auth — htpasswd file or callback verifier. Returns <code>401</code> with <code>WWW-Authenticate: Basic</code> on missing/invalid credentials. Apache parity: <code>AuthType Basic</code> + <code>AuthName</code> + <code>AuthUserFile</code> + <code>Require</code>. nginx parity: <code>auth_basic "Realm"</code> + <code>auth_basic_user_file htpasswd</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// 1) htpasswd-file backend, scoped to /admin
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(
        htpasswdFile: __DIR__ . '/.htpasswd',
        realm:        'Admin Area',
    ),
    '/admin'
));

// 2) callback verifier — lets you check against the DB, scoped to /api/private
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(
        verify: fn($user, $pass) => User::checkCredentials($user, $pass),
        realm:  'API',
    ),
    '/api/private'
));
PHP]); ?>

<h3 id="ip-access" class="mw-h3"><code>IpAccessMiddleware</code></h3>
<p>CIDR allow/deny lists. Apache parity: legacy <code>Allow from</code> / <code>Deny from</code> / <code>Order Allow,Deny</code> (mod_access_compat) and modern <code>Require ip</code>. Pairs naturally with <code>App::$trusted_proxies</code> + <code>App::clientIp()</code> for correct client-IP resolution behind a front proxy.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\IpAccessMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// Allow only internal networks to hit /admin.
// The middleware is deny-first: any IP not in 'allow' is refused.
// No 'deny' entry is needed — the allow list already excludes everything else.
$app->addMiddleware(ScopedMiddleware::location(
    new IpAccessMiddleware([
        'allow' => ['10.0.0.0/8', '192.168.0.0/16', '127.0.0.1/32'],
    ]),
    '/admin'
));
PHP]); ?>

<h3 id="rate-limit" class="mw-h3"><code>RateLimitMiddleware</code></h3>
<p>Sliding-window request rate limiter using <code>Store</code> for cross-worker shared state. nginx parity: <code>limit_req zone=one rate=10r/s burst=20;</code>. Returns <code>429 Too Many Requests</code> with <code>Retry-After</code> when the window is full.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RateLimitMiddleware;

// Create the backing Store once (BEFORE $app->run())
Store::make('rate_limit', 100_000, [
    'count' => [Store::TYPE_INT, 4],   // column spec is a positional [type, size] tuple
    'reset' => [Store::TYPE_INT, 8],
]);

// 60 requests per minute per client IP (keyed by client IP internally)
$app->addMiddleware(new RateLimitMiddleware(
    limit:     60,
    window:    60,            // seconds
    tableName: 'rate_limit',
));
PHP]); ?>

<h3 id="concurrency-limit" class="mw-h3"><code>ConcurrencyLimitMiddleware</code></h3>
<p>In-flight concurrent-request cap. nginx parity: <code>limit_conn zone=one 10;</code>. Backed by <code>OpenSwoole\Atomic</code> (<code>Counter</code>) — increments on entry, decrements in a <code>finally</code>. Returns <code>503</code> when the cap is reached.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Counter;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;

$counter = new Counter(0, 'inflight');   // (initial, name) — create BEFORE $app->run()

$app->addMiddleware(new ConcurrencyLimitMiddleware(
    maxConcurrent: 100,     // max concurrent in-flight requests
    counter:       $counter,
));
PHP]); ?>

<h3 id="block-php-ext" class="mw-h3"><code>BlockPhpExtMiddleware</code></h3>
<p>Refuses any URL ending in <code>.php</code> with a <code>404</code>. Useful for apps that want extensionless URLs as the only public surface (so scrapers can't enumerate raw files by guessing <code>config.php</code> / <code>admin.php</code>). Apache parity: <code>RewriteCond %{THE_REQUEST} \.php; RewriteRule . - [R=404,L]</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BlockPhpExtMiddleware;

$app->addMiddleware(new BlockPhpExtMiddleware());
// /admin.php       → 404
// /config.php?x=1  → 404
// /admin           → handled normally (the .php is implicit)
PHP]); ?>

<h3 id="mime-type" class="mw-h3"><code>MimeTypeMiddleware</code></h3>
<p>Sets or overrides <code>Content-Type</code> on non-static responses by URL extension or pattern. Static files are MIME-typed by OpenSwoole's static handler — this middleware fills the gap for handler-generated responses. Apache parity: <code>AddType font/woff2 .woff2</code> and <code>ForceType image/svg+xml</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\MimeTypeMiddleware;

$app->addMiddleware(new MimeTypeMiddleware([
    'woff2' => 'font/woff2',
    'glb'   => 'model/gltf-binary',
    'wasm'  => 'application/wasm',
]));
PHP]); ?>

<h3 id="body-rewrite" class="mw-h3"><code>BodyRewriteMiddleware</code></h3>
<p>Single-line regex substitution on the response body. Useful for late-stage URL rewriting (e.g., serving a CDN-versioned <code>asset.js?v=abc</code>) or hot-patching templates. Apache parity: <code>Substitute "s/foo/bar/"</code> (mod_substitute). Multi-line and streaming variants are on the roadmap.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BodyRewriteMiddleware;

$app->addMiddleware(new BodyRewriteMiddleware([
    // CDN URL rewrite for HTML responses
    '#https?://old-cdn\.example\.com/#' => 'https://cdn.example.com/',
    // Asset version cache-bust
    '#\.js"#'                            => '.js?v=' . APP_VERSION . '"',
], contentTypes: ['text/html', 'application/xhtml+xml']));
PHP]); ?>

<h3 id="host-router" class="mw-h3"><code>HostRouterMiddleware</code></h3>
<p>Routes by <code>Host</code> header inside one ZealPHP instance. nginx parity: multiple <code>server { server_name a.com; }</code> blocks. For true isolation prefer one ZealPHP process per host behind Caddy/Traefik; use this when ergonomic co-tenancy is the goal.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HostRouterMiddleware;

$app->addMiddleware(new HostRouterMiddleware([
    'app.example.com'   => fn($req, $next) => $next->handle($req),
    'admin.example.com' => function ($req, $next) {
        // Tighter middleware stack for the admin host
        $req = $req->withAttribute('app.scope', 'admin');
        return $next->handle($req);
    },
    'api.example.com'   => fn($req, $next) =>
        $next->handle($req->withAttribute('app.scope', 'api')),
    '__default'         => fn($req, $next) => $next->handle($req),
]));
PHP]); ?>

<h3 id="content-encoding" class="mw-h3"><code>ContentEncodingMiddleware</code></h3>
<p>Sets the response <code>Content-Encoding</code> header from the request URL's dot-separated file suffixes. Apache's <code>find_ct</code> walks every suffix and accumulates an encoding chain — <code>archive.tar.gz</code> with <code>AddEncoding x-gzip .gz</code> yields <code>Content-Encoding: x-gzip</code>, and a doubly-encoded <code>data.gz.gz</code> yields <code>gzip, gzip</code> (order preserved, duplicates intentionally kept). Additive and opt-in: never overrides a <code>Content-Encoding</code> a handler (or a real compression middleware) already set. Apache parity: <code>mod_mime AddEncoding</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ContentEncodingMiddleware;

$app->addMiddleware(new ContentEncodingMiddleware([
    'gz'  => 'gzip',
    'br'  => 'br',
    'bz2' => 'bzip2',
]));
PHP]); ?>

<h3 id="content-language" class="mw-h3"><code>ContentLanguageMiddleware</code></h3>
<p>Sets the response <code>Content-Language</code> header from the request URL's dot-separated suffixes — <code>page.en.html</code> with <code>AddLanguage en .en</code> yields <code>Content-Language: en</code>. Multiple language suffixes accumulate in order and are emitted comma-joined (RFC 9110 §8.5 allows a list). Additive and opt-in: only sets the header when the response doesn't already declare one. Apache parity: <code>mod_mime AddLanguage</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ContentLanguageMiddleware;

$app->addMiddleware(new ContentLanguageMiddleware([
    'en' => 'en',
    'fr' => 'fr',
    'de' => 'de',
]));
PHP]); ?>

<h3 id="merge-slashes" class="mw-h3"><code>MergeSlashesMiddleware</code></h3>
<p>Collapses runs of consecutive slashes in the request path to a single slash before routing, so <code>/a//b///c</code> matches the same route as <code>/a/b/c</code>. Internal rewrite (no redirect) — mutates <code>$g->server['REQUEST_URI']</code>, which the router reads. The query string is left untouched. Register it ahead of route-dependent middleware. Apache parity: <code>MergeSlashes On</code>. nginx parity: <code>merge_slashes on;</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\MergeSlashesMiddleware;

$app->addMiddleware(new MergeSlashesMiddleware());
// Now: /api//users///42  routes the same as  /api/users/42
PHP]); ?>

<h3 id="request-header" class="mw-h3"><code>RequestHeaderMiddleware</code></h3>
<p>Manipulates the request headers the application sees, before handlers run. Headers are written into <code>$g->server</code> using the mod_php CGI convention (<code>HTTP_&lt;NAME&gt;</code>, uppercased, dashes → underscores), so <code>apache_request_headers()</code>, <code>getallheaders()</code>, and <code>$g->server['HTTP_*']</code> reflect the change. Operations: <code>set</code> (replace/create), <code>append</code> / <code>add</code> (comma-joined append or create), <code>unset</code> (remove). Apache parity: <code>mod_headers RequestHeader</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RequestHeaderMiddleware;

$app->addMiddleware(new RequestHeaderMiddleware([
    ['op' => 'set',    'name' => 'X-Forwarded-Proto', 'value' => 'https'],
    ['op' => 'append', 'name' => 'X-Trace',           'value' => 'edge'],
    ['op' => 'unset',  'name' => 'X-Debug'],
]));
PHP]); ?>

<h3 id="return" class="mw-h3"><code>ReturnMiddleware</code></h3>
<p>Unconditionally returns a fixed response — the route handler never runs. For 3xx statuses the second argument is the redirect target (<code>Location</code>); for any other status it's the response body. Pair with <a href="#scoped"><code>ScopedMiddleware</code></a> to limit it to a path (the nginx <code>location { return ... }</code> shape). nginx parity: <code>return</code> directive.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;

// Outright block a path
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(403), '/blocked'));

// Permanent redirect from /old → /new
$app->addMiddleware(ScopedMiddleware::match(new ReturnMiddleware(301, '/new'), '#^/old$#'));

// Health-check stub
$app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(200, 'pong'), '/ping'));
PHP]); ?>

<h3 id="scoped" class="mw-h3"><code>ScopedMiddleware</code></h3>
<p>Apply another middleware only to matching request paths — the Apache-container equivalent for middleware. Two factory methods: <code>ScopedMiddleware::location($inner, '/admin')</code> is <code>&lt;Location "/admin"&gt;</code> (literal URL-path prefix — matches <code>/admin</code>, <code>/admin/x</code>, and — like Apache — <code>/administrator</code>; use a trailing slash or a regex for segment precision). <code>ScopedMiddleware::match($inner, '#^/api/#')</code> is <code>&lt;LocationMatch&gt;</code> / <code>&lt;FilesMatch&gt;</code> (PCRE against the path). Outside the scope the inner middleware is skipped entirely.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Middleware\BasicAuthMiddleware;
use ZealPHP\Middleware\BlockPhpExtMiddleware;

// Scope BasicAuth to /admin only
$app->addMiddleware(ScopedMiddleware::location(
    new BasicAuthMiddleware(htpasswdFile: __DIR__ . '/.htpasswd', realm: 'Admin'),
    '/admin'
));

// Refuse *.php URLs anywhere on the host
$app->addMiddleware(ScopedMiddleware::match(new BlockPhpExtMiddleware(), '#\.php$#'));
PHP]); ?>

<h3 id="set-env-if" class="mw-h3"><code>SetEnvIfMiddleware</code></h3>
<p>Sets request "environment" variables (into <code>$g->server</code>, where mod_php code reads them as <code>$_SERVER</code>) when an attribute of the request matches a regex. The classic use is tagging bots, internal IPs, or URL areas so downstream middleware / handlers can branch on a simple flag. Attribute names mirror Apache: the special tokens <code>Remote_Addr</code>, <code>Remote_Host</code>, <code>Server_Addr</code>, <code>Request_Method</code>, <code>Request_Protocol</code>, <code>Request_URI</code>; any other name is treated as a request header (so <code>User-Agent</code> gives <code>BrowserMatch</code> behaviour). Apache parity: <code>mod_setenvif</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\SetEnvIfMiddleware;

$app->addMiddleware(new SetEnvIfMiddleware([
    ['attr' => 'User-Agent',  'regex' => '#bot#i',    'set' => ['IS_BOT' => '1']],
    ['attr' => 'Request_URI', 'regex' => '#^/admin#', 'set' => ['ADMIN_AREA' => '1']],
    ['attr' => 'Remote_Addr', 'regex' => '#^10\.#',   'set' => ['INTERNAL' => '1']],
]));
PHP]); ?>

<h3 id="body-size-limit" class="mw-h3"><code>BodySizeLimitMiddleware</code></h3>
<p>Rejects oversized request bodies with <code>413 Content Too Large</code> before the handler runs. Accepts an integer (bytes) or a shorthand string (<code>'10M'</code>, <code>'512K'</code>). Pass <code>0</code> for unlimited. nginx parity: <code>client_max_body_size 10m;</code>. Apache parity: <code>LimitRequestBody</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\BodySizeLimitMiddleware;

// Reject uploads larger than 10 MB
$app->addMiddleware(new BodySizeLimitMiddleware('10M'));
PHP]); ?>

<h3 id="redirect" class="mw-h3"><code>RedirectMiddleware</code></h3>
<p>Declarative URL redirects — first matching rule short-circuits. Each rule is an associative array with either a <code>'from'</code> key (prefix match, like Apache <code>Redirect /old /new</code>) or a <code>'match'</code> key (PCRE regex with capture groups, like <code>RedirectMatch</code>). The per-rule default status is <code>302</code>; pass an explicit <code>'status'</code> per rule to override. A rule without a <code>'to'</code> key is silently skipped. Apache parity: <code>mod_alias</code> (<code>Redirect</code> / <code>RedirectMatch</code>).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RedirectMiddleware;

$app->addMiddleware(new RedirectMiddleware([
    // Permanent prefix redirect
    ['from' => '/blog',         'to' => '/articles',                'status' => 301],
    // Regex redirect with back-reference (permanent)
    ['match' => '#^/old/(.+)#', 'to' => '/new/$1',                 'status' => 301],
    // Temporary redirect (302 is the per-rule default)
    ['from' => '/beta',         'to' => 'https://beta.example.com', 'status' => 302],
]));
PHP]); ?>

<h3 id="referer" class="mw-h3"><code>RefererMiddleware</code></h3>
<p>Hotlink protection — refuses requests whose <code>Referer</code> header is not in the allowed set with <code>403 Forbidden</code>. Specs can be plain host names, wildcards (<code>*.example.com</code>), or regexes (prefixed with <code>~</code>). A missing <code>Referer</code> is allowed by default (<code>allowNone: true</code>). nginx parity: <code>valid_referers</code> / <code>if ($invalid_referer) { return 403; }</code>.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\RefererMiddleware;

$app->addMiddleware(new RefererMiddleware(
    referers:    ['example.com', '*.example.com'],
    serverNames: ['example.com'],   // own hosts always allowed
));
PHP]); ?>

<h3 id="csrf" class="mw-h3"><code>CsrfMiddleware</code></h3>
<p>Double-submit CSRF protection for state-mutating requests (<code>POST</code>, <code>PUT</code>, <code>PATCH</code>, <code>DELETE</code>). Generates a per-session token and validates it on every non-safe request. Pass an array of URL paths to exempt (e.g. API endpoints using their own token scheme).</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\CsrfMiddleware;

$app->addMiddleware(new CsrfMiddleware(
    exempt: ['/api/', '/webhooks/'],
));
PHP]); ?>

<h3 id="health-check" class="mw-h3"><code>HealthCheckMiddleware</code></h3>
<p>Short-circuits on health-check paths and returns a JSON response — <code>200 {"status":"ok"}</code> when healthy, <code>503 {"status":"unhealthy",...}</code> when the optional check callback returns an error string. Default path is <code>/healthz</code>; pass additional paths (<code>/readyz</code>, <code>/_health</code>) as needed. Route handlers never run for health-check paths.</p>
<?php App::render('/components/_code', [
    'code' => <<<'PHP'
use ZealPHP\Middleware\HealthCheckMiddleware;
use ZealPHP\Store;

$app->addMiddleware(new HealthCheckMiddleware(
    paths: ['/healthz', '/readyz'],
    check: function (): ?string {
        // Return null → healthy; non-null string → unhealthy reason
        try {
            Store::get('rate_limit', '__ping');
            return null;
        } catch (\Throwable $e) {
            return 'store unreachable: ' . $e->getMessage();
        }
    },
));
PHP]); ?>

<h2 class="mw-h2-ref">Custom middleware</h2>
<p class="mw-note-custom">Middleware always returns a <code>Psr\Http\Message\ResponseInterface</code> — that's PSR-15's contract, not ZealPHP's. Inside the route handler that the middleware wraps, the handler still uses the <a href="/responses#return-contract">universal return contract</a>; ZealPHP's <code>ResponseMiddleware</code> converts the return into a PSR-7 response before your middleware sees it.</p>

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
