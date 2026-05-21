<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Charset Middleware
 *
 * Appends `; charset=<charset>` to the response Content-Type for text-ish
 * content types (text/html, text/plain, text/css, text/javascript,
 * application/json, application/xml, application/javascript, image/svg+xml,
 * …) when the Content-Type doesn't already declare a charset.
 *
 * Apache equivalent:
 *   AddDefaultCharset utf-8
 *   AddCharset utf-8 .html .css .js .json
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\CharsetMiddleware());          // utf-8
 *   $app->addMiddleware(new \ZealPHP\Middleware\CharsetMiddleware('iso-8859-1'));
 */
class CharsetMiddleware implements MiddlewareInterface
{
    /**
     * Content-Type prefixes that get a charset appended. Anything else
     * (image/png, application/octet-stream, application/pdf, …) is left
     * untouched — sending `charset=utf-8` on a binary type is a mild
     * protocol bug some clients log.
     */
    private const TEXTISH_PREFIXES = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/ld+json',
        'application/xhtml+xml',
        'image/svg+xml',
    ];

    public function __construct(private string $charset = 'utf-8')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $ct = $response->getHeaderLine('Content-Type');
        $ctWasEmpty = ($ct === '');
        if ($ctWasEmpty) {
            // mod_php default_mimetype / Apache DefaultType: give untyped
            // responses a default Content-Type (text/html) before adding charset.
            $ct = \ZealPHP\App::$default_mimetype;
            if ($ct === '') {
                return $response;
            }
        }
        if (stripos($ct, 'charset=') !== false) {
            return $response;
        }

        // Append charset for text-ish types; for a (rare) non-textish default we
        // still set the bare Content-Type so the response isn't left untyped.
        $newCt = $this->isTextish($ct) ? $ct . '; charset=' . $this->charset : $ct;
        if (!$ctWasEmpty && $newCt === $ct) {
            return $response; // non-textish response that already had a Content-Type
        }

        // Mirror the change onto the underlying OpenSwoole response when
        // available (production path) — the PSR-7 layer alone won't reach
        // streaming or fallback paths that read from $g->zealphp_response.
        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Content-Type', $newCt);
        }

        return $response->withHeader('Content-Type', $newCt);
    }

    private function isTextish(string $ct): bool
    {
        $ct = strtolower($ct);
        foreach (self::TEXTISH_PREFIXES as $prefix) {
            if (str_starts_with($ct, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
