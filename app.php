<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/App.php';
use ZealPHP\App;

$app = new App(__DIR__);

// Now we can define a route with parameters:
$app->route('/hello/{name}', function($name) {
    return "<h1>Hello, $name!</h1>";
});

$app->route("/global/{name}", function($name, $app, $request) {
    // dynamically return the superglobal from name
    if ($name === 'GET') {
        return $_GET;
    }
    if ($name === 'POST') {
        return $_POST;
    }
    if ($name === 'COOKIE') {
        return $_COOKIE;
    }
    if ($name === 'FILES') {
        return $_FILES;
    }
    if ($name === 'SERVER') {
        return $_SERVER;
    }
    if ($name === 'REQUEST') {
        return $_REQUEST;
    }
    if ($name === 'ENV') {
        return $_ENV;
    }
    if ($name === 'SESSION') {
        return $_SESSION;
    }
    if ($name === 'GLOBALS') {
        return $GLOBALS;
    }
    return "Unknown superglobal";
});

// We can also define multiple methods for a route:
$app->route('/user/{id}/post/{postId}',[
    'methods' => ['GET', 'POST']
], function($id, $postId) {
    return "<h1>User $id, Post $postId</h1>";
});

$app->run();
