<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP — Async PHP Framework', 'page' => 'home', 'active' => 'home',
    'description' => 'ZealPHP is an async PHP framework built on OpenSwoole. Coroutines, SSR streaming, WebSocket, and zero blocking.']);
