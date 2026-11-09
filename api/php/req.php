<?php
// Test/debug endpoint (prints request or process state): not defined unless
// ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return;
}
use ZealPHP\G;

$req = function(){
    $g = G::instance();
    print_r($g->server);
    print_r($g->get);
};