<?php
use ZealPHP\G;

${basename(__FILE__, '.php')} = function () {
    session_start();
    $g = G::instance();
    $g->session = [];
    session_destroy();
    header('Location: /learn/notes');
    http_response_code(302);
};
