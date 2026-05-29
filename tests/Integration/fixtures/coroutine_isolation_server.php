<?php

declare(strict_types=1);

/**
 * Fixture server for CoroutineIsolationContractTest — the ext-zealphp isolation
 * contract under real concurrency. Boots App::mode('coroutine-legacy') (Mode 4:
 * superglobals(true) + coroutine + silentRedeclare + includeIsolation +
 * coroutineGlobalsIsolation) and exposes a /probe route that, under a forced
 * coroutine yield, asserts per-coroutine isolation of superglobals AND user
 * globals, plus visibility of a bootstrap-time global.
 *
 * Run by the test harness as:  php -d extension=<zealphp.so> <this> <port>
 */
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ZealPHP\App;

$port = (int)($argv[1] ?? 9911);

App::mode(App::MODE_COROUTINE_LEGACY);          // the one-call Mode 4 legacy preset
$app = App::init('127.0.0.1', $port);

// Per-worker concurrency tracking (Atomic = process-shared, NOT coroutine-local).
$cur  = new OpenSwoole\Atomic(0);
$max  = new OpenSwoole\Atomic(0);

// Simulate a WordPress-style bootstrap global ($wp). The framework's
// post-bootstrap re-baseline (registered by coroutine-legacy) must capture it
// so it stays visible inside every request coroutine.
App::onWorkerStart(function () {
    global $bootstrap_global;
    $bootstrap_global = 'SHARED-FROM-BOOTSTRAP';
});

$app->route('/probe', function ($request, $response) use ($cur, $max) {
    $g = \ZealPHP\G::instance();
    global $req_global, $bootstrap_global;

    // Read via $g->get — the canonical accessor. Its &__get hands back $_GET
    // BY REFERENCE (flipping the slot to IS_REFERENCE); the ext snapshot must
    // still isolate it per coroutine. This is the exact path real apps use.
    $x = $g->get['x'] ?? 'NONE';
    $_GET['x']  = $x;                 // superglobal (must isolate)
    $req_global = $x;                 // per-request user global (must isolate)
    $bootVisible = $bootstrap_global ?? 'MISSING';

    $beforeSG = $_GET['x'];
    $beforeGv = $req_global;

    $c = $cur->add(1);
    while (true) { $m = $max->get(); if ($c <= $m || $max->cmpset($m, $c)) break; }

    // Reliable ~40ms yield via Timer (works) + Channel (works) — forces the
    // 40 concurrent requests to interleave here.
    $ch = new OpenSwoole\Coroutine\Channel(1);
    OpenSwoole\Timer::after(40, function () use ($ch) { $ch->push(1); });
    $ch->pop(3.0);
    $cur->sub(1);

    $afterSG = $_GET['x'] ?? 'GONE';
    $afterGv = $req_global ?? 'GONE';
    $payload = [
        'cid'          => \OpenSwoole\Coroutine::getCid(),
        'expect'       => $afterSG,
        'sg_stable'    => ($beforeSG === $afterSG),
        'gv_stable'    => ($beforeGv === $afterGv),
        'boot_visible' => ($bootVisible === 'SHARED-FROM-BOOTSTRAP'),
        'maxc'         => $max->get(),
    ];
    // End directly on the raw OpenSwoole response (barrier-style) — bisecting
    // whether the framework return-value dispatch is what wipes $_GET.
    $g->openswoole_response->header('Content-Type', 'application/json');
    $g->openswoole_response->end(json_encode($payload));
});

$app->route('/ping', fn() => ['pong' => true]);
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'log_level' => 5]);
