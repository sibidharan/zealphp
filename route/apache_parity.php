<?php
/**
 * Apache+mod_php parity test routes — exercise overrides and request shims.
 * Used by tests/Integration/ApacheParityTest.php.
 */

use ZealPHP\App;

$app = App::instance();

// apache_request_headers() / getallheaders() — canonical hyphen-case keys.
$app->route('/parity/request-headers', ['methods' => ['GET']], function() {
    return [
        'apache_request_headers' => apache_request_headers(),
        'getallheaders'          => getallheaders(),
    ];
});

// apache_response_headers() — read what's been set so far.
$app->route('/parity/response-headers', ['methods' => ['GET']], function() {
    header('X-Test-Custom: alpha');
    header('X-Another-Header: beta');
    return ['response_headers' => apache_response_headers()];
});

// header_remove() — clear one header that was previously set.
$app->route('/parity/header-remove', ['methods' => ['GET']], function() {
    header('X-Should-Stay: kept');
    header('X-Should-Go: removed');
    header_remove('X-Should-Go');
    return ['ok' => true];
});

// headers_sent() — should be false during normal handler execution.
$app->route('/parity/headers-sent', ['methods' => ['GET']], function() {
    return ['sent' => headers_sent()];
});

// setrawcookie — value should NOT be urlencoded.
$app->route('/parity/setrawcookie', ['methods' => ['GET']], function() {
    setrawcookie('rawck', 'a b+c/d', 0, '/');
    setcookie('regularck', 'a b+c/d', 0, '/');
    return ['ok' => true];
});

// header() with HTTP/x.x status line — Apache form.
$app->route('/parity/header-status', ['methods' => ['GET']], function() {
    header('HTTP/1.1 418 I am a teapot');
    return ['ok' => true];
});

// header() with explicit response code parameter.
$app->route('/parity/header-code', ['methods' => ['GET']], function() {
    header('X-Whatever: 1', true, 503);
    return ['ok' => true];
});

// apache_setenv / apache_getenv / apache_note — per-request key/value scratch.
$app->route('/parity/apache-env', ['methods' => ['GET']], function() {
    apache_setenv('FOO', 'bar');
    apache_note('greet', 'hello');
    return [
        'foo'   => apache_getenv('FOO'),
        'greet' => apache_note('greet'),
    ];
});

// virtual() — returns false (unsupported) without crashing.
$app->route('/parity/virtual', ['methods' => ['GET']], function() {
    $rc = virtual('/anything');
    return ['returned' => $rc];
});

// set_time_limit / ignore_user_abort / connection_status — no-op success.
$app->route('/parity/safe-stubs', ['methods' => ['GET']], function() {
    return [
        'set_time_limit'    => set_time_limit(30),
        'ignore_user_abort' => ignore_user_abort(true),
        'connection_status' => connection_status(),
        'connection_aborted'=> connection_aborted(),
    ];
});

// is_uploaded_file — fake path must be rejected; real $_FILES tmp would pass.
$app->route('/parity/is-uploaded', ['methods' => ['GET']], function() {
    return ['forged' => is_uploaded_file('/etc/passwd')];
});

// ob_flush mid-handler — should stream partial output.
$app->route('/parity/ob-flush', ['methods' => ['GET']], function() {
    echo "first-chunk\n";
    ob_flush();
    echo "second-chunk\n";
    ob_flush();
    echo "third-chunk\n";
});

// PATH_INFO route — used only when App::$path_info = true.
$app->route('/parity/path-info', ['methods' => ['GET']], function() {
    $g = \ZealPHP\G::instance();
    return [
        'path_info'       => $g->server['PATH_INFO']       ?? null,
        'path_translated' => $g->server['PATH_TRANSLATED'] ?? null,
        'request_uri'     => $g->server['REQUEST_URI']     ?? null,
    ];
});
