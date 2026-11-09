<?php
// Integration-test fixture: not served unless ZEALPHP_TEST_ROUTES is set.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
// Universal return contract fixture: echo + explicit string return →
// concat (preserves wire order, "shell first then body").
echo 'A';
return 'B';
