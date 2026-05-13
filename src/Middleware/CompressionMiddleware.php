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
 * Accept-Encoding. Skips streaming responses (SSE, Generator, stream())
 * and responses smaller than the threshold.
 *
 * Usage in app.php (add LAST so it wraps innermost):
 *   $app->addMiddleware(new \ZealPHP\Middleware\CompressionMiddleware());
 */
class CompressionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $minLength  = 1024,  // bytes — skip tiny responses
        private int $level      = 6      // gzip level 1–9
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Never compress streaming responses (body already sent)
        $g = G::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        $accept = $request->getHeaderLine('Accept-Encoding');
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

    private function isUncompressible(string $ct): bool
    {
        foreach (['image/', 'video/', 'audio/', 'application/zip',
                  'application/gzip', 'application/octet-stream'] as $prefix) {
            if (str_starts_with($ct, $prefix)) return true;
        }
        return false;
    }
}
