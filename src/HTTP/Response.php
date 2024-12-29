<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

class Response
{
    public \OpenSwoole\Http\Response $parent;
    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
        $g = \ZealPHP\G::instance();
        $g->response_headers_list = [];
        $g->response_cookies_list = [];
        $g->response_rawcookies_list = [];
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
        $g = \ZealPHP\G::instance();
        $g->response_headers_list[] = [$key, $value];
        if(strtolower($key) == 'location' && $value){
            $g->status = 302;
        }
        return true;
    }

    public function cookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $g = \ZealPHP\G::instance();
        $g->response_cookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    public function rawCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $g = \ZealPHP\G::instance();
        $g->response_rawcookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    public function end(?string $data = null): bool
    {
        return $this->parent->end($data);
    }

    public function flush(): bool
    {
        if($this->parent->isWritable()){
            $g = \ZealPHP\G::instance();
            foreach ($g->response_headers_list as $header) {
                $this->parent->header(...$header);
            }
            foreach ($g->response_cookies_list as $cookie) {
                $this->parent->cookie(...$cookie);
            }
            foreach ($g->response_rawcookies_list as $cookie) {
                $this->parent->rawCookie(...$cookie);
            }
            $g->response_headers_list = [];
            $g->response_cookies_list = [];
            $g->response_rawcookies_list = [];
            $g->status = null;
            return true;
        } else {
            return false;
        }
    }
}