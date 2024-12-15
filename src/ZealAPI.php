<?php
namespace ZealPHP;
// error_reporting(E_ALL ^ E_DEPRECATED);

use ZealPHP\REST;
use ZealPHP\App;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;

class ZealAPI extends REST
{
    public $data = "";

    private $api_rpc;
    private $auth = null;
    public $_response = null;
    public $request = null;
    public $cwd = null;
    
    public function __construct($request, $response, $cwd)
    {
        $this->cwd = $cwd;
        $this->_response = $response;
        $this->request = $request;
        parent::__construct($request, $response);                  // Init parent contructor
    }

    /*
    * Public method for access api.
    * This method dynmically call the method based on the query string
    *
    */
    public function processApi($module, $request=null)
    {
        $g = G::getInstance();
        $module = $module ? '/'.$module : '';
        $func = basename($request);
        if (!isset($module) and (int)method_exists($this, $func) > 0) {
            $this->$func();
        } else {
            if (isset($module)) {
                $dir = $this->cwd.'/api'.$module;
                $g->server['DOCUMENT_ROOT'] = App::$cwd . '/api';
                $file = $dir.'/'.$request.'.php';
                if (file_exists($file)) {
                    include $file;
                    try {
                        $this->api_rpc = \Closure::bind(${$func}, $this, get_class());
                    } catch (\TypeError $e) {
                        elog(jTraceEx($e), "error");
                        $this->response($this->json(['error'=>'method_not_found']), 404);
                        return;
                    }
                    $g->server['PHP_SELF'] = $module.'/'.$request.'.php';
                    if(App::$superglobals) {
                        $_SERVER['PHP_SELF'] = $g->server['PHP_SELF'];
                    }
                    $handler = $this->api_rpc;
                    $reflection = is_array($handler)
                    ? new \ReflectionMethod($handler[0], $handler[1])
                    : new \ReflectionFunction($handler);

                    $invokeArgs = [];
                    foreach ($reflection->getParameters() as $param) {
                        $pname = $param->getName();
                        if (isset($params[$pname])) {
                            $invokeArgs[] = $params[$pname];
                        } else if ($pname == 'app'){
                            $invokeArgs[] = $this;
                        } else if ($pname == 'request'){
                            $invokeArgs[] = $this->request;
                        } else if ($pname == 'response'){
                            $invokeArgs[] = $this->_response;
                        } else if ($pname == 'server'){
                            $invokeArgs[] = App::$server;
                        } else {
                            $invokeArgs[] = $param->isDefaultValueAvailable() 
                                ? $param->getDefaultValue() 
                                : null;
                        }
                    }
                    $this->$func(...$invokeArgs);
                } else {
                    $this->response($this->json(['error'=>'method_not_found']), 404);
                }
            } else {
                //we can even process functions without module here.
                $this->response($this->json(['error'=>'method_not_found']), 404);
            }
        }
    }

    // public function isAuthenticated()
    // {
    //     return Session::$authStatus == Constants::STATUS_LOGGEDIN ;
    // }

    /**
     * @param $param Http Parameters
     * Checks if all supplied parameters exists
     */
    public function paramsExists($parms = array())
    {
        $exists = true;
        foreach ($parms as $param) {
            if (!array_key_exists($param, $this->_request)) {
                $exists = false;
            }
        }
        return $exists;
    }

    // public function isAuthenticatedFor(User $user)
    // {
    //     return Session::getUser()->getEmail() == $user->getEmail();
    // }

    // public function isAdmin()
    // {
    //     return Session::isAdmin();
    // }

    // public function getUsername()
    // {
    //     return Session::getUser()->getUsername();
    // }

    public function die($e)
    {
        $data = [
            "error" => $e->getMessage(),
            "stack" => jTraceEx($e),
            "type" => "exception"
        ];
        elog(jTraceEx($e), "error");
        $response_code = 400;
        if ($e->getMessage() == "Expired token" || $e->getMessage() == "Unauthorized") {
            $response_code = 403;
        }

        if ($e->getMessage() == "Not found") {
            $response_code = 404;
        }
        $data = $this->json($data);
        $this->response($data, $response_code);
    }

    //TODO: Buggy current-call- hangs if calling nonexisting method inside API.
    public function __call($method, $args)
    {
        
        if (is_callable($this->api_rpc)) {
            return call_user_func_array($this->api_rpc, $args);
        } else {
            $error = ['error'=>'methood_not_callable', 'method'=>$method];
            // logit($error, "fatal");
            $this->response($this->json($error), 404);
        }
    }

    /*
    Encode array into JSON
    */
    private function json($data)
    {
        if (is_array($data)) {
            return json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return "{}";
        }
    }
}
