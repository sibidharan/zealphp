<?php
namespace ZealPHP\HTTP\Factory;

use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use OpenSwoole\Core\Psr\Uri;

/**
 * PSR-17 `UriFactoryInterface` implementation backed by `OpenSwoole\Core\Psr\Uri`.
 *
 * Registered with the PSR-17 container so ZealPHP's middleware stack and
 * internal helpers can create `UriInterface` instances without depending on a
 * third-party HTTP factory library.
 */
class UriFactory implements UriFactoryInterface
{
    /**
     * Create a new `UriInterface` instance from a URI string.
     *
     * An empty string produces a URI with all components unset (the
     * `OpenSwoole\Core\Psr\Uri` default). Delegates parsing to
     * `OpenSwoole\Core\Psr\Uri`.
     */
    public function createUri(string $uri = ''): UriInterface
    {
        return new Uri($uri);
    }
}
