<?php

require_once __DIR__ . '/vendor/autoload.php';

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Coroutine as co;
use OpenSwoole\Coroutine\Channel;
use ZealPHP\App;
use ZealPHP\G;

use function ZealPHP\elog;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;
use function ZealPHP\zlog;

App::superglobals(false);

$app = App::init('0.0.0.0', 8080);

# Route for /phpinfo 
$app->route('/phpinfo', function() {
    //Loads template from app/phpinfo.php since PHP_SELF is /phpinfo.php
    App::render('phpinfo');
});


$app->route('/co', function() {
    $channel = new Channel(5);
    go(function() use ($channel) {
        sleep(3);
        $channel->push('Hello, Coroutine 1!');
    });
    go(function() use ($channel) {
        sleep(3);
        $channel->push('Hello, Coroutine! 2');
    });
    go(function() use ($channel) {
        sleep(1);
        $channel->push('Hello, Coroutine! 3');
    });
    go(function() use ($channel) {
        sleep(2);
        $channel->push('Hello, Coroutine! 4');
    });
    go(function() use ($channel) {
        sleep(3);
        $channel->push('Hello, Coroutine 5!');
    });
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = $channel->pop();
    }
    echo "<pre>";
    print_r($results);
    echo "</pre>";
});

// $app->route('/home', function() {
//     echo "<h1>This is home override</h1>";
// });

$app->route('/quiz/{page}', function($page) {
    echo "<h1>This is quiz: $page</h1>";
});

$app->route('/quiz/{page}/{tab}/{nwe}', function($nwe, $tab, $page) {
    echo "<h1>This is quiz: $page tab=$tab</h1>";
});

// $app->route('/quiz/{page}/{tab}/{id}', function($page, $tab, $id) {
//     echo "<h1>This is quiz: $page tab=$tab id=$id</h1>";
// });

// $app->route('/hello/{name}', function($name, $self) {
//     echo "<h1>Hello, $self->get $name!</h1>";
// });

$app->route("/suglobal/{name}", [
    'methods' => ['GET', 'POST']
],function($name) {
    response_add_header('X-Prototype',  'buffer');
    response_set_status(202);
    // $g = G::instance();
    if(App::$superglobals){
        if (isset($GLOBALS[$name])) {
            print_r($GLOBALS[$name]);
        } else{
            echo "Unknown superglobal";
        }
    } else {
        $g = G::instance();
        if (isset($g->$name)) {
            print_r($g->$name);
        } else{
            echo "Unknown global";
        }
    }
});

$app->route("/coglobal/set/session", [
    'methods' => ['GET', 'POST']
],function($name) {
    $G = G::instance();
    $G->session['name'] = $name;
    return new Response('Session set', 300, 'success', ['Content-Type' => 'text/plain', 'X-Test' => 'test']);
});

$app->route("/coglobal/get/session", [
    'methods' => ['GET', 'POST']
],function($name) {
    echo G::get('session')['name'];
});

$app->route('/user/{id}/post/{postId}',[
    'methods' => ['GET', 'POST']
], function($id, $postId) {
    echo "<h1>User $id, Post $postId</h1>";
});

$app->nsRoute('watch', '/get/{key}', function($key){
    echo $_GET[$key] ?? null;
});

// patternRoute
// Matches any URL starting with /raw/
$app->patternRoute('/raw/(?P<rest>.*)', ['methods' => ['GET']], function($rest) {
    echo "You requested: $rest";
});

# Override Implicit Rules
// $app->nsRoute('api', '{name}', function($name) {
//     echo "<h1>Namespace Route Override, $name!</h1>";
// });


$app->run([
    'task_worker_num' => 8
]);