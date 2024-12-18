<?

use OpenSwoole\Core\Psr\Response;

$psr = function(){
    return (new Response("PSR Hello World"))->withStatus(205);
    // return 205;
};