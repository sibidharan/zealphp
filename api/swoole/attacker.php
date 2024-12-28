<?php
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