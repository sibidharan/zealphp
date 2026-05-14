<?php
$g = \ZealPHP\G::instance();
header('Content-Type: application/json');
echo json_encode([
    'path_info'       => $g->server['PATH_INFO']       ?? null,
    'path_translated' => $g->server['PATH_TRANSLATED'] ?? null,
    'script_name'     => $g->server['SCRIPT_NAME']     ?? null,
]);
