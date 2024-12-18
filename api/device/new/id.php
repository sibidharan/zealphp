<?

use ZealPHP\App;
use ZealPHP\G;
$id = function($response){
    $g = G::instance();
    echo "Received id: ".$g->get['id'];
    $response->status(206);
    $response->write($g->get['id']);
};