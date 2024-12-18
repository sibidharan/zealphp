<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

class Response
{
    public \OpenSwoole\Http\Response $parent;

    private $statusCode;
    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
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
        \ZealPHP\elog($name);

        if (property_exists($this->parent, $name)) {
            return $this->parent->$name;
        } else {
            if($name == 'parent'){
                return $this->parent;
            }
        }
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }

    // Magic method to set properties on the parent
    public function __set($name, $value)
    {
        \ZealPHP\elog($name);
        if($name == 'parent'){
            $this->parent = $value;
            return;
        }
        if (property_exists($this->parent, $name)) {
            $this->parent->$name = $value;
        } else {
            $this->$name = $value;
        }
    }

    public function status(int $statusCode, string $reason = ''): bool
    {
        $this->statusCode = $statusCode;
        $g = \ZealPHP\G::instance();
        $g->status = $statusCode;
        return $this->parent->status($statusCode, $reason);
    }

    public function json($data, $status = 200)
    {
        $this->header('Content-Type', 'application/json');
        $this->status($status);
        $this->end(json_encode($data));
    }

    // You can override methods if necessary or add more custom methods
    public function header(string $key, string $value): bool
    {
        return $this->parent->header($key, $value);
    }

    public function end(?string $data = null): bool
    {
        return $this->parent->end($data);
    }

    // Include any other methods you need to forward explicitly if required
}