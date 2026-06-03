<?php
/**
 * Per-route middleware — live demo for the ZealPHP OSS website.
 *
 * Demonstrates route-level middleware (Hyperf-style, ergonomic like Traefik):
 * named aliases, the `middleware:` route option, route groups, short-circuit,
 * and the `describeRoutes()` introspection that powers the middleware
 * visualizer.
 *
 * Endpoints:
 *   GET /demo/middleware/route-level   — route with ['request-id','demo-header']
 *   GET /demo/middleware/plain         — sibling route with NO middleware (scoping proof)
 *   GET /demo/middleware/blocked       — short-circuit: a guard returns 403, handler never runs
 *   GET /demo/mwgroup/alpha            — group with a shared header middleware
 *   GET /demo/mwgroup/beta             — same group, same shared middleware
 *   GET /demo/middleware/visualize     — describeRoutes() as JSON (the visualizer feed)
 *
 * App::when() — centralized PATH-scoped middleware (works for routes AND api):
 *   GET /demo/scoped/test              — non-api route under when('/demo/scoped')
 *   GET /api/secured/list              — when('/api/secured') stamps X-Api-Secured
 *   GET /api/secured/profile           — when scope + in-file $middleware (X-Request-Id)
 *   GET /api/open/list                 — sibling api namespace, NO when (scoping proof)
 *   GET /api/blocked/secret            — when('/api/blocked',['block']) → 403 short-circuit
 */

use ZealPHP\App;
use ZealPHP\Middleware\RequestIdMiddleware;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Middleware\ReturnMiddleware;

$app = App::instance();

// ── Named middleware aliases (Traefik's "named & shared", Laravel's aliases) ──
// Registered once at boot; resolved to instances at App::run(). Reused across
// every route that references them — they're stateless, so that's safe.
App::middlewareAlias('request-id',  fn() => new RequestIdMiddleware());
App::middlewareAlias('demo-header', fn() => new HeaderMiddleware(['set' => ['X-Demo-Route' => 'route-level']]));
App::middlewareAlias('group-header', fn() => new HeaderMiddleware(['set' => ['X-Demo-Group' => 'mwgroup']]));
App::middlewareAlias('block', fn() => new ReturnMiddleware(403, 'Blocked by route-level middleware'));

// ── A route WITH per-route middleware ──
// 'request-id' stamps X-Request-Id; 'demo-header' stamps X-Demo-Route. The
// handler can read the id back via the per-request memo.
$app->route('/demo/middleware/route-level',
    middleware: ['request-id', 'demo-header'],
    handler: function () {
        return [
            'ok'         => true,
            'route'      => '/demo/middleware/route-level',
            'middleware' => ['request-id', 'demo-header'],
            'request_id' => \ZealPHP\RequestContext::once('request_id', fn() => null),
            'note'       => 'Both X-Request-Id and X-Demo-Route headers are set by route middleware.',
        ];
    });

// ── A sibling route with NO middleware (proves middleware is route-scoped) ──
$app->route('/demo/middleware/plain', function () {
    return [
        'ok'         => true,
        'route'      => '/demo/middleware/plain',
        'middleware' => [],
        'note'       => 'No X-Demo-Route header here — middleware is per-route, not global.',
    ];
});

// ── Short-circuit: a guard middleware returns 403; the handler never runs ──
$app->route('/demo/middleware/blocked',
    middleware: ['block'],
    handler: function () {
        // Unreachable — the 'block' middleware short-circuits before this runs.
        return ['ok' => true, 'note' => 'You should never see this body.'];
    });

// ── A route group: shared prefix + shared middleware applied to many routes ──
$app->group('/demo/mwgroup', ['group-header'], function ($g) {
    $g->route('/alpha', fn() => ['ok' => true, 'group' => 'mwgroup', 'route' => 'alpha']);
    $g->route('/beta',  fn() => ['ok' => true, 'group' => 'mwgroup', 'route' => 'beta']);
});

// ── App::when() — centralized PATH-scoped middleware ──
// One mechanism for ALL routes, api included: api/secured/list.php is reached
// by the URL /api/secured/list, so scoping by path covers it with no api-only
// glue. Shorter prefix = outermost; chains compose in registration order.
App::middlewareAlias('api-secured', fn() => new HeaderMiddleware(['set' => ['X-Api-Secured' => '1']]));

App::when('/api/secured', ['api-secured']);  // every api/secured/* endpoint
App::when('/api/blocked', ['block']);        // a 403 guard over a whole api namespace
App::when('/demo/scoped', ['demo-header']);  // when() is not api-only — any URL path

// A plain (non-api) route under a when() scope — proves when wraps route dispatch.
$app->route('/demo/scoped/test', fn() => ['ok' => true, 'scope' => '/demo/scoped']);

// ── Visualizer feed: the routing + middleware topology as JSON ──
// Think Traefik's dashboard, but for your own in-process routes. The Middleware
// page (/middleware) renders this live.
$app->route('/demo/middleware/visualize', function () use ($app) {
    return $app->describeRoutes();
});
