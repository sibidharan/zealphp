<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: return an array for JSON serialisation.
return ['ok' => true, 'who' => 'include-array'];
