<?php

namespace ZealPHP;

use ZealPHP\App;

#[\AllowDynamicProperties]
class G
{
    private static $instance = null;

    // Declared properties bypass __get/__set — direct pointer access (~2ns vs ~50ns)
    public array $server = [];
    public array $get = [];
    public array $post = [];
    public array $request = [];
    public array $cookie = [];
    public array $files = [];
    public array $session = [];
    public array $session_params = [];
    public ?int $status = null;
    public ?bool $_streaming = null;
    public ?bool $_session_started = null;
    public mixed $zealphp_request = null;
    public mixed $zealphp_response = null;
    public mixed $openswoole_request = null;
    public mixed $openswoole_response = null;
    public array $response_headers_list = [];
    public array $response_cookies_list = [];
    public array $response_rawcookies_list = [];

    private function __construct()
    {
    }

    public static function instance()
    {
        if (!App::$superglobals) {
            $cid = \OpenSwoole\Coroutine::getCid();
            if ($cid >= 0) {
                $context = \OpenSwoole\Coroutine::getContext($cid);
                if (!isset($context['__g'])) {
                    $context['__g'] = new G();
                }
                return $context['__g'];
            }
        }
        if (self::$instance === null) {
            $bt = debug_backtrace();
            $bt = array_shift($bt);
            elog("Creating new G instance from $bt[file]:$bt[line]");
            self::$instance = new G();
        }
        return self::$instance;
    }

    // Return by reference
    public function &__get($key)
    {
        if (App::$superglobals) {
            if (in_array($key, ['get', 'post', 'cookie', 'files', 'server', 'request', 'env', 'session'])) {
                $superglobalKey = '_' . strtoupper($key);
                if (!isset($GLOBALS[$superglobalKey])) {
                    // Initialize the superglobal if it doesn't exist
                    $GLOBALS[$superglobalKey] = null;
                }
                return $GLOBALS[$superglobalKey];
            }
            return $GLOBALS[$key];
        } else {
            if (!isset($this->$key)) {
                // Initialize the property if it doesn't exist
                $this->$key = null;
            }
            return $this->$key;
        }
    }

    public function __set($key, $value)
    {
        if (App::$superglobals) {
            if (in_array($key, ['get', 'post', 'cookie', 'files', 'server', 'request', 'env', 'session'])) {
                $superglobalKey = '_' . strtoupper($key);
                // elog("Setting superglobal $key");
                $GLOBALS[$superglobalKey] = $value;
            } else {
                $GLOBALS[$key] = $value;
            }
            
        } else {
            $this->$key = $value;
        }
    }

    public static function get($key)
    {
        return self::instance()->$key;
    }

    public static function set($key, $value)
    {
        self::instance()->$key = $value;
    }

}
