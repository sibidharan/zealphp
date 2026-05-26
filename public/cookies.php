<?php
if (isset($_GET['set'])) {
    setcookie('test_cookie', 'zealphp_val', time() + 3600);
    echo 'Cookie set';
} else {
    echo 'COOKIES: '; print_r($_COOKIE);
}
