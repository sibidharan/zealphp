<?php
/**
 * mod_php parity — verification routes.
 *
 * Exercises the uopz-overridden built-ins so the integration suite can confirm
 * they behave like Apache+mod_php through a real request:
 *   - filter_input() reads request input from $g (native returns null under CLI)
 *   - php_sapi_name() reports the configured SAPI (default: real PHP_SAPI)
 * All routes live under /parity/* to avoid clashing with existing routes.
 */

use ZealPHP\App;

$app = App::instance();

// filter_input(INPUT_GET, 'n', FILTER_VALIDATE_INT) — returns the filtered value.
// ?n=42 -> 42 (int), ?n=nope -> false, missing -> null.
$app->route('/parity/filter-input', ['methods' => ['GET']], function () {
    $n = filter_input(INPUT_GET, 'n', FILTER_VALIDATE_INT);
    return ['n' => $n, 'type' => gettype($n)];
});

// php_sapi_name() through the override (default config reports real PHP_SAPI).
$app->route('/parity/sapi', ['methods' => ['GET']], function () {
    return ['sapi' => php_sapi_name()];
});
