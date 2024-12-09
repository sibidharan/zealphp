<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/App.php';
require_once __DIR__ . '/src/API.class.php';

use ZealPHP\App;

$app = new App(__DIR__);

$app->route('/', function() {
    return "<h1>Hello, ZealPHP!</h1>";
});

$app->route('/hello/{name}', function($name, $self) {
    return "<h1>Hello, $self->get $name!</h1>";
});

$app->route("/global/{name}", [
    'methods' => ['GET', 'POST']
],function($name) {
    if (isset($GLOBALS[$name])) {
        return $GLOBALS[$name];
    } else{
        return "Unknown superglobal";
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

$app->nsRoute('api', '{name}', function($name) {
    echo "<h1>Namespace Route, $name!</h1>";
});

$app->nsPathRoute('api', "{module}/{rquest}", [
    'methods' => ['GET', 'POST']
], function($module, $rquest, $response){
    $api = new API($response);
    try {
        $api->processApi($module, $rquest);
    } catch (Exception $e){
        $api->die($e);
    }
});

$app->run();