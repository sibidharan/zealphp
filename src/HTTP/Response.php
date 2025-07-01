<?php

namespace ZealPHP\HTTP;

use function ZealPHP\response_set_status;

/**
 * Response wraps the OpenSwoole HTTP Response to collect headers and cookies
 * before flushing them to the client.
 */
class Response
{
    public \OpenSwoole\Http\Response $parent;
    /**
     * Construct a new Response wrapper for the given OpenSwoole HTTP Response.
     *
     * Initializes global state lists for headers and cookies.
     *
     * @param \OpenSwoole\Http\Response $response The original response object.
     */
    public function __construct(\OpenSwoole\Http\Response $response)
    {
        $this->parent = $response;
        $g = \ZealPHP\G::instance();
        $g->response_headers_list = [];
        $g->response_cookies_list = [];
        $g->response_rawcookies_list = [];
    }

    /**
     * Forward method calls to the underlying OpenSwoole response object.
     *
     * @param string $name      Method name to call.
     * @param array  $arguments Arguments for the method.
     * @return mixed            Result of the forwarded call.
     * @throws \BadMethodCallException If method does not exist on the parent.
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->parent, $name)) {
            return call_user_func_array([$this->parent, $name], $arguments);
        }
        throw new \BadMethodCallException("Method {$name} does not exist");
    }

    /**
     * Forward property access to the underlying response or wrapper.
     *
     * @param string $name The property name.
     * @return mixed       Reference to the property value.
     * @throws \InvalidArgumentException If property does not exist.
     */
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

    /**
     * Forward setting a property on the underlying response or wrapper.
     *
     * @param string $name  The property name.
     * @param mixed  $value The value to set.
     */
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

    /**
     * Set the HTTP status code for the response.
     *
     * @param int    $statusCode HTTP status code to send.
     * @param string $reason     Optional reason phrase (unused).
     * @return bool True on success.
     */
    public function status(int $statusCode, string $reason = ''): bool
    {
        $this->statusCode = $statusCode;
        $g = \ZealPHP\G::instance();
        $g->status = $statusCode;
        return $this->parent->status($statusCode, $reason);
    }

    /**
     * Send a JSON response with the given data and HTTP status.
     *
     * @param mixed $data   Data to encode as JSON.
     * @param int   $status HTTP status code to set.
     * @return void
     */
    public function json($data, $status = 200)
    {
        $this->header('Content-Type', 'application/json');
        $this->status($status);
        $this->end(json_encode($data));
    }

    // You can override methods if necessary or add more custom methods
    /**
     * Queue a response header to be sent on flush.
     *
     * @param string $key   Header name.
     * @param string $value Header value.
     * @return bool True on success.
     */
    public function header(string $key, string $value): bool
    {
        $g = \ZealPHP\G::instance();
        $g->response_headers_list[] = [$key, $value];
        if(strtolower($key) == 'location' && $value){
            $g->status = 302;
        }
        return true;
    }

    /**
     * Queue a Set-Cookie header to be sent on flush.
     *
     * @param string $key       Cookie name.
     * @param string $value     Cookie value.
     * @param int    $expire    Expiration timestamp.
     * @param string $path      Cookie path.
     * @param string $domain    Cookie domain.
     * @param bool   $secure    Secure flag.
     * @param bool   $httponly  HttpOnly flag.
     * @param string $samesite  SameSite attribute.
     * @param string $priority  Cookie priority.
     * @return bool True on success.
     */
    public function cookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $g = \ZealPHP\G::instance();
        $g->response_cookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    /**
     * Queue a raw Set-Cookie header without URL encoding, to be sent on flush.
     *
     * @param string $key       Cookie name.
     * @param string $value     Cookie value.
     * @param int    $expire    Expiration timestamp.
     * @param string $path      Cookie path.
     * @param string $domain    Cookie domain.
     * @param bool   $secure    Secure flag.
     * @param bool   $httponly  HttpOnly flag.
     * @param string $samesite  SameSite attribute.
     * @param string $priority  Cookie priority.
     * @return bool True on success.
     */
    public function rawCookie(string $key, string $value = '', int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false, string $samesite = '', string $priority = ''): bool
    {
        $g = \ZealPHP\G::instance();
        $g->response_rawcookies_list[] = [$key, $value, $expire, $path, $domain, $secure, $httponly, $samesite, $priority];
        return true;
    }

    /**
     * End the response and send data to the client.
     *
     * @param string|null $data Optional data to send before ending.
     * @return bool True on success.
     */
    public function end(?string $data = null): bool
    {
        return $this->parent->end($data);
    }

    /**
     * Flush queued headers and cookies to the underlying response.
     *
     * Iterates over collected headers and cookies, sending them,
     * then clears the global lists.
     *
     * @return bool True if flush succeeded, false otherwise.
     */
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
