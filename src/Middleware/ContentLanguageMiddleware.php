<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\HTTP\MimeResolver;
use ZealPHP\RequestContext;

/**
 * Content-Language Middleware — Apache `mod_mime` `AddLanguage` parity.
 *
 * Sets the response `Content-Language` header from the request URL's file
 * suffixes. Apache `find_ct` (`mod_mime.c:938–946`) accumulates a language list
 * across suffixes — `page.en.html` with `AddLanguage en .en` yields
 * `Content-Language: en`. Multiple language suffixes accumulate in order and
 * are emitted comma-joined (RFC 9110 §8.5 allows a list). The multi-suffix
 * walk is delegated to {@see MimeResolver}.
 *
 * This middleware is ADDITIVE and OPT-IN: with the default empty map it never
 * touches the response, and it only sets `Content-Language` when the response
 * doesn't already declare one (an explicit handler value wins).
 *
 * Apache equivalent:
 *   `AddLanguage en .en`
 *   `AddLanguage fr .fr`
 *   `AddLanguage de .de`
 *
 * Usage in `app.php`:
 *
 * ```php
 * $app->addMiddleware(new \ZealPHP\Middleware\ContentLanguageMiddleware([
 *     'en' => 'en',
 *     'fr' => 'fr',
 *     'de' => 'de',
 * ]));
 * ```
 */
class ContentLanguageMiddleware implements MiddlewareInterface
{
    private MimeResolver $resolver;

    /** @param array<string, string|int> $map ext => content-language (e.g. fr => fr) */
    public function __construct(array $map = [])
    {
        // Language map lives in the resolver's language slot; type/encoding stay empty.
        $this->resolver = new MimeResolver([], [], $map);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Content-Language')) {
            return $response;
        }

        $languages = $this->resolver->resolve($request->getUri()->getPath())['languages'];
        if ($languages === []) {
            return $response;
        }

        $value = implode(', ', $languages);

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Content-Language', $value);
        }

        return $response->withHeader('Content-Language', $value);
    }
}
