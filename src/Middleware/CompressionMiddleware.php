<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Stream;
use ZealPHP\G;

/**
 * Compression Middleware (gzip / deflate)
 *
 * Compresses response bodies when the client advertises support via
 * Accept-Encoding. Skips streaming responses (SSE, Generator, stream()),
 * responses smaller than the threshold, and optionally requests that already
 * came through a reverse proxy such as Traefik.
 *
 * Reference usage when OpenSwoole http_compression is disabled:
 *   $app->addMiddleware(new \ZealPHP\Middleware\CompressionMiddleware());
 */
class CompressionMiddleware implements MiddlewareInterface
{
    private const PROXY_HEADERS = [
        'Forwarded',
        'Via',
        'X-Forwarded-For',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
        'X-Forwarded-Proto',
        'X-Forwarded-Prefix',
        'X-Forwarded-Server',
        'X-Real-IP',
    ];

    public function __construct(
        private int $minLength  = 1024,  // bytes — skip tiny responses
        private int $level      = 6,     // gzip level 1–9
        private bool $skipProxiedRequests = false
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Never compress streaming responses (body already sent)
        $g = G::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        if ($this->skipProxiedRequests && $this->isProxiedRequest($request)) {
            return $response;
        }

        $accept = strtolower($request->getHeaderLine('Accept-Encoding'));
        $body   = (string) $response->getBody();

        if (strlen($body) < $this->minLength) {
            return $response;
        }

        // Skip if already encoded
        if ($response->hasHeader('Content-Encoding')) {
            return $response;
        }

        // Skip non-compressible content types
        $ct = $response->getHeaderLine('Content-Type');
        if ($this->isUncompressible($ct)) {
            return $response;
        }

        if (str_contains($accept, 'gzip')) {
            $compressed = gzencode($body, $this->level);
            return $response
                ->withHeader('Content-Encoding', 'gzip')
                ->withHeader('Content-Length',   (string)strlen($compressed))
                ->withHeader('Vary',             'Accept-Encoding')
                ->withBody(Stream::streamFor($compressed));
        }

        if (str_contains($accept, 'deflate')) {
            $compressed = gzdeflate($body, $this->level);
            return $response
                ->withHeader('Content-Encoding', 'deflate')
                ->withHeader('Content-Length',   (string)strlen($compressed))
                ->withHeader('Vary',             'Accept-Encoding')
                ->withBody(Stream::streamFor($compressed));
        }

        return $response;
    }

    private function isProxiedRequest(ServerRequestInterface $request): bool
    {
        foreach (self::PROXY_HEADERS as $header) {
            if ($request->getHeaderLine($header) !== '') {
                return true;
            }
        }

        return false;
    }

    private function isUncompressible(string $ct): bool
    {
        foreach (['image/', 'video/', 'audio/', 'application/zip',
                  'application/gzip', 'application/octet-stream'] as $prefix) {
            if (str_starts_with($ct, $prefix)) return true;
        }
        return false;
    }
}
