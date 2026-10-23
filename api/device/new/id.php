<?

use ZealPHP\App;
use ZealPHP\G;
$id = function($response){
    $g = G::instance();
    // Reflected straight into a text/html response — escape it.
    $safe = htmlspecialchars((string) ($g->get['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo "Received id: ".$safe;
    $response->status(206);
    $response->write($safe);
};