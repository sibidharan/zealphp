<?php
// Mixed-mode test server — booted by tests/Integration/SuperglobalsParityTest.
//
// superglobals(true) + processIsolation(false) + enableCoroutine(false) =
// "drop-in LAMP" lifecycle: $_GET/$_POST/$_SESSION populated like Apache
// mod_php, no per-include CGI fork cost, sequential request handling.
//
// Port is read from $argv[1] so the test can pick a free one and avoid
// colliding with the long-running dev server on :8080.

require_once __DIR__ . '/../../vendor/autoload.php';

use ZealPHP\App;

App::superglobals(true);
App::processIsolation(false);
App::enableCoroutine(false);
App::hookAll(0);
App::documentRoot(__DIR__ . '/../../public');

$port = isset($argv[1]) ? (int) $argv[1] : 8099;
$app  = App::init('127.0.0.1', $port);

// Probe route — returns a JSON blob the test can assert against.
$app->route('/__parity_probe', function () {
    $g = \ZealPHP\RequestContext::instance();
    session_start();
    $_SESSION['hits'] = ($_SESSION['hits'] ?? 0) + 1;
    $g->session['via_g'] = ($g->session['via_g'] ?? 0) + 1;
    return [
        '_GET'          => $_GET,
        '_POST'         => $_POST,
        '_COOKIE_count' => count($_COOKIE),
        '_SERVER_method' => $_SERVER['REQUEST_METHOD'] ?? null,
        '_SERVER_uri'    => $_SERVER['REQUEST_URI']    ?? null,
        '_SERVER_has_host' => isset($_SERVER['HTTP_HOST']),
        '_REQUEST'      => $_REQUEST,
        '_SESSION'      => $_SESSION,
        'g_get'         => $g->get,
        'g_post'        => $g->post,
        'g_session'     => $g->session,
        'g_get_equals_dollar'     => ($g->get == $_GET),
        'g_post_equals_dollar'    => ($g->post == $_POST),
        'g_session_equals_dollar' => ($g->session == $_SESSION),
        'session_is_aliased'      => (
            (function () {
                $g_ctx = \ZealPHP\RequestContext::instance();
                $_SESSION['__alias_test'] = 'set_via_dollar';
                $matches_a = ($g_ctx->session['__alias_test'] ?? null) === 'set_via_dollar';
                $g_ctx->session['__alias_test2'] = 'set_via_g';
                $matches_b = ($_SESSION['__alias_test2'] ?? null) === 'set_via_g';
                return $matches_a && $matches_b;
            })()
        ),
        // v0.2.30 (issue #17) — $g->get must be a LIVE alias of $_GET, not a
        // snapshot. Mutating $_GET after dispatch must show through $g->get
        // and vice versa.
        'get_is_aliased'          => (
            (function () {
                $g_ctx = \ZealPHP\RequestContext::instance();
                $_GET['__alias_dollar'] = 'D';
                $a = ($g_ctx->get['__alias_dollar'] ?? null) === 'D';
                $g_ctx->get['__alias_g'] = 'G';
                $b = ($_GET['__alias_g'] ?? null) === 'G';
                return $a && $b;
            })()
        ),
    ];
});

// POST body parsing — $_POST must be populated for application/x-www-form-urlencoded.
$app->route('/__parity_post', ['methods' => ['POST']], function () {
    $g = \ZealPHP\RequestContext::instance();
    return [
        '_POST'             => $_POST,
        'g_post'            => $g->post,
        'g_post_eq_dollar'  => ($g->post == $_POST),
        '_SERVER_method'    => $_SERVER['REQUEST_METHOD']    ?? null,
        '_SERVER_ct'        => $_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? null,
    ];
});

// session_destroy parity — both names should be cleared.
$app->route('/__parity_destroy', function () {
    $g = \ZealPHP\RequestContext::instance();
    session_start();
    $_SESSION['marker'] = 'before-destroy';
    $before = [
        '_SESSION_has'  => isset($_SESSION['marker']),
        'g_session_has' => isset($g->session['marker']),
    ];
    session_destroy();
    return [
        'before'           => $before,
        '_SESSION_after'   => $_SESSION,
        'g_session_after'  => $g->session,
        'both_empty_after' => (empty($_SESSION) && empty($g->session)),
    ];
});

// session_unset parity — clears data, keeps session id.
$app->route('/__parity_unset', function () {
    $g = \ZealPHP\RequestContext::instance();
    session_start();
    $_SESSION['marker'] = 'pre-unset';
    $g->session['via_g_marker'] = 'pre-unset-via-g';
    session_unset();
    return [
        '_SESSION_after'   => $_SESSION,
        'g_session_after'  => $g->session,
        'both_empty_after' => (empty($_SESSION) && empty($g->session)),
        'session_id_still' => session_id() !== '' ? 'set' : 'empty',
    ];
});

$app->run();
