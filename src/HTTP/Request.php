<?php

namespace ZealPHP\HTTP;

/**
 * @method string|false getContent()
 * @method string|null rawContent()
 * @method array<string, string>|false getHeader()
 * @method array<string, string>|false getMethod()
 * @method string|false getData()
 * @method bool create(array<string, mixed> $settings = [])
 * @method bool parse(string $data)
 * @method bool isCompleted()
 */
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
        // OpenSwoole stubs type these as mixed; runtime they're array<string, ...>
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->header = &$request->header;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->server = &$request->server;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->cookie = &$request->cookie;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->get = &$request->get;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->files = &$request->files;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
        $this->post = &$request->post;
        /** @phpstan-ignore-next-line assign.propertyType — by-ref proxy to mixed-typed OpenSwoole prop */
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
            // @phpstan-ignore-next-line — __call proxy; signature is dynamic by design
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

    // ---- htmx HX-* request header helpers ----------------------------------

    /** Returns true when the request carries `HX-Request: true`. */
    public function isHtmx(): bool
    {
        return ($this->header['hx-request'] ?? '') === 'true';
    }

    /** Returns true when the request was issued via `hx-boost`. */
    public function isBoosted(): bool
    {
        return ($this->header['hx-boosted'] ?? '') === 'true';
    }

    /** Returns true when the request is a history-restoration miss. */
    public function isHistoryRestoreRequest(): bool
    {
        return ($this->header['hx-history-restore-request'] ?? '') === 'true';
    }

    /** Returns the `HX-Target` element id, or null if absent. */
    public function htmxTarget(): ?string
    {
        $v = $this->header['hx-target'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Returns the `HX-Trigger` element id, or null if absent. */
    public function htmxTrigger(): ?string
    {
        $v = $this->header['hx-trigger'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Returns the `HX-Trigger-Name` element name, or null if absent. */
    public function htmxTriggerName(): ?string
    {
        $v = $this->header['hx-trigger-name'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Returns the `HX-Current-URL` browser URL, or null if absent. */
    public function htmxCurrentUrl(): ?string
    {
        $v = $this->header['hx-current-url'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Returns the `HX-Prompt` user response string, or null if absent. */
    public function htmxPrompt(): ?string
    {
        $v = $this->header['hx-prompt'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}