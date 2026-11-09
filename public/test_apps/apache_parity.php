<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
echo 'Request Headers: '; print_r(apache_request_headers());
echo 'All Headers: '; print_r(getallheaders());
