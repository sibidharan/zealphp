<?php

namespace ZealPHP;

class Cache
{
    protected static $redisConfig;

    public static $redis;

    public static function configure(array $config)
    {
        self::$redisConfig = $config;
    }

    protected static function connect()
    {
        if (!isset(self::$redis)) {
            self::$redis = new \Redis();
            self::$redis->connect(self::$redisConfig['host'], self::$redisConfig['port']);
            self::$redis->select(1);
        }
    }

    public static function set($key, $val)
    {
        self::connect();

        // if (is_object($val) or is_array($val)) {
        //     // Handle objects (optional, can throw exception or serialize)
        //     // throw new ObjectNotSupportedException();
        //     $val = serialize($val);
        // }



        self::$redis->set($key, serialize($val));
        // Optionally set expiration for the key
        // self::$redis->expire($key, 3600); // 1 hour
    }

    public static function get($key, $default = false)
    {
        self::connect();

        $serializedVal = self::$redis->get($key);
        if ($serializedVal === false) {
            return $default;
        }
        // logit($key, "dev");
        // logit($serializedVal, "dev");
        return unserialize($serializedVal);
    }

    public static function clear($key)
    {
        self::connect();
        self::$redis->del($key);
    }
}
