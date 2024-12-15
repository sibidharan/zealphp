<?php
namespace ZealPHP;
use ZealPHP\App;
use OpenSwoole\Coroutine as co;
class G
{
    private static $instance;

    private function __construct()
    {
        $this->get = [];
        $this->post = [];
        $this->files = [];
        $this->cookie = [];
        $this->session = [];
        $this->server = [];
        $this->request = [];
        $this->env = [];
        $this->session_params = [];
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new G();
        }
        return self::$instance;
    }

    public function __get($key)
    {
        return $this->$key;
    }

    public function __set($key, $value)
    {
        $this->$key = $value;
    }

    public static function get($key)
    {
        return self::getInstance()->$key;
    }

    public static function set($key, $value)
    {
        self::getInstance()->$key = $value;
    }
}

// // Our HTTP server instance
// $server = new Swoole\HTTP\Server("127.0.0.1", 9501);

// // ...

// // The request event handler callback
// $server->on('Request', function($request, $response)
// {
//     /*
//      * At the start of every new request, setup global
//      * request variables using Swoole server methods.
//      */
//    ContextManager::set('_GET', (array)$request->get);
//    ContextManager::set('_POST', (array)$request->post);
//    ContextManager::set('_FILES', (array)$request->files);
//    ContextManager::set('_COOKIE', (array)$request->cookie);

//   // And when you use them
//   echo ContextManager::get('_GET')['foo'] ?? 'bar';

//   // Instead of traditional PHP global variables...
//   echo $_GET['foo'] ?? 'bar';
// });
