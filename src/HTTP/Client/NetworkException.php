<?php
namespace ZealPHP\HTTP\Client;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * PSR-18 `NetworkExceptionInterface` implementation.
 *
 * Thrown (or returned as the `$error` field of `HTTPResponse`) when an
 * outbound HTTP request fails at the transport layer — DNS resolution
 * failure, TCP connect timeout, TLS handshake error, etc. — before any
 * HTTP response status is received. Carries the originating `RequestInterface`
 * so callers can inspect or retry the failed request.
 */
class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    /**
     * @param RequestInterface $request  The request that triggered the network failure.
     * @param string           $message  Human-readable error description.
     * @param int              $code     Optional error code (defaults to `0`).
     * @param \Throwable|null  $previous Underlying cause, if any.
     */
    public function __construct(
        private RequestInterface $request,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Returns the request that triggered this network exception. */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
