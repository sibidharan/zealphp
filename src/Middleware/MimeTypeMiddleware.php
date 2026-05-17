<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * MIME Type Middleware
 *
 * Sets the response Content-Type based on the URL's extension when the
 * handler didn't already set one. Useful for handlers that stream raw bytes
 * for custom file types (`.wasm`, `.glb`, `.usdz`, …) without each handler
 * remembering the right MIME string.
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
    /** @var array<string, string> */
    private array $map;

    /** @param array<string, string> $map ext => mime-type */
    public function __construct(array $map = [])
    {
        $out = [];
        foreach ($map as $ext => $mime) {
            $out[strtolower(ltrim((string)$ext, '.'))] = (string)$mime;
        }
        $this->map = $out;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Content-Type')) {
            return $response;
        }

        $ext = strtolower(pathinfo($request->getUri()->getPath(), PATHINFO_EXTENSION));
        if ($ext === '' || !isset($this->map[$ext])) {
            return $response;
        }

        $mime = $this->map[$ext];

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Content-Type', $mime);
        }

        return $response->withHeader('Content-Type', $mime);
    }
}
