<?php
/**
 * Fixture for CoroutineSessionSuperglobalTest — coroutine mode, one worker.
 * Usage: php coroutine_session_superglobal_server.php <port>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

App::mode(App::MODE_COROUTINE);
$app = App::init('127.0.0.1', (int) ($argv[1] ?? 9891));

$app->route('/write', function () {
    session_start();
    $_SESSION['who'] = (string) (RequestContext::instance()->get['v'] ?? '');
    return ['ok' => true];
});
$app->route('/read', function () {
    return ['sg' => $_SESSION ?? null];
});
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
