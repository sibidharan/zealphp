<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/App.php';
use ZealPHP\App;

$app = new App();

// Now we can define a route with parameters:
$app->route('GET', '/hello/{name}', function($name) {
    return "<h1>Hello, $name!</h1>";
});

$app->route('GET', '/user/{id}/post/{postId}', function($id, $postId) {
    return "<h1>User $id, Post $postId</h1>";
});

$app->run();
