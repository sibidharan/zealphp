<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
session_start();
$user = $_GET['user'] ?? 'Guest';
$_SESSION['last_user'] = $user;
header('Content-Type: text/plain');
echo "Hello $user!\n";
echo "Last user: " . ($_SESSION['last_user'] ?? 'None') . "\n";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
