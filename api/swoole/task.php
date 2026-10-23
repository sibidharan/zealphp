<?
use ZealPHP\App;
use function ZealPHP\elog;

$app = App::instance();

$task = function($request, $response,OpenSwoole\HTTP\Server  $server) {
    $server->task([
        'handler' => '/task/backup',
        'args' => [1, 2]
    ], -1, function ($server, $task_id, $data) {
        print_r($data);
        // Only the PSR response this task returns — never arbitrary objects.
        $response = unserialize($data['result'], [
            'allowed_classes' => [\OpenSwoole\Core\Psr\Response::class, \OpenSwoole\Core\Psr\Stream::class],
        ]);
        print_r($response);
        // Output the response body
        echo "Received from child: " . $response->getBody();
    });
};