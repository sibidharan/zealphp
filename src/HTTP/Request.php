<?php

namespace ZealPHP\HTTP; 

namespace ZealPHP\HTTP;

class Request extends \OpenSwoole\HTTP\Request
{
    public \OpenSwoole\Http\Request $parent;
    /** @var array<string, string>|null */
    public $header;

    /** @var array<string, mixed>|null */
    public $server;

    /** @var array<string, string>|null */
    public $cookie;

    /** @var array<string, mixed>|null */
    public $get;

    /** @var array<string, mixed>|null */
    public $files;

    /** @var array<string, mixed>|null */
    public $post;

    /** @var array<string, mixed>|null */
    public $tmpfiles;

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
     * Forward method calls to the underlying OpenSwoole request.
     *
     * @param string            $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * Proxy property reads to the underlying OpenSwoole request.
     *
     * @param string $name
     * @return mixed
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
     * Proxy property writes to the underlying OpenSwoole request.
     *
     * @param string $name
     * @param mixed  $value
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