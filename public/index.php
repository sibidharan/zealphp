<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/App.php';
use ZealPHP\App;

$app = new App();

// Now we can define a route with parameters:
$app->route('/hello/{name}', function($name) {
    return "<h1>Hello, $name!</h1>";
});

// We can also define multiple methods for a route:
$app->route('/user/{id}/post/{postId}',[
    'methods' => ['GET', 'POST']
], function($id, $postId) {
    return "<h1>User $id, Post $postId</h1>";
});

$app->run();
