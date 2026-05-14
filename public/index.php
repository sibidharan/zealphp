<?php use ZealPHP\App;
App::render('_master', ['title' => 'ZealPHP — The PHP Runtime for AI Web Apps', 'page' => 'home', 'active' => 'home',
    'description' => 'The PHP runtime for AI web applications. Upgrade existing PHP codebases to async — SSR streaming, WebSocket, SSE, coroutines, shared memory. One server, Go-level performance.']);
