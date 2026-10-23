<?php
/**
 * Fixture for SessionPersistBeforeSendTest. Each write route ends the response
 * and then keeps the request alive, so a session written only in the manager's
 * request-end `finally` is not in the store yet when the client's next request
 * reads it.
 * Usage: php session_persist_before_send_server.php <port> <coroutine|mixed>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

$port = (int) ($argv[1] ?? 9838);
$mixed = ($argv[2] ?? 'coroutine') === 'mixed';
App::mode($mixed ? App::MODE_MIXED : App::MODE_COROUTINE);
$app = App::init('127.0.0.1', $port);

$read = static function () use ($mixed): array {
    return $mixed ? (array) ($_SESSION ?? []) : (array) (RequestContext::instance()->session ?? []);
};
$write = static function (array $s) use ($mixed): void {
    if ($mixed) {
        $_SESSION = $s;
    } else {
        RequestContext::instance()->session = $s;
    }
};
$linger = static function (): void {
    if (\OpenSwoole\Coroutine::getCid() > 0) {
        \OpenSwoole\Coroutine::usleep(600000);
    } else {
        usleep(600000);
    }
};

// Sets v, sends the response, then lingers before the request ends.
$app->route('/set', function ($response) use ($read, $write, $linger) {
    session_start();
    $s = $read();
    $s['v'] = (string) (RequestContext::instance()->get['v'] ?? '');
    $s['tmp'] = 'x';
    $write($s);
    $response->end('set');
    $linger();
});

// Sends the response first, then changes the session: the change must still
// be persisted (by the manager's close), including the unset of a key the
// early save already stored.
$app->route('/after', function ($response) use ($read, $write) {
    session_start();
    $response->end('after');
    $s = $read();
    $s['late'] = 'yes';
    unset($s['tmp']);
    $write($s);
});

$app->route('/get', function () use ($read) {
    session_start();
    return $read();
});
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 2, 'dispatch_mode' => 3, 'task_worker_num' => 0, 'log_level' => 5]);
