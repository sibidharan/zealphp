<?php
/**
 * ZealPHP entrypoint for the LAMP scaffold.
 *
 *   composer install
 *   php app.php
 *   # → http://localhost:8080
 *
 * Mode: superglobals(true) + processIsolation(false). Sequential request
 * handling per worker (same shape as Apache prefork MPM) with real $_SESSION
 * and NO per-include fork cost.
 *
 * Nothing in public/ knows ZealPHP exists. Every file there starts with
 * `require_once __DIR__ . '/../bootstrap/g.php'` and uses $g->get / $g->session
 * the same way they would on Apache.
 */

require_once __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;

// Mixed-mode lifecycle: legacy globals + in-process execution.
//
// - superglobals(true): $_GET, $_POST, $_SESSION populated per request (the
//   way Apache's mod_php does it). Code that reads/writes them directly works.
// - processIsolation(false): no proc_open per included file. Apache parity
//   without paying ~30–50 ms of CGI fork cost per request.
// - enableCoroutine(false) + hookAll(0): no coroutine scheduler, one request
//   at a time per worker. Avoids the race that affects superglobals(true)
//   + coroutines (concurrent requests would clobber each other's $_GET).
App::superglobals(true);
App::processIsolation(false);
App::enableCoroutine(false);
App::hookAll(0);

App::documentRoot(__DIR__ . '/public');

$app = App::init('0.0.0.0', (int) (getenv('PORT') ?: 8080));

// No explicit routes needed. The implicit public-file routes serve
// public/index.php, public/about.php, public/api/users.php, etc.
//
// To add ZealPHP-native routes (WebSocket, SSE, async handlers) alongside
// the LAMP code, register them here BEFORE $app->run():
//
//   $app->route('/health', fn() => ['ok' => true]);

$app->run();
