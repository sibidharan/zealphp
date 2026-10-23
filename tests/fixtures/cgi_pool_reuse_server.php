<?php
// CGI-pool reuse test server — booted by tests/Integration/CgiPoolReuseIsolationTest.
//
// processIsolation(true) + cgiMode('pool') with ONE OpenSwoole worker, ONE pool
// subprocess and a recycle limit above 1, so consecutive requests are served by
// the same reused subprocess. That is the configuration where one visitor's
// session (H1) or stray stdout bytes (H2) could reach the next visitor.
//
// Document root comes from ZEALPHP_TEST_PUBLIC_DIR; port from $argv[1].

require_once __DIR__ . '/../../vendor/autoload.php';

use ZealPHP\App;

App::superglobals(true);
App::processIsolation(true);
App::cgiMode('pool');
App::cgiPoolSize(1);
App::cgiPoolMaxRequests(100);
App::enableCoroutine(false);
App::hookAll(0);
App::ignorePhpExt(false);

$docRoot = getenv('ZEALPHP_TEST_PUBLIC_DIR');
if (!is_string($docRoot) || !is_dir($docRoot)) {
    fwrite(STDERR, "cgi_pool_reuse_server: ZEALPHP_TEST_PUBLIC_DIR env var missing or not a dir\n");
    exit(2);
}
App::documentRoot($docRoot);

$port = isset($argv[1]) ? (int) $argv[1] : 9851;
$app  = App::init('127.0.0.1', $port);

$pidFile = sys_get_temp_dir() . '/zealphp-cgi-pool-reuse-' . $port . '.pid';
$app->run(['worker_num' => 1, 'task_worker_num' => 0, 'pid_file' => $pidFile]);
