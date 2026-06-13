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
            $hydrated = \OpenSwoole\Core\Psr\ServerRequest::from($this->native);
            assert($hydrated instanceof ServerRequestInterface);
            $this->hydrated = $hydrated;
        }
        return $this->hydrated;
    }

    // -- Fast path: zero-allocation methods --

    public function getMethod(): string
    {
        if ($this->hydrated) return $this->hydrated->getMethod();
        $server = $this->native->server ?? [];
        assert(is_array($server));
        $method = $server['request_method'] ?? 'GET';
        return is_string($method) ? $method : 'GET';
    }

    public function getHeaderLine(string $name): string
    {
        if ($this->hydrated) return $this->hydrated->getHeaderLine($name);
        $lower = strtolower($name);
        $header = $this->native->header ?? [];
        assert(is_array($header));
        $val = $header[$lower] ?? '';
        return is_string($val) ? $val : '';
    }

    /** @return array<string> */
    public function getHeader(string $name): array
    {
        if ($this->hydrated) return $this->hydrated->getHeader($name);
        $lower = strtolower($name);
        $header = $this->native->header ?? [];
        assert(is_array($header));
        $val = $header[$lower] ?? null;
        return (is_string($val) && $val !== '') ? [$val] : [];
    }

    public function hasHeader(string $name): bool
    {
        if ($this->hydrated) return $this->hydrated->hasHeader($name);
        $header = $this->native->header ?? [];
        assert(is_array($header));
        return isset($header[strtolower($name)]);
    }

    /** @return array<string, array<string>> */
    public function getHeaders(): array
    {
        if ($this->hydrated) {
            /** @var array<string, array<string>> */
            return $this->hydrated->getHeaders();
        }
        /** @var array<string, array<string>> $headers */
        $headers = [];
        $rawHeaders = $this->native->header ?? [];
        assert(is_array($rawHeaders));
        foreach ($rawHeaders as $k => $v) {
            if (!is_string($k)) continue;
            $strVal = is_string($v) ? $v : (is_scalar($v) ? (string)$v : '');
            $headers[$k] = [$strVal];
        }
        return $headers;
    }

    /** @return array<string, mixed> */
    public function getServerParams(): array
    {
        if ($this->hydrated) {
            /** @var array<string, mixed> */
            return $this->hydrated->getServerParams();
        }
        $server = $this->native->server ?? [];
        assert(is_array($server));
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($server as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function getQueryParams(): array
    {
        if ($this->hydrated) {
            /** @var array<string, mixed> */
            return $this->hydrated->getQueryParams();
        }
        $get = $this->native->get ?? [];
        assert(is_array($get));
        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($get as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    /** @return array<string, string> */
    public function getCookieParams(): array
    {
        if ($this->hydrated) {
            /** @var array<string, string> */
            return $this->hydrated->getCookieParams();
        }
        $cookie = $this->native->cookie ?? [];
        assert(is_array($cookie));
        /** @var array<string, string> $result */
        $result = [];
        foreach ($cookie as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $result[$k] = $v;
            }
        }
        return $result;
    }

    public function getRequestTarget(): string
    {
        if ($this->hydrated) return $this->hydrated->getRequestTarget();
        $server = $this->native->server ?? [];
        assert(is_array($server));
        $target = $server['request_uri'] ?? '/';
        return is_string($target) ? $target : '/';
    }

    public function getProtocolVersion(): string
    {
        if ($this->hydrated) return $this->hydrated->getProtocolVersion();
        $server = $this->native->server ?? [];
        assert(is_array($server));
        $protocol = $server['server_protocol'] ?? 'HTTP/1.1';
        if (!is_string($protocol)) {
            $protocol = 'HTTP/1.1';
        }
        return str_replace('HTTP/', '', $protocol);
    }

    // -- Hydration-required methods --

    public function getBody(): StreamInterface
    {
        return $this->hydrate()->getBody();
    }

    /**
     * @infection-ignore-all The #435 catch is integration/defensive code: the
     *   os#395 TypeError only arises from a malformed request target processed
     *   by the REAL OpenSwoole Uri parser, which the per-mutant Unit harness
     *   (hand-built native request) only approximates. Its remaining surviving
     *   mutants are provably-equivalent (e.g. the (string) cast on a
     *   parse_url(...PHP_URL_PATH) result that can't be false after the
     *   leading-slash collapse). Behaviour is pinned by
     *   testGetUriRecoversFromTripleSlashTarget; excluded from the MSI baseline
     *   per infection.json5's integration-only policy.
     */
    public function getUri(): UriInterface
    {
        try {
            return $this->hydrate()->getUri();
        } catch (\TypeError $e) {
            // #435 — defence in depth against OpenSwoole os#395: a request
            // target that makes parse_url() return false (e.g. a `///echo`
            // triple-slash) crashes Core\Psr\Uri::parse() with a TypeError
            // BEFORE any handler runs, turning a malformed target into an
            // unauthenticated 500 for every getUri()-calling middleware
            // (ScopedMiddleware / BlockPhpExt / Referer / Redirect). Rebuild a
            // valid Uri from the raw target with leading-slash runs collapsed so
            // the access-control middleware sees a clean path instead of 500-ing.
            $server = $this->native->server ?? [];
            $target = is_array($server) && is_string($server['request_uri'] ?? null)
                ? $server['request_uri'] : '/';
            // Collapse a run of leading slashes to one ("///echo" → "/echo") so
            // parse_url() no longer reads the path as an authority and returns
            // false. Strip a query for the Uri's path portion (the framework
            // reads the query from query_string elsewhere).
            $path = (string) (parse_url('http://placeholder' . '/' . ltrim($target, '/'), PHP_URL_PATH) ?? '/');
            return new \OpenSwoole\Core\Psr\Uri($path);
        }
    }

    /** @return array<string, \Psr\Http\Message\UploadedFileInterface> */
    public function getUploadedFiles(): array
    {
        /** @var array<string, \Psr\Http\Message\UploadedFileInterface> */
        return $this->hydrate()->getUploadedFiles();
    }

    /**
     * @return array<string, mixed>|object|null
     */
    public function getParsedBody()
    {
        /** @var array<string, mixed>|object|null */
        return $this->hydrate()->getParsedBody();
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        /** @var array<string, mixed> */
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

    /** @param array<string, string> $cookies */
    public function withCookieParams(array $cookies): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withCookieParams($cookies);
        return $new;
    }

    /** @param array<string, mixed> $query */
    public function withQueryParams(array $query): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withQueryParams($query);
        return $new;
    }

    /** @param array<string, \Psr\Http\Message\UploadedFileInterface> $uploadedFiles */
    public function withUploadedFiles(array $uploadedFiles): static
    {
        $new = clone $this;
        $new->hydrated = $this->hydrate()->withUploadedFiles($uploadedFiles);
        return $new;
    }

    /** @param array<string, mixed>|object|null $data */
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
