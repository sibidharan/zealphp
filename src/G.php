<?php

namespace ZealPHP;

use ZealPHP\App;

class G
{
    private static $instance = null;
    // Other properties...

    private function __construct()
    {
        // Initialize properties...
        $this->session_params = [];
        $this->status = null;
    }

    public static function instance()
    {
        if (self::$instance === null) {
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
            $superglobalKey = '_' . strtoupper($key);
            $GLOBALS[$superglobalKey] = $value;
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