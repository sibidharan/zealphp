<?php

declare(strict_types=1);

// This file runs as a standalone fork-master subprocess (proc_open'd by the
// host) — coverage tools in the parent PHPUnit process cannot instrument it.
// @codeCoverageIgnoreStart

/**
 * Apache MPM prefork for ZealPHP — the fork-per-request CGI runner (EXPERIMENTAL).
 *
 * A long-lived TEMPLATE process (NO OpenSwoole reactor — pcntl_fork() is unsafe
 * inside the reactor) binds a UNIX socket and forks a FRESH child per accepted
 * request. The child:
 *   1. runs the target file AT TRUE GLOBAL SCOPE (this script's top level), so a
 *      legacy app's top-level variables ($menu/$submenu in WP's wp-admin) become
 *      real $GLOBALS and `global $menu` resolves — the issue #167 fix;
 *   2. captures output/status/headers/cookies (uopz/ext-zealphp overrides);
 *   3. writes the response frame, then HARD-exits (posix_kill SIGKILL — no PHP
 *      shutdown / destructors that could flush COW-shared resources and corrupt
 *      the parent). All its define()/class declarations die with it.
 *
 * The parent never runs app code, so every fork starts from the same clean, warm
 * baseline: fresh-process correctness (no "Cannot redeclare class") at fork cost
 * (~1 ms COW) instead of proc_open cold-start (~30-50 ms).
 *
 * Protocol: one length-prefixed JSON request frame in, one response frame out,
 * per connection (the same {@see \ZealPHP\CGI\IPC} framing the pool uses).
 *
 * Wiring/host side is {@see \ZealPHP\CGI\ForkPool}. NOTE: the capture-override
 * block below is intentionally duplicated from pool_worker.php for this first
 * experimental cut (zero regression risk to the production pool); it will be
 * de-duplicated into a shared runtime once fork mode is validated.
 *
 * Requires: pcntl + posix. Env: ZEALPHP_FORK_SOCK (unix socket path),
 * ZEALPHP_FORK_MAX_CONCURRENT (live-child cap, default 16), ZEALPHP_CWD.
 */

ini_set('display_errors', 'stderr');

if ((string) getenv('ZEALPHP_POOL_DEBUG_DEPRECATIONS') !== '1') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $__fm_autoload) {
    if (is_file($__fm_autoload)) {
        require_once $__fm_autoload;
        break;
    }
}

use ZealPHP\CGI\IPC;

require_once __DIR__ . '/CGI/CgiInputStream.php';

if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('pcntl_waitpid')) {
    fwrite(STDERR, "fork_master: pcntl/posix unavailable — fork mode needs both\n");
    exit(1);
}

$sockPath = (string) (getenv('ZEALPHP_FORK_SOCK') ?: '');
if ($sockPath === '') {
    fwrite(STDERR, "fork_master: ZEALPHP_FORK_SOCK not set\n");
    exit(1);
}
$maxConcurrent = max(1, (int) (getenv('ZEALPHP_FORK_MAX_CONCURRENT') ?: '16'));

// ── Per-request capture state (a child sets these, then exits) ──
$__fm_headers    = [];
$__fm_cookies    = [];
$__fm_rawcookies = [];
$__fm_status     = 200;

// ── Capture overrides (header/cookie/output/upload). Same shape as
// pool_worker.php so the host response builder is unchanged. Registered once;
// children inherit them across fork. ──
$z_override = function (string $name, \Closure $cb): void {
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
    $z_override('header', function (string $header, bool $replace = true, int $response_code = 0): void {
        global $__fm_headers, $__fm_status;
        if ($response_code > 0) {
            $__fm_status = $response_code;
        }
        if (stripos($header, 'HTTP/') === 0) {
            if (preg_match('/\d{3}/', $header, $m)) {
                $__fm_status = (int) $m[0];
            }
            return;
        }
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            if (strcasecmp($name, 'Status') === 0) {
                $codeStr = strtok($value, ' ');
                if ($codeStr !== false && ctype_digit($codeStr)) {
                    $code = (int) $codeStr;
                    if ($code >= 100 && $code <= 599) {
                        $__fm_status = $code;
                    }
                }
                $__fm_headers[] = [$name, $value];
                return;
            }
            if ($replace) {
                $__fm_headers = array_values(array_filter(
                    $__fm_headers,
                    static fn ($h) => strcasecmp($h[0], $name) !== 0
                ));
            }
            $__fm_headers[] = [$name, $value];
        }
    });

    $z_override('header_remove', function (?string $name = null): void {
        global $__fm_headers;
        $__fm_headers = $name === null ? [] : array_values(array_filter(
            $__fm_headers,
            static fn ($h) => strcasecmp($h[0], $name) !== 0
        ));
    });

    $z_override('http_response_code', function (?int $code = null): int|bool {
        global $__fm_status;
        if ($code === null) {
            return $__fm_status;
        }
        $prev = $__fm_status;
        $__fm_status = $code;
        return $prev;
    });

    $z_override('headers_sent', function (&$file = null, &$line = null): bool {
        $file = null;
        $line = 0;
        return false;
    });

    $z_override('setcookie', function (string $name, string $value = '', int|array $expires = 0, string $path = '', string $domain = '', bool $secure = false, bool $httponly = false): bool {
        global $__fm_cookies;
        $__fm_cookies[] = is_array($expires)
            ? array_merge(['name' => $name, 'value' => $value], $expires)
            : compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly');
        return true;
    });

    $z_override('setrawcookie', function (string $name, string $value = '', int|array $expires = 0, string $path = '', string $domain = '', bool $secure = false, bool $httponly = false): bool {
        global $__fm_rawcookies;
        $__fm_rawcookies[] = is_array($expires)
            ? array_merge(['name' => $name, 'value' => $value], $expires)
            : compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly');
        return true;
    });

    // is_uploaded_file/move_uploaded_file — bridge OpenSwoole uploads (same as
    // pool_worker.php). Resolve against the live $_FILES set per request.
    $z_override('is_uploaded_file', function (string $filename): bool {
        foreach ($_FILES as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tmp = $entry['tmp_name'] ?? null;
            if (is_array($tmp) ? in_array($filename, $tmp, true) : (is_string($tmp) && $tmp === $filename)) {
                return true;
            }
        }
        return false;
    });

    $z_override('move_uploaded_file', function (string $from, string $to): bool {
        $isUpload = false;
        foreach ($_FILES as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $tmp = $entry['tmp_name'] ?? null;
            if ((is_array($tmp) && in_array($from, $tmp, true)) || (is_string($tmp) && $tmp === $from)) {
                $isUpload = true;
                break;
            }
        }
        if (!$isUpload) {
            return false;
        }
        if (@rename($from, $to)) {
            return true;
        }
        if (@copy($from, $to)) {
            @unlink($from);
            return true;
        }
        return false;
    });
}

/**
 * Populate request superglobals + php://input from a request frame. Returns the
 * absolute file to include, or null on a bad/missing file. (The include itself
 * is done by the CALLER at top-level scope — see the loop — so the included
 * file's top-level variables become real $GLOBALS.)
 *
 * @param array<mixed,mixed> $req
 */
function fork_prepare_request(array $req): ?string
{
    $file = isset($req['file']) && is_string($req['file']) ? $req['file'] : '';
    if ($file === '' || !is_file($file)) {
        return null;
    }
    $_SERVER  = is_array($req['server']  ?? null) ? array_merge($_SERVER, $req['server']) : $_SERVER;
    $_GET     = is_array($req['get']     ?? null) ? $req['get']     : [];
    $_POST    = is_array($req['post']    ?? null) ? $req['post']    : [];
    $_COOKIE  = is_array($req['cookies'] ?? null) ? $req['cookies'] : [];
    $_FILES   = is_array($req['files']   ?? null) ? $req['files']   : [];
    $_REQUEST = array_merge($_GET, $_POST);

    $body = $req['body'] ?? '';
    if (is_string($body) && ($req['body_encoding'] ?? '') === 'base64') {
        $body = (string) base64_decode($body, true);
    }
    $GLOBALS['__zeal_cgi_raw_input'] = is_string($body) ? $body : '';
    @stream_wrapper_unregister('php');
    @stream_wrapper_register('php', \ZealPHP\CGI\CgiInputStream::class);

    return $file;
}

/**
 * Build the response frame from the captured state + the include's output.
 *
 * @param mixed $result the include's return value
 * @return array<string,mixed>
 */
function fork_build_response(mixed $result, string $body): array
{
    global $__fm_headers, $__fm_cookies, $__fm_rawcookies, $__fm_status;

    $hasReturn   = is_int($result) || is_array($result) || is_string($result);
    $returnValue = $hasReturn ? $result : null;

    return [
        'status'       => $__fm_status ?: 200,
        'headers'      => $__fm_headers,
        'cookies'      => $__fm_cookies,
        'rawcookies'   => $__fm_rawcookies,
        'body'         => $body,
        'return_value' => is_scalar($returnValue) || is_array($returnValue) || $returnValue === null ? $returnValue : null,
        'has_return'   => $hasReturn && $returnValue !== null && $returnValue !== 1,
    ];
}

$__fm_cwd = getenv('ZEALPHP_CWD');
if (is_string($__fm_cwd) && $__fm_cwd !== '') {
    @chdir($__fm_cwd);
}

@unlink($sockPath);
$__fm_errno = 0;
$__fm_errstr = '';
$listener = @stream_socket_server('unix://' . $sockPath, $__fm_errno, $__fm_errstr);
if ($listener === false) {
    fwrite(STDERR, "fork_master: bind failed on $sockPath: $__fm_errstr\n");
    exit(1);
}
@chmod($sockPath, 0700);

pcntl_async_signals(true);
$__fm_running = true;
$__fm_live    = 0;
$__fm_reap = function () use (&$__fm_live): void {
    while (($p = @pcntl_waitpid(-1, $st, WNOHANG)) > 0) {
        $__fm_live = max(0, $__fm_live - 1);
    }
};
pcntl_signal(SIGCHLD, $__fm_reap);
pcntl_signal(SIGTERM, function () use (&$__fm_running): void { $__fm_running = false; });
pcntl_signal(SIGINT, function () use (&$__fm_running): void { $__fm_running = false; });

// Boot-sync line so the host can wait for "ready" before dispatching.
fwrite(STDERR, "ZEALPHP_FORK_SERVER_READY\n");

while ($__fm_running) {
    // Orphan guard: if the OpenSwoole worker that spawned us died (uncleanly,
    // without close()-ing us), our ppid reparents to init (1). Exit so we never
    // leak as an orphaned fork-master holding a stale socket.
    if (function_exists('posix_getppid') && posix_getppid() === 1) {
        break;
    }
    // Backpressure: never accept past the live-child cap (fork-bomb guard).
    while ($__fm_live >= $maxConcurrent) {
        $__fm_reap();
        usleep(1000);
    }
    $conn = @stream_socket_accept($listener, 1.0);
    if ($conn === false) {
        continue; // accept timeout (re-check $__fm_running) or EINTR
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        IPC::writeFrame($conn, ['status' => 503, 'body' => 'fork_master: fork failed', 'headers' => [], 'cookies' => [], 'rawcookies' => []]);
        @fclose($conn);
        continue;
    }

    if ($pid === 0) {
        // ── CHILD (still at this script's TOP-LEVEL scope) ──
        @fclose($listener);            // only the parent accepts
        pcntl_signal(SIGCHLD, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);

        $__fm_resp = ['status' => 500, 'body' => 'fork_master: no request', 'headers' => [], 'cookies' => [], 'rawcookies' => []];
        $__fm_file = null;
        try {
            $__fm_req = IPC::readFrame($conn, 10.0);
            if (is_array($__fm_req)) {
                $__fm_file = fork_prepare_request($__fm_req);
            }
        } catch (\Throwable $e) {
            $__fm_resp = ['status' => 500, 'body' => 'fork_master child: ' . $e->getMessage(), 'headers' => [], 'cookies' => [], 'rawcookies' => []];
        }

        if ($__fm_file !== null) {
            ob_start();
            $__fm_result = 1;
            try {
                /** @psalm-suppress UnresolvableInclude */
                $__fm_result = include $__fm_file;   // ← TRUE GLOBAL SCOPE
            } catch (\Throwable $e) {
                $__fm_resp = ['status' => 500, 'body' => 'fork_master: ' . $e->getMessage(), 'headers' => [], 'cookies' => [], 'rawcookies' => []];
                while (ob_get_level() > 0) { ob_end_clean(); }
                $__fm_file = null;
            }
            if ($__fm_file !== null) {
                $__fm_body = '';
                while (ob_get_level() > 0) {
                    $chunk = ob_get_clean();
                    if (is_string($chunk)) { $__fm_body .= $chunk; }
                }
                $__fm_resp = fork_build_response($__fm_result, $__fm_body);
            }
        }

        try {
            IPC::writeFrame($conn, $__fm_resp);
        } catch (\Throwable $e) {
            // client gone — nothing to do
        }
        @fclose($conn);

        // HARD exit — no PHP shutdown / destructors / shutdown functions, so a
        // child can never corrupt the parent's COW-shared state. The response
        // is already on the wire.
        @posix_kill((int) posix_getpid(), SIGKILL);
        exit(0); // unreachable
    }

    // ── PARENT ──
    $__fm_live++;
    @fclose($conn); // the child owns the connection
}

@fclose($listener);
@unlink($sockPath);
exit(0);
