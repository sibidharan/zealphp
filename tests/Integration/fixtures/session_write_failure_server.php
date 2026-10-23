<?php
/**
 * Fixture for SessionWriteFailureTest — one worker, so "the same pid answers
 * after the failed write" proves the worker survived.
 * Usage: php session_write_failure_server.php <port> <coroutine|mixed>
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\RequestContext;

$port = (int) ($argv[1] ?? 9836);
$mode = ($argv[2] ?? 'coroutine') === 'mixed' ? App::MODE_MIXED : App::MODE_COROUTINE;

App::mode($mode);
$app = App::init('127.0.0.1', $port);

// Stores a value the session serializer rejects, so the request-end write throws.
$app->route('/unwritable', function () use ($mode) {
    session_start();
    if ($mode === App::MODE_MIXED) {
        $_SESSION['cb'] = static function () {};
    } else {
        $g = RequestContext::instance();
        $s = (array) ($g->session ?? []);
        $s['cb'] = static function () {};
        $g->session = $s;
    }
    return ['pid' => getmypid()];
});

$app->route('/pid', fn() => ['pid' => getmypid()]);
$app->route('/ping', fn() => ['ok' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
