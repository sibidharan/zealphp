<?

use function ZealPHP\elog;
use ZealPHP\App;
use OpenSwwole\Coroutine as co;


$backup = function ($a, $b) {
    elog("Received $a $b", "task");
};