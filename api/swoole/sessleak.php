<?php
use Swoole\Coroutine as co;
use Swoole\Coroutine\Channel;
use ZealPHP\G;
use function ZealPHP\elog;
$sessleak = function(){
    $channel = new Channel(5);
    $_SESSION['test'] = 'test';
    $_POST['name'] = 'John Doe';
    go(function() use ($channel){
        $_SESSION['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        $channel->push($_SESSION);
    });

    go(function() use ($channel){
        $_SESSION['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        $channel->push($_SESSION);
    });

    go(function() use ($channel){
        $_SESSION['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        $channel->push($_SESSION);
    });

    go(function() use ($channel){
        $_SESSION['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        $channel->push($_SESSION);
    });

    go(function() use ($channel){
        $_SESSION['test'] = 'test';
        elog("Session leak started, inside coroutine, waiting for 10 seconds to check if _SESSION gets overwritten. Now bombard the server with requests...", "test");
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        co::sleep(2);
        $_SESSION['test'];
        $channel->push($_SESSION);
    });

    for($i = 0; $i < 5; $i++) {
        $data = $channel->pop();
        echo "<br>\n<pre>";
        print_r($data ?? "Session leak detected $_POST[name]");
        print_r($_POST);
        echo "</pre>";
    }
};