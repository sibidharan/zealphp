<?php
/**
 * Live demo API endpoints for the ZealPHP OSS website.
 *
 * Every "LIVE OUTPUT" panel on the website calls one of these.
 * Each returns JSON with the result + metadata so the panel can show it.
 *
 * Parameter injection demos — every case:
 *   /demo/inject/url/{id}           — URL param only
 *   /demo/inject/url-request/{id}   — URL + $request
 *   /demo/inject/url-response/{id}  — URL + $response
 *   /demo/inject/request-only       — $request only
 *   /demo/inject/all/{id}           — $id + $request + $response
 *   /demo/inject/defaults/{id}      — with default $page = 1
 *   /demo/inject/defaults/{id}/{page}
 *
 * Route type demos:
 *   /demo/route/ns/items            — nsRoute
 *   /demo/route/ns-path/{path}      — nsPathRoute (catch-all)
 *   /demo/route/pattern             — patternRoute
 *
 * Response demos:
 *   /demo/response/json
 *   /demo/response/redirect-301
 *   /demo/response/redirect-302
 *   /demo/response/headers
 *   /demo/response/cookie
 *
 * Coroutine demos:
 *   /demo/coroutine/parallel
 *   /demo/coroutine/channel
 *
 * Store/Counter demos:
 *   /demo/store/set-get
 *   /demo/store/incr
 *   /demo/counter/increment
 *
 * Session demos:
 *   /demo/session/write
 *   /demo/session/read
 *
 * Middleware demos:
 *   /demo/middleware/cors
 *   /demo/middleware/etag
 *   /demo/middleware/compress
 */

use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;
use ZealPHP\Counter;

$app = App::instance();

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function demo_t(): float { return microtime(true); }
function demo_ms(float $start): float { return round((microtime(true) - $start) * 1000, 2); }

// Shared demo counter (created in route file scope = before start())
static $demoCounter = null;
if ($demoCounter === null) {
    $demoCounter = new Counter(0);
}

// Shared demo store
Store::make('demo_store', 128, [
    'name'  => [\OpenSwoole\Table::TYPE_STRING, 64],
    'score' => [\OpenSwoole\Table::TYPE_INT,    8],
]);

// ---------------------------------------------------------------------------
// Parameter Injection
// ---------------------------------------------------------------------------

$app->route('/demo/inject/url/{id}', ['methods' => ['GET']], function($id) {
    return ['id' => $id, 'injected' => ['id'], 'note' => 'URL param only'];
});

$app->route('/demo/inject/url-request/{id}', ['methods' => ['GET']], function($id, $request) {
    return [
        'id'     => $id,
        'method' => $request->server['request_method'] ?? 'GET',
        'uri'    => $request->server['request_uri']    ?? '',
        'injected' => ['id', 'request'],
    ];
});

$app->route('/demo/inject/url-response/{id}', ['methods' => ['GET']], function($id, $response) {
    $response->header('X-Injected-Id', $id);
    return [
        'id'             => $id,
        'response_class' => get_class($response),
        'header_set'     => 'X-Injected-Id: ' . $id,
        'injected'       => ['id', 'response'],
    ];
});

$app->route('/demo/inject/request-only', ['methods' => ['GET']], function($request) {
    return [
        'method'   => $request->server['request_method'] ?? 'GET',
        'uri'      => $request->server['request_uri']    ?? '',
        'injected' => ['request'],
    ];
});

$app->route('/demo/inject/all/{id}', ['methods' => ['GET']], function($id, $request, $response) {
    $response->header('X-Full-Inject', 'yes');
    return [
        'id'             => $id,
        'method'         => $request->server['request_method'] ?? 'GET',
        'response_class' => get_class($response),
        'injected'       => ['id', 'request', 'response'],
    ];
});

$app->route('/demo/inject/defaults/{id}', ['methods' => ['GET']], function($id, $page = 1) {
    return ['id' => $id, 'page' => $page, 'note' => 'page used default value: 1'];
});

$app->route('/demo/inject/defaults/{id}/{page}', ['methods' => ['GET']], function($id, $page = 1) {
    return ['id' => $id, 'page' => $page, 'note' => 'page from URL'];
});

// ---------------------------------------------------------------------------
// Route types
// ---------------------------------------------------------------------------

$app->nsRoute('demo/route', '/ns/items', ['methods' => ['GET']], function() {
    return ['route_type' => 'nsRoute', 'namespace' => 'demo/route', 'path' => '/ns/items'];
});

$app->nsPathRoute('demo/route/ns-path', '{path}', ['methods' => ['GET']], function($path) {
    return ['route_type' => 'nsPathRoute', 'captured' => $path, 'note' => 'last param catches everything including slashes'];
});

$app->patternRoute('/demo/route/pattern', ['methods' => ['GET']], function() {
    return ['route_type' => 'patternRoute', 'note' => 'full regex control, no {param} syntax needed'];
});

// ---------------------------------------------------------------------------
// Response methods
// ---------------------------------------------------------------------------

$app->route('/demo/response/json', ['methods' => ['GET']], function() {
    return ['framework' => 'ZealPHP', 'async' => true, 'engine' => 'OpenSwoole', 'time' => time()];
});

$app->route('/demo/response/redirect-301', ['methods' => ['GET']], function($response) {
    $response->redirect('/routing', 301);
});

$app->route('/demo/response/redirect-302', ['methods' => ['GET']], function($response) {
    $response->redirect('/routing', 302);
});

$app->route('/demo/response/headers', ['methods' => ['GET']], function($response) {
    $response->header('X-Framework',   'ZealPHP');
    $response->header('X-Async',       'true');
    $response->header('Cache-Control', 'no-store');
    return ['headers_set' => ['X-Framework: ZealPHP', 'X-Async: true', 'Cache-Control: no-store']];
});

$app->route('/demo/response/cookie', ['methods' => ['GET']], function($response) {
    $response->cookie('demo_session', 'abc123', 0, '/', '', false, true, 'Strict');
    return ['cookie_set' => 'demo_session=abc123; SameSite=Strict; HttpOnly'];
});

// ---------------------------------------------------------------------------
// Coroutines
// ---------------------------------------------------------------------------

$app->route('/demo/coroutine/parallel', ['methods' => ['GET']], function() {
    $ch    = new Channel(3);
    $start = microtime(true);

    // Simulate 3 DB/API fetches running in parallel
    go(function() use ($ch) { usleep(1000000); $ch->push(['source' => 'users',  'count' => 42]); });
    go(function() use ($ch) { usleep(1000000); $ch->push(['source' => 'orders', 'count' => 18]); });
    go(function() use ($ch) { usleep(1000000); $ch->push(['source' => 'stats',  'count' => 99]); });

    $results = [];
    for ($i = 0; $i < 3; $i++) $results[] = $ch->pop();

    return [
        'results'    => $results,
        'elapsed_s'  => round(microtime(true) - $start, 3),
        'note'       => 'All 3 ran in parallel — total ≈ 1s not 3s',
    ];
});

$app->route('/demo/coroutine/channel', ['methods' => ['GET']], function() {
    $ch    = new Channel(1);
    $start = microtime(true);

    go(function() use ($ch) {
        usleep(500000); // 0.5s
        $ch->push(['value' => 42, 'from' => 'producer coroutine', 'pid' => getmypid()]);
    });

    $result = $ch->pop(2); // wait up to 2s
    return [
        'received'  => $result,
        'elapsed_s' => round(microtime(true) - $start, 3),
        'pattern'   => 'producer/consumer via Channel',
    ];
});

// ---------------------------------------------------------------------------
// Store + Counter
// ---------------------------------------------------------------------------

$app->route('/demo/store/set-get', ['methods' => ['GET']], function() {
    Store::set('demo_store', 'alice', ['name' => 'Alice Wonderland', 'score' => 100]);
    Store::set('demo_store', 'bob',   ['name' => 'Bob Builder',      'score' => 75]);
    $alice = Store::get('demo_store', 'alice');
    return [
        'alice'        => $alice,
        'total_rows'   => Store::count('demo_store'),
        'worker_pid'   => getmypid(),
        'note'         => 'Shared across all forked workers via OpenSwoole\Table',
    ];
});

$app->route('/demo/store/incr', ['methods' => ['GET']], function() {
    Store::set('demo_store', 'page_hits', ['name' => 'page_hits', 'score' => 0]);
    $v1 = Store::incr('demo_store', 'page_hits', 'score');
    $v2 = Store::incr('demo_store', 'page_hits', 'score');
    $v3 = Store::incr('demo_store', 'page_hits', 'score', 5);
    return ['after_incr_1' => $v1, 'after_incr_2' => $v2, 'after_incr_5' => $v3, 'worker_pid' => getmypid()];
});

$app->route('/demo/counter/increment', ['methods' => ['GET']], function() use ($demoCounter) {
    $new = $demoCounter->increment();
    return [
        'total' => $new,
        'pid'   => getmypid(),
        'note'  => 'Lock-free atomic shared across all workers (OpenSwoole\Atomic)',
    ];
});

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

$app->route('/demo/session/write', ['methods' => ['GET']], function() {
    $g = G::instance();
    $g->session['demo_user']     = ['id' => 1, 'name' => 'alice'];
    $g->session['demo_login_at'] = time();
    return ['written' => $g->session['demo_user'], 'keys' => array_keys($g->session)];
});

$app->route('/demo/session/read', ['methods' => ['GET']], function() {
    $g = G::instance();
    return [
        'session_keys' => array_keys($g->session),
        'has_user'     => isset($g->session['demo_user']),
        'session_id'   => session_id(),
        'is_isolated'  => true,
        'note'         => 'Each coroutine has its own G::instance()->session via Coroutine::getContext()',
    ];
});

// ---------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------

$app->route('/demo/middleware/cors', ['methods' => ['GET', 'POST']], function() {
    return [
        'cors_active'    => true,
        'note'           => 'Check response headers for Access-Control-Allow-Origin: *',
        'middleware'     => 'CorsMiddleware',
    ];
});

$app->route('/demo/middleware/etag', ['methods' => ['GET']], function() {
    return [
        'etag_demo'  => true,
        'content'    => str_repeat('ZealPHP', 50), // stable content = stable ETag
        'note'       => 'First request returns ETag header. Repeat with If-None-Match to get 304.',
    ];
});

$app->route('/demo/middleware/compress', ['methods' => ['GET']], function() {
    return [
        'compression' => true,
        'body'        => str_repeat('ZealPHP is fast and async. ', 100),
        'note'        => 'Send Accept-Encoding: gzip to see Content-Encoding: gzip in response',
    ];
});

// ---------------------------------------------------------------------------
// Template fragments — App::fragment() (added in v0.2.24).
//
// Same URL renders either the full contacts list (browser navigation /
// htmx-boost) OR just one row (htmx swap with hx-get="?fragment=contact-N").
// One template file, one route handler, both responses. The template uses
// App::fragment('contact-{id}', ...) to mark each row as a named region;
// the framework either runs them inline (no fragment selector → full page)
// or extracts the matching one (selector present → just that row).
//
// See /learn/htmx#fragments for the teaching version.
// ---------------------------------------------------------------------------

$app->route('/demo/fragments/contacts', ['methods' => ['GET']], function() {
    $g = \ZealPHP\RequestContext::instance();
    $contacts = [
        ['id' => 1, 'name' => 'Alice Chen',  'role' => 'Backend lead',  'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob Singh',   'role' => 'Designer',      'email' => 'bob@example.com'],
        ['id' => 3, 'name' => 'Carol Davis', 'role' => 'Infra engineer','email' => 'carol@example.com'],
        ['id' => 4, 'name' => 'Dan Mei',     'role' => 'Product',       'email' => 'dan@example.com'],
    ];
    $fragment = is_string($g->get['fragment'] ?? null) ? $g->get['fragment'] : null;

    if ($fragment !== null) {
        // htmx swap response — bare fragment HTML, no shell, so htmx replaces
        // just the matched row. The framework returns 404 automatically if
        // the fragment selector doesn't match any App::fragment() block.
        return App::render('/demos/contacts-list', [
            'contacts' => $contacts,
            'fragment' => $fragment,
        ]);
    }

    // Full page render — wrap in the standard _demo_shell so the page has
    // the same breadcrumb + theme as every other /demo/view/ page on the
    // site, with a back-link to the htmx lesson where this is taught.
    $body = App::renderToString('/demos/contacts-list', [
        'contacts' => $contacts,
        'fragment' => null,
    ]);
    return demo_render(
        'Template fragments — contacts list',
        'One template file, two responses. Click <strong>Show details</strong> on any row — htmx requests <code class="demo-inline">?fragment=contact-{id}</code> and the server returns only that row\'s markup, not the whole page. Open DevTools → Network → XHR to confirm each swap is a single 200 response with just one <code class="demo-inline">&lt;li&gt;</code> in the body. Try <a href="/demo/fragments/contacts?fragment=does-not-exist" target="_blank" rel="noopener">/demo/fragments/contacts?fragment=does-not-exist</a> for the HTTP 404 case — fragment selector with no matching region, no fallback to the full page.',
        [['heading' => 'Live contacts', 'body' => $body]],
        'learn/htmx',
        'Forms & htmx'
    );
});

// ---------------------------------------------------------------------------
// Demo viewers — open in a new tab from lesson "Try it live" links.
// Each viewer wraps the raw demo output in a standalone HTML shell
// (template/components/_demo_shell.php) with a back-link to the lesson.
// The raw /demo/<...> endpoints above stay JSON for tests and direct API
// use; the viewers below are the human-friendly versions.
// ---------------------------------------------------------------------------

/**
 * Render a demo viewer page through a clean standalone shell —
 * site CSS (zealphp.css + learn.css) for typography and colors, but no
 * big top-nav or footer. The whole shell + breadcrumb + body lives in
 * template/components/_demo_shell.php.
 *
 * @param array<int, array{heading: string, body: string}> $sections
 */
function demo_render(string $title, string $description, array $sections, string $back_slug, string $back_label): string {
    return App::renderToString('/components/_demo_shell', [
        'title'       => $title,
        'description' => $description,
        'sections'    => $sections,
        'back_slug'   => $back_slug,
        'back_label'  => $back_label,
    ]);
}

/** Renders one "Response" section showing status + content-type + payload. */
function demo_section_response(int $status, string $contentType, string $payload, bool $pretty = true): array {
    if ($pretty && stripos($contentType, 'json') !== false) {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) $payload = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    $cls = 's' . substr((string)$status, 0, 1) . 'xx';
    $body = '<dl class="demo-kv">'
          . '<dt>Status</dt><dd><span class="demo-status ' . $cls . '">' . $status . ' ' . htmlspecialchars(_demo_phrase($status)) . '</span></dd>'
          . '<dt>Content-Type</dt><dd>' . htmlspecialchars($contentType) . '</dd>'
          . '</dl>'
          . '<pre class="demo-payload" style="margin-top:.6rem">' . htmlspecialchars($payload) . '</pre>';
    return ['heading' => 'Response', 'body' => $body];
}

function _demo_phrase(int $s): string {
    return match($s) {
        200 => 'OK', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found',
        304 => 'Not Modified', 400 => 'Bad Request', 401 => 'Unauthorized',
        403 => 'Forbidden', 404 => 'Not Found', 500 => 'Internal Server Error',
        default => '',
    };
}

// Inject viewers ------------------------------------------------------------

$app->route('/demo/view/inject/url/{id}', ['methods' => ['GET']], function($id) {
    return demo_render(
        'Inject: URL param only',
        'Route: <code class="demo-inline">$app->route(\'/demo/inject/url/{id}\', function ($id) { ... })</code>. The framework injected <code class="demo-inline">$id</code> by name from the URL pattern. Nothing else was injected.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/inject/url/' . htmlspecialchars($id) . '</code>'],
            demo_section_response(200, 'application/json', json_encode(['id' => $id, 'injected' => ['id'], 'note' => 'URL param only'])),
        ],
        'learn/injection', 'Parameter Injection'
    );
});

$app->route('/demo/view/inject/request-only', ['methods' => ['GET']], function($request) {
    $payload = ['method' => $request->server['REQUEST_METHOD'] ?? 'GET', 'uri' => $request->server['REQUEST_URI'] ?? '/', 'host' => $request->header['host'] ?? '', 'injected' => ['request']];
    return demo_render(
        'Inject: $request only',
        'Route handler declares only <code class="demo-inline">$request</code>. The framework injects the <code class="demo-inline">ZealPHP\HTTP\Request</code> wrapper — headers, query, body, server vars, all accessible.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/inject/request-only</code>'],
            demo_section_response(200, 'application/json', json_encode($payload)),
        ],
        'learn/injection', 'Parameter Injection'
    );
});

$app->route('/demo/view/inject/url-response/{id}', ['methods' => ['GET']], function($id, $response) {
    $response->header('X-Demo-Injected', 'id+response');
    return demo_render(
        'Inject: URL param + $response',
        'Two-name injection: <code class="demo-inline">function ($id, $response)</code>. The framework injects <code class="demo-inline">$id</code> from the URL and <code class="demo-inline">$response</code> from the framework. Note the custom <code class="demo-inline">X-Demo-Injected</code> header on this very response.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/inject/url-response/' . htmlspecialchars($id) . '</code>'],
            demo_section_response(200, 'application/json', json_encode(['id' => $id, 'injected' => ['id', 'response'], 'header_set' => 'X-Demo-Injected: id+response'])),
        ],
        'learn/injection', 'Parameter Injection'
    );
});

$app->route('/demo/view/inject/all/{id}', ['methods' => ['GET']], function($id, $request, $response) {
    $response->header('X-Demo-Injected', 'all');
    $payload = ['id' => $id, 'method' => $request->server['REQUEST_METHOD'] ?? 'GET', 'query' => $request->get ?? [], 'injected' => ['id', 'request', 'response']];
    return demo_render(
        'Inject: $id + $request + $response',
        'Three-name injection: <code class="demo-inline">function ($id, $request, $response)</code>. Order does not matter — injection is by parameter name. Try adding <code class="demo-inline">?foo=bar&debug=1</code> to the URL and reload.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/inject/all/' . htmlspecialchars($id) . '</code> · try <code class="demo-inline">?foo=bar</code>'],
            demo_section_response(200, 'application/json', json_encode($payload)),
        ],
        'learn/injection', 'Parameter Injection'
    );
});

$app->route('/demo/view/inject/defaults/{id}', ['methods' => ['GET']], function($id, $page = 1) {
    return demo_render(
        'Inject: default parameter values',
        'Handler signature: <code class="demo-inline">function ($id, $page = 1)</code>. The framework injects <code class="demo-inline">$id</code> from the URL; <code class="demo-inline">$page</code> isn\'t in the URL or the framework injection table, so the default value (<code class="demo-inline">1</code>) is used. To override, use the path with two segments — see the related URL below.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/inject/defaults/' . htmlspecialchars($id) . '</code> · with page: <code class="demo-inline">/demo/inject/defaults/' . htmlspecialchars($id) . '/7</code>'],
            demo_section_response(200, 'application/json', json_encode(['id' => $id, 'page' => $page, 'note' => '$page used default value 1 because no override was passed'])),
        ],
        'learn/injection', 'Parameter Injection'
    );
});

// Response viewers ----------------------------------------------------------

$app->route('/demo/view/response/json', ['methods' => ['GET']], function() {
    $payload = ['framework' => 'ZealPHP', 'async' => true, 'engine' => 'OpenSwoole', 'time' => time()];
    return demo_render(
        'Response: return an array → JSON',
        'Handler returns a plain PHP array. The framework auto-encodes it as JSON and sets <code class="demo-inline">Content-Type: application/json</code>. No need to call <code class="demo-inline">$response->json()</code>.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/response/json</code>'],
            demo_section_response(200, 'application/json', json_encode($payload)),
        ],
        'learn/responses', 'Returning a Response'
    );
});

$app->route('/demo/view/response/redirect-302', ['methods' => ['GET']], function() {
    return demo_render(
        'Response: 302 redirect',
        'Handler called <code class="demo-inline">$response->redirect(\'/\')</code> which returns a PSR-7 response with status 302 and a <code class="demo-inline">Location: /</code> header. The browser would normally follow this — opened directly so you see the response, not the destination.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/response/redirect-302</code> · related: <a href="/demo/view/response/redirect-301">redirect-301</a>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s3xx">302 Found</span></dd><dt>Location</dt><dd><code class="demo-inline">/</code></dd></dl>'],
        ],
        'learn/responses', 'Returning a Response'
    );
});

$app->route('/demo/view/response/redirect-301', ['methods' => ['GET']], function() {
    return demo_render(
        'Response: 301 permanent redirect',
        'Handler called <code class="demo-inline">$response->redirect(\'/\', 301)</code>. Status 301 tells the browser and search engines that the move is permanent — bookmarks and indexes get updated.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/response/redirect-301</code>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s3xx">301 Moved Permanently</span></dd><dt>Location</dt><dd><code class="demo-inline">/</code></dd></dl>'],
        ],
        'learn/responses', 'Returning a Response'
    );
});

$app->route('/demo/view/response/headers', ['methods' => ['GET']], function() {
    return demo_render(
        'Response: custom headers',
        'Handler called <code class="demo-inline">$response->header(\'X-Demo-Method\', \'response-header()\')</code> and similar for two more custom headers. Open DevTools → Network → this request to see them all in the response.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/response/headers</code>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>X-Demo-Method</dt><dd><code class="demo-inline">response-header()</code></dd><dt>X-Powered-By</dt><dd><code class="demo-inline">ZealPHP + OpenSwoole</code></dd><dt>X-Demo-Time</dt><dd><code class="demo-inline">' . htmlspecialchars((string)time()) . '</code></dd></dl><p style="margin:.85rem 0 0;color:#57534e;font-size:.85rem">Inspect this very response in DevTools Network — the headers above are real, set live by the demo route.</p>'],
        ],
        'learn/responses', 'Returning a Response'
    );
});

$app->route('/demo/view/response/cookie', ['methods' => ['GET']], function($response) {
    $response->cookie('zealphp_demo_cookie', 'set-at-' . time(), time() + 3600, '/', '', false, false, 'Lax');
    return demo_render(
        'Response: $response->cookie()',
        'Handler called <code class="demo-inline">$response->cookie(\'zealphp_demo_cookie\', ...)</code>. Look at <code class="demo-inline">document.cookie</code> in DevTools console to confirm it landed. Cookie path <code class="demo-inline">/</code>, SameSite=Lax, expires in 1 hour.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/response/cookie</code>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Set-Cookie</dt><dd><code class="demo-inline">zealphp_demo_cookie=set-at-' . time() . '; Max-Age=3600; Path=/; SameSite=Lax</code></dd></dl>'],
        ],
        'learn/responses', 'Returning a Response'
    );
});

// Store + Counter viewers --------------------------------------------------

$app->route('/demo/view/store/set-get', ['methods' => ['GET']], function() {
    return demo_render(
        'Store: write → read across workers',
        'Write a row to a shared-memory <code class="demo-inline">Store</code> table. Every worker can read what any other worker wrote &mdash; no Redis, no network round-trip. Open this page in another window: click <strong>Write row</strong> here and watch the row contents update there live.',
        [
            ['heading' => 'Write a row', 'body' =>
                '<form data-store-form style="display:grid;grid-template-columns:1fr 1fr auto;gap:.6rem;align-items:end;padding:.4rem 0">' .
                '  <label style="font-size:.85rem;color:#57534e">name<input name="name" required maxlength="60" placeholder="e.g. high-score" style="display:block;margin-top:.2rem;width:100%;padding:.45rem .6rem;border:1px solid #d6d3d1;border-radius:6px"></label>' .
                '  <label style="font-size:.85rem;color:#57534e">who<input name="who" maxlength="60" value="viewer" style="display:block;margin-top:.2rem;width:100%;padding:.45rem .6rem;border:1px solid #d6d3d1;border-radius:6px"></label>' .
                '  <button type="submit" class="btn btn-primary" style="padding:.55rem 1rem">Write row</button>' .
                '</form>' .
                '<p style="margin:.6rem 0 0;font-size:.85rem;color:#78716c">Hits <code class="demo-inline">Store::set(\'ws_store_demo_data\', \'shared_row\', […])</code>. Broadcasts to every connected tab.</p>'
            ],
            ['heading' => 'Current row (live across tabs)', 'body' =>
                '<pre class="demo-payload" data-store-row>connecting…</pre>' .
                '<div data-store-status class="ws-counter-status" style="margin-top:.65rem">connecting…</div>'
            ],
            ['heading' => 'How it works', 'body' =>
                '<pre class="demo-payload">// route/learn.php — boot
Store::make(\'ws_store_demo_data\', 32, [
    \'n\'    =&gt; [Table::TYPE_INT,    8],
    \'name\' =&gt; [Table::TYPE_STRING, 64],
    \'who\'  =&gt; [Table::TYPE_STRING, 64],
    \'ts\'   =&gt; [Table::TYPE_INT,    8],
]);

// POST /api/learn/demo/store-write
Store::set(\'ws_store_demo_data\', \'shared_row\', [
    \'name\' =&gt; $name,
    \'who\'  =&gt; $who,
    \'ts\'   =&gt; time(),
]);
ws_store_demo_broadcast();   // push current row to every /ws/store-demo client</pre>'],
        ],
        'learn/store', 'Sharing State'
    );
});

$app->route('/demo/view/store/incr', ['methods' => ['GET']], function() {
    return demo_render(
        'Store: atomic increment',
        '<code class="demo-inline">Store::incr(\'table\', \'row\', \'column\', $by)</code> increments an integer column atomically across all workers. No locks, no read-modify-write race &mdash; one syscall. Click <strong>+1</strong>. Open this URL in another window: every tab tracks the same value.',
        [
            ['heading' => 'Live counter (atomic across workers)', 'body' =>
                '<div class="ws-counter-card">' .
                '  <div class="ws-counter-value" data-store-counter-value>0</div>' .
                '  <p class="ws-counter-label">stored as <code>ws_store_demo_data.shared_row.n</code> &middot; bumped via <code>Store::incr()</code></p>' .
                '  <div class="ws-counter-actions">' .
                '    <button type="button" class="btn btn-primary" data-store-counter="bump">+1</button>' .
                '    <button type="button" class="btn btn-ghost" data-store-counter="reset">Reset</button>' .
                '  </div>' .
                '  <div data-store-status class="ws-counter-status">connecting…</div>' .
                '</div>'
            ],
            ['heading' => 'Open in 2+ tabs to see it', 'body' =>
                '<p style="margin:0">Multiple tabs all subscribe to <code class="demo-inline">/ws/store-demo</code>. Each bump POSTs to <code class="demo-inline">/api/learn/demo/store-bump</code>, which calls <code class="demo-inline">Store::incr()</code> and broadcasts the new value over the WebSocket to every connected client. <strong>This is what <code class="demo-inline">/learn/websocket</code> uses for its real-time-sync section.</strong></p>'
            ],
        ],
        'learn/store', 'Sharing State'
    );
});

$app->route('/demo/view/counter/increment', ['methods' => ['GET']], function() {
    // Interactive cross-tab demo. Reuses the existing /ws/counter-demo
    // endpoint that powers the /learn/websocket inline counter — so this
    // popup-friendly viewer is a standalone mirror of that widget.
    return demo_render(
        'Counter: lock-free atomic int',
        '<code class="demo-inline">$counter-&gt;increment()</code> wraps <code class="demo-inline">OpenSwoole\\Atomic</code> &mdash; lock-free, cross-worker, no syscall per bump. Click <strong>+1</strong>. Open this URL in another window/tab and click +1 there: every tab updates live via a WebSocket broadcast. Reset zeros the counter for everyone.',
        [
            ['heading' => 'Live counter (shared across tabs)', 'body' =>
                '<div class="ws-counter-card">' .
                '  <div class="ws-counter-value" data-ws-counter-value>0</div>' .
                '  <p class="ws-counter-label">value lives in shared memory; every worker + every tab sees the same number</p>' .
                '  <div class="ws-counter-actions">' .
                '    <button type="button" class="btn btn-primary" data-ws-counter="bump">+1</button>' .
                '    <button type="button" class="btn btn-ghost" data-ws-counter="reset">Reset</button>' .
                '  </div>' .
                '  <div data-ws-counter-status class="ws-counter-status">connecting…</div>' .
                '</div>'
            ],
            ['heading' => 'How it works', 'body' =>
                '<pre class="demo-payload">// route/learn.php — boot
$wsCounterDemo = new Counter(0);
Store::make(\'ws_counter_demo_clients\', 4096, [...]);

// WebSocket endpoint — track open fds
$app-&gt;ws(\'/ws/counter-demo\',
    onOpen: function ($server, $request) use ($wsCounterDemo) {
        Store::set(\'ws_counter_demo_clients\', (string)$request-&gt;fd, [...]);
        $server-&gt;push($request-&gt;fd, json_encode([\'value\' =&gt; $wsCounterDemo-&gt;get()]));
    },
    /* ... onClose, onMessage ... */
);

// Bump endpoint — increment + broadcast to every connected fd
$app-&gt;route(\'/api/learn/demo/counter-bump\', [\'methods\' =&gt; [\'POST\']], function () use ($wsCounterDemo) {
    $new = $wsCounterDemo-&gt;increment();
    ws_counter_demo_broadcast((int)$new);
    return [\'value\' =&gt; (int)$new];
});</pre>'],
        ],
        'learn/store', 'Sharing State'
    );
});

// Middleware viewers --------------------------------------------------------

$app->route('/demo/view/middleware/cors', ['methods' => ['GET']], function($request) {
    $origin = $request->header['origin'] ?? '(none)';
    return demo_render(
        'Middleware: CorsMiddleware',
        'The <code class="demo-inline">CorsMiddleware</code> registered in <code class="demo-inline">app.php</code> adds <code class="demo-inline">Access-Control-Allow-Origin</code> + related headers to every response. It also intercepts OPTIONS preflight requests automatically — try <code class="demo-inline">curl -X OPTIONS http://host/anything</code>.',
        [
            ['heading' => 'Origin you sent', 'body' => '<code class="demo-inline">' . htmlspecialchars($origin) . '</code>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Access-Control-Allow-Origin</dt><dd><code class="demo-inline">*</code> (or whatever <code class="demo-inline">ZEALPHP_CORS_ORIGINS</code> is set to)</dd><dt>Vary</dt><dd><code class="demo-inline">Origin</code></dd></dl>'],
        ],
        'learn/middleware', 'Middleware: The Wrap'
    );
});

$app->route('/demo/view/middleware/etag', ['methods' => ['GET']], function() {
    $body = str_repeat('ZealPHP', 50);
    $etag = 'W/"' . md5($body) . '"';
    return demo_render(
        'Middleware: ETagMiddleware',
        'The <code class="demo-inline">ETagMiddleware</code> generates a weak ETag from the response body. Re-request this URL with <code class="demo-inline">If-None-Match: ' . $etag . '</code> and you\'ll get a <code class="demo-inline">304 Not Modified</code> — body skipped, bandwidth saved.',
        [
            ['heading' => 'Live URL', 'body' => '<code class="demo-inline">GET /demo/middleware/etag</code>'],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>ETag</dt><dd><code class="demo-inline">' . htmlspecialchars($etag) . '</code></dd></dl><p style="margin:.85rem 0 0;color:#57534e;font-size:.85rem">Try in DevTools → Network: reload, then reload again — second request returns 304.</p>'],
        ],
        'learn/middleware', 'Middleware: The Wrap'
    );
});

$app->route('/demo/view/middleware/compress', ['methods' => ['GET']], function($request) {
    $accepts = $request->header['accept-encoding'] ?? '';
    $hasGzip = stripos($accepts, 'gzip') !== false;
    return demo_render(
        'Middleware: gzip compression',
        'OpenSwoole\'s built-in <code class="demo-inline">http_compression</code> is enabled by default in <code class="demo-inline">App::run()</code>. The response below is ~2.7&nbsp;KB raw but is sent as <code class="demo-inline">Content-Encoding: gzip</code> when the client advertises it.',
        [
            ['heading' => 'Your Accept-Encoding', 'body' => '<code class="demo-inline">' . htmlspecialchars($accepts) . '</code> ' . ($hasGzip ? '✓ gzip supported' : '✗ no gzip in Accept-Encoding') . ''],
            ['heading' => 'Response', 'body' => '<dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Content-Encoding</dt><dd><code class="demo-inline">' . ($hasGzip ? 'gzip' : 'identity') . '</code> (depends on your Accept-Encoding)</dd><dt>Raw body size</dt><dd>≈ 2.7 KB</dd></dl>'],
        ],
        'learn/middleware', 'Middleware: The Wrap'
    );
});

// Streaming viewers --------------------------------------------------------
// User: "add ssr sse demo streaming shells without linking the api directly."
// These wrap the raw /stream/* endpoints in the themed shell with buttons
// that drive the stream from the page (no raw chunks in the address bar).

$app->route('/demo/view/streaming/ssr', ['methods' => ['GET']], function() {
    return demo_render(
        'Streaming: Generator yield (SSR)',
        'A route handler that returns a <code class="demo-inline">\\Generator</code> streams every <code class="demo-inline">yield</code> chunk to the browser the moment it produces it &mdash; the page renders progressively instead of waiting for the whole HTML to assemble. Click <strong>Run</strong> below; the chunks arrive in the dark panel.',
        [
            ['heading' => 'How it\'s wired (route side)', 'body' => '<pre class="demo-payload">$app->route(\'/stream/ssr\', function () {
    return (function () {
        yield \'&lt;section&gt;&lt;h2&gt;Loading…&lt;/h2&gt;&lt;/section&gt;\';
        foreach (slow_data_source() as $row) {
            yield "&lt;article&gt;{$row}&lt;/article&gt;";
        }
        yield \'&lt;footer&gt;Done.&lt;/footer&gt;\';
    })();
});</pre>'],
            ['heading' => 'Run it', 'body' => '<button class="demo-action-btn" type="button" data-viewer-action="ssr" data-demo-url="/stream/ssr" data-target="demo-ssr-out">▶ Run Generator SSR</button><div id="demo-ssr-out" class="demo-live-output">Click <em>Run</em> to start the stream…</div>'],
        ],
        'learn/streaming', 'Streaming Done Right'
    );
});

$app->route('/demo/view/streaming/stream', ['methods' => ['GET']], function() {
    return demo_render(
        'Streaming: $response->stream()',
        'The <code class="demo-inline">$response->stream($fn)</code> primitive gives you fine-grained control: <code class="demo-inline">$fn</code> receives a <code class="demo-inline">$write(string)</code> closure that flushes chunks the moment you call it. Useful when you want to decide what to send <em>at run time</em> instead of yielding a fixed sequence.',
        [
            ['heading' => 'How it\'s wired (route side)', 'body' => '<pre class="demo-payload">$app->route(\'/stream/words\', function ($response) {
    return $response->stream(function ($write) {
        foreach (explode(\' \', \'streamed word by word from the server\') as $w) {
            $write($w . \' \');
            co::sleep(0.15);  // a real handler waits on real I/O instead
        }
    });
});</pre>'],
            ['heading' => 'Run it', 'body' => '<button class="demo-action-btn" type="button" data-viewer-action="stream" data-demo-url="/stream/words" data-target="demo-stream-out">▶ Run stream() demo</button><div id="demo-stream-out" class="demo-live-output">Click <em>Run</em>. Each word arrives as a separate chunk.</div>'],
        ],
        'learn/streaming', 'Streaming Done Right'
    );
});

$app->route('/demo/view/streaming/sse', ['methods' => ['GET']], function() {
    return demo_render(
        'Streaming: Server-Sent Events',
        'SSE is one-way push over plain HTTP &mdash; the browser opens an <code class="demo-inline">EventSource</code>, the server keeps the response open and emits named events. <code class="demo-inline">$response->sse($fn)</code> sets the right headers and gives you an <code class="demo-inline">$emit($data, $event, $id)</code> callback. Below: <strong>Connect</strong> opens an <code class="demo-inline">EventSource</code> to <code class="demo-inline">/stream/events</code>; the event log fills with one tick per second.',
        [
            ['heading' => 'How it\'s wired (route side)', 'body' => '<pre class="demo-payload">$app->route(\'/stream/events\', function ($response) {
    $response->sse(function ($emit) {
        $emit(json_encode([\'status\' =&gt; \'connected\']), \'open\');
        for ($i = 1; $i &lt;= 10; $i++) {
            co::sleep(1);
            $emit(json_encode([\'tick\' =&gt; $i]), \'tick\', (string)$i);
        }
        $emit(json_encode([\'done\' =&gt; true]), \'done\');
    });
});</pre>'],
            ['heading' => 'Connect', 'body' => '<button class="demo-action-btn" type="button" data-viewer-action="sse-start" data-demo-url="/stream/events" data-target="demo-sse-out">▶ Connect EventSource</button> <button class="demo-action-btn ghost" type="button" data-viewer-action="sse-stop">■ Disconnect</button><div id="demo-sse-out" class="demo-live-output">Click <em>Connect</em>. One event per second, 10 ticks then auto-closes.</div>'],
        ],
        'learn/streaming', 'Streaming Done Right'
    );
});

// Build-the-App widget viewers ------------------------------------------------
// Each of these pops a Build-the-App lesson's interactive widget into its
// own /demo/view/* shell — same partial as inlined in the lesson, but
// hosted in the slim breadcrumb shell so users can side-by-side a clean
// surface against the lesson tab (for cross-tab WS sync testing).

$app->route('/demo/view/notes/widget', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    if (!$u) {
        return demo_render(
            'Personal Notes',
            'Log in below to use the standalone notes widget. You\'ll stay on this page after sign-in.',
            [['heading' => '',
              'body'    => App::renderToString('/components/_demo_login_card', [
                  'intro' => 'This demo needs an account. Sign in or create one — no redirects, the widget loads in place.',
              ])]],
            'learn/notes', 'Personal Notes'
        );
    }
    return demo_render(
        'Personal Notes — standalone',
        'Same <code class="demo-inline">_notes_widget</code> partial that renders inline in the <a href="/learn/notes">Personal Notes lesson</a> — here in its own shell so you can open multiple windows for cross-tab WebSocket sync testing.',
        [
            ['heading' => 'Live widget',
             'body'    => App::renderToString('/components/_notes_widget', ['user' => $u])],
        ],
        'learn/notes', 'Personal Notes'
    );
});

$app->route('/demo/view/chat/widget', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    if (!$u) {
        return demo_render(
            'AI Chat',
            'Log in below to use the standalone chat widget.',
            [['heading' => '',
              'body'    => App::renderToString('/components/_demo_login_card', [
                  'intro' => 'The chat needs an account so it can read and modify your notes. Sign in or create one — the chat loads in place.',
              ])]],
            'learn/ai-chat', 'AI Chat'
        );
    }
    return demo_render(
        'AI Chat — standalone',
        'Same <code class="demo-inline">_chat_widget</code> partial that renders inline in the <a href="/learn/ai-chat">AI Chat lesson</a>. Try a prompt like <em>"create a note titled shopping list"</em> and watch the Event Log below the chat for live <span class="proto-badge sse">SSE</span> + <span class="proto-badge ws">WS</span> events.',
        [
            ['heading' => 'Live widget',
             'body'    => App::renderToString('/components/_chat_widget', ['user' => $u])],
        ],
        'learn/ai-chat', 'AI Chat'
    );
});

$app->route('/demo/view/websocket/counter', ['methods' => ['GET']], function () {
    // Open this URL in two windows: click +1 in either, the other updates
    // live. No auth — every connected tab on every account sees the same
    // global value (it's a teaching demo for the broadcast pattern).
    return demo_render(
        'WebSocket cross-tab counter',
        'Click <strong>+1</strong>. Open this URL in a second window — both update live via a WebSocket broadcast to every connected client. <a href="/learn/websocket">Read the build</a>.',
        [
            ['heading' => '',
             'body'    => App::renderToString('/components/_ws_counter_widget', ['as_demo' => true])],
        ],
        'learn/websocket', 'Real-Time Sync'
    );
});

$app->route('/demo/view/tictactoe/play', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    if (!$u) {
        return demo_render(
            'Multiplayer Tic-Tac-Toe',
            'Sign in to play. Display name = your username so opponents see who they\'re playing.',
            [['heading' => '',
              'body'    => App::renderToString('/components/_demo_login_card', [
                  'intro' => 'Tic-tac-toe needs an account so other players see your name. Sign in or create one — the game loads in place.',
              ])]],
            'learn/tictactoe', 'Tic-Tac-Toe'
        );
    }
    // Use a focused full-board layout — short description, no section heading.
    // The widget is the page; everything else is breadcrumb + 1-line context.
    return demo_render(
        'Tic-Tac-Toe',
        'Same room ID = same game. First two players take X and O; rest are viewers. <code class="demo-inline">?view=1</code> forces viewer. <a href="/learn/tictactoe">Read the build</a>.',
        [
            ['heading' => '',  // empty heading; demo-shell-h:empty hides it via CSS
             'body'    => App::renderToString('/components/_tictactoe_widget', ['user' => $u])],
        ],
        'learn/tictactoe', 'Tic-Tac-Toe'
    );
});
