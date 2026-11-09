<?php
// Test/debug fixture (prints request or process state): not served unless
// ZEALPHP_TEST_ROUTES is set. Public, it printed the server's environment.
if (!\ZealPHP\env_flag('ZEALPHP_TEST_ROUTES', false)) {
    return 404;
}
if ($_FILES) {
    echo 'FILES: '; print_r($_FILES);
} else {
    echo '<form method="POST" enctype="multipart/form-data"><input type="file" name="test"><input type="submit"></form>';
}
