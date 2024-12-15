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
    }

    public static function getInstance()
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
            $superglobalKey = '_' . strtoupper($key);
            if (!isset($GLOBALS[$superglobalKey])) {
                // Initialize the superglobal if it doesn't exist
                $GLOBALS[$superglobalKey] = null;
            }
            return $GLOBALS[$superglobalKey];
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
        return self::getInstance()->$key;
    }

    public static function set($key, $value)
    {
        self::getInstance()->$key = $value;
    }

}