<?php
/**
 * Fixture for MixedModeRequestScopeTest — an App::mode('mixed') server.
 *
 * mixed mode runs RequestContext as a PROCESS-WIDE singleton (no coroutine
 * per request), so anything the framework documents as request-scoped must be
 * reset by hand between requests. Usage: php mixed_mode_request_scope_server.php <port>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

$port = (int) ($argv[1] ?? 9835);

App::mode(App::MODE_MIXED);
$app = App::init('127.0.0.1', $port);

// RequestContext::once() — the documented request-scoped memo. A per-request
// "current user" memoised here must never be served to the next request.
$app->route('/whoami', function () {
    $u = (string) ($_GET['u'] ?? 'anon');
    return ['asked' => $u, 'user' => RequestContext::once('current_user', fn () => $u)];
});

$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
