<?php

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;

App::superglobals(false);
$app = App::init('0.0.0.0', 8080);

// In-memory client list (per-worker; sufficient for single-worker demos)
$clients = [];

$app->ws(
    '/chat',
    onMessage: function($server, $frame, $g) use (&$clients) {
        $payload = json_encode([
            'from' => $frame->fd,
            'message' => $frame->data,
            'time' => date('H:i:s'),
        ]);
        foreach (array_keys($clients) as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $payload);
            }
        }
    },
    onOpen: function($server, $request, $g) use (&$clients) {
        $clients[$request->fd] = true;
        $server->push($request->fd, json_encode([
            'event' => 'connected',
            'id' => $request->fd,
            'online' => count($clients),
        ]));
        // Notify others
        $payload = json_encode([
            'event' => 'join',
            'id' => $request->fd,
            'online' => count($clients),
        ]);
        foreach (array_keys($clients) as $fd) {
            if ($fd !== $request->fd && $server->isEstablished($fd)) {
                $server->push($fd, $payload);
            }
        }
    },
    onClose: function($server, $fd, $g) use (&$clients) {
        unset($clients[$fd]);
        $payload = json_encode([
            'event' => 'leave',
            'id' => $fd,
            'online' => count($clients),
        ]);
        foreach (array_keys($clients) as $cfd) {
            if ($server->isEstablished($cfd)) {
                $server->push($cfd, $payload);
            }
        }
    }
);

// Serve the HTML client
$app->route('/', function() {
    return file_get_contents(__DIR__ . '/public/index.html');
});

$app->run();
