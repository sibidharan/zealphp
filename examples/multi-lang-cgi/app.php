<?php

/**
 * Multi-language CGI backend demo.
 *
 * Shows App::registerCgiBackend() for .py (fcgi), .pl (proc), and .php (default).
 * Run: php app.php
 * Then browse http://localhost:8099/hello.py, /info.pl, /index.php
 */

use ZealPHP\App;

require __DIR__ . '/../../vendor/autoload.php';

App::superglobals(true);
App::processIsolation(true);

$app = App::init('0.0.0.0', 8099, __DIR__);
App::documentRoot('public');

// .pl — run Perl scripts directly via proc with /usr/bin/perl interpreter
App::registerCgiBackend('.pl', [
    'mode'        => 'proc',
    'interpreter' => '/usr/bin/perl',
]);

// .cgi — direct shebang execution (proc, no interpreter override)
App::registerCgiBackend('.cgi', ['mode' => 'proc']);

// .py (fcgi) — requires a running Python FastCGI server (e.g. flup).
// Uncomment if you have python-fpm or flup running on 127.0.0.1:9001:
// App::registerCgiBackend('.py', [
//     'mode'    => 'fcgi',
//     'address' => '127.0.0.1:9001',
//     'fcgi_params' => ['SCRIPT_ROOT' => __DIR__ . '/public'],
// ]);

// .php uses App::$cgi_mode (default 'proc') — no explicit registration needed.
// The registry falls back to the global mode for unregistered extensions.

$app->setFallback(function () {
    return App::include('/' . ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
});

$app->run();
