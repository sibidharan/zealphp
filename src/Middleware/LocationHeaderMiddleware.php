<?php

declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rewrites the port in an outbound `Location` header to a configured value.
 *
 * Extracted from src/App.php (Phase 0 structural relocation). FQCN changed to
 * `ZealPHP\Middleware\LocationHeaderMiddleware` (was `ZealPHP\LocationHeaderMiddleware`);
 * it had zero live references in the codebase.
 */
class LocationHeaderMiddleware implements MiddlewareInterface
{
    private int $correctPort;

    public function __construct(int $correctPort)
    {
        $this->correctPort = $correctPort;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Location')) {
            $location = $response->getHeaderLine('Location');
            $parsedUrl = parse_url($location);

            if (isset($parsedUrl['host']) && isset($parsedUrl['port']) && $parsedUrl['port'] != $this->correctPort) {
                $parsedUrl['port'] = $this->correctPort;
                $newLocation = $this->buildUrl($parsedUrl);
                $response = $response->withHeader('Location', $newLocation);
            }
        }

        return $response;
    }

    /**
     * @param array<string, string|int> $parsedUrl
     */
    private function buildUrl(array $parsedUrl): string
    {
        $scheme   = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        // #403 — preserve userinfo (RFC 3986 §3.2.1 `[ userinfo "@" ]`). Only
        // the port is being rewritten; dropping `user:pass@` would emit a
        // semantically different URL than the handler set.
        $user     = isset($parsedUrl['user']) ? (string) $parsedUrl['user'] : '';
        $pass     = isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass'] : '';
        $userinfo = ($user !== '' || $pass !== '') ? $user . $pass . '@' : '';
        $host     = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $port     = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $path     = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $query    = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        return "$scheme$userinfo$host$port$path$query$fragment";
    }
}
