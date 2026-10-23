<?php
/**
 * Fixture for SessionStoreRaceTest. A request that is still running when the
 * session is destroyed (or a key is removed) by another request must not put
 * the old session back when it closes.
 * Usage: php session_store_race_server.php <port> <coroutine|mixed> <inline|file|table> <save_path> [ttl]
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

$port = (int) ($argv[1] ?? 9870);
$mixed = ($argv[2] ?? 'coroutine') === 'mixed';
$handler = $argv[3] ?? 'inline';
$savePath = $argv[4] ?? sys_get_temp_dir() . '/zealphp_sess_race_' . $port;
$ttl = (int) ($argv[5] ?? 0);
@mkdir($savePath, 0700, true);

App::mode($mixed ? App::MODE_MIXED : App::MODE_COROUTINE);
App::sessionSavePath($savePath);
if ($ttl > 0) {
    App::sessionTtl($ttl);
}
if ($handler !== 'inline') {
    App::sessionHandler($handler);
}
// Small Table rows so the oversize fallback is reachable from a test.
App::sessionDataSize(1024);
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
$linger = static function (int $ms): void {
    if (\OpenSwoole\Coroutine::getCid() > 0) {
        \OpenSwoole\Coroutine::usleep($ms * 1000);
    } else {
        usleep($ms * 1000);
    }
};
$arg = static fn(string $k): string => (string) (RequestContext::instance()->get[$k] ?? '');

$app->route('/login', function () use ($read, $write, $arg) {
    session_start();
    $s = $read();
    $s['user'] = $arg('u') !== '' ? $arg('u') : 'alice';
    $s['tmp'] = 'x';
    $write($s);
    return ['ok' => true];
});

// Holds the session open while another request changes it, then touches one
// key of its own (or, with ?touch=0, nothing).
$app->route('/slow', function () use ($read, $write, $linger, $arg) {
    session_start();
    $linger(700);
    if ($arg('touch') !== '0') {
        $s = $read();
        $s['seen'] = (int) ($s['seen'] ?? 0) + 1;
        $write($s);
    }
    return ['ok' => true];
});

$app->route('/logout', function () {
    session_start();
    session_destroy();
    return ['ok' => true];
});

$app->route('/drop', function () use ($read, $write) {
    session_start();
    $s = $read();
    unset($s['tmp']);
    $write($s);
    return ['ok' => true];
});

// Stores a value of ?n bytes (bigger than the Table row with ?n=4096).
$app->route('/big', function () use ($read, $write, $arg) {
    session_start();
    $s = $read();
    $s['big'] = str_repeat('b', (int) $arg('n'));
    $write($s);
    return ['ok' => true];
});

$app->route('/regen', function () use ($read, $write) {
    session_start();
    session_regenerate_id(true);
    $s = $read();
    $s['regen'] = 1;
    $write($s);
    return ['ok' => true];
});

$app->route('/get', function () use ($read) {
    session_start();
    $s = $read();
    if (isset($s['big']) && is_string($s['big'])) {
        $s['big'] = strlen($s['big']);
    }
    return $s;
});

$app->route('/files', function () use ($savePath) {
    return array_map('basename', glob($savePath . '/sess_*') ?: []);
});
$app->route('/pid', fn() => ['pid' => getmypid()]);
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 2, 'dispatch_mode' => 3, 'task_worker_num' => 0, 'log_level' => 5]);
