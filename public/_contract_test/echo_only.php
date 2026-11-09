<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: no explicit return, just echo.
$g = \ZealPHP\RequestContext::instance();
echo 'ECHOED:' . ($g->server['PHP_SELF'] ?? 'no-self');
