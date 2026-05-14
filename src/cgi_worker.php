<?php
// ZealPHP CGI Worker — runs PHP files at true global scope for legacy app compatibility.
//
// Usage: php cgi_worker.php /path/to/file.php
// Input:  stdin = POST body, env ZEALPHP_REQUEST_CONTEXT = JSON context
// Output: stdout = response body, stderr = JSON metadata (one line, sent before body)
//
// Protocol: metadata is written to stderr FIRST (as a single JSON line),
// then body streams to stdout. This enables SSE and streaming responses.

$__z_ctx = json_decode(getenv('ZEALPHP_REQUEST_CONTEXT') ?: '{}', true);

$_SERVER  = array_merge($_SERVER, $__z_ctx['server'] ?? []);
$_GET     = $__z_ctx['get'] ?? [];
$_POST    = $__z_ctx['post'] ?? [];
$_COOKIE  = $__z_ctx['cookie'] ?? [];
$_FILES   = $__z_ctx['files'] ?? [];
$_ENV     = array_merge($_ENV ?? [], $__z_ctx['env'] ?? []);
$_REQUEST = array_merge($_GET, $_POST);

$__z_headers = [];
$__z_cookies = [];
$__z_rawcookies = [];
$__z_status = 200;
$__z_meta_sent = false;
$__z_apache_env = [];
$__z_apache_notes = [];
$__z_uploaded = [];
foreach ($_FILES as $entry) {
    if (!is_array($entry)) continue;
    $tmp = $entry['tmp_name'] ?? null;
    if (is_array($tmp)) {
        foreach ($tmp as $t) { if (is_string($t)) $__z_uploaded[$t] = true; }
    } elseif (is_string($tmp)) {
        $__z_uploaded[$tmp] = true;
    }
}

function __z_send_meta() {
    global $__z_headers, $__z_cookies, $__z_rawcookies, $__z_status, $__z_meta_sent;
    if ($__z_meta_sent) return;
    $__z_meta_sent = true;
    fwrite(STDERR, json_encode([
        'status_code' => $__z_status,
        'headers' => $__z_headers,
        'cookies' => $__z_cookies,
        'rawcookies' => $__z_rawcookies,
    ], JSON_UNESCAPED_SLASHES) . "\n");
}

if (function_exists('uopz_set_return')) {
    uopz_set_return('header', function(string $header, bool $replace = true, int $response_code = 0) {
        global $__z_headers, $__z_status;
        if ($response_code > 0) $__z_status = $response_code;
        if (stripos($header, 'HTTP/') === 0) {
            preg_match('/\d{3}/', $header, $m);
            if ($m) $__z_status = (int)$m[0];
            return;
        }
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($replace) {
                $__z_headers = array_values(array_filter(
                    $__z_headers,
                    fn($h) => strcasecmp($h[0], $name) !== 0
                ));
            }
            $__z_headers[] = [$name, $value];
        }
    }, true);

    uopz_set_return('header_remove', function(?string $name = null) {
        global $__z_headers;
        if ($name === null) {
            $__z_headers = [];
        } else {
            $__z_headers = array_values(array_filter(
                $__z_headers,
                fn($h) => strcasecmp($h[0], $name) !== 0
            ));
        }
    }, true);

    uopz_set_return('headers_list', function() {
        global $__z_headers;
        return array_map(fn($h) => $h[0] . ': ' . $h[1], $__z_headers);
    }, true);

    uopz_set_return('headers_sent', function(&$file = null, &$line = null) {
        return false;
    }, true);

    uopz_set_return('setcookie', function(
        string $name, string $value = '', $expires_or_options = 0,
        string $path = '', string $domain = '', bool $secure = false,
        bool $httponly = false, string $samesite = ''
    ) {
        global $__z_cookies;
        $__z_cookies[] = [$name, $value, $expires_or_options, $path, $domain, $secure, $httponly];
        return true;
    }, true);

    uopz_set_return('setrawcookie', function(
        string $name, string $value = '', $expires_or_options = 0,
        string $path = '', string $domain = '', bool $secure = false,
        bool $httponly = false
    ) {
        global $__z_rawcookies;
        $__z_rawcookies[] = [$name, $value, $expires_or_options, $path, $domain, $secure, $httponly];
        return true;
    }, true);

    uopz_set_return('http_response_code', function($code = null) {
        global $__z_status;
        if ($code !== null) $__z_status = (int)$code;
        return $__z_status;
    }, true);

    // flush() — send metadata on first call, then flush ob buffer to stdout
    uopz_set_return('flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    // ob_end_flush / ob_flush — same streaming behavior
    uopz_set_return('ob_end_flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    uopz_set_return('ob_flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    uopz_set_return('ob_implicit_flush', function($enable = true) {
        // no-op: streaming is driven by flush()/ob_flush() calls explicitly
    }, true);

    uopz_set_return('is_uploaded_file', function(string $filename) {
        global $__z_uploaded;
        return isset($__z_uploaded[$filename]);
    }, true);

    uopz_set_return('move_uploaded_file', function(string $from, string $to) {
        global $__z_uploaded;
        if (!isset($__z_uploaded[$from])) return false;
        if (@rename($from, $to)) {
            unset($__z_uploaded[$from]);
            return true;
        }
        if (@copy($from, $to)) {
            @unlink($from);
            unset($__z_uploaded[$from]);
            return true;
        }
        return false;
    }, true);
}

// Apache mod_php functions are not defined in CLI SAPI; define them globally
// here for the duration of the subprocess so legacy code runs unchanged.
if (!function_exists('apache_request_headers')) {
    function apache_request_headers(): array {
        $out = [];
        foreach ($_SERVER as $name => $value) {
            if (strncmp($name, 'HTTP_', 5) === 0) {
                $canonical = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                $out[$canonical] = $value;
            } elseif ($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') {
                $canonical = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower($name))));
                $out[$canonical] = $value;
            }
        }
        return $out;
    }
}

if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        return apache_request_headers();
    }
}

if (!function_exists('apache_response_headers')) {
    function apache_response_headers(): array {
        global $__z_headers;
        $out = [];
        foreach ($__z_headers as $pair) {
            $out[$pair[0]] = $pair[1];
        }
        return $out;
    }
}

if (!function_exists('apache_setenv')) {
    function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool {
        global $__z_apache_env;
        $__z_apache_env[$variable] = $value;
        return true;
    }
}

if (!function_exists('apache_getenv')) {
    function apache_getenv(string $variable, bool $walk_to_top = false) {
        global $__z_apache_env;
        return $__z_apache_env[$variable] ?? false;
    }
}

if (!function_exists('apache_note')) {
    function apache_note(string $note_name, ?string $note_value = null): string {
        global $__z_apache_notes;
        $previous = (string)($__z_apache_notes[$note_name] ?? '');
        if ($note_value !== null) {
            $__z_apache_notes[$note_name] = $note_value;
        }
        return $previous;
    }
}

if (!function_exists('virtual')) {
    function virtual(string $uri): bool {
        // No internal-subrequest support — silently return false.
        return false;
    }
}

set_error_handler(function($severity, $message, $file, $line) {
    $label = match($severity) {
        E_WARNING, E_USER_WARNING => 'Warning',
        E_NOTICE, E_USER_NOTICE => 'Notice',
        E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
        default => 'Error',
    };
    echo "<br>\n<b>{$label}</b>: {$message} in <b>{$file}</b> on line <b>{$line}</b><br>\n";
    return true;
});

$__z_file = $argv[1] ?? null;
if (!$__z_file || !file_exists($__z_file)) {
    fwrite(STDERR, json_encode(['status_code' => 404, 'headers' => [], 'cookies' => [], 'rawcookies' => []]) . "\n");
    echo '<pre>404 Not Found</pre>';
    exit(1);
}

$__z_cwd = getenv('ZEALPHP_CWD');
if ($__z_cwd) chdir($__z_cwd);

register_shutdown_function(function() {
    global $__z_meta_sent;
    __z_send_meta();
    $output = ob_get_clean();
    if ($output !== false && $output !== '') {
        fwrite(STDOUT, $output);
    }
    fflush(STDOUT);
});

ob_start();

try {
    include $__z_file;
} catch (\Throwable $__z_err) {
    $__z_status = 500;
    echo '<pre>' . htmlspecialchars($__z_err->getMessage()) . "\n" . htmlspecialchars($__z_err->getTraceAsString()) . '</pre>';
}
