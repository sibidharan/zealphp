<?php
namespace ZealPHP;

use Exception;
use ZealPHP\App;
use ZealPHP\StringUtils;
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;
use Throwable;

/**
 * Retrieve a value from the $_GET superglobal with a default fallback.
 *
 * @param string $key     The GET parameter name.
 * @param mixed  $default Value to return if the key is not set.
 * @return mixed          The value from $_GET or the default.
 */
function get($key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/**
 * Handle a request using a preforking process model.
 *
 * Executes the provided task logic in a separate Process, captures its output,
 * HTTP headers, and cookies metadata, then returns the response data.
 *
 * @param callable $taskLogic The logic to execute in the preforked process.
 * @param bool     $wait      Whether to wait for the task to complete. Default true.
 * @return string             The output from the task logic.
 */
function prefork_request_handler($taskLogic, $wait = true)
{
    $worker = new Process(function ($worker) use ($taskLogic) {
        stream_wrapper_unregister("php");
        stream_wrapper_register("php", \ZealPHP\IOStreamWrapper::class);
        $g = G::instance();
        elog("prefork_request_handler enter response_header_list: ".var_export($g->response_headers_list, true));
        try {
            $g->response_headers_list = [];
            $g->status = 200;
            ob_start();
            $taskLogic($worker);
            $data = ob_get_clean();
            $worker->write(empty($data) ? 'EOF' : $data);
            $response_code = http_response_code();
            $worker->push(serialize([
                'status_code' => $response_code ? $response_code : 200,
                'headers' => $g->response_headers_list,
                'cookies' => $g->response_cookies_list,
                'rawcookies' => $g->response_rawcookies_list,
                'exit_code' => 0,
                'length' => strlen($data),
                'exited' => false,
                'finished' => true
            ]));
            // elog("prefork_request_handler exit response_header_list: ".var_export($g->response_headers_list, true));
            $worker->exit(0);
        } catch (Throwable $e) {
            $data = ob_get_clean();
            $worker->write(empty($data) ? 'EOF' : $data);
            $exit_code = $e instanceof \OpenSwoole\ExitException;
            $response_code = http_response_code();
            if(!$response_code){
                $response_code = $exit_code ? 200 : 500;
            }
            $worker->push(serialize([
                'status_code' => $response_code,
                'headers' => $g->response_headers_list,
                'cookies' => $g->response_cookies_list,
                'rawcookies' => $g->response_rawcookies_list,
                'exited' => $exit_code,
                'length' => strlen($data),
                'error' => $e
            ]));
            // elog("coprocess error: ".var_export($e, true));
            // elog("prefork_request_handler exit response_header_list: ".var_export($g->response_headers_list, true));
            $worker->exit(0);
        }
    }, false, SOCK_STREAM, true);

    // Start the worker
    $worker->useQueue(0, 2);
    $worker->start();
    $recv = $data = $worker->read();
    #TODO: test if this logic works
    while (strlen($recv) == 8192) {
        $recv = $worker->read();
        if ($recv === '' || $recv === false) {
            break;
        }
        $data .= $recv;
    }
    if($data == 'EOF'){
        $data   = '';
    }
    Process::wait($wait);
    $g = G::instance();
    $response_metadata = unserialize($worker->pop(65535));
    // elog("coprocess resposnse metadata: ".var_export($response_metadata, true));
    $worker->freeQueue();
    if($response_metadata){
        response_set_status($response_metadata['status_code'] ?? 200);
        foreach($response_metadata['headers'] as $pair){
            $g->zealphp_response->header(...$pair);
        }
        foreach($response_metadata['cookies'] as $pair){
            $g->zealphp_response->cookie(...$pair);
        }
        foreach($response_metadata['rawcookies'] as $pair){
            $g->zealphp_response->rawCookie(...$pair);
        }
        if (isset($response_metadata['exited']) and isset($response_metadata['error']) and !$response_metadata['exited'] and $response_metadata['error']) {
            response_set_status(500);
            throw $response_metadata['error'];
        }
    }
    return $data;
}

/**
 * Execute a task in a separate process and capture its output.
 *
 * Runs the provided $taskLogic in a new Process, waits if requested,
 * and returns any buffered output.
 *
 * @param callable $taskLogic The logic to execute in the separate process.
 * @param bool     $wait      Whether to wait for the process to complete. Default true.
 * @return string             The captured output from the process.
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
 * Alias for coprocess(): execute a task in a separate process.
 *
 * @param callable $taskLogic The logic to execute in a separate process.
 * @return string             The captured output from the process.
 */
function coproc($taskLogic){
    return coprocess($taskLogic);
}


/**
 * Generate a Java-style stack trace string for an exception, including nested causes.
 *
 * @param \Throwable   $e    The exception to trace.
 * @param array|null    $seen (Internal) Array of seen file:line entries to prevent recursion.
 * @return string           The formatted exception trace.
 */
function jTraceEx($e, $seen=null)
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

/**
 * Get the base name of the calling PHP script without its .php extension.
 *
 * @return string The script name without extension.
 */
function zapi(){
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    $caller = array_shift($bt);
    return basename($caller['file'], '.php');
}

/**
 * Log a message with a tag and optional backtrace limit to PHP error log.
 *
 * @param string $message The message to log.
 * @param string $tag     The tag for grouping log entries. Default '*'.
 * @param int    $limit   Number of backtrace frames to include. Default 1.
 * @return void
 */
function elog($message, $tag = "*", $limit = 1){
    if($tag == "wordpress"){
        return;
    }
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit);
    $caller = array_shift($bt);
    $date = date('d-m-Y H:i:s');
    # add microseconds or nano seconds down to 6 decimal places
    $date .= substr((string)microtime(), 1, 6);
    $relative_path = str_replace(App::$cwd, '', $caller['file']);
    error_log("┌[$tag] $date $relative_path:$caller[line]
└❯ $message \n");
}

/**
 * Log detailed debug information with optional URI filter.
 *
 * @param mixed       $log           The message or data to log.
 * @param string      $tag           The log category (e.g. 'system', 'info').
 * @param string|null $filter        Optional URI substring filter.
 * @param bool        $invert_filter Whether to invert the filter logic.
 * @return void
 */
function zlog($log, $tag = "system", $filter = null, $invert_filter = false)
{
    if ($filter != null and !StringUtils::str_contains($_SERVER['REQUEST_URI'], $filter)) {
        return;
    }
    if ($filter != null and $invert_filter) {
        return;
    }

    // if(get_class(Session::getUser()) == "User") {
    //     $user = Session::getUser()->getUsername();
    // } else {
    //     $user = 'worker';
    // }

    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = 'cli';
    }

    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    $caller = array_shift($bt);
    $g = G::instance();
    if ((in_array($tag, ["system", "fatal", "error", "warning", "info", "debug"]))) {
        $date = date('l jS F Y h:i:s A');
        //$date = date('h:i:s A');
        if (is_object($log)) {
            $log = purify_array($log);
        }
        if (is_array($log)) {
            $log = json_encode($log, JSON_PRETTY_PRINT);
        }
        $unique_req_id = $g->session['UNIQUE_REQUEST_ID'];
        $request_uri = $g->server['REQUEST_URI'];
        if (error_log(
            '[*] #' . $tag . ' [' . $date . '] ' . " Request ID: $unique_req_id\n" .
                '    URL: ' . $request_uri . " \n" .
                '    Caller: ' . $caller['file'] . ':' . $caller['line'] . "\n" .
                '    Timer: ' . get_current_render_time() . ' sec' . " \n" .
                "    Message: \n" . indent($log) . "\n\n"
        )) {
        }
    }
}


/**
 * Get a configuration value from the global $__site_config JSON.
 *
 * @param string $key The configuration key to retrieve.
 * @return mixed      The configuration value, or null if not set.
 */
function get_config($key)
{
    global $__site_config;
    $array = json_decode($__site_config, true);
    if (isset($array[$key])) {
        return $array[$key];
    } else {
        return null;
    }
}

/**
 * Calculate elapsed time since session start.
 *
 * @return float The render time in seconds with microsecond precision.
 */
function get_current_render_time()
{
    $time = microtime();
    $time = explode(' ', $time);
    $time = $time[1] + $time[0];
    $finish = $time;
    $total_time = number_format(($finish - G::instance()->session['__start_time']), 5);
    return $total_time;
}


/**
 * Indent each line of the given string by a number of spaces.
 *
 * @param string $string The text to indent.
 * @param int    $indend Number of spaces to prepend to each line.
 * @return string        The indented text.
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
 * Convert an object or iterator to a pure array.
 *
 * @param mixed $obj The object or array to purify.
 * @return array     The resulting array.
 */
function purify_array($obj)
{
    $h = json_decode(json_encode($obj), true);
    //print_r($h);
    return empty($h) ? [] : $h;
}


/**
 * Generate a cryptographically secure unique identifier string.
 *
 * @param int $length Desired length of the ID. Default is 13.
 * @return string     The generated unique ID.
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
 * Log an HTTP access entry with status and response length.
 *
 * @param int $status HTTP status code. Default is 200.
 * @param int $length Response body length in bytes.
 * @return void
 */
function access_log($status = 200, $length){
    $g = G::instance();
    $time = date('d/M/Y:H:i:s');
    $time .= substr((string)microtime(), 1, 6);
    $remote = $g->server['REMOTE_ADDR'];
    $request = $g->server['REQUEST_METHOD'].' '.$g->server['REQUEST_URI'].' '.$g->server['SERVER_PROTOCOL'];
    $referer = $g->server['HTTP_REFERER'] ?? '-';
    $user_agent = $g->server['HTTP_USER_AGENT'] ?? '-';
    $log = "$remote - - [$time] \"$request\" $status $length \"$referer\" \"$user_agent\"\n";
    // file_put_contents('/var/log/zealphp/access.log', $log, FILE_APPEND);
    error_log($log);
}

/**
 * Add an HTTP header to the current response.
 *
 * @param string $key     Header name.
 * @param string $value   Header value.
 * @param bool   $ucwords Whether to capitalize header words. Default true.
 * @return void
 */
function response_add_header($key, $value, $ucwords = true)
{
    $g = G::instance();
    // elog("response_add_header: $key ".var_export($value, true));
    $g->zealphp_response->header($key, $value, $ucwords);
}


/**
 * Set the HTTP status code for the current response.
 *
 * @param int $status The HTTP status code to set.
 * @return void
 */
function response_set_status(int $status)
{
    $g = G::instance();
    if(is_int($status)){
        $g->status = $status;
    } else {
        $g->status = 200;
    } 
}

/**
 * Retrieve the list of HTTP headers queued for the response.
 *
 * @return array Array of header name/value pairs.
 */
function response_headers_list()
{
    $g = G::instance();
    return $g->response_headers_list;
}

/**
 * Set a cookie in the HTTP response.
 *
 * @param string $name     The name of the cookie.
 * @param string $value    The value of the cookie.
 * @param int    $expire   Expiration timestamp.
 * @param string $path     Cookie path.
 * @param string $domain   Cookie domain.
 * @param bool   $secure   Secure flag (HTTPS only).
 * @param bool   $httponly HttpOnly flag.
 * @return void
 */
function setcookie($name, $value = "", $expire = 0, $path = "", $domain = "", $secure = false, $httponly = false) {
    // $cookie = "$name=$value";
    // if ($expire) {
    //     $cookie .= "; expires=" . gmdate('D, d-M-Y H:i:s T', $expire);
    // }
    // if ($path) {
    //     $cookie .= "; path=$path";
    // }
    // if ($domain) {
    //     $cookie .= "; domain=$domain";
    // }
    // if ($secure) {
    //     $cookie .= "; secure";
    // }
    // if ($httponly) {
    //     $cookie .= "; httponly";
    // }
    $g = G::instance();
    $g->zealphp_response->cookie($name, $value, $expire, $path, $domain, $secure, $httponly);
}

/**
 * Set a raw cookie in the HTTP response without URL encoding.
 *
 * @param string $name     The name of the cookie.
 * @param string $value    The value of the cookie.
 * @param int    $expire   Expiration timestamp.
 * @param string $path     Cookie path.
 * @param string $domain   Cookie domain.
 * @param bool   $secure   Secure flag (HTTPS only).
 * @param bool   $httponly HttpOnly flag.
 * @return void
 */
function setrawcookie($name, $value = "", $expire = 0, $path = "", $domain = "", $secure = false, $httponly = false) {
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
    $g = G::instance();
    $g->zealphp_response->rawCookie($name, $value, $expire, $path, $domain, $secure, $httponly);
}

/**
 * Add a raw header line to the HTTP response.
 *
 * @param string     $header             The header line (e.g., "Key: Value").
 * @param bool       $replace            Whether to replace a previous similar header.
 * @param int|null   $http_response_code Optional HTTP status code.
 * @return void
 */
function header($header, $replace = true, $http_response_code = null) {
    // elog("Setting header: $header");
    $header = explode(':', $header, 2);
    if (count($header) < 2) {
        return false;
    }
    $name = trim($header[0]);
    $value = trim($header[1]);
    response_add_header($name, $value);
}


/**
 * Get or set the HTTP response status code.
 *
 * @param int|null $code Optional status code to set. If null, returns the current code.
 * @return int          The HTTP status code when getting.
 */
function http_response_code($code = null) {
   if ($code !== null) {
       response_set_status($code);
   } else {
       return G::instance()->status;
   }
}

/**
 * Retrieve the list of HTTP headers to send, formatted as strings.
 *
 * @return string[] Array of header lines (e.g., "Key: Value").
 */
function headers_list() {
   $headers = response_headers_list();
   $result = [];
   foreach ($headers as $pair) {
       $result[] = "$pair[0]: $pair[1]";
   }
   return $result;
}

/**
 * Check if headers have already been sent to the client.
 *
 * @param string|null &$file If provided, filled with the filename where output started.
 * @param int|null    &$line If provided, filled with the line number where output started.
 * @return bool True if headers have already been sent, false otherwise.
 */
function headers_sent(&$file = null, &$line = null) {
   return false;
}
    