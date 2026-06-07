<?php use ZealPHP\App; ?>

<section class="section">
<div class="container">
<h1 class="section-title">Routing &amp; Parameter Injection</h1>
<p class="section-desc">ZealPHP uses reflection to inject route parameters, <code>$request</code>, <code>$response</code>, and <code>$app</code> into handlers by name — no annotations, no containers. <code>$req</code> / <code>$res</code> are accepted as short aliases for <code>$request</code> / <code>$response</code>.</p>

<!-- File-based routing -->
<h2 class="route-h2">File-based routing — just like LAMP</h2>
<p class="route-mb-1">Drop a <code>.php</code> file in <code>public/</code>. It becomes a route. No config, no registration, no framework code needed.</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>Notes</th></tr>
  <tr><td><code>public/index.php</code></td><td><code>/</code></td><td>Root route</td></tr>
  <tr><td><code>public/about.php</code></td><td><code>/about</code></td><td>Filename becomes the path (no <code>.php</code>)</td></tr>
  <tr><td><code>public/users/list.php</code></td><td><code>/users/list</code></td><td>Subdirectories work</td></tr>
  <tr><td><code>public/admin/index.php</code></td><td><code>/admin</code></td><td>Directory index</td></tr>
</table>

<p class="route-my-sm">Inside these files, everything you already know works:</p>

<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — coroutine-safe form (works in both modes)',
    'code'  => <<<'PHP'
<?php
use ZealPHP\RequestContext;

$g = RequestContext::instance();
session_start();
if (empty($g->session['user'])) { header('Location: /login'); exit; }
?>
<h1>Welcome, <?= htmlspecialchars($g->session['user']['name']) ?></h1>
<p>Your orders: <?= count($g->get['filter'] ?? []) ?> filters active</p>
PHP
]); ?>

<div class="callout info route-mt-1">
<strong>This is the migration on-ramp.</strong> Drop your existing PHP files into <code>public/</code> and they run on OpenSwoole immediately — <code>session_start()</code>, <code>header()</code>, <code>echo</code> all work unchanged via ext-zealphp overrides. The recommended form is <code>$g-&gt;session</code> / <code>$g-&gt;get</code> via <code>RequestContext::instance()</code> — works in both modes, no extension needed. With ext-zealphp, <code>$_GET</code> / <code>$_SESSION</code> are also per-coroutine safe in both modes (saved/restored on every yield/resume). See the <a href="/coroutines#state-parity"><code>$g</code> vs <code>$_*</code> parity rule</a> and the <a href="/migration">migration ladder</a>.
</div>

<p class="route-my-1">Same convention works for APIs — drop files in <code>api/</code>:</p>

<table class="ztable">
  <tr><th>File</th><th>URL</th><th>Notes</th></tr>
  <tr><td><code>api/device/list.php</code></td><td><code>/api/device/list</code></td><td>Filename match — <code>$list</code> handles all methods</td></tr>
  <tr><td><code>api/device/add.php</code></td><td><code>/api/device/add</code></td><td>Filename match — <code>$add</code> handles all methods</td></tr>
  <tr><td><code>api/users.php</code></td><td><code>/api/users</code></td><td>Per-method — <code>$get</code>/<code>$post</code>/… handle their method; others get 405</td></tr>
</table>

<p class="route-my-sm">Two conventions. <strong>Filename match</strong>: the closure variable matches the filename — all HTTP methods reach it. <strong>Per-method</strong>: define <code>$get</code>, <code>$post</code>, <code>$put</code>, <code>$delete</code>, <code>$patch</code> — each handles its method, undefined ones return 405. See <a href="/api#per-method-dispatch">/api#per-method-dispatch</a>.</p>

<?php App::render('/components/_code', [
    'label' => 'api/device/list.php — filename match (all methods)',
    'code'  => <<<'PHP'
<?php
$list = function () {
    $this->response($this->json(['devices' => []]), 200);
};
PHP
]); ?>

<p class="route-mt-1">Public files ride the <a href="/responses#return-contract">universal return contract</a> — same shapes as a route handler.</p>

<h2 class="route-h2-section">Programmatic routes</h2>
<p class="route-mb-1">When you need URL parameters, WebSocket, or middleware — use programmatic routes. File-based routing handles the rest.</p>

<!-- Route types -->
<h2 class="route-h2">Route types</h2>
<table class="ztable">
  <tr><th>Method</th><th>Example</th><th>Use when</th></tr>
  <tr><td><code>route()</code></td><td><code>/users/{id}</code></td><td>Standard URL with named segments</td></tr>
  <tr><td><code>nsRoute()</code></td><td><code>/admin/users</code></td><td>Group routes under a namespace prefix</td></tr>
  <tr><td><code>nsPathRoute()</code></td><td><code>/api/v1/users/list</code></td><td>Namespace + catch-all last segment (includes slashes)</td></tr>
  <tr><td><code>patternRoute()</code></td><td><code>/raw/(?P&lt;rest&gt;.*)</code></td><td>Full regex control</td></tr>
  <tr><td><code>ws()</code></td><td><code>/ws/chat</code></td><td>WebSocket endpoint</td></tr>
</table>

<!-- Route options -->
<h2 class="route-h2">Route options — <code>methods</code> &amp; <code>raw</code></h2>
<p class="route-mb-1">All four registrars (<code>route</code>, <code>nsRoute</code>, <code>nsPathRoute</code>, <code>patternRoute</code>) take the same options — pass them as an <strong>array</strong> (2nd argument) or as <strong>named arguments</strong>. The two forms are interchangeable and compose; a named argument overrides the matching array key.</p>
<table class="ztable">
  <tr><th>Option</th><th>Type / default</th><th>What it does</th></tr>
  <tr><td><code>methods</code></td><td><code>array</code>, default <code>['GET']</code></td><td>Allowed HTTP verbs. Lowercase is normalised to uppercase; a request with an unlisted verb is rejected.</td></tr>
  <tr><td><code>raw</code></td><td><code>bool</code>, default <code>false</code></td><td>Skip the per-request output buffer (<code>ob_start()</code>). For handlers that stream or write to <code>$response</code> directly (SSE, <code>$response-&gt;stream()</code>, binary payloads) instead of letting the framework capture echoed output.</td></tr>
</table>
<?php App::render('/components/_code', [
    'label' => 'Array form and named-argument form are equivalent',
    'code'  => <<<'PHP'
// Two-arg shorthand — GET only:
$app->route('/hello/{name}', fn($name) => "Hi {$name}");

// Array options (backward-compatible):
$app->route('/users', ['methods' => ['GET', 'POST']], $handler);

// Named arguments — same result:
$app->route('/users', methods: ['GET', 'POST'], handler: $handler);

// raw: skip output buffering for a hand-rolled streaming writer:
$app->route('/export.csv', methods: ['GET'], raw: true, handler: function($response) {
    $response->stream(fn($write) => $write("id,name\n"));
});
PHP
]); ?>

<!-- Per-route middleware -->
<h2 class="route-h2-section" id="per-route-middleware">Per-route middleware</h2>
<p class="route-mb-1">Attach a PSR-15 middleware chain to a <strong>single route</strong> — auth, headers, rate-limit, a redirect — without registering it globally. The <code>middleware</code> option is accepted by all four registrars (<code>route</code>, <code>nsRoute</code>, <code>nsPathRoute</code>, <code>patternRoute</code>), and like <code>methods</code>/<code>raw</code> it works as a named argument <em>and</em> as an array-option key. It's <strong>purely additive and backward-compatible</strong> — routes without <code>middleware</code> take the unchanged fast path with zero added work.</p>

<table class="ztable">
  <tr><th>Option</th><th>Type / default</th><th>What it does</th></tr>
  <tr><td><code>middleware</code></td><td><code>array</code>, default <code>[]</code></td><td>A list of <code>MiddlewareInterface</code> instances and/or named alias strings. Each runs only for this route, wrapping the handler.</td></tr>
</table>

<p class="route-my-sm">Entries are either a ready middleware <strong>instance</strong> or an <strong>alias string</strong> registered with <code>App::middlewareAlias()</code>. The two declaration forms — the <code>middleware:</code> named argument and the <code>['middleware' =&gt; [...]]</code> array-option key — <strong>combine</strong>: array-option entries run first (outermost), then named-argument entries.</p>

<?php App::render('/components/_code', [
    'label' => 'Per-route middleware — instances and alias strings, on any registrar',
    'code'  => <<<'PHP'
use ZealPHP\Middleware\{RequestIdMiddleware, IpAccessMiddleware};

// Mix alias strings with a live instance:
$app->route('/admin/users', methods: ['GET'],
    middleware: ['auth', 'request-id', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])],
    handler: fn() => User::all());

// Same option on nsRoute / nsPathRoute / patternRoute:
$app->nsRoute('api', '/jobs', middleware: ['request-id'], handler: $list);

// Array-option form — entries here run OUTSIDE the named-arg ones:
$app->route('/report', ['middleware' => ['auth']], $handler, middleware: ['request-id']);
// chain: auth (outer) -> request-id -> handler
PHP
]); ?>

<!-- Named aliases -->
<h2 class="route-h2">Named middleware aliases</h2>
<p class="route-mb-1">Register a reusable middleware once, reference it by name everywhere — the named-and-shared vocabulary from Traefik, the route-alias pattern from Laravel. Pass either a ready <code>MiddlewareInterface</code> instance (reused as-is) or a <strong>factory callable</strong> that returns one.</p>

<?php App::render('/components/_code', [
    'label' => 'App::middlewareAlias() — instance, factory, and parameterised form',
    'code'  => <<<'PHP'
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware, RequestIdMiddleware};

App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware($verifier));
App::middlewareAlias('admin-only', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']]));
App::middlewareAlias('request-id', fn() => new RequestIdMiddleware());

// Parameterised reference: 'throttle:120' calls the factory with the
// comma-split args, e.g. fn('120') — mirrors Laravel 'throttle:60,1'.
App::middlewareAlias('throttle', fn($n = '60') => new RateLimitMiddleware(limit: (int)$n));

$app->route('/admin/users', middleware: ['auth', 'admin-only', 'throttle:120'], handler: $fn);
PHP
]); ?>

<div class="callout info route-mt-1">
<strong>Factories run once, instances are shared.</strong> An alias factory is invoked <strong>once at <code>App::run()</code></strong> (boot, single-coroutine), and the resulting instance is shared across every request that uses the alias. So middleware <strong>must be stateless</strong> — one object serves all concurrent coroutines; keep per-request state in <code>$g</code> (<a href="/coroutines#state-parity"><code>RequestContext</code></a>), never on the middleware object.
</div>

<!-- Route groups -->
<h2 class="route-h2" id="route-groups">Route groups</h2>
<p class="route-mb-1">Apply a shared URL <strong>prefix</strong> and/or a shared <strong>middleware chain</strong> to many routes at once. <code>$app->group()</code> hands your callback a <code>RouteGroup</code> whose <code>route()</code>/<code>nsRoute()</code>/<code>nsPathRoute()</code>/<code>patternRoute()</code>/<code>group()</code> mirror <code>App</code>'s — each prepends the group prefix and prepends the group's shared middleware.</p>

<?php App::render('/components/_code', [
    'label' => '$app->group() — shared prefix + middleware, nesting',
    'code'  => <<<'PHP'
$app->group('/admin', ['auth', 'admin-only'], function ($g) {
    $g->route('/users',    fn() => User::all());       // /admin/users
    $g->route('/settings', fn() => Settings::get());   // /admin/settings

    $g->group('/audit', ['audit-log'], function ($g) { // nests the prefix + middleware
        $g->route('/recent', fn() => Audit::recent()); // /admin/audit/recent
        // chain: auth -> admin-only -> audit-log -> handler
    });
});

// Middleware is optional — pass just a prefix and a registrar:
$app->group('/v1', function ($g) {
    $g->route('/ping', fn() => 'pong');                // /v1/ping
});
PHP
]); ?>

<div class="callout info route-mt-1">
<strong>Group middleware wraps outside the route's own.</strong> Groups nest — an inner <code>$g->group()</code> composes its prefix and middleware onto the outer group's. One exception: <code>patternRoute()</code> inside a group does <strong>not</strong> auto-apply the prefix (a raw regex is ambiguous to prefix, so bake it into the pattern yourself) — the group <strong>middleware still applies</strong>.
</div>

<!-- Ordering -->
<h2 class="route-h2">Execution order</h2>
<p class="route-mb-1">A request walks the chain from the outside in; the response unwinds in reverse. A middleware that returns without calling the handler (a 403, a redirect) <strong>short-circuits</strong> — the handler and everything inside it never run.</p>

<table class="ztable">
  <tr><th>Order</th><th>Layer</th><th>Rule</th></tr>
  <tr><td>1 (outermost)</td><td>Global middleware</td><td>First-registered (<code>$app->addMiddleware()</code>) is outermost. Wraps every route.</td></tr>
  <tr><td>2</td><td>Group middleware</td><td>The group's shared chain, outer groups before inner.</td></tr>
  <tr><td>3</td><td>Route middleware</td><td>This route's own list — first-listed is outermost. (Array-option entries precede named-arg entries.)</td></tr>
  <tr><td>4 (innermost)</td><td>Handler</td><td>Runs last; its return value rides the <a href="/responses#return-contract">universal return contract</a>.</td></tr>
</table>

<div class="callout info route-mt-1">
<strong>Visualise it.</strong> <code>$app->describeRoutes()</code> returns the global chain (ending in <code>ResponseMiddleware (router)</code>), the registered aliases, and every route with its resolved middleware chain — before <em>or</em> after <code>run()</code>. The <a href="/middleware#visualizer">middleware visualizer</a> renders it as a Traefik-style chain view; <code>GET /demo/middleware/visualize</code> returns the raw JSON.
</div>

<!-- Worked example -->
<h2 class="route-h2">Worked example</h2>
<p class="route-mb-1">A correlation id on every request, basic-auth + an IP allow-list on the admin area, and a per-route rate limit — composed from aliases, a group, and an inline instance.</p>

<?php App::render('/components/_code', [
    'label' => 'app.php — aliases + group + per-route middleware',
    'code'  => <<<'PHP'
use ZealPHP\App;
use ZealPHP\Middleware\{BasicAuthMiddleware, IpAccessMiddleware, RateLimitMiddleware, RequestIdMiddleware};

$app = App::instance();

// 1) Register reusable middleware by name.
App::middlewareAlias('request-id', fn() => new RequestIdMiddleware());
App::middlewareAlias('auth',       fn() => new BasicAuthMiddleware($verifier));
App::middlewareAlias('throttle',   fn($n = '60') => new RateLimitMiddleware(limit: (int)$n));

// 2) request-id on every request, globally (outermost of all).
$app->addMiddleware(new RequestIdMiddleware());

// 3) The whole /admin area is auth-gated and IP-restricted.
$app->group('/admin', ['auth', new IpAccessMiddleware(['allow' => ['10.0.0.0/8']])], function ($g) {
    $g->route('/users', fn() => User::all());                       // auth -> ip -> handler

    // 4) One route gets an extra, tighter rate limit on top of the group chain.
    $g->route('/export', methods: ['POST'], middleware: ['throttle:30'],
        handler: fn() => Report::export());                         // auth -> ip -> throttle:30 -> handler
});

$app->run();
PHP
]); ?>

<div class="callout info route-mt-1">
<strong>Try the live demos.</strong> <code>GET /demo/middleware/route-level</code> stamps <code>X-Request-Id</code> + <code>X-Demo-Route</code> and echoes the request id; <code>/demo/middleware/plain</code> has no middleware (proving per-route scoping); <code>/demo/middleware/blocked</code> short-circuits with a 403 before the handler runs; <code>/demo/mwgroup/alpha</code> and <code>/demo/mwgroup/beta</code> share a group header.
</div>

<!-- Injection cases -->
<h2 class="route-h2">Parameter injection — every case</h2>
<p class="route-mb-1-5">All panels below auto-run against the live server. The handler signature determines what gets injected. <code>$req</code> / <code>$res</code> are accepted as short aliases for <code>$request</code> / <code>$response</code> — and the reserved framework-object names bind the injected object <strong>before</strong> any same-named URL segment (security fix #240), so <code>function($req)</code> always receives the wrapper, never a path string.</p>

<?php
$cases = [
  ['inject-1', 'URL param only',                '/demo/inject/url/42',
   <<<'PHP'
$app->route('/users/{id}', function($id) {
    return ['id' => $id];
});
PHP],
  ['inject-2', 'URL param + $request',          '/demo/inject/url-request/99',
   <<<'PHP'
$app->route('/users/{id}', function($id, $request) {
    return ['id' => $id, 'method' => $request->server['request_method']];
});
PHP],
  ['inject-3', 'URL param + $response',         '/demo/inject/url-response/7',
   <<<'PHP'
$app->route('/users/{id}', function($id, $response) {
    $response->header('X-User-Id', $id);
    return ['id' => $id, 'response_class' => get_class($response)];
});
PHP],
  ['inject-4', '$request only',                  '/demo/inject/request-only',
   <<<'PHP'
$app->route('/info', function($request) {
    return ['method' => $request->server['request_method'],
            'uri'    => $request->server['request_uri']];
});
PHP],
  ['inject-5', 'All: $id + $request + $response','/demo/inject/all/123',
   <<<'PHP'
$app->route('/full/{id}', function($id, $request, $response) {
    $response->header('X-Injected', 'yes');
    return ['id' => $id, 'method' => $request->server['request_method'],
            'response_class' => get_class($response)];
});
PHP],
  ['inject-6', 'Default param value',            '/demo/inject/defaults/abc',
   <<<'PHP'
// ZealPHP has no optional-segment syntax like {page?}.
// Express "optional" params by registering a base route with a default:
$app->route('/paged/{id}', function($id, $page = 1) {
    return ['id' => $id, 'page' => $page];  // page defaults to 1
});
PHP],
  ['inject-7', 'Default overridden by URL',      '/demo/inject/defaults/abc/5',
   <<<'PHP'
// Same handler — page is 5 from URL
$app->route('/paged/{id}/{page}', function($id, $page = 1) {
    return ['id' => $id, 'page' => $page];
});
PHP],
];
foreach ($cases as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<!-- Route type demos -->
<h2 class="route-h2">Live route type demos</h2>
<?php
$routeTypes = [
  ['rt-1', 'nsRoute — /demo/route/ns/items',                '/demo/route/ns/items',
   '$app->nsRoute(\'demo\', \'/route/ns/items\', function() {' . "\n" .
   '    return [\'route_type\' => \'nsRoute\', \'prefix\' => \'demo\'];' . "\n" .
   '});'],
  ['rt-2', 'nsPathRoute — catches full path after prefix',   '/demo/route/ns-path/api/v1/users/list',
   '$app->nsPathRoute(\'demo/route/ns-path\', \'{path}\', function($path) {' . "\n" .
   '    return [\'route_type\' => \'nsPathRoute\', \'captured\' => $path];' . "\n" .
   '});'],
  ['rt-3', 'patternRoute — regex match',                     '/demo/route/pattern',
   '$app->patternRoute(\'/demo/route/pattern\', function() {' . "\n" .
   '    return [\'route_type\' => \'patternRoute\'];' . "\n" .
   '});'],
];
foreach ($routeTypes as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 class="route-h2-top">Route priority</h2>
<p>Routes are matched in this order — the first match wins. Earlier in the list = higher priority:</p>

<table class="ztable">
<tr><th>#</th><th>Source</th><th>Loaded</th></tr>
<tr><td>1</td><td>Explicit <code>$app->route()</code> in <code>app.php</code></td><td>Before <code>$app->run()</code> (already in the table when <code>run()</code> starts)</td></tr>
<tr><td>2</td><td>Files in <code>route/*.php</code></td><td>At server startup (auto-included by <code>run()</code> via <code>glob</code>, after app.php routes)</td></tr>
<tr><td>3</td><td>Implicit API: <code>/api/{module}/{request}</code></td><td>Inside <code>$app->run()</code></td></tr>
<tr><td>4</td><td>Implicit public files: <code>/</code>, <code>/{file}</code>, <code>/{dir}/{uri}</code></td><td>Inside <code>$app->run()</code></td></tr>
<tr><td>5</td><td>Fallback handler (if <code>setFallback()</code> registered)</td><td>When nothing else matches</td></tr>
</table>

<div class="callout info">
<strong>Override implicit routes</strong> by placing a file in <code>route/</code>. For example, to customize <code>/admin/users</code> instead of letting it auto-resolve to <code>public/admin/users.php</code>, define an explicit route in <code>route/admin.php</code> — it loads first and takes precedence.
</div>

<h2 class="route-h2-top">Apache parity in public/ routing</h2>
<p class="route-mb-1">The implicit <code>public/</code> routes mirror Apache+mod_php's default DocumentRoot behavior — including the subtle directives most developers don't think about until something breaks. Each is on by default and toggleable via a static flag on <code>App</code>:</p>

<table class="ztable">
<tr><th>Apache directive</th><th>ZealPHP behavior</th><th>Flag</th></tr>
<tr>
  <td><code>DocumentRoot /path</code></td>
  <td>The folder every implicit route and the static handler resolve against — defaults to <code>public/</code></td>
  <td><code>App::documentRoot('public')</code> (set before <code>App::init()</code>)</td>
</tr>
<tr>
  <td><code>DirectorySlash On</code></td>
  <td><code>/docs</code> → <code>301 /docs/</code> when <code>docs</code> is a directory under <code>public/</code></td>
  <td><code>App::$directory_slash = true</code></td>
</tr>
<tr>
  <td><code>DirectoryIndex index.php index.html index.htm</code></td>
  <td>Walks the list in order; HTML/HTM served via <code>$response-&gt;sendFile()</code> so Range and ETag still work</td>
  <td><code>App::$directory_index</code> (array)</td>
</tr>
<tr>
  <td><code>AcceptPathInfo On</code></td>
  <td><code>/api.php/users/42</code> → <code>SCRIPT_NAME=/api.php</code>, <code>PATH_INFO=/users/42</code>; rewrites <code>REQUEST_URI</code> to just the script</td>
  <td><code>App::$path_info = true</code></td>
</tr>
<tr>
  <td><code>&lt;FilesMatch "^\.&gt;"</code> deny</td>
  <td>Any URL with a dotfile component (<code>.env</code>, <code>.git/config</code>) returns 403. <code>.well-known/</code> is allow-listed per RFC 8615.</td>
  <td><code>App::$block_dotfiles = true</code></td>
</tr>
<tr>
  <td>URL traversal rejection</td>
  <td><code>%2e%2e</code>, <code>\0</code>, backslash decoded and matched BEFORE route lookup → 400</td>
  <td>always on</td>
</tr>
<tr>
  <td>Static-handler URL whitelist</td>
  <td>At boot, <code>App::$static_handler_locations</code> defaults to <code>[]</code> (empty). When empty, the framework substitutes a safe whitelist: <code>/css/ /js/ /img/ /images/ /fonts/ /assets/ /static/ /favicon.ico /robots.txt</code>. Anything outside falls through to PHP routing.</td>
  <td><code>App::$static_handler_locations</code> (set before <code>run()</code>; <code>[]</code> = use default whitelist)</td>
</tr>
<tr>
  <td><code>ErrorDocument N /path</code></td>
  <td><code>App::instance()-&gt;setErrorHandler(404, $cb)</code> registers a per-status custom page; catch-all variant: <code>setErrorHandler($cb)</code>. See <a href="/responses">Responses</a>.</td>
  <td><code>App::$error_handlers</code> (private)</td>
</tr>
<tr>
  <td><code>FileETag</code> / <code>If-None-Match</code> / <code>If-Modified-Since</code></td>
  <td><code>$response-&gt;sendFile()</code> emits weak ETag (<code>W/"mtime-size"</code>) and <code>Last-Modified</code>; matches return 304. Range request honored on the same path.</td>
  <td>always on for <code>sendFile()</code></td>
</tr>
</table>

<div class="callout info route-mt-1">
<strong>For ETag on static assets too</strong>, disable OpenSwoole's built-in static handler (<code>enable_static_handler =&gt; false</code> in <code>$app-&gt;run()</code> settings) and add a wildcard route that calls <code>$response-&gt;sendFile()</code>. The built-in handler emits <code>Last-Modified</code> only — no ETag, no Range. The trade-off is a small per-request PHP hop. See the <a href="/http#parity">Apache parity</a> section.
</div>

<h2 class="route-h2-top">Pattern routes with named regex groups</h2>
<p><code>patternRoute</code> accepts any regex with named capture groups (PCRE <code>(?P&lt;name&gt;...)</code> syntax). Captured names are injected as handler parameters:</p>

<?php App::render('/components/_code', [
    'label' => 'Named capture group → handler parameter',
    'code'  => <<<'PHP'
// Match any URL starting with /raw/
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function($rest) {
    echo "You requested: $rest";
    return 202;
});

// Multiple groups
$app->patternRoute('/blog/(?P<year>\d{4})/(?P<slug>[a-z-]+)', function($year, $slug) {
    return ['year' => $year, 'slug' => $slug];
});

// Block .php extension entirely
$app->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
    $response->status(403);
    $response->write("403 Forbidden");
});
PHP]); ?>

</div>
</section>
