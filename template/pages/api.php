<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">REST API — File-Based</h1>
<p class="section-desc">Drop a PHP file in <code>api/</code> and it becomes a REST endpoint automatically. The file defines a closure whose variable name matches the filename. <code>$this</code> inside the closure is the <code>ZealAPI</code> instance (that's the class powering this — keep reading).</p>

<h2>How it works</h2>

<?php App::render('/components/_code', [
    'label' => 'api/device/list.php → /api/device/list',
    'code'  => <<<'PHP'
<?php
// File: api/device/list.php
// Endpoint: /api/device/list — Mode 1: EVERY HTTP method hits this ONE handler.
// The variable name MUST match basename($file, '.php') → 'list'

use ZealPHP\G;

$list = function() {
    // $this is the ZealAPI instance. Because Mode 1 doesn't split GET/POST
    // into separate closures, use the helper to tell which verb came in:
    $method = $this->get_request_method();   // 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'

    if ($method === 'POST') {
        $g = G::instance();
        return ['created' => true, 'method' => $method, 'body' => $g->post];
    }

    // default (GET, HEAD, …)
    return [
        'devices' => [['id' => 1, 'name' => 'Sensor A'], ['id' => 2, 'name' => 'Sensor B']],
        'method'  => $method,
    ];
};
PHP]); ?>

<p>In <strong>Mode 1</strong> a single closure answers every HTTP method, so use
<code>$this->get_request_method()</code> to branch on the verb (it returns
<code>GET</code>, <code>POST</code>, <code>PUT</code>, <code>DELETE</code>, or
<code>PATCH</code>). If you'd rather split each method into its own closure, use
<a href="#per-method-dispatch">per-method dispatch</a> (Mode 2) instead.</p>

<h2>File naming convention</h2>
<table class="ztable">
  <tr><th>File</th><th>Variable</th><th>Endpoint</th><th>Notes</th></tr>
  <tr><td><code>api/device/list.php</code></td><td><code>$list</code></td><td><code>/api/device/list</code></td><td>Directory = module, filename = endpoint</td></tr>
  <tr><td><code>api/device/add.php</code></td><td><code>$add</code></td><td><code>/api/device/add</code></td><td>Handler decides what HTTP methods to accept</td></tr>
  <tr><td><code>api/learn/notes.php</code></td><td><code>$notes</code></td><td><code>/api/learn/notes</code></td><td>Responds to GET, POST, PUT, DELETE</td></tr>
  <tr><td><code>api/docs/search.php</code></td><td><code>$search</code></td><td><code>/api/docs/search</code></td><td>Filename is the action name</td></tr>
</table>

<div class="callout info">
The variable name <strong>must match</strong> the filename (without <code>.php</code>). <code>api/device/list.php</code> defines <code>$list = function() { ... };</code>. ZealAPI binds it as a Closure with <code>$this</code> set to the ZealAPI instance. Every endpoint accepts GET, POST, PUT, DELETE, and PATCH.
</div>

<h2 id="per-method-dispatch">Per-method dispatch</h2>
<p>Inspired by <a href="https://nextjs.org/docs/app/getting-started/route-handlers">Next.js App Router route handlers</a>: instead of one catch-all closure, define <code>$get</code>, <code>$post</code>, <code>$put</code>, <code>$delete</code>, or <code>$patch</code> — each handles its HTTP method. Undefined methods return <strong>405 Method Not Allowed</strong> automatically.</p>

<?php App::render('/components/_code', [
    'label' => 'api/users.php — one file, per-method handlers',
    'code'  => <<<'PHP'
<?php
$get = function() {
    return ['users' => [['id' => 1, 'name' => 'Alice']]];
};

$post = function() {
    return ['created' => true, 'id' => 3];
};

// PUT, DELETE, PATCH → automatic 405 Method Not Allowed
// HEAD → auto-derived from $get (body stripped by framework)
// OPTIONS → auto-generated Allow header
PHP]); ?>

<table class="ztable">
  <tr><th>Request</th><th>What happens</th></tr>
  <tr><td><code>GET /api/users</code></td><td>Runs <code>$get</code> → JSON response</td></tr>
  <tr><td><code>POST /api/users</code></td><td>Runs <code>$post</code> → JSON response</td></tr>
  <tr><td><code>DELETE /api/users</code></td><td><strong>405</strong> + <code>Allow: GET, POST, HEAD, OPTIONS</code></td></tr>
  <tr><td><code>HEAD /api/users</code></td><td>Runs <code>$get</code>, body stripped</td></tr>
</table>

<div class="callout warning">
<strong>Don't mix both conventions in one file.</strong> If <code>api/list.php</code> defines both <code>$list</code> (filename match) <em>and</em> <code>$get</code>/<code>$post</code>, the filename match wins and method handlers are unreachable. The framework logs a warning to <code>debug.log</code> when this happens.
</div>

<h2>Return value conventions</h2>
<p>API handlers ride the <a href="/responses#return-contract">universal return contract</a> — same shapes as route handlers, public files, <code>App::render()</code>, and <code>App::include()</code>. <code>int</code> = status, <code>array</code> = JSON, <code>string</code> = HTML, <code>Generator</code> = stream, <code>ResponseInterface</code> = PSR-7 used directly.</p>

<?php App::render('/components/_code', [
    'label' => 'api/products/list.php — return array for JSON',
    'code'  => <<<'PHP'
<?php
$list = function() {
    return [
        'products' => [
            ['id' => 1, 'name' => 'Widget', 'price' => 9.99],
            ['id' => 2, 'name' => 'Gadget', 'price' => 24.99],
        ],
        'total' => 2,
    ];
};
// → {"products": [...], "total": 2}
PHP]); ?>

<h2>Parameter injection</h2>
<p>API handlers get the same parameter injection as route handlers. <code>$req</code> / <code>$res</code> are accepted as short aliases for <code>$request</code> / <code>$response</code> — they receive the exact same wrappers:</p>

<?php App::render('/components/_code', [
    'label' => 'Magic parameters: $request, $response, $app, $server (auto-injected by name)',
    'code'  => <<<'PHP'
<?php
use ZealPHP\RequestContext;

$get = function($request, $response) {
    // ZealAPI's dispatcher injects these by parameter name (reflection-cached):
    //   $request  → ZealPHP\HTTP\Request  (wrapped OpenSwoole request; alias: $req)
    //   $response → ZealPHP\HTTP\Response (wrapped OpenSwoole response; alias: $res)
    //   $app      → ZealPHP\App instance  (the main application instance)
    //   $server   → \OpenSwoole\Http\Server (raw OpenSwoole server handle)
    //   $this     → ZealAPI instance      (handler runs inside Closure::bind)
    // Any other named parameter receives its default value (or null if none).

    // Pull a query param via the injected request — the cleanest form:
    $id = $request->get['id'] ?? null;
    //                  ^ ZealPHP\HTTP\Request->get is the OpenSwoole-parsed
    //                    query array (per-request, NOT the $_GET superglobal).

    if (!$id) return 400;                      // int return = HTTP status (universal contract)

    // Need $g (session, cookies, server vars)? Grab it explicitly:
    $g = RequestContext::instance();
    if (empty($g->session['user'])) return 401;

    return ['user' => User::find($id)];        // array return = JSON body (universal contract)
};
PHP]); ?>

<div class="callout info apidoc-mt-half">
<strong>Three equivalent ways to read query params</strong> inside an API handler — all are per-request safe (none touch the process-wide <code>$_GET</code> superglobal): <code>$request-&gt;get['id']</code> (injected parameter, cleanest), <code>RequestContext::instance()-&gt;get['id']</code> (useful when you also need <code>$g-&gt;session</code>), or <code>$this-&gt;_request['id']</code> (legacy form — <code>$this-&gt;_request</code> is the <strong>sanitized input array</strong> <code>ZealAPI</code> builds via <code>cleanInputs()</code>, so index it directly: <code>$this-&gt;_request['id']</code>, not <code>-&gt;get['id']</code>). Prefer the injected <code>$request</code> for new code. ZealAPI does NOT auto-inject <code>$g</code> by name — call <code>RequestContext::instance()</code> explicitly when you need it.
</div>

<h2>Streaming from APIs</h2>
<p>API handlers can return Generators for streaming responses:</p>

<?php App::render('/components/_code', [
    'label' => 'api/feed/stream.php — streaming JSON array',
    'code'  => <<<'PHP'
<?php
$stream = function() {
    return (function() {
        yield '{"events":[';
        $first = true;
        foreach (Event::cursor() as $event) {
            if (!$first) yield ',';
            yield json_encode($event->toArray());
            $first = false;
        }
        yield ']}';
    })();
};
PHP]); ?>

<h2>$this methods (ZealAPI instance)</h2>
<table class="ztable">
<tr><th>Property / Method</th><th>Description</th></tr>
<tr><td><code>$this->_request</code></td><td>Sanitized request input — GET (or GET+POST merged, or the parsed body) run through <code>cleanInputs()</code>. An array, not a request object.</td></tr>
<tr><td><code>$this->_response</code></td><td>The <code>ZealPHP\HTTP\Response</code> wrapper (its <code>-&gt;parent</code> is the raw OpenSwoole response). <code>$this-&gt;response()</code> writes through it.</td></tr>
<tr><td><code>$this->request</code></td><td>The injected <code>$request</code> — the <code>ZealPHP\HTTP\Request</code> wrapper (<code>$this-&gt;request-&gt;get</code> = per-request query array). Note: no underscore, distinct from <code>$this->_request</code>.</td></tr>
<tr><td><code>$this->paramsExists(['id', 'name'])</code></td><td>Check required params exist in GET/POST</td></tr>
<tr><td><code>$this->response($data, $status)</code></td><td>Send response with status code</td></tr>
<tr><td><code>$this->die($exception)</code></td><td>Handle exception and send error response</td></tr>
<tr><td><code>$this->get_request_method()</code></td><td>Returns GET, POST, PUT, DELETE</td></tr>
<tr><td><code>$this->setContentType($type)</code></td><td>Set response content type</td></tr>
<tr><td><code>$this->isAuthenticated()</code></td><td>Consults <code>App::authChecker()</code>. Default <code>false</code>. See below.</td></tr>
<tr><td><code>$this->isAdmin()</code></td><td>Consults <code>App::adminChecker()</code>. Default <code>false</code>.</td></tr>
<tr><td><code>$this->getUsername()</code></td><td>Consults <code>App::usernameProvider()</code>. Default <code>null</code>.</td></tr>
<tr><td><code>$this->requirePostAuth()</code></td><td>POST + authenticated guard. Returns <code>false</code> and emits <code>403</code> JSON on failure.</td></tr>
</table>

<h2 id="api-middleware">Scoping middleware to API endpoints</h2>
<p>
  ZealAPI files aren't individually registered routes, so there is <strong>no separate "api middleware"</strong> — <code>api/admin/x.php</code> is reached by the URL <code>/api/admin/x</code>, which flows through the same stack as everything else. Scope middleware by <strong>path</strong> with <code>App::when()</code> and it covers the api layer for free:
</p>
<?php App::render('/components/_code', [
    'label' => 'app.php — path-scoped middleware covers the api layer',
    'code'  => <<<'PHP'
App::middlewareAlias('auth', fn() => new BasicAuthMiddleware($verifier));

App::when('/api/admin', ['auth', 'admin-only']);  // every api/admin/*.php endpoint
App::when('/api',       ['request-id']);          // the whole /api/* surface
PHP]); ?>
<p>For a guard that belongs to <strong>one file</strong>, declare <code>$middleware</code> inline (read like <code>$get</code>/<code>$post</code>) — it runs <strong>innermost</strong>, closest to the handler:</p>
<?php App::render('/components/_code', [
    'label' => 'api/admin/users/delete.php — co-located guard',
    'code'  => <<<'PHP'
$middleware = ['confirm-token'];   // runs after App::when('/api/admin')['auth']

$delete = function () {
    return ['deleted' => true];
};
PHP]); ?>
<p class="apidoc-mb-1">Full model + ordering on the <a href="/middleware#path-scoped">middleware page</a>. Middleware <em>gates</em> the request ("should this reach the handler?"); the auth hooks below <em>identify</em> the authenticated user inside the handler — they compose.</p>

<h2 id="auth-hooks">Pluggable auth hooks <span class="badge apidoc-version-badge">v0.2.25</span></h2>
<p>
  ZealAPI doesn't know what your auth system looks like — your app might use ZealPHP sessions, a Symfony bundle, the SelfMade Ninja stack, a custom OAuth flow, or JWT in a header. So the framework <strong>doesn't bake an auth check in</strong>. Instead it consults three optional callbacks you register on <code>App</code>. They default to fail-closed values (<code>false</code>, <code>false</code>, <code>null</code>) so endpoints guarded by <code>requirePostAuth()</code> reject everything until you wire them up.
</p>

<table class="ztable apidoc-mb-1">
<tr><th>Setter</th><th>Signature</th><th>Consumed by</th><th>Default</th></tr>
<tr><td><code>App::authChecker(?callable)</code></td><td><code>fn(): bool</code></td><td><code>ZealAPI::isAuthenticated()</code></td><td><code>false</code></td></tr>
<tr><td><code>App::adminChecker(?callable)</code></td><td><code>fn(): bool</code></td><td><code>ZealAPI::isAdmin()</code></td><td><code>false</code></td></tr>
<tr><td><code>App::usernameProvider(?callable)</code></td><td><code>fn(): ?string</code></td><td><code>ZealAPI::getUsername()</code></td><td><code>null</code></td></tr>
</table>

<p>Wire them <strong>once</strong>, in your app's boot file (or in a framework wrapper's bootstrap if you're shipping a multi-app platform). Every ZealAPI handler downstream inherits the answers — no per-handler boilerplate.</p>

<?php App::render('/components/_code', [
    'label' => 'app.php — wiring ZealAPI auth to your own session',
    'code'  => <<<'PHP'
<?php
use ZealPHP\App;

require __DIR__ . '/vendor/autoload.php';

// Register the three hooks ONCE during boot. ZealPHP doesn't care what
// auth system you use — it just asks you. Callbacks run on each
// requirePostAuth() / isAuthenticated() / isAdmin() / getUsername() call,
// so you can read $_SESSION / $g->session / a JWT header / a global —
// whatever lives at the moment the API handler dispatches.
// Canonical: read via $g — works in BOTH App::superglobals modes.
App::authChecker(fn(): bool       => !empty(\ZealPHP\G::instance()->session['user_id']));
App::adminChecker(fn(): bool      => (\ZealPHP\G::instance()->session['role'] ?? '') === 'admin');
App::usernameProvider(fn(): ?string => \ZealPHP\G::instance()->session['username'] ?? null);

$app = App::init('0.0.0.0', 8080);
$app->run();
PHP,
]); ?>

<?php App::render('/components/_code', [
    'label' => 'api/device/delete.php — handler-side use',
    'code'  => <<<'PHP'
<?php
// File: api/device/delete.php — handler filters by method internally
$delete = function() {
    // POST + authenticated guard. Sends 403 JSON and returns false if
    // either check fails — short-circuits the handler.
    if (!$this->requirePostAuth()) return;

    // Admin-only operation? Compose checks naturally:
    if (!$this->isAdmin()) {
        return $this->response($this->json(['error' => 'admin_only']), 403);
    }

    $username = $this->getUsername();   // for audit log
    $g = \ZealPHP\G::instance();        // per-coroutine; safe in both modes
    User::delete($g->post['id'], $username);
    return ['ok' => true];
};
PHP,
]); ?>

<div class="callout info apidoc-mt-half">
<strong>Why three orthogonal setters instead of one auth-provider interface?</strong> Most apps need only <code>isAuthenticated()</code>; a few need <code>isAdmin()</code> too; a smaller subset wants <code>getUsername()</code> for logging. Three closures means the trivial case is one line, the polished case is three. The setters follow the existing <code>App::superglobals()</code> / <code>App::sessionLifecycle()</code> fluent precedent — same shape, same lifecycle (configure before <code>App::init()</code>, queried by ZealAPI at request time). See <a href="/learn/auth">the auth lesson</a> for a worked example with a real session.
</div>

<h2>Live ZealAPI endpoints</h2>
<?php
$demos = [
  ['api-sapi', 'GET /api/php/sapi_name — returns SAPI name', '/api/php/sapi_name', <<<'PHP'
// api/php/sapi_name.php
$sapi_name = function() {
    return ['sapi' => php_sapi_name(), 'async' => true];
};
PHP],
  ['api-get',  'GET /api/php/get — dump GET params',          '/api/php/get?demo=zealapi&works=true', <<<'PHP'
// api/php/get.php
$get = function() {
    $g = G::instance();
    return ['query_params' => $g->get, 'async' => php_sapi_name() === 'cli'];
};
PHP],
];
foreach ($demos as [$id, $title, $url, $code]) {
    App::render('/components/_demo', compact('id', 'title', 'url', 'code'));
}
?>

<h2 class="apidoc-mt-2-5">Error responses</h2>
<p class="apidoc-mb-1">All ZealAPI failures emit JSON with an <code>error</code> key and an HTTP status code. Use the <code>error</code> string to branch in client code; the <code>hint</code> is for humans.</p>

<table class="ztable">
<tr><th>Status</th><th>error</th><th>When</th></tr>
<tr><td><code>400</code></td><td><code>invalid_module</code></td><td>Path component fails the strict <code>[a-zA-Z0-9_/-]</code> regex (prevents traversal)</td></tr>
<tr><td><code>400</code></td><td><code>invalid_request</code></td><td>Method name contains characters other than <code>[a-zA-Z0-9_\-]</code></td></tr>
<tr><td><code>404</code></td><td><code>method_not_found</code></td><td>Handler file missing, or the expected closure variable name does not exist in the file</td></tr>
<tr><td><code>404</code></td><td><code>handler_not_found</code></td><td>Per-method dispatch: no matching <code>$get</code>/<code>$post</code>/… variable found in the file (file exists but the method handler is absent)</td></tr>
<tr><td><code>404</code></td><td><code>undefined_method</code></td><td>Handler called <code>$this-&gt;X()</code> but <code>X</code> is not a method on <code>ZealAPI/REST</code></td></tr>
<tr><td><code>405</code></td><td><code>method_not_allowed</code></td><td>Per-method dispatch: the file defines some method handlers (e.g. <code>$get</code>) but not the one the client used — <code>Allow</code> header lists the defined methods</td></tr>
<tr><td><code>500</code></td><td>—</td><td>Uncaught throwable inside the handler — stack trace is logged via <code>elog()</code></td></tr>
</table>

<h3 class="apidoc-mt-1-5">Typo detection — <code>undefined_method</code></h3>
<p class="apidoc-mb-1">When you call a method that doesn't exist on <code>$this</code> from inside a handler, ZealAPI no longer hangs (it used to recurse on <code>__call</code> until stack overflow). It returns 404 with a structured error and, when the typo is close to a real method, a <code>did_you_mean</code> hint computed via levenshtein.</p>

<?php App::render('/components/_demo', [
    'id'    => 'api-undefined-method',
    'title' => 'GET /api/bug/bad — handler typos $this-&gt;paramExist (real method is paramsExists)',
    'url'   => '/api/bug/bad',
    'code'  => <<<'PHP'
// api/bug/bad.php
$bad = function($request) {
    if ($this->paramExist(['id'])) {   // ← typo (real method is paramsExists)
        return ['id' => $request->get['id'] ?? 'n/a'];
    }
};
PHP,
]); ?>

<p class="apidoc-hint">If the typo is too far from any real method (more than 3 edits, or above 40% of the name length), the <code>did_you_mean</code> field is omitted to avoid misleading suggestions — only the <code>error</code>, <code>method</code>, and a generic hint are returned.</p>

<h2>Implicit public/ file serving</h2>
<p>Files in <code>public/</code> are served automatically — no route definition needed:</p>

<table class="ztable">
<tr><th>File</th><th>URL</th><th>How</th></tr>
<tr><td><code>public/index.php</code></td><td><code>/</code></td><td>Root route</td></tr>
<tr><td><code>public/about.php</code></td><td><code>/about</code></td><td>Filename → path (no <code>.php</code>)</td></tr>
<tr><td><code>public/admin/index.php</code></td><td><code>/admin/</code></td><td>Directory index</td></tr>
<tr><td><code>public/admin/users.php</code></td><td><code>/admin/users</code></td><td>Nested path</td></tr>
<tr><td><code>public/css/style.css</code></td><td><code>/css/style.css</code></td><td>Static file (served by OpenSwoole directly)</td></tr>
</table>

<div class="apidoc-split">
<div>
<?php App::render('/components/_code', [
    'label' => 'public/about.php — 3-line page',
    'code'  => <<<'PHP'
<?php use ZealPHP\App;
App::render('_master', [
    'title' => 'About Us',
    'page'  => 'about',
]);
PHP]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — streaming page',
    'code'  => <<<'PHP'
<?php
use ZealPHP\App;
// Return a Generator → streams to browser
return (function() {
    yield App::renderToString('shell-open',
        ['title' => 'Dashboard']);
    yield "<h1>Dashboard</h1>";
    yield App::renderToString('shell-close');
})();
PHP]); ?>
</div>
</div>

<p>Public files ride the <a href="/responses#return-contract">universal return contract</a> — same shapes as a route handler.</p>

<div class="callout info">
<strong>Static files</strong> (CSS, JS, images, fonts) in <code>public/</code> are served directly by OpenSwoole's <code>enable_static_handler</code> — they never hit PHP. Only <code>.php</code> files are processed by ZealPHP.
</div>

<h2>Task workers</h2>
<p>Task workers run CPU-intensive or background work without blocking HTTP workers. Dispatch tasks from any request handler; task handlers live in <code>task/</code>.</p>

<?php App::render('/components/_code', [
    'label' => 'task/backup.php — define a task handler',
    'code'  => <<<'PHP'
<?php
// File: task/backup.php
// The variable name must match basename → 'backup'

use function ZealPHP\elog;

$backup = function($db_name, $output_dir) {
    elog("Starting backup of $db_name to $output_dir");

    // Heavy work here — runs in task worker, not HTTP worker
    $file = "$output_dir/$db_name-" . date('Ymd-His') . ".sql";
    exec("mysqldump $db_name > $file");

    return ['status' => 'done', 'file' => $file];
};
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'Dispatch from a route handler',
    'code'  => <<<'PHP'
use ZealPHP\App;

$app->route('/admin/backup', ['methods' => ['POST']], function() {
    // Dispatch to task worker (non-blocking)
    App::getServer()->task([
        'handler' => '/task/backup',
        'args'    => ['my_database', '/backups'],
    ]);

    return ['queued' => true, 'message' => 'Backup started in background'];
});
PHP]); ?>

<h3>Task worker configuration</h3>
<?php App::render('/components/_code', [
    'label' => 'Enable task workers in app.php',
    'code'  => <<<'PHP'
$app->run([
    'task_worker_num' => 4,            // 4 dedicated task workers
    'task_enable_coroutine' => true,   // Coroutines in task workers (default)
]);
PHP]); ?>

<table class="ztable">
<tr><th>Concept</th><th>Detail</th></tr>
<tr><td>Handler naming</td><td>File <code>task/backup.php</code> defines <code>$backup = function(...) { ... }</code></td></tr>
<tr><td>Dispatch</td><td><code>App::getServer()->task(['handler' => '/task/backup', 'args' => [...]])</code></td></tr>
<tr><td>Return value</td><td>Received in the <code>finish</code> callback (logged by default)</td></tr>
<tr><td>Coroutines</td><td>Task workers run in coroutine mode — <code>go()</code>, channels, async I/O all work</td></tr>
<tr><td>Blocking safety</td><td>Tasks run in separate worker processes — CPU-bound work doesn't block HTTP</td></tr>
</table>

<div class="callout warn">
<strong>Default: 0 task workers.</strong> Set <code>task_worker_num</code> in <code>$app->run()</code> if you use task dispatch. Without task workers, <code>$server->task()</code> will fail silently.
</div>

</div>
</section>
