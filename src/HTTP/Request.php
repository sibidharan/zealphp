<?php

namespace ZealPHP\HTTP; 

namespace ZealPHP\HTTP;

/**
 * Request wraps the OpenSwoole HTTP Request and exposes its fields in ZealPHP.
 *
 * Provides properties for headers, server parameters, cookies, GET/POST data,
 * and forwards method calls to the underlying request object.
 */
class Request extends \OpenSwoole\HTTP\Request
{
    private \OpenSwoole\Http\Request $parent;
    public $header;

    public $server;

    public $cookie;

    public  $get;

    public $files;

    public $post;

    public $tmpfiles;

    /**
     * Construct a new Request wrapper for the given OpenSwoole HTTP Request.
     *
     * @param \OpenSwoole\Http\Request $request The original Swoole HTTP request.
     */
    public function __construct(\OpenSwoole\Http\Request $request)
    {
        $this->parent = $request;
        $this->header = &$request->header;
        $this->server = &$request->server;
        $this->cookie = &$request->cookie;
        $this->get = &$request->get;
        $this->files = &$request->files;
        $this->post = &$request->post;
        $this->tmpfiles = &$request->tmpfiles;
    }

    /**
     * Forward method calls to the underlying OpenSwoole request object.
     *
     * @param string $name      Method name to call.
     * @param array  $arguments Arguments for the method.
     * @return mixed            Result of the forwarded method.
     * @throws \BadMethodCallException If method does not exist.
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * Forward property access to the underlying OpenSwoole request or this wrapper.
     *
     * @param string $name The property name.
     * @return mixed       Reference to the property value.
     * @throws \InvalidArgumentException If property does not exist.
     */
    public function &__get($name)
    {
        if($name == 'parent'){
            return $this->parent;
        }
        if (property_exists($this->parent, $name)) {
            return $this->parent->$name;
        }
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }

    /**
     * Forward setting a property on the underlying request or this wrapper.
     *
     * @param string $name  The property name.
     * @param mixed  $value The value to assign.
     */
    public function __set($name, $value)
    {
        if (property_exists($this->parent, $name)) {
            $this->parent->$name = $value;
        } else {
            $this->$name = $value;
        }
    }

    // Add your custom methods or override existing ones here
}
