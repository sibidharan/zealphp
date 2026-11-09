<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
ob_start();
echo 'Buffered text';
$content = ob_get_clean();
echo 'Cleaned: ' . $content;
flush();
echo ' Flushed text';
