<?

namespace ZealPHP;

use ZealPHP\App;
use ZealPHP\StringUtils;
use OpenSwoole\Process;
use OpenSwoole\Coroutine as co;

function prefork_request_handler($taskLogic, $wait = true)
{
    $worker = new Process(function ($worker) use ($taskLogic) {
        $g = G::instance();
        // elog("prefork_request_handler response_header_list: ".var_export($g->response_headers_list, true));
        try {
            $g->response_headers_list = [];
            $g->status = 200;
            
            ob_start();
            $taskLogic($worker);
            $data = ob_get_clean();
            $worker->write($data);
            $response_code = http_response_code();
            $worker->push(serialize([
                'status_code' => $response_code ? $response_code : 200,
                'headers' => $g->response_headers_list,
                'exit_code' => 0,
                'length' => strlen($data)
            ]));
            $worker->exit(0);
        } catch (\Throwable $e) {
            $data = ob_get_clean();
            if($data){
                $worker->write($data);
            } else {
                $worker->write('EOF');
            }

            $exit_code = $e instanceof \OpenSwoole\ExitException;
            $response_code = http_response_code();
            if(!$response_code){
                $response_code = $exit_code ? 200 : 500;
            }
            $worker->push(serialize([
                'status_code' => $response_code,
                'headers' => $g->response_headers_list,
                'exited' => $exit_code,
                'length' => strlen($data),
                'error' => $e
            ]));
            // elog("coprocess error: ".var_export($e, true));
            $worker->exit(0);
        }
        elog("prefork_request_handler response_header_list: ".var_export($g->response_headers_list, true));
    }, false, SOCK_STREAM, false);

    // Start the worker
    $worker->useQueue(0, 2);
    $worker->start();
    Process::wait($wait);
    # read all data from buffer using while loop using $worker->read(8192)
    $recv = $data = $worker->read();
    # test if this logic works
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
    $response_metadata = unserialize($worker->pop(65535));
    $worker->freeQueue();
    response_set_status($response_metadata['status_code']);
    foreach($response_metadata['headers'] as $key => $value){
        response_add_header($key, $value, true);
    }
    // elog("coprocess request metadata: ".var_export($_SERVER, true));
    // elog("coprocess resposnse metadata: ".var_export($response_metadata, true));
    return $data;

}

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
            $worker->write($data);
            $worker->exit();
        } catch (\Throwable $e) {
            $data = ob_get_clean();
            if($data) $worker->write($data);
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
    return $worker->read();
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

function zapi($filename){
    return basename($filename, '.php');
}

function elog($message, $tag = "*", $limit = 1){
    $bt = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, $limit);
    $caller = array_shift($bt);

    $date = date('d-m-Y H:i:s');
    # add microseconds or nano seconds down to 6 decimal places
    $date .= substr((string)microtime(), 1, 6);
    $relative_path = str_replace(App::$cwd, '', $caller['file']);
    error_log("┌[$tag] $date $relative_path:$caller[line]
└❯ $message \n");
}

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

function response_add_header($key, $value, $ucwords = true)
{
    $g = G::instance();
    // elog("response_add_header: $key ".var_export($value, true));
    $g->zealphp_response->header($key, $value, $ucwords);
}

function response_set_status($status)
{
    $g = G::instance();
    $g->status = $status;
    $g->zealphp_response->status($status);
}

function response_headers_list()
{
    $g = G::instance();
    return $g->response_headers_list;
}