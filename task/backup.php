<?

use function ZealPHP\elog;
use ZealPHP\App;
use function ZealPHP\coproc;

$backup = function ($a, $b) {
    elog("Received $a $b", "task");
    coproc(function(){
        go(function(){
            elog("In coroutine", "task");
        });
        go(function(){
            elog("In coroutine 2", "task");
        });
        echo "Hello from coroutine";
    });
    elog("After coproc", "task");
    return $a+$b;
};