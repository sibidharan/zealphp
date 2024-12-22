<?

use function ZealPHP\elog;
use ZealPHP\App;
use function ZealPHP\coproc;
use OpenSwoole\Core\Psr\Response;

$backup = function ($a, $b) {
    $response = new Response("Hello World",200, 'child_fork', ['Content-Type' => 'application/json']);

    // Serialize the response
    $serializedResponse = serialize($response);
    return $serializedResponse;
};