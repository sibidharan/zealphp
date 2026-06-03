<?php
// Apache + mod_php compatibility shims at GLOBAL namespace.
//
// These functions are defined by PHP only when running under mod_php (Apache
// SAPI). In CLI / OpenSwoole / PHP-FPM they're missing, which makes legacy
// code (WordPress, Drupal, classic PHP apps) fail with "Call to undefined
// function". `uopz_set_return()` cannot override functions that don't exist, so
// we register conditional global shims that delegate to the namespaced
// implementations in `src/utils.php`.
//
// Every shim is guarded by `function_exists()` so this file is safe to include
// multiple times and in environments where the real Apache extensions ARE loaded.

if (!function_exists('apache_request_headers')) {
    /**
     * Return all HTTP request headers as an associative array.
     * Shim for `apache_request_headers()` / `getallheaders()` on non-Apache SAPIs.
     *
     * @return array<string, string>
     */
    function apache_request_headers(): array {
        return \ZealPHP\apache_request_headers();
    }
}

if (!function_exists('getallheaders')) {
    /**
     * Alias for `apache_request_headers()` — both names exist under mod_php.
     * This shim makes `getallheaders()` available on OpenSwoole / CLI SAPIs.
     *
     * @return array<string, string>
     */
    function getallheaders(): array {
        return \ZealPHP\getallheaders();
    }
}

if (!function_exists('apache_response_headers')) {
    /**
     * Return all response headers queued for the current request.
     * Delegates to `\ZealPHP\apache_response_headers()` which reads from the
     * per-request `$g->zealphp_response` header list.
     *
     * @return array<string, string>
     */
    function apache_response_headers(): array {
        return \ZealPHP\apache_response_headers();
    }
}

if (!function_exists('apache_setenv')) {
    /**
     * Set a named Apache subprocess environment variable.
     * In ZealPHP this writes into the per-request `$g->server` bag under the
     * conventional `HTTP_*` key so subsequent handler code sees the value.
     *
     * The `$walk_to_top` parameter is accepted for API compatibility but has
     * no effect (there is no parent-request scope in OpenSwoole).
     */
    function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool {
        return \ZealPHP\apache_setenv($variable, $value, $walk_to_top);
    }
}

if (!function_exists('apache_getenv')) {
    /**
     * Retrieve a named Apache subprocess environment variable.
     * Returns `false` when the variable is not set (matching Apache's behaviour).
     *
     * The `$walk_to_top` parameter is accepted for API compatibility but has
     * no effect in ZealPHP.
     *
     * @return string|false
     */
    function apache_getenv(string $variable, bool $walk_to_top = false) {
        return \ZealPHP\apache_getenv($variable, $walk_to_top);
    }
}

if (!function_exists('apache_note')) {
    /**
     * Get or set an Apache request note (named annotation attached to the request).
     * When `$note_value` is `null`, returns the current value of the note.
     * When `$note_value` is provided, sets it and returns the previous value (or
     * an empty string when the note was not previously set).
     */
    function apache_note(string $note_name, ?string $note_value = null): string {
        return \ZealPHP\apache_note($note_name, $note_value);
    }
}

if (!function_exists('virtual')) {
    /**
     * Perform an Apache internal sub-request for `$uri`.
     * In ZealPHP this dispatches the URI through the framework's routing stack
     * in-process (similar to Apache's `virtual()` / `mod_include` sub-request
     * mechanism). Returns `true` on success, `false` on failure.
     */
    function virtual(string $uri): bool {
        return \ZealPHP\virtual($uri);
    }
}
