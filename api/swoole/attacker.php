<?php
// Test/debug endpoint (prints request or process state): not defined unless
// ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return;
}
use ZealPHP\G;
use OpenSwoole\Coroutine as co;
$attacker = function(){
    $g = G::instance();
    $_SESSION['name'] = 'Swoole Attacker';
    $_POST = [];
    co::sleep(0.01);
    $g->status = 506;
    $g->name = 'Swoole Attacker';
    $g->inject = 'Swoole Attacker';
};