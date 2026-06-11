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
// or installed as a dependency (vendor/zealphp/zealphp/src/ → the real
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
// #357 — single header_register_callback() slot (mod_php keeps one). The
// registered callback is fired by __z_fire_header_callback() right after the
// included file finishes (while the header() override is still active), so
// header()/header_remove() calls inside it land in $__z_headers.
$__z_header_callback = null;
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

/**
 * #357 — fire a registered header_register_callback() exactly once (mod_php
 * keeps a single callback). Two registration sources, drained in order:
 *   (1) the subprocess-local override above, which stashes into
 *       $GLOBALS['__z_header_callback'] (the common case — autoload off);
 *   (2) the framework's utils.php header_register_callback(), active when
 *       ZEALPHP_CGI_AUTOLOAD=1, which stashes into the RequestContext memo.
 *
 * MUST be called while the header() override is still active (right after the
 * included file finishes) — NOT from the shutdown function: uopz tears its
 * overrides down before register_shutdown_function runs, so header() calls
 * inside a callback fired at shutdown would not be captured into $__z_headers.
 */
function __z_fire_header_callback(): void
{
    global $__z_header_callback;
    if (!is_callable($__z_header_callback)
        && class_exists(\ZealPHP\RequestContext::class, false)
    ) {
        try {
            $__z_g = \ZealPHP\RequestContext::instance();
            $__z_memoCb = $__z_g->memo['_header_callback'] ?? null;
            if (is_callable($__z_memoCb)) {
                unset($__z_g->memo['_header_callback']);
                $__z_header_callback = $__z_memoCb;
            }
        } catch (\Throwable) {
            // RequestContext unavailable — fall through with no callback.
        }
    }
    if (is_callable($__z_header_callback)) {
        $__z_cb = $__z_header_callback;
        $__z_header_callback = null; // single callback, fired once
        try {
            $__z_cb();
        } catch (\Throwable $__z_cbErr) {
            // A misbehaving callback must never abort the response.
            fwrite(STDERR, 'header_register_callback threw: ' . $__z_cbErr->getMessage() . "\n");
        }
    }
}

/**
 * Write the metadata frame (status, headers, cookies, optional return value) to `STDERR`
 * as a single JSON line. Idempotent — subsequent calls are no-ops once `$__z_meta_sent`
 * is `true`. Called by the `flush()` override and the shutdown function so the frame is
 * always sent before the body, regardless of whether the included file streams or buffers.
 */
function __z_send_meta() {
    global $__z_headers, $__z_cookies, $__z_rawcookies, $__z_status, $__z_meta_sent,
           $__z_return_value, $__z_has_return, $__z_header_callback;
    if ($__z_meta_sent) return;
    // #357 — make sure any registered header_register_callback() has fired
    // before we snapshot the headers (no-op if it already ran right after the
    // include; the streaming/flush path reaches __z_send_meta() without that
    // explicit call).
    __z_fire_header_callback();
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

    // #357 — header_register_callback() parity: store the callback in a
    // subprocess-local slot. __z_fire_header_callback() invokes it right after
    // the included file finishes (NOT at shutdown — uopz tears its overrides
    // down before register_shutdown_function runs, so a header() inside the
    // callback fired at shutdown would not be captured). header() inside the
    // callback is a normal call into the still-active override.
    $z_override('header_register_callback', function(callable $callback): bool {
        $GLOBALS['__z_header_callback'] = $callback;
        return true;
    }, true);

    $z_override('headers_sent', function(&$file = null, &$line = null) {
        return false;
    }, true);

    // mod_php-parity filter_input()/filter_input_array() (#316): this
    // subprocess runs under the CLI SAPI, whose internal SAPI request tables
    // are EMPTY — native filter_input() returns null even though
    // $_GET/$_POST/$_COOKIE/$_SERVER are fully populated from the IPC context
    // above. Resolve the INPUT_* bag from the LIVE superglobals and delegate
    // to filter_var()/filter_var_array(), mirroring the main worker's
    // \ZealPHP\filter_input override (App.php overrideBuiltin wiring).
    $__z_filter_bag = function (int $type): array {
        $bag = match ($type) {
            INPUT_GET    => $_GET,
            INPUT_POST   => $_POST,
            INPUT_COOKIE => $_COOKIE,
            INPUT_SERVER => $_SERVER,
            INPUT_ENV    => $_ENV,
            default      => [],
        };
        return is_array($bag) ? $bag : [];
    };

    $z_override('filter_input', function (int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0) use ($__z_filter_bag): mixed {
        $bag = $__z_filter_bag($type);
        if (!array_key_exists($var_name, $bag)) {
            return null;
        }
        return filter_var($bag[$var_name], $filter, $options);
    }, true);

    $z_override('filter_input_array', function (int $type, array|int $options = FILTER_DEFAULT, bool $add_empty = true) use ($__z_filter_bag): array|false|null {
        return filter_var_array($__z_filter_bag($type), $options, $add_empty);
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
    /**
     * Polyfill for `apache_request_headers()` in CLI SAPI.
     * Reconstructs the canonical header map from `$_SERVER` `HTTP_*` keys plus
     * `CONTENT_TYPE` and `CONTENT_LENGTH`, matching Apache `mod_php` behaviour.
     *
     * @return array<string, string> Map of header name → value.
     */
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
    /**
     * Polyfill for `getallheaders()` in CLI SAPI — delegates to `apache_request_headers()`.
     *
     * @return array<string, string> Map of header name → value.
     */
    function getallheaders(): array {
        return apache_request_headers();
    }
}

if (!function_exists('apache_response_headers')) {
    /**
     * Polyfill for `apache_response_headers()` in CLI SAPI.
     * Returns the response headers collected so far by the `header()` override.
     *
     * @return array<string, string> Map of header name → value.
     */
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
    /**
     * Polyfill for `apache_setenv()` in CLI SAPI.
     * Stores `$value` in the subprocess-local `$__z_apache_env` map (Apache `mod_env` parity).
     */
    function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool {
        global $__z_apache_env;
        $__z_apache_env[$variable] = $value;
        return true;
    }
}

if (!function_exists('apache_getenv')) {
    /**
     * Polyfill for `apache_getenv()` in CLI SAPI.
     * Reads from `$__z_apache_env`; returns `false` when the variable is not set.
     */
    function apache_getenv(string $variable, bool $walk_to_top = false) {
        global $__z_apache_env;
        return $__z_apache_env[$variable] ?? false;
    }
}

if (!function_exists('apache_note')) {
    /**
     * Polyfill for `apache_note()` in CLI SAPI.
     * Gets/sets a named note in `$__z_apache_notes`. Returns the previous value (empty string if unset).
     */
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
    /**
     * Polyfill for `virtual()` in CLI SAPI.
     * Internal sub-requests are not supported in the subprocess context — always returns `false`.
     */
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

// Bridge php://input: the parent (Dispatcher) wrote the request body to our STDIN
// and closed it, but native CLI php://input does NOT expose that. Read it here and
// serve it via CgiInputStream so legacy code / the WP REST API (the block editor's
// JSON save) can read file_get_contents('php://input'). Other php:// pass through.
// (Also drains STDIN so a >64 KB POST body can't block the parent's pipe write.)
$GLOBALS['__zeal_cgi_raw_input'] = (string) (@stream_get_contents(STDIN) ?: '');
require_once __DIR__ . '/CGI/CgiInputStream.php';
@stream_wrapper_unregister('php');
@stream_wrapper_register('php', \ZealPHP\CGI\CgiInputStream::class);

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

// #357 — fire header_register_callback() here, while the header() override is
// still active (the shutdown function runs AFTER uopz has torn its overrides
// down, so a header() inside the callback fired there would be lost). Idempotent
// with the __z_send_meta() call, which is a no-op once the callback has run.
__z_fire_header_callback();

// @codeCoverageIgnoreEnd
