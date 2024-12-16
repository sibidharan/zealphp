<?

use ZealPHP\G;

$req = function(){
    $g = G::getInstance();
    print_r($g->server);
    print_r($g->get);
};