<?
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine as co;
use ZealPHP\G;

$co = function(){
    # Testing session leak
    $channel = new Channel(5);
    $g = G::instance();
    // $g->status = 200;
    $g->get['name'] = 'John Doe';
    $_SESSION['name'] = 'Jane Doe';
    go(function() use ($channel) {
        co::sleep(10);
        $channel->push('Hello, Coroutine 1!');
    });
    go(function() use ($channel) {
        co::sleep(7);
        $channel->push('Hello, Coroutine! 2');
    });
    go(function() use ($channel) {
        co::sleep(6);
        $channel->push('Hello, Coroutine! 3');
    });
    go(function() use ($channel) {
        co::sleep(5);
        $channel->push('Hello, Coroutine! 4');
    });
    go(function() use ($channel) {
        co::sleep(9);
        $channel->push('Hello, Coroutine 5!');
    });
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = $channel->pop();
    }
    return [
        'status' => $g->status,
        'name' => $g->get['name'],
        'a_name' => $_SESSION['name'],
        'results' => $results
    ];
};