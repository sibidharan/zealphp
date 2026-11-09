<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: verify that App::include() auto-populates
// $g->server['PHP_SELF'] / SCRIPT_NAME / SCRIPT_FILENAME before invoking us.
$g = \ZealPHP\RequestContext::instance();
return [
    'php_self'        => $g->server['PHP_SELF']        ?? null,
    'script_name'     => $g->server['SCRIPT_NAME']     ?? null,
    'script_filename' => $g->server['SCRIPT_FILENAME'] ?? null,
];
