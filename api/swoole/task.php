<?
use ZealPHP\App;
use function ZealPHP\elog;

$app = App::instance();

$task = function($request, $response,OpenSwoole\HTTP\Server  $server) {
    $server->task([
        'handler' => '/task/backup',
        'args' => [1, 2]
    ], -1, function ($server, $task_id, $data) {
        elog(json_encode($data), "api");
    });
};