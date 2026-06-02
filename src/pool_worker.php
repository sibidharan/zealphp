<?php

declare(strict_types=1);

// This file runs as a standalone subprocess via proc_open — coverage tools
// in the parent PHPUnit process cannot instrument it.
// @codeCoverageIgnoreStart

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

ini_set('display_errors', 'stderr');

// Suppress E_DEPRECATED and E_USER_DEPRECATED by default. PHP 8.4 emits one
// deprecation per non-explicit-nullable parameter in older vendor libraries.
// phpMyAdmin alone produces ~200 such warnings per request. They all go to
// stderr — which is a 64 KB pipe to the parent. The parent does not drain
// stderr until the subprocess dies, so once that pipe fills, the next
// fwrite(STDERR, ...) blocks the subprocess forever. The whole worker
// deadlocks; readFrame on the parent times out at $cgi_timeout (default 60s).
// Opt back in via ZEALPHP_POOL_DEBUG_DEPRECATIONS=1 if you actually want them.
if ((string) getenv('ZEALPHP_POOL_DEBUG_DEPRECATIONS') !== '1') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $__pw_autoload) {
    if (is_file($__pw_autoload)) {
        require_once $__pw_autoload;
        break;
    }
}

use ZealPHP\CGI\IPC;

// Bridge php://input to the request's raw body (REST/JSON payloads) for legacy
// code in this pooled subprocess — mirrors \ZealPHP\IOStreamWrapper (in-process
// modes) and cgi_worker.php (proc). CgiInputStream serves the CURRENT request's
// body from $GLOBALS['__zeal_cgi_raw_input'], set per request by
// pool_prepare_request; every other php:// stream passes through.
require_once __DIR__ . '/CGI/CgiInputStream.php';
$GLOBALS['__zeal_cgi_raw_input'] = '';
@stream_wrapper_unregister('php');
@stream_wrapper_register('php', \ZealPHP\CGI\CgiInputStream::class);

$maxRequests = (int) (getenv('ZEALPHP_POOL_MAX_REQUESTS') ?: '500');
$count       = 0;

// Per-request capture state. Reset by reset_request_state() between frames.
$__pw_headers    = [];   /** @var list<array{0:string,1:string}> */
$__pw_cookies    = [];   /** @var list<array<string,mixed>> */
$__pw_rawcookies = [];   /** @var list<array<string,mixed>> */
$__pw_status     = 200;
$__pw_shutdown_functions = [];
$__pw_mid_request = false;

// FD-3 IPC: open fd 3 once at boot — the parent's proc_open spec includes
// fd 3 as a writable pipe. Reuse the handle across every iteration; the
// IPC_Sender destructor writes a metadata frame to it.
// When fd 3 isn't open (very old tests / legacy environments), the file
// pointer is false; the destructor falls back to writing the frame on
// STDOUT (legacy protocol).
$__pw_fd3 = @fopen('php://fd/3', 'w');
if ($__pw_fd3 === false) {
    $__pw_fd3 = null;
}

/**
 * Destructor-based metadata frame sender. PHP runs destructors even after
 * `exit()` is called from inside a shutdown function — phpMyAdmin's
 * `ResponseRenderer->response()` does exactly that. The shutdown chain
 * gets preempted, but a destructor on a static instance still fires,
 * so the parent receives status/headers/cookies regardless of how the
 * request ended.
 *
 * Body bytes are written directly to STDOUT during the request (no IPC
 * framing on STDOUT), so this sender only carries metadata.
 */
final class ZealPHP_IPC_Sender
{
    private static ?self $instance = null;

    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __destruct()
    {
        global $__pw_mid_request, $__pw_status, $__pw_headers, $__pw_cookies,
               $__pw_rawcookies, $__pw_shutdown_functions, $__pw_fd3;

        if (!$__pw_mid_request) {
            return;
        }

        // Flush session to disk before sending the IPC frame — exit()
        // fires our destructor before PHP's native session_write_close.
        // Without this, a redirect after exit() races: the next request reads
        // a stale session file because the old worker hasn't flushed yet.
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Do NOT re-run leftover $__pw_shutdown_functions here. We're in this
        // destructor specifically because exit()/die() preempted the regular
        // shutdown chain — re-invoking the queued functions is unsafe:
        //   (a) phpMyAdmin's ResponseRenderer->response() (the very function
        //       that called exit()) is typically still in $__pw_shutdown_functions
        //       and hangs when re-entered with already-finalized state
        //       (verified by tracing — destructor blocked at "[4.0] running sf").
        //   (b) any leftover function that itself calls exit() would recurse
        //       into this destructor.
        //   (c) exit() inside a shutdown function semantically means "stop
        //       now and emit the response" — running more handlers contradicts
        //       that intent.
        // Buffered output is captured below and emitted as the response body.

        // Capture remaining output buffer as the response body. On the exit
        // path the body lives in ob_start (pool_handle_request opened it but
        // didn't get to call ob_get_clean before exit() preempted the chain).
        // On the happy path ob_get_level() is already 0 (the main loop
        // emptied the buffer and consumed the body), so $body stays ''.
        $body = '';
        while (ob_get_level() > 0) {
            $chunk = ob_get_clean();
            if (is_string($chunk)) {
                $body .= $chunk;
            }
        }

        $resp = [
            'status'     => $__pw_status ?: 200,
            'headers'    => is_array($__pw_headers) ? $__pw_headers : [],
            'cookies'    => is_array($__pw_cookies) ? $__pw_cookies : [],
            'rawcookies' => is_array($__pw_rawcookies) ? $__pw_rawcookies : [],
            '_exit'      => true,
        ];

        try {
            if ($__pw_fd3 !== null && is_resource($__pw_fd3)) {
                // FD-3 IPC: write metadata + body_length to fd 3 FIRST so
                // the parent knows how many body bytes to drain from STDOUT.
                $resp['body_length'] = strlen($body);
                IPC::writeFrame($__pw_fd3, $resp);
                if ($body !== '') {
                    fwrite(STDOUT, $body);
                }
                fflush(STDOUT);
            } else {
                // Legacy single-channel: stuff body into the frame.
                $resp['body'] = $body;
                IPC::writeFrame(STDOUT, $resp);
            }
        } catch (\Throwable $e) {
            // pipes may be broken — nothing we can do
        }
    }
}

ZealPHP_IPC_Sender::init();

// exit()/die() survival — the ZealPHP_IPC_Sender::__destruct() above is the
// PRIMARY mechanism. It fires LAST in PHP's teardown sequence, even AFTER
// `exit()` is called from inside a shutdown function (phpMyAdmin's
// `ResponseRenderer->response()` does exactly that).
//
// We register a regular shutdown function too as a belt-and-suspenders. It
// runs the app's registered shutdown chain in the SAME order as a normal
// request — the destructor only runs them as a safety net if the chain was
// preempted before this handler completed. ob_buffer drain happens in the
// destructor either way.
register_shutdown_function(function (): void {
    global $__pw_mid_request, $__pw_shutdown_functions;

    if (!$__pw_mid_request) {
        return;
    }

    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Run any app-registered shutdown functions in registration order.
    // If one calls exit(), PHP cuts off iteration and the destructor
    // still completes the metadata-frame send for whatever ran.
    if (is_array($__pw_shutdown_functions)) {
        foreach ($__pw_shutdown_functions as $i => $sf) {
            try {
                $sf[0](...$sf[1]);
            } catch (\Throwable $e) {
                // ignore — we're shutting down
            }
            // Mark consumed so the destructor doesn't double-run it on
            // the safety-net path.
            unset($__pw_shutdown_functions[$i]);
        }
    }
});

// uopz overrides — set ONCE at boot, survive every iteration. Mirror the
// shape cgi_worker.php produces so the parent's response builder doesn't
// need to fork a special pool-mode code path.
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
    $z_override('register_shutdown_function', function (callable $callback, mixed ...$args): void {
        global $__pw_shutdown_functions;
        $__pw_shutdown_functions[] = [$callback, $args];
    }, true);

    $z_override('header', function (string $header, bool $replace = true, int $response_code = 0): void {
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

    $z_override('header_remove', function (?string $name = null): void {
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

    $z_override('headers_list', function (): array {
        global $__pw_headers;
        return array_map(static fn ($h) => $h[0] . ': ' . $h[1], $__pw_headers);
    }, true);

    $z_override('headers_sent', function (&$file = null, &$line = null): bool {
        $file = null;
        $line = 0;
        return false;
    }, true);

    $z_override('http_response_code', function (?int $code = null): int|bool {
        global $__pw_status;
        if ($code === null) {
            return $__pw_status;
        }
        $prev = $__pw_status;
        $__pw_status = $code;
        return $prev;
    }, true);

    $z_override('setcookie', function (
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

    $z_override('setrawcookie', function (
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

    // STDOUT pollution guard. The pool worker writes IPC frames to STDOUT,
    // so any app writing directly to STDOUT (via flush() / ob_end_flush() /
    // fastcgi_finish_request()) corrupts the IPC channel — the parent reads
    // garbage as a frame length, returns "subprocess died mid-request",
    // and the actual response is lost. Neutralize all three: app output
    // stays in the outer ob_start buffer, where the shutdown handler /
    // pool_handle_request can capture it cleanly.
    $z_override('flush', function (): void { /* no-op */ }, true);
    $z_override('ob_flush', function (): void { /* no-op */ }, true);
    $z_override('ob_end_flush', function (): bool {
        // Pop the buffer but keep its contents accessible to the IPC framer
        // (writing to STDOUT directly would corrupt the IPC channel). When
        // there's still an outer buffer, we echo into it (so ob_get_clean()
        // at the framing point reads the merged content). When we're at the
        // OUTERMOST buffer (the one pool_handle_request opened at line 501),
        // re-open it with the same content so the framing point still finds
        // it — otherwise the content is lost forever. This is what
        // wp_ob_end_flush_all() triggers via WordPress's shutdown_action_hook:
        // it flushes EVERY buffer including our outer one, and without the
        // re-open the response body vanishes.
        if (ob_get_level() === 0) {
            return false;
        }
        $content = (string) ob_get_clean();
        if (ob_get_level() > 0) {
            if ($content !== '') {
                echo $content;
            }
        } else {
            // We just popped the outermost framework buffer — re-open it so
            // the response body survives to the IPC framing step.
            ob_start();
            if ($content !== '') {
                echo $content;
            }
        }
        return true;
    }, true);
    $z_override('fastcgi_finish_request', function (): bool {
        // Common legacy pattern: render page, fastcgi_finish_request(),
        // then do background work. In pool we can't actually deliver early
        // since we use length-prefixed IPC — just no-op and let the
        // response flow through the normal shutdown path.
        return true;
    }, true);

    // is_uploaded_file() / move_uploaded_file() — bridge OpenSwoole-delivered
    // uploads to legacy code (same overrides cgi_worker.php carries for proc).
    // The pool repopulates $_FILES per request (pool_prepare_request), so these
    // resolve against the LIVE $_FILES superglobal at call time, not a boot
    // snapshot. WITHOUT them, PHP's native is_uploaded_file() runs — and its
    // SAPI upload list is empty under OpenSwoole — so WordPress's
    // wp_handle_upload() fails every media upload with "Specified file failed
    // upload test." (handles both scalar and array `tmp_name` shapes).
    $z_override('is_uploaded_file', function (string $filename): bool {
        foreach ($_FILES as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tmp = $entry['tmp_name'] ?? null;
            if (is_array($tmp)) {
                if (in_array($filename, $tmp, true)) {
                    return true;
                }
            } elseif (is_string($tmp) && $tmp === $filename) {
                return true;
            }
        }
        return false;
    }, true);

    $z_override('move_uploaded_file', function (string $from, string $to): bool {
        $isUpload = false;
        foreach ($_FILES as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tmp = $entry['tmp_name'] ?? null;
            if ((is_array($tmp) && in_array($from, $tmp, true))
                || (is_string($tmp) && $tmp === $from)) {
                $isUpload = true;
                break;
            }
        }
        if (!$isUpload) {
            return false;
        }
        // rename within a filesystem; copy+unlink across (e.g. /tmp → uploads).
        if (@rename($from, $to)) {
            return true;
        }
        if (@copy($from, $to)) {
            @unlink($from);
            return true;
        }
        return false;
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

// FPM parity: snapshot constants + classes + functions + included_files at
// boot so pool_reset_request_state() can roll them back to this baseline
// after every request. Without this, the FIRST app's defines pollute the
// second app's namespace (Kanboard's ROOT_DIR leaks into phpMyAdmin etc.).
if (function_exists('zealphp_process_state_snapshot')) {
    @zealphp_process_state_snapshot();
}

// READY signal on stderr — the framing channel (stdout) stays pure. Parent
// reads this for boot sync (bounds the dispatch-after-spawn window).
fwrite(STDERR, "ZEALPHP_POOL_WORKER_READY\n");

while ($count < $maxRequests) {
    $req = IPC::readFrame(STDIN);
    if ($req === null) {
        break; // parent closed pipe → clean exit
    }

    $__pw_mid_request = true;
    // Run the include AT GLOBAL SCOPE (not inside pool_handle_request) so a
    // legacy app's top-level variables become real $GLOBALS. [Issue 1]
    $__pw_prep = pool_prepare_request($req);
    if (isset($__pw_prep['__error'])) {
        $resp = $__pw_prep['__error'];
    } else {
        $__pw_inc_result = null;
        $__pw_inc_error  = null;
        try {
            /** @psalm-suppress UnresolvableInclude */
            $__pw_inc_result = include $__pw_prep['file'];
        } catch (\Throwable $__pw_e) {
            $__pw_inc_error = $__pw_e;
        }
        $resp = pool_finish_request($__pw_inc_result, $__pw_inc_error, $__pw_prep['prevCwd']);
    }

    // FD-3 IPC happy path: body goes to STDOUT, metadata frame goes to fd 3.
    // The metadata frame carries `body_length` so the parent reads exactly
    // that many bytes from STDOUT — needed because STDOUT is a persistent
    // pipe (no EOF between iterations on the same worker).
    $body = '';
    if (isset($resp['body']) && is_string($resp['body'])) {
        $body = $resp['body'];
        unset($resp['body']);
    }

    if ($__pw_fd3 !== null && is_resource($__pw_fd3)) {
        // Write metadata FIRST to fd 3 (parent reads metadata first to
        // learn the body length), then stream the body to STDOUT.
        $resp['body_length'] = strlen($body);
        IPC::writeFrame($__pw_fd3, $resp);
        if ($body !== '') {
            fwrite(STDOUT, $body);
        }
        fflush(STDOUT);
    } else {
        // Legacy fallback: parent reads a single frame from STDOUT when
        // fd 3 isn't open. Put the body back into the frame.
        $resp['body'] = $body;
        IPC::writeFrame(STDOUT, $resp);
    }

    $__pw_mid_request = false;

    pool_reset_request_state();
    $count++;
}

exit(0);

/**
 * Prepare per-request state and return the file to include + the prior cwd.
 * The `include` itself is performed by the CALLER at GLOBAL scope (see the
 * request loop) — NOT here — so a legacy app's top-level variables
 * ($menu / $submenu in WP's wp-admin) become real `$GLOBALS`. Including from
 * inside a function made them function-locals, so WP's `global $menu` resolved
 * to null and `uksort($menu, …)` fataled. [Issue 1: include scope]
 *
 * @param array<mixed,mixed> $req
 * @return array{file:string,prevCwd:mixed}|array{__error:array<string,mixed>}
 */
function pool_prepare_request(array $req): array
{
    $file = isset($req['file']) && is_string($req['file']) ? $req['file'] : '';
    if ($file === '' || !is_file($file)) {
        return ['__error' => [
            'status'     => 404,
            'body'       => "pool_worker: file not found: $file",
            'headers'    => [],
            'cookies'    => [],
            'rawcookies' => [],
        ]];
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

    // Raw request body for php://input (CgiInputStream serves it). IPC base64s
    // the body only when it isn't valid UTF-8 (binary); decode that case.
    $__pw_body = $req['body'] ?? '';
    if (is_string($__pw_body) && ($req['body_encoding'] ?? '') === 'base64') {
        $__pw_body = (string) base64_decode($__pw_body, true);
    }
    $GLOBALS['__zeal_cgi_raw_input'] = is_string($__pw_body) ? $__pw_body : '';

    $prevCwd = getcwd();
    chdir(dirname($file));

    ob_start();
    return ['file' => $file, 'prevCwd' => $prevCwd];
}

/**
 * Capture output and build the response AFTER the global-scope include.
 *
 * @param mixed           $result   the include's return value (null if it threw)
 * @param \Throwable|null $error    exception thrown by the include, if any
 * @param mixed           $prevCwd  cwd to restore
 * @return array<string,mixed>
 */
function pool_finish_request(mixed $result, ?\Throwable $error, mixed $prevCwd): array
{
    global $__pw_headers, $__pw_cookies, $__pw_rawcookies, $__pw_status;

    if ($error !== null) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (is_string($prevCwd)) chdir($prevCwd);
        return [
            'status'     => 500,
            'body'       => 'pool_worker fatal: ' . $error->getMessage(),
            'headers'    => [],
            'cookies'    => [],
            'rawcookies' => [],
            'stderr'     => $error->getTraceAsString(),
        ];
    }
    if (is_string($prevCwd)) chdir($prevCwd);

    // Execute registered shutdown functions before capturing the output buffer.
    // CRITICAL: clear each entry from $__pw_shutdown_functions as it runs.
    // If a user shutdown function calls exit() (phpMyAdmin's
    // ResponseRenderer->response() does this), PHP unwinds to its own
    // shutdown sequence — which fires our register_shutdown_function and
    // our IPC_Sender destructor. Both also iterate $__pw_shutdown_functions
    // as safety nets, so we'd run the user function 2-3 times without
    // clearing. Unset BEFORE invoke so even if the user fn exit()s, the
    // entry is already consumed.
    global $__pw_shutdown_functions;
    if (is_array($__pw_shutdown_functions)) {
        foreach (array_keys($__pw_shutdown_functions) as $sfKey) {
            $sf = $__pw_shutdown_functions[$sfKey] ?? null;
            unset($__pw_shutdown_functions[$sfKey]);
            if (!is_array($sf) || !isset($sf[0]) || !is_callable($sf[0])) {
                continue;
            }
            try {
                $sf[0](...(is_array($sf[1] ?? null) ? $sf[1] : []));
            } catch (\Throwable $e) {
                error_log("pool_worker shutdown function error: " . $e->getMessage());
            }
        }
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
    global $__pw_headers, $__pw_cookies, $__pw_rawcookies, $__pw_status, $__pw_globals_snapshot, $__pw_shutdown_functions;

    // Issue #108 — flush $_SESSION to disk BEFORE nulling it. The pool worker
    // outlives any single request, so PHP's native shutdown sequence (which
    // calls session_write_close for the user) never fires between frames. If
    // we null $_SESSION here without writing first, every session mutation
    // made by the included file is silently dropped and the file on disk
    // stays at its pre-request state. Next request reads the stale file and
    // sees the old value — exactly the "Value: EMPTY" symptom from #108.
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $_SERVER  = [];
    $_GET     = [];
    $_POST    = [];
    $_COOKIE  = [];
    $_FILES   = [];
    $_REQUEST = [];
    $_SESSION = null;
    $GLOBALS['__zeal_cgi_raw_input'] = '';

    $__pw_headers    = [];
    $__pw_cookies    = [];
    $__pw_rawcookies = [];
    $__pw_status     = 200;
    $__pw_shutdown_functions = [];

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

    // FPM parity for constants, classes, functions, included_files —
    // ext-zealphp's process_state_clean removes everything added during
    // the request, restoring the boot snapshot. Without this, app-defined
    // constants leak across apps in the same pool (Kanboard's ROOT_DIR
    // collides with phpMyAdmin's CACHE_DIR; both crash on re-define).
    if (function_exists('zealphp_process_state_clean')) {
        @zealphp_process_state_clean(); // flags=7 default: files+classes+functions
    }
}
