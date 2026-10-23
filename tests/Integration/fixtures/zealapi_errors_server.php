<?php
/**
 * Fixture for ZealApiErrorDisclosureTest: a ZealAPI app whose handlers throw.
 * Usage: php zealapi_errors_server.php <port>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;

$app = App::init('127.0.0.1', (int) ($argv[1] ?? 9840), __DIR__ . '/zealapi_errors');
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
