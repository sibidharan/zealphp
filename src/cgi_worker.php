<?php
// ZealPHP CGI Worker — runs PHP files at true global scope for legacy app compatibility.
// Solves the fundamental PHP scoping issue where `include` inside a closure puts
// variables in the closure scope instead of $GLOBALS, breaking WordPress admin.
//
// Usage: php cgi_worker.php /path/to/file.php
// Input:  stdin = POST body, env ZEALPHP_REQUEST_CONTEXT = JSON context
// Output: stdout = response body, stderr = JSON metadata

$__z_ctx = json_decode(getenv('ZEALPHP_REQUEST_CONTEXT') ?: '{}', true);

$_SERVER = array_merge($_SERVER, $__z_ctx['server'] ?? []);
$_GET    = $__z_ctx['get'] ?? [];
$_POST   = $__z_ctx['post'] ?? [];
$_COOKIE = $__z_ctx['cookie'] ?? [];
$_FILES  = $__z_ctx['files'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);

$__z_headers = [];
$__z_cookies = [];
$__z_rawcookies = [];
$__z_status = 200;

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

    uopz_set_return('setcookie', function(
        string $name, string $value = '', $expires_or_options = 0,
        string $path = '', string $domain = '', bool $secure = false,
        bool $httponly = false, string $samesite = ''
    ) {
        global $__z_cookies;
        $__z_cookies[] = [$name, $value, $expires_or_options, $path, $domain, $secure, $httponly];
        return true;
    }, true);

    uopz_set_return('http_response_code', function($code = null) {
        global $__z_status;
        if ($code !== null) $__z_status = (int)$code;
        return $__z_status;
    }, true);

    uopz_set_return('headers_sent', function(&$file = null, &$line = null) {
        return false;
    }, true);
}

$__z_file = $argv[1] ?? null;
if (!$__z_file || !file_exists($__z_file)) {
    fwrite(STDERR, json_encode(['status_code' => 404, 'headers' => [], 'cookies' => [], 'rawcookies' => []]));
    echo '<pre>404 Not Found</pre>';
    exit(1);
}

$__z_cwd = getenv('ZEALPHP_CWD');
if ($__z_cwd) chdir($__z_cwd);

register_shutdown_function(function() {
    global $__z_status, $__z_headers, $__z_cookies, $__z_rawcookies;
    $output = ob_get_clean();
    if ($output === false) $output = '';
    fwrite(STDOUT, $output);
    fwrite(STDERR, json_encode([
        'status_code' => $__z_status,
        'headers' => $__z_headers,
        'cookies' => $__z_cookies,
        'rawcookies' => $__z_rawcookies ?? [],
    ], JSON_UNESCAPED_SLASHES));
});

ob_start();

try {
    include $__z_file;
} catch (\Throwable $__z_err) {
    $__z_status = 500;
    echo '<pre>' . htmlspecialchars($__z_err->getMessage()) . "\n" . htmlspecialchars($__z_err->getTraceAsString()) . '</pre>';
}
