<?php
namespace ZealPHP;

use Exception;
use ZealPHP\App;
use ZealPHP\StringUtils;
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;
use Throwable;

function get($key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/**
 * Handles a request using a preforking model.
 *
 * @param callable $taskLogic The logic to be executed in the preforked process.
 * @param bool $wait Optional. Whether to wait for the task to complete. Default is true.
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

function coproc($taskLogic){
    return coprocess($taskLogic);
}


/**
* jTraceEx() - provide a Java style exception trace
* @param $exception
* @param $seen      - array passed to recursive calls to accumulate trace lines already seen
*                     leave as NULL when calling this function
* @return string of array strings, one entry per trace line
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

function zapi(){
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1);
    $caller = array_shift($bt);
    return basename($caller['file'], '.php');
}

/**
 * Logs a message with an optional tag and limit.
 *
 * @param string $message The message to log.
 * @param string $tag The tag to associate with the log message. Default is "*".
 * @param int $limit The limit for the log message. Default is 1.
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
 * Logs a message with an optional tag and filter.
 *
 * @param mixed  $log           The message or data to log.
 * @param string $tag           The tag to categorize the log entry. Default is "system".
 * @param mixed  $filter        Optional filter to apply to the log entry.
 * @param bool   $invert_filter Whether to invert the filter logic. Default is false.
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
 * Get the current render time since request received and started processing.
 *
 * This function calculates and returns the current render time.
 *
 * @return float The current render time in seconds.
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
 * @param  Any $obj
 * @return Array
 */
function purify_array($obj)
{
    $h = json_decode(json_encode($obj), true);
    //print_r($h);
    return empty($h) ? [] : $h;
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
 * @param int $status The HTTP status code to log. Default is 200.
 * @param int $length The length of the response content.
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
 * Adds a header to the response.
 *
 * @param string $key The name of the header.
 * @param string $value The value of the header.
 * @param bool $ucwords Optional. Whether to capitalize the first letter of each word in the header name. Default is true.
 */
function response_add_header($key, $value, $ucwords = true)
{
    $g = G::instance();
    // elog("response_add_header: $key ".var_export($value, true));
    $g->zealphp_response->header($key, $value, $ucwords);
}

/**
 * Sets the HTTP response status code.
 *
 * @param int $status The HTTP status code to set for the response.
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
 * Retrieves all the response headers.
 *
 * @return array An associative array of all the response headers.
 */
function response_headers_list()
{
    $g = G::instance();
    return $g->response_headers_list;
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


/*
* @param int|null $code The HTTP status code to set. If null, the current status code is returned.
* @return int The current HTTP response status code.
*/
function http_response_code($code = null) {
   if ($code !== null) {
       response_set_status($code);
   } else {
       return G::instance()->status;
   }
}

/**
* Retrieves all HTTP headers sent by the server.
*
* This function returns an array of all the HTTP headers that have been sent
* by the server. It can be useful for debugging or logging purposes.
*
* @return array An associative array of all the HTTP headers.
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
* Checks if headers have already been sent and optionally returns the file and line number where the output started.
*
* @param string|null $file Optional. If provided, this will be set to the filename where output started.
* @param int|null $line Optional. If provided, this will be set to the line number where output started.
* @return bool Returns true if headers have already been sent, false otherwise.
*/
function headers_sent(&$file = null, &$line = null) {
   return false;
}