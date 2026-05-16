<?php
namespace ZealPHP;

use Exception;
use ZealPHP\App;
use ZealPHP\StringUtils;
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;
use Throwable;

/**
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function get($key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function env_flag(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    $value = strtolower(trim((string) $value));
    return !in_array($value, ['0', 'false', 'off', 'no', 'none'], true);
}

function bench_mode_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_BENCH_MODE', false);
    return $enabled;
}

function site_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $configured = getenv('ZEALPHP_SITE_URL');
        if ($configured === false || trim((string) $configured) === '') {
            $configured = getenv('ZEALPHP_SITE_HOST');
        }
        if ($configured === false || trim((string) $configured) === '') {
            $configured = 'https://php.zeal.ninja';
        }

        $configured = trim((string) $configured);
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $configured)) {
            $configured = 'https://' . ltrim($configured, '/');
        }
        $base = rtrim($configured, '/');
    }

    $path = trim($path);
    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function site_host(): string
{
    $url = site_url();
    $parts = parse_url($url);
    if (is_array($parts) && !empty($parts['host'])) {
        return $parts['host'];
    }

    return $url;
}

function async_logging_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_LOG_ASYNC', true);
    return $enabled;
}

function resolve_log_dir(): ?string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = [];
    $envDir = getenv('ZEALPHP_LOG_DIR');
    if ($envDir !== false && trim((string) $envDir) !== '') {
        $candidates[] = trim((string) $envDir);
    }
    $candidates[] = '/tmp/zealphp';

    $cwd = getcwd();
    if ($cwd !== false && $cwd !== '') {
        $candidates[] = $cwd . '/tmp/zealphp';
        $candidates[] = $cwd . '/logs/zealphp';
    }

    foreach (array_unique($candidates) as $candidate) {
        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }
        if (is_dir($candidate) && is_writable($candidate)) {
            $resolved = rtrim($candidate, '/');
            return $resolved;
        }
    }

    $resolved = null;
    return $resolved;
}

function debug_logging_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    if (bench_mode_enabled()) {
        $enabled = false;
        return $enabled;
    }

    $value = getenv('ZEALPHP_DEBUG_LOG');
    if ($value === false || $value === '') {
        $value = getenv('ZEALPHP_ELOG');
    }

    if ($value === false || $value === '') {
        $enabled = true;
        return $enabled;
    }

    $value = strtolower(trim((string) $value));
    $enabled = !in_array($value, ['0', 'false', 'off', 'no', 'none'], true);
    return $enabled;
}

function access_logging_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    if (bench_mode_enabled()) {
        $enabled = false;
        return $enabled;
    }

    $enabled = env_flag('ZEALPHP_ACCESS_LOG', true);
    return $enabled;
}

function log_file_for(string $kind): ?string
{
    static $cache = [];
    if (array_key_exists($kind, $cache)) {
        return $cache[$kind];
    }

    $path = null;
    if ($kind === 'access') {
        $path = getenv('ZEALPHP_ACCESS_LOG_FILE');
    } elseif ($kind === 'zlog') {
        $path = getenv('ZEALPHP_ZLOG_FILE');
    } elseif ($kind === 'debug') {
        $path = getenv('ZEALPHP_DEBUG_LOG_FILE');
    }

    if ($path === false || $path === null || $path === '') {
        $path = getenv('ZEALPHP_LOG_FILE');
    }

    if ($path === false || trim((string) $path) === '') {
        $dir = resolve_log_dir();
        if ($dir === null) {
            return null;
        }
        if ($kind === 'access') {
            $path = $dir . '/access.log';
        } elseif ($kind === 'zlog') {
            $path = $dir . '/zlog.log';
        } else {
            $path = $dir . '/debug.log';
        }
    }

    $path = trim((string) $path);
    $cache[$kind] = $path === '' ? null : $path;
    return $cache[$kind];
}

function log_sink_for(string $path): ?\OpenSwoole\Coroutine\Channel
{
    static $sinks = [];
    static $started = [];

    if (isset($sinks[$path])) {
        return $sinks[$path];
    }

    if (!async_logging_enabled() || co::getCid() < 0 || !function_exists('go')) {
        return null;
    }

    $queue = new \OpenSwoole\Coroutine\Channel(8192);
    $sinks[$path] = $queue;

    if (!isset($started[$path])) {
        $started[$path] = true;
        go(static function () use ($queue, $path): void {
            if (!str_contains($path, '://')) {
                $dir = dirname($path);
                if ($dir !== '.' && !is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
            }

            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                while (($message = $queue->pop()) !== false) {
                    // @phpstan-ignore-next-line — OpenSwoole\Coroutine\Channel::pop() returns mixed
                    error_log((string)$message);
                }
                return;
            }

            stream_set_write_buffer($handle, 0);
            while (($message = $queue->pop()) !== false) {
                if ($message === '') {
                    continue;
                }
                // @phpstan-ignore-next-line — OpenSwoole\Coroutine\Channel::pop() returns mixed
                fwrite($handle, (string)$message);
            }
            fclose($handle);
        });
    }

    return $queue;
}

function log_write(string $message, string $kind = 'debug'): void
{
    $path = log_file_for($kind);
    if ($path === null) {
        error_log($message);
        return;
    }

    $sink = log_sink_for($path);
    if ($sink instanceof \OpenSwoole\Coroutine\Channel) {
        if ($sink->push($message, 0.001)) {
            return;
        }
    }

    if (!str_contains($path, '://')) {
        $dir = dirname($path);
        if ($dir !== '.' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    $handle = @fopen($path, 'ab');
    if ($handle === false) {
        error_log($message);
        return;
    }
    stream_set_write_buffer($handle, 0);
    fwrite($handle, $message);
    fclose($handle);
}

/**
 * Executes a task logic in a separate process.
 *
 * @param callable $taskLogic The logic to be executed in the separate process.
 * @param bool $wait Optional. Whether to wait for the process to complete. Default is true.
 *
 * @return mixed The result of the task logic if $wait is true, otherwise null.
 */
function coprocess($taskLogic, $wait = true)
{
    if(App::$superglobals == false){
        throw new \Exception("Superglobals are disabled which enables coroutines, cannot use coprocess inside coroutine, use coroutines directly.");
    }
    $worker = new Process(function ($worker) use ($taskLogic) {
        try{
            ob_start();
            $taskLogic($worker);
            $data = ob_get_clean();
            $worker->write(empty($data) ? 'EOF' : $data);
            $worker->exit();
        } catch (\Throwable $e) {
            $data = ob_get_clean();
            if(!empty($data)){
                $worker->write($data);
            } else {
                $worker->write('EOF');
            }
            if($e instanceof \OpenSwoole\ExitException){
                $worker->exit(0);
            } else {
                $worker->exit(1);
            }
        }
    }, false, SOCK_STREAM, true);

    // Start the worker
    $worker->start();
    Process::wait($wait);
    $data = $worker->read(65535);
    if($data == 'EOF'){
        $data   = '';
    }
    return $data;
}

/**
 * @param callable $taskLogic
 * @return mixed
 */
function coproc($taskLogic){
    return coprocess($taskLogic);
}


/**
* jTraceEx() - provide a Java style exception trace
* @param \Throwable        $e
* @param array<int,string>|null $seen array passed to recursive calls to accumulate trace lines already seen
*                                     leave as NULL when calling this function
* @return string of array strings, one entry per trace line
*/
function jTraceEx($e, $seen=null): string
{
    $starter = $seen ? 'Caused by: ' : '';
    $result = array();
    if (!$seen) {
        $seen = array();
    }
    $trace  = $e->getTrace();
    $prev   = $e->getPrevious();
    $result[] = sprintf('%s%s: %s', $starter, get_class($e), $e->getMessage());
    $file = $e->getFile();
    $line = $e->getLine();
    while (true) {
        $current = "$file:$line";
        if (is_array($seen) && in_array($current, $seen)) {
            $result[] = sprintf(' ... %d more', count($trace)+1);
            break;
        }
        $result[] = sprintf(
            ' at %s%s%s(%s%s%s)',
            count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
            count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '',
            count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
            $line === null ? $file : str_replace(App::$cwd, '', $file),
            $line === null ? '' : ':',
            $line === null ? '' : $line
        );
        if (is_array($seen)) {
            $seen[] = "$file:$line";
        }
        if (!count($trace)) {
            break;
        }
        $file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'anonymous';
        $line = array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line'] ? $trace[0]['line'] : null;
        array_shift($trace);
    }
    $result = join("\n", $result);
    if ($prev) {
        $result  .= "\n" . jTraceEx($prev, $seen);
    }

    return $result;
}

function zapi(): string {
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    $caller = array_shift($bt);
    return basename($caller['file'] ?? '(unknown)', '.php');
}

/**
 * Logs a message with an optional tag and limit.
 *
 * @param string $message The message to log.
 * @param string $tag The tag to associate with the log message. Default is "*".
 * @param int $limit The limit for the log message. Default is 1.
 */
function elog($message, $tag = "*", $limit = 1): void {
    if (!debug_logging_enabled()) {
        return;
    }
    if($tag == "wordpress"){
        return;
    }
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit);
    $caller = $bt[0];
    $date = date('d-m-Y H:i:s') . substr((string)microtime(), 1, 6);
    $relative_path = str_replace(App::$cwd, '', $caller['file'] ?? '(unknown)');
    $callerLine = $caller['line'] ?? 0;
    log_write("┌[$tag] $date $relative_path:$callerLine\n└❯ $message \n");
}

/**
 * Logs a message with an optional tag and filter.
 *
 * @param mixed  $log           The message or data to log.
 * @param string $tag           The tag to categorize the log entry. Default is "system".
 * @param mixed  $filter        Optional filter to apply to the log entry.
 * @param bool   $invert_filter Whether to invert the filter logic. Default is false.
 */
function zlog($log, $tag = "system", $filter = null, $invert_filter = false): void
{
    static $validTags = ['system' => 1, 'fatal' => 1, 'error' => 1, 'warning' => 1, 'info' => 1, 'debug' => 1];

    if (!debug_logging_enabled()) {
        return;
    }
    // @phpstan-ignore-next-line — $filter is documented mixed; coerced to string at boundary
    if ($filter != null and !StringUtils::str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), (string)$filter)) {
        return;
    }
    if ($filter != null and $invert_filter) {
        return;
    }

    if (!isset($validTags[$tag])) {
        return;
    }

    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = 'cli';
    }

    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $caller = $bt[0];
    $g = RequestContext::instance();
    $date = date('Y-m-d H:i:s');
    if (is_object($log)) {
        $log = purify_array($log);
    }
    if (is_array($log)) {
        $log = json_encode($log);
    }
    // @phpstan-ignore-next-line — session map is array<string, mixed>; UNIQUE_REQUEST_ID coerced to string at boundary
    $unique_req_id = (string)($g->session['UNIQUE_REQUEST_ID'] ?? '');
    $request_uri = $g->server['REQUEST_URI'] ?? '';
    $callerFile = $caller['file'] ?? '(unknown)';
    $callerLine = $caller['line'] ?? 0;
    // @phpstan-ignore-next-line — $log is documented mixed (string|array|object); coerced via json_encode above
    $msg = indent((string)$log);
    log_write(
        "[*] #{$tag} [{$date}] Request ID: {$unique_req_id}\n" .
            "    URL: {$request_uri}\n" .
            "    Caller: {$callerFile}:{$callerLine}\n" .
            "    Timer: " . get_current_render_time() . " sec\n" .
            "    Message:\n" . $msg . "\n\n",
        'zlog'
    );
}


/**
 * @param string $key
 * @return mixed
 */
function get_config($key)
{
    global $__site_config;
    $array = json_decode((string)$__site_config, true);
    if (is_array($array) && isset($array[$key])) {
        return $array[$key];
    } else {
        return null;
    }
}

/**
 * Get the current render time since request received and started processing.
 *
 * This function calculates and returns the current render time.
 *
 * @return float The current render time in seconds.
 */
function get_current_render_time()
{
    $finish = microtime(true);
    return (float) number_format(
        ($finish - RequestContext::instance()->session['__start_time']),
        5
    );
}


/**
 * Indend the given text with the given number of spaces
 *
 * @param String $string
 * @param Integer $indend	Number of lines to indent
 * @return String
 */
function indent($string, $indend = 4)
{
    $lines = explode(PHP_EOL, $string);
    $newlines = array();
    $s = "";
    $i = 0;
    while ($i < $indend) {
        $s = $s . " ";
        $i++;
    }
    foreach ($lines as $line) {
        array_push($newlines, $s . $line);
    }
    return implode(PHP_EOL, $newlines);
}

/**
 * Takes an iterator or object, and converts it into an Array.
 * @param  mixed $obj
 * @return array<int|string, mixed>
 */
function purify_array($obj)
{
    $h = json_decode((string)json_encode($obj), true);
    //print_r($h);
    return is_array($h) ? $h : [];
}


/**
 * Generates a unique identifier of a specified length.
 *
 * @param int $length The length of the unique identifier to generate. Default is 13.
 * @return string The generated unique identifier.
 */
function uniqidReal($length = 13)
{
    // uniqid gives 13 chars, but you could adjust it to your needs.
    if (function_exists("random_bytes")) {
        $bytes = random_bytes(ceil($length / 2));
    } elseif (function_exists("openssl_random_pseudo_bytes")) {
        $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
    } else {
        throw new \Exception("no cryptographically secure random function available");
    }
    return substr(bin2hex($bytes), 0, $length);
}

/**
 * Logs access details with the given status and length.
 *
 * @param int $status The HTTP status code to log.
 * @param int $length The length of the response content.
 */
function access_log(int $status = 200, int $length = 0): void {
    if (!access_logging_enabled()) {
        return;
    }
    $g = RequestContext::instance();
    static $cachedDate = '';
    static $cachedSecond = 0;
    $now = time();
    if ($now !== $cachedSecond) {
        $cachedDate = date('d/M/Y:H:i:s');
        $cachedSecond = $now;
    }
    $time = $cachedDate . substr((string)microtime(), 1, 6);
    $remote = $g->server['REMOTE_ADDR'];
    $request = $g->server['REQUEST_METHOD'].' '.$g->server['REQUEST_URI'].' '.$g->server['SERVER_PROTOCOL'];
    $referer = $g->server['HTTP_REFERER'] ?? '-';
    $user_agent = $g->server['HTTP_USER_AGENT'] ?? '-';
    $log = "$remote - - [$time] \"$request\" $status $length \"$referer\" \"$user_agent\"\n";
    log_write($log, 'access');
}

/**
 * Adds a header to the response.
 *
 * @param string $key The name of the header.
 * @param string $value The value of the header.
 * @param bool $ucwords Optional. Whether to capitalize the first letter of each word in the header name. Default is true.
 */
/**
 * @param string $key
 * @param string $value
 * @param bool   $ucwords
 */
function response_add_header($key, $value, $ucwords = true): void
{
    $g = RequestContext::instance();
    // elog("response_add_header: $key ".var_export($value, true));
    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any request handler runs
    $g->zealphp_response->header($key, $value, $ucwords);
}

/**
 * Sets the HTTP response status code.
 *
 * @param int $status The HTTP status code to set for the response.
 */
function response_set_status(int $status): void
{
    RequestContext::instance()->status = $status;
}

/**
 * Retrieves all the response headers.
 *
 * @return array<int, array{0: string, 1: string}> An associative array of all the response headers.
 */
function response_headers_list(): array
{
    $response = RequestContext::instance()->zealphp_response;
    return $response === null ? [] : $response->headersList;
}

/**
 * Set a cookie.
 *
 * @param string $name The name of the cookie.
 * @param string $value The value of the cookie. Default is an empty string.
 * @param int $expire The time the cookie expires. This is a Unix timestamp so is in number of seconds since the epoch. Default is 0.
 * @param string $path The path on the server in which the cookie will be available on. Default is an empty string.
 * @param string $domain The (sub)domain that the cookie is available to. Default is an empty string.
 * @param bool $secure Indicates that the cookie should only be transmitted over a secure HTTPS connection from the client. Default is false.
 * @param bool $httponly When true the cookie will be made accessible only through the HTTP protocol. Default is false.
 */
/**
 * @param string $name
 * @param string $value
 * @param int    $expire
 * @param string $path
 * @param string $domain
 * @param bool   $secure
 * @param bool   $httponly
 * @param string $samesite
 */
function setcookie($name, $value = "", $expire = 0, $path = "", $domain = "", $secure = false, $httponly = false, $samesite = ''): bool {
    // Cookie name char rules match PHP native setcookie: reject `=,; \t\r\n\013\014\0`.
    // Reject CR/LF/NUL in value/path/domain to prevent Set-Cookie header injection.
    if (strpbrk((string)$name, "=,; \t\r\n\013\014\0") !== false) {
        trigger_error("Cookie names cannot contain any of the following '=,; \\t\\r\\n\\013\\014'", E_USER_WARNING);
        return false;
    }
    if (strpbrk((string)$value, "\r\n\0") !== false
        || strpbrk((string)$path, "\r\n\0") !== false
        || strpbrk((string)$domain, "\r\n\0") !== false
        || strpbrk((string)$samesite, "\r\n\0") !== false) {
        trigger_error('Cookie value/path/domain/samesite contains control characters', E_USER_WARNING);
        return false;
    }
    $g = RequestContext::instance();
    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any request handler runs
    $g->zealphp_response->cookie($name, $value, $expire, $path, $domain, $secure, $httponly, $samesite);
    return true;
}

/**
 * Set a raw cookie.
 *
 * @param string $name The name of the cookie.
 * @param string $value The value of the cookie. Default is an empty string.
 * @param int $expire The time the cookie expires. This is a Unix timestamp so is in number of seconds since the epoch. Default is 0.
 * @param string $path The path on the server in which the cookie will be available on. Default is an empty string.
 * @param string $domain The (sub)domain that the cookie is available to. Default is an empty string.
 * @param bool $secure Indicates that the cookie should only be transmitted over a secure HTTPS connection from the client. Default is false.
 * @param bool $httponly When true the cookie will be made accessible only through the HTTP protocol. Default is false.
 */
/**
 * @param string $name
 * @param string $value
 * @param int    $expire
 * @param string $path
 * @param string $domain
 * @param bool   $secure
 * @param bool   $httponly
 */
function setrawcookie($name, $value = "", $expire = 0, $path = "", $domain = "", $secure = false, $httponly = false): bool {
    // setrawcookie() skips URL-encoding on $value, which is the whole point —
    // it's "raw" so callers can pass already-encoded values verbatim. Match
    // PHP native behavior: reject the name char-class, but only reject
    // CRLF/NUL in the value/path/domain (the response-splitting vector).
    // Do NOT reject space/comma/semicolon in the value — PHP allows them
    // (test fixture: setrawcookie('rawck', 'a b+c/d')).
    if (strpbrk((string)$name, "=,; \t\r\n\013\014\0") !== false) {
        trigger_error("Cookie names cannot contain any of the following '=,; \\t\\r\\n\\013\\014'", E_USER_WARNING);
        return false;
    }
    if (strpbrk((string)$value, "\r\n\0") !== false
        || strpbrk((string)$path, "\r\n\0") !== false
        || strpbrk((string)$domain, "\r\n\0") !== false) {
        trigger_error('Raw cookie value/path/domain contains control characters', E_USER_WARNING);
        return false;
    }
    $cookie = "$name=$value";
    if ($expire) {
        $cookie .= "; expires=" . gmdate('D, d-M-Y H:i:s T', $expire);
    }
    if ($path) {
        $cookie .= "; path=$path";
    }
    if ($domain) {
        $cookie .= "; domain=$domain";
    }
    if ($secure) {
        $cookie .= "; secure";
    }
    if ($httponly) {
        $cookie .= "; httponly";
    }
    $g = RequestContext::instance();
    // @phpstan-ignore-next-line — zealphp_response set by CoSessionManager before any request handler runs
    $g->zealphp_response->rawCookie($name, $value, $expire, $path, $domain, $secure, $httponly);
    return true;
}

/**
 * @param string   $header
 * @param bool     $replace
 * @param int|null $http_response_code
 * @return false|void
 */
function header($header, $replace = true, $http_response_code = null) {
    // CRLF / NUL injection guard — matches PHP native header() since 4.4.2.
    // Without this, `header("X-Foo: " . $userInput)` with CRLF in $userInput
    // enables HTTP response splitting (smuggle a second header / response body).
    if (strpbrk($header, "\r\n\0") !== false) {
        trigger_error('Header may not contain more than a single header, new line detected', E_USER_WARNING);
        return false;
    }
    // Apache mod_php form 1: status line — header("HTTP/1.1 404 Not Found");
    if (stripos($header, 'HTTP/') === 0) {
        if (preg_match('/\s(\d{3})/', $header, $m)) {
            response_set_status((int)$m[1]);
        }
        return;
    }
    // Apache mod_php form 2: Status: 404 — variant used by some CGI tooling
    if (stripos($header, 'Status:') === 0) {
        if (preg_match('/(\d{3})/', $header, $m)) {
            response_set_status((int)$m[1]);
        }
        return;
    }
    $parts = explode(':', $header, 2);
    if (count($parts) < 2) {
        return false;
    }
    $name = trim($parts[0]);
    $value = trim($parts[1]);
    if ($replace) {
        $response = RequestContext::instance()->zealphp_response;
        if ($response !== null) {
            $response->headersList = array_values(array_filter(
                $response->headersList,
                static fn($pair) => strcasecmp($pair[0], $name) !== 0
            ));
        }
    }
    response_add_header($name, $value);
    if ($http_response_code !== null && (int)$http_response_code > 0) {
        response_set_status((int)$http_response_code);
    }
}


/*
* @param int|null $code The HTTP status code to set. If null, the current status code is returned.
* @return int The current HTTP response status code.
*/
/**
 * @param int|null $code
 * @return int|null
 */
function http_response_code($code = null) {
   if ($code !== null) {
       response_set_status($code);
   } else {
       return RequestContext::instance()->status;
   }
   return null;
}

/**
* Retrieves all HTTP headers sent by the server.
*
* This function returns an array of all the HTTP headers that have been sent
* by the server. It can be useful for debugging or logging purposes.
*
* @return array<int, string> An associative array of all the HTTP headers.
*/
function headers_list(): array {
   $headers = response_headers_list();
   $result = [];
   foreach ($headers as $pair) {
       $result[] = "$pair[0]: $pair[1]";
   }
   return $result;
}

/**
* Checks if headers have already been sent and optionally returns the file and line number where the output started.
*
* @param string|null $file Optional. If provided, this will be set to the filename where output started.
* @param int|null $line Optional. If provided, this will be set to the line number where output started.
* @return bool Returns true if headers have already been sent, false otherwise.
*/
function headers_sent(&$file = null, &$line = null) {
   $g = RequestContext::instance();
   if (isset($g->openswoole_response) && $g->openswoole_response !== null) {
       return !$g->openswoole_response->isWritable();
   }
   return false;
}

/**
 * Remove a previously set response header. With no argument, clears all.
 */
function header_remove(?string $name = null): void
{
    $response = RequestContext::instance()->zealphp_response;
    if ($response === null) {
        return;
    }
    if ($name === null) {
        $response->headersList = [];
        return;
    }
    $response->headersList = array_values(array_filter(
        $response->headersList,
        static fn($pair) => strcasecmp($pair[0], $name) !== 0
    ));
}

/**
 * Force the current output buffer to the client. In main-worker mode, this
 * switches the response into streaming mode (headers flushed, body chunks
 * written via OpenSwoole). Subsequent echo+flush calls stream incrementally.
 */
function flush(): void
{
    $g = RequestContext::instance();
    if ($g->openswoole_response === null) {
        return;
    }
    if (!$g->openswoole_response->isWritable()) {
        return;
    }
    if (!($g->_streaming ?? false)) {
        $g->_streaming = true;
        if (isset($g->zealphp_response) && $g->zealphp_response !== null) {
            $g->zealphp_response->flush();
        }
    }
    if (ob_get_level() > 0) {
        $data = ob_get_clean();
        if ($data !== false && $data !== '') {
            $g->openswoole_response->write($data);
        }
        ob_start();
    }
}

function ob_flush(): void
{
    \ZealPHP\flush();
}

function ob_end_flush(): void
{
    \ZealPHP\flush();
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

/**
 * Apache mod_php toggles implicit flush on/off. ZealPHP buffers per request
 * by default; we accept the call as a no-op rather than crashing legacy code.
 */
/**
 * @param bool|int $enable
 */
function ob_implicit_flush($enable = true): void
{
    // no-op
}

/**
 * Apache mod_php getallheaders() / apache_request_headers() — return all
 * inbound request headers with canonical (Hyphen-Capitalized) case.
 *
 * @return array<string, string>
 */
function apache_request_headers(): array
{
    $g = RequestContext::instance();
    $out = [];
    $raw = [];
    if (isset($g->zealphp_request) && $g->zealphp_request !== null) {
        $raw = $g->zealphp_request->parent->header ?? [];
    }
    foreach ($raw as $name => $value) {
        $canonical = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower((string)$name))));
        $out[$canonical] = is_array($value) ? implode(', ', $value) : (string)$value;
    }
    return $out;
}

/**
 * @return array<string, string>
 */
function getallheaders(): array
{
    return apache_request_headers();
}

/**
 * Apache mod_php apache_response_headers() — currently set outbound headers.
 *
 * @return array<string, string>
 */
function apache_response_headers(): array
{
    $response = RequestContext::instance()->zealphp_response;
    if ($response === null) {
        return [];
    }
    $out = [];
    foreach ($response->headersList as $pair) {
        $out[$pair[0]] = $pair[1];
    }
    return $out;
}

/**
 * Apache mod_php per-request env table. Backed by Legacy\ApacheContext on
 * G; lifetime = one request. Lazy — only allocated if legacy code calls
 * apache_setenv/getenv/note.
 */
function apache_setenv(string $variable, string $value, bool $walk_to_top = false): bool
{
    $g = RequestContext::instance();
    if ($g->apacheContext === null) {
        $g->apacheContext = new \ZealPHP\Legacy\ApacheContext();
    }
    $g->apacheContext->env[$variable] = $value;
    return true;
}

/**
 * @return string|false
 */
function apache_getenv(string $variable, bool $walk_to_top = false)
{
    $ctx = RequestContext::instance()->apacheContext;
    return $ctx === null ? false : ($ctx->env[$variable] ?? false);
}

/**
 * Apache mod_php apache_note() — per-request note table. Returns previous value.
 */
function apache_note(string $note_name, ?string $note_value = null): string
{
    $g = RequestContext::instance();
    $previous = (string)($g->apacheContext->notes[$note_name] ?? '');
    if ($note_value !== null) {
        if ($g->apacheContext === null) {
            $g->apacheContext = new \ZealPHP\Legacy\ApacheContext();
        }
        $g->apacheContext->notes[$note_name] = $note_value;
    }
    return $previous;
}

/**
 * Apache mod_php virtual() — performs an internal subrequest. Not supported
 * in ZealPHP's single-process model; we log once and return false rather than
 * crashing legacy code.
 */
function virtual(string $uri): bool
{
    elog("virtual() is not supported in ZealPHP — ignored: $uri", 'warn');
    return false;
}

/**
 * set_time_limit() — OpenSwoole has its own coroutine/worker timeouts and
 * native PHP execution-time limit is irrelevant here. Treated as no-op success.
 */
function set_time_limit(int $seconds): bool
{
    return true;
}

/**
 * ignore_user_abort() — Apache mod_php controls whether the script keeps
 * running after the client disconnects. Tracked in G; with OpenSwoole the
 * coroutine continues regardless, but we honor the API contract.
 */
/**
 * @param bool|null $enable
 */
function ignore_user_abort($enable = null): int
{
    $g = RequestContext::instance();
    $previous = $g->ignore_user_abort_state;
    if ($enable !== null) {
        $g->ignore_user_abort_state = $enable ? 1 : 0;
    }
    return $previous;
}

function connection_status(): int
{
    $g = RequestContext::instance();
    if (isset($g->openswoole_response) && $g->openswoole_response !== null
        && !$g->openswoole_response->isWritable()) {
        return 1; // CONNECTION_ABORTED
    }
    return 0; // CONNECTION_NORMAL
}

function connection_aborted(): int
{
    $g = RequestContext::instance();
    if (isset($g->openswoole_response) && $g->openswoole_response !== null
        && !$g->openswoole_response->isWritable()) {
        return 1;
    }
    return 0;
}

/**
 * Apache's URL-rewrite output handler — not used in ZealPHP. No-op.
 */
function output_add_rewrite_var(string $name, string $value): bool
{
    return false;
}

function output_reset_rewrite_vars(): bool
{
    return true;
}

/**
 * is_uploaded_file() — verifies that $filename is one of the temp paths
 * registered in this request's $_FILES. Rejects forged paths from user input.
 */
function is_uploaded_file(string $filename): bool
{
    $g = RequestContext::instance();
    foreach ($g->files ?? [] as $entry) {
        if (!is_array($entry)) continue;
        $tmp = $entry['tmp_name'] ?? null;
        if (is_array($tmp)) {
            if (in_array($filename, $tmp, true)) return true;
        } elseif (is_string($tmp) && $tmp === $filename) {
            return true;
        }
    }
    return false;
}

/**
 * move_uploaded_file() — equivalent of Apache+mod_php behavior, gated by
 * is_uploaded_file() and falling back to copy+unlink across filesystems.
 */
function move_uploaded_file(string $from, string $to): bool
{
    if (!is_uploaded_file($from)) {
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
}

/**
 * Per-coroutine set_error_handler override. The native PHP handler is
 * installed at boot and delegates to G's per-coroutine stack — this override
 * just records the user-space registration without touching the engine.
 */
function set_error_handler(?callable $callback, int $error_levels = E_ALL): ?callable
{
    $g = RequestContext::instance();
    $stack = $g->error_handlers_stack;
    $prev = !empty($stack) ? $stack[count($stack) - 1][0] : null;
    if ($callback === null) {
        array_pop($stack);
    } else {
        $stack[] = [$callback, $error_levels];
    }
    $g->error_handlers_stack = $stack;
    return $prev;
}

function restore_error_handler(): bool
{
    $g = RequestContext::instance();
    $stack = $g->error_handlers_stack;
    array_pop($stack);
    $g->error_handlers_stack = $stack;
    return true;
}

function set_exception_handler(?callable $callback): ?callable
{
    $g = RequestContext::instance();
    $stack = $g->exception_handlers_stack;
    $prev = !empty($stack) ? $stack[count($stack) - 1] : null;
    if ($callback === null) {
        array_pop($stack);
    } else {
        $stack[] = $callback;
    }
    $g->exception_handlers_stack = $stack;
    return $prev;
}

function restore_exception_handler(): bool
{
    $g = RequestContext::instance();
    $stack = $g->exception_handlers_stack;
    array_pop($stack);
    $g->exception_handlers_stack = $stack;
    return true;
}

/**
 * Per-request shutdown function — fires after the route handler returns and
 * before the PSR response is emitted, so the function can still call
 * echo/header/http_response_code and have those land in the response.
 */
function register_shutdown_function(callable $callback, mixed ...$args): void
{
    $g = RequestContext::instance();
    $list = $g->shutdown_functions;
    $list[] = [$callback, $args];
    $g->shutdown_functions = $list;
}

/**
 * Per-coroutine error_reporting. Falls back to the level captured at App boot.
 */
function error_reporting(?int $error_level = null): int
{
    $g = RequestContext::instance();
    $current = $g->error_reporting_level ?? \ZealPHP\App::$initial_error_reporting;
    if ($error_level !== null) {
        $g->error_reporting_level = $error_level;
    }
    return $current;
}
