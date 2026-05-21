<?php
namespace ZealPHP;

use ZealPHP\ZealAPI;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use OpenSwoole\Coroutine as co;
class App
{
    /** @var array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}> */
    protected array $routes = [];
    /** @var array<string, array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}>> */
    protected array $routes_by_method = [];
    /** @var array<string, array<string, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}>> */
    protected array $routes_by_exact_method = [];
    /** @var array<string, array{message: callable, open: callable|null, close: callable|null}> */
    protected array $ws_routes = [];
    /** @var array<int, callable> */
    protected static array $workerStartHooks = [];
    /** @var array<int, callable> */
    protected static array $workerStopHooks = [];
    protected static float $workerStartedAt = 0.0;
    protected string $host;
    protected int $port;
    public static string $cwd;
    /** @var \OpenSwoole\WebSocket\Server|\OpenSwoole\Http\Server|null */
    public static $server;
    public static ?string $default_php_self = null;
    private static ?self $instance = null;
    public static bool $display_errors = true;
    public static bool $superglobals = true;
    public static ?StackHandler $middleware_stack = null;
    /** @var array<int, MiddlewareInterface> */
    public static array $middleware_wait_stack = [];
    public static bool $ignore_php_ext = true;
    public static bool $coproc_implicit_request_handler = false;
    /**
     * Per-include CGI process-isolation override. `null` means "follow
     * $superglobals" (true → CGI subprocess via cgi_worker.php; false →
     * in-process via executeFile()), which preserves today's default
     * coupling. Set via App::processIsolation(bool) — see that method for
     * the trade-offs. App::run() resolves this into the backing
     * $coproc_implicit_request_handler flag right before the server starts.
     */
    public static ?bool $process_isolation = null;
    /**
     * How a process-isolated legacy include is dispatched, when
     * processIsolation() is on:
     *
     *   'proc' (default) — proc_open() spawns a FRESH PHP interpreter per
     *                      request (src/cgi_worker.php). True global scope:
     *                      top-level `$x = ...` is visible via `global $x` in
     *                      functions, so unmodified WordPress/Drupal work. Cost:
     *                      cold PHP startup + autoload per request (~tens of ms).
     *
     *   'fork' — OpenSwoole\Process forks the already-booted worker (copy-on-
     *            write: warm interpreter, loaded autoloader, hot opcache). ~5×
     *            faster than 'proc' because nothing re-execs. TRADE-OFF: the
     *            file runs in the fork closure's FUNCTION scope, so bare
     *            top-level vars are NOT visible via the `global` keyword
     *            (`$wpdb` -style patterns break). Superglobals
     *            ($_GET/$_POST/$_SESSION/$_SERVER) work normally. Use for
     *            "modernised legacy" apps that read request state via
     *            superglobals and don't lean on bare-global wiring; keep 'proc'
     *            for unmodified WordPress/Drupal.
     *
     * Set via App::cgiMode('fork'|'proc'). Default 'proc' — no behaviour change
     * for existing isolation users.
     */
    public static string $cgi_mode = 'proc';
    /**
     * OpenSwoole `enable_coroutine` server-setting override. `null` means
     * "follow !$superglobals" (true → coroutine-per-request, false → one
     * synchronous request at a time per worker). Set via
     * App::enableCoroutine(bool). Combining true with $superglobals=true
     * is unsafe — process-wide $_GET/$_POST/$_SESSION will race across
     * concurrent coroutines; the helper warns at run() time.
     */
    public static ?bool $enable_coroutine_override = null;
    /**
     * `OpenSwoole\Runtime::enableCoroutine($flags)` override. Same shape
     * as App::hookAll() input: `null` → follow !$superglobals (HOOK_ALL when
     * coroutine mode, 0 in superglobals mode); `true` → HOOK_ALL; `false`
     * → 0; `int` → explicit bitmask. PDO is intentionally NOT hooked in
     * OpenSwoole 22.1 / 26.2 regardless of this flag.
     * @var bool|int|null
     */
    public static $hook_all_override = null;
    /** Apache DirectorySlash equivalent — redirect `/foo` → `/foo/` when foo is a directory. */
    public static bool $directory_slash = true;
    /**
     * Apache DirectoryIndex — file names tried in order when a directory is requested.
     * @var array<int, string>
     */
    public static array $directory_index = ['index.php', 'index.html', 'index.htm'];
    /** Apache PATH_INFO — when `/script.php/extra/path`, expose `/extra/path` as PATH_INFO. */
    public static bool $path_info = true;
    /**
     * Apache `AllowEncodedSlashes` — when false (default, matching Apache), a
     * request whose RAW (pre-decode) path contains an encoded slash (`%2F`/`%2f`)
     * is refused with 404 before route matching. Apache's `unescape_url()`
     * forbids `AP_SLASHES` by default; we mirror that. Set true to permit
     * encoded slashes (they are then decoded to `/` like any other octet).
     */
    public static bool $allow_encoded_slashes = false;
    /**
     * Static handler URL-prefix whitelist. Empty = serve any path under document_root (Apache default).
     * @var array<int, string>
     */
    public static array $static_handler_locations = [];
    /** Block any path containing a dotfile component (.git, .env, .htaccess, etc.). Apache convention. */
    public static bool $block_dotfiles = true;
    /**
     * Apache DocumentRoot equivalent. Relative values (the default) are
     * resolved against App::$cwd; absolute values are used as-is. Drives
     * App::include() path resolution and the implicit /{file}/{dir/uri} routes.
     */
    public static string $document_root = 'public';
    /**
     * Apache TraceEnable — defaults to OFF for security. When false (default)
     * ResponseMiddleware refuses HTTP TRACE with 405 regardless of any matching
     * route definition. Set to true only if you know you need TRACE.
     */
    public static bool $trace_enabled = false;
    /**
     * Apache AddDefaultCharset. Stored here for consumers (e.g. a future
     * CharsetMiddleware) that want a server-wide default charset to append
     * to text-ish Content-Type headers.
     */
    public static string $default_charset = 'utf-8';
    /**
     * Apache `DefaultType` / PHP `default_mimetype`. The Content-Type applied by
     * CharsetMiddleware to a response that doesn't set one itself (mod_php sends
     * `text/html` by default). Set to '' to leave untyped responses untouched.
     */
    public static string $default_mimetype = 'text/html';
    /**
     * Apache `ServerTokens`. Controls how much detail the `X-Powered-By`
     * response header advertises:
     *   'Full'  (default) → `ZealPHP + OpenSwoole`
     *   'Prod' / 'Major' / 'Minor' / 'Min' / 'OS' → `ZealPHP`
     *   'None'  (or '')   → header omitted entirely (info-leak hardening)
     * Set via App::serverTokens() before App::init().
     */
    public static string $server_tokens = 'Full';
    /**
     * Apache `FileETag`. When false, `ETagMiddleware` emits no `ETag` header
     * and never returns 304 (equivalent to `FileETag None`). Default true.
     * Set via App::fileETag() before App::init().
     */
    public static bool $file_etag = true;
    /**
     * mod_php-parity SAPI identity for the php_sapi_name() override. Default null
     * returns the real PHP_SAPI ("cli") — no behavior change. Set to a web SAPI
     * string (e.g. 'apache2handler', 'fpm-fcgi') so legacy code branching on
     * php_sapi_name() takes its web path. The PHP_SAPI *constant* is unaffected
     * (uopz cannot redefine it). Configure via App::sapiName() before App::init().
     */
    public static ?string $sapi_name = null;
    /**
     * Whether ZealPHP's per-request session lifecycle runs. Default true: the
     * SessionManager / CoSessionManager OnRequest wrapper reads the PHPSESSID
     * cookie, calls zeal_session_start(), optionally emits the Set-Cookie
     * header, and closes the session at request end. Set to false when
     * another framework (e.g. Symfony's NativeSessionStorage via the
     * zealphp-symfony bridge) owns the session lifecycle — ZealPHP then skips
     * the session-specific work but still does request-context setup
     * ($g->openswoole_request, $g->zealphp_response, error-stack reset, etc.).
     *
     * The underlying zeal_session_* uopz-overridden functions remain
     * installed and callable from user code either way; this toggle only
     * controls whether the SessionManager wrapper drives the lifecycle
     * automatically for every request.
     */
    public static bool $session_lifecycle = true;
    /**
     * Auth-hook callbacks consulted by `ZealAPI::isAuthenticated()`,
     * `::isAdmin()`, and `::getUsername()` so the framework's built-in
     * file-based API layer can delegate auth questions to whatever auth
     * system the app uses (Symfony Security, Auth0, the SelfMadeNinja stack,
     * a custom `$_SESSION['user']` check, etc.) without subclassing or
     * monkey-patching ZealAPI itself.
     *
     * Set via the fluent setters `App::authChecker()`, `App::adminChecker()`,
     * `App::usernameProvider()`. Defaults: null → ZealAPI returns the safe
     * fail-closed values (`false`, `false`, `null`). See the issue #13
     * discussion and `/learn/api` for usage.
     *
     * @var callable|null
     */
    public static $auth_checker = null;
    /** @var callable|null */
    public static $admin_checker = null;
    /** @var callable|null */
    public static $username_provider = null;
    /**
     * Apache `RewriteCond %{REQUEST_FILENAME} !-d` + `RewriteRule ^(.+)/$ /$1 [R=301,L]`.
     * When true, non-directory URIs ending in `/` are 301-redirected to the no-slash
     * form. Inverse of `$directory_slash`. Default false (keeps current behaviour).
     */
    public static bool $strip_trailing_slash = false;
    /**
     * Apache `ServerAdmin webmaster@example.com`. When set, the framework's default
     * 500/error page mentions this contact. Null disables the contact line.
     */
    public static ?string $server_admin = null;
    /**
     * Apache `ServerName www.example.com:443`. The canonical host the server
     * advertises in absolute redirects (and other absolute URL builders) when
     * `$use_canonical_name` is true. Include scheme-port if relevant; the raw
     * value is returned as-is by App::canonicalHost().
     */
    public static ?string $canonical_name = null;
    /**
     * Apache `UseCanonicalName On|Off`. When true and `$canonical_name` is set,
     * App::canonicalHost() returns the canonical name; otherwise it returns the
     * request `Host` header. Default false (Apache's default since 2.0).
     */
    public static bool $use_canonical_name = false;
    /**
     * Apache `HostnameLookups On|Off`. When true, the framework populates
     * `$g->server['REMOTE_HOST']` via `gethostbyaddr($g->server['REMOTE_ADDR'])`
     * on each request. **WARNING**: this performs a blocking reverse-DNS lookup
     * per request (mitigated by OpenSwoole's coroutine hook converting it to a
     * non-blocking async resolve, but still a measurable per-request cost). Off
     * by default — Apache's own default since 1.3.
     */
    public static bool $hostname_lookups = false;
    /**
     * Maximum seconds to wait for a CGI subprocess (proc mode) to produce
     * its metadata line on stderr. After this deadline the child receives
     * SIGTERM; if it does not exit within 5 s it receives SIGKILL. Matches
     * Apache's `CGIScriptTimeout` directive. Default 60 s.
     */
    public static int $cgi_timeout = 60;
    /**
     * CIDR list of proxy IPs whose `X-Forwarded-For` / `X-Real-IP` headers
     * App::clientIp() will trust. Empty (the default) means no proxies trusted
     * — App::clientIp() always returns `REMOTE_ADDR`. Critical for production
     * deploys behind Traefik/Caddy/nginx; without it rate limiters and access
     * logs see the proxy IP instead of the real client.
     *
     * Supports IPv4 (`10.0.0.0/8`, `192.168.1.42`) and IPv6 (`2001:db8::/32`,
     * `::1`). A bare IP without `/prefix` is treated as `/32` (v4) or `/128` (v6).
     *
     * @var array<int, string>
     */
    public static array $trusted_proxies = [];
    /**
     * Apache `LogFormat "..."`. Format string used by access_log() to render
     * each request line. Tokens (Apache mod_log_config subset):
     *
     *   %h          Remote host/IP (uses App::clientIp() when $trusted_proxies set)
     *   %l          Remote logname (always `-` — RFC 1413 ident is dead)
     *   %u          Remote user (session username if set, else `-`)
     *   %t          Time `[17/May/2026:07:30:00 +0000]`
     *   %r          First line of request "GET /foo HTTP/1.1"
     *   %>s         Final response status
     *   %b          Response body bytes (`-` when zero, CLF convention)
     *   %B          Response body bytes (0 when zero)
     *   %D          Request duration in microseconds
     *   %T          Request duration in seconds
     *   %{NAME}i    Value of request header NAME (e.g. %{Referer}i)
     *   %{NAME}o    Value of response header NAME
     *   %{NAME}e    Value of $g->server[NAME] (env)
     *   %m          Request method
     *   %U          URL path (no query string)
     *   %q          Query string (prefixed with `?` if present)
     *   %H          Request protocol ("HTTP/1.1")
     *   %v          Server name (from Host header)
     *
     * Default is Apache's NCSA combined format (the prior hardcoded ZealPHP
     * output — preserving behaviour for existing log parsers). Switch to the
     * shorter Common Log Format via:
     *   App::accessLogFormat('%h %l %u %t "%r" %>s %b');
     */
    public static string $access_log_format = '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-Agent}i"';
    /**
     * Parsed format spec cache (token list). Filled lazily by formatAccessLogLine()
     * the first time it sees a given format string. Resets when accessLogFormat()
     * is reassigned via the fluent setter.
     *
     * @var array<int, array{kind:string, arg?:string}>|null
     */
    private static ?array $access_log_format_compiled = null;
    /**
     * Apache `LimitRequestFields` — maximum number of request header fields a
     * single request may carry. Enforced at the PHP application layer: requests
     * carrying more than this many headers are rejected with 400 before route
     * dispatch. Set to 0 to disable the check (unlimited). Default 100 matches
     * Apache's compiled-in default.
     */
    public static int $limit_request_fields = 100;
    /**
     * Apache `LimitRequestFieldSize` — maximum byte length of a single request
     * header line. **NOT enforced by ZealPHP.** OpenSwoole's C-layer HTTP parser
     * owns all wire-level framing; ZealPHP only sees the already-parsed
     * `$request->header` array. The `http_header_buffer_size` option was
     * explicitly NOT passed to OpenSwoole (its option validator rejects it at
     * boot — see App::run() ~line 3748). Changing this value has no effect on
     * the actual per-header byte limit, which is governed by OpenSwoole's global
     * header-buffer size (~8 KiB default). This property is retained for
     * documentation and future compatibility only.
     */
    public static int $limit_request_field_size = 8190;
    /**
     * Apache `LimitRequestLine` — maximum byte length of the HTTP request line
     * (method + URI + protocol). **NOT enforced by ZealPHP.** OpenSwoole's C
     * parser reads the request line before any PHP code runs; there is no
     * per-request-line cap that ZealPHP can apply after the fact. OpenSwoole's
     * global `http_header_buffer_size` governs this limit at the wire level.
     * This property is retained for documentation and future compatibility only.
     */
    public static int $limit_request_line = 8190;
    /** @var array<string, mixed>|null */
    private static ?array $fallback_handler = null;
    /** Initial error_reporting level captured at boot — referenced by the per-coroutine override. */
    public static int $initial_error_reporting = E_ALL;
    /**
     * Status -> custom error handler registry (key 0 = catch-all).
     * @var array<int, array{handler:callable, param_map:array<int, array{name:string, has_default:bool, default:mixed}>, raw:bool}>
     */
    private static array $error_handlers = [];
    /**
     * IANA-registered HTTP status reason phrases (RFC 9110 §15).
     * Source: https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * (registry snapshot 2025-09-15). Phrases match the IANA "Description"
     * column verbatim — pinned exhaustively by tests/Unit/IanaStatusConformanceTest.
     *
     * Documented deviations:
     *   - 418 'I'm a teapot' — IANA lists "(Unused)"; kept as the RFC 2324 /
     *     widely-recognised extension phrase.
     *   - 306 and 418 are the only reserved/"(Unused)" codes; all other entries
     *     are IANA-assigned. 104 (temporary registration) is intentionally omitted.
     *
     * Universal return contract: handlers may return any 100-599 status — see
     * template/pages/responses.php#status-range (canonical).
     */
    private const REASON_PHRASES = [
        // 1xx Informational
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        // 2xx Success
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        // 3xx Redirection
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        // 4xx Client Errors
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        // 5xx Server Errors
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * Methods ZealPHP recognises. A request whose method is outside this set
     * gets 501 Not Implemented (Apache: M_INVALID → HTTP_NOT_IMPLEMENTED,
     * server/protocol.c:1253). Standard RFC 9110 methods plus the common
     * WebDAV verbs Apache registers in ap_method_registry_init(). A recognised
     * method that has no matching route still flows through to 404/405/fallback.
     *
     * @var array<int, string>
     */
    public const KNOWN_METHODS = [
        'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE', 'PATCH',
        'CONNECT',
        // WebDAV (RFC 4918 / 3253) — registered by Apache's method registry.
        'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK',
        'VERSION-CONTROL', 'REPORT', 'CHECKOUT', 'CHECKIN', 'UNCHECKOUT',
        'MKWORKSPACE', 'UPDATE', 'LABEL', 'MERGE', 'BASELINE-CONTROL',
        'MKACTIVITY', 'ORDERPATCH', 'ACL', 'SEARCH',
    ];

    /**
     * Coerce a handler's int return value to a valid HTTP status code.
     * Per the universal return contract, ints must be in 100-599 (RFC 7230).
     * Out-of-range values are coerced to 500 with a warning logged via elog()
     * so the bug surfaces in the debug log instead of silently downgrading.
     * Matches Apache HTTP server's behavior (out-of-range → 500).
     */
    public static function coerceStatusCode(int $status): int
    {
        if ($status >= 100 && $status < 600) {
            return $status;
        }
        \ZealPHP\elog(
            "Invalid HTTP status code returned: {$status}. Coercing to 500. "
            . "(Universal return contract allows 100-599. "
            . "See template/pages/responses.php#status-range.)"
        );
        return 500;
    }

    /**
     * Look up an IANA reason phrase for the given status code. Used by
     * emitStatus() to pass an explicit reason to OpenSwoole's two-arg
     * `$response->status($code, $reason)` — required because the native
     * one-arg form silently rejects codes missing from its internal C
     * list (notably 451, even on ext 26.x), and the request emits HTTP
     * 200 instead.
     */
    public static function reasonPhrase(int $status): string
    {
        return self::REASON_PHRASES[$status] ?? '';
    }

    /**
     * Set the response status via OpenSwoole's two-arg form so codes its
     * native list doesn't recognise still emit correctly on the wire.
     * Empty reason → defer to OpenSwoole's default (which has its own
     * built-in phrasing for the common codes).
     */
    public static function emitStatus(\OpenSwoole\HTTP\Response $response, int $status): void
    {
        $reason = self::reasonPhrase($status);
        if ($reason !== '') {
            $response->status($status, $reason);
        } else {
            $response->status($status);
        }
    }

    private function __construct(string $host = '0.0.0.0', int $port = 8080, string $cwd = __DIR__)
    {
        # if uopz not enabled, throw error
        if (!extension_loaded('uopz')) {
            throw new \Exception("uopz extension is required for ZealPHP to work, 'pecl install uopz' to install and load it in your php.ini");
        }
        $this->host = $host;
        $this->port = $port;
        self::$cwd = $cwd;

        //TODO: $_ENV - read from /etc/environment, make this optional?
        $_ENV = [];
        if (file_exists('/etc/environment')) {
            $env = file_get_contents('/etc/environment');
            if ($env === false) { $env = ''; }
            $env = explode("\n", $env);
            foreach ($env as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                list($key, $value) = explode('=', $line, 2);
                $_ENV[$key] = $value;
            }
        }

        // Capture initial error_reporting BEFORE uopz overrides it (else our override
        // would self-recurse trying to read the "native" default).
        self::$initial_error_reporting = \error_reporting();

        // Install ONE process-level native error/exception handler before uopz
        // overrides. After uopz takes over set_error_handler / set_exception_handler,
        // user-space calls store handlers in G (per-coroutine). Real PHP errors
        // raised by the engine still go through THIS native dispatcher, which
        // reads the current coroutine's G stack — giving per-coroutine isolation.
        \set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            $g = \ZealPHP\RequestContext::instance();
            $level = $g->error_reporting_level ?? \ZealPHP\App::$initial_error_reporting;
            if (!($severity & $level)) {
                return true; // suppressed by error_reporting
            }
            $stack = $g->error_handlers_stack;
            if (!empty($stack)) {
                $top = $stack[count($stack) - 1];
                [$callable, $levels] = $top;
                if ($severity & $levels) {
                    try {
                        return (bool)$callable($severity, $message, $file, $line);
                    } catch (\Throwable $e) {
                        // Avoid loops; let PHP default handle if user handler explodes.
                        return false;
                    }
                }
            }
            return false; // PHP default handler
        });

        \set_exception_handler(static function (\Throwable $e) {
            $g = \ZealPHP\RequestContext::instance();
            $stack = $g->exception_handlers_stack;
            if (!empty($stack)) {
                try {
                    $stack[count($stack) - 1]($e);
                } catch (\Throwable $e2) {
                    // swallow
                }
            }
        });

        // Capture native phpinfo(INFO_MODULES) text ONCE before overriding phpinfo,
        // so PhpInfo can surface extension-specific detail without recursing into
        // its own override (and without per-request uopz_unset races). \phpinfo here
        // is still the original built-in — the uopz override is installed below.
        \ob_start();
        \phpinfo(INFO_MODULES);
        \ZealPHP\Diagnostics\PhpInfo::primeModuleText((string) \ob_get_clean());

        // Built-ins always present in CLI/OpenSwoole — uopz can override directly.
        \uopz_set_return('header', \Closure::fromCallable('\ZealPHP\header'), true);
        \uopz_set_return('header_remove', \Closure::fromCallable('\ZealPHP\header_remove'), true);
        \uopz_set_return('headers_list', \Closure::fromCallable('\ZealPHP\headers_list'), true);
        \uopz_set_return('headers_sent', \Closure::fromCallable('\ZealPHP\headers_sent'), true);
        \uopz_set_return('setcookie', \Closure::fromCallable('\ZealPHP\setcookie') , true);
        \uopz_set_return('setrawcookie', \Closure::fromCallable('\ZealPHP\setrawcookie') , true);
        \uopz_set_return('http_response_code', \Closure::fromCallable('\ZealPHP\http_response_code'), true);
        \uopz_set_return('flush', \Closure::fromCallable('\ZealPHP\flush'), true);
        \uopz_set_return('ob_flush', \Closure::fromCallable('\ZealPHP\ob_flush'), true);
        \uopz_set_return('ob_end_flush', \Closure::fromCallable('\ZealPHP\ob_end_flush'), true);
        \uopz_set_return('ob_implicit_flush', \Closure::fromCallable('\ZealPHP\ob_implicit_flush'), true);
        \uopz_set_return('set_time_limit', \Closure::fromCallable('\ZealPHP\set_time_limit'), true);
        \uopz_set_return('ignore_user_abort', \Closure::fromCallable('\ZealPHP\ignore_user_abort'), true);
        \uopz_set_return('connection_status', \Closure::fromCallable('\ZealPHP\connection_status'), true);
        \uopz_set_return('connection_aborted', \Closure::fromCallable('\ZealPHP\connection_aborted'), true);
        \uopz_set_return('output_add_rewrite_var', \Closure::fromCallable('\ZealPHP\output_add_rewrite_var'), true);
        \uopz_set_return('output_reset_rewrite_vars', \Closure::fromCallable('\ZealPHP\output_reset_rewrite_vars'), true);
        \uopz_set_return('is_uploaded_file', \Closure::fromCallable('\ZealPHP\is_uploaded_file'), true);
        \uopz_set_return('move_uploaded_file', \Closure::fromCallable('\ZealPHP\move_uploaded_file'), true);
        \uopz_set_return('phpinfo', \Closure::fromCallable('\ZealPHP\phpinfo'), true);
        \uopz_set_return('php_sapi_name', \Closure::fromCallable('\ZealPHP\php_sapi_name'), true);
        \uopz_set_return('filter_input', \Closure::fromCallable('\ZealPHP\filter_input'), true);
        \uopz_set_return('filter_input_array', \Closure::fromCallable('\ZealPHP\filter_input_array'), true);
        \uopz_set_return('header_register_callback', \Closure::fromCallable('\ZealPHP\header_register_callback'), true);
        \uopz_set_return('error_log', \Closure::fromCallable('\ZealPHP\error_log'), true);
        // Per-coroutine error/exception/shutdown handler registry.
        \uopz_set_return('set_error_handler', \Closure::fromCallable('\ZealPHP\set_error_handler'), true);
        \uopz_set_return('restore_error_handler', \Closure::fromCallable('\ZealPHP\restore_error_handler'), true);
        \uopz_set_return('set_exception_handler', \Closure::fromCallable('\ZealPHP\set_exception_handler'), true);
        \uopz_set_return('restore_exception_handler', \Closure::fromCallable('\ZealPHP\restore_exception_handler'), true);
        \uopz_set_return('register_shutdown_function', \Closure::fromCallable('\ZealPHP\register_shutdown_function'), true);
        \uopz_set_return('error_reporting', \Closure::fromCallable('\ZealPHP\error_reporting'), true);
        // Apache-only built-ins (apache_*, getallheaders, virtual) are NOT defined
        // in CLI SAPI; uopz can't override what doesn't exist. They are registered
        // as global shims via src/apache_shims.php (composer files autoload) that
        // delegate to the same \ZealPHP\* namespaced implementations.
        \uopz_set_return('session_start', \Closure::fromCallable('\ZealPHP\Session\zeal_session_start'), true);
        \uopz_set_return('session_id', \Closure::fromCallable('\ZealPHP\Session\zeal_session_id'), true);
        \uopz_set_return('session_status', \Closure::fromCallable('\ZealPHP\Session\zeal_session_status'), true);
        \uopz_set_return('session_name', \Closure::fromCallable('\ZealPHP\Session\zeal_session_name'), true);
        \uopz_set_return('session_write_close', \Closure::fromCallable('\ZealPHP\Session\zeal_session_write_close'), true);
        \uopz_set_return('session_destroy', \Closure::fromCallable('\ZealPHP\Session\zeal_session_destroy'), true);
        \uopz_set_return('session_unset', \Closure::fromCallable('\ZealPHP\Session\zeal_session_unset'), true);
        \uopz_set_return('session_regenerate_id', \Closure::fromCallable('\ZealPHP\Session\zeal_session_regenerate_id'), true);
        \uopz_set_return('session_get_cookie_params', \Closure::fromCallable('\ZealPHP\Session\zeal_session_get_cookie_params'), true);
        \uopz_set_return('session_set_cookie_params', \Closure::fromCallable('\ZealPHP\Session\zeal_session_set_cookie_params'), true);
        \uopz_set_return('session_cache_limiter', \Closure::fromCallable('\ZealPHP\Session\zeal_session_cache_limiter'), true);
        \uopz_set_return('session_cache_expire', \Closure::fromCallable('\ZealPHP\Session\zeal_session_cache_expire'), true);
        \uopz_set_return('session_commit', \Closure::fromCallable('\ZealPHP\Session\zeal_session_commit'), true);
        \uopz_set_return('session_abort', \Closure::fromCallable('\ZealPHP\Session\zeal_session_abort'), true);
        \uopz_set_return('session_encode', \Closure::fromCallable('\ZealPHP\Session\zeal_session_encode'), true);
        \uopz_set_return('session_decode', \Closure::fromCallable('\ZealPHP\Session\zeal_session_decode'), true);
        \uopz_set_return('session_save_path', \Closure::fromCallable('\ZealPHP\Session\zeal_session_save_path'), true);
        \uopz_set_return('session_module_name', \Closure::fromCallable('\ZealPHP\Session\zeal_session_module_name'), true);
    }

    /**
     * Initializes the application.
     *
     * @param string $host The host address to bind to. Defaults to '0.0.0.0'.
     * @param int    $port The port number to bind to. Defaults to 8080.
     * @param string $cwd  The current working directory. Defaults to the directory of the script.
     *
     * @return App
     */
    public static function init($host = '0.0.0.0', $port = 8080, $cwd=null): App
    {
        if ($cwd === null) {
            $php_self = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1)[0]['file'] ?? '';
            $file_name = '/'.basename($php_self);
            $cwd = dirname($php_self);
            self::$default_php_self = $file_name;
            $stack = (new StackHandler())->add(new ResponseMiddleware());
            assert($stack instanceof StackHandler);
            self::$middleware_stack = $stack;
        }
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
            \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        }
        if (self::$instance == null) {
            self::$instance = new App($host, $port, $cwd);
        } else {
            elog("App already initialized", "warn");
        }
        return self::$instance;
    }

    public static function superglobals(bool $enable = true): void
    {
        self::$superglobals = $enable;
    }

    // -----------------------------------------------------------------------
    // Fluent configuration accessors. The convention: pass null (or no arg)
    // to read the current value; pass a non-null value to set and return it.
    // Backing static properties stay public for BC — these methods are the
    // documented API surface and what the converter bot is taught to emit.
    // -----------------------------------------------------------------------

    public static function ignorePhpExt(?bool $on = null): bool
    {
        if ($on !== null) self::$ignore_php_ext = $on;
        return self::$ignore_php_ext;
    }

    public static function directorySlash(?bool $on = null): bool
    {
        if ($on !== null) self::$directory_slash = $on;
        return self::$directory_slash;
    }

    /**
     * @param array<int, string>|null $files
     * @return array<int, string>
     */
    public static function directoryIndex(?array $files = null): array
    {
        if ($files !== null) self::$directory_index = $files;
        return self::$directory_index;
    }

    public static function pathInfo(?bool $on = null): bool
    {
        if ($on !== null) self::$path_info = $on;
        return self::$path_info;
    }

    /**
     * @param array<int, string>|null $prefixes
     * @return array<int, string>
     */
    public static function staticHandlerLocations(?array $prefixes = null): array
    {
        if ($prefixes !== null) self::$static_handler_locations = $prefixes;
        return self::$static_handler_locations;
    }

    public static function blockDotfiles(?bool $on = null): bool
    {
        if ($on !== null) self::$block_dotfiles = $on;
        return self::$block_dotfiles;
    }

    public static function displayErrors(?bool $on = null): bool
    {
        if ($on !== null) self::$display_errors = $on;
        return self::$display_errors;
    }

    /**
     * Apache DocumentRoot equivalent. Relative path → resolved against cwd;
     * absolute path → used as-is. Drives App::include() resolution and the
     * implicit-route file lookups.
     */
    public static function documentRoot(?string $path = null): string
    {
        if ($path !== null) self::$document_root = $path;
        return self::$document_root;
    }

    /** Apache TraceEnable. Default OFF for security (XST attack vector). */
    public static function traceEnabled(?bool $on = null): bool
    {
        if ($on !== null) self::$trace_enabled = $on;
        return self::$trace_enabled;
    }

    /** Apache AddDefaultCharset. Server-wide default. */
    public static function defaultCharset(?string $charset = null): string
    {
        if ($charset !== null) self::$default_charset = $charset;
        return self::$default_charset;
    }

    /**
     * Apache `DefaultType` / PHP `default_mimetype`. The Content-Type
     * CharsetMiddleware applies to responses that don't set one. Pass '' to
     * disable. No-arg call returns the current value.
     */
    public static function defaultMimeType(?string $type = null): string
    {
        if ($type !== null) self::$default_mimetype = $type;
        return self::$default_mimetype;
    }

    /**
     * Apache `ServerTokens`. Controls the `X-Powered-By` header detail.
     * No-arg call returns the current setting. See `App::$server_tokens`.
     */
    public static function serverTokens(?string $tokens = null): string
    {
        if ($tokens !== null) self::$server_tokens = $tokens;
        return self::$server_tokens;
    }

    /**
     * Apache `FileETag`. false ⇒ `ETagMiddleware` emits no ETag and never 304s
     * (`FileETag None`). No-arg call returns the current value.
     */
    public static function fileETag(?bool $enabled = null): bool
    {
        if ($enabled !== null) self::$file_etag = $enabled;
        return self::$file_etag;
    }

    /**
     * Resolve the `X-Powered-By` header value for the current `ServerTokens`
     * setting, or null when the header should be omitted. Consumed at the
     * response-emission boundary; exposed for introspection/testing.
     */
    public static function poweredByHeader(): ?string
    {
        return match (strtolower(self::$server_tokens)) {
            'none', '' => null,
            'full'     => 'ZealPHP + OpenSwoole',
            default    => 'ZealPHP',
        };
    }

    /**
     * mod_php-parity SAPI name reported by the php_sapi_name() override.
     * No-arg call returns the current setting (null = report real PHP_SAPI);
     * one-arg call opts in to a web SAPI string for legacy-app compatibility.
     */
    public static function sapiName(?string $name = null): ?string
    {
        if ($name !== null) self::$sapi_name = $name;
        return self::$sapi_name;
    }

    /**
     * Detect whether the request arrived over TLS, for deriving REQUEST_SCHEME /
     * HTTPS in the $_SERVER builder. Mirrors the session-cookie secure detection
     * (src/Session/utils.php): a direct HTTPS=on, an X-Forwarded-Proto: https from
     * a proxy, or SERVER_PORT 443.
     *
     * @param array<string, mixed> $srv
     */
    private static function requestIsHttps(array $srv): bool
    {
        $https = $srv['HTTPS'] ?? '';
        if (is_scalar($https) && strtolower((string)$https) === 'on') {
            return true;
        }
        $proto = $srv['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_scalar($proto) && strtolower((string)$proto) === 'https') {
            return true;
        }
        $port = $srv['SERVER_PORT'] ?? '';
        return is_scalar($port) && (string)$port === '443';
    }

    /**
     * Toggle ZealPHP's per-request session lifecycle. When disabled, the
     * SessionManager / CoSessionManager OnRequest wrapper skips
     * session_start / cookie emission / session write-close — request-context
     * init (openswoole_request, zealphp_response, error-stack reset) still
     * runs unconditionally. Use this when another framework (e.g. Symfony's
     * NativeSessionStorage via the zealphp-symfony bridge) owns sessions and
     * you don't want ZealPHP racing it for the PHPSESSID cookie. The
     * zeal_session_* uopz overrides remain installed and callable from user
     * code either way.
     */
    public static function sessionLifecycle(?bool $enabled = null): bool
    {
        if ($enabled !== null) self::$session_lifecycle = $enabled;
        return self::$session_lifecycle;
    }

    /**
     * Register a callback that `ZealAPI::isAuthenticated()` consults.
     * Signature: `fn(): bool`. The callback decides whether the current
     * request is authenticated — typically by reading `$_SESSION`,
     * `$g->session`, or your own auth state.
     *
     * Without this hook, `ZealAPI::isAuthenticated()` returns `false`
     * (fail-closed default), so any API endpoint guarded by
     * `requirePostAuth()` rejects every request. Fixes the gap surfaced
     * in [issue #13](https://github.com/sibidharan/zealphp/issues/13).
     *
     * Pass `null` (or omit the argument and rely on the existing value)
     * to read the current checker. Pass a callable to install one.
     *
     * Example:
     *   App::authChecker(fn() => !empty($_SESSION['user_id']));
     *   App::authChecker(fn() => MyAuth::status() === MyAuth::LOGGED_IN);
     *
     * @param callable|null $fn
     */
    public static function authChecker(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$auth_checker = $fn;
        return self::$auth_checker;
    }

    /**
     * Register a callback that `ZealAPI::isAdmin()` consults.
     * Same shape as `authChecker()` — `fn(): bool`, default null.
     *
     * @param callable|null $fn
     */
    public static function adminChecker(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$admin_checker = $fn;
        return self::$admin_checker;
    }

    /**
     * Register a callback that `ZealAPI::getUsername()` consults.
     * Signature: `fn(): ?string`. Default null → `getUsername()` returns
     * null.
     *
     * @param callable|null $fn
     */
    public static function usernameProvider(?callable $fn = null): ?callable
    {
        if (func_num_args() > 0) self::$username_provider = $fn;
        return self::$username_provider;
    }

    /**
     * Per-include CGI process isolation (Apache mod_php-style fresh process
     * per file). When true (the default in superglobals mode),
     * App::include() dispatches each .php file through cgi_worker.php via
     * proc_open() — global state (defined classes, constants, ini_set,
     * output handlers) is contained inside the subprocess. When false,
     * runs in-process via executeFile() — saves the ~30-50ms proc_open +
     * PHP startup + autoloader cost per call, but every include shares the
     * worker's PHP arena.
     *
     * Set to false when the legacy code is well-behaved enough to coexist
     * in a shared worker (Symfony, Laravel, modern PHP apps). Keep true
     * for unmodified WordPress / Drupal where define()-heavy plugins assume
     * a fresh process per request.
     *
     * `null` (default) means "follow App::$superglobals" — preserves the
     * historical pairing so callers that don't touch this knob see no
     * behaviour change. App::run() resolves null into the backing
     * $coproc_implicit_request_handler bool right before the server starts.
     */
    public static function processIsolation(?bool $on = null): bool
    {
        if (func_num_args() > 0) self::$process_isolation = $on;
        return self::$process_isolation ?? self::$superglobals;
    }

    /**
     * Select how a process-isolated legacy include is dispatched: 'proc'
     * (default, fresh PHP per request via proc_open — true global scope, full
     * WordPress/Drupal compatibility) or 'fork' (warm OpenSwoole\Process fork
     * of the booted worker — ~5× faster, but function-scope so bare-`global`
     * wiring breaks). See App::$cgi_mode for the full trade-off. No-arg call
     * returns the current mode. Only takes effect when processIsolation() is on.
     */
    public static function cgiMode(?string $mode = null): string
    {
        if ($mode !== null) {
            if ($mode !== 'proc' && $mode !== 'fork') {
                throw new \InvalidArgumentException("App::cgiMode() expects 'proc' or 'fork', got '{$mode}'.");
            }
            self::$cgi_mode = $mode;
        }
        return self::$cgi_mode;
    }

    /**
     * OpenSwoole's `enable_coroutine` server setting — whether each inbound
     * HTTP request is auto-wrapped in its own coroutine. When false,
     * requests run synchronously one at a time per worker (a worker
     * handling request N blocks any other inbound request until N
     * completes). When true, requests can yield on hooked I/O and other
     * requests dispatched on the same worker make progress.
     *
     * Default coupling is `!App::$superglobals` — running coroutines in
     * superglobals mode races the process-wide $_GET/$_POST/$_SESSION
     * arrays across concurrent requests, the original bug ZealPHP's
     * per-coroutine $g context was designed to avoid. **Setting this to
     * true while $superglobals=true is REFUSED — App::run() throws
     * RuntimeException at boot (v0.2.27+).**
     *
     * `null` follows the default coupling.
     */
    public static function enableCoroutine(?bool $on = null): bool
    {
        if (func_num_args() > 0) self::$enable_coroutine_override = $on;
        return self::$enable_coroutine_override ?? !self::$superglobals;
    }

    /**
     * `OpenSwoole\Runtime::enableCoroutine($flags)` — process-wide PHP
     * I/O hooks that make blocking calls (fopen, fread, curl, mysqli,
     * etc.) yield to the coroutine scheduler instead of blocking the
     * worker. PDO is intentionally NOT hooked in OpenSwoole 22.1 / 26.2
     * regardless of this flag — Doctrine queries always block.
     *
     * Default coupling is `!App::$superglobals` (HOOK_ALL when coroutine
     * mode, 0 when superglobals mode). Hooked I/O in superglobals mode is
     * **unsafe** — yields can expose process-wide superglobal mutations
     * to other concurrent coroutines. App::run() throws RuntimeException
     * at boot for that combination (v0.2.27+).
     *
     * Accepts:
     *  - null  → follow default coupling
     *  - true  → HOOK_ALL
     *  - false → 0 (no hooks)
     *  - int   → explicit flag bitmask (HOOK_TCP | HOOK_FILE | ...)
     *
     * Returns the resolved int flag bitmask currently in effect.
     *
     * @param bool|int|null $on
     */
    public static function hookAll($on = null): int
    {
        if (func_num_args() > 0) self::$hook_all_override = $on;
        $v = self::$hook_all_override;
        if ($v === null)  return self::$superglobals ? 0 : \OpenSwoole\Runtime::HOOK_ALL;
        if ($v === true)  return \OpenSwoole\Runtime::HOOK_ALL;
        if ($v === false) return 0;
        return (int) $v;
    }

    /**
     * Refuse to start with lifecycle combinations that race process-wide
     * superglobals across concurrent coroutines.
     *
     * History: pre-v0.2.27 these were elog()'d at warn level so they
     * landed in /tmp/zealphp/debug.log but didn't refuse — the rationale
     * was "users may have niche reasons (security audits, debugging)". In
     * practice the warning was invisible to anyone not actively reading
     * the debug log, and the unsafe configuration is how cross-request
     * state-leak bugs ship to production. v0.2.27 changes this to a hard
     * throw at App::run() boot — fail loud, fail fast, before any
     * request can be served against a broken contract.
     *
     * @throws \RuntimeException When superglobals(true) is combined with
     *   enableCoroutine(true) or hookAll(non-zero) — both expose
     *   $_GET/$_POST/$_SESSION (process-wide PHP arrays) to concurrent
     *   coroutine writes, which races across requests.
     */
    private static function validateLifecycleCombination(bool $sg, int $hookFlags, bool $enableCo): void
    {
        if ($sg && $enableCo) {
            throw new \RuntimeException(
                'ZealPHP lifecycle: App::superglobals(true) + App::enableCoroutine(true) is unsafe. '
                . 'Concurrent coroutines would race $_GET/$_POST/$_SESSION (process-wide PHP arrays). '
                . 'Use App::superglobals(false) for coroutine concurrency, or App::enableCoroutine(false) '
                . 'to keep legacy superglobals semantics with sequential request handling per worker '
                . '(Apache prefork MPM-style). Refer to /coroutines#lifecycle-modes for the supported '
                . 'mode matrix.'
            );
        }
        if ($sg && $hookFlags !== 0) {
            throw new \RuntimeException(
                'ZealPHP lifecycle: App::superglobals(true) + App::hookAll(non-zero) is unsafe. '
                . 'Hooked I/O can yield mid-request, exposing process-wide superglobal mutations to '
                . 'other concurrent coroutines. Use App::superglobals(false) when enabling I/O hooks, '
                . 'or App::hookAll(0) to keep legacy superglobals semantics. Refer to '
                . '/coroutines#lifecycle-modes for the supported mode matrix.'
            );
        }
    }

    /**
     * Apache `RewriteCond %{REQUEST_FILENAME} !-d; RewriteRule ^(.+)/$ /$1 [R=301,L]`.
     * Inverse of directorySlash(). When true, non-directory URIs ending in `/`
     * 301-redirect to the no-slash form. Default off.
     */
    public static function stripTrailingSlash(?bool $on = null): bool
    {
        if ($on !== null) self::$strip_trailing_slash = $on;
        return self::$strip_trailing_slash;
    }

    /**
     * Apache `ServerAdmin`. Contact email/identifier embedded in the framework's
     * default error pages. Pass null (or '') to clear.
     */
    public static function serverAdmin(?string $admin = null): ?string
    {
        if (func_num_args() > 0) {
            self::$server_admin = ($admin === '' ? null : $admin);
        }
        return self::$server_admin;
    }

    /**
     * Apache `ServerName`. Canonical host advertised in absolute redirects
     * when useCanonicalName() is on. Pass null/'' to clear.
     */
    public static function canonicalName(?string $name = null): ?string
    {
        if (func_num_args() > 0) {
            self::$canonical_name = ($name === '' ? null : $name);
        }
        return self::$canonical_name;
    }

    /** Apache `UseCanonicalName`. See $use_canonical_name docblock. */
    public static function useCanonicalName(?bool $on = null): bool
    {
        if ($on !== null) self::$use_canonical_name = $on;
        return self::$use_canonical_name;
    }

    /** Apache `HostnameLookups`. Default false — blocking DNS is a perf cost. */
    public static function hostnameLookups(?bool $on = null): bool
    {
        if ($on !== null) self::$hostname_lookups = $on;
        return self::$hostname_lookups;
    }

    /**
     * Trusted proxy CIDRs consulted by App::clientIp().
     *
     * @param  array<int, string>|null $cidrs
     * @return array<int, string>
     */
    public static function trustedProxies(?array $cidrs = null): array
    {
        if ($cidrs !== null) self::$trusted_proxies = array_values($cidrs);
        return self::$trusted_proxies;
    }

    /** Apache `LogFormat`. Resets the compiled-spec cache on set. */
    public static function accessLogFormat(?string $format = null): string
    {
        if ($format !== null) {
            self::$access_log_format = $format;
            self::$access_log_format_compiled = null;
        }
        return self::$access_log_format;
    }

    /** Apache `LimitRequestFields`. */
    public static function limitRequestFields(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_fields = max(0, $n);
        return self::$limit_request_fields;
    }

    /** Apache `LimitRequestFieldSize`. Maps to OpenSwoole http_header_buffer_size. */
    public static function limitRequestFieldSize(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_field_size = max(0, $n);
        return self::$limit_request_field_size;
    }

    /** Apache `LimitRequestLine`. Advisory; OpenSwoole's header buffer covers it. */
    public static function limitRequestLine(?int $n = null): int
    {
        if ($n !== null) self::$limit_request_line = max(0, $n);
        return self::$limit_request_line;
    }

    /**
     * Resolve the real client IP for the current request, honouring the
     * `$trusted_proxies` allow-list. Behaviour:
     *
     *   1. Read REMOTE_ADDR from $g->server (the direct peer).
     *   2. If REMOTE_ADDR is NOT in any trusted_proxies CIDR, return it as-is.
     *      The peer is untrusted, so any X-Forwarded-* header it sent is a lie.
     *   3. If REMOTE_ADDR IS in a trusted CIDR, walk X-Forwarded-For right-to-left
     *      (Apache mod_remoteip semantics) and return the rightmost IP that is
     *      NOT in trusted_proxies — that's the real client. If every entry is
     *      trusted, fall back to the leftmost address.
     *   4. If X-Forwarded-For is absent but X-Real-IP is present (and the peer
     *      is trusted), return X-Real-IP.
     *
     * Returns the empty string when no IP can be determined (REMOTE_ADDR missing
     * entirely — only happens for non-request contexts like CLI invocation).
     */
    public static function clientIp(): string
    {
        $g = \ZealPHP\RequestContext::instance();
        $remote = (string)($g->server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return '';
        }
        if (empty(self::$trusted_proxies) || !self::peerInTrustedProxies($remote)) {
            return $remote;
        }

        $forwarded = (string)($g->server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $chain = array_map('trim', explode(',', $forwarded));
            for ($i = count($chain) - 1; $i >= 0; $i--) {
                $ip = $chain[$i];
                if ($ip === '') continue;
                if (!self::peerInTrustedProxies($ip)) {
                    return $ip;
                }
            }
            // Every hop is trusted — fall back to the first (originator) entry.
            // $chain is guaranteed non-empty here: $forwarded !== '' and explode
            // always yields at least one element, so [0] is always defined.
            $first = $chain[0];
            return $first !== '' ? $first : $remote;
        }

        $realIp = (string)($g->server['HTTP_X_REAL_IP'] ?? '');
        if ($realIp !== '') {
            return $realIp;
        }
        return $remote;
    }

    /**
     * Match $ip against every entry in App::$trusted_proxies. Wrapper so the
     * CIDR walk lives in one place; callers pass user-controlled input here so
     * the per-entry guard inside cidrContains() is the only validation needed.
     */
    private static function peerInTrustedProxies(string $ip): bool
    {
        foreach (self::$trusted_proxies as $cidr) {
            if (self::cidrContains((string)$cidr, $ip)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does $ip fall within $cidr? Supports IPv4 and IPv6. A bare IP without
     * `/prefix` is treated as a single-host range (/32 v4, /128 v6). Returns
     * false on any parse failure rather than throwing — defensive for header-
     * sourced input.
     */
    private static function cidrContains(string $cidr, string $ip): bool
    {
        if ($cidr === '' || $ip === '') return false;

        $slash = strpos($cidr, '/');
        $net   = $slash === false ? $cidr : substr($cidr, 0, $slash);
        $bits  = $slash === false ? null  : (int)substr($cidr, $slash + 1);

        $netPacked = @inet_pton($net);
        $ipPacked  = @inet_pton($ip);
        if ($netPacked === false || $ipPacked === false) return false;
        // Different address families (v4 vs v6) never match.
        if (strlen($netPacked) !== strlen($ipPacked)) return false;

        $maxBits = strlen($netPacked) * 8;
        if ($bits === null) $bits = $maxBits;
        if ($bits < 0 || $bits > $maxBits) return false;
        if ($bits === 0) return true;  // 0.0.0.0/0 or ::/0 matches everything

        $fullBytes = intdiv($bits, 8);
        $remBits   = $bits % 8;
        if ($fullBytes > 0 && substr($netPacked, 0, $fullBytes) !== substr($ipPacked, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) return true;
        $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
        return (ord($netPacked[$fullBytes]) & ord($mask))
             === (ord($ipPacked[$fullBytes])  & ord($mask));
    }

    /**
     * Canonical host for absolute URL building. Returns $canonical_name when
     * useCanonicalName() is on AND $canonical_name is set; otherwise returns
     * the request `Host` header (falling back to `SERVER_NAME`, then ''). Used
     * by absolute-redirect builders that need to decide between the configured
     * server name and the client-provided Host.
     */
    public static function canonicalHost(): string
    {
        if (self::$use_canonical_name && self::$canonical_name !== null && self::$canonical_name !== '') {
            return self::$canonical_name;
        }
        $g = \ZealPHP\RequestContext::instance();
        $host = (string)($g->server['HTTP_HOST'] ?? $g->server['SERVER_NAME'] ?? '');
        return $host;
    }

    /**
     * Render one access-log line for the current request using App::$access_log_format.
     * Called by ZealPHP\access_log() — direct callers are rare but the helper is
     * public so user code (e.g. a custom logger middleware) can reuse it.
     *
     * The format spec is compiled to a token list on first use and cached on
     * App::$access_log_format_compiled; accessLogFormat() clears the cache when
     * the format string is changed.
     *
     * @param int        $status   Final HTTP status code (after handler + middleware)
     * @param int        $length   Response body byte count (0 OK; %b emits '-' per CLF)
     * @param float|null $durationSec Request duration in seconds; pass null when unknown
     */
    public static function formatAccessLogLine(int $status, int $length, ?float $durationSec = null): string
    {
        $tokens = self::$access_log_format_compiled;
        if ($tokens === null) {
            $tokens = self::compileAccessLogFormat(self::$access_log_format);
            self::$access_log_format_compiled = $tokens;
        }

        $g = \ZealPHP\RequestContext::instance();
        $out = '';
        foreach ($tokens as $tok) {
            $out .= self::renderAccessLogToken($tok, $g, $status, $length, $durationSec);
        }
        return $out;
    }

    /**
     * Compile an Apache LogFormat string into a flat token list. Supported
     * directive families (Apache mod_log_config subset):
     *   %h %l %u %t %r %s %>s %b %B %D %T %m %U %q %H %v
     *   %{NAME}i  %{NAME}o  %{NAME}e
     * Unknown directives are passed through verbatim (Apache compatibility:
     * mod_log_config logs '-' for unknown but compatibility matters less than
     * surfacing typos to the operator).
     *
     * @return array<int, array{kind:string, arg?:string}>
     */
    private static function compileAccessLogFormat(string $format): array
    {
        $tokens = [];
        $len = strlen($format);
        $literal = '';
        $i = 0;
        while ($i < $len) {
            $ch = $format[$i];
            if ($ch !== '%') {
                $literal .= $ch;
                $i++;
                continue;
            }
            if ($literal !== '') {
                $tokens[] = ['kind' => 'lit', 'arg' => $literal];
                $literal = '';
            }
            // Lookahead — skip the '%'
            $i++;
            if ($i >= $len) {
                $literal .= '%';
                break;
            }
            // %{NAME}i / %{NAME}o / %{NAME}e
            if ($format[$i] === '{') {
                $closeBrace = strpos($format, '}', $i + 1);
                if ($closeBrace === false || $closeBrace + 1 >= $len) {
                    $literal .= '%' . substr($format, $i);
                    $i = $len;
                    continue;
                }
                $name = substr($format, $i + 1, $closeBrace - $i - 1);
                $kindChar = $format[$closeBrace + 1];
                $kindMap = ['i' => 'header_in', 'o' => 'header_out', 'e' => 'env'];
                if (isset($kindMap[$kindChar])) {
                    $tokens[] = ['kind' => $kindMap[$kindChar], 'arg' => $name];
                } else {
                    $tokens[] = ['kind' => 'lit', 'arg' => '%{' . $name . '}' . $kindChar];
                }
                $i = $closeBrace + 2;
                continue;
            }
            // %>s — Apache's "final status after internal redirects". For us
            // it's identical to %s; we accept both and emit the same value.
            if ($format[$i] === '>' && $i + 1 < $len && $format[$i + 1] === 's') {
                $tokens[] = ['kind' => 'status'];
                $i += 2;
                continue;
            }
            $code = $format[$i];
            $i++;
            switch ($code) {
                case 'h': $tokens[] = ['kind' => 'host']; break;
                case 'a': $tokens[] = ['kind' => 'host']; break;   // %a == remote IP
                case 'l': $tokens[] = ['kind' => 'lit', 'arg' => '-']; break;
                case 'u': $tokens[] = ['kind' => 'user']; break;
                case 't': $tokens[] = ['kind' => 'time']; break;
                case 'r': $tokens[] = ['kind' => 'request']; break;
                case 's': $tokens[] = ['kind' => 'status']; break;
                case 'b': $tokens[] = ['kind' => 'bytes_clf']; break;
                case 'B': $tokens[] = ['kind' => 'bytes']; break;
                case 'D': $tokens[] = ['kind' => 'duration_us']; break;
                case 'T': $tokens[] = ['kind' => 'duration_s']; break;
                case 'm': $tokens[] = ['kind' => 'method']; break;
                case 'U': $tokens[] = ['kind' => 'url_path']; break;
                case 'q': $tokens[] = ['kind' => 'query']; break;
                case 'H': $tokens[] = ['kind' => 'protocol']; break;
                case 'v': $tokens[] = ['kind' => 'server_name']; break;
                case '%': $tokens[] = ['kind' => 'lit', 'arg' => '%']; break;
                default:
                    $tokens[] = ['kind' => 'lit', 'arg' => '%' . $code];
            }
        }
        if ($literal !== '') {
            $tokens[] = ['kind' => 'lit', 'arg' => $literal];
        }
        return $tokens;
    }

    /**
     * Render one compiled access-log token. Kept separate from the tokenizer
     * so the hot path (per-request) only does the table-lookup half; the
     * tokenize path runs once per format-string change.
     *
     * @param array{kind:string, arg?:string} $token
     */
    private static function renderAccessLogToken(array $token, \ZealPHP\RequestContext $g, int $status, int $length, ?float $durationSec): string
    {
        switch ($token['kind']) {
            case 'lit':
                return (string)($token['arg'] ?? '');
            case 'host':
                $ip = self::clientIp();
                return $ip !== '' ? $ip : '-';
            case 'user':
                $user = $g->session['username'] ?? $g->server['REMOTE_USER'] ?? null;
                return is_scalar($user) && (string)$user !== '' ? (string)$user : '-';
            case 'time':
                // Apache CLF timestamp: [day/month/year:hour:minute:second zone]
                // The Server gets the same per-second cache key the legacy
                // access_log() used, but format includes timezone.
                return '[' . date('d/M/Y:H:i:s O') . ']';
            case 'request':
                $method = (string)($g->server['REQUEST_METHOD'] ?? '-');
                $uri    = (string)($g->server['REQUEST_URI'] ?? '-');
                $proto  = (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
                return "{$method} {$uri} {$proto}";
            case 'status':
                return (string)$status;
            case 'bytes_clf':
                return $length > 0 ? (string)$length : '-';
            case 'bytes':
                return (string)$length;
            case 'duration_us':
                return $durationSec === null ? '-' : (string)(int)round($durationSec * 1_000_000);
            case 'duration_s':
                return $durationSec === null ? '-' : (string)(int)round($durationSec);
            case 'method':
                return (string)($g->server['REQUEST_METHOD'] ?? '-');
            case 'url_path':
                $path = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                return is_string($path) && $path !== '' ? $path : '-';
            case 'query':
                $qs = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                return is_string($qs) && $qs !== '' ? '?' . $qs : '';
            case 'protocol':
                return (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
            case 'server_name':
                return (string)($g->server['HTTP_HOST'] ?? $g->server['SERVER_NAME'] ?? '-');
            case 'header_in':
                $name = (string)($token['arg'] ?? '');
                if ($name === '') return '-';
                $key = 'HTTP_' . strtr(strtoupper($name), '-', '_');
                $val = $g->server[$key] ?? null;
                return is_scalar($val) && (string)$val !== '' ? (string)$val : '-';
            case 'header_out':
                $name = (string)($token['arg'] ?? '');
                if ($name === '' || $g->zealphp_response === null) return '-';
                foreach ($g->zealphp_response->headersList as [$k, $v]) {
                    if (strcasecmp((string)$k, $name) === 0) {
                        return (string)$v;
                    }
                }
                return '-';
            case 'env':
                $name = (string)($token['arg'] ?? '');
                if ($name === '') return '-';
                $val = $g->server[$name] ?? null;
                return is_scalar($val) && (string)$val !== '' ? (string)$val : '-';
        }
        return '';
    }

    /**
     * Like App::include() but returns null instead of 403 when the requested
     * file does not exist under the document root. Use for "try this file,
     * fall through to something else if missing" patterns:
     *
     *   $app->route('/{slug}', function($slug) use ($app) {
     *       $result = App::tryInclude("/articles/{$slug}.php");
     *       if ($result === null) return App::tryInclude("/legacy/{$slug}.php") ?? 404;
     *       return $result;
     *   });
     *
     * Security gating (dotfile/document-root checks) still applies — paths
     * that exist but fail the security check return 403 just like include().
     * Only the "file missing" branch is rewritten to null.
     *
     * @param array<string, mixed> $args
     */
    public static function tryInclude(string $publicPath, array $args = []): mixed
    {
        $rel    = ltrim($publicPath, '/');
        $docAbs = self::resolveDocumentRoot();
        $absPath = realpath($docAbs . '/' . $rel);

        if ($absPath === false || !is_file($absPath)) {
            return null;
        }
        return self::include($publicPath, $args);
    }

    public static function instance(): ?App
    {
        return self::$instance;
    }

    /**
     * @return array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, array<int, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}>>
     */
    public function routesByMethod(): array
    {
        return $this->routes_by_method;
    }

    /**
     * @return array<string, array<string, array{path:string,pattern:string,methods:array<int|string,string>,handler:callable|null,param_map:array<int,array<string, mixed>>,raw:bool}>>
     */
    public function routesByExactMethod(): array
    {
        return $this->routes_by_exact_method;
    }

    protected function isExactRoutePath(string $path): bool
    {
        return preg_match('/[\\\\^$.|?*+()[\\]{}]/', $path) === 0;
    }

    /**
     * Register a WebSocket endpoint.
     *
     * @param string        $path      URI path, e.g. '/ws/chat'
     * @param callable      $onMessage function($server, $frame, $g) — called for each message
     * @param callable|null $onOpen    function($server, $request, $g) — called on connect
     * @param callable|null $onClose   function($server, $fd, $g)     — called on disconnect
     */
    public function ws(string $path, callable $onMessage, ?callable $onOpen = null, ?callable $onClose = null): void
    {
        $this->ws_routes[$path] = [
            'message' => $onMessage,
            'open'    => $onOpen,
            'close'   => $onClose,
        ];
    }

    /**
     * @return array<string, array{message: callable, open: callable|null, close: callable|null}>
     */
    public function wsRoutes(): array
    {
        return $this->ws_routes;
    }

    // -----------------------------------------------------------------------
    // Timer helpers (must be called inside a coroutine context: workerStart,
    // request handler, or onWorkerStart callback)
    // -----------------------------------------------------------------------

    /** Recurring timer: calls $fn every $ms milliseconds in this worker. */
    public static function tick(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::tick($ms, $fn);
    }

    /** One-shot timer: calls $fn once after $ms milliseconds. */
    public static function after(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::after($ms, $fn);
    }

    /** Cancel a timer returned by tick() or after(). */
    public static function clearTimer(int $id): void
    {
        \OpenSwoole\Timer::clear($id);
    }

    /**
     * Register a callback to run inside every worker's workerStart event.
     * Use this to start per-worker timers, warm caches, open connections, etc.
     * Called as: $fn($server, $workerId)
     */
    public static function onWorkerStart(callable $fn): void
    {
        self::$workerStartHooks[] = $fn;
    }

    /**
     * Register a per-worker shutdown hook. Runs inside the worker process when
     * it exits (max_request recycle, graceful shutdown, or reload), BEFORE the
     * process terminates — the reliable place to flush per-worker state
     * (counters, buffered I/O, coverage dumps). Unlike register_shutdown_function,
     * this fires on OpenSwoole's signal-driven worker stop.
     * Called as: $fn($server, $workerId)
     */
    public static function onWorkerStop(callable $fn): void
    {
        self::$workerStopHooks[] = $fn;
    }

    /**
     * Normalize a methods array (any shape) into a list of uppercase strings.
     *
     * @param array<mixed> $methods
     * @return array<int, string>
     */
    private static function normalizeMethods(array $methods): array
    {
        $out = [];
        foreach ($methods as $m) {
            if (is_string($m)) {
                $out[] = strtoupper($m);
            }
        }
        return $out;
    }

    /**
     * @param callable|array{0:object|string,1:string} $handler
     * @return array<int, array{name:string, has_default:bool, default:mixed}>
     */
    private function buildParamMap($handler): array
    {
        try {
            if (is_array($handler)) {
                $target = $handler[0];
                $method = $handler[1];
                assert(is_object($target) || is_string($target));
                assert(is_string($method));
                $reflection = new \ReflectionMethod($target, $method);
            } else {
                $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
            }
            $map = [];
            foreach ($reflection->getParameters() as $param) {
                $pname = $param->getName();
                $map[] = [
                    'name'        => $pname,
                    'has_default' => $param->isDefaultValueAvailable(),
                    'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
            return $map;
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    // Prevent the instance from being cloned.
    private function __clone()
    {
    }

    // Prevent from being unserialized.
    public function __wakeup()
    {
    }

    /**
     * @return \OpenSwoole\WebSocket\Server|\OpenSwoole\Http\Server|null
     */
    public static function getServer()
    {
        return self::$server;
    }

    public static function display_errors(bool $display_errors = true): void
    {
        self::$display_errors = $display_errors;
    }

    
    /**
     * Registers a route with the application.
     *
     * @param string $path The URL path pattern for the route. Flask-like {param} syntax can be used for named parameters.
     * @param array $options Optional settings for the route, such as HTTP methods.
     *                       - 'methods' (array): HTTP methods allowed for this route. Defaults to ['GET'].
     * @param callable|null $handler The callback function to handle the route.
     *
     * If only two arguments are provided, the second argument is assumed to be the handler, and no options are set.
     *
     * The route pattern is converted to a named regex group for parameter matching.
     *
     * Example usage:
     * $app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id) {
     *     // Handler code here
     * });
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     */
    public function route(string $path, $options = [], $handler = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        // But it's good that we clearly specify all three arguments in usage.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Convert flask-like {param} to named regex group
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => self::normalizeMethods($methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * nsRoute: Define a route under a specific namespace.
     * e.g. $app->nsRoute('api', '/users', ['methods' => ['GET']], fn() => "User list");
     * This will create a route at /api/users
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     */
    public function nsRoute(string $namespace, string $path, $options = [], $handler = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Convert {param} style placeholders (no change from route)
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => self::normalizeMethods($methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * nsPathRoute: Define a route under a namespace but allow the last parameter to capture everything (including slashes).
     * Here we assume the route is something like $app->nsPathRoute('api', ...)
     * and the actual route will be `/api/{path}` with {path} capturing all trailing segments.
     * 
     * Example:
     * $app->nsPathRoute('api', ['methods' => ['GET']], function($path) {
     *     return "Full path under /api: $path";
     * });
     * 
     * Accessing /api/devices/set_pref will set $path = "devices/set_pref".
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     */
    public function nsPathRoute(string $namespace, string $path, $options = [], $handler = null): void
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));
    
        // Find all parameters
        preg_match_all('/\{([^}]+)\}/', $path, $paramMatches);
        $paramsFound = $paramMatches[1];
        $lastParam = end($paramsFound);
    
        // Replace parameters: all but last use [^/]+, last one uses .+
        $pattern = preg_replace_callback('/\{([^}]+)\}/', function($m) use ($lastParam) {
            $paramName = $m[1];
            if ($paramName === $lastParam) {
                // Last parameter is catch-all, match everything remaining
                return '(?P<' . $paramName . '>.+)';
            } else {
                // Intermediate parameters match a single segment only
                return '(?P<' . $paramName . '>[^/]+)';
            }
        }, $path);
    
        $pattern = "#^" . $pattern . "$#";

        assert(is_callable($handler));
        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => self::normalizeMethods($methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }


    /**
     * patternRoute: Allow full control of the pattern without {param} placeholders.
     * Here, the user provides a fully formed regex pattern (without anchors) and we anchor it internally.
     * e.g. $app->patternRoute('/api/(.*)', ['methods'=>['GET']], fn() => "Pattern matched!");
     * This will match any route starting with /api/.
     * 
     * TODO: Allow users to provide variable names for the regex groups.
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     */
    public function patternRoute(string $regex, $options = [], $handler = null): void
    {
        // If only two arguments are provided
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        assert(is_array($options));

        $methods = $options['methods'] ?? ['GET'];
        assert(is_array($methods));

        // Ensure the pattern is properly anchored if not already
        if (substr($regex, 0, 1) !== '#') {
            $regex = "#^" . $regex . "$#";
        }

        assert(is_callable($handler));
        $this->routes[] = [
            'path'      => $regex,
            'pattern'   => $regex,
            'methods'   => self::normalizeMethods($methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * Parses the given CSS file.
     *
     * @param string $file The path to the CSS file to be parsed.
     * @return array<string, array<string, string>> The parsed CSS rules as an associative array.
     */
    public static function parseCss(string $file): array
    {
        $css = file_get_contents($file);
        if ($css === false) { $css = ''; }
        preg_match_all('/(?ims)([a-z0-9\s\.\:#_\-@,]+)\{([^\}]*)\}/', $css, $arr);
        $result = array();
        foreach ($arr[0] as $i => $x) {
            $selector = trim($arr[1][$i]);
            $rules = explode(';', trim($arr[2][$i]));
            $rules_arr = array();
            foreach ($rules as $strRule) {
                if (!empty($strRule)) {
                    $rule = explode(":", $strRule);
                    $rules_arr[trim($rule[0])] = trim($rule[1]);
                }
            }

            $selectors = explode(',', trim($selector));
            foreach ($selectors as $strSel) {
                $result[$strSel] = $rules_arr;
            }
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // File execution family — shared core + four public surfaces.
    //
    // The universal return contract: see template/pages/responses.php
    // (canonical) and .claude/CLAUDE.md "Return value conventions" (mirror).
    // Keep all three in lock-step on any change.
    //
    //   render()           → template name, BC echo on void+echo, full contract otherwise
    //   renderToString()   → template name, coerces every return shape to string
    //   renderStream()     → template name, coerces every return shape to Generator
    //   include()          → public-relative path, full contract, never echoes
    //
    // All four share self::executeFile() — they only differ in path resolution
    // and how they coerce the core's return value.
    // -----------------------------------------------------------------------

    /**
     * Run a PHP file with the framework's universal return contract.
     *
     * Captures buffered output, then maps the included file's result:
     *   void+echo                        → buffered string
     *   return 404; (int)                → int
     *   return ['ok' => true]; (array)   → array
     *   return "html"; (string)          → string (concatenated with prior echo)
     *   echo "shell"; return "body";     → "shellbody"
     *   return (function(){yield…})();   → Generator (prefixed with echo, if any)
     *   return function($req){yield…};   → Closure (param-injected at call site,
     *                                       then re-applied to result)
     *
     * Throws bubble up to the caller — output buffer is dropped on throw so
     * partial echo doesn't leak into the next response.
     *
     * @param string $absPath  Already resolved absolute path
     * @param array<string,mixed> $args  Extracted into the file's scope
     * @return mixed
     */
    private static function executeFile(string $absPath, array $args): mixed
    {
        $g = RequestContext::instance();

        // ── Fragment-mode setup (htmx-essay-style template fragments).
        // If $args['fragment'] names a region, App::fragment() helpers inside
        // the template extract it; everything else short-circuits via
        // HaltException. Save+restore the state slot so nested executeFile()
        // calls (e.g. App::render() inside a template) compose cleanly.
        $fragmentName = (isset($args['fragment']) && is_string($args['fragment']))
            ? $args['fragment']
            : null;
        $previousFragmentState = $g->memo['_fragment'] ?? null;
        if ($fragmentName !== null) {
            $g->memo['_fragment'] = [
                'wanted'  => $fragmentName,
                'matched' => false,
                'result'  => null,
            ];
        }

        ob_start();
        $result = null;
        try {
            $args['g'] = $g;
            extract($args, EXTR_SKIP);
            $result = include $absPath;
        } catch (HaltException $e) {
            // Clean halt — preserves buffered output as the body (PR #10).
            // Fragment-capture extension: if App::fragment() matched and the
            // closure returned a non-null contract-shaped value (int / array
            // / Generator / Closure / string), surface it as $result so the
            // universal return contract applies.
            $haltState = self::getFragmentState();
            if ($haltState !== null && $haltState['matched'] && $haltState['result'] !== null) {
                $result = $haltState['result'];
            } else {
                // Plain halt (no explicit fragment return) — flag the
                // buffered echo as the response body via the same code path
                // PHP's "no explicit return from include" uses ($result === 1).
                // Without this, the bottom-of-method `return $result` would
                // throw away the buffered HTML the template echoed before
                // the halt, defeating the whole point of catching HaltException.
                $result = 1;
            }
        } catch (\Throwable $e) {
            @ob_end_clean();
            self::restoreFragmentState($previousFragmentState);
            throw $e;
        }
        $output = ob_get_clean();
        if ($output === false) {
            $output = '';
        }

        // Fragment-mode post-flight: requested but no App::fragment('X', ...)
        // block matched. The template ran to completion and we now have the
        // full-page output — definitely not what the caller asked for. Per
        // the universal return contract, surface a 404.
        $postState = self::getFragmentState();
        $fragmentMatched = ($postState !== null && $postState['matched']);
        self::restoreFragmentState($previousFragmentState);
        if ($fragmentName !== null && !$fragmentMatched) {
            return 404;
        }

        if ($result instanceof \Closure) {
            $params = self::resolveClosureParams($result, $args, $absPath);
            $invoked = $result(...$params);
            // The closure may yield a Generator, return a scalar, or return
            // another Closure. Re-thread through the same coercion logic so
            // the wire shape matches whatever the closure produced.
            if ($invoked instanceof \Generator) {
                return $output !== '' ? self::prependToStreamable($output, $invoked) : $invoked;
            }
            // Closure returning a scalar — surface the value directly; if the
            // file also echoed pre-return, concat for the "echo shell, return
            // body" idiom (only meaningful when both are strings).
            if (is_string($invoked) && $output !== '') {
                return $output . $invoked;
            }
            if ($invoked === null || $invoked === 1) {
                return $output !== '' ? $output : null;
            }
            return $invoked;
        }

        if ($result instanceof \Generator) {
            return $output !== '' ? self::prependToStreamable($output, $result) : $result;
        }

        // PHP's `include` returns int(1) when the file has no explicit `return`.
        // `return;` (void) yields null. Both should surface buffered output.
        if ($result === 1 || ($result === null && $output !== '')) {
            return $output !== '' ? $output : null;
        }

        // Explicit string return: if the file also echoed, preserve wire order.
        if (is_string($result) && $output !== '') {
            return $output . $result;
        }

        return $result;
    }

    /**
     * Resolve a template-file name to an absolute path.
     *
     * Lookup rules mirror the historical render() behaviour:
     *   - Leading slash ("/foo") = absolute lookup from $dir root
     *   - When the current request's PHP_SELF basename is a sub-directory
     *     under $dir, prefer "$dir/{basename}/$tpl.php"
     *   - Otherwise fall back to "$dir/$tpl.php"
     */
    private static function resolveTemplatePath(string $tpl, string $dir): string
    {
        $currentFile  = self::getCurrentFile(null);
        $templateDir  = self::$cwd . "/$dir";
        $rootLookup   = strpos($tpl, '/') === 0;

        if ($rootLookup) {
            $candidate = $templateDir . $tpl . '.php';
        } else if (!empty($currentFile) && is_dir("$templateDir/" . $currentFile)) {
            $candidate = "$templateDir/" . $currentFile . '/' . $tpl . '.php';
        } else {
            $candidate = "$templateDir/" . $tpl . '.php';
        }

        $resolved = realpath($candidate);
        if (!$resolved || !file_exists($resolved) || strpos($resolved, self::$cwd) !== 0) {
            $bt = debug_backtrace();
            $caller = array_shift($bt);
            throw new TemplateUnavailableException(
                "The template $candidate does not exist in file "
                . str_replace(self::$cwd, '', $caller['file'] ?? '') . ":" . ($caller['line'] ?? '')
            );
        }
        return $resolved;
    }

    /**
     * Resolve a Closure's parameters by name from $args, using each parameter's
     * default value when the name is absent. Reflection is cached per file path
     * so repeated calls (e.g. streaming templates yielded in a loop) pay only
     * one reflection cost per worker.
     *
     * @param array<string,mixed> $args
     * @return array<int,mixed>
     */
    private static function resolveClosureParams(\Closure $fn, array $args, string $cacheKey): array
    {
        /** @var array<string, array<int, array{name: string, default: mixed}>> $cache */
        static $cache = [];
        if (!isset($cache[$cacheKey])) {
            $ref = new \ReflectionFunction($fn);
            $cache[$cacheKey] = array_map(
                static fn(\ReflectionParameter $p): array => [
                    'name'    => $p->getName(),
                    'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
                ],
                $ref->getParameters()
            );
        }
        $out = [];
        foreach ($cache[$cacheKey] as $p) {
            $out[] = $args[$p['name']] ?? $p['default'];
        }
        return $out;
    }

    /**
     * Combine a pre-yield buffered chunk with a Generator so the wire order
     * is "echo first, then stream". Returns a new Generator that yields the
     * buffered chunk before delegating to the original.
     */
    private static function prependToStreamable(string $prefix, \Generator $gen): \Generator
    {
        yield $prefix;
        yield from $gen;
    }

    /**
     * Coerce an executeFile() result to a string. Generators are consumed and
     * concatenated; arrays/objects are JSON-encoded; null becomes ''.
     */
    private static function coerceToString(mixed $result): string
    {
        if ($result === null) return '';
        if (is_string($result)) return $result;
        if (is_int($result) || is_float($result) || is_bool($result)) return (string)$result;
        if ($result instanceof \Generator) {
            $buf = '';
            foreach ($result as $chunk) {
                if (is_string($chunk)) {
                    $buf .= $chunk;
                } elseif (is_scalar($chunk) || $chunk === null) {
                    $buf .= (string)$chunk;
                } elseif (is_object($chunk) && method_exists($chunk, '__toString')) {
                    $buf .= (string)$chunk;
                }
                // else: skip non-stringifiable yields (array/object without __toString)
            }
            return $buf;
        }
        if (is_array($result) || is_object($result)) {
            return (string)json_encode($result);
        }
        return '';
    }

    /**
     * Coerce an executeFile() result to a Generator. Strings/scalars yield
     * once; Generators yield-from; null yields nothing.
     */
    private static function coerceToStream(mixed $result): \Generator
    {
        if ($result === null) {
            return;
        }
        if ($result instanceof \Generator) {
            yield from $result;
            return;
        }
        if (is_array($result) || is_object($result)) {
            yield (string)json_encode($result);
            return;
        }
        if (is_string($result) || is_int($result) || is_float($result) || is_bool($result)) {
            yield (string)$result;
        }
    }

    /**
     * Render a template with the provided data.
     *
     * Templates are looked up under ./template/ in the current working dir;
     * PHP_SELF is consulted as a sub-directory prefix unless $tpl starts with `/`.
     *
     * **Return contract**: see executeFile(). Templates may return int / array /
     * string / Generator / Closure to participate in the universal contract.
     *
     * **Backwards compatibility**: legacy callers expect render() to echo. When
     * the template has no explicit `return` (the historical pattern in every
     * public/*.php) the captured output is echoed back. Explicit non-void
     * returns flow through to the caller unchanged.
     *
     * @see App::executeFile() (private core) and the sibling methods (renderToString / renderStream / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function render(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): mixed
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        // BC: void-context callers (every App::render('_master', ...) call in
        // public/*.php) expect echo. If executeFile() returned a string (the
        // "file only echoed, no explicit return" case OR an explicit string
        // return) emit it now. Explicit non-string returns pass through so
        // route handlers can `return App::render(...)` and get the universal
        // contract applied at the response boundary.
        if (is_string($result)) {
            echo $result;
        }
        return $result;
    }

    /**
     * Render a template and return the result as a string. Generators are
     * consumed; Closures are invoked with param injection; arrays/objects
     * are JSON-encoded.
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderStream / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function renderToString(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): string
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        return self::coerceToString($result);
    }

    /**
     * Render a template as a Generator. Streaming templates (return-a-Closure
     * or return-a-Generator) yield directly; echo-style templates yield their
     * buffered output once.
     *
     * Compose multiple template streams with `yield from`:
     *   return (function() {
     *       yield from App::renderStream('shell-open', ['title' => 'Users']);
     *       yield from App::renderStream('users/list', ['users' => $users]);
     *       yield from App::renderStream('shell-close');
     *   })();
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderToString / include).
     *
     * @param array<string, mixed> $__args
     */
    public static function renderStream(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): \Generator
    {
        $path = self::resolveTemplatePath($__template_file, $__default_template_dir);
        $result = self::executeFile($path, $__args);
        yield from self::coerceToStream($result);
    }

    /**
     * Declare a named region inside a template — the htmx-essay "template
     * fragment" pattern. The same template renders the full page when called
     * via `App::render('page', $args)`, and just the named region when called
     * via `App::render('page', ['fragment' => $name] + $args)`. One file,
     * two responses — no separate partial file required.
     *
     * Three behaviours depending on the parent render's fragment selector:
     *  - selector is null (normal full-page render) → `$fn()` runs inline,
     *    its echo flows into the surrounding template, its return value is
     *    discarded (the parent render's return owns the universal contract).
     *  - selector matches $name → the page-shell buffer is cleared, `$fn()`
     *    runs, its return is captured, then `HaltException` short-circuits
     *    the rest of the template. `executeFile()` propagates the return
     *    so the closure can `return 404;` / `return ['k'=>'v'];` / yield a
     *    Generator just like a route handler.
     *  - selector is set but does not match $name → skipped silently.
     *
     * Same return contract as every other entry point: int=status,
     * array=JSON, string=HTML, Generator=stream, Closure=invoked-and-recursed,
     * null=use buffered output. See `template/pages/responses.php#return-contract`.
     *
     * Example — htmx-style row swap:
     * ```php
     * // template/contacts/list.php
     * <ul>
     *   <?php foreach ($contacts as $contact): ?>
     *     <?php App::fragment("contact-{$contact->id}", function() use ($contact) { ?>
     *       <li id="contact-<?= $contact->id ?>"><?= htmlspecialchars($contact->name) ?></li>
     *     <?php }); ?>
     *   <?php endforeach; ?>
     * </ul>
     * ```
     *
     * Full page: `App::render('contacts/list', ['contacts' => $all])`.
     * Single row (htmx swap response, same template): `App::render('contacts/list', ['contacts' => $all, 'fragment' => "contact-{$id}"])`.
     */
    public static function fragment(string $name, callable $fn): void
    {
        $state = self::getFragmentState();

        // Not in fragment-extraction mode — render the region inline as part
        // of the full page. The callable's return value is discarded; the
        // parent App::render() / App::include() owns the universal contract.
        if ($state === null) {
            $fn();
            return;
        }

        if ($state['wanted'] !== $name) {
            // Fragment-extraction mode, but not this region. Skip silently.
            return;
        }

        // Match. Clear the page-shell buffer so only this fragment's output
        // survives, run the callable, capture its return value, and throw
        // HaltException to short-circuit the rest of the template.
        // `executeFile()` catches the throw, surfaces the captured return as
        // the response's $result, and emits the buffered (fragment-only)
        // echo as the response body — same universal-return-contract path
        // every other entry point uses.
        ob_clean();
        $state['matched'] = true;
        $result = $fn();
        if ($result !== null) {
            $state['result'] = $result;
        }
        // Write the state back as a fresh array. PHPStan can't track nested-
        // key writes through $g->memo['_fragment']['matched'] because $g->memo
        // is typed as array<string, mixed>; assigning the whole sub-array
        // keeps the offset-access checker happy.
        $g = RequestContext::instance();
        $g->memo['_fragment'] = $state;
        throw new HaltException("fragment {$name} captured");
    }

    /**
     * Read and narrow the current fragment-extraction state from $g->memo.
     * `$g->memo` is `array<string, mixed>` so PHPStan can't see the shape of
     * `$g->memo['_fragment']` without help — this helper does the narrowing
     * once and returns a typed array (or null when no fragment mode is set).
     *
     * @return array{wanted: string, matched: bool, result: mixed}|null
     */
    private static function getFragmentState(): ?array
    {
        $g = RequestContext::instance();
        /** @var mixed $state */
        $state = $g->memo['_fragment'] ?? null;
        if (!is_array($state)) {
            return null;
        }
        /** @var mixed $wantedRaw */
        $wantedRaw = $state['wanted'] ?? null;
        if (!is_string($wantedRaw)) {
            return null;
        }
        return [
            'wanted'  => $wantedRaw,
            'matched' => (bool)($state['matched'] ?? false),
            'result'  => $state['result'] ?? null,
        ];
    }

    /**
     * Restore `$g->memo['_fragment']` to its prior state. Called by
     * `executeFile()` to undo fragment-mode setup for nested renders and on
     * error paths. `null` means "no fragment mode was active before" — drop
     * the slot entirely so the next App::fragment() call falls into the
     * normal inline-render branch.
     *
     * @param mixed $previous
     */
    private static function restoreFragmentState($previous): void
    {
        $g = RequestContext::instance();
        if ($previous === null) {
            unset($g->memo['_fragment']);
        } else {
            $g->memo['_fragment'] = $previous;
        }
    }

    
    /**
     * Returns the current executing script name without extenstion
     * @return String
     */
    public static function getCurrentFile(?string $file = null): string
    {
        $g = RequestContext::instance();
        if ($file == null) {
            return basename((string)($g->server['PHP_SELF'] ?? ''), '.php');
        } else {
            return basename($file, '.php');
        }
    }

    
    /**
     * Boundary-aware containment test: is $candidate the same path as $root, or
     * a descendant of it?
     *
     * Both arguments are expected to already be canonical (realpath'd) absolute
     * paths — this is the pure decision the symlink-escape guard hangs on, kept
     * separate so it can be unit-tested without a filesystem.
     *
     * A plain `strpos($candidate, $root) === 0` prefix match is unsafe: docroot
     * `/var/www/public` would wrongly accept the sibling `/var/www/public-data`
     * (shared string prefix, different directory). We require either an exact
     * match or that $candidate begins with $root followed by the directory
     * separator, so only true descendants pass.
     *
     * @param string $candidate Canonical absolute path under test.
     * @param string $root       Canonical absolute document-root path (no trailing slash).
     */
    public static function pathWithinRoot(string $candidate, string $root): bool
    {
        if ($candidate === '' || $root === '') {
            return false;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($candidate === $root) {
            return true; // the docroot itself
        }
        return str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Checks if the given file path is safe to serve/execute from the document
     * root. Apache `ap_directory_walk` / `resolve_symlink` parity:
     *
     *  - Symlink escape (CRITICAL): we canonicalize BOTH the file and the
     *    document root with realpath() and require boundary-aware containment.
     *    realpath() follows every symlink to its target, so a link inside
     *    docroot pointing outside (e.g. /etc/passwd) resolves to a path that
     *    fails the containment check and is refused. Apache refuses such links
     *    at the C level unless `Options +FollowSymLinks` is set; ZealPHP refuses
     *    them unconditionally on the PHP-served path.
     *  - Non-regular files: device nodes, FIFOs and sockets are refused
     *    (Apache request.c:1286-1292 — only REG/DIR pass the directory walk).
     *  - Dotfile segments (.git, .env, .htaccess, …) are refused when
     *    App::$block_dotfiles is on.
     *
     * Honest limitation: this guard only covers the PHP-served path
     * (App::include() / serveDirectory() / the implicit file routes). Assets
     * under the OpenSwoole built-in static handler prefixes (static_handler_
     * locations — /css/, /js/, …) are served by OpenSwoole's C-level handler
     * before any PHP runs and have no FollowSymLinks guard; keep those
     * directories symlink-free in production, or disable enable_static_handler
     * and route assets through PHP so this check applies.
     *
     * @param mixed $abs_file The candidate file path. Callers pass a realpath()
     *                         result (string|false) or a raw path; the value is
     *                         validated and re-canonicalized here.
     * @return bool Returns true if the file is a regular file within the
     *              document root, false otherwise.
     */
    public function includeCheck($abs_file){
        if (!is_string($abs_file) || $abs_file === '') {
            return false;
        }
        // Canonicalize both sides so symlinks are resolved to their real target
        // before the containment test. realpath() returns false for a path that
        // does not exist or is unreadable — refuse those too.
        $realRoot = realpath(self::resolveDocumentRoot());
        $realFile = realpath($abs_file);
        if ($realRoot === false || $realFile === false) {
            return false;
        }
        if (!self::pathWithinRoot($realFile, $realRoot)) {
            return false; // outside the document root (covers symlink escape)
        }
        // Apache refuses non-regular files (devices/pipes/sockets) — only
        // regular files and directories survive the directory walk. Directories
        // are handled by serveDirectory(); here we require a regular file.
        if (!is_file($realFile)) {
            return false;
        }
        if (self::$block_dotfiles) {
            $relative = substr($realFile, strlen($realRoot));
            foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
                if ($segment !== '' && $segment[0] === '.') {
                    return false; // dotfile (.git, .env, .htaccess, etc.)
                }
            }
        }
        return true;
    }

    /**
     * ENOTDIR detection for Apache parity (request.c:1244-1250 — "deny rather
     * than assume not found"). When a path component that should be a directory
     * is actually a regular file (e.g. /home.php/extra), Apache returns 403, not
     * 404, deliberately refusing to leak whether the deeper path exists.
     *
     * realpath() collapses both ENOENT and ENOTDIR to false, so we walk the
     * uncanonicalized path: if any non-final ancestor exists and is NOT a
     * directory, the request hit ENOTDIR. Symlinks are followed by is_dir()/
     * is_file(), matching the kernel's traversal.
     *
     * @param string $absPath The non-canonical absolute path the request mapped to.
     */
    public static function isEnotdir(string $absPath): bool
    {
        $absPath = rtrim($absPath, DIRECTORY_SEPARATOR);
        $parent  = dirname($absPath);
        while ($parent !== '' && $parent !== DIRECTORY_SEPARATOR && $parent !== '.') {
            if (file_exists($parent)) {
                // First existing ancestor: if it's a file (not a dir), the
                // remaining segments could never resolve — that's ENOTDIR.
                return !is_dir($parent);
            }
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        return false;
    }

    /**
     * Apache DirectorySlash + DirectoryIndex behavior.
     *
     * If the request hit a directory under public/, optionally 301-redirect
     * to the trailing-slash form, then walk App::$directory_index until a
     * file is found. .php files run via includeFile(); others are served
     * via sendFile() (so Range/ETag work).
     *
     * Returns: \Generator for streaming, int for status code, null when the
     * route was handled inline (response already emitted), or false to
     * indicate the directory has no servable index.
     *
     * @return mixed Generator|int|string|array|object|Closure|null|false — whatever
     *               App::include() returns for .php indexes, false when no index
     *               matched, null when a slash-redirect or sendFile was emitted.
     */
    public function serveDirectory(string $relDir, string $urlPrefix): mixed
    {
        $g = RequestContext::instance();

        if (self::$directory_slash) {
            $requestPath = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';
            if ($requestPath !== '' && substr((string)$requestPath, -1) !== '/') {
                $newUrl = $requestPath . '/';
                $qs = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
                if ($qs) $newUrl .= '?' . $qs;
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->redirect($newUrl, 301);
                $g->_streaming = true;
                return null;
            }
        }

        $base = self::resolveDocumentRoot() . '/' . $relDir;
        foreach (self::$directory_index as $indexFile) {
            $abs = realpath($base . '/' . $indexFile);
            if (!$abs || !file_exists($abs)) continue;
            if (!$this->includeCheck($abs)) continue;

            $relPath = '/' . trim($urlPrefix, '/') . '/' . $indexFile;
            if (substr($indexFile, -4) === '.php') {
                // App::include() owns the $_SERVER preamble + the contract.
                return App::include($relPath);
            }
            $g->server['PHP_SELF']        = $relPath;
            $g->server['SCRIPT_NAME']     = $relPath;
            $g->server['SCRIPT_FILENAME'] = $abs;
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->sendFile($abs);
            $g->_streaming = true;
            return null;
        }
        return false;
    }

    /**
     * Run a public/ file with Apache document-root parity and the framework's
     * universal return contract.
     *
     * Path resolution: $publicPath is relative to App::$document_root (defaults
     * to "public"). Leading slash optional — '/article.php' and 'article.php'
     * both resolve to public/article.php. Same convention as a URL path.
     *
     * Security: includeCheck() rejects paths outside the document root and
     * dotfile segments (when App::$block_dotfiles is on); refused paths return
     * int(403) so ResponseMiddleware can render the right status.
     *
     * Apache parity: $g->server['PHP_SELF'], SCRIPT_NAME, SCRIPT_FILENAME are
     * auto-populated before include so the file sees canonical $_SERVER values
     * — callers no longer need the 3-line preamble.
     *
     * In superglobals mode (legacy apps) dispatches via cgiSubprocess(); in
     * coroutine mode runs in-process via executeFile(). Return value is the
     * same shape in both modes (the subprocess metadata channel carries it).
     *
     * @see App::executeFile() (private core) and the sibling methods (render / renderToString / renderStream).
     *
     * @param array<string,mixed> $args  Extracted into the file's scope (coroutine mode only)
     */
    public static function include(string $publicPath, array $args = []): mixed
    {
        $rel    = ltrim($publicPath, '/');
        $docAbs = self::resolveDocumentRoot();
        $absPath = realpath($docAbs . '/' . $rel);

        $app = self::instance();
        if (!$app || $absPath === false || !$app->includeCheck($absPath)) {
            return 403;
        }

        $g = RequestContext::instance();
        $g->server['PHP_SELF']        = '/' . $rel;
        $g->server['SCRIPT_NAME']     = '/' . $rel;
        $g->server['SCRIPT_FILENAME'] = $absPath;

        if (self::$coproc_implicit_request_handler) {
            return self::$cgi_mode === 'fork'
                ? self::cgiFork($absPath)
                : self::cgiSubprocess($absPath);
        }
        return self::executeFile($absPath, $args);
    }

    /**
     * @deprecated since 0.2.18 — use App::include() with a public-relative path.
     *
     * Legacy alias kept for the WordPress showcase and existing user scaffolds.
     * Accepts an absolute path. For paths under the document root, delegates
     * to App::include() (security check + $_SERVER preamble apply). For paths
     * outside (e.g. test fixtures, embedded utilities), passes straight to the
     * shared core so the return contract applies but no security gate fires —
     * matching the historical includeFile() behaviour.
     */
    public static function includeFile(string $path): mixed
    {
        $docAbs = self::resolveDocumentRoot();
        if (strpos($path, $docAbs) === 0) {
            $rel = substr($path, strlen($docAbs));
            return self::include($rel, []);
        }
        // Outside the document root — preserve legacy "trust the caller"
        // semantics while still applying the universal return contract.
        if (self::$coproc_implicit_request_handler) {
            return self::$cgi_mode === 'fork'
                ? self::cgiFork($path)
                : self::cgiSubprocess($path);
        }
        return self::executeFile($path, []);
    }

    /**
     * Resolve App::$document_root to an absolute path. Relative values are
     * treated as ${App::$cwd}/$document_root; absolute values pass through.
     */
    public static function resolveDocumentRoot(): string
    {
        $root = self::$document_root;
        if ($root !== '' && $root[0] === '/') {
            return rtrim($root, '/');
        }
        return self::$cwd . '/' . rtrim($root, '/');
    }

    /**
     * Percent-decode a path repeatedly until it stops changing.
     *
     * Apache normalises before each access check, so a double-encoded payload
     * like `%252e%252e` (which decodes once to `%2e%2e`, then again to `..`)
     * is caught. A single `rawurldecode()` only peels one layer — leaving the
     * traversal sequence intact after the first decode. Decoding until stable
     * closes that gap. The iteration count is capped so a pathological input
     * (`%2525252525…`) can't spin the CPU; once the cap is hit we return the
     * partially-decoded form and let the caller's traversal/null-byte checks
     * run against it (any surviving `..`/`%` is treated conservatively).
     */
    public static function decodeUntilStable(string $path, int $maxIterations = 10): string
    {
        for ($i = 0; $i < $maxIterations; $i++) {
            $next = rawurldecode($path);
            if ($next === $path) {
                return $path;
            }
            $path = $next;
        }
        return $path;
    }

    /**
     * Normalise a request path the way Apache's `ap_normalize_path()` does
     * (`server/util.c`): collapse runs of `//` to a single `/` (MergeSlashes,
     * on by default), drop `/./` segments, and unwind `/segment/../` back over
     * the preceding segment. A `..` that would climb above root is dropped
     * (clamped at `/`), matching Apache's behaviour for the routing path.
     *
     * Operates on an already percent-decoded, query-stripped path. Returns a
     * path that always starts with `/` for absolute inputs; a `*` (OPTIONS
     * asterisk-form) or empty input is returned unchanged.
     */
    public static function normalizeRequestPath(string $path): string
    {
        if ($path === '' || $path === '*') {
            return $path;
        }

        $absolute = $path[0] === '/';
        $segments = explode('/', $path);
        /** @var array<int, string> $out */
        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                // Empty segment = duplicate slash; '.' = current dir. Drop both.
                continue;
            }
            if ($segment === '..') {
                // Unwind one real segment; clamp at root rather than escaping.
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$absolute) {
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }

        $normalized = implode('/', $out);
        if ($absolute) {
            $normalized = '/' . $normalized;
        }
        // Preserve a single trailing slash when the original ended in one and
        // the result is more than just root, so DirectorySlash handling and
        // strip-trailing-slash logic see the same shape they did before.
        if ($normalized !== '/' && substr($path, -1) === '/' && substr($normalized, -1) !== '/') {
            $normalized .= '/';
        }
        return $normalized === '' ? '/' : $normalized;
    }

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
            $env['DOCUMENT_ROOT'] = self::resolveDocumentRoot();
        }
        if (!isset($env['SERVER_ADMIN']) && self::$server_admin !== null && self::$server_admin !== '') {
            $env['SERVER_ADMIN'] = self::$server_admin;
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
            $env['PATH_TRANSLATED'] = self::resolveDocumentRoot() . $env['PATH_INFO'];
        }

        $env['ZEALPHP_REQUEST_CONTEXT'] = $ctx;
        $env['ZEALPHP_CWD'] = self::$cwd;

        return $env;
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
     */
    private static function cgiSubprocess(string $path): mixed
    {
        $g = RequestContext::instance();

        $ctx = json_encode([
            'server' => $g->server,
            'get'    => $g->get,
            'post'   => $g->post,
            'cookie' => $g->cookie,
            'files'  => $g->files,
            'env'    => $g->env ?? $_ENV,
        ], JSON_UNESCAPED_SLASHES);

        $env = self::buildCgiEnv($g->server, is_string($ctx) ? $ctx : '{}');

        $cgiWorker = __DIR__ . '/cgi_worker.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            PHP_BINARY . ' ' . escapeshellarg($cgiWorker) . ' ' . escapeshellarg($path),
            $descriptors,
            $pipes,
            self::resolveDocumentRoot(),
            $env
        );

        if (!is_resource($process)) {
            elog("cgiSubprocess: failed to start process for $path", "error");
            return 500;
        }

        try {
            // @phpstan-ignore-next-line — zealphp_request set by CoSessionManager before any route dispatches
            $postBody = $g->zealphp_request->parent->getContent();
            if ($postBody) fwrite($pipes[0], (string)$postBody);
        } catch (\Throwable $e) {}
        fclose($pipes[0]);

        // Protocol: CGI worker sends metadata as a single JSON line on stderr
        // BEFORE streaming body on stdout. This enables SSE and streaming.
        // Apply a configurable read timeout (App::$cgi_timeout seconds) so a
        // hung subprocess never blocks the OpenSwoole worker indefinitely.
        // Apache parity: CGIScriptTimeout / apr_file_pipe_timeout_set() (mod_cgi.c:437,444).
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + self::$cgi_timeout;
        $metaLine = '';
        while (microtime(true) < $deadline) {
            $line = fgets($pipes[2]);
            if ($line !== false) {
                $metaLine = $line;
                break;
            }
            if (feof($pipes[2])) {
                break;
            }
            usleep(5000);
        }
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
            $stderrRemainder = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            fclose($pipes[1]);
            proc_close($process);
            if (is_string($stderrRemainder) && $stderrRemainder !== '') {
                elog("[cgi_worker] (timeout) stderr: " . rtrim($stderrRemainder), "cgi_worker");
            }
            elog("cgiSubprocess: timeout after " . self::$cgi_timeout . "s for $path", "error");
            return 500;
        }

        // Drain any remaining stderr after the metadata line and route via elog
        // so PHP fatal errors / warnings from the subprocess are visible.
        // Apache parity: cgi_common.h:103-126 log_script_err() reads child stderr line-by-line.
        stream_set_blocking($pipes[2], true);
        $stderrRemainder = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (is_string($stderrRemainder) && $stderrRemainder !== '') {
            elog("[cgi_worker] stderr: " . rtrim($stderrRemainder), "cgi_worker");
        }

        $streaming   = false;
        $returnValue = null;
        $hasReturn   = false;
        $meta = json_decode(trim($metaLine), true);
        if (is_array($meta)) {
            $statusCode = $meta['status_code'] ?? 200;
            response_set_status(is_numeric($statusCode) ? (int)$statusCode : 200);
            $metaHeaders = is_array($meta['headers'] ?? null) ? $meta['headers'] : [];

            foreach ($metaHeaders as $pair) {
                if (!is_array($pair) || count($pair) < 2) continue;
                $p0 = is_scalar($pair[0]) ? (string)$pair[0] : '';
                $p1 = is_scalar($pair[1]) ? (string)$pair[1] : '';

                // mod_cgi parity: CGI/1.1 RFC 3875 §6.3.3 — "Status: NNN Reason"
                // sets the HTTP response code. Apache: ap_scan_script_header_err_brigade_ex().
                // Strip the Status: pseudo-header so it never reaches the client.
                if (strcasecmp($p0, 'Status') === 0) {
                    $codeStr = strtok($p1, ' ');
                    if ($codeStr !== false && ctype_digit($codeStr)) {
                        $parsed = (int)$codeStr;
                        if ($parsed >= 100 && $parsed <= 599) {
                            response_set_status($parsed);
                        }
                    }
                    continue;
                }

                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header($p0, $p1);
            }
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

        // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
        if ($streaming && $g->openswoole_response->isWritable()) {
            // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
            $g->zealphp_response->flush();
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false || $chunk === '') {
                    usleep(10000);
                    continue;
                }
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                if (!$g->openswoole_response->isWritable()) break;
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->write($chunk);
            }
            fclose($pipes[1]);
            proc_close($process);
            // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
            if ($g->openswoole_response->isWritable()) {
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                $g->openswoole_response->end();
            }
            $g->_streaming = true;
            return null;
        }

        $body = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        $body = $body === false ? '' : $body;

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
     * Warm-fork variant of cgiSubprocess(): instead of proc_open()-ing a fresh
     * PHP interpreter per request, OpenSwoole\Process forks the already-booted
     * worker (copy-on-write — the interpreter, Composer autoloader, and opcache
     * are inherited, so PHP startup + autoload are NOT re-paid). ~5× faster than
     * 'proc' mode on a trivial file.
     *
     * Isolation: the child is a fresh process that runs the file and exits, so
     * define()/class/ini mutations and any die()/exit() die WITH the child — the
     * worker is never affected (this is actually safer for die()-heavy legacy
     * code than the in-process executeFile() path).
     *
     * TRADE-OFF vs 'proc': the file is included inside the fork closure, so it
     * runs in FUNCTION scope, not true global scope. Superglobals
     * ($_GET/$_POST/$_SESSION/$_SERVER) work (they're always global), but a bare
     * top-level `$x = ...` is NOT visible via `global $x` in a function — so
     * unmodified WordPress/Drupal (`global $wpdb;`) need 'proc'. See App::$cgi_mode.
     *
     * Streaming note: the child runs to completion and ships one buffered
     * payload back, so incremental SSE does not stream chunk-by-chunk in fork
     * mode (an infinite event-stream loop would hang). Use 'proc' mode or a
     * native coroutine SSE route for live streaming.
     */
    private static function cgiFork(string $path): mixed
    {
        $g = RequestContext::instance();

        // Snapshot the per-request superglobal state to re-assert in the child.
        // (The child inherits these COW, but re-setting is explicit + guards
        // against any worker-level residue.)
        $ctx = [
            'server' => $g->server,
            'get'    => $g->get,
            'post'   => $g->post,
            'cookie' => $g->cookie,
            'files'  => $g->files,
            'env'    => is_array($g->env ?? null) ? $g->env : $_ENV,
        ];

        $worker = new \OpenSwoole\Process(function (\OpenSwoole\Process $child) use ($path, $ctx) {
            $cg = RequestContext::instance();

            // Re-assert superglobals at true global scope (superglobals are
            // always global even when assigned inside this closure).
            $_SERVER  = array_merge($_SERVER, $ctx['server']);
            $_GET     = $ctx['get'];
            $_POST    = $ctx['post'];
            $_COOKIE  = $ctx['cookie'];
            $_FILES   = $ctx['files'];
            $_ENV     = array_merge($_ENV, $ctx['env']);
            $_REQUEST = array_merge($_GET, $_POST);

            // Reset the inherited response capture buffers so we ship back ONLY
            // what THIS include adds. header()/setcookie()/http_response_code()
            // are uopz-overridden (inherited from the worker) to buffer into
            // $cg->zealphp_response — we read those lists after the include.
            $resp = $cg->zealphp_response;
            if ($resp !== null) {
                $resp->headersList    = [];
                $resp->cookiesList    = [];
                $resp->rawCookiesList = [];
            }
            $cg->status = 200;

            // Shutdown-safe payload writer: legacy code that calls die()/exit()
            // still ships its buffered output + metadata back to the parent.
            $sent = false;
            $emit = function () use (&$sent, $child, $resp, $cg) {
                if ($sent) return;
                $sent = true;
                $body = ob_get_level() > 0 ? (string) ob_get_clean() : '';
                $payload = [
                    'status'     => is_int($cg->status ?? null) ? $cg->status : 200,
                    'headers'    => $resp !== null ? $resp->headersList : [],
                    'cookies'    => $resp !== null ? $resp->cookiesList : [],
                    'rawcookies' => $resp !== null ? $resp->rawCookiesList : [],
                    'body'       => $body,
                ];
                if (isset($GLOBALS['__zeal_fork_return_set']) && $GLOBALS['__zeal_fork_return_set']) {
                    $payload['return_value'] = $GLOBALS['__zeal_fork_return'] ?? null;
                }
                // Length-prefixed frame: a 4-byte big-endian length header then
                // the JSON. The parent reads exactly that many bytes — never a
                // post-EOF read, because OpenSwoole\Process::read() BLOCKS (does
                // not return '') once the child has exited.
                $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
                $child->write(pack('N', strlen($json)) . $json);
            };
            register_shutdown_function($emit);

            ob_start();
            try {
                $result = include $path;
                // Universal return contract (mirror cgi_worker.php): invoke a
                // returned Closure, consume a returned Generator into the body,
                // drop non-serialisable returns.
                if ($result instanceof \Closure) {
                    $result = $result();
                }
                if ($result instanceof \Generator) {
                    foreach ($result as $chunk) {
                        if (is_scalar($chunk)) { echo (string) $chunk; }
                    }
                    $result = null;
                }
                if (is_resource($result)
                    || (is_object($result) && !($result instanceof \JsonSerializable) && !($result instanceof \stdClass))) {
                    $result = null;
                }
                $GLOBALS['__zeal_fork_return']     = $result;
                $GLOBALS['__zeal_fork_return_set'] = true;
            } catch (HaltException $e) {
                // Clean per-request halt — buffered output is the body.
            } catch (\OpenSwoole\ExitException $e) {
                // Legacy die()/exit(): PHP already echoed any die("msg") string
                // into the buffer before the exit unwound. Treat as a clean end
                // — keep the buffered body + whatever status was set, no trace.
                // (In fork mode this only ends the child; the worker is safe.)
            } catch (\Throwable $e) {
                $cg->status = 500;
                echo '<pre>' . htmlspecialchars($e->getMessage()) . "\n"
                   . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            $emit();
            $child->exit(0);
        }, false, SOCK_STREAM, false); // redirect=false, SOCK_STREAM, enable_coroutine=false

        $pid = $worker->start();
        if ($pid === false) {
            elog("cgiFork: failed to fork process for $path", "error");
            return 500;
        }

        // Read the length-prefixed frame: 4-byte big-endian header, then
        // exactly that many payload bytes. Reading the exact length (rather
        // than looping to EOF) is required — OpenSwoole\Process::read() blocks
        // forever after the child exits instead of returning ''. The parent
        // drains concurrently with the child writing, so payloads larger than
        // the pipe buffer stream through without deadlock.
        $readN = static function (\OpenSwoole\Process $w, int $n): string {
            $buf = '';
            while (strlen($buf) < $n) {
                $chunk = $w->read($n - strlen($buf));
                if ($chunk === '' || $chunk === false) break;
                $buf .= $chunk;
            }
            return $buf;
        };
        $header = $readN($worker, 4);
        $raw = '';
        if (strlen($header) === 4) {
            $lenInfo = unpack('N', $header);
            $len = (is_array($lenInfo) && isset($lenInfo[1]) && is_int($lenInfo[1])) ? $lenInfo[1] : 0;
            if ($len > 0) {
                $raw = $readN($worker, $len);
            }
        }
        \OpenSwoole\Process::wait(true);

        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return 500;
        }

        $statusCode = $meta['status'] ?? 200;
        response_set_status(is_numeric($statusCode) ? (int) $statusCode : 200);

        $respW = $g->zealphp_response;
        if ($respW !== null) {
            foreach ((array) ($meta['headers'] ?? []) as $pair) {
                if (is_array($pair) && count($pair) >= 2
                    && is_scalar($pair[0]) && is_scalar($pair[1])) {
                    $respW->header((string) $pair[0], (string) $pair[1]);
                }
            }
            // Cookie tuples round-tripped through JSON come back as mixed —
            // narrow each positional arg before re-applying (the tuple shape
            // is Response::cookie's: name, value, expire, path, domain, secure,
            // httponly, samesite, priority).
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
            foreach ((array) ($meta['cookies'] ?? []) as $args) {
                $applyCookie([$respW, 'cookie'], $args);
            }
            foreach ((array) ($meta['rawcookies'] ?? []) as $args) {
                $applyCookie([$respW, 'rawCookie'], $args);
            }
        }

        $body = is_string($meta['body'] ?? null) ? $meta['body'] : '';
        $hasReturn   = array_key_exists('return_value', $meta);
        $returnValue = $meta['return_value'] ?? null;

        // Same return contract as cgiSubprocess()/executeFile().
        if ($hasReturn && $returnValue !== null && $returnValue !== 1) {
            if (is_string($returnValue) && $body !== '') {
                return $body . $returnValue;
            }
            return $returnValue;
        }
        return $body !== '' ? $body : null;
    }

    /**
     * Register a fallback handler for unmatched routes (like Apache's RewriteRule . /index.php [L]).
     */
    public function setFallback(callable $handler): void
    {
        self::$fallback_handler = [
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getFallback(): ?array
    {
        return self::$fallback_handler;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        self::$middleware_wait_stack[] = $middleware;
    }

    private function invokeFallbackOrNotFound(): \Psr\Http\Message\ResponseInterface
    {
        // Dispatch the fallback as a real route so its body — whether echoed,
        // returned as string/array/Generator/Response — is preserved instead of
        // being discarded by the outer route's int-return path in dispatchRoute.
        if (self::$fallback_handler !== null) {
            $method = (string)(RequestContext::instance()->server['REQUEST_METHOD'] ?? 'GET');
            return (new ResponseMiddleware())->dispatchRoute(self::$fallback_handler, [], $method);
        }
        return $this->renderError(404);
    }

    /**
     * Register a custom error page handler — Apache's `ErrorDocument` equivalent.
     *
     * Status-specific:  $app->setErrorHandler(404, fn() => App::render('404'));
     * Catch-all:        $app->setErrorHandler(fn($status) => ...);
     *
     * Handler signature supports param injection by name — any of:
     *   function() | function($status) | function($exception) |
     *   function($status, $exception, $request, $response)
     */
    public function setErrorHandler(int|callable $statusOrHandler, ?callable $handler = null): void
    {
        if (is_callable($statusOrHandler) && $handler === null) {
            $cb = $statusOrHandler;
            $status = 0; // catch-all
        } else {
            assert(is_int($statusOrHandler));
            $status = $statusOrHandler;
            $cb = $handler;
        }
        if (!is_callable($cb)) {
            throw new \InvalidArgumentException('setErrorHandler requires a callable');
        }
        self::$error_handlers[$status] = [
            'handler'   => $cb,
            'param_map' => $this->buildParamMap($cb),
            'raw'       => false,
        ];
    }

    /**
     * @return array{handler:callable, param_map:array<int, array{name:string, has_default:bool, default:mixed}>, raw:bool}|null
     */
    public static function getErrorHandler(int $status): ?array
    {
        return self::$error_handlers[$status] ?? self::$error_handlers[0] ?? null;
    }

    /**
     * Clear previously-accumulated response headers from a handler that then
     * failed, keeping only the headers that Apache preserves across an error
     * response (ap_send_error_response: apr_table_clear(r->headers_out) then
     * re-instate headers required by HTTP protocol for specific status codes).
     *
     * Apache parity (http_protocol.c:1246-1292):
     *   Location        — preserved from err_headers_out for redirect chains.
     *   WWW-Authenticate — preserved for 401 (mod_auth sets it in err_headers_out,
     *                      http_request.c:604).
     *   Allow           — Apache re-adds Allow for 405/501 inside ap_send_error_response
     *                     after the table clear (http_protocol.c:1289-1292). We preserve
     *                     any Allow header the framework set before calling renderError()
     *                     (e.g. the 405 dispatch path) rather than clearing + re-adding.
     *
     * Called at the top of renderError() so the policy applies to both custom
     * handler dispatch and the default error body paths.
     */
    private function clearHandlerHeaders(int $status): void
    {
        $g = RequestContext::instance();
        if ($g->zealphp_response === null) {
            return;
        }
        // Always-preserved headers (Apache err_headers_out equivalents):
        //   location         — redirect chains
        //   allow            — RFC 9110 §15.5.6: required on 405/501; Apache re-adds
        //                      after the clear, so we preserve rather than wipe + re-add
        $preserveNames = ['location', 'allow'];
        // WWW-Authenticate is only meaningful on 401; preserve it there only so a
        // handler that happened to set it for a different status can't leak it.
        if ($status === 401) {
            $preserveNames[] = 'www-authenticate';
        }
        $g->zealphp_response->headersList = array_values(
            array_filter(
                $g->zealphp_response->headersList,
                static function (array $pair) use ($preserveNames): bool {
                    return in_array(strtolower($pair[0]), $preserveNames, true);
                }
            )
        );
    }

    /**
     * Render the response for an error status. Dispatches a user-registered
     * handler if one exists (status-specific takes precedence over catch-all);
     * otherwise returns the framework's default body (HTML or JSON per Accept).
     *
     * Handler exceptions are caught and logged — falls back to default body
     * so a buggy 500 handler can't infinite-loop.
     */
    public function renderError(int $status, ?\Throwable $exception = null): \Psr\Http\Message\ResponseInterface
    {
        $g = RequestContext::instance();
        // Apache ap_send_error_response parity: clear headers the failed handler
        // accumulated before emitting the error body. Preserves Location (redirect
        // chains) and, for 401 only, WWW-Authenticate (Basic/Digest challenge).
        $this->clearHandlerHeaders($status);
        // Recursion guard — if a user-registered error handler itself triggers
        // an error, the nested call falls straight through to the default page
        // instead of looping back into the same handler.
        if ($g->error_render_depth >= 1) {
            return $this->defaultErrorResponse($status, $exception);
        }
        $route = self::getErrorHandler($status);
        if ($route !== null) {
            $g->error_status    = $status;
            $g->error_exception = $exception;
            // Seed g->status with the error status so a handler that returns array/string
            // produces a response with the right HTTP status (the handler can still
            // override via http_response_code() before returning).
            $g->status = $status;
            $g->error_render_depth = $g->error_render_depth + 1;
            try {
                $method = (string)($g->server['REQUEST_METHOD'] ?? 'GET');
                return (new ResponseMiddleware())->dispatchRoute(
                    $route,
                    ['status' => $status, 'exception' => $exception],
                    $method
                );
            } catch (\Throwable $e) {
                elog("Error handler for $status itself threw: " . $e->getMessage(), 'error');
                // fall through to default
            } finally {
                $g->error_render_depth = max(0, $g->error_render_depth - 1);
            }
        }
        return $this->defaultErrorResponse($status, $exception);
    }

    /**
     * Default error body. Honors `Accept: application/json` for JSON envelope,
     * otherwise emits HTML. Stack trace included only when App::$display_errors.
     */
    private function defaultErrorResponse(int $status, ?\Throwable $exception): \Psr\Http\Message\ResponseInterface
    {
        $g = RequestContext::instance();
        $reason = self::REASON_PHRASES[$status] ?? '';
        // HEAD strips the body on error responses too (Apache ap_send_error_response
        // honours r->header_only). Content-Length still reflects the body that a
        // GET would have produced, so we compute the body then drop it for HEAD.
        $isHead = (string)($g->server['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
        $accept = strtolower((string)($g->server['HTTP_ACCEPT'] ?? ''));
        $wantsJson = $accept !== ''
            && str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/html');

        if ($wantsJson) {
            $errorPayload = [
                'status'  => $status,
                'message' => $reason,
                'trace'   => ($exception && self::$display_errors) ? jTraceEx($exception) : null,
            ];
            // Apache ServerAdmin parity: surface the configured contact in
            // machine-readable error responses too, so API clients can route
            // bug reports without scraping HTML.
            if (self::$server_admin !== null && self::$server_admin !== '') {
                $errorPayload['contact'] = self::$server_admin;
            }
            $body = (string)json_encode(['error' => $errorPayload], JSON_UNESCAPED_SLASHES);
            // HEAD strips the body but keeps Content-Length for the entity a GET
            // would have produced — emitted via the buffered header list (the
            // same path the normal HEAD dispatch branches use).
            if ($isHead) {
                response_add_header('Content-Length', (string)strlen($body));
            }
            $resp = (new Response($isHead ? '' : $body))
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json');
            assert($resp instanceof \Psr\Http\Message\ResponseInterface);
            return $resp;
        }

        $body = "<pre>{$status} {$reason}</pre>";
        if ($exception && self::$display_errors) {
            $body .= "\n<pre>" . htmlspecialchars(jTraceEx($exception)) . "</pre>";
        }
        // Apache ServerAdmin parity: default error pages show a contact line
        // when one is configured. Mirrors mod_core's behaviour and the
        // <address> block Apache appends when ServerSignature is on.
        if (self::$server_admin !== null && self::$server_admin !== '') {
            $body .= "\n<address>Contact: " . htmlspecialchars(self::$server_admin) . "</address>";
        }
        if ($isHead) {
            response_add_header('Content-Length', (string)strlen($body));
            return (new Response(''))->withStatus($status);
        }
        return (new Response($body))->withStatus($status);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function parseCliArgs(): array
    {
        $rawArgv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
        if (!is_array($rawArgv)) {
            return [];
        }
        // Filter to ensure all elements are strings (PHPStan can't infer from $_SERVER).
        $argv = array_values(array_filter($rawArgv, 'is_string'));
        if (count($argv) <= 1) {
            return [];
        }

        array_shift($argv);
        $command = 'start';
        $flags = [];
        $i = 0;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            if (in_array($arg, ['start', 'stop', 'status', 'restart', 'logs'], true)) {
                $command = $arg;
                $i++;
                continue;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                self::cliHelp();
                exit(0);
            }
            if ($arg === '-p' || $arg === '--port') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['port'] = (int)$argv[++$i];
                if ($flags['port'] < 1 || $flags['port'] > 65535) { echo "Error: port must be between 1 and 65535\n"; exit(1); }
            } elseif ($arg === '-H' || $arg === '--host') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['host'] = $argv[++$i];
            } elseif ($arg === '-w' || $arg === '--workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['worker_num'] = max(1, (int)$argv[++$i]);
            } elseif ($arg === '-d' || $arg === '--daemonize') {
                $flags['daemonize'] = true;
            } elseif ($arg === '--task-workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['task_worker_num'] = max(0, (int)$argv[++$i]);
            } elseif ($arg === '--pid-file') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['pid_file'] = $argv[++$i];
            } elseif ($arg === '--access') {
                $flags['log_access'] = true;
            } elseif ($arg === '--debug') {
                $flags['log_debug'] = true;
            } elseif ($arg === '--server') {
                $flags['log_server'] = true;
            } elseif ($arg === '--zlog') {
                $flags['log_zlog'] = true;
            } elseif (str_starts_with($arg, '-')) {
                echo "Warning: unknown flag '{$arg}' (ignored)\n";
            }
            $i++;
        }

        switch ($command) {
            case 'stop':
                if (isset($flags['port']) || !empty($flags['pid_file'])) {
                    self::cliStop(self::resolvePidFile($flags));
                } else {
                    self::cliStopAuto();
                }
                exit(0);
            case 'status':
                self::cliStatus($flags);
                exit(0);
            case 'logs':
                self::cliLogs($flags);
                exit(0);
            case 'restart':
                $pidFile = self::resolvePidFile($flags);
                $wasDaemonized = file_exists($pidFile);
                echo "Restarting ZealPHP...\n";
                self::cliStop($pidFile, quiet: true);
                if ($wasDaemonized && !isset($flags['daemonize'])) {
                    $flags['daemonize'] = true;
                }
                // The "Restarted (pid X, port Y)" confirmation is printed by
                // forkStartupReporter() in the shared 'default' start path
                // below — it forks so the terminal-attached process prints
                // the message AFTER the new daemon is confirmed up, instead
                // of the prompt returning first and the message overlapping
                // the next command (issue #17). Fall through to start.
            default:
                $pidFile = self::resolvePidFile($flags);
                if ($command === 'start' && file_exists($pidFile)) {
                    $pid = (int)trim((string)file_get_contents($pidFile));
                    if ($pid > 0 && @posix_kill($pid, 0)) {
                        $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
                        echo "ZealPHP is already running (pid {$pid}, port {$port})\n";
                        echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                        exit(0);
                    }
                    @unlink($pidFile);
                }

                $overrides = [];
                if (isset($flags['host'])) { $overrides['_host'] = $flags['host']; }
                if (isset($flags['port'])) { $overrides['_port'] = $flags['port']; }
                if (isset($flags['worker_num'])) { $overrides['worker_num'] = $flags['worker_num']; }
                if (isset($flags['daemonize'])) { $overrides['daemonize'] = true; }
                if (isset($flags['task_worker_num'])) { $overrides['task_worker_num'] = $flags['task_worker_num']; }
                if (isset($flags['pid_file'])) { $overrides['pid_file'] = $flags['pid_file']; }
                // Daemonized start/restart: fork so the terminal-attached
                // parent prints the confirmation AFTER the new daemon is up
                // (issue #17). The child returns these overrides and goes on
                // to boot the self-daemonizing server. A foreground start
                // (no -d) blocks in run() and needs no confirmation line.
                if (isset($flags['daemonize'])) {
                    $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
                    $verb = $command === 'restart'
                        ? 'Restarted'
                        : 'Started ZealPHP in detached mode';
                    self::forkStartupReporter($pidFile, (int)$port, $verb);
                }
                return $overrides;
        }
    }

    /**
     * For daemonized start/restart: fork so the terminal-attached parent
     * polls for the new daemon's PID file and prints a confirmation line
     * BEFORE the shell prompt returns, while the child goes on to boot the
     * (self-daemonizing) server. The parent never touches OpenSwoole — it
     * only watches the PID file and exits, so the confirmation is always the
     * last thing written to the terminal (fixes the issue #17 race where the
     * prompt returned first and the message overlapped the next command).
     *
     * No-op when pcntl is unavailable or the fork fails: start proceeds
     * without a confirmation line (prior behaviour), never silently broken.
     *
     * @param string $verb e.g. "Restarted" or "Started ZealPHP in detached mode"
     *
     * Forks + polls the daemon PID file and exits in the child — neither
     * unit-testable in-process (pcntl_fork/exit kills the test runner) nor
     * dumpable as a subprocess (the OpenSwoole server suppresses the PHP
     * shutdown coverage flush). Verified manually + by the CLI behaviour.
     * @codeCoverageIgnore
     */
    private static function forkStartupReporter(string $pidFile, int $port, string $verb): void
    {
        if (!function_exists('pcntl_fork')) {
            return; // proceed to boot in-process — no confirmation possible
        }
        $childPid = pcntl_fork();
        if ($childPid <= 0) {
            // Child (0) boots the server; -1 (fork failed) also proceeds so
            // start is never blocked by the inability to report.
            return;
        }
        // Parent (terminal-attached): wait for the daemon to write its PID
        // file, print the confirmation, then exit so the prompt comes last.
        $newPid = 0;
        for ($i = 0; $i < 50; $i++) {   // poll up to 5s
            usleep(100000);
            if (file_exists($pidFile)) {
                $candidate = (int)trim(@file_get_contents($pidFile) ?: '');
                if ($candidate > 0 && @posix_kill($candidate, 0)) {
                    $newPid = $candidate;
                    break;
                }
            }
        }
        if ($newPid > 0) {
            echo "{$verb} (pid {$newPid}, port {$port}).\n";
        } else {
            echo "{$verb}, but could not confirm — check `php app.php status`.\n";
        }
        exit(0);
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function resolvePidFile(array $flags): string
    {
        if (!empty($flags['pid_file'])) {
            // @phpstan-ignore-next-line — flags is array<string, mixed>; pid_file value coerced to string at boundary
            return (string)$flags['pid_file'];
        }
        $envPid = getenv('ZEALPHP_PID_FILE');
        if ($envPid !== false && trim((string)$envPid) !== '') {
            return trim((string)$envPid);
        }
        // @phpstan-ignore-next-line — flags is array<string, mixed>; port value coerced to int at boundary
        $port = (int)($flags['port'] ?? (self::$instance ? self::$instance->port : 8080));
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir !== false && trim((string)$logDir) !== '') {
            return rtrim(trim((string)$logDir), '/') . "/zealphp_{$port}.pid";
        }
        if (is_dir('/tmp/zealphp')) {
            return "/tmp/zealphp/zealphp_{$port}.pid";
        }
        return "/tmp/zealphp_{$port}.pid";
    }

    /**
     * Pull the port number from a default-shaped pid file path like
     * /tmp/zealphp/zealphp_8080.pid. Returns 0 when the caller passed a
     * --pid-file override that doesn't match the convention.
     */
    private static function extractPortFromPidFile(string $pidFile): int
    {
        if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Returns the PID listening on $port, or null when nothing's listening
     * or it can't be determined (non-Linux, /proc unreadable). Linux-only:
     * /proc/net/tcp + tcp6 give the LISTEN-state socket inode; /proc/[pid]/fd/*
     * resolves inode → owner pid. We deliberately avoid stream_socket_server /
     * socket_bind here — those are intercepted by OpenSwoole's runtime hook
     * (HOOK_ALL) and become coroutine-only, which would crash this CLI path.
     */
    private static function findPortOwnerPid(int $port): ?int
    {
        if ($port <= 0 || !is_readable('/proc/net/tcp')) {
            return null;
        }
        $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
        $inode   = null;
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $tcpFile) {
            $lines = @file($tcpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim((string)$line));
                if (!is_array($parts) || count($parts) < 10) {
                    continue;
                }
                if ($parts[3] !== '0A') {    // 0A = TCP_LISTEN
                    continue;
                }
                if (!str_ends_with($parts[1], ':' . $hexPort)) {
                    continue;
                }
                $candidate = $parts[9];
                if ($candidate !== '' && $candidate !== '0') {
                    $inode = $candidate;
                    break 2;
                }
            }
        }
        if ($inode === null) {
            return null;
        }
        $target = "socket:[{$inode}]";
        $fds    = glob('/proc/[0-9]*/fd/*') ?: [];
        foreach ($fds as $fd) {
            if (@readlink($fd) !== $target) {
                continue;
            }
            if (preg_match('#^/proc/(\d+)/#', $fd, $m) === 1) {
                return (int)$m[1];
            }
        }
        return null;
    }

    /**
     * True when /proc/$pid/cmdline looks like a ZealPHP daemon: argv[0] is
     * a real php binary (php, php8.3, etc.) AND the args reference app.php.
     * Used to gate kill operations so a recycled PID belonging to an
     * unrelated process is never targeted.
     *
     * The stricter argv[0] check matters because bash wrappers that spawn
     * `php app.php …` as a child carry "app.php" in their own cmdline — a
     * naive substring match falsely identifies them as ZealPHP daemons and
     * would happily kill them.
     *
     * Non-Linux returns true (be permissive — caller still has posix_kill).
     */
    private static function processIsZealphp(int $pid): bool
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!is_readable($cmdlinePath)) {
            return true;
        }
        $raw = @file_get_contents($cmdlinePath);
        if ($raw === false || $raw === '') {
            return false;
        }
        // strtok on non-empty input always returns non-empty-string here
        // (we checked $raw !== '' above), so no false-return branch needed.
        $argv0 = strtok($raw, "\0");
        // Match `php`, `php8`, `php8.3` but not `php-fpm`, `php-cgi`,
        // `phpunit`, `phpstan`, etc.
        if (preg_match('/^php(\d|\.|$)/', basename($argv0)) !== 1) {
            return false;
        }
        return strpos(str_replace("\0", ' ', $raw), 'app.php') !== false;
    }

    /**
     * Recover from an orphaned-daemon situation: pid file missing or stale
     * but the port is still held. If the listener is a ZealPHP process,
     * graceful-then-force kill it. Returns true when an orphan was
     * cleaned up, false when there's nothing to do (port free, or held by
     * something that isn't ours).
     *
     * Orphan recovery is rare and notable, so messages always print even
     * when called from a "quiet" code path (cliStop during restart) —
     * silent recovery hides the fact that the system was in a degraded
     * state that needed self-healing.
     */
    private static function claimOrphanIfAny(int $port): bool
    {
        // findPortOwnerPid returns null in three indistinguishable cases:
        // port free, /proc unreadable, or LISTEN socket owner not in
        // /proc/*/fd. All three mean "no orphan to clean up."
        $ownerPid = self::findPortOwnerPid($port);
        if ($ownerPid === null) {
            return false;
        }
        if (!self::processIsZealphp($ownerPid)) {
            echo "Port {$port} is held by pid {$ownerPid} (not a ZealPHP process) — refusing to touch it.\n";
            return false;
        }
        echo "Found orphaned ZealPHP daemon on port {$port} (pid {$ownerPid}, no PID file) — cleaning up.\n";
        $pgid      = @posix_getpgid($ownerPid);
        $killGroup = $pgid && $pgid !== posix_getpgid(posix_getpid());
        $killGroup ? posix_kill(-$pgid, SIGTERM) : posix_kill($ownerPid, SIGTERM);
        for ($i = 0; $i < 100; $i++) {      // up to 10s
            usleep(100000);
            if (!@posix_kill($ownerPid, 0)) {
                echo "Orphan cleaned up.\n";
                return true;
            }
        }
        echo "Orphan ignored graceful SIGTERM — force killing.\n";
        $killGroup ? posix_kill(-$pgid, SIGKILL) : posix_kill($ownerPid, SIGKILL);
        usleep(200000);
        return true;
    }

    private static function cliStop(string $pidFile, bool $quiet = false): void
    {
        $say = function (string $msg) use ($quiet): void {
            if (!$quiet) { echo $msg; }
        };

        if (!file_exists($pidFile)) {
            // Pid file gone but the port might still be held by an orphaned
            // daemon. Auto-recover instead of silently passing through; the
            // alternative is the next start/restart binding the port and
            // failing without ever telling the user why.
            $port = self::extractPortFromPidFile($pidFile);
            if (self::claimOrphanIfAny($port)) {
                return;
            }
            $say("ZealPHP is not running (no PID file: {$pidFile})\n");
            return;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
            $say("ZealPHP is not running (stale PID file)\n");
            @unlink($pidFile);
            // Stale pid file gone, but an orphan listener may still be there.
            $port = self::extractPortFromPidFile($pidFile);
            self::claimOrphanIfAny($port);
            return;
        }
        $pgid = @posix_getpgid($pid);
        $killGroup = $pgid && $pgid !== posix_getpgid(posix_getpid());
        $say("Stopping ZealPHP (pid {$pid})...\n");
        $killGroup ? posix_kill(-$pgid, SIGTERM) : posix_kill($pid, SIGTERM);
        // OpenSwoole graceful shutdown (workers finish current requests, master
        // tears down listeners) typically takes 5-7 seconds. Poll for up to 10s
        // before falling back to SIGKILL.
        for ($i = 0; $i < 10; $i++) {       // first 500ms: fast poll
            usleep(50000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        for ($i = 0; $i < 95; $i++) {       // next 9.5s: slower poll
            usleep(100000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        $say("Graceful shutdown timed out, force killing...\n");
        $killGroup ? posix_kill(-$pgid, SIGKILL) : posix_kill($pid, SIGKILL);
        usleep(100000);
        @unlink($pidFile);
    }

    private static function cliStopAuto(): void
    {
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            $logDir = is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp';
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid') ?: [];
        $running = [];
        foreach ($pidFiles as $f) {
            $pid = (int)trim((string)file_get_contents($f));
            // Pids get recycled — a bare posix_kill check will report a long-
            // dead ZealPHP daemon as "running" if its PID was reused by an
            // unrelated process. processIsZealphp() reads /proc/$pid/cmdline
            // to confirm the listener is actually ours.
            if ($pid > 0 && @posix_kill($pid, 0) && self::processIsZealphp($pid)) {
                $port = preg_match('/zealphp_(\d+)\.pid$/', $f, $m) ? $m[1] : '?';
                $running[] = ['file' => $f, 'pid' => $pid, 'port' => $port];
            } else {
                @unlink($f);
            }
        }
        if (empty($running)) {
            echo "No ZealPHP instances running\n";
            return;
        }
        if (count($running) === 1) {
            self::cliStop($running[0]['file']);
            return;
        }
        echo "Multiple ZealPHP instances running:\n";
        foreach ($running as $r) {
            echo "  pid {$r['pid']}, port {$r['port']}\n";
        }
        echo "Use 'php app.php stop -p PORT' to stop a specific instance\n";
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function cliStatus(array $flags): void
    {
        if (isset($flags['port'])) {
            $pidFile = self::resolvePidFile($flags);
            self::cliStatusOne($pidFile);
            return;
        }

        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            $logDir = is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp';
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid') ?: [];
        if (empty($pidFiles)) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }

        $found = 0;
        foreach ($pidFiles as $pidFile) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            // Pid liveness + cmdline check: posix_kill(0) only tells us the
            // PID exists, not that it's ours — recycled PIDs would lie.
            if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
                @unlink($pidFile);
                continue;
            }
            $port = '?';
            if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
                $port = $m[1];
            }
            echo "ZealPHP is running (pid {$pid}, port {$port})\n";
            $found++;
        }

        if ($found === 0) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }
        exit(0);
    }

    private static function cliStatusOne(string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            echo "ZealPHP is not running\n";
            exit(1);
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        // Cmdline verification guards against PID recycling (see cliStatus).
        if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
            echo "ZealPHP is not running (stale PID file)\n";
            @unlink($pidFile);
            exit(1);
        }
        $port = '?';
        if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
            $port = $m[1];
        }
        echo "ZealPHP is running (pid {$pid}, port {$port})\n";
        exit(0);
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function cliLogs(array $flags): void
    {
        if (isset($flags['port'])) {
            echo "Note: log files are shared across all ports. -p flag ignored.\n";
        }
        $hasFilter = isset($flags['log_access']) || isset($flags['log_debug'])
                  || isset($flags['log_server']) || isset($flags['log_zlog']);

        $files = [];

        if (!$hasFilter || isset($flags['log_access'])) {
            $path = \ZealPHP\log_file_for('access');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_debug'])) {
            $path = \ZealPHP\log_file_for('debug');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_zlog'])) {
            $path = \ZealPHP\log_file_for('zlog');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_server'])) {
            $serverLog = getenv('ZEALPHP_SERVER_LOG_FILE');
            if ($serverLog === false || trim((string)$serverLog) === '') {
                $dir = \ZealPHP\resolve_log_dir();
                if ($dir !== null) {
                    $serverLog = $dir . '/server.log';
                }
            }
            if ($serverLog !== false && trim((string)$serverLog) !== '') {
                $files[] = trim((string)$serverLog);
            }
        }

        if (empty($files)) {
            echo "No log files found. Check ZEALPHP_LOG_DIR or run the server first.\n";
            exit(1);
        }

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                @touch($file);
            }
        }

        echo "Tailing log files (Ctrl+C to stop):\n";
        foreach ($files as $file) {
            echo "  {$file}\n";
        }
        echo "\n";

        $cmd = 'tail -F';
        foreach ($files as $file) {
            $cmd .= ' ' . escapeshellarg($file);
        }
        passthru($cmd);
    }

    private static function cliHelp(): void
    {
        echo <<<'HELP'
Usage: php app.php [command] [options]

Commands:
  start    Start the server (default)
  stop     Stop a running server
  restart  Stop and restart the server
  status   Check if server is running
  logs     Tail log files (Ctrl+C to stop)

Options:
  -p, --port N         Listen port (default: from App::init)
  -H, --host ADDR      Listen address (default: 0.0.0.0)
  -w, --workers N      Number of worker processes
  -d, --daemonize      Run in background
  --task-workers N     Number of task workers (default: 0)
  --pid-file PATH      Custom PID file path
  -h, --help           Show this help message

Log filters (use with 'logs' command):
  --access             Only tail access.log
  --debug              Only tail debug.log
  --server             Only tail server.log
  --zlog               Only tail zlog.log

Examples:
  php app.php                        Start with defaults
  php app.php start -p 9501 -d      Start daemonized on port 9501
  php app.php stop                   Stop the default (port 8080) server
  php app.php stop -p 9501          Stop the server on port 9501
  php app.php restart -p 9501       Restart on port 9501
  php app.php status                 Check if default server is running
  php app.php status -p 9501        Check server on port 9501
  php app.php logs                   Tail all log files
  php app.php logs --access          Tail only access log
  php app.php logs --access --debug  Tail access + debug logs

PID files: /tmp/zealphp/zealphp_{port}.pid (one per port, supports multiple apps)

HELP;
    }

    /**
     * Runs the ZealPHP application.
     *
     * @param array|null $settings Optional settings to override the default OpenSwoole Server Configuration settings.
     *
     * Default settings:
     * - enable_static_handler: bool (default: true)
     * - document_root: string (default: self::$cwd . '/public')
     * - enable_coroutine: bool (default: true)
     * - pid_file: string (default: '/tmp/zealphp_{port}.pid')
     *
     * CLI usage:
     *   php app.php [start|stop|status] [-p port] [-H host] [-w workers] [-d] [--task-workers N] [--pid-file path]
     *
     * @param array<string, mixed>|null $settings
     */
    public function run(?array $settings = null): void
    {
        $cliOverrides = self::parseCliArgs();
        if (isset($cliOverrides['_host'])) {
            // @phpstan-ignore-next-line — cliOverrides is array<string, mixed>; _host coerced to string at boundary
            $this->host = (string)$cliOverrides['_host'];
            unset($cliOverrides['_host']);
        }
        if (isset($cliOverrides['_port'])) {
            // @phpstan-ignore-next-line — cliOverrides is array<string, mixed>; _port coerced to int at boundary
            $this->port = (int)$cliOverrides['_port'];
            unset($cliOverrides['_port']);
            if (is_array($settings) && isset($settings['pid_file'])) {
                $settings['pid_file'] = preg_replace(
                    '/zealphp_\d+\.pid$/',
                    "zealphp_{$this->port}.pid",
                    // @phpstan-ignore-next-line — settings is array<string, mixed>; pid_file coerced to string at boundary
                    (string)$settings['pid_file']
                );
            }
        }
        if (!empty($cliOverrides)) {
            $settings = array_merge($settings ?? [], $cliOverrides);
        }

        // Resolve the three lifecycle knobs through their fluent setters.
        // Each defaults to "follow App::$superglobals" (null backing → the
        // historical pairing), so callers that never touch the new
        // App::processIsolation() / App::enableCoroutine() / App::hookAll()
        // methods see exactly today's behaviour. Callers that want the
        // "Symfony mixed-mode" combo (superglobals=true + processIsolation=
        // false + enable_coroutine=false + hook_all=0) set the knobs
        // independently before App::run().
        App::$coproc_implicit_request_handler = App::processIsolation();
        $hookFlags = App::hookAll();
        $enableCoroutine = App::enableCoroutine();

        // Surface combinations that are syntactically allowed but race
        // process-wide superglobals against concurrent coroutines / hooked
        // I/O. We warn rather than refuse — see App::hookAll() docblock.
        self::validateLifecycleCombination(App::$superglobals, $hookFlags, $enableCoroutine);

        if ($hookFlags !== 0) {
            co::set(['hook_flags' => $hookFlags]);
            // Two-arg form (enable, flags). Single-arg with an int as $enable
            // also works at runtime — PHP truthiness coerces non-zero int to
            // true and OpenSwoole's C side reads the int as the flag bitmask
            // — but the IDE stub declares the first arg as strict bool, so
            // PHPStan flags it. Two-arg form is the canonical OpenSwoole API
            // and matches every stub version.
            \OpenSwoole\Runtime::enableCoroutine(true, $hookFlags);
        }
        // Use the same path resolution as the stop/status CLI commands so that
        // `php app.php stop` finds the PID file the server just wrote. Without
        // this, the server writes /tmp/zealphp_PORT.pid (flat) but stop looks
        // under /tmp/zealphp/zealphp_PORT.pid (subdir) — they disagree.
        $defaultPidFile = self::resolvePidFile(['port' => $this->port]);
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::resolveDocumentRoot(),
            // Restrict OpenSwoole's built-in static handler to the listed URL prefixes
            // (Apache equivalent: serving only safe subtrees). Leave empty to serve all
            // — including dotfiles — like Apache default. Default whitelist below is
            // safe for typical web apps; override via $app->run(['static_handler_locations' => [...]]).
            //
            // IMPORTANT: directory entries MUST end with `/`. OpenSwoole does raw
            // string-prefix matching, so a bare `/js` entry silently intercepts
            // user routes like `/json` (a real bug we shipped in 0.2.x — found
            // when /json on the docs site returned OpenSwoole's default 404
            // instead of routing into the framework). Trailing slash forces
            // segment-boundary matching.
            'static_handler_locations' => self::$static_handler_locations !== []
                ? self::$static_handler_locations
                : ['/css/', '/js/', '/img/', '/images/', '/fonts/', '/assets/', '/static/', '/favicon.ico', '/robots.txt'],
            'enable_coroutine' => $enableCoroutine,
            // Runtime compression is owned by OpenSwoole. Do not also register
            // CompressionMiddleware unless this setting is disabled.
            'http_compression' => true,
            'pid_file' => $defaultPidFile,
            // Worker recycling — bounds memory growth from leaks accumulated
            // in long-running workers (static caches, closure captures, leaky
            // extensions). After this many requests a worker exits cleanly and
            // is respawned with a fresh PHP arena. Set 0 to disable. Override
            // via ZEALPHP_MAX_REQUEST env var or $app->run(['max_request' => N]).
            'max_request' => (int)(getenv('ZEALPHP_MAX_REQUEST') ?: 100000),
            'task_worker_num' => 0,
            'task_enable_coroutine' => true,
            // Suppress NOTICE-level messages from OpenSwoole internals (e.g. ERRNO 1005
            // "session does not exist" when SSE/WS clients disconnect mid-stream).
            // Pass 'log_level' => 0 in $app->run() settings to restore full debug output.
            'log_level' => 4,  // 0=DEBUG 1=TRACE 2=INFO 3=NOTICE 4=WARNING 5=ERROR 6=NONE
            // Apache LimitRequestFieldSize / LimitRequestLine parity:
            // App::$limit_request_field_size / $limit_request_line are kept as
            // ADVISORY properties — OpenSwoole 22.x does not expose a public
            // server option matching Apache's per-header byte limit. The
            // earlier attempt to publish them as 'http_header_buffer_size'
            // was rejected by OpenSwoole's option validator at boot
            // (server option not recognised). If you need a hard cap, run
            // ZealPHP behind a front proxy (Caddy/nginx) that enforces it.
        ];
        // @phpstan-ignore-next-line — settings is array<string, mixed>; pid_file coerced to string at boundary
        $pidFile = (string)($settings['pid_file'] ?? $default_settings['pid_file']);
        if (file_exists($pidFile)) {
            $existingPid = (int)trim((string)file_get_contents($pidFile));
            // processIsZealphp() guards against recycled PIDs falsely
            // reporting "already running" when the original daemon is gone.
            if ($existingPid > 0 && @posix_kill($existingPid, 0) && self::processIsZealphp($existingPid)) {
                echo "ZealPHP is already running (pid {$existingPid}, port {$this->port})\n";
                echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                exit(0);
            }
            @unlink($pidFile);
        }
        // Catch the orphan case the pid-file check above can't see: pid file
        // missing or stale, but a previous daemon is still bound to the port.
        // Without this, OpenSwoole's bind would fail silently and the user
        // would see "could not confirm" with no actionable explanation.
        self::claimOrphanIfAny($this->port);

        self::$server = $server = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
        if ($settings == null){
            $effective_settings = $default_settings;
        } else {
            $effective_settings = array_merge($default_settings, $settings);
            // Re-assert the resolved enable_coroutine value AFTER user
            // settings merge — otherwise a stray `enable_coroutine` key in
            // the user-passed settings array would silently override the
            // App::enableCoroutine() decision and the lifecycle warnings
            // would be a lie.
            $effective_settings['enable_coroutine'] = $enableCoroutine;
        }
        $server->set($effective_settings);

        # Include all files in route directory and its sub directories

        $route_files = glob(self::$cwd."/route/*.php") ?: [];
        foreach ($route_files as $route_file) {
            elog("Including route file 1: ".str_replace(App::$cwd, '', $route_file));
            include $route_file;
        }

        # Implicit route for including APIs.
        # The two-segment route is registered FIRST so that /api/users/list
        # matches with module=users, request=list (a single segment passing
        # the security regex), instead of being captured by the one-segment
        # catch-all as request="users/list" — which contains a slash and
        # would fail validation with a misleading "invalid_request" error.
        $this->nsPathRoute('api', "{module}/{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function(string $module, string $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi($module, $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        $this->nsPathRoute('api', "{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function(string $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi("", $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        # Implicit route for ignoring PHP extensions

        if(App::$ignore_php_ext){
            $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
                $app = App::instance();
                assert($app !== null);
                // Apache parity (#25): a `.php` file that exists on disk but is
                // blocked from direct access is 403 Forbidden; a `.php` URL with
                // no backing file is 404 Not Found — "doesn't exist" must not
                // masquerade as "no permission".
                $g = \ZealPHP\RequestContext::instance();
                $reqPath = parse_url((string)($g->server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
                $reqPath = is_string($reqPath) ? rawurldecode($reqPath) : '';
                $docRoot = App::resolveDocumentRoot();
                $abs = realpath($docRoot . '/' . ltrim($reqPath, '/'));
                $exists = $abs !== false && is_file($abs) && str_starts_with($abs, $docRoot . '/');
                return $app->renderError($exists ? 403 : 404);
            });
        }

        # Block URLs targeting dotfile segments (.git/, .env, .htaccess, …).
        # `.well-known/` is allowed — it's a registered convention (RFC 8615).
        if (App::$block_dotfiles) {
            $this->patternRoute('/(.*/)?\.(?!well-known)[^/]*', [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD']
            ], function($response) {
                $app = App::instance();
                assert($app !== null);
                return $app->renderError(403);
            });
        }
        // $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
        //     echo("<pre>403 Forbidden</pre>");
        //     return(403);
        // });

        # Implicit route for index.php

        $this->route('/',[
            'methods' => ['GET', 'POST']
        ], function($response){
            $docRoot = self::resolveDocumentRoot();
            if (file_exists($docRoot . '/index.php')) {
                // App::include() owns includeCheck() + the $_SERVER preamble.
                return App::include('/index.php');
            }
            return $this->invokeFallbackOrNotFound();
        });

        # Global route for all files in the root of the public directory
        $this->route(App::$ignore_php_ext ? '/{file}/?' : '/{file}(\.php)?/?', [
            'methods' => ['GET', 'POST']
        ], function(string $file, $response){
            # if file ends with .php remove it
            if (substr($file, -4) == '.php') {
                $file = substr($file, 0, -4);
            }
            $docRoot  = self::resolveDocumentRoot();
            $abs_file = realpath($docRoot . '/' . $file . '.php');
            if ($abs_file !== false && file_exists($abs_file)) {
                return App::include('/' . $file . '.php');
            } else if (is_dir($docRoot . '/' . $file)) {
                $result = $this->serveDirectory($file, $file);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            }
            // Apache parity: a path component that is a file rather than a
            // directory (ENOTDIR) is 403, not 404 — deny rather than leak.
            if (self::isEnotdir($docRoot . '/' . $file)) {
                return 403;
            }
            return $this->invokeFallbackOrNotFound();
        });

        # Global route for all directories and sub directories in the public directory
        $this->nsPathRoute('{dir}', App::$ignore_php_ext ? '{uri}/?' : '{uri}(\.php)?/?', [
            'methods' => ['GET', 'POST']
        ], function(string $dir, string $uri, $response){
            elog("Directory: $dir, URI: $uri");
            # if uri ends with .php remove it
            if (substr($uri, -4) == '.php') {
                $uri = substr($uri, 0, -4);
            }
            $docRoot  = self::resolveDocumentRoot();
            $abs_file = realpath($docRoot . '/' . $dir . '/' . $uri . '.php');
            if ($abs_file !== false && file_exists($abs_file)) {
                return App::include('/' . $dir . '/' . $uri . '.php');
            } else if (is_dir($docRoot . '/' . $dir . '/' . $uri)) {
                $result = $this->serveDirectory($dir.'/'.$uri, $dir.'/'.$uri);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            }
            // Apache parity: ENOTDIR (a path component is a file) is 403, not 404.
            if (self::isEnotdir($docRoot . '/' . $dir . '/' . $uri)) {
                return 403;
            }
            return $this->invokeFallbackOrNotFound();
        });

        if (($effective_settings['task_worker_num'] ?? 0) > 0) {
            $server->on('task', function ($server, $id, $rid, $data) {
                assert(is_array($data));
                $handler = $data['handler'] ?? '';
                assert(is_string($handler));
                $args = $data['args'] ?? [];
                assert(is_array($args));
                $_func = basename($handler);
                if(file_exists(App::$cwd.$handler.'.php')){
                    include App::$cwd.$handler.'.php';
                    /** @var callable $fn */
                    $fn = $$_func;
                    $result = $fn(...$args);
                    unset($$_func);
                } else {
                    elog("Task handler not found: $handler", "error");
                    $result = false;
                }
                elog((string)json_encode([$data, $result]), "task");
                return [
                    'task' => $data,
                    'result' => $result
                ];
            });

            $server->on('finish', function ($server, $task_id, $data) {
                elog((string)json_encode($data), "task_task");
            });
        }

        $SessionManager = self::$superglobals ?  'ZealPHP\Session\SessionManager' : 'ZealPHP\Session\CoSessionManager';

        assert(self::$middleware_stack !== null);
        foreach (array_reverse(self::$middleware_wait_stack) as $middleware) {
            elog("Registering middleware: ".get_class($middleware));
            $newStack = self::$middleware_stack->add($middleware);
            assert($newStack instanceof StackHandler);
            self::$middleware_stack = $newStack;
        }

        $server->on("request",new $SessionManager(function(\ZealPHP\HTTP\Request $request, \ZealPHP\HTTP\Response $response) {
            $g = RequestContext::instance();
            /** @var string|null $serverSoftware */
            static $serverSoftware = null;
            if ($serverSoftware === null) {
                $serverSoftware = 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion();
            }

            $g->status = 200;
            /** @var array<string, mixed> $get */
            $get = $request->get ?? [];
            /** @var array<string, mixed> $post */
            $post = $request->post ?? [];
            /** @var array<string, mixed> $cookie */
            $cookie = $request->cookie ?? [];
            /** @var array<string, mixed> $files */
            $files = $request->files ?? [];
            $g->get = $get;
            $g->post = $post;
            $g->request = $g->get + $g->post;
            $g->cookie = $cookie;
            $g->files = $files;

            // Build $_SERVER — use array_change_key_case instead of foreach+strtoupper
            /** @var array<string, bool|float|int|string|null> $srv */
            $srv = [];
            if ($request->server) {
                foreach ($request->server as $sk => $sv) {
                    $srv[strtoupper($sk)] = $sv;
                }
            }
            if ($request->header) {
                foreach ($request->header as $key => $value) {
                    $srv['HTTP_' . strtr(strtoupper($key), '-', '_')] = $value;
                }
            }
            $srv += [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'SCRIPT_NAME' => '/app.php',
                'SERVER_NAME' => $srv['HTTP_HOST'] ?? site_host(),
                'DOCUMENT_ROOT' => self::resolveDocumentRoot(),
                'PHP_SELF' => App::$default_php_self,
                'SERVER_SOFTWARE' => $serverSoftware,
            ];
            if (!isset($srv['SCRIPT_FILENAME'])) {
                $docRoot = $srv['DOCUMENT_ROOT'] ?? '';
                $phpSelf = $srv['PHP_SELF'] ?? '';
                $srv['SCRIPT_FILENAME'] = (is_scalar($docRoot) ? (string)$docRoot : '')
                    . (is_scalar($phpSelf) ? (string)$phpSelf : '');
            }

            if ($srv['REQUEST_METHOD'] === 'POST' && isset($srv['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $override = $srv['HTTP_X_HTTP_METHOD_OVERRIDE'];
                $srv['REQUEST_METHOD'] = is_scalar($override) ? (string)$override : null;
            }
            // Apache HostnameLookups: populate REMOTE_HOST via reverse DNS when
            // explicitly enabled. WARNING — blocking call (OpenSwoole's coroutine
            // hook converts gethostbyaddr() to non-blocking, but it's still a
            // measurable per-request cost). Off by default since Apache 1.3.
            if (App::$hostname_lookups && isset($srv['REMOTE_ADDR'])) {
                $remoteRaw = $srv['REMOTE_ADDR'];
                $remote = is_scalar($remoteRaw) ? (string)$remoteRaw : '';
                if ($remote !== '') {
                    $host = @gethostbyaddr($remote);
                    if ($host !== false && $host !== $remote) {
                        $srv['REMOTE_HOST'] = $host;
                    }
                }
            }
            // mod_php parity: keys OpenSwoole's $request->server doesn't provide.
            // GATEWAY_INTERFACE is the CGI/1.1 constant mod_php always sets;
            // REQUEST_SCHEME + HTTPS are derived from the request (honoring
            // X-Forwarded-Proto behind a trusted proxy). HTTPS is only set under
            // TLS, matching mod_php (the key is absent on plain HTTP).
            $srv += ['GATEWAY_INTERFACE' => 'CGI/1.1'];
            if (self::requestIsHttps($srv)) {
                $srv['REQUEST_SCHEME'] = 'https';
                $srv['HTTPS'] = $srv['HTTPS'] ?? 'on';
            } else {
                $srv['REQUEST_SCHEME'] = $srv['REQUEST_SCHEME'] ?? 'http';
            }
            /** @var array<string, bool|float|int|string|null> $srvFinal */
            $srvFinal = $srv;
            $g->server = $srvFinal;

            // v0.2.27 — superglobals(true) mode populates PHP's $_GET, $_POST,
            // $_COOKIE, $_FILES, $_SERVER, $_REQUEST from the OpenSwoole request.
            // Restores v0.1.x behaviour that was lost when G switched to declared
            // properties (commit 900c18a). Legacy code using $_GET['foo'] works
            // without rewriting it as $g->get['foo'], which is the entire point
            // of the `$superglobals = true` flag. Race-safe under the documented
            // superglobals(true) + enableCoroutine(false) pairing; the unsafe
            // combination is already flagged at App::run() boot time. $_SESSION
            // is intentionally NOT touched here — the session manager owns its
            // own write path (file load + uopz session_start).
            if (App::$superglobals) {
                $GLOBALS['_GET']     = $get;
                $GLOBALS['_POST']    = $post;
                $GLOBALS['_COOKIE']  = $cookie;
                $GLOBALS['_FILES']   = $files;
                $GLOBALS['_SERVER']  = $srvFinal;
                $GLOBALS['_REQUEST'] = $g->request;
                // v0.2.30 (issue #17) — make $g->get/post/cookie/files/server/
                // request LIVE ALIASES of the superglobals, not per-request
                // snapshots. A declared `public array $get` is accessed
                // directly and shadows __get/__set, so a $_GET mutation after
                // dispatch wasn't visible through $g->get (and vice versa).
                // unset() the declared typed slots so reads/writes route
                // through RequestContext::__get()/__set(), which proxy to
                // $GLOBALS['_GET'] etc. by reference — the same live-alias
                // mechanism the session manager already applies to
                // $g->session. In superglobals mode the two names are now
                // genuinely the same array.
                unset($g->get, $g->post, $g->cookie, $g->files, $g->server, $g->request);
            }

            $serverRequest  = new \ZealPHP\HTTP\LazyServerRequest($request->parent);

            try {
                $mw = App::middleware();
                assert($mw !== null);
                $serverResponse = $mw->handle($serverRequest);

                // Per-request shutdown functions (Apache mod_php parity). Run AFTER
                // middleware returns but BEFORE emit, so a shutdown function can
                // still echo/header()/http_response_code() into the final response.
                $shutdown = $g->shutdown_functions;
                if (!empty($shutdown)) {
                    ob_start();
                    $beforeStatus = $g->status;
                    foreach ($shutdown as [$fn, $args]) {
                        try { $fn(...$args); } catch (\Throwable $e) {
                            elog("shutdown function threw: ".$e->getMessage(), 'error');
                        }
                    }
                    $g->shutdown_functions = [];
                    $extra = ob_get_clean();
                    if ($extra !== false && $extra !== '') {
                        $combined = (string)$serverResponse->getBody() . $extra;
                        $bodyRes = fopen('php://temp', 'r+');
                        if ($bodyRes !== false) {
                            fwrite($bodyRes, $combined);
                            rewind($bodyRes);
                            $serverResponse = $serverResponse->withBody(
                                new \OpenSwoole\Core\Psr\Stream($bodyRes)
                            );
                        }
                    }
                    if ($g->status !== null && $g->status !== $beforeStatus
                        && $g->status !== $serverResponse->getStatusCode()) {
                        $serverResponse = $serverResponse->withStatus($g->status);
                    }
                }

                if ($response->parent->isWritable()) {
                    // mod_php header_register_callback() — fire once just before
                    // headers flush so header() calls inside it still land.
                    $headerCb = $g->memo['_header_callback'] ?? null;
                    if (is_callable($headerCb)) {
                        unset($g->memo['_header_callback']);
                        try {
                            $headerCb();
                        } catch (\Throwable $e) {
                            elog("header_register_callback threw: " . $e->getMessage(), 'error');
                        }
                    }
                    $response->flush();
                    // Apache ServerTokens parity — value/omission per App::$server_tokens.
                    $poweredBy = self::poweredByHeader();
                    if ($poweredBy !== null) {
                        $response->parent->header('X-Powered-By', $poweredBy);
                    }
                    // Threaded emit — use App::emitStatus() instead of vendor
                    // Response::emit()'s one-arg status() call, so codes like
                    // 451 (missing from OpenSwoole's native C list) emit
                    // correctly. Body/header transcription mirrors vendor.
                    App::emitStatus($response->parent, $serverResponse->getStatusCode());
                    foreach ($serverResponse->getHeaders() as $hName => $hValues) {
                        foreach ($hValues as $hValue) {
                            $response->parent->header($hName, $hValue);
                        }
                    }
                    $body = $serverResponse->getBody();
                    $body->rewind();
                    $chunkSize = \OpenSwoole\Core\Psr\Response::CHUNK_SIZE;
                    if ($body->getSize() > $chunkSize) {
                        while (!$body->eof()) {
                            $response->parent->write($body->read($chunkSize));
                        }
                        $response->parent->end();
                    } else {
                        $response->parent->end($body->getContents());
                    }
                }
                access_log($serverResponse->getStatusCode(), 0);
            } catch (\Throwable|\OpenSwoole\ExitException $e) {
                elog(jTraceEx($e), "error");
                if ($response->parent->isWritable()) {
                    // Render via App::renderError so a user-registered 500 handler
                    // (Apache ErrorDocument equivalent) runs even at the top level.
                    try {
                        $app = App::instance();
                        assert($app !== null);
                        $errResp = $app->renderError(500, $e);
                        App::emitStatus($response->parent, $errResp->getStatusCode());
                        foreach ($errResp->getHeaders() as $name => $values) {
                            foreach ($values as $value) {
                                $response->parent->header($name, $value);
                            }
                        }
                        $g->status = $errResp->getStatusCode();
                        $response->parent->end((string)$errResp->getBody());
                    } catch (\Throwable $e2) {
                        App::emitStatus($response->parent, 500);
                        $g->status = 500;
                        $body = App::$display_errors
                            ? "<pre>".jTraceEx($e)."</pre>"
                            : "<pre>500 Internal Server Error</pre>";
                        $response->parent->end($body);
                    }
                }
            }
        }));

        // Build method-indexed dispatch table once at boot (O(1) method lookup per request)
        foreach ($this->routes as $route) {
            foreach ($route['methods'] as $m) {
                $this->routes_by_method[$m][] = $route;
                /** @phpstan-ignore-next-line isset on always-present key kept defensively */
                if (isset($route['path']) && $this->isExactRoutePath($route['path'])) {
                    $this->routes_by_exact_method[$m][$route['path']] = $route;
                }
            }
        }

        // Register the php:// stream wrapper once per worker process instead of per-request
        // and invoke any user-registered onWorkerStart hooks (timers, warmup, etc.)
        $server->on('workerStart', function($server, $workerId) {
            @stream_wrapper_unregister("php");
            stream_wrapper_register("php", \ZealPHP\IOStreamWrapper::class);
            self::$workerStartedAt = microtime(true);
            foreach (self::$workerStartHooks as $hook) {
                $hook($server, $workerId);
            }
        });

        // Worker recycle observability — fires when a worker exits (max_request
        // hit, graceful shutdown, or admin reload). Logs the request count,
        // peak RSS, and uptime so the max_request backstop is visible in prod
        // logs. Set ZEALPHP_RECYCLE_LOG=0 to silence.
        $server->on('workerStop', function($server, $workerId) {
            // User-registered per-worker shutdown hooks run first, before the
            // recycle-log line — so a hook can flush state even if logging is off.
            foreach (self::$workerStopHooks as $hook) {
                try {
                    $hook($server, $workerId);
                } catch (\Throwable $e) {
                    // A failing shutdown hook must not abort worker teardown.
                    \ZealPHP\elog('[workerStop hook] ' . $e->getMessage(), 'warn');
                }
            }
            if (\ZealPHP\env_flag('ZEALPHP_RECYCLE_LOG', true) === false) {
                return;
            }
            // @phpstan-ignore-next-line — $server is typed mixed by OpenSwoole event-handler signature; method_exists guards the call
            $stats = method_exists($server, 'stats') ? @$server->stats() : [];
            assert(is_array($stats));
            $rawReqCount = $stats['worker_request_count'] ?? $stats['request_count'] ?? 0;
            $reqCount = is_numeric($rawReqCount) ? (int)$rawReqCount : 0;
            $peakMb = round(memory_get_peak_usage(true) / 1048576, 1);
            $uptime = self::$workerStartedAt > 0
                ? round(microtime(true) - self::$workerStartedAt, 1)
                : 0.0;
            $workerIdInt = is_numeric($workerId) ? (int)$workerId : 0;
            \ZealPHP\elog(sprintf(
                '[recycle] worker %d exited after %d requests, peak RSS %s MB, uptime %ss',
                $workerIdInt,
                $reqCount,
                $peakMb,
                $uptime
            ), 'info');
        });

        // fd → ws path map, shared across WebSocket event closures
        /** @var array<int, string> $wsFdMap */
        $wsFdMap = [];

        $server->on('open', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request) use (&$wsFdMap) {
            $serverArr = $request->server ?? [];
            assert(is_array($serverArr));
            $rawPath = $serverArr['path_info'] ?? '/';
            $path = is_string($rawPath) ? $rawPath : '/';
            $fd = $request->fd;
            assert(is_int($fd));
            $wsFdMap[$fd] = $path;
            $g     = RequestContext::instance();

            // Initialize session from the upgrade request's cookie so
            // WebSocket onOpen handlers can read $g->session just like
            // HTTP handlers do via CoSessionManager.
            $sessionName = function_exists('ZealPHP\\Session\\zeal_session_name')
                ? \ZealPHP\Session\zeal_session_name()
                : 'PHPSESSID';
            if (is_array($request->cookie) && isset($request->cookie[$sessionName])) {
                $rawSid = $request->cookie[$sessionName];
                if (is_string($rawSid)) {
                    $g->cookie[$sessionName] = $rawSid;
                    \ZealPHP\Session\zeal_session_id($rawSid);
                    \ZealPHP\Session\zeal_session_start();
                    $g->_session_started = true;
                }
            }

            $app = App::instance();
            assert($app !== null);
            $route = $app->wsRoutes()[$path] ?? null;
            if ($route !== null && $route['open'] !== null) {
                ($route['open'])($server, $request, $g);
            }

            // Write-close the session after onOpen so the file isn't locked
            if ($g->_session_started ?? false) {
                \ZealPHP\Session\zeal_session_write_close();
            }
        });

        $server->on('message', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\WebSocket\Frame $frame) use (&$wsFdMap) {
            // Skip control frames: PING(9), PONG(10), CONTINUATION(0)
            // Only dispatch TEXT(1) and BINARY(2) to route handlers
            $op = $frame->opcode;
            if ($op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_TEXT &&
                $op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
                return;
            }
            $fd = $frame->fd;
            assert(is_int($fd));
            $path  = $wsFdMap[$fd] ?? null;
            $g     = RequestContext::instance();
            $app = App::instance();
            assert($app !== null);
            $route = $path ? ($app->wsRoutes()[$path] ?? null) : null;
            if ($route !== null) {
                ($route['message'])($server, $frame, $g);
            }
        });

        $server->on('close', function(\OpenSwoole\WebSocket\Server $server, int $fd) use (&$wsFdMap) {
            $path  = $wsFdMap[$fd] ?? null;
            unset($wsFdMap[$fd]);
            $g     = RequestContext::instance();
            $app = App::instance();
            assert($app !== null);
            $route = $path ? ($app->wsRoutes()[$path] ?? null) : null;
            if ($route !== null && $route['close'] !== null) {
                ($route['close'])($server, $fd, $g);
            }
        });

        // Graceful shutdown: send WebSocket CLOSE frame 1001 (Going Away) to all connections
        $server->on('shutdown', function(\OpenSwoole\WebSocket\Server $server) use (&$wsFdMap) {
            foreach (array_keys($wsFdMap) as $fd) {
                $fdInt = (int)$fd;
                if ($server->isEstablished($fdInt)) {
                    $server->disconnect($fdInt, 1001, 'Server shutting down');
                }
            }
        });

        elog("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }

    public static function middleware(): ?StackHandler
    {
        return self::$middleware_stack;
    }
}

class ResponseMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    private function dispatchRawRoute(array $route, array $params, string $method): ResponseInterface
    {
        $g = RequestContext::instance();
        $handler = $route['handler'];
        assert(is_callable($handler));
        $paramMap = $route['param_map'];
        assert(is_array($paramMap));

        $invokeArgs = [];
        foreach ($paramMap as $param) {
            assert(is_array($param));
            $pname = $param['name'] ?? null;
            assert(is_string($pname));
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        try {
            $object = call_user_func_array($handler, $invokeArgs);
            if ($object instanceof ResponseInterface) {
                return $object;
            }

            if ($object instanceof \Generator) {
                // Capture status BEFORE flush — Response::flush() clears g->status.
                $streamStatus = $g->status ?? 200;
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                App::emitStatus($g->openswoole_response, $streamStatus);
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Accept-Ranges', 'none');
                // HEAD: send headers only, never the streamed body (Apache
                // strips content buckets via ctx->final_header_only). Streaming
                // length is unknown/chunked, so no Content-Length is emitted.
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                    return (new Response('', $streamStatus));
                }
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    if (!$g->openswoole_response->isWritable()) break;
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                if ($g->openswoole_response->isWritable()) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                }
                return (new Response('', $streamStatus));
            }

            if ($g->_streaming ?? false) {
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                // Universal return contract: int = HTTP status. Coerce out-of-range
                // to 500 + log warning so bugs surface instead of silently emitting
                // bogus status codes (Apache-parity behavior).
                $status = App::coerceStatusCode((int)$object);
                $body = '';
            } else {
                $status = $g->status ?? 200;
                if (is_array($object) or is_object($object)) {
                    response_add_header('Content-Type', 'application/json');
                    $body = (string)json_encode($object);
                } else if (is_string($object)) {
                    $body = $object;
                } else {
                    $body = '';
                }
            }

            if ($method === 'HEAD') {
                response_add_header('Content-Length', (string)strlen($body));
                return (new Response('', $status));
            }
            return (new Response($body, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if($e instanceof \OpenSwoole\ExitException){
                if($e->getStatus() == 0){
                    return (new Response(''))->withStatus($g->status ?? 200);
                } else {
                    $app = App::instance();
                    assert($app !== null);
                    return $app->renderError(500);
                }
            }
            // If this dispatch was itself invoked by renderError (error handler
            // dispatch path), let the throw bubble back so the outer renderError
            // catches it and renders the ORIGINAL error status's default page —
            // not a fresh 500 from inside the recursion.
            if ($g->error_render_depth > 0) {
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack;
            if (!empty($excStack)) {
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = (string)ob_get_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            elog(jTraceEx($e), "error");
            $app = App::instance();
            assert($app !== null);
            return $app->renderError(500, $e);
        }
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    public function dispatchRoute(array $route, array $params, string $method): ResponseInterface
    {
        if (($route['raw'] ?? false) === true) {
            return $this->dispatchRawRoute($route, $params, $method);
        }

        $g = RequestContext::instance();
        $handler = $route['handler'];
        assert(is_callable($handler));
        $paramMap = $route['param_map'];
        assert(is_array($paramMap));

        $invokeArgs = [];
        foreach ($paramMap as $param) {
            assert(is_array($param));
            $pname = $param['name'] ?? null;
            assert(is_string($pname));
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        try {
            ob_start();
            $object = call_user_func_array($handler, $invokeArgs);

            // Fast paths — discard output buffer without string copy
            if ($object instanceof \Generator) {
                ob_end_clean();
                $streamStatus = $g->status ?? 200;
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                App::emitStatus($g->openswoole_response, $streamStatus);
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Accept-Ranges', 'none');
                // HEAD: send headers only, never the streamed body (Apache
                // strips content buckets via ctx->final_header_only). Streaming
                // length is unknown/chunked, so no Content-Length is emitted.
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                    return (new Response('', $streamStatus));
                }
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    if (!$g->openswoole_response->isWritable()) break;
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                if ($g->openswoole_response->isWritable()) {
                    // @phpstan-ignore-next-line — openswoole_response set by CoSessionManager before any route dispatches
                    $g->openswoole_response->end();
                }
                return (new Response('', $streamStatus));
            }

            if ($g->_streaming ?? false) {
                // Apache+mod_php auto-flushes any remaining buffer at handler exit
                // even in streaming mode. Mirror that to keep the last echo visible.
                if (ob_get_level() > 0) {
                    $remaining = ob_get_clean();
                    if ($remaining !== false && $remaining !== ''
                        && isset($g->openswoole_response)
                        && $g->openswoole_response->isWritable()) {
                        $g->openswoole_response->write($remaining);
                    }
                }
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                ob_end_clean();
                // Universal return contract: int = HTTP status. Coerce out-of-range
                // (< 100 or >= 600) to 500 + log warning — Apache-parity behavior.
                $istatus = App::coerceStatusCode((int)$object);
                // Status-only returns from a handler (e.g. `return 404;`) route
                // through renderError so any registered custom error page fires —
                // Apache's `ErrorDocument` behavior for unhandled status codes.
                if ($istatus >= 400 && $istatus < 600) {
                    $app = App::instance();
                    assert($app !== null);
                    return $app->renderError($istatus);
                }
                return (new Response('', $istatus));
            }

            $status = $g->status ?? 200;

            if ($object instanceof ResponseInterface) {
                ob_end_clean();
                return $object;
            }

            if (is_array($object) || is_object($object)) {
                ob_end_clean();
                $body = (string)json_encode($object);
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Content-Type', 'application/json');
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->header('Content-Length', (string)strlen($body));
                    return (new Response('', $status));
                }
                return (new Response($body, $status));
            }

            if (is_string($object)) {
                ob_end_clean();
                if ($method === 'HEAD') {
                    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                    $g->zealphp_response->header('Content-Length', (string)strlen($object));
                    return (new Response('', $status));
                }
                return (new Response($object, $status));
            }

            // void + echo — only path that needs the buffered output
            $buffer = (string)ob_get_clean();
            if ($method === 'HEAD') {
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->header('Content-Length', (string)strlen($buffer));
                return (new Response('', $status));
            }
            return (new Response($buffer, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if($e instanceof \OpenSwoole\ExitException){
                if($e->getStatus() == 0){
                    elog("HTTP Status: ".$g->status);
                    return (new Response((string)ob_get_clean()))->withStatus($g->status ?? 200);
                } else {
                    @ob_end_clean();
                    $app = App::instance();
                    assert($app !== null);
                    return $app->renderError(500);
                }
            }
            // Inside an error-render recursion — rethrow so the outer renderError
            // catches and falls through to the default body for the ORIGINAL status.
            if ($g->error_render_depth > 0) {
                @ob_end_clean();
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack;
            if (!empty($excStack)) {
                if (ob_get_level() > 0) { @ob_clean(); }
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = (string)ob_get_clean();
                @ob_end_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            @ob_end_clean();
            elog(jTraceEx($e), "error");
            $app = App::instance();
            assert($app !== null);
            return $app->renderError(500, $e);
        }
    }

    /**
     * Reconstruct the request line + headers for a TRACE echo body, mirroring
     * Apache's ap_send_http_trace() (http_filters.c:1130). Format is the request
     * line, each header as `Name: value`, then a terminating blank line — the
     * message/http representation the client sent. Header names/values are
     * passed through verbatim (TRACE is an introspection echo); CR/LF inside a
     * value is stripped so a crafted header can't inject extra wire lines.
     *
     * @param array<string, string> $headers
     */
    public static function buildTraceEcho(string $method, string $uri, string $protocol, array $headers): string
    {
        $crlf = "\r\n";
        $out = $method . ' ' . $uri . ' ' . $protocol . $crlf;
        foreach ($headers as $name => $value) {
            $cleanName = str_replace(["\r", "\n"], '', $name);
            $cleanValue = str_replace(["\r", "\n"], '', $value);
            $out .= $cleanName . ': ' . $cleanValue . $crlf;
        }
        return $out . $crlf;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();
        $uri = (string)$g->server['REQUEST_URI'];
        $method = (string)$g->server['REQUEST_METHOD'];
        $app = App::instance();
        assert($app !== null);

        // URL-decoded traversal/null-byte rejection BEFORE route matching.
        // Apache rejects these at the URI parse layer; we do the same so encoded
        // attacks (%2e%2e, %00, backslash) can't survive past pattern matching.
        $parsedPath = parse_url($uri, PHP_URL_PATH);
        $rawPath = is_string($parsedPath) ? $parsedPath : $uri;

        // Apache AllowEncodedSlashes Off (default): an encoded slash in the RAW
        // path is refused with 404 before it can be decoded to a real `/`. Check
        // the pre-decode bytes — once decoded, %2F is indistinguishable from a
        // literal slash. Gated by App::$allow_encoded_slashes (default false).
        if (!App::$allow_encoded_slashes && stripos($rawPath, '%2f') !== false) {
            return $app->renderError(404);
        }

        // Decode-until-stable: Apache normalises before each access check, so a
        // double-encoded payload (%252e%252e -> %2e%2e -> ..) is caught. A single
        // rawurldecode() peels only one layer. Decode repeatedly (capped) then run
        // the traversal/null-byte/backslash checks against the fully-decoded form.
        $decoded = App::decodeUntilStable($rawPath);
        if (strpos($decoded, "\0") !== false
            || strpos($decoded, '\\') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $decoded)) {
            return $app->renderError(400);
        }

        // Apache ap_normalize_path: collapse `//` -> `/` and drop `/./` segments
        // before route matching, so `//admin//` and `/./admin` cannot bypass a
        // pattern route guarding `/admin`. Rebuild REQUEST_URI from the normalised
        // path so every downstream consumer (PATH_INFO, route table) sees one form.
        $path = App::normalizeRequestPath($rawPath);
        if ($path !== $rawPath) {
            $qs = parse_url($uri, PHP_URL_QUERY);
            $uri = $path . (is_string($qs) && $qs !== '' ? '?' . $qs : '');
            $g->server['REQUEST_URI'] = $uri;
        }

        // RFC 9112 §3.2: an HTTP/1.1 request MUST carry a Host header; a server
        // MUST reject one that lacks it with 400. HTTP/1.0 is exempt (Host is
        // optional there). curl-based clients always send Host, so this only
        // bites malformed/raw requests — the vhost-confusion / smuggling surface.
        if (($g->server['SERVER_PROTOCOL'] ?? '') === 'HTTP/1.1' && !isset($g->server['HTTP_HOST'])) {
            return $app->renderError(400);
        }

        // RFC 9110 §15.6.2 / Apache server/protocol.c:1253 — a method the server
        // does not recognise gets 501 Not Implemented, not 404. This distinguishes
        // "I don't know this verb" from "no such resource". Known methods (incl.
        // HEAD/OPTIONS/TRACE and the WebDAV verbs) fall through to normal routing,
        // where an unmatched-but-known method still resolves to 404/405/fallback.
        if (!in_array($method, App::KNOWN_METHODS, true)) {
            return $app->renderError(501);
        }

        // Apache LimitRequestFields — reject requests that carry more header
        // fields than the configured limit. Apache enforces this at
        // ap_get_mime_headers_core (protocol.c:930-940) with a 400 response.
        // We replicate it here at the PHP layer after OpenSwoole has parsed the
        // header array. A limit of 0 disables the check (unlimited).
        if (App::$limit_request_fields > 0) {
            $headerCount = 0;
            foreach (array_keys($g->server) as $sk) {
                if (str_starts_with((string)$sk, 'HTTP_')) {
                    $headerCount++;
                }
            }
            if ($headerCount > App::$limit_request_fields) {
                return $app->renderError(400);
            }
        }

        // Apache PATH_INFO — `/script.php/extra/path` exposes `/extra/path` to
        // the script and rewrites REQUEST_URI to just the script. Triggers
        // only when the literal `.php/` appears in the URL (WordPress/Drupal
        // permalink style); implicit-extension routing is unaffected.
        if (App::$path_info && strpos($path, '.php/') !== false) {
            [$scriptPath, $extra] = explode('.php/', $path, 2);
            $scriptPath .= '.php';
            $docRoot = App::resolveDocumentRoot();
            $abs = realpath($docRoot . $scriptPath);
            if ($abs && is_file($abs) && strpos($abs, $docRoot) === 0) {
                $g->server['PATH_INFO']       = '/' . $extra;
                $g->server['PATH_TRANSLATED'] = $docRoot . '/' . $extra;
                $g->server['SCRIPT_NAME']     = $scriptPath;
                $qs = parse_url($uri, PHP_URL_QUERY);
                // When ignore_php_ext is on, the `.php` URI would hit the 403-block
                // route — strip the extension so the implicit file route resolves it.
                $rewritten = App::$ignore_php_ext
                    ? substr($scriptPath, 0, -4)
                    : $scriptPath;
                $uri = $rewritten . ($qs ? '?' . $qs : '');
                $g->server['REQUEST_URI'] = $uri;
            }
        }

        // TRACE — disabled by default (XST attack vector). Apache's compiled
        // default is On, but ZealPHP ships TraceEnable Off as a hardening choice.
        // Set App::traceEnabled(true) to opt into the Apache ap_send_http_trace()
        // behaviour: echo the request back as a message/http body.
        if ($method === 'TRACE') {
            if (!App::$trace_enabled) {
                response_set_status(405);
                response_add_header('Allow', 'GET, HEAD, POST, PUT, DELETE, OPTIONS, PATCH');
                return new Response('', 405);
            }
            $req = $g->zealphp_request;
            // Apache (non-extended TraceEnable On) refuses a request body with
            // 413 — only AP_TRACE_EXTENDED echoes it, and ZealPHP's boolean knob
            // maps to the non-extended mode (http_filters.c:1082).
            $bodyRaw = $req instanceof \ZealPHP\HTTP\Request ? $req->rawContent() : null;
            if (is_string($bodyRaw) && $bodyRaw !== '') {
                return $app->renderError(413);
            }
            $headers = ($req instanceof \ZealPHP\HTTP\Request && is_array($req->header))
                ? $req->header
                : [];
            $body = self::buildTraceEcho(
                $method,
                $uri,
                (string)($g->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1'),
                $headers
            );
            response_set_status(200);
            response_add_header('Content-Type', 'message/http');
            return new Response($body, 200);
        }

        // Apache: RewriteCond %{REQUEST_FILENAME} !-d
        //         RewriteRule ^(.+)/$ /$1 [R=301,L]
        // When stripTrailingSlash() is on and the URI ends in `/` but does not
        // map to a directory under document_root, 301-redirect to the no-slash
        // form. Directory URIs are left alone so the existing DirectorySlash
        // path (serveDirectory()) keeps working. Only safe for GET/HEAD per
        // RFC 9110 §15.4.2 — POST/PUT/DELETE pass through unchanged.
        if (App::$strip_trailing_slash
            && ($method === 'GET' || $method === 'HEAD')
            && $path !== '/'
            && substr($path, -1) === '/') {
            $docRoot = App::resolveDocumentRoot();
            $candidate = realpath($docRoot . rtrim($path, '/'));
            if ($candidate === false || !is_dir($candidate)) {
                $newPath = rtrim($path, '/');
                $qs = parse_url($uri, PHP_URL_QUERY);
                $location = $newPath . ($qs ? '?' . $qs : '');
                // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any route dispatches
                $g->zealphp_response->redirect($location, 301);
                $g->_streaming = true;
                return new Response('', 301);
            }
        }

        // OPTIONS — return allowed methods for this URI without running a handler
        if ($method === 'OPTIONS') {
            // RFC 9110 §9.3.7 / Apache http_core.c:336 — `OPTIONS *` is a
            // server-wide capability probe ("HTTP pong"), not resource-specific:
            // 200 with an empty body and no Allow header. The request target `*`
            // arrives as the raw REQUEST_URI (query string never applies to `*`).
            if ($uri === '*') {
                response_set_status(200);
                return new Response('', 200);
            }
            $allowed = ['OPTIONS'];
            foreach ($app->routesByMethod() as $m => $routes) {
                foreach ($routes as $route) {
                    if (preg_match($route['pattern'], $uri)) {
                        $allowed[] = $m;
                        if ($m === 'GET') $allowed[] = 'HEAD';
                        break;
                    }
                }
            }
            $allowed = array_unique($allowed);
            response_set_status(204);
            response_add_header('Allow', implode(', ', $allowed));
            return new Response('', 204);
        }

        // HEAD — match GET routes, run the handler, strip the body
        $matchMethod = ($method === 'HEAD') ? 'GET' : $method;

        $exactRoutes = $app->routesByExactMethod();
        if (isset($exactRoutes[$matchMethod][$uri])) {
            return $this->dispatchRoute($exactRoutes[$matchMethod][$uri], [], $method);
        }

        foreach ($app->routesByMethod()[$matchMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);
                return $this->dispatchRoute($route, $params, $method);
            }
        }
        // RFC 9110 §15.5.6: the URI matches a registered route for some method
        // but not this one → 405 Method Not Allowed + an `Allow` header listing
        // the supported methods (distinct from 404 = "no such resource"). The
        // implicit static routes are GET/HEAD/POST-only, so PUT/DELETE/PATCH on a
        // file-style path correctly 405s (Apache's static handler does the same);
        // a path matching no route at all falls through to the fallback / 404.
        $allowed = [];
        foreach ($app->routesByMethod() as $m => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['pattern'], $uri)) {
                    $allowed[] = $m;
                    if ($m === 'GET') {
                        $allowed[] = 'HEAD';
                    }
                    break;
                }
            }
        }
        if ($allowed !== []) {
            $allowed[] = 'OPTIONS';
            response_add_header('Allow', implode(', ', array_values(array_unique($allowed))));
            return $app->renderError(405);
        }

        $fallback = App::getFallback();
        if ($fallback !== null) {
            return $this->dispatchRoute($fallback, [], $method);
        }
        return $app->renderError(404);
    }
}

// class LoggingMiddleware implements MiddlewareInterface
// {
//     public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
//     {
//         $response = $handler->handle($request);
//         // elog("LoggingMiddleware process() received:".$response->getBody());
//         access_log($response->getStatusCode(), strlen($response->getBody()));
//         return $response;
//     }
// }

class TemplateUnavailableException extends \Exception {

	/** @var string */
	protected $message = "The template you are trying to include does not seem to exist. Please check the file name.
	Invalid error message. ";
	/** @var int */
	protected $code = 1002;

	public function __construct(string $message) {
		$this->message = $message;
		parent::__construct($this->message, $this->code);
	}

	public function __toString() {
		return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
	}

}


class LocationHeaderMiddleware implements MiddlewareInterface
{
    private int $correctPort;

    public function __construct(int $correctPort)
    {
        $this->correctPort = $correctPort;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Location')) {
            $location = $response->getHeaderLine('Location');
            $parsedUrl = parse_url($location);

            if (isset($parsedUrl['host']) && isset($parsedUrl['port']) && $parsedUrl['port'] != $this->correctPort) {
                $parsedUrl['port'] = $this->correctPort;
                $newLocation = $this->buildUrl($parsedUrl);
                $response = $response->withHeader('Location', $newLocation);
            }
        }

        return $response;
    }

    /**
     * @param array<string, string|int> $parsedUrl
     */
    private function buildUrl(array $parsedUrl): string
    {
        $scheme   = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host     = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $port     = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $path     = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $query    = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        return "$scheme$host$port$path$query$fragment";
    }
}
