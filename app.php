<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/App.php';
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
    return "<h1>User $id, Post $postId</h1>";
});

$app->run();
