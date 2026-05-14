<?php
/**
 * Error-handling integration test fixtures.
 *
 * Registers custom error handlers (Apache ErrorDocument equivalent) plus
 * routes under /__error_test/* that trigger errors deterministically.
 * Used by tests/Integration/ErrorHandlingTest.php, ErrorHandlersIsolationTest.php,
 * and ContentNegotiationTest.php.
 */

use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;

$app = App::instance();

// ─── Scope A: ErrorDocument handlers ───────────────────────────────────────
//
// Scoped to /__error_test/* URLs to avoid polluting the demo site's normal
// error pages. The default framework body is returned for any other URL.

$isTestUri = static function (): bool {
    $uri = G::instance()->server['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';
    return str_starts_with($path, '/__error_test/');
};

$app->setErrorHandler(404, function($status) use ($isTestUri) {
    if (!$isTestUri()) {
        // Fall through to framework default (HTML).
        http_response_code(404);
        echo "<pre>404 Not Found</pre>";
        return;
    }
    $path = parse_url(G::instance()->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '';
    // Sub-dispatch by URI to avoid mutating the global handler from request routes,
    // which would leak between integration tests.
    if (str_ends_with($path, '/html-handler-wins')) {
        return '<custom-html-404>';
    }
    return "CUSTOM-404-BODY status={$status}";
});

$app->setErrorHandler(500, function($exception) use ($isTestUri) {
    if (!$isTestUri()) {
        http_response_code(500);
        echo "<pre>500 Internal Server Error</pre>";
        return;
    }
    $msg = $exception ? $exception->getMessage() : 'no-exception';
    return "CUSTOM-500-BODY msg={$msg}";
});

$app->setErrorHandler(403, function() use ($isTestUri) {
    if (!$isTestUri()) {
        http_response_code(403);
        echo "<pre>403 Forbidden</pre>";
        return;
    }
    http_response_code(403);
    echo "CUSTOM-403-BODY";
});

$app->setErrorHandler(400, function() use ($isTestUri) {
    if (!$isTestUri()) {
        http_response_code(400);
        echo "<pre>400 Bad Request</pre>";
        return;
    }
    return "CUSTOM-400-BODY";
});

$app->setErrorHandler(418, function($status) use ($isTestUri) {
    if (!$isTestUri()) {
        return "<pre>418 I'm a teapot</pre>";
    }
    return ['error' => $status, 'catch_all_special' => 'teapot'];
});

// ─── Routes that trigger errors deterministically ──────────────────────────

$app->route('/__error_test/throw-not-found', function() {
    return 404;
});

$app->route('/__error_test/throw-exception', function() {
    throw new \RuntimeException('boom-message');
});

// Returns an array via the catch-all path: registered 418 handler emits JSON.
$app->route('/__error_test/teapot', function() {
    return 418;
});

// Handler-throws-inside-handler — verifies the renderError recursion guard.
// Uses status 502 (Bad Gateway) so the global 500 handler stays untouched.
$app->route('/__error_test/handler-self-throws', function() {
    App::instance()->setErrorHandler(502, function() {
        throw new \RuntimeException('handler-itself-threw');
    });
    http_response_code(502);
    return 502;
});

// Returns an array as 410 — verifies handler can return JSON-shaped body.
// Uses 410 (Gone) instead of 404 so we don't poison the global 404 handler
// for later tests that exercise the default test 404 handler.
$app->route('/__error_test/array-via-410', function() {
    App::instance()->setErrorHandler(410, function($status) {
        return ['error_status' => $status, 'shape' => 'array'];
    });
    return 410;
});

// Handler that returns a Generator (streaming). Uses 422 (Unprocessable Entity).
$app->route('/__error_test/generator-via-422', function() {
    App::instance()->setErrorHandler(422, function() {
        return (function() {
            yield 'A';
            yield 'B';
            yield 'C';
        })();
    });
    return 422;
});

// ─── Scope B: per-coroutine error/exception/shutdown handler tests ─────────

$app->route('/__error_test/handler-catches-warning', function() {
    $caught = false;
    set_error_handler(function() use (&$caught) {
        $caught = true;
        return true;
    });
    @trigger_error('test-warning', E_USER_WARNING);
    restore_error_handler();
    return ['caught' => $caught];
});

$app->route('/__error_test/restore-pops-back-to-previous', function() {
    $log = [];
    set_error_handler(function() use (&$log) { $log[] = 'A'; return true; });
    set_error_handler(function() use (&$log) { $log[] = 'B'; return true; });
    restore_error_handler();
    @trigger_error('after-restore', E_USER_WARNING);
    restore_error_handler();
    return ['log' => $log];
});

$app->route('/__error_test/restore-beyond-empty', function() {
    restore_error_handler(); // no-op on empty stack
    restore_error_handler();
    return ['ok' => true];
});

// Isolation test side A: set handler, sleep, return.
$app->route('/__error_test/slow-handler-set', function() {
    Store::set('error_test', 'iso', ['handler_fired' => 0, 'handler_cid' => 0, 'shutdown_count' => 0]);
    $cid = \OpenSwoole\Coroutine::getCid();
    set_error_handler(function() use ($cid) {
        Store::set('error_test', 'iso', ['handler_fired' => 1, 'handler_cid' => $cid, 'shutdown_count' => 0]);
        return true;
    });
    \OpenSwoole\Coroutine::sleep(0.5);
    restore_error_handler();
    return ['slow-done' => true];
});

// Isolation test side B: trigger a warning during A's sleep.
$app->route('/__error_test/fast-trigger', function() {
    @trigger_error('from-fast', E_USER_WARNING);
    $row = Store::get('error_test', 'iso') ?: ['handler_fired' => 0, 'handler_cid' => 0];
    return [
        'handler_fired' => (int)($row['handler_fired'] ?? 0),
        'handler_cid'   => (int)($row['handler_cid'] ?? 0),
    ];
});

$app->route('/__error_test/exception-handler-echo', function() {
    set_exception_handler(function(\Throwable $e) {
        echo "HANDLED:" . $e->getMessage();
    });
    throw new \RuntimeException('boom-exc');
});

$app->route('/__error_test/shutdown-echo', function() {
    register_shutdown_function(function() {
        echo "SHUTDOWN-RAN";
    });
    echo "HANDLER-RAN";
});

$app->route('/__error_test/shutdown-status', function() {
    register_shutdown_function(function() {
        http_response_code(503);
    });
    echo "STATUS-SHIFTED";
});

$app->route('/__error_test/shutdown-order', function() {
    register_shutdown_function(function() { echo "-ONE"; });
    register_shutdown_function(function() { echo "-TWO"; });
    register_shutdown_function(function() { echo "-THREE"; });
    echo "START";
});

$app->route('/__error_test/shutdown-throws', function() {
    register_shutdown_function(function() {
        throw new \RuntimeException('shutdown-explosion');
    });
    echo "OK";
});

$app->route('/__error_test/shutdown-counter', function() {
    register_shutdown_function(function() {
        Store::incr('error_test', 'sc', 'shutdown_count', 1);
    });
    return ['registered' => true];
});

$app->route('/__error_test/shutdown-counter-read', function() {
    $row = Store::get('error_test', 'sc');
    return ['count' => (int)($row['shutdown_count'] ?? 0)];
});

// ─── Scope C: error_reporting + content negotiation ────────────────────────

$app->route('/__error_test/error-reporting-set', function() {
    error_reporting(E_ERROR);
    \OpenSwoole\Coroutine::sleep(0.3);
    return ['level_in_slow' => error_reporting()];
});

$app->route('/__error_test/error-reporting-read', function() {
    return ['level' => error_reporting()];
});

$app->route('/__error_test/error-reporting-roundtrip', function() {
    $before = error_reporting();
    error_reporting(E_WARNING);
    $during = error_reporting();
    error_reporting($before);
    return ['before' => $before, 'during' => $during];
});

$app->route('/__error_test/suppressed-notice', function() {
    $caught = false;
    error_reporting(0);
    set_error_handler(function() use (&$caught) {
        $caught = true;
        return true;
    });
    @trigger_error('quiet', E_USER_NOTICE);
    restore_error_handler();
    return ['caught' => $caught];
});

// Custom 404 handler that always returns HTML — used to verify user choice
// wins over Accept-based negotiation. Sub-dispatch by URI happens inside the
// registered 404 handler so the global handler isn't mutated here.
$app->route('/__error_test/html-handler-wins', function() {
    return 404;
});
