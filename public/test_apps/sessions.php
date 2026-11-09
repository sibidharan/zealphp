<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
session_start();
if (!isset($_SESSION['count'])) $_SESSION['count'] = 0;
$_SESSION['count']++;
echo 'Session Count: ' . $_SESSION['count'];
