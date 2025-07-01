<?php

namespace ZealPHP;

use ZealPHP\App;

/**
 * G is a global singleton registry providing unified access to PHP superglobals
 * and application state within ZealPHP.
 */
class G
{
    private static $instance = null;

    /**
     * Initialize the global registry, setting up default session parameters and status.
     */
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

    /**
     * Magic getter to access superglobals or internal registry properties.
     *
     * When superglobals are enabled, keys like 'get', 'post', 'cookie', etc.
     * map to the corresponding PHP superglobals. Otherwise returns stored properties.
     *
     * @param string $key The property or superglobal name.
     * @return mixed Reference to the requested data.
     */
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

    /**
     * Magic setter to assign values to superglobals or internal registry properties.
     *
     * @param string $key   The property or superglobal name.
     * @param mixed  $value The value to set.
     */
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
