<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/App.php';

use ZealPHP\App;

$app = new App(__DIR__);

$app->route('/', function() {
    echo "<h1>Hello, ZealPHP!</h1>";
});

$app->route('/home', function() {
    echo "<h1>This is home override</h1>";
});

$app->route('/quiz/{page}', function($page) {
    echo "<h1>This is quiz: $page</h1>";
});

$app->route('/quiz/{page}/{tab}', function($page, $tab) {
    echo "<h1>This is quiz: $page tab=$tab</h1>";
});

$app->route('/quiz/{page}/{tab}/{id}', function($page, $tab, $id) {
    echo "<h1>This is quiz: $page tab=$tab id=$id</h1>";
});

$app->route('/hello/{name}', function($name, $self) {
    echo "<h1>Hello, $self->get $name!</h1>";
});

$app->route("/global/{name}", [
    'methods' => ['GET', 'POST']
],function($name) {
    if (isset($GLOBALS[$name])) {
        print_r($GLOBALS[$name]);
    } else{
        echo "Unknown superglobal";
    }
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


// $app->nsRoute('api', '{name}', function($name) {
//     echo "<h1>Namespace Route Override, $name!</h1>";
// });


$app->run();