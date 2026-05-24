<?php

declare(strict_types=1);

/**
 * Persistent subprocess entry for ZealPHP's native FCGI-style worker pool.
 *
 * Loops on stdin frames: read request payload → execute the PHP file → write
 * response frame to stdout → reset request state → next iteration. Exits
 * cleanly on EOF (parent closed the pipe) or after ZEALPHP_POOL_MAX_REQUESTS
 * requests (FPM `pm.max_requests` parity recycle).
 *
 * Each iteration is approximately what `cgi_worker.php` does for ONE request,
 * but without the per-request boot cost — Composer autoloader, uopz overrides,
 * and the IPC class stay loaded across iterations. That's what the pool buys
 * over `cgiMode('proc')` (where every request pays ~30-50 ms cold-start PHP).
 *
 * Caveat for unmodified WordPress / Drupal: PHP's global namespace (defined
 * classes, define() constants, ini_set) PERSISTS across iterations of the
 * SAME worker. Setting `ZEALPHP_POOL_MAX_REQUESTS=1` recycles after each
 * request → true fresh-process semantics, identical to `cgiMode('proc')` but
 * with the parent pool managing spawn cost amortisation. K > 1 (default 500)
 * is for apps with idempotent boot — modernised legacy or framework code
 * that guards `defined() ?: define()`. WordPress itself emits E_NOTICE on
 * re-define but continues fine (notice ≠ fatal), matching FPM behavior.
 *
 * uopz overrides: header(), header_remove(), setcookie(), setrawcookie(),
 * http_response_code(), headers_list(), headers_sent() — same shape as
 * src/cgi_worker.php's captures so the response frame can be threaded back
 * through the parent worker's response builder unchanged.
 */

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $__pw_autoload) {
    if (is_file($__pw_autoload)) {
        require_once $__pw_autoload;
        break;
    }
}

use ZealPHP\CGI\IPC;

$maxRequests = (int) (getenv('ZEALPHP_POOL_MAX_REQUESTS') ?: '500');
$count       = 0;

// Per-request capture state. Reset by reset_request_state() between frames.
$__pw_headers    = [];   /** @var list<array{0:string,1:string}> */
$__pw_cookies    = [];   /** @var list<array<string,mixed>> */
$__pw_rawcookies = [];   /** @var list<array<string,mixed>> */
$__pw_status     = 200;

// uopz overrides — set ONCE at boot, survive every iteration. Mirror the
// shape cgi_worker.php produces so the parent's response builder doesn't
// need to fork a special pool-mode code path.
if (function_exists('uopz_set_return')) {
    uopz_set_return('header', function (string $header, bool $replace = true, int $response_code = 0): void {
        global $__pw_headers, $__pw_status;
        if ($response_code > 0) {
            $__pw_status = $response_code;
        }
        if (stripos($header, 'HTTP/') === 0) {
            // "HTTP/1.1 NNN Reason"
            preg_match('/\d{3}/', $header, $m);
            if ($m) {
                $__pw_status = (int) $m[0];
            }
            return;
        }
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            // CGI/1.1 RFC 3875 §6.3.3 — "Status:" pseudo-header sets the
            // response code (mod_cgi parity). Keep in the captured headers
            // list too so the host-side wrapper can suppress it from the
            // outbound HTTP response.
            if (strcasecmp($name, 'Status') === 0) {
                $codeStr = strtok($value, ' ');
                if ($codeStr !== false && ctype_digit($codeStr)) {
                    $code = (int) $codeStr;
                    if ($code >= 100 && $code <= 599) {
                        $__pw_status = $code;
                    }
                }
                $__pw_headers[] = [$name, $value];
                return;
            }
            if ($replace) {
                $__pw_headers = array_values(array_filter(
                    $__pw_headers,
                    static fn ($h) => strcasecmp($h[0], $name) !== 0
                ));
            }
            $__pw_headers[] = [$name, $value];
        }
    }, true);

    uopz_set_return('header_remove', function (?string $name = null): void {
        global $__pw_headers;
        if ($name === null) {
            $__pw_headers = [];
        } else {
            $__pw_headers = array_values(array_filter(
                $__pw_headers,
                static fn ($h) => strcasecmp($h[0], $name) !== 0
            ));
        }
    }, true);

    uopz_set_return('headers_list', function (): array {
        global $__pw_headers;
        return array_map(static fn ($h) => $h[0] . ': ' . $h[1], $__pw_headers);
    }, true);

    uopz_set_return('headers_sent', function (&$file = null, &$line = null): bool {
        $file = null;
        $line = 0;
        return false;
    }, true);

    uopz_set_return('http_response_code', function (?int $code = null): int|bool {
        global $__pw_status;
        if ($code === null) {
            return $__pw_status;
        }
        $prev = $__pw_status;
        $__pw_status = $code;
        return $prev;
    }, true);

    uopz_set_return('setcookie', function (
        string $name,
        string $value = '',
        int|array $expires = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false
    ): bool {
        global $__pw_cookies;
        $cookie = is_array($expires)
            ? array_merge(['name' => $name, 'value' => $value], $expires)
            : compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly');
        $__pw_cookies[] = $cookie;
        return true;
    }, true);

    uopz_set_return('setrawcookie', function (
        string $name,
        string $value = '',
        int|array $expires = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false
    ): bool {
        global $__pw_rawcookies;
        $cookie = is_array($expires)
            ? array_merge(['name' => $name, 'value' => $value], $expires)
            : compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly');
        $__pw_rawcookies[] = $cookie;
        return true;
    }, true);
}

// Drain any output buffering that may have been auto-started so PHP errors
// + notices can't pollute the response-frame stream on stdout.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// $GLOBALS snapshot for FPM-style per-request cleanup (issue #18 follow-up).
// Captures every key in $GLOBALS RIGHT NOW — after autoloader load, after
// uopz overrides, after `use ZealPHP\CGI\IPC` etc., but BEFORE the first
// request fires. Everything in this snapshot is "framework / pool-worker
// scope" and must SURVIVE every iteration. Anything added later (by the
// included PHP file) is "request scope" and gets unset in
// pool_reset_request_state().
//
// Concretely, this clears WordPress's `$wp_did_header` sentinel between
// requests — that's the global that gates the entire WP bootstrap chain
// in wp-blog-header.php (`if (!isset($wp_did_header)) { … wp() … }`). Without
// this cleanup, the 2nd request finds it set, skips the wp() call, and
// returns an empty body. FPM's SAPI does this at the C level via
// PG(symbol_table) tear-down between requests; we do it in PHP here.
//
// What this DOES clean:
//   - $wp_did_header, $wpdb, $wp_query, $post, $wp_filter — any WP global
//   - Any user-set `global $foo;` from the included file
//   - Any `$GLOBALS['x'] = ...` direct write
//
// What this CAN'T clean (PHP language limitations):
//   - define()'d constants — PHP has no un-define (would need uopz_undefine).
//     Apps must use `defined() ?: define()` guards (WordPress does — emits
//     E_NOTICE on re-define but continues, matching FPM behaviour).
//   - Class declarations — same story; once loaded, they stay.
//   - require_once'd files — opcache caches the parsed bytecode; second
//     require_once is a no-op. That's actually FINE because WordPress
//     bootstraps via require_once chains and re-entry via plain `require`
//     gated on $wp_did_header.
$__pw_globals_snapshot = array_fill_keys(array_keys($GLOBALS), true);

// READY signal on stderr — the framing channel (stdout) stays pure. Parent
// reads this for boot sync (bounds the dispatch-after-spawn window).
fwrite(STDERR, "ZEALPHP_POOL_WORKER_READY\n");

while ($count < $maxRequests) {
    $req = IPC::readFrame(STDIN);
    if ($req === null) {
        break; // parent closed pipe → clean exit
    }

    $resp = pool_handle_request($req);
    IPC::writeFrame(STDOUT, $resp);

    pool_reset_request_state();
    $count++;
}

exit(0);

/**
 * @param array<mixed,mixed> $req
 * @return array<string,mixed>
 */
function pool_handle_request(array $req): array
{
    global $__pw_headers, $__pw_cookies, $__pw_rawcookies, $__pw_status;

    $file = isset($req['file']) && is_string($req['file']) ? $req['file'] : '';
    if ($file === '' || !is_file($file)) {
        return [
            'status'     => 404,
            'body'       => "pool_worker: file not found: $file",
            'headers'    => [],
            'cookies'    => [],
            'rawcookies' => [],
        ];
    }

    // Populate request-input superglobals. Merging $_SERVER (rather than
    // wholesale replacing it) preserves the subprocess's own env-derived
    // keys (PATH, USER, HOME, …) that downstream code may read.
    $_SERVER  = array_merge($_SERVER, is_array($req['server'] ?? null) ? $req['server'] : []);
    $_GET     = is_array($req['get']     ?? null) ? $req['get']     : [];
    $_POST    = is_array($req['post']    ?? null) ? $req['post']    : [];
    $_COOKIE  = is_array($req['cookies'] ?? null) ? $req['cookies'] : [];
    $_FILES   = is_array($req['files']   ?? null) ? $req['files']   : [];
    $_REQUEST = array_merge($_GET, $_POST);

    ob_start();
    $result = null;
    try {
        /** @psalm-suppress UnresolvableInclude */
        $result = include $file;
    } catch (\Throwable $e) {
        ob_end_clean();
        return [
            'status'     => 500,
            'body'       => 'pool_worker fatal: ' . $e->getMessage(),
            'headers'    => [],
            'cookies'    => [],
            'rawcookies' => [],
            'stderr'     => $e->getTraceAsString(),
        ];
    }
    $body = (string) ob_get_clean();

    // Universal return contract — mirror src/cgi_worker.php exactly so the
    // host-side response builder treats pool returns identically to proc.
    if ($result instanceof \Closure) {
        $result = $result();
    }
    if ($result instanceof \Generator) {
        foreach ($result as $chunk) {
            if (is_scalar($chunk)) {
                $body .= (string) $chunk;
            }
        }
        $result = null;
    }

    return [
        'status'       => $__pw_status ?: 200,
        'headers'      => $__pw_headers,
        'cookies'      => $__pw_cookies,
        'rawcookies'   => $__pw_rawcookies,
        'body'         => $body,
        'return_value' => is_scalar($result) || is_array($result) || $result === null ? $result : null,
    ];
}

function pool_reset_request_state(): void
{
    global $__pw_headers, $__pw_cookies, $__pw_rawcookies, $__pw_status, $__pw_globals_snapshot;

    $_SERVER  = [];
    $_GET     = [];
    $_POST    = [];
    $_COOKIE  = [];
    $_FILES   = [];
    $_REQUEST = [];
    $_SESSION = null;

    $__pw_headers    = [];
    $__pw_cookies    = [];
    $__pw_rawcookies = [];
    $__pw_status     = 200;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // FPM-style request-scope cleanup. Unset every $GLOBALS key that wasn't
    // in the boot snapshot. Skip:
    //   - Anything starting with `_` (superglobals + our `__pw_*` internals
    //     are all reset above explicitly; PHP-defined `_ENV` etc. stay)
    //   - `GLOBALS` (self-reference; touching it is a runtime error)
    // See the snapshot block at boot for the full rationale.
    if (is_array($__pw_globals_snapshot)) {
        foreach (array_keys($GLOBALS) as $__pw_key) {
            if (!is_string($__pw_key)) {
                continue;
            }
            if (isset($__pw_globals_snapshot[$__pw_key])) {
                continue;
            }
            if ($__pw_key === 'GLOBALS' || str_starts_with($__pw_key, '_')) {
                continue;
            }
            unset($GLOBALS[$__pw_key]);
        }
    }
}
