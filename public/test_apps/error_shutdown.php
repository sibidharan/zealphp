<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
set_error_handler(function($errno, $errstr) {
    echo "Caught Error: [$errno] $errstr\n";
});
register_shutdown_function(function() {
    echo "Shutdown function executed\n";
});
trigger_error('Test User Error', E_USER_NOTICE);
echo "Main execution finished\n";
