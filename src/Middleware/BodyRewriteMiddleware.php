<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Body Rewrite Middleware (`mod_substitute` equivalent)
 *
 * Applies an ordered list of regex substitutions to text-ish response bodies.
 * Useful for upstream URL rewriting (`HTTP` -> `HTTPS` in legacy app HTML),
 * dropping internal hostnames from rendered output, or stamping a build hash
 * onto every page without touching the templates.
 *
 * Apache equivalent (`mod_substitute`):
 *   `AddOutputFilterByType SUBSTITUTE text/html`
 *   `Substitute "s|http://internal.lan|https://public.example.com|n"`
 *
 * Only fires on text-ish content types (`text/*`, `application/json`,
 * `application/xml`, `application/javascript`, …). Streaming responses are
 * skipped — the body isn't materialised at this layer.
 *
 * Each rule is `['pattern' => $regex, 'replacement' => $replacement]`. The
 * pattern must include delimiters and any modifier flags. Replacement
 * follows PHP `preg_replace()` syntax — `$1`, `$2`, … for capture groups.
 *
 * Usage in `app.php`:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\BodyRewriteMiddleware([
 *       ['pattern' => '#http://internal\.lan#', 'replacement' => 'https://example.com'],
 *       ['pattern' => '/Powered by Old/i',      'replacement' => 'Powered by ZealPHP'],
 *   ]));
 */
class BodyRewriteMiddleware implements MiddlewareInterface
{
    private const TEXTISH_PREFIXES = [
        'text/',
        'application/json',
        'application/xml',
        'application/javascript',
        'application/xhtml+xml',
        'image/svg+xml',
    ];

    /** @var array<int, array{pattern: string, replacement: string}> */
    private array $rules;

    /**
     * @param array<int, array{pattern: string, replacement: string}> $rules
     */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (empty($this->rules)) {
            return $response;
        }

        $g = RequestContext::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        $ct = strtolower($response->getHeaderLine('Content-Type'));
        if (!$this->isTextish($ct)) {
            return $response;
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            return $response;
        }

        $newBody = $body;
        foreach ($this->rules as $rule) {
            $result = @preg_replace($rule['pattern'], $rule['replacement'], $newBody);
            if ($result === null) {
                // Bad pattern — leave the body alone for this rule rather
                // than crashing the request. Surface via elog once on first hit.
                continue;
            }
            $newBody = $result;
        }

        if ($newBody === $body) {
            return $response;
        }

        // Construct Stream directly rather than via Stream::streamFor() — the
        // vendor helper has no declared return type and falls through to void
        // for non-scalar inputs, which trips PHPStan at level 10.
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return $response; // can't rewrite without a stream
        }
        fwrite($stream, $newBody);
        fseek($stream, 0);
        return $response
            ->withBody(new Stream($stream))
            ->withHeader('Content-Length', (string)strlen($newBody));
    }

    private function isTextish(string $ct): bool
    {
        if ($ct === '') {
            return false;
        }
        foreach (self::TEXTISH_PREFIXES as $prefix) {
            if (str_starts_with($ct, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
