<?php
use Swoole\Coroutine as co;
use Swoole\Coroutine\Channel;
use ZealPHP\G;
use function ZealPHP\elog;
$sessleak = function(){
    $channel = new Channel(5);
    $g = G::instance();
    $g->session['test'] = 'test';
    $_POST['name'] = 'John Doe';
    go(function() use ($channel){
        $g = G::instance();
        $g->session['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        $channel->push($g->session);
    });

    go(function() use ($channel){
        $g = G::instance();
        $g->session['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        $channel->push($g->session);
    });

    go(function() use ($channel){
        $g = G::instance();
        $g->session['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        $channel->push($g->session);
    });

    go(function() use ($channel){
        $g = G::instance();
        $g->session['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        $channel->push($g->session);
    });

    go(function() use ($channel){
        $g = G::instance();
        $g->session['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        co::sleep(2);
        $g->session['test'];
        $channel->push($g->session);
    });
        // elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
    for($i = 0; $i < 5; $i++) {
        $data = $channel->pop();
        echo "<br>\n<pre>";
        print_r($data ?? "Session leak detected $_POST[name]");
        print_r($_POST);
        echo "</pre>";
    }
};