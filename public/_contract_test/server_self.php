<?php
// Universal return contract fixture: verify that App::include() auto-populates
// $g->server['PHP_SELF'] / SCRIPT_NAME / SCRIPT_FILENAME before invoking us.
$g = \ZealPHP\RequestContext::instance();
return [
    'php_self'        => $g->server['PHP_SELF']        ?? null,
    'script_name'     => $g->server['SCRIPT_NAME']     ?? null,
    'script_filename' => $g->server['SCRIPT_FILENAME'] ?? null,
];
