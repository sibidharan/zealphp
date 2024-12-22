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
        $response = unserialize($data['result']);
        print_r($response);
        // Output the response body
        echo "Received from child: " . $response->getBody();
    });
};