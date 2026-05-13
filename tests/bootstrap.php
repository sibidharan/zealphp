<?php
require_once __DIR__ . '/../vendor/autoload.php';

define('ZEALPHP_ROOT', dirname(__DIR__));
define('TEST_SERVER_HOST', '127.0.0.1');
// Use port from ZEALPHP_TEST_PORT env var if set, otherwise default to 8080
define('TEST_SERVER_PORT', (int)(getenv('ZEALPHP_TEST_PORT') ?: 8080));
define('TEST_SERVER_URL', 'http://' . TEST_SERVER_HOST . ':' . TEST_SERVER_PORT);
