<?

use ZealPHP\G;

$req = function(){
    $g = G::instance();
    print_r($g->server);
    print_r($g->get);
};