<?php
declare(strict_types=1);

namespace ZealPHP\CGI;

use ZealPHP\App;
use ZealPHP\RequestContext;
use function ZealPHP\elog;
use function ZealPHP\response_set_status;

/**
 * CGI execution machinery extracted from App.php (Phase 2 structural refactor).
 *
 * Holds the heavy dispatch implementations for ZealPHP's legacy/CGI execution
 * paths: building the RFC 3875 CGI env, minting the host->subprocess session
 * handoff, the FastCGI (fcgi) dispatch, the proc/shebang subprocess dispatch,
 * the RFC 3875 interpreter-response parser, and the native worker-pool dispatch.
 *
 * Configuration + the public API surface (cgiMode, registerCgiBackend,
 * resolveCgiBackend, the $cgi_* static properties, exec/rawExec) remain on
 * {@see App}; this class reads them via App::$cgi_* and App::resolveCgiBackend().
 * App keeps thin public delegating shims for buildCgiEnv()/parseCgiResponse()
 * so existing callers/tests keep working unchanged.
 *
 * No logic changes — bodies moved verbatim from App.php.
 */
class Dispatcher
{
    /**
     * Most output buffered from one non-PHP CGI script (headers + body) before
     * it is stopped and the request fails (C10). Same ceiling as an IPC frame.
     */
    public const CGI_OUTPUT_CAP_BYTES = IPC::MAX_FRAME_BYTES;

    /** Most stderr kept per CGI request for the log; the rest is read and dropped. */
    private const DIAG_CAP_BYTES = 1024 * 1024;

    /**
     * Largest ZEALPHP_REQUEST_CONTEXT still put in a non-PHP script's env.
     * exec() refuses one env string past 128 KB (MAX_ARG_STRLEN), and the
     * whole request would fail with E2BIG (C12).
     */
    private const CGI_ENV_CONTEXT_MAX_BYTES = 100_000;

    /**
     * Build the OS-level environment array passed to the CGI subprocess.
     *
     * Extracted as a public static method so unit tests can assert the exact
     * env without spawning a process (reflection is not needed). Apache parity
     * reference: util_script.c ap_add_common_vars() + ap_add_cgi_vars().
     *
     * @param array<string, mixed> $server  $g->server (OpenSwoole-populated)
     * @param string               $ctx     JSON-encoded ZEALPHP_REQUEST_CONTEXT
     * @return array<string, string>
     */
    public static function buildCgiEnv(array $server, string $ctx): array
    {
        $env = [];
        $allowedPrefixes = ['HTTP_', 'REQUEST_', 'SERVER_', 'SCRIPT_', 'DOCUMENT_', 'CONTENT_', 'REMOTE_', 'QUERY_', 'PATH_', 'AUTH_'];
        foreach ($server as $k => $v) {
            if (!is_string($v)) continue;
            if ($k === 'HTTPS') {
                $env[$k] = $v;
                continue;
            }
            // SECURITY: strip HTTP_PROXY to prevent the httpoxy CVE-class attack.
            // A client-supplied "Proxy:" request header maps to HTTP_PROXY in the
            // subprocess env, which many HTTP client libraries read as proxy config.
            // Apache's fix: util_script.c:224-227 skips the "Proxy" header entirely.
            if ($k === 'HTTP_PROXY') {
                continue;
            }
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($k, $prefix)) {
                    $env[$k] = $v;
                    break;
                }
            }
        }

        // RFC 3875 mandatory vars absent from OpenSwoole's $request->server.
        if (!isset($env['GATEWAY_INTERFACE'])) {
            $env['GATEWAY_INTERFACE'] = 'CGI/1.1';
        }
        if (!isset($env['SERVER_SOFTWARE'])) {
            $env['SERVER_SOFTWARE'] = 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion();
        }
        if (!isset($env['DOCUMENT_ROOT'])) {
            $env['DOCUMENT_ROOT'] = App::resolveDocumentRoot();
        }
        if (!isset($env['SERVER_ADMIN']) && App::$server_admin !== null && App::$server_admin !== '') {
            $env['SERVER_ADMIN'] = App::$server_admin;
        }
        // AUTH_TYPE / REMOTE_USER — carry from $server if present (set by BasicAuthMiddleware);
        // already included via the AUTH_ prefix above, but add explicit fallback keys.
        if (!isset($env['AUTH_TYPE'])) {
            $authHeader = $server['HTTP_AUTHORIZATION'] ?? $server['AUTHORIZATION'] ?? '';
            if (is_string($authHeader) && stripos($authHeader, 'Basic ') === 0) {
                $env['AUTH_TYPE'] = 'Basic';
            }
        }
        if (!isset($env['REMOTE_USER']) && isset($server['REMOTE_USER']) && is_string($server['REMOTE_USER'])) {
            $env['REMOTE_USER'] = $server['REMOTE_USER'];
        }
        if (!isset($env['REMOTE_PORT']) && isset($server['REMOTE_PORT'])) {
            $rp = $server['REMOTE_PORT'];
            if (is_scalar($rp)) {
                $env['REMOTE_PORT'] = (string)$rp;
            }
        }
        if (!isset($env['PATH_TRANSLATED']) && isset($env['PATH_INFO']) && $env['PATH_INFO'] !== '') {
            $env['PATH_TRANSLATED'] = App::resolveDocumentRoot() . $env['PATH_INFO'];
        }

        $env['ZEALPHP_REQUEST_CONTEXT'] = $ctx;
        $env['ZEALPHP_CWD'] = App::$cwd;
        // cgi_worker.php shows PHP errors in the page only when the host would
        // (secure-by-default App::displayErrors(); see the worker's handler).
        $env['ZEALPHP_CGI_DISPLAY_ERRORS'] = App::displayErrors() ? '1' : '0';
        // Gate the Composer autoloader load in cgi_worker.php. Default off
        // (see App::$cgi_subprocess_autoload docblock — fixes the v0.2.41
        // WordPress wp-cron deadlock by restoring the v0.2.0 zero-overhead
        // subprocess start path).
        if (App::$cgi_subprocess_autoload) {
            $env['ZEALPHP_CGI_AUTOLOAD'] = '1';
        }

        return $env;
    }

    /**
     * Prepare the host->CGI session handoff (issue #108).
     *
     * In superglobals(true) + processIsolation(true) mode the host's
     * SessionManager opens a session at request start. If we then dispatch
     * to a CGI subprocess that ALSO opens a session, two distinct write
     * paths race the same file: the subprocess flushes its $_SESSION on
     * exit, and the host then runs its own session_write_close() in the
     * SessionManager `finally`, overwriting the subprocess's writes with
     * stale in-memory state. Additionally, the host and the subprocess
     * generate INDEPENDENT session ids on a first visit — the host sends
     * its id back via Set-Cookie, but the subprocess wrote to its own
     * unrelated file, so the next request reads an empty session.
     *
     * This helper closes both gaps:
     *   - Flushes the host's $_SESSION to disk via session_write_close()
     *     so the subprocess reads the same authoritative state the host
     *     has in memory.
     *   - Returns the host's session id so the caller can inject it into
     *     the subprocess cookie env, guaranteeing the subprocess
     *     read/writes the SAME file the host's Set-Cookie pointed the
     *     client at.
     *   - Marks `$g->_cgi_session_handoff = true` so SessionManager's
     *     finally block skips its own session_write_close — the
     *     subprocess now owns this session's lifecycle.
     *
     * Returns null when no handoff is needed (coroutine mode, session not
     * started, sessionLifecycle disabled). Safe to call repeatedly within
     * one request — the second call sees session_status() inactive and
     * returns the captured id without re-closing.
     */
    /**
     * Mint and propagate a session id on behalf of a CGI subprocess request
     * (issue #108).
     *
     * In `cgiOwnsSessions()` mode the host's SessionManager is bypassed, so
     * NOTHING on the host side emits a Set-Cookie for first-time visitors.
     * The subprocess can't emit one either: PHP's session module sends the
     * session cookie through its internal `php_setcookie()` C function, NOT
     * the userspace `setcookie()` — uopz can't intercept that path, and CLI
     * SAPI silently discards the buffered SAPI headers. Result: every first
     * visit gets a freshly generated SID inside the subprocess and zero
     * Set-Cookie on the wire, so the next request has no PHPSESSID to send
     * and the cycle repeats forever ("Value: EMPTY" loop).
     *
     * This helper closes that gap. When called from a CGI dispatcher:
     *   - If the request already has a PHPSESSID cookie, return it (no-op).
     *   - Otherwise mint a fresh id via `session_create_id()`, stash it in
     *     `$g->cookie[session_name]` so the subprocess sees it in $_COOKIE,
     *     AND emit `Set-Cookie` on the outbound response so the client uses
     *     the same id on the next request.
     *
     * `session_create_id()` is safe to call without an active session in
     * PHP 7.1+ — it just returns a fresh random string in the configured
     * session.sid_bits_per_character format.
     *
     * Returns null when the host is NOT in `cgiOwnsSessions()` mode — host
     * SessionManager already owns cookie emission there.
     */
    private static function mintCgiSession(\ZealPHP\RequestContext $g): ?string
    {
        if (!App::cgiOwnsSessions()) {
            return null;
        }
        $name = 'PHPSESSID';
        if (function_exists('session_name')) {
            $candidate = \session_name();
            if (is_string($candidate)) {
                $name = $candidate;
            }
        }
        $existing = $g->cookie[$name] ?? null;
        if (is_string($existing) && $existing !== '') {
            // Returning visitor — forward the id the client SENT. Non-polluting
            // (the client already had it) and required so the subprocess
            // read/writes the session the client's cookie points at.
            return $existing;
        }
        // #355 — LAZY: a FIRST-time visitor (no incoming PHPSESSID) must NOT be
        // minted a session id unless the app explicitly opts in. mod_php with
        // session.auto_start=0 emits no Set-Cookie and leaves $_COOKIE clean
        // for a script that never calls session_start(); the old unconditional
        // host-mint here injected an id into BOTH the request-side $_COOKIE
        // (request-input fidelity bug — a handler saw a cookie the client never
        // sent) and the response Set-Cookie (unsolicited tracking cookie that
        // defeats shared-cache caching). Eager first-visit minting stays
        // available behind App::$cgi_session_auto_start for legacy apps that
        // need the #108 "subprocess can't emit its own session cookie" workaround.
        if (!\ZealPHP\App::$cgi_session_auto_start) {
            return null;
        }
        if (!function_exists('session_create_id')) {
            return null;
        }
        $sid = \session_create_id();
        if (!is_string($sid) || $sid === '') {
            return null;
        }
        $g->cookie[$name] = $sid;
        if ($g->openswoole_response !== null && $g->openswoole_response->isWritable()) {
            // C8 — minted before the script runs, so the app's own cookie
            // params are not known yet. Keep the old attributes, except Secure,
            // which follows the host's own session cookies: ZEALPHP_SESSION_SECURE,
            // else whether this request came over HTTPS. SameSite is left
            // unset as before, so an old app's cross-site flows keep working.
            $envSecure = getenv('ZEALPHP_SESSION_SECURE');
            $secure = $envSecure !== false
                ? filter_var($envSecure, FILTER_VALIDATE_BOOLEAN)
                : App::requestIsHttps($g->server);
            $g->openswoole_response->cookie($name, $sid, 0, '/', '', $secure, true);
        }
        return $sid;
    }

    /**
     * Emit the PHPSESSID Set-Cookie AFTER a CGI subprocess that STARTED a
     * session (#108 / #355). The subprocess can't emit its own session cookie
     * (PHP's session module uses the C-internal `php_setcookie()`, which the
     * CLI SAPI discards), so it reports the active session id back in the
     * metadata frame (`session_id`); the host emits the cookie here.
     *
     * Lazy + mod_php-faithful: a script that never calls `session_start()`
     * reports no session id, so NOTHING is emitted (no unsolicited cookie). The
     * cookie is also skipped when the client already sent that exact id (no
     * redundant Set-Cookie on a returning visitor). This is the post-run
     * counterpart to `mintCgiSession()`'s pre-run client-id forwarding — together
     * they give #108 first-visit cookie delivery WITHOUT #355's eager mint.
     *
     * @param mixed $response the response wrapper carrying ->cookie()/->isWritable()
     * @param array<array-key, mixed> $meta the decoded subprocess metadata frame
     */
    private static function emitCgiSessionCookieFromMeta(
        \ZealPHP\RequestContext $g,
        mixed $response,
        array $meta
    ): void {
        if (!App::cgiOwnsSessions()) {
            return;
        }
        $sid = $meta['session_id'] ?? null;
        if (!is_string($sid) || $sid === '') {
            return; // subprocess never started a session — emit nothing.
        }
        $metaName = $meta['session_name'] ?? null;
        $name = (is_string($metaName) && $metaName !== '') ? $metaName : 'PHPSESSID';
        // Returning visitor already holds this id — no redundant Set-Cookie.
        $existing = $g->cookie[$name] ?? null;
        if (is_string($existing) && $existing === $sid) {
            return;
        }
        if (!is_object($response)) {
            return;
        }
        // NB: the response wrapper (ZealPHP\HTTP\Response) forwards cookie()/
        // isWritable() through __call, so method_exists() would report false —
        // call directly and let the wrapper forward (mirrors the $applyCookie
        // path that calls $respW->cookie(...) unconditionally above).
        if (is_callable([$response, 'isWritable']) && !$response->isWritable()) {
            return;
        }
        if (is_callable([$response, 'cookie'])) {
            $c = self::sessionCookieAttributes($meta['session_cookie'] ?? null);
            $response->cookie($name, $sid, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5]);
        }
    }

    /**
     * Set-Cookie attributes for a CGI session cookie, from the subprocess's
     * session_get_cookie_params() (C8).
     *
     * The host used to emit every CGI session cookie as
     * `(0, '/', '', secure=false, httponly=true)`, whatever the app had set
     * with session_set_cookie_params() or session.cookie_* — an app that asked
     * for Secure, SameSite, a domain, a path or a lifetime did not get it. The
     * app's values are used now. HttpOnly stays on regardless: the host always
     * set it, and an old app that never asked for it must not lose it.
     * Missing or malformed values fall back to those old defaults.
     *
     * @return array{0:int,1:string,2:string,3:bool,4:bool,5:string} expires, path, domain, secure, httponly, samesite
     */
    private static function sessionCookieAttributes(mixed $params): array
    {
        if (!is_array($params)) {
            return [0, '/', '', false, true, ''];
        }
        $lifetime = isset($params['lifetime']) && is_numeric($params['lifetime']) ? (int) $params['lifetime'] : 0;
        $path     = isset($params['path']) && is_string($params['path']) && $params['path'] !== '' ? $params['path'] : '/';
        $domain   = isset($params['domain']) && is_string($params['domain']) ? $params['domain'] : '';
        $samesite = isset($params['samesite']) && is_string($params['samesite']) ? $params['samesite'] : '';
        return [
            $lifetime > 0 ? time() + $lifetime : 0,
            $path,
            $domain,
            !empty($params['secure']),
            true,
            $samesite,
        ];
    }

    /** @var array<string, true> FastCGI upstreams already warned about in this worker. */
    private static array $fcgi_upstreams_warned = [];

    /**
     * The fcgi backend hands the request (env + raw body) to an external
     * PHP-FPM, which runs PHP's own request startup: exactly what PHP2Shell
     * (CVE-2026-109618 / 109619) attacks. setup.sh's patched-PHP guard covers
     * the interpreter ZealPHP ships, not that one. Say so once per upstream.
     */
    private static function warnUnguardedFcgiUpstream(string $address): void
    {
        if (isset(self::$fcgi_upstreams_warned[$address])) {
            return;
        }
        self::$fcgi_upstreams_warned[$address] = true;
        elog('[warn] fcgi backend: forwarding requests to the PHP-FPM at ' . $address
            . '. ZealPHP cannot check that PHP: it must carry the PHP2Shell fix '
            . '(CVE-2026-109618/109619; 8.2.35, 8.3.36, 8.4.27, 8.5.12 or a distro backport).', 'warn');
    }

    /**
     * Dispatch a legacy include to a FastCGI backend (e.g. php-fpm) via the
     * FCGI binary protocol. Used when App::cgiMode() === 'fcgi'.
     *
     * Builds CGI env via App::buildCgiEnv(), adds SCRIPT_FILENAME / SCRIPT_NAME,
     * reads the request body, calls \ZealPHP\CGI\FastCgiClient::request(), maps
     * the response (status, headers, body, stderr) back through the universal
     * return contract. The FastCgiClient socket layer is OpenSwoole-native
     * (Coroutine\Client) — the call never blocks the worker.
     *
     * On connection failure or protocol error, logs via elog() and returns 502.
     *
     * @param string|null $address     Per-backend FastCGI address override (host:port or unix:/path).
     *   null → falls back to App::$fcgi_address.
     * @param array<string,string> $extraParams  Extra FCGI params merged after buildCgiEnv()
     *   (Apache SetEnvIf / nginx fastcgi_param parity).
     */
    public static function cgiFcgi(string $path, ?string $address = null, array $extraParams = []): mixed
    {
        $g = RequestContext::instance();
        self::warnUnguardedFcgiUpstream($address ?? App::$fcgi_address);

        // Issue #108 — mint + emit a session cookie on the host side when
        // the subprocess owns sessions and the client didn't send a
        // PHPSESSID. mintCgiSession() is a no-op outside cgiOwnsSessions
        // mode, so existing flows (per-extension FCGI backends in mixed
        // mode, etc.) are unaffected.
        self::mintCgiSession($g);

        $ctx = json_encode([
            'server' => $g->server,
            'get'    => $g->get,
            'post'   => $g->post,
            'cookie' => $g->cookie,
            'files'  => $g->files,
            'env'    => $g->env ?? $_ENV,
        ], JSON_UNESCAPED_SLASHES);

        $env = self::buildCgiEnv($g->server, is_string($ctx) ? $ctx : '{}');

        // FCGI mandatory vars
        $env['SCRIPT_FILENAME'] = $path;
        $docRoot = App::resolveDocumentRoot();
        $env['SCRIPT_NAME'] = str_starts_with($path, $docRoot)
            ? '/' . ltrim(substr($path, strlen($docRoot)), '/')
            : $path;

        // Per-backend extra params (nginx fastcgi_param / Apache SetEnvIf parity)
        foreach ($extraParams as $k => $v) {
            $env[$k] = $v;
        }

        // Request body (POST data etc.)
        $stdinBody = '';
        try {
            // @phpstan-ignore-next-line — zealphp_request set by CoSessionManager before any route dispatches
            $raw = $g->zealphp_request->parent->rawContent();
            if (is_string($raw)) {
                $stdinBody = $raw;
            }
        } catch (\Throwable) {
            // No body available — proceed with empty stdin
        }

        $fcgiAddress = $address ?? App::$fcgi_address;
        try {
            $client = new \ZealPHP\CGI\FastCgiClient($fcgiAddress, App::$cgi_timeout);
            // #289 — FastCgiClient::connect() picks its socket transport by
            // coroutine context: the yielding OpenSwoole client inside a coroutine,
            // a BLOCKING socket outside one (legacy-cgi / superglobals(true), where
            // the request handler is NOT coroutine-wrapped). So request() runs
            // DIRECTLY in both contexts — no nested Coroutine::run(). The #261 wrap
            // that used to run here fixed the "API must be called in the coroutine"
            // fatal but DEADLOCKED the FCGI read: the reactor callback that started
            // Coroutine::run() was parked waiting for the very scheduler that needed
            // the reactor to deliver the socket-readable event, so every request hung
            // until cgi_timeout (#289). A blocking socket has no scheduler to deadlock.
            $response = $client->request($env, $stdinBody);
        } catch (\ZealPHP\CGI\FastCgiException $e) {
            elog("cgiFcgi: FastCGI error for {$path}: " . $e->getMessage(), 'error');
            return 502;
        } catch (\Throwable $e) {
            elog("cgiFcgi: unexpected error for {$path}: " . $e->getMessage(), 'error');
            return 502;
        }

        if ($response['stderr'] !== '') {
            elog("[fcgi] stderr: " . rtrim($response['stderr']), 'fcgi');
        }

        // Apply status
        response_set_status($response['status']);

        // #260 — apply replace-aware so an upstream's multiple same-name headers
        // (multi Set-Cookie, Link, …) all reach the wire. FastCgiClient returns an
        // ordered list of [name, value] pairs; Status is consumed above.
        self::applyCgiHeaders($g->zealphp_response, $response['headers']);

        return $response['body'];
    }

    /**
     * Run a PHP file in a separate process at true global scope (CGI-style).
     * Required for legacy apps like WordPress that depend on bare variable
     * assignments and `global` keyword declarations being seen by every file.
     *
     * The subprocess (src/cgi_worker.php) serialises status, headers, cookies
     * AND the include's return value to stderr as a single JSON line; this
     * method consumes that channel and returns the same shape executeFile()
     * would have, so the universal return contract applies in both modes.
     *
     * Streaming responses (Generator returns, text/event-stream content type)
     * are consumed inside the subprocess and streamed back through stdout;
     * this method threads them through to the OpenSwoole response and
     * returns null (the caller signals _streaming and ResponseMiddleware
     * skips its buffering).
     *
     * @param string|null $interpreter  Full path to the interpreter binary.
     *   null (default) → PHP + cgi_worker.php (existing behaviour, uopz captures).
     *   Non-null → proc_open([$interpreter, $scriptPath]) with buildCgiEnv() env
     *   (RFC 3875; CGI/1.1 Status: header parsing still works for any interpreter).
     */
    public static function cgiSubprocess(string $path, ?string $interpreter = null): mixed
    {
        $g = RequestContext::instance();

        // Issue #108 — see mintCgiSession() docblock.
        self::mintCgiSession($g);

        $ctx = json_encode([
            'server' => $g->server,
            'get'    => $g->get,
            'post'   => $g->post,
            'cookie' => $g->cookie,
            'files'  => $g->files,
            'env'    => $g->env ?? $_ENV,
        ], JSON_UNESCAPED_SLASHES);

        $ctx = is_string($ctx) ? $ctx : '{}';
        $isPhp = $interpreter === null && str_ends_with(strtolower($path), '.php');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($isPhp) {
            // C12 — the context goes to cgi_worker.php over its own pipe (fd 4),
            // not in ZEALPHP_REQUEST_CONTEXT: exec() refuses a single env
            // string past ~128 KB (E2BIG), so a large form post or cookie set
            // failed the request. C7 — the metadata line comes back on fd 3,
            // which nothing else writes, instead of being the first stderr line.
            $env = self::buildCgiEnv($g->server, '');
            unset($env['ZEALPHP_REQUEST_CONTEXT']);
            $env['ZEALPHP_CGI_META_FD'] = '3';
            $env['ZEALPHP_CGI_CONTEXT_FD'] = '4';
            $descriptors[3] = ['pipe', 'w'];
            $descriptors[4] = ['pipe', 'r'];
            // PHP target: route through cgi_worker.php for uopz header/cookie captures.
            $cgiWorker = __DIR__ . '/../cgi_worker.php';
            $cmd = PHP_BINARY . ' -d display_errors=stderr -d ' . App::subprocessStrictModeIni() . ' ' . escapeshellarg($cgiWorker) . ' ' . escapeshellarg($path);
        } else {
            $env = self::buildCgiEnv($g->server, $ctx);
            if (strlen($ctx) > self::CGI_ENV_CONTEXT_MAX_BYTES) {
                // A non-PHP script reads its request from the CGI env and stdin;
                // this copy is a convenience it cannot get past E2BIG anyway.
                unset($env['ZEALPHP_REQUEST_CONTEXT']);
            }
            if ($interpreter === null) {
                // Non-PHP file with no explicit interpreter (the `cgiScriptAlias`-only
                // path): exec the file directly, relying on its `#!` shebang line.
                $refusal = self::scriptAliasRefusal($path);
                if ($refusal !== null) {
                    elog("cgiSubprocess: {$refusal}: {$path}", 'error');
                    return 500;
                }
                $cmd = escapeshellarg($path);
            } else {
                // Non-PHP target with an explicit interpreter (e.g. `.py` + `/usr/bin/python3`).
                // The script must output CGI/1.1 headers (Content-Type + blank line + body).
                $cmd = escapeshellarg($interpreter) . ' ' . escapeshellarg($path);
            }
        }

        $process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            App::resolveDocumentRoot(),
            $env
        );

        if (!is_resource($process)) {
            elog("cgiSubprocess: failed to start process for $path", "error");
            return 500;
        }

        $deadline = microtime(true) + App::$cgi_timeout;
        if ($isPhp) {
            // cgi_worker.php reads the whole context before anything else.
            self::writeAll($pipes[4], $ctx, $deadline);
            fclose($pipes[4]);
        }
        try {
            // @phpstan-ignore-next-line — zealphp_request set by CoSessionManager before any route dispatches
            $postBody = $g->zealphp_request->parent->getContent();
            if ($postBody) self::writeAll($pipes[0], (string) $postBody, $deadline);
        } catch (\Throwable $e) {}
        fclose($pipes[0]);

        // ── Non-PHP CGI path (RFC 3875) ───────────────────────────────────
        // A raw Python/Perl/etc. CGI script — whether reached via an explicit
        // `interpreter` registration OR via shebang exec under a `cgiScriptAlias`
        // — knows nothing about the cgi_worker metadata protocol below.
        // It writes a standard CGI response to STDOUT: header lines, a blank
        // line, then the body. Parse that directly (Apache mod_cgi parity:
        // ap_scan_script_header_err_brigade_ex()). stderr is drained for error
        // visibility (cgi_common.h log_script_err()).
        if (!$isPhp) {
            return self::cgiInterpreterResponse($process, $pipes, $path);
        }

        // Protocol: CGI worker sends metadata as a single JSON line on fd 3
        // BEFORE streaming body on stdout. This enables SSE and streaming.
        // Apply a configurable read timeout (App::$cgi_timeout seconds) so a
        // hung subprocess never blocks the OpenSwoole worker indefinitely.
        // Apache parity: CGIScriptTimeout / apr_file_pipe_timeout_set() (mod_cgi.c:437,444).
        //
        // stdout and stderr are drained while we wait, so a script that writes
        // a lot to either before its metadata is sent (a flood of warnings, an
        // fwrite(STDOUT)) cannot block on a full pipe. stdout read here is the
        // start of the body.
        $early = self::readCgiMetaLine($pipes, $deadline);
        $metaLine = $early['meta'];
        $stderrRemainder = $early['stderr'];
        $bodyHead = $early['stdout'];
        if ($metaLine === '') {
            // Subprocess timed out or died without metadata — kill it.
            proc_terminate($process, 15); // SIGTERM
            $killDeadline = microtime(true) + 5.0;
            while (microtime(true) < $killDeadline) {
                $st = proc_get_status($process);
                if (!$st['running']) break;
                usleep(50000);
            }
            $st = proc_get_status($process);
            if ($st['running']) {
                proc_terminate($process, 9); // SIGKILL
            }
            // Drain remaining stderr for visibility.
            stream_set_blocking($pipes[2], true);
            $stderrRemainder .= (string) stream_get_contents($pipes[2], self::DIAG_CAP_BYTES);
            fclose($pipes[2]);
            fclose($pipes[1]);
            proc_close($process);
            if ($stderrRemainder !== '') {
                elog("[cgi_worker] (timeout) stderr: " . rtrim($stderrRemainder), "cgi_worker");
            }
            elog("cgiSubprocess: timeout after " . App::$cgi_timeout . "s for $path", "error");
            return 500;
        }

        // NOTE: stderr is deliberately NOT drained to EOF here. The worker writes
        // the metadata line and THEN the (often >64 KB) body to stdout at
        // shutdown; blocking on stderr-to-EOF before reading stdout deadlocks once
        // the body exceeds the ~64 KB OS pipe buffer — the child blocks writing the
        // stdout we aren't reading, while we block reading the stderr the child
        // won't close until it exits (the WordPress-on-proc hang, Issue 3). stdout
        // and any remaining stderr (for log visibility — Apache cgi_common.h:103-126
        // log_script_err parity) are drained CONCURRENTLY below instead.

        $streaming   = false;
        $returnValue = null;
        $hasReturn   = false;
        $meta = json_decode(trim($metaLine), true);
        if (is_array($meta)) {
            $statusCode = $meta['status_code'] ?? 200;
            response_set_status(is_numeric($statusCode) ? (int)$statusCode : 200);
            $metaHeaders = is_array($meta['headers'] ?? null) ? $meta['headers'] : [];

            // #260 — apply replace-aware so MULTIPLE same-name headers (multi
            // Set-Cookie, Link, Vary, …) all reach the wire instead of collapsing
            // to the last. Status: is consumed as the response code, not forwarded.
            self::applyCgiHeaders($g->zealphp_response, $metaHeaders);
            $metaCookies = is_array($meta['cookies'] ?? null) ? $meta['cookies'] : [];
            foreach ($metaCookies as $args) {
                if (is_array($args) && !empty($args)) {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->cookie(...$args);
                }
            }
            $metaRawCookies = is_array($meta['rawcookies'] ?? null) ? $meta['rawcookies'] : [];
            foreach ($metaRawCookies as $args) {
                if (is_array($args) && !empty($args)) {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->rawCookie(...$args);
                }
            }
            // #108/#355 — emit PHPSESSID only when the subprocess actually
            // started a session (reported via the meta frame).
            self::emitCgiSessionCookieFromMeta($g, $g->zealphp_response, $meta);
            // Detect streaming content types (SSE, chunked, event-stream)
            foreach ($metaHeaders as $pair) {
                if (is_array($pair) && count($pair) >= 2) {
                    $p0 = is_scalar($pair[0]) ? (string)$pair[0] : '';
                    $p1 = is_scalar($pair[1]) ? (string)$pair[1] : '';
                    if (strcasecmp($p0, 'Content-Type') === 0
                        && stripos($p1, 'text/event-stream') !== false) {
                        $streaming = true;
                    }
                }
            }
            // Universal return contract: the subprocess captures the file's
            // return value (int / array / string / null) and ships it here.
            // Generator/Closure returns are consumed inside the subprocess
            // and stream out as body — they appear as a `streamed` marker.
            if (array_key_exists('return_value', $meta)) {
                $hasReturn   = true;
                $returnValue = $meta['return_value'];
            }
        }

        // ── Streaming (SSE) path: forward stdout to the client progressively,
        // draining stderr non-blocking in the same select loop so warnings can
        // never wedge the child (same pipe-ordering hazard as the buffered path).
        // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
        if ($streaming && $g->openswoole_response->isWritable()) {
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->flush();
            if ($bodyHead !== '') {
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->write($bodyHead);
            }
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            while (!feof($pipes[1])) {
                $read = [$pipes[1], $pipes[2]];
                $w = $e = null;
                $sel = @stream_select($read, $w, $e, 1, 0);
                if ($sel === false) { usleep(1000); continue; } // EINTR — retry
                if ($sel === 0) { continue; }
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || $chunk === '') { continue; }
                    if ($stream === $pipes[2]) { $stderrRemainder .= $chunk; continue; }
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    if (!$g->openswoole_response->isWritable()) { break 2; }
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->write($chunk);
                }
            }
            // Grab any stderr tail the child flushed as it exited (non-blocking).
            while (($chunk = fread($pipes[2], 65536)) !== false && $chunk !== '') {
                $stderrRemainder .= $chunk;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if ($stderrRemainder !== '') {
                elog("[cgi_worker] stderr: " . rtrim($stderrRemainder), "cgi_worker");
            }
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            if ($g->openswoole_response->isWritable()) {
                \ZealPHP\Session\zeal_session_persist_before_send();
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->end();
            }
            $g->_streaming = true;
            return null;
        }

        // ── Buffered path: read stdout (body) and any remaining stderr (logging)
        // CONCURRENTLY via stream_select, so an oversized body on stdout can never
        // deadlock against an undrained stderr (Issue 3 — the WordPress-on-proc
        // hang). Bounded by a fresh App::$cgi_timeout window so a wedged child can
        // never pin the worker. stream_select yields the coroutine under HOOK_ALL
        // and is a plain blocking syscall in legacy-cgi (no scheduler).
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $body = $bodyHead;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $drainDeadline = microtime(true) + App::$cgi_timeout;
        while ($open !== []) {
            if (microtime(true) >= $drainDeadline) {
                elog("cgiSubprocess: stdout/stderr drain timeout after " . App::$cgi_timeout . "s for $path", "error");
                break;
            }
            $read = array_values($open);
            $w = $e = null;
            $sel = @stream_select($read, $w, $e, 1, 0);
            if ($sel === false) { usleep(1000); continue; } // EINTR (e.g. SIGCHLD) — retry
            if ($sel === 0) { continue; }
            foreach ($read as $stream) {
                $fd = ($stream === $pipes[1]) ? 1 : 2;
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    // select reported the pipe readable + an empty read = EOF.
                    fclose($stream);
                    unset($open[$fd]);
                    continue;
                }
                if ($fd === 1) { $body .= $chunk; } else { $stderrRemainder .= $chunk; }
            }
        }
        foreach ($open as $stream) { @fclose($stream); }
        proc_close($process);
        if ($stderrRemainder !== '') {
            elog("[cgi_worker] stderr: " . rtrim($stderrRemainder), "cgi_worker");
        }

        // Surface the file's return value when it was explicit (int / array /
        // string). Trust the subprocess: when return_value is non-null AND
        // not the default 1-from-no-return, return it. The body (echoed
        // output) is folded in only if the return was a string (echo-shell-
        // then-return-body idiom) — exactly matching executeFile().
        if ($hasReturn && $returnValue !== null && $returnValue !== 1) {
            if (is_string($returnValue) && $body !== '') {
                return $body . $returnValue;
            }
            return $returnValue;
        }
        return $body !== '' ? $body : null;
    }

    /**
     * Why a ScriptAlias target must not run, or null when it may (C11).
     *
     * Apache runs a ScriptAlias file only through its `#!` line; without one,
     * exec() fails with ENOEXEC and /bin/sh (via `sh -c` and execvp's own
     * fallback) would run the file as a shell script, whatever it contains.
     * suexec also refuses a script anyone but its owner can write. Both are
     * required here.
     */
    private static function scriptAliasRefusal(string $path): ?string
    {
        if (!is_executable($path)) {
            return 'file not executable; ScriptAlias dispatch requires +x and a #! shebang';
        }
        $perms = @fileperms($path);
        if ($perms === false || ($perms & 0022) !== 0) {
            return 'script is group- or world-writable; ScriptAlias refuses to run it (suexec parity)';
        }
        $fh = @fopen($path, 'rb');
        $magic = $fh !== false ? (string) fread($fh, 2) : '';
        if ($fh !== false) {
            fclose($fh);
        }
        if ($magic !== '#!') {
            return 'script has no #! line; ScriptAlias runs only files that name their interpreter (Apache parity)';
        }
        return null;
    }

    /**
     * Write all of `$data` to a child's pipe by `$deadline`, never blocking
     * past it. Inside a coroutine at most PIPE_BUF bytes go per writable event
     * (see WorkerPool::exchange()). Returns false on a broken pipe or timeout.
     *
     * @param resource $pipe
     */
    private static function writeAll($pipe, string $data, float $deadline): bool
    {
        stream_set_blocking($pipe, false);
        $chunk = \OpenSwoole\Coroutine::getCid() > 0 ? 4096 : 65536;
        while ($data !== '') {
            $wait = $deadline - microtime(true);
            if ($wait <= 0) {
                return false;
            }
            $wait = max(0.001, min(1.0, $wait));
            $sec = (int) $wait;
            $r = null;
            $w = [$pipe];
            $e = null;
            $sel = @stream_select($r, $w, $e, $sec, (int) (($wait - $sec) * 1_000_000));
            if ($sel === false) {
                usleep(1000); // EINTR — retry
                continue;
            }
            if ($sel === 0) {
                continue; // not writable yet (the only stream selected)
            }
            $n = @fwrite($pipe, strlen($data) > $chunk ? substr($data, 0, $chunk) : $data);
            if ($n === false) {
                return false;
            }
            $data = (string) substr($data, $n);
        }
        return true;
    }

    /**
     * Wait for cgi_worker.php's metadata line on fd 3, draining stdout and
     * stderr meanwhile (C7). Returns the line ('' on timeout or when fd 3
     * closed without one), the stdout read so far (the start of the body), and
     * the stderr read so far.
     *
     * @param array<int,resource> $pipes
     * @return array{meta:string, stdout:string, stderr:string}
     */
    private static function readCgiMetaLine(array $pipes, float $deadline): array
    {
        /** @var array<int,resource> $open */
        $open = [1 => $pipes[1], 2 => $pipes[2], 3 => $pipes[3]];
        foreach ($open as $p) {
            stream_set_blocking($p, false);
        }
        $buf = [1 => '', 2 => '', 3 => ''];
        $meta = '';
        while (true) {
            $nl = strpos($buf[3], "\n");
            if ($nl !== false) {
                $meta = substr($buf[3], 0, $nl + 1);
                break;
            }
            if (!isset($open[3]) || microtime(true) >= $deadline) {
                break;
            }
            $read = array_values($open);
            $w = $e = null;
            $wait = max(0.001, min(1.0, $deadline - microtime(true)));
            $sec = (int) $wait;
            $sel = @stream_select($read, $w, $e, $sec, (int) (($wait - $sec) * 1_000_000));
            if ($sel === false) {
                usleep(1000); // EINTR — retry
                continue;
            }
            foreach ($read as $stream) {
                $fd = (int) array_search($stream, $open, true);
                $data = @fread($stream, 65536);
                if ($data === false || $data === '') {
                    if (feof($stream)) {
                        unset($open[$fd]);
                    }
                    continue;
                }
                $cap = $fd === 2 ? self::DIAG_CAP_BYTES : self::CGI_OUTPUT_CAP_BYTES;
                if (strlen($buf[$fd]) < $cap) {
                    $buf[$fd] .= $data;
                }
            }
        }
        @fclose($pipes[3]);
        return ['meta' => $meta, 'stdout' => $buf[1], 'stderr' => $buf[2]];
    }

    /**
     * Apply CGI-captured response headers to the live response, preserving
     * MULTIPLE same-name headers (multi `Set-Cookie`, `Link`, `Vary`, …).
     *
     * #260 — the proc/pool/fork workers capture headers as an ordered list of
     * `[name, value]` pairs (the uopz `header()` override already honours each
     * `header(..., $replace)` call), but applying every captured pair with the
     * wrapper's default `$replace = true` collapsed each same-name header to the
     * LAST on the wire. Here the FIRST occurrence of a name replaces any
     * framework default; every subsequent same-name pair is appended, so the
     * worker's exact multi-header set reaches the client.
     *
     * The CGI/1.1 "Status:" pseudo-header (RFC 3875 §6.3.3) is consumed as the
     * response code and never forwarded (mod_cgi parity).
     *
     * @param mixed           $resp  a Response-like object exposing
     *                               `header(string, string, bool)` — the live
     *                               `$g->zealphp_response` (typed `mixed`) or a
     *                               test double; null/non-object emits nothing
     * @param iterable<mixed> $pairs each element a `[string $name, string $value]` pair
     */
    private static function applyCgiHeaders(mixed $resp, iterable $pairs): void
    {
        // Duck-typed: $g->zealphp_response is `mixed`, so accept any object that
        // exposes header() (the real wrapper or a test stub); null otherwise.
        $emitter = (is_object($resp) && method_exists($resp, 'header')) ? $resp : null;
        $seen = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) < 2
                || !is_scalar($pair[0]) || !is_scalar($pair[1])) {
                continue;
            }
            $name  = (string) $pair[0];
            $value = (string) $pair[1];
            if (strcasecmp($name, 'Status') === 0) {
                $codeStr = strtok($value, ' ');
                if ($codeStr !== false && ctype_digit($codeStr)) {
                    $code = (int) $codeStr;
                    if ($code >= 100 && $code <= 599) {
                        response_set_status($code);
                    }
                }
                continue;
            }
            if ($emitter === null) {
                continue;
            }
            $lc = strtolower($name);
            // First occurrence overrides any framework default; the rest append.
            $emitter->header($name, $value, !isset($seen[$lc]));
            $seen[$lc] = true;
        }
    }

    /**
     * Parse a raw CGI/1.1 interpreter response (RFC 3875) into status, headers
     * and body — pure, side-effect-free string handling.
     *
     * The header block is split from the body on the FIRST blank line, CRLF or
     * bare LF alike ({@see FastCgiClient::splitHeaderBlock()}). Header
     * lines are parsed `Name: value` (first colon splits; colons in the value
     * are preserved). The `Status: NNN Reason` pseudo-header (§6.3.3) is
     * extracted into the returned `status` (only when `NNN` is a valid 100–599
     * code) and is NOT echoed back as a header. Lines without a colon are
     * ignored.
     *
     * When `$raw` has no blank-line separator at all, the whole input is treated
     * as the body and `status`/`headers` come back empty — the caller decides
     * whether that's a malformed-header error (cgiInterpreterResponse() already
     * does, before calling this) or a bodies-only response.
     *
     * @return array{status: int|null, headers: list<array{0:string,1:string}>, body: string}
     */
    public static function parseCgiResponse(string $raw): array
    {
        // Split the CGI header block from the body on the FIRST blank line,
        // CRLF or bare LF alike (C2: a later CRLF CRLF inside the body must
        // never pull body bytes into the headers).
        $split = FastCgiClient::splitHeaderBlock($raw);
        if ($split === null) {
            return ['status' => null, 'headers' => [], 'body' => $raw];
        }
        [$headerBlock, $body] = $split;

        $status  = null;
        $headers = [];
        foreach (preg_split('/\r\n|\n/', $headerBlock) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // RFC 3875 §6.3.3 — "Status: NNN Reason" sets the HTTP status and
            // must not be forwarded to the client as a header.
            if (strcasecmp($name, 'Status') === 0) {
                $status = FastCgiClient::statusCode($value) ?? $status;
                continue;
            }

            // #260 — ordered [name, value] pair, not $headers[$name], so an
            // interpreter that emits multiple same-name headers (multi
            // Set-Cookie, …) doesn't collapse them to the last on the wire.
            $headers[] = [$name, $value];
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Consume a standard RFC 3875 CGI response from a non-PHP interpreter
     * subprocess: read stdout (header block + blank line + body), apply the
     * parsed headers / Status: pseudo-header to the response, and return the
     * body through the universal contract. stderr is drained for error logging.
     *
     * Unlike cgiSubprocess()'s PHP/cgi_worker path, there is NO stderr metadata
     * channel — a Python/Perl script just writes CGI to stdout. text/event-stream
     * responses stream chunk-by-chunk; everything else returns one buffered body.
     *
     * Apache parity: ap_scan_script_header_err_brigade_ex() (header scan + Status:),
     * log_script_err() (stderr drain), CGIScriptTimeout (read deadline).
     *
     * @param resource              $process
     * @param array<int,resource>   $pipes
     */
    private static function cgiInterpreterResponse($process, array $pipes, string $path): mixed
    {
        $g = RequestContext::instance();

        // Read the full stdout (headers + body) under the CGI timeout, draining
        // stderr in the same select loop. A trivial CGI script completes
        // near-instantly; the deadline only guards a hang.
        //
        // C10 — stderr used to be read only after stdout hit EOF, so a script
        // that wrote more than a pipe's worth (~64 KB) of warnings blocked on
        // it and the request waited out the CGI timeout. The buffered output
        // is capped too (it was unbounded): past CGI_OUTPUT_CAP_BYTES the
        // script is stopped and the request fails.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + App::$cgi_timeout;
        $raw = '';
        $stderr = '';
        $timedOut = false;
        $overCap = false;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            $read = array_values($open);
            $w = $e = null;
            $wait = max(0.001, min(1.0, $deadline - microtime(true)));
            $sec = (int) $wait;
            $sel = @stream_select($read, $w, $e, $sec, (int) (($wait - $sec) * 1_000_000));
            if ($sel === false) { usleep(1000); continue; } // EINTR — retry
            foreach ($read as $stream) {
                $fd = $stream === $pipes[1] ? 1 : 2;
                $chunk = @fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    if (feof($stream)) {
                        unset($open[$fd]);
                    }
                    continue;
                }
                if ($fd === 2) {
                    // Apache logs child stderr (log_script_err); keep a bounded copy.
                    if (strlen($stderr) < self::DIAG_CAP_BYTES) {
                        $stderr .= $chunk;
                    }
                    continue;
                }
                $raw .= $chunk;
                if (strlen($raw) > self::CGI_OUTPUT_CAP_BYTES) {
                    $overCap = true;
                    break 2;
                }
            }
        }
        fclose($pipes[2]);

        if ($overCap) {
            $timedOut = true; // same teardown: stop the script, fail the request
        }
        if ($timedOut) {
            proc_terminate($process, 15);
            $killDeadline = microtime(true) + 5.0;
            while (microtime(true) < $killDeadline) {
                $st = proc_get_status($process);
                if (!$st['running']) break;
                usleep(50000);
            }
            $st = proc_get_status($process);
            if ($st['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            proc_close($process);
            if ($stderr !== '') {
                elog("[cgi] (timeout) stderr: " . rtrim($stderr), "cgi");
            }
            elog($overCap
                ? 'cgiInterpreterResponse: output exceeds ' . self::CGI_OUTPUT_CAP_BYTES . " bytes for $path"
                : 'cgiInterpreterResponse: timeout after ' . App::$cgi_timeout . "s for $path", 'error');
            return 500;
        }

        fclose($pipes[1]);
        $exit = proc_get_status($process)['exitcode'];
        proc_close($process);

        if ($stderr !== '') {
            elog("[cgi] stderr: " . rtrim($stderr), "cgi");
        }

        // No header block at all. If the script produced nothing and exited
        // non-zero, surface a 500 (mod_cgi: "malformed header from script").
        if (FastCgiClient::splitHeaderBlock($raw) === null) {
            if ($raw === '') {
                if ($exit !== 0) {
                    elog("cgiInterpreterResponse: empty output, exit code $exit for $path", "error");
                    return 500;
                }
                return null;
            }
            elog("cgiInterpreterResponse: malformed CGI header (no blank line) for $path", "error");
            return 500;
        }

        $parsed  = self::parseCgiResponse($raw);
        $body    = $parsed['body'];
        $headers = $parsed['headers'];

        if ($parsed['status'] !== null) {
            response_set_status($parsed['status']);
        }

        // #260 — $headers is now an ordered [name, value] pair list. Detect SSE
        // by scanning the pairs, then apply replace-aware so multiple same-name
        // headers (multi Set-Cookie, …) survive instead of collapsing to the last.
        $streaming = false;
        foreach ($headers as $pair) {
            if (strcasecmp($pair[0], 'Content-Type') === 0
                && stripos($pair[1], 'text/event-stream') !== false) {
                $streaming = true;
                break;
            }
        }
        self::applyCgiHeaders($g->zealphp_response, $headers);

        // SSE / event-stream: flush headers + body immediately so EventSource
        // clients see events without waiting for the whole response.
        // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
        if ($streaming && $g->openswoole_response->isWritable()) {
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->flush();
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            if ($body !== '' && $g->openswoole_response->isWritable()) {
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->write($body);
            }
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            if ($g->openswoole_response->isWritable()) {
                \ZealPHP\Session\zeal_session_persist_before_send();
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->end();
            }
            $g->_streaming = true;
            return null;
        }

        return $body !== '' ? $body : null;
    }


    /**
     * `cgiMode('pool')` dispatch — native FCGI-style worker pool.
     *
     * Lazily creates a per-OpenSwoole-worker singleton `WorkerPool` with
     * `$cgi_pool_size` pre-spawned PHP subprocesses, then dispatches the
     * current request frame to an idle subprocess via Coroutine\Channel.
     * The subprocess executes the file with mod_php-style isolation
     * (clean global scope per request — same as `cgiMode('proc')`), then
     * writes the response frame back through the IPC pipe; the parent
     * coroutine yields the entire time (HOOK_ALL hooks pipe reads),
     * letting the OpenSwoole worker serve thousands of other coroutines
     * in parallel while pool subprocesses execute legacy PHP.
     *
     * Response-shape handling mirrors cgiFork() exactly — both consume
     * the same IPC frame format (status, headers, cookies, rawcookies,
     * body, return_value) and apply captures to $g->zealphp_response via
     * the same patterns, so the host response builder treats pool
     * dispatch identically to proc/fork.
     *
     * Auto-respawn on subprocess death (FPM-equivalent recovery
     * semantics — `proc_get_status(['running' => false])` triggers
     * `respawn(idx)`) and recycle after `$cgi_pool_max_requests`
     * (FPM `pm.max_requests` parity).
     */
    public static function cgiPool(string $path): mixed
    {
        $g = RequestContext::instance();

        if (App::$cgi_pool_instance === null) {
            try {
                App::$cgi_pool_instance = new \ZealPHP\CGI\WorkerPool(
                    size: App::$cgi_pool_size,
                    maxRequestsPerWorker: App::$cgi_pool_max_requests,
                );
            } catch (\Throwable $e) {
                elog("cgiPool: failed to spawn worker pool: " . $e->getMessage(), 'error');
                return 500;
            }
        }

        // Issue #108 — see mintCgiSession() docblock.
        self::mintCgiSession($g);

        $request = self::buildCgiRequestFrame($g, $path);

        try {
            $resp = App::$cgi_pool_instance->dispatch($request, App::$cgi_timeout > 0 ? (float) App::$cgi_timeout : 30.0);
        } catch (\Throwable $e) {
            elog("cgiPool: dispatch failed for $path: " . $e->getMessage(), 'error');
            return 500;
        }

        return self::applyCgiResponseFrame($resp);
    }

    /**
     * Fork-per-request CGI dispatch — `App::cgiMode('fork')` (Apache MPM prefork,
     * EXPERIMENTAL). Routes `.php` through a {@see ForkPool}: the fork-master
     * forks a FRESH child per request that runs the file at true global scope
     * (the #167 wp-admin fix) and dies — fresh-process correctness (no
     * "Cannot redeclare class") at fork cost (~1 ms), not proc_open cold-start.
     *
     * Shares the request-frame shape and response-apply contract with cgiPool.
     */
    public static function cgiFork(string $path): mixed
    {
        $g = RequestContext::instance();

        if (App::$cgi_fork_instance === null) {
            try {
                App::$cgi_fork_instance = new ForkPool(maxConcurrent: max(1, App::$cgi_fork_max_concurrent));
            } catch (\Throwable $e) {
                elog("cgiFork: failed to spawn fork master: " . $e->getMessage(), 'error');
                return 500;
            }
        }

        // Issue #108 — see mintCgiSession() docblock.
        self::mintCgiSession($g);

        $request = self::buildCgiRequestFrame($g, $path);

        try {
            $resp = App::$cgi_fork_instance->dispatch($request, App::$cgi_timeout > 0 ? (float) App::$cgi_timeout : 30.0);
        } catch (\Throwable $e) {
            elog("cgiFork: dispatch failed for $path: " . $e->getMessage(), 'error');
            return 500;
        }

        return self::applyCgiResponseFrame($resp);
    }

    /**
     * Build the IPC request frame from the current request context — shared by
     * cgiPool and cgiFork. The raw body feeds php://input (CgiInputStream);
     * multipart/form-data is skipped (it rides $_FILES, and the full blob could
     * exceed IPC's 64 MB cap).
     *
     * @return array<string,mixed>
     */
    private static function buildCgiRequestFrame(RequestContext $g, string $path): array
    {
        $rawBody = '';
        $ct = '';
        foreach ($g->server as $k => $v) {
            $kl = strtolower((string) $k);
            if ($kl === 'content_type' || $kl === 'http_content_type' || $kl === 'content-type') {
                $ct = is_scalar($v) ? (string) $v : '';
                break;
            }
        }
        if (stripos($ct, 'multipart/form-data') === false) {
            // zealphp_request is set by CoSessionManager before a real dispatch,
            // but is null in unit harnesses — narrow before reading ->parent so
            // a missing request wrapper degrades to an empty body, not a warning.
            $req = $g->zealphp_request;
            if ($req instanceof \ZealPHP\HTTP\Request) {
                try {
                    $rawBody = (string) $req->parent->getContent();
                } catch (\Throwable $e) {
                    $rawBody = '';
                }
            }
        }

        return [
            'file'    => $path,
            'server'  => $g->server,
            'get'     => $g->get,
            'post'    => $g->post,
            'cookies' => $g->cookie,
            'files'   => $g->files,
            'body'    => $rawBody,
        ];
    }

    /**
     * Apply a pool/fork response frame (status + headers + cookies) to the
     * response and return the body per the universal return contract — shared
     * by cgiPool and cgiFork.
     *
     * @param array<mixed,mixed> $resp
     */
    private static function applyCgiResponseFrame(array $resp): mixed
    {
        $g = RequestContext::instance();

        $statusCode = $resp['status'] ?? 200;
        response_set_status(is_numeric($statusCode) ? (int) $statusCode : 200);

        $respW = $g->zealphp_response;
        if ($respW !== null) {
            // #260 — apply replace-aware so multiple same-name headers (multi
            // Set-Cookie, Link, …) all reach the wire instead of collapsing to the
            // last. Status: is consumed as the response code (already applied above).
            self::applyCgiHeaders($respW, (array) ($resp['headers'] ?? []));
            $applyCookie = static function (callable $fn, mixed $args): void {
                if (!is_array($args) || !isset($args[0]) || !is_scalar($args[0])) return;
                $s = static fn(int $i, string $d): string =>
                    isset($args[$i]) && is_scalar($args[$i]) ? (string) $args[$i] : $d;
                $b = static fn(int $i): bool => isset($args[$i]) && (bool) $args[$i];
                $fn(
                    (string) $args[0],
                    $s(1, ''),
                    isset($args[2]) && is_numeric($args[2]) ? (int) $args[2] : 0,
                    $s(3, '/'),
                    $s(4, ''),
                    $b(5),
                    $b(6),
                    $s(7, ''),
                );
            };
            // Cookies arrive in two shapes: associative (setcookie $expires=array
            // form) OR positional (compact()-built); narrow both to positional.
            $narrow = static function (mixed $args): mixed {
                if (is_array($args) && isset($args['name']) && is_scalar($args['name'])) {
                    return [
                        (string) $args['name'],
                        isset($args['value']) && is_scalar($args['value']) ? (string) $args['value'] : '',
                        isset($args['expires']) && is_numeric($args['expires']) ? (int) $args['expires'] : 0,
                        isset($args['path']) && is_scalar($args['path']) ? (string) $args['path'] : '/',
                        isset($args['domain']) && is_scalar($args['domain']) ? (string) $args['domain'] : '',
                        !empty($args['secure']),
                        !empty($args['httponly']),
                        isset($args['samesite']) && is_scalar($args['samesite']) ? (string) $args['samesite'] : '',
                    ];
                }
                return $args;
            };
            foreach ((array) ($resp['cookies'] ?? []) as $args) {
                $applyCookie([$respW, 'cookie'], $narrow($args));
            }
            foreach ((array) ($resp['rawcookies'] ?? []) as $args) {
                $applyCookie([$respW, 'rawCookie'], $narrow($args));
            }
            // #108/#355 — emit PHPSESSID only when the pool subprocess actually
            // started a session (reported in the response frame).
            self::emitCgiSessionCookieFromMeta($g, $respW, $resp);
        }

        $body        = is_string($resp['body'] ?? null) ? $resp['body'] : '';
        $hasReturn   = array_key_exists('return_value', $resp);
        $returnValue = $resp['return_value'] ?? null;

        // Universal return contract — same as cgiSubprocess/cgiFork/executeFile.
        if ($hasReturn && $returnValue !== null && $returnValue !== 1) {
            if (is_string($returnValue) && $body !== '') {
                return $body . $returnValue;
            }
            return $returnValue;
        }
        return $body !== '' ? $body : null;
    }
}
