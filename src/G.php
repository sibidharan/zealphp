<?php

namespace ZealPHP;

use ZealPHP\App;

class G
{
    private static $instance = null;

    private function __construct()
    {
        $this->session_params = [];
        $this->status = null;
    }

    public static function instance()
    {
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