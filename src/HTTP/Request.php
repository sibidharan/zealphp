<?php

namespace ZealPHP\HTTP; 

namespace ZealPHP\HTTP;

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

    // Magic method to forward method calls to the parent
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    // Magic method to get properties from the parent
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

    // Magic method to set properties on the parent
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