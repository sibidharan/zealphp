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
        'method' => $request->server['REQUEST_METHOD'] ?? 'GET',
        'uri'    => $request->server['REQUEST_URI']    ?? '',
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
        'method'   => $request->server['REQUEST_METHOD'] ?? 'GET',
        'uri'      => $request->server['REQUEST_URI']    ?? '',
        'injected' => ['request'],
    ];
});

$app->route('/demo/inject/all/{id}', ['methods' => ['GET']], function($id, $request, $response) {
    $response->header('X-Full-Inject', 'yes');
    return [
        'id'             => $id,
        'method'         => $request->server['REQUEST_METHOD'] ?? 'GET',
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
