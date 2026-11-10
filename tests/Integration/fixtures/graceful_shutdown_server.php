<?php
/**
 * Fixture for GracefulShutdownTest: a coroutine-mode server whose workers each
 * have a recurring timer and log one line per request through the async writer.
 * Usage: php graceful_shutdown_server.php <port>   (ZEALPHP_LOG_DIR picks the log dir)
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\G;

use function ZealPHP\elog;

$port = (int) ($argv[1] ?? 9861);

// Coroutine mode: requests run in coroutines, so logging goes through the
// async writer.
App::mode(App::MODE_COROUTINE);
$app = App::init('127.0.0.1', $port);

// A recurring timer in every worker, like the session GC on worker 0.
App::onWorkerStart(static function (): void {
    App::tick(60000, static fn () => null);
});

$app->route('/ping', static function () {
    $n = G::instance()->get['n'] ?? '';
    elog('shutdown-test ping ' . (is_scalar($n) ? (string) $n : ''), 'info');
    return ['ok' => true, 'pid' => getmypid()];
});

$app->run(['worker_num' => 2, 'task_worker_num' => 0]);
