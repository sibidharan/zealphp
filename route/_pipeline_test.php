<?php
/**
 * Test route for tests/Integration/PipeliningOrderTest.php — sleeps for ?ms
 * then echoes ?n, so pipelined requests with decreasing delays would finish
 * out of order if the server answered in completion order.
 */

use ZealPHP\App;
use ZealPHP\RequestContext;

$app = App::instance();

$app->route('/__pipeline_test/echo', function () {
    $g = RequestContext::instance();
    $ms = max(0, min(500, (int) ($g->get['ms'] ?? 0)));
    \OpenSwoole\Coroutine::usleep($ms * 1000);
    return ['n' => (string) ($g->get['n'] ?? '')];
});
