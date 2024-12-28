<?
use function ZealPHP\zlog;
use OpenSwoole\Coroutine as co;
use ZealPHP\G;
$test = function () {
    $g = G::instance();
    // $g->name = 'John Doe';
    // co::sleep(5);
    return $g;
};