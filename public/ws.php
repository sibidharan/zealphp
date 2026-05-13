<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP · WebSocket', 'page' => 'websocket', 'active' => 'websocket',
    'description' => 'Real-time bi-directional WebSocket demos with rooms, auth, binary frames, and heartbeat.']);
