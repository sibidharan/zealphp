<?php
echo json_encode([
    'GET' => $_GET,
    'POST' => $_POST,
    'METHOD' => $_SERVER['REQUEST_METHOD'],
    'URI' => $_SERVER['REQUEST_URI']
]);
