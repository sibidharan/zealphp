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

    /**
     * The target element's id, or null if absent or the target has no id.
     *
     * htmx 2 sends the bare id (`main`); htmx 4 sends `tag#id` (`div#main`), or
     * just the tag when the element has no id. Both come back as `main`.
     */
    public function htmxTarget(): ?string
    {
        return $this->elementId($this->header['hx-target'] ?? null);
    }

    /**
     * The triggering element's id, or null if absent or it has no id: htmx 2's
     * `HX-Trigger`, else the id part of htmx 4's `HX-Source`.
     */
    public function htmxTrigger(): ?string
    {
        $v = $this->header['hx-trigger'] ?? null;
        if (is_string($v) && $v !== '' && $this->htmxVersion() !== 4) {
            return $v;
        }
        return $this->elementId($this->header['hx-source'] ?? null);
    }

    /** The raw `HX-Source` header (htmx 4): the triggering element as `tag#id`, or null. */
    public function htmxSource(): ?string
    {
        $v = $this->header['hx-source'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * The `HX-Request-Type` header (htmx 4): `'full'` when the response
     * replaces the body (or uses hx-select), `'partial'` otherwise; null when
     * absent.
     */
    public function htmxRequestType(): ?string
    {
        $v = $this->header['hx-request-type'] ?? null;
        return $v === 'full' || $v === 'partial' ? $v : null;
    }

    /**
     * Which htmx sent the request: 4 when it carries `HX-Request-Type` (only
     * htmx 4 sends it), 2 for any other htmx request, null for a non-htmx one.
     */
    public function htmxVersion(): ?int
    {
        if (!$this->isHtmx()) {
            return null;
        }
        return isset($this->header['hx-request-type']) ? 4 : 2;
    }

    /**
     * The id in an htmx element identifier: `main` (htmx 2) or `div#main`
     * (htmx 4) → `main`; a bare tag from htmx 4 (`div`, element without id) → null.
     */
    private function elementId(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $hash = strpos($v, '#');
        if ($hash !== false) {
            $id = rawurldecode(substr($v, $hash + 1));
            return $id !== '' ? $id : null;
        }
        // No '#': htmx 2 sent a bare id; htmx 4 sent a tag name (no id).
        return $this->htmxVersion() === 4 ? null : $v;
    }

    /** Returns the `HX-Trigger-Name` element name (htmx 2 only), or null if absent. */
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