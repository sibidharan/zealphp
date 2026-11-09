<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Coroutine state isolation fixture: read $g->get['cid'] (set by the route
// just before include()'d) and echo it back. Under concurrent load each
// coroutine should see its OWN cid — proving $g is per-coroutine isolated
// in coroutine mode.
$g = \ZealPHP\RequestContext::instance();
header('Content-Type: text/plain');
return 'CID:' . ($g->get['cid'] ?? 'none');
