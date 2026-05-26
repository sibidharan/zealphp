<?php
set_error_handler(function($errno, $errstr) {
    echo "Caught Error: [$errno] $errstr\n";
});
register_shutdown_function(function() {
    echo "Shutdown function executed\n";
});
trigger_error('Test User Error', E_USER_NOTICE);
echo "Main execution finished\n";
