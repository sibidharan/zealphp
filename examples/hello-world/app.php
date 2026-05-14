<?php

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;

App::superglobals(false);
$app = App::init('0.0.0.0', 8080);

// JSON route with URL parameter
$app->route('/hello/{name}', function($name) {
    return ['hello' => $name, 'time' => date('H:i:s')];
});

// HTML route with request injection
$app->route('/greet/{name}', function($name, $request) {
    return "<h1>Hello, " . htmlspecialchars($name) . "!</h1>
            <p>Method: {$request->server['REQUEST_METHOD']}</p>";
});

// Simple homepage
$app->route('/', function() {
    return '<h1>ZealPHP</h1><p>Try <a href="/hello/world">/hello/world</a></p>';
});

$app->run();
