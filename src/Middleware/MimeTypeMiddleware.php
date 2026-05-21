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
 * MIME Type Middleware
 *
 * Sets the response Content-Type based on the URL's extension when the
 * handler didn't already set one. Useful for handlers that stream raw bytes
 * for custom file types (`.wasm`, `.glb`, `.usdz`, …) without each handler
 * remembering the right MIME string.
 *
 * Multi-suffix aware (Apache mod_mime `find_ct` parity): the type is resolved
 * by walking every dot-separated suffix left-to-right, so `document.html.gz`
 * resolves its Content-Type from `html` (the last *type-mapped* suffix wins)
 * while `gz` contributes no type. Leading-dot basenames (`.png`) are hidden
 * files with no extension and receive no type. See {@see MimeResolver}.
 *
 * Note: OpenSwoole's static file handler has its own internal MIME map for
 * files served directly off disk via `static_handler_locations`. This
 * middleware covers the case where your PHP handler is generating the body
 * (the static handler isn't in the picture) and you still want extension-
 * based Content-Type negotiation.
 *
 * Apache equivalent:
 *   AddType application/wasm           .wasm
 *   AddType model/gltf-binary          .glb
 *   AddType model/vnd.usdz+zip         .usdz
 *
 * Only fires when the upstream response has no Content-Type — explicit
 * `$response->header('Content-Type', ...)` calls in the handler always win.
 *
 * Usage in app.php:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\MimeTypeMiddleware([
 *       'wasm' => 'application/wasm',
 *       'glb'  => 'model/gltf-binary',
 *       'usdz' => 'model/vnd.usdz+zip',
 *   ]));
 */
class MimeTypeMiddleware implements MiddlewareInterface
{
    private MimeResolver $resolver;

    /** @param array<string, string|int> $map ext => mime-type */
    public function __construct(array $map = [])
    {
        $this->resolver = new MimeResolver($map);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Content-Type')) {
            return $response;
        }

        $mime = $this->resolver->resolve($request->getUri()->getPath())['type'];
        if ($mime === null) {
            return $response;
        }

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Content-Type', $mime);
        }

        return $response->withHeader('Content-Type', $mime);
    }
}
