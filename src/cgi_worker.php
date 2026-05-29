<?php
// ZealPHP CGI Worker — runs PHP files at true global scope for legacy app compatibility.
//
// Usage: `php cgi_worker.php /path/to/file.php`
// Input:  stdin = POST body, env `ZEALPHP_REQUEST_CONTEXT` = JSON context
// Output: stdout = response body, stderr = JSON metadata (one line, sent before body)
//
// Protocol: metadata is written to stderr FIRST (as a single JSON line),
// then body streams to stdout. This enables SSE and streaming responses.
//
// This is a standalone subprocess entry point (not a class), already excluded
// from PHPStan; its body runs in a forked `php cgi_worker.php` process and is
// verified by the integration suite + `tests/Unit/CgiWorkerTest`, not measured
// as a coverage unit.
// @codeCoverageIgnoreStart

ini_set('display_errors', 'stderr');

// Same stderr-pipe-fill deadlock applies in proc mode. PHP 8.4 + heavy vendor
// libraries (phpMyAdmin's Safe, etc.) can emit hundreds of deprecation warnings
// per request to stderr. Parent's stream_get_contents only drains stderr on
// subprocess death, so a flooded stderr blocks the subprocess fwrite. Opt in
// with ZEALPHP_CGI_DEBUG_DEPRECATIONS=1.
if ((string) getenv('ZEALPHP_CGI_DEBUG_DEPRECATIONS') !== '1') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

// Load the Composer autoloader so the included file has the SAME class /
// global-function surface it would in fork mode (which inherits the warm
// worker's autoloader via copy-on-write). Without this, `\ZealPHP\App`,
// the Apache shims, and global helpers are undefined inside proc-mode CGI
// includes — an inconsistency between the two CGI backends (issue #17).
// Two layouts: this file as the repo root (vendor/ is a sibling of src/),
// or installed as a dependency (vendor/sibidharan/zealphp/src/ → the real
// autoloader is three levels up). First existing wins; missing is non-fatal
// (unmodified WordPress/Drupal ships its own bootstrap and needs neither).
// Default OFF — restores v0.2.0 zero-overhead subprocess start (~15 ms vs
// ~30 ms with autoloader loaded). Loading Composer's vendor/autoload.php
// costs measurable time on every subprocess spawn and pulls in the entire
// ZealPHP framework + uopz + apache_shims, none of which unmodified
// WordPress / Drupal / Joomla need. The cost matters for apps like
// WordPress whose wp_cron() fires non-blocking HTTP self-calls with a
// 10 ms timeout — a 30 ms autoload window causes those POSTs to queue
// faster than workers can drain, eventually deadlocking the pool
// (issue #18, the v0.2.41 WP-on-proc regression vs v0.2.0).
//
// Opt in via `App::cgiSubprocessAutoload(true)` if your public/*.php files
// need \ZealPHP\App or framework classes inside the subprocess (modern
// apps built ON ZealPHP, not legacy apps migrated TO it).
if (getenv('ZEALPHP_CGI_AUTOLOAD') === '1') {
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $__z_autoload) {
        if (is_file($__z_autoload)) {
            require_once $__z_autoload;
            break;
        }
    }
}

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

$__z_return_value = null;
$__z_has_return   = false;

function __z_send_meta() {
    global $__z_headers, $__z_cookies, $__z_rawcookies, $__z_status, $__z_meta_sent,
           $__z_return_value, $__z_has_return;
    if ($__z_meta_sent) return;
    $__z_meta_sent = true;
    $payload = [
        'status_code' => $__z_status,
        'headers' => $__z_headers,
        'cookies' => $__z_cookies,
        'rawcookies' => $__z_rawcookies,
    ];
    // Universal return contract: surface the include's return value so the
    // host process can apply the same int/array/string/null treatment that
    // executeFile() does in coroutine mode. Generator returns are streamed
    // inline (already echoed pre-meta) and don't ride this channel.
    if ($__z_has_return) {
        $payload['return_value'] = $__z_return_value;
    }
    fwrite(STDERR, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
}

$z_override = function(string $name, \Closure $cb, bool $execute = true): void {
    if (function_exists('zealphp_override')) {
        if (function_exists('zealphp_restore')) {
            @zealphp_restore($name);
        }
        zealphp_override($name, $cb);
    } elseif (function_exists('uopz_set_return')) {
        uopz_set_return($name, $cb, true);
    }
};

if (function_exists('zealphp_override') || function_exists('uopz_set_return')) {
    $z_override('header', function(string $header, bool $replace = true, int $response_code = 0) {
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
            // CGI/1.1 RFC 3875 §6.3.3: Status header sets response code.
            // mod_cgi parity: ap_scan_script_header_err_brigade_ex() extracts NNN
            // from "Status: NNN Reason" and uses it as the HTTP status code.
            if (strcasecmp($name, 'Status') === 0) {
                $codeStr = strtok($value, ' ');
                if ($codeStr !== false && ctype_digit($codeStr)) {
                    $code = (int)$codeStr;
                    if ($code >= 100 && $code <= 599) {
                        $__z_status = $code;
                    }
                }
                // Store in headers so the host-side cgiSubprocess() can also see it
                // and skip forwarding it to the client (Status: is a CGI meta-header,
                // not an HTTP response header).
                $__z_headers[] = [$name, $value];
                return;
            }
            if ($replace) {
                $__z_headers = array_values(array_filter(
                    $__z_headers,
                    fn($h) => strcasecmp($h[0], $name) !== 0
                ));
            }
            $__z_headers[] = [$name, $value];
        }
    }, true);

    $z_override('header_remove', function(?string $name = null) {
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

    $z_override('headers_list', function() {
        global $__z_headers;
        return array_map(fn($h) => $h[0] . ': ' . $h[1], $__z_headers);
    }, true);

    $z_override('headers_sent', function(&$file = null, &$line = null) {
        return false;
    }, true);

    $z_override('setcookie', function(
        string $name, string $value = '', int|array $expires_or_options = 0,
        string $path = '', string $domain = '', bool $secure = false,
        bool $httponly = false, string $samesite = ''
    ) {
        global $__z_cookies;
        if (is_array($expires_or_options)) {
            $o = $expires_or_options;
            $__z_cookies[] = [
                $name, $value,
                (int)($o['expires'] ?? 0),
                (string)($o['path'] ?? ''),
                (string)($o['domain'] ?? ''),
                (bool)($o['secure'] ?? false),
                (bool)($o['httponly'] ?? false),
            ];
        } else {
            $__z_cookies[] = [$name, $value, $expires_or_options, $path, $domain, $secure, $httponly];
        }
        return true;
    }, true);

    $z_override('setrawcookie', function(
        string $name, string $value = '', int|array $expires_or_options = 0,
        string $path = '', string $domain = '', bool $secure = false,
        bool $httponly = false
    ) {
        global $__z_rawcookies;
        if (is_array($expires_or_options)) {
            $o = $expires_or_options;
            $__z_rawcookies[] = [
                $name, $value,
                (int)($o['expires'] ?? 0),
                (string)($o['path'] ?? ''),
                (string)($o['domain'] ?? ''),
                (bool)($o['secure'] ?? false),
                (bool)($o['httponly'] ?? false),
            ];
        } else {
            $__z_rawcookies[] = [$name, $value, $expires_or_options, $path, $domain, $secure, $httponly];
        }
        return true;
    }, true);

    $z_override('http_response_code', function($code = null) {
        global $__z_status;
        if ($code !== null) $__z_status = (int)$code;
        return $__z_status;
    }, true);

    // flush() — send metadata on first call, then flush ob buffer to stdout
    $z_override('flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    // ob_end_flush / ob_flush — same streaming behavior
    $z_override('ob_end_flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    $z_override('ob_flush', function() {
        __z_send_meta();
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            fwrite(STDOUT, $data);
            fflush(STDOUT);
        }
        ob_start();
    }, true);

    $z_override('ob_implicit_flush', function($enable = true) {
        // no-op: streaming is driven by flush()/ob_flush() calls explicitly
    }, true);

    $z_override('is_uploaded_file', function(string $filename) {
        global $__z_uploaded;
        return isset($__z_uploaded[$filename]);
    }, true);

    $z_override('move_uploaded_file', function(string $from, string $to) {
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
    $__z_result = include $__z_file;
    $__z_has_return = true;
    // Closure return: invoke with no args (param injection doesn't cross the
    // process boundary). The result of the invocation is what we surface — if
    // it's itself a Generator, fall through to the streaming branch.
    if ($__z_result instanceof \Closure) {
        $__z_result = $__z_result();
    }
    if ($__z_result instanceof \Generator) {
        // Consume the generator inside this process — each chunk is echoed
        // (and streamed back via the existing flush() override on the host
        // side). The return_value is then null so the host doesn't double-up.
        foreach ($__z_result as $__z_chunk) {
            echo (string)$__z_chunk;
        }
        $__z_result = null;
    }
    // Skip non-serialisable returns (resources, raw objects) — host can't
    // make sense of them anyway. Coerce to JSON-safe shape.
    if (is_resource($__z_result) || (is_object($__z_result) && !($__z_result instanceof \JsonSerializable) && !($__z_result instanceof \stdClass))) {
        $__z_result = null;
    }
    $__z_return_value = $__z_result;
} catch (\Throwable $__z_err) {
    $__z_status = 500;
    echo '<pre>' . htmlspecialchars($__z_err->getMessage()) . "\n" . htmlspecialchars($__z_err->getTraceAsString()) . '</pre>';
}

// @codeCoverageIgnoreEnd
