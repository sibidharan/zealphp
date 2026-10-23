<?php
/**
 * Fixture for WebSocketSessionStrictModeTest: a WebSocket route plus an HTTP
 * route, sessions in a private save path, booted with display_errors=1.
 * Usage: php -d display_errors=1 ws_session_strict_server.php <port> <save_path>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

App::sessionSavePath((string) ($argv[2] ?? sys_get_temp_dir()));
$app = App::init('127.0.0.1', (int) ($argv[1] ?? 9890));

$app->ws('/ws', function ($server, $frame) {
});
$app->route('/whoami', function () {
    session_start();
    return ['sid' => session_id()];
});
$app->route('/warn', function () {
    $a = [];
    return ['v' => $a['undefined_probe_key']];
});
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
