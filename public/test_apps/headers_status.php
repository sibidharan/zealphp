<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
header('X-ZealPHP: rocks');
http_response_code(201);
echo 'Custom header and 201 status set';
