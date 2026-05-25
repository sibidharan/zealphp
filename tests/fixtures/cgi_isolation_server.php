<?php
// CGI-isolation test server — booted by tests/Integration/CgiSessionPersistenceTest.
//
// superglobals(true) + processIsolation(true) — the configuration in which
// issue #108 was filed. Defaults to cgiMode('pool') (the new default since
// v0.2.42). Each include is dispatched to the worker pool subprocess.
//
// Document root points at the per-test public dir created by the test class
// (via ZEALPHP_TEST_PUBLIC_DIR env var). The test writes set.php / get.php
// fixtures into that dir, then drives the server over HTTP with curl.
//
// Port is read from $argv[1] so the test picks a free one and avoids
// colliding with the long-running dev server on :8080.

require_once __DIR__ . '/../../vendor/autoload.php';

use ZealPHP\App;

App::superglobals(true);
App::processIsolation(true);
App::enableCoroutine(false);
App::hookAll(0);
// Allow `/set.php` / `/get.php` to route through implicit public-file dispatch
// — the reporter's exact URLs from issue #108. Default $ignore_php_ext = true
// would 403 these.
App::ignorePhpExt(false);

$docRoot = getenv('ZEALPHP_TEST_PUBLIC_DIR');
if (!is_string($docRoot) || !is_dir($docRoot)) {
    fwrite(STDERR, "cgi_isolation_server: ZEALPHP_TEST_PUBLIC_DIR env var missing or not a dir\n");
    exit(2);
}
App::documentRoot($docRoot);

$port = isset($argv[1]) ? (int) $argv[1] : 8099;
$app  = App::init('127.0.0.1', $port);

// Per-test pid_file in a writable dir — /tmp/zealphp/ may be root-owned from
// a prior dev-server run and reject our write.
$pidFile = sys_get_temp_dir() . '/zealphp-cgi-iso-' . $port . '.pid';
$app->run(['pid_file' => $pidFile]);
