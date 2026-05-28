<?php

declare(strict_types=1);

/**
 * Trust-bar fixture server for TrustBarIsolationTest.
 *
 * Boots App::mode('coroutine-legacy') and exposes /probe, which sets EVERY
 * request-state primitive to a per-request unique value, yields the coroutine
 * (~40ms via Timer+Channel so concurrent requests interleave), then re-reads
 * them. The driver fires N concurrent requests and verifies each kept ITS OWN
 * value — i.e. per-coroutine isolation under real concurrency.
 *
 * Run as:  php -d extension=<zealphp.so> <this> <port>
 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;

$port = (int)($argv[1] ?? 9820);
App::mode(App::MODE_COROUTINE_LEGACY);
App::defineIsolation(true);
$app = App::init('127.0.0.1', $port);

$cur = new OpenSwoole\Atomic(0);
$max = new OpenSwoole\Atomic(0);

App::onWorkerStart(function () { global $tb_boot; $tb_boot = 'BOOT'; });

class TBClass { public static $user = 'init'; }
function tb_static($v) { static $s = null; if ($v !== null) $s = $v; return $s; }

$app->route('/probe', function ($request, $response) use ($cur, $max) {
    $g = \ZealPHP\G::instance();
    global $tb_glob, $tb_boot;
    $x = $g->get['x'] ?? 'NONE';

    $_GET['k']=$x; $_POST['k']=$x; $_REQUEST['k']=$x; $_COOKIE['k']=$x;
    $_FILES['k']=$x; $_SERVER['TBK']=$x; $_SESSION['k']=$x;
    tb_static($x);                              // function-local static (process-level)
    TBClass::$user = $x;                        // class static
    $tb_glob = $x;                              // $GLOBALS
    if (!defined('TB_TENANT')) define('TB_TENANT', $x); // constant
    putenv("TB_ENV=$x");                        // process env (process-level)
    ini_set('default_charset', $x);             // ini

    header("X-TB: $x");
    setcookie('tbc', $x);
    http_response_code(200);

    $c = $cur->add(1); while (true) { $m=$max->get(); if ($c<=$m||$max->cmpset($m,$c)) break; }
    $ch = new OpenSwoole\Coroutine\Channel(1);
    OpenSwoole\Timer::after(40, fn() => $ch->push(1));
    $ch->pop(3.0);                              // YIELD — interleave point
    $cur->sub(1);

    return ['x'=>$x, 'maxc'=>$max->get(), 'iso'=>[
        '$_GET'        => ($_GET['k'] ?? null) === $x,
        '$_POST'       => ($_POST['k'] ?? null) === $x,
        '$_REQUEST'    => ($_REQUEST['k'] ?? null) === $x,
        '$_COOKIE'     => ($_COOKIE['k'] ?? null) === $x,
        '$_FILES'      => ($_FILES['k'] ?? null) === $x,
        '$_SERVER'     => ($_SERVER['TBK'] ?? null) === $x,
        '$_SESSION'    => ($_SESSION['k'] ?? null) === $x,
        'class_static' => TBClass::$user === $x,
        '$GLOBALS'     => ($tb_glob ?? null) === $x,
        'constant'     => (defined('TB_TENANT') ? constant('TB_TENANT') : null) === $x,
        'ini_set'      => ini_get('default_charset') === $x,
        'bootstrap'    => ($tb_boot ?? null) === 'BOOT',
        // process-level landmines — reported, NOT part of the isolation contract:
        'fn_static'    => tb_static(null) === $x,
        'putenv'       => getenv('TB_ENV') === $x,
    ]];
});

$app->route('/ping', fn() => ['ok'=>true]);
$app->run(['worker_num'=>2, 'task_worker_num'=>0, 'log_level'=>5]);
