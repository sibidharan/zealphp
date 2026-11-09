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
 *   /demo/inject/aliases/{id}       — $req / $res short aliases for $request / $response
 *   /demo/inject/req-segment/{req}  — #240: reserved {req} binds the Request wrapper, NOT the URL segment
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
use ZealPHP\Site\DemoHelpers;

$app = App::instance();

// Shared demo counter (created in route file scope = before start())
static $demoCounter = null;
if ($demoCounter === null) {
    $demoCounter = new Counter(0);
}

// Shared demo store
Store::make('demo_store', 128, [
    'name'  => [Store::TYPE_STRING, 64],
    'score' => [Store::TYPE_INT,    8],
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

// $req / $res are accepted as short aliases for $request / $response — they
// receive the exact same wrapper instances as the long names.
$app->route('/demo/inject/aliases/{id}', ['methods' => ['GET']], function($id, $req, $res) {
    $res->header('X-Alias-Inject', 'yes');
    return [
        'id'             => $id,
        'method'         => $req->server['request_method'] ?? 'GET',
        'request_class'  => get_class($req),
        'response_class' => get_class($res),
        'echo'           => $req->get['echo'] ?? null,
        'injected'       => ['id', 'req', 'res'],
        'note'           => '$req / $res are short aliases for $request / $response',
    ];
});

// Security (#240): a {req} URL segment CANNOT shadow the injected Request
// wrapper. The reserved framework-object names (request/req/response/res/app)
// bind the object FIRST, so a handler typed function($req) always gets the
// wrapper — never an attacker-controlled path segment. To read the segment,
// name the parameter something else.
$app->route('/demo/inject/req-segment/{req}', ['methods' => ['GET']], function($req) {
    return [
        'is_request_wrapper' => $req instanceof \ZealPHP\HTTP\Request,
        'is_string'          => is_string($req),
        'note'               => '#240: reserved {req} binds the Request wrapper, not the URL segment',
    ];
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

// Backend-agnostic roundtrip: exercises the full Store API through whichever
// backend is configured (env var ZEALPHP_STORE_BACKEND or App::run). Used by
// tests/Integration/StoreBackendIntegrationTest.php to prove the same
// call shapes work end-to-end whether storage is local Table or remote Redis.
$app->route('/demo/store-roundtrip', ['methods' => ['GET']], function() {
    $key = 'rt_' . bin2hex(random_bytes(4));
    Store::set('demo_store', $key, ['name' => $key, 'score' => 42]);
    $afterSet = Store::get('demo_store', $key);
    Store::incr('demo_store', $key, 'score', 8);
    $afterIncr = Store::get('demo_store', $key);
    $existed  = Store::exists('demo_store', $key);
    Store::del('demo_store', $key);
    $afterDel = Store::get('demo_store', $key);
    return [
        'backend'    => get_class(Store::defaultBackend()),
        'set'        => $afterSet,
        'incr'       => $afterIncr,
        'existed'    => $existed,
        'after_del'  => $afterDel,
        'worker_pid' => getmypid(),
    ];
});

// ---------------------------------------------------------------------------
// Pub/sub demo — only meaningful with ZEALPHP_STORE_BACKEND=redis (Store::publish
// throws StoreException on the Table backend). The handler writes received
// messages into a tiny in-memory log (a Store table) the smoke test reads.
// ---------------------------------------------------------------------------
if (Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend) {
    Store::make('demo_pubsub_log', 256, [
        'channel' => [Store::TYPE_STRING, 64],
        'payload' => [Store::TYPE_STRING, 256],
        'pattern' => [Store::TYPE_STRING, 64],
        'ts'      => [Store::TYPE_INT,    8],
    ]);

    App::subscribe('demo:pubsub', function (string $payload, string $channel, ?string $pattern): void {
        $id = 'm_' . bin2hex(random_bytes(4));
        Store::set('demo_pubsub_log', $id, [
            'channel' => $channel, 'payload' => $payload,
            'pattern' => $pattern ?? '', 'ts' => time(),
        ]);
    });
    App::subscribe('demo:pubsub:*', function (string $payload, string $channel, ?string $pattern): void {
        $id = 'm_' . bin2hex(random_bytes(4));
        Store::set('demo_pubsub_log', $id, [
            'channel' => $channel, 'payload' => $payload,
            'pattern' => $pattern ?? '', 'ts' => time(),
        ]);
    });

    // Reliable variant — drives the Streams runner (XREADGROUP loop).
    // Handler returns true → XACK (message removed from pending).
    App::subscribeReliable('demo:reliable', function (string $payload, string $id, string $stream, array $fields): bool {
        $rowKey = 'r_' . bin2hex(random_bytes(4));
        Store::set('demo_pubsub_log', $rowKey, [
            'channel' => 'stream:' . $stream,
            'payload' => $payload,
            'pattern' => $id,
            'ts'      => time(),
        ]);
        return true;
    });
}

// Publishing is off unless asked for, and then limited to demo: channels and
// streams: on a shared Redis an open publish endpoint could post into the
// framework's own channels (WebSocket routing, component invalidation).
if (\ZealPHP\env_flag('ZEALPHP_REDIS_DEMOS', false)) {
    $app->route('/demo/pubsub/publish', ['methods' => ['GET']], function (\ZealPHP\HTTP\Request $request) {
        $q = $request->get ?? [];
        $channel = is_string($q['channel'] ?? null) ? $q['channel'] : 'demo:pubsub';
        $msg     = is_string($q['msg']     ?? null) ? $q['msg']     : 'hello';
        if (!str_starts_with($channel, 'demo:')) {
            return 400;
        }
        try {
            $receivers = Store::publish($channel, $msg);
            return ['ok' => true, 'channel' => $channel, 'msg' => $msg, 'receivers' => $receivers];
        } catch (\ZealPHP\Store\StoreException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    });

    $app->route('/demo/pubsub/publish-reliable', ['methods' => ['GET']], function (\ZealPHP\HTTP\Request $request) {
        $q = $request->get ?? [];
        $stream = is_string($q['stream'] ?? null) ? $q['stream'] : 'demo:reliable';
        $msg    = is_string($q['msg']    ?? null) ? $q['msg']    : 'durable';
        if (!str_starts_with($stream, 'demo:')) {
            return 400;
        }
        try {
            $id = Store::publishReliable($stream, $msg);
            return ['ok' => true, 'stream' => $stream, 'msg' => $msg, 'message_id' => $id];
        } catch (\ZealPHP\Store\StoreException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    });
}

$app->route('/demo/pubsub/log', ['methods' => ['GET']], function () {
    if (!(Store::defaultBackend() instanceof \ZealPHP\Store\RedisBackend)) {
        return ['ok' => false, 'error' => 'demo:pubsub log is only populated when ZEALPHP_STORE_BACKEND=redis'];
    }
    $rows = [];
    foreach (Store::iterate('demo_pubsub_log') as $key => $row) {
        $rows[] = ['id' => $key] + $row;
    }
    usort($rows, fn(array $a, array $b): int => ((int) $a['ts']) <=> ((int) $b['ts']));
    return ['ok' => true, 'count' => count($rows), 'log' => $rows];
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
        return App::render('/demos/contacts-list', ['contacts' => $contacts], fragment: $fragment);
    }

    // Full page render — wrap in the standard _demo_shell so the page has
    // the same breadcrumb + theme as every other /demo/view/ page on the
    // site, with a back-link to the htmx lesson where this is taught.
    return DemoHelpers::demo_render('Template fragments — contacts list',
        App::component('/demos/fragments/contacts', ['contacts' => $contacts]), 'learn/htmx', 'Forms & htmx');
});

// ---------------------------------------------------------------------------
// Demo viewers — open in a new tab from lesson "Try it live" links.
// Each viewer wraps a view from template/demos/view/ (the description and
// demo sections) in the standalone shell (template/components/_demo_shell.php)
// with a back-link to the lesson. The raw /demo/<...> endpoints above stay
// JSON for tests and direct API use; the viewers below are the
// human-friendly versions. The handlers only gather data — the markup is in
// the views.
// ---------------------------------------------------------------------------

// Inject viewers ------------------------------------------------------------

$app->route('/demo/view/inject/url/{id}', ['methods' => ['GET']], function($id) {
    return DemoHelpers::demo_render('Inject: URL param only', App::component('/demos/view/inject/url', [
        'id'      => $id,
        'payload' => json_encode(['id' => $id, 'injected' => ['id'], 'note' => 'URL param only']),
    ]), 'learn/injection', 'Parameter Injection');
});

$app->route('/demo/view/inject/request-only', ['methods' => ['GET']], function($request) {
    $payload = ['method' => $request->server['REQUEST_METHOD'] ?? 'GET', 'uri' => $request->server['REQUEST_URI'] ?? '/', 'host' => $request->header['host'] ?? '', 'injected' => ['request']];
    return DemoHelpers::demo_render('Inject: $request only', App::component('/demos/view/inject/request-only', [
        'payload' => json_encode($payload),
    ]), 'learn/injection', 'Parameter Injection');
});

$app->route('/demo/view/inject/url-response/{id}', ['methods' => ['GET']], function($id, $response) {
    $response->header('X-Demo-Injected', 'id+response');
    return DemoHelpers::demo_render('Inject: URL param + $response', App::component('/demos/view/inject/url-response', [
        'id'      => $id,
        'payload' => json_encode(['id' => $id, 'injected' => ['id', 'response'], 'header_set' => 'X-Demo-Injected: id+response']),
    ]), 'learn/injection', 'Parameter Injection');
});

$app->route('/demo/view/inject/all/{id}', ['methods' => ['GET']], function($id, $request, $response) {
    $response->header('X-Demo-Injected', 'all');
    $payload = ['id' => $id, 'method' => $request->server['REQUEST_METHOD'] ?? 'GET', 'query' => $request->get ?? [], 'injected' => ['id', 'request', 'response']];
    return DemoHelpers::demo_render('Inject: $id + $request + $response', App::component('/demos/view/inject/all', [
        'id'      => $id,
        'payload' => json_encode($payload),
    ]), 'learn/injection', 'Parameter Injection');
});

$app->route('/demo/view/inject/defaults/{id}', ['methods' => ['GET']], function($id, $page = 1) {
    return DemoHelpers::demo_render('Inject: default parameter values', App::component('/demos/view/inject/defaults', [
        'id'      => $id,
        'payload' => json_encode(['id' => $id, 'page' => $page, 'note' => '$page used default value 1 because no override was passed']),
    ]), 'learn/injection', 'Parameter Injection');
});

// Response viewers ----------------------------------------------------------

$app->route('/demo/view/response/json', ['methods' => ['GET']], function() {
    $payload = ['framework' => 'ZealPHP', 'async' => true, 'engine' => 'OpenSwoole', 'time' => time()];
    return DemoHelpers::demo_render('Response: return an array → JSON', App::component('/demos/view/response/json', [
        'payload' => json_encode($payload),
    ]), 'learn/responses', 'Returning a Response');
});

$app->route('/demo/view/response/redirect-302', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Response: 302 redirect', App::component('/demos/view/response/redirect-302'),
        'learn/responses', 'Returning a Response');
});

$app->route('/demo/view/response/redirect-301', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Response: 301 permanent redirect', App::component('/demos/view/response/redirect-301'),
        'learn/responses', 'Returning a Response');
});

$app->route('/demo/view/response/headers', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Response: custom headers', App::component('/demos/view/response/headers', [
        'now' => time(),
    ]), 'learn/responses', 'Returning a Response');
});

$app->route('/demo/view/response/cookie', ['methods' => ['GET']], function($response) {
    $now = time();
    $response->cookie('zealphp_demo_cookie', 'set-at-' . $now, $now + 3600, '/', '', false, false, 'Lax');
    return DemoHelpers::demo_render('Response: $response->cookie()', App::component('/demos/view/response/cookie', [
        'now' => $now,
    ]), 'learn/responses', 'Returning a Response');
});

// Store + Counter viewers --------------------------------------------------

$app->route('/demo/view/store/set-get', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Store: write → read across workers', App::component('/demos/view/store/set-get'),
        'learn/store', 'Sharing State');
});

$app->route('/demo/view/store/incr', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Store: atomic increment', App::component('/demos/view/store/incr'),
        'learn/store', 'Sharing State');
});

$app->route('/demo/view/counter/increment', ['methods' => ['GET']], function() {
    // Interactive cross-tab demo. Reuses the existing /ws/counter-demo
    // endpoint that powers the /learn/websocket inline counter — so this
    // popup-friendly viewer is a standalone mirror of that widget.
    return DemoHelpers::demo_render('Counter: lock-free atomic int', App::component('/demos/view/counter/increment'),
        'learn/store', 'Sharing State');
});

// Middleware viewers --------------------------------------------------------

$app->route('/demo/view/middleware/cors', ['methods' => ['GET']], function($request) {
    return DemoHelpers::demo_render('Middleware: CorsMiddleware', App::component('/demos/view/middleware/cors', [
        'origin' => $request->header['origin'] ?? '(none)',
    ]), 'learn/middleware', 'Middleware: The Wrap');
});

$app->route('/demo/view/middleware/etag', ['methods' => ['GET']], function() {
    $body = str_repeat('ZealPHP', 50);
    return DemoHelpers::demo_render('Middleware: ETagMiddleware', App::component('/demos/view/middleware/etag', [
        'etag' => 'W/"' . md5($body) . '"',
    ]), 'learn/middleware', 'Middleware: The Wrap');
});

$app->route('/demo/view/middleware/compress', ['methods' => ['GET']], function($request) {
    $accepts = $request->header['accept-encoding'] ?? '';
    return DemoHelpers::demo_render('Middleware: gzip compression', App::component('/demos/view/middleware/compress', [
        'accepts' => $accepts,
        'gzip'    => stripos($accepts, 'gzip') !== false,
    ]), 'learn/middleware', 'Middleware: The Wrap');
});

// Streaming viewers --------------------------------------------------------
// User: "add ssr sse demo streaming shells without linking the api directly."
// These wrap the raw /stream/* endpoints in the themed shell with buttons
// that drive the stream from the page (no raw chunks in the address bar).

$app->route('/demo/view/streaming/ssr', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Streaming: Generator yield (SSR)', App::component('/demos/view/streaming/ssr'),
        'learn/streaming', 'Streaming Done Right');
});

$app->route('/demo/view/streaming/stream', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Streaming: $response->stream()', App::component('/demos/view/streaming/stream'),
        'learn/streaming', 'Streaming Done Right');
});

$app->route('/demo/view/streaming/sse', ['methods' => ['GET']], function() {
    return DemoHelpers::demo_render('Streaming: Server-Sent Events', App::component('/demos/view/streaming/sse'),
        'learn/streaming', 'Streaming Done Right');
});

// Build-the-App widget viewers ------------------------------------------------
// Each of these pops a Build-the-App lesson's interactive widget into its
// own /demo/view/* shell — same component as inlined in the lesson, but
// hosted in the slim breadcrumb shell so users can side-by-side a clean
// surface against the lesson tab (for cross-tab WS sync testing). Signed
// out, the view shows the login card instead.

$app->route('/demo/view/notes/widget', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    return DemoHelpers::demo_render($u ? 'Personal Notes — standalone' : 'Personal Notes',
        App::component('/demos/view/notes/widget', ['user' => $u]), 'learn/notes', 'Personal Notes');
});

$app->route('/demo/view/chat/widget', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    return DemoHelpers::demo_render($u ? 'AI Chat — standalone' : 'AI Chat',
        App::component('/demos/view/chat/widget', ['user' => $u]), 'learn/ai-chat', 'AI Chat');
});

$app->route('/demo/view/websocket/counter', ['methods' => ['GET']], function () {
    // Open this URL in two windows: click +1 in either, the other updates
    // live. No auth — every connected tab on every account sees the same
    // global value (it's a teaching demo for the broadcast pattern).
    return DemoHelpers::demo_render('WebSocket cross-tab counter', App::component('/demos/view/websocket/counter'),
        'learn/websocket', 'Real-Time Sync');
});

$app->route('/demo/view/tictactoe/play', ['methods' => ['GET']], function () {
    // The widget is the page: a focused full-board layout, one-line context.
    $u = \ZealPHP\Learn\Auth::currentUser();
    return DemoHelpers::demo_render($u ? 'Tic-Tac-Toe' : 'Multiplayer Tic-Tac-Toe',
        App::component('/demos/view/tictactoe/play', ['user' => $u]), 'learn/tictactoe', 'Tic-Tac-Toe');
});

$app->route('/demo/view/chatroom/widget', ['methods' => ['GET']], function () {
    $u = \ZealPHP\Learn\Auth::currentUser();
    return DemoHelpers::demo_render('Multi-Room Group Chat',
        App::component('/demos/view/chatroom/widget', ['user' => $u]), 'learn/chatroom', 'Multi-Room Group Chat');
});

// ── #338 — fatal→500 guard test endpoint ────────────────────────────────
// Deliberately KILLS the worker with an uncatchable E_COMPILE_ERROR
// (incompatible interface implementation, unique class names per request so
// the inheritance-link fatal — not a redeclare — fires every time). The
// fatalResponseGuard must answer this connection with HTTP 500 instead of
// leaving the client to time out (mod_php parity). Gated off by default:
// only enabled when the server is started with ZEALPHP_FATAL_TEST=1, so the
// public demo site never exposes a crash-the-worker endpoint.
$app->route('/demo/fatal500', function () {
    if (getenv('ZEALPHP_FATAL_TEST') !== '1') {
        return 403;
    }
    $n = 'F338_' . str_replace('.', '_', uniqid('', true));
    eval("interface I{$n} { public function x(): array; }"
        . "class C{$n} implements I{$n} { public function x(): string { return ''; } }");
    return ['unreachable' => true]; // the eval above is a worker-killing fatal
});
