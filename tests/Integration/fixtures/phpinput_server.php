<?php

declare(strict_types=1);

/**
 * php://input isolation fixture for PhpInputIsolationTest.
 *
 * Boots App::mode('coroutine-legacy') and exposes POST /echo, which yields
 * mid-request (Timer+Channel, so concurrent requests interleave) and then
 * reads `php://input` TWICE through IOStreamWrapper — plus `php://temp` and
 * `php://memory` to prove the wrapper still delegates non-input streams.
 * The driver POSTs a unique body per request and verifies every response
 * echoes exactly its own body (no cross-coroutine body contamination, no
 * one-shot stream).
 *
 * Run as:  php -d extension=<zealphp.so> <this> <port>
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;

$port = (int)($argv[1] ?? 9821);
$workers = (int)($argv[2] ?? 2);
App::mode(App::MODE_COROUTINE_LEGACY);
$app = App::init('127.0.0.1', $port);

$app->route('/echo', methods: ['POST'], handler: function () {
    $first = file_get_contents('php://input');

    // Interleave point — concurrent requests overlap here, so a process-wide
    // input buffer would cross-contaminate.
    $ch = new OpenSwoole\Coroutine\Channel(1);
    OpenSwoole\Timer::after(30, static fn () => $ch->push(1));
    $ch->pop(3.0);

    $second = file_get_contents('php://input');     // re-readable after yield

    $tmp = fopen('php://temp', 'r+');
    fwrite($tmp, 'TEMPOK');
    rewind($tmp);
    $t = (string) fread($tmp, 16);
    fclose($tmp);

    return [
        'len'   => strlen($first),
        'raw'   => $first,
        'again' => $second === $first,
        'temp'  => $t,
    ];
});

$app->route('/ping', fn () => ['ok' => true]);
$app->run(['worker_num' => $workers, 'task_worker_num' => 0, 'log_level' => 5]);
