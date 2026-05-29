<?php

declare(strict_types=1);

/**
 * Cross-mode isolation probe server (standalone, ad-hoc).
 *
 * MODE env selects the lifecycle: legacy-cgi | coroutine | coroutine-legacy | mixed.
 * /probe sets every request-state primitive to a per-request unique value,
 * yields the coroutine (~40ms via Timer+Channel so concurrent requests
 * interleave), then re-reads them; concurrent-driver.php verifies isolation.
 *
 * Usage:  MODE=coroutine-legacy PORT=9820 \
 *           php -d extension=/path/to/zealphp.so scripts/isolation/probe-server.php
 *
 * See also the committed PHPUnit equivalents:
 *   tests/Integration/CoroutineIsolationContractTest.php
 *   tests/Integration/TrustBarIsolationTest.php
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ZealPHP\App;

$mode = getenv('MODE') ?: 'coroutine-legacy';
$port = (int)(getenv('PORT') ?: 9820);

App::mode($mode);
if ($mode === 'coroutine-legacy') {
    App::defineIsolation(true);
    App::coroutineStaticsIsolation(true);   // Stage 5 — isolate function-local static $x
}
$app = App::init('127.0.0.1', $port);

$cur = new OpenSwoole\Atomic(0);
$max = new OpenSwoole\Atomic(0);
App::onWorkerStart(function () { global $tb_boot; $tb_boot = 'BOOT'; });

class IsoProbeClass { public static $user = 'init'; }
function iso_probe_static($v) { static $s = null; if ($v !== null) $s = $v; return $s; }

$app->route('/probe', function ($request, $response) use ($cur, $max) {
    $g = \ZealPHP\G::instance();
    global $tb_glob, $tb_boot;
    $x = $g->get['x'] ?? 'NONE';

    $_GET['k']=$x; $_POST['k']=$x; $_REQUEST['k']=$x; $_COOKIE['k']=$x;
    $_FILES['k']=$x; $_SERVER['TBK']=$x; $_SESSION['k']=$x;
    iso_probe_static($x); IsoProbeClass::$user = $x; $tb_glob = $x;
    if (!defined('TB_TENANT')) define('TB_TENANT', $x);
    putenv("TB_ENV=$x"); ini_set('default_charset', $x);
    header("X-TB: $x"); setcookie('tbc', $x); http_response_code(200);

    $c = $cur->add(1); while (true) { $m=$max->get(); if ($c<=$m||$max->cmpset($m,$c)) break; }
    if (\OpenSwoole\Coroutine::getCid() > 0) {
        $ch = new OpenSwoole\Coroutine\Channel(1);
        OpenSwoole\Timer::after(40, fn() => $ch->push(1));
        $ch->pop(3.0);
    }
    $cur->sub(1);

    return ['x'=>$x, 'maxc'=>$max->get(), 'iso'=>[
        '$_GET'=>($_GET['k']??null)===$x, '$_POST'=>($_POST['k']??null)===$x,
        '$_REQUEST'=>($_REQUEST['k']??null)===$x, '$_COOKIE'=>($_COOKIE['k']??null)===$x,
        '$_FILES'=>($_FILES['k']??null)===$x, '$_SERVER'=>($_SERVER['TBK']??null)===$x,
        '$_SESSION'=>($_SESSION['k']??null)===$x, 'class_static'=>IsoProbeClass::$user===$x,
        '$GLOBALS'=>($tb_glob??null)===$x, 'constant'=>(defined('TB_TENANT')?constant('TB_TENANT'):null)===$x,
        'ini_set'=>ini_get('default_charset')===$x, 'bootstrap'=>($tb_boot??null)==='BOOT',
        'fn_static'=>iso_probe_static(null)===$x, 'putenv'=>getenv('TB_ENV')===$x,
    ]];
});
$app->route('/ping', fn() => ['ok'=>true]);
$app->run(['worker_num'=>2, 'task_worker_num'=>0, 'log_level'=>5]);
