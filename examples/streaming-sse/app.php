<?php

require_once __DIR__ . '/vendor/autoload.php';

use OpenSwoole\Coroutine as co;
use ZealPHP\App;

App::superglobals(false);
$app = App::init('0.0.0.0', 8080);

// SSE endpoint — sends a tick every second for 20 seconds
$app->route('/events', function($response) {
    $response->sse(function($emit) {
        $emit(json_encode([
            'message' => 'connected',
            'time' => date('H:i:s'),
        ]), 'open');

        for ($i = 1; $i <= 20; $i++) {
            co::sleep(1);
            $emit(json_encode([
                'tick' => $i,
                'time' => date('H:i:s'),
                'message' => "Event $i of 20",
            ]), 'tick', (string)$i);
        }

        $emit(json_encode(['message' => 'stream complete']), 'done');
    });
});

// Generator SSR streaming — streams HTML sections progressively
$app->route('/stream', function() {
    return (function() {
        yield '<h1>Streaming HTML</h1><p>Each paragraph arrives one second apart.</p>';
        for ($i = 1; $i <= 5; $i++) {
            co::sleep(1);
            yield "<p>Section $i arrived at " . date('H:i:s') . "</p>";
        }
        yield '<p><em>Stream complete.</em></p>';
    })();
});

// Serve the HTML client
$app->route('/', function() {
    return file_get_contents(__DIR__ . '/public/index.html');
});

$app->run();
