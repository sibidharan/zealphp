<?php
// Apache+mod_php compatibility shims at GLOBAL namespace.
//
// These functions are defined by PHP only when running under mod_php (Apache
// SAPI). In CLI / OpenSwoole / PHP-FPM they're missing, which makes legacy
// code (WordPress, Drupal, classic PHP apps) fail with "Call to undefined
// function". uopz_set_return cannot override functions that don't exist, so
// we register conditional global shims that delegate to the namespaced
// implementations in src/utils.php.

if (!function_exists('apache_request_headers')) {
    /** @return array<string, string> */
    function apache_request_headers(): array {
        return \ZealPHP\apache_request_headers();
    }
}

if (!function_exists('getallheaders')) {
    /** @return array<string, string> */
    function getallheaders(): array {
        return \ZealPHP\getallheaders();
    }
}

if (!function_exists('apache_response_headers')) {
    /** @return array<string, string> */
    function apache_response_headers(): array {
        return \ZealPHP\apache_response_headers();
    }
}

if (!function_exists('apache_setenv')) {
    function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool {
        return \ZealPHP\apache_setenv($variable, $value, $walk_to_top);
    }
}

if (!function_exists('apache_getenv')) {
    /** @return string|false */
    function apache_getenv(string $variable, bool $walk_to_top = false) {
        return \ZealPHP\apache_getenv($variable, $walk_to_top);
    }
}

if (!function_exists('apache_note')) {
    function apache_note(string $note_name, ?string $note_value = null): string {
        return \ZealPHP\apache_note($note_name, $note_value);
    }
}

if (!function_exists('virtual')) {
    function virtual(string $uri): bool {
        return \ZealPHP\virtual($uri);
    }
}
