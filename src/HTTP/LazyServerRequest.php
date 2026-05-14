<?php
namespace ZealPHP\HTTP;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Lazy PSR-7 ServerRequest — defers expensive hydration until accessed.
 *
 * The full OpenSwoole\Core\Psr\ServerRequest::from() creates Uri, Stream,
 * UploadedFile objects and copies all headers/params on every request.
 * Most middleware only calls getMethod() and getHeaderLine() — this wrapper
 * returns those from the native OpenSwoole request with zero allocation.
 *
 * Only when a method requiring the full PSR-7 object is called (getBody(),
 * getUri(), withHeader(), etc.) does it hydrate the underlying object.
 */
class LazyServerRequest implements ServerRequestInterface
{
    private \OpenSwoole\Http\Request $native;
    private ?ServerRequestInterface $hydrated = null;

    public function __construct(\OpenSwoole\Http\Request $native)
    {
        $this->native = $native;
    }

    private function hydrate(): ServerRequestInterface
    {
        if ($this->hydrated === null) {
            $this->hydrated = \OpenSwoole\Core\Psr\ServerRequest::from($this->native);
        }
        return $this->hydrated;
    }

    // -- Fast path: zero-allocation methods --

    public function getMethod(): string
    {
        if ($this->hydrated) return $this->hydrated->getMethod();
        return $this->native->server['request_method'] ?? 'GET';
    }

    public function getHeaderLine(string $name): string
    {
        if ($this->hydrated) return $this->hydrated->getHeaderLine($name);
        $lower = strtolower($name);
        return $this->native->header[$lower] ?? '';
    }

    public function getHeader(string $name): array
    {
        if ($this->hydrated) return $this->hydrated->getHeader($name);
        $lower = strtolower($name);
        $val = $this->native->header[$lower] ?? null;
        return $val !== null ? [$val] : [];
    }

    public function hasHeader(string $name): bool
    {
        if ($this->hydrated) return $this->hydrated->hasHeader($name);
        return isset($this->native->header[strtolower($name)]);
    }

    public function getHeaders(): array
    {
        if ($this->hydrated) return $this->hydrated->getHeaders();
        $headers = [];
        foreach ($this->native->header ?? [] as $k => $v) {
            $headers[$k] = [$v];
        }
        return $headers;
    }

    public function getServerParams(): array
    {
        if ($this->hydrated) return $this->hydrated->getServerParams();
        return $this->native->server ?? [];
    }

    public function getQueryParams(): array
    {
        if ($this->hydrated) return $this->hydrated->getQueryParams();
        return $this->native->get ?? [];
    }

    public function getCookieParams(): array
    {
        if ($this->hydrated) return $this->hydrated->getCookieParams();
        return $this->native->cookie ?? [];
    }

    public function getRequestTarget(): string
    {
        if ($this->hydrated) return $this->hydrated->getRequestTarget();
        return $this->native->server['request_uri'] ?? '/';
    }

    public function getProtocolVersion(): string
    {
        if ($this->hydrated) return $this->hydrated->getProtocolVersion();
        $protocol = $this->native->server['server_protocol'] ?? 'HTTP/1.1';
        return str_replace('HTTP/', '', $protocol);
    }

    // -- Hydration-required methods --

    public function getBody(): StreamInterface
    {
        return $this->hydrate()->getBody();
    }

    public function getUri(): UriInterface
    {
        return $this->hydrate()->getUri();
    }

    public function getUploadedFiles(): array
    {
        return $this->hydrate()->getUploadedFiles();
    }

    public function getParsedBody()
    {
        return $this->hydrate()->getParsedBody();
    }

    public function getAttributes(): array
    {
        return $this->hydrate()->getAttributes();
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->hydrate()->getAttribute($name, $default);
    }

    // -- with* methods: hydrate then delegate --

    public function withMethod(string $method): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withMethod($method);
        return $new;
    }

    public function withHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withHeader($name, $value);
        return $new;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withAddedHeader($name, $value);
        return $new;
    }

    public function withoutHeader(string $name): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withoutHeader($name);
        return $new;
    }

    public function withBody(StreamInterface $body): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withBody($body);
        return $new;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withUri($uri, $preserveHost);
        return $new;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withRequestTarget($requestTarget);
        return $new;
    }

    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withProtocolVersion($version);
        return $new;
    }

    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withCookieParams($cookies);
        return $new;
    }

    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withQueryParams($query);
        return $new;
    }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withUploadedFiles($uploadedFiles);
        return $new;
    }

    public function withParsedBody($data): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withParsedBody($data);
        return $new;
    }

    public function withAttribute(string $name, $value): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withAttribute($name, $value);
        return $new;
    }

    public function withoutAttribute(string $name): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withoutAttribute($name);
        return $new;
    }
}
