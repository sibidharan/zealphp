<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
if (isset($_GET['set'])) {
    setcookie('test_cookie', 'zealphp_val', time() + 3600);
    echo 'Cookie set';
} else {
    echo 'COOKIES: '; print_r($_COOKIE);
}
