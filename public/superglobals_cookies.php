<?php
if (isset($_GET['set'])) {
    setcookie('test', '123', time()+3600);
    echo 'SET';
} else {
    echo 'COOKIE: ' . ($_COOKIE['test'] ?? 'NONE');
}
