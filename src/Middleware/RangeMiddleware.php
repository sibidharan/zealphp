<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\G;

/**
 * HTTP Range Request Middleware (RFC 7233)
 *
 * Handles Range: bytes=... headers, returning 206 Partial Content for
 * satisfiable single and multi-range requests and 416 Range Not Satisfiable
 * for out-of-bounds ranges.
 *
 * Also adds Accept-Ranges: bytes to all eligible 200 responses.
 *
 * Only applies to GET responses with a non-empty body.
 * Streaming responses and non-200 upstream responses are passed through.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\RangeMiddleware());
 */
class RangeMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $method = $request->getMethod();

        if ($method !== 'GET' && $method !== 'HEAD') {
            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $g = G::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        $total = strlen($body);

        // Always advertise range support on eligible responses
        $this->setHeader($g, 'Accept-Ranges', 'bytes');

        $rangeHeader = $request->getHeaderLine('Range');
        if ($rangeHeader === '') {
            return $response->withHeader('Accept-Ranges', 'bytes');
        }

        if (!preg_match('/^bytes=(.+)$/i', $rangeHeader, $m)) {
            return $response->withHeader('Accept-Ranges', 'bytes');
        }

        // If-Range: only honour the range if ETag matches
        $ifRange = $request->getHeaderLine('If-Range');
        if ($ifRange !== '') {
            $etag = $response->getHeaderLine('ETag');
            if ($etag !== '' && $ifRange !== $etag) {
                return $response->withHeader('Accept-Ranges', 'bytes');
            }
        }

        $specs = explode(',', $m[1]);
        $ranges = [];

        foreach ($specs as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }

            if (str_starts_with($spec, '-')) {
                // Suffix range: bytes=-N → last N bytes
                $suffixLen = (int) substr($spec, 1);
                if ($suffixLen <= 0 || $suffixLen > $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $ranges[] = [$total - $suffixLen, $total - 1];
            } elseif (str_ends_with($spec, '-')) {
                // Open-end range: bytes=N-
                $start = (int) substr($spec, 0, -1);
                if ($start >= $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $ranges[] = [$start, $total - 1];
            } elseif (str_contains($spec, '-')) {
                // Bounded range: bytes=N-M
                [$startStr, $endStr] = explode('-', $spec, 2);
                $start = (int) $startStr;
                $end   = (int) $endStr;
                if ($start > $end || $start >= $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $end = min($end, $total - 1);
                $ranges[] = [$start, $end];
            }
        }

        if (empty($ranges)) {
            return $this->unsatisfiable($total, $g);
        }

        $contentType = $response->getHeaderLine('Content-Type') ?: 'application/octet-stream';

        if (count($ranges) === 1) {
            return $this->singleRange($ranges[0], $body, $total, $g);
        }

        return $this->multiRange($ranges, $body, $total, $contentType, $g);
    }

    private function singleRange(array $range, string $body, int $total, G $g): ResponseInterface
    {
        [$start, $end] = $range;
        $slice  = substr($body, $start, $end - $start + 1);
        $crHeader = "bytes {$start}-{$end}/{$total}";

        $this->setHeader($g, 'Content-Range', $crHeader);
        $this->setHeader($g, 'Content-Length', (string) strlen($slice));
        $g->status = 206;

        return (new Response($slice, 206))
            ->withHeader('Content-Range', $crHeader)
            ->withHeader('Accept-Ranges', 'bytes');
    }

    private function multiRange(array $ranges, string $body, int $total, string $contentType, G $g): ResponseInterface
    {
        $boundary = 'zealphp_' . bin2hex(random_bytes(16));
        $parts    = [];

        foreach ($ranges as [$start, $end]) {
            $slice    = substr($body, $start, $end - $start + 1);
            $parts[]  = "--{$boundary}\r\n"
                . "Content-Type: {$contentType}\r\n"
                . "Content-Range: bytes {$start}-{$end}/{$total}\r\n"
                . "\r\n"
                . $slice;
        }

        $multiBody = implode("\r\n", $parts) . "\r\n--{$boundary}--\r\n";
        $ct        = "multipart/byteranges; boundary={$boundary}";

        $this->setHeader($g, 'Content-Type', $ct);
        $this->setHeader($g, 'Content-Length', (string) strlen($multiBody));
        $g->status = 206;

        return (new Response($multiBody, 206))
            ->withHeader('Content-Type', $ct)
            ->withHeader('Accept-Ranges', 'bytes');
    }

    private function unsatisfiable(int $total, G $g): ResponseInterface
    {
        $crHeader = "bytes */{$total}";
        $this->setHeader($g, 'Content-Range', $crHeader);
        $g->status = 416;

        return (new Response('', 416))
            ->withHeader('Content-Range', $crHeader);
    }

    /**
     * Queue a response header via the ZealPHP response wrapper (production path).
     * Guards against null in unit-test contexts where zealphp_response is not set.
     */
    private function setHeader(G $g, string $key, string $value): void
    {
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header($key, $value);
        }
    }
}
