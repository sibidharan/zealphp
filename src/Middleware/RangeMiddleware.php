<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\RequestContext;

/**
 * HTTP Range Request Middleware (RFC 7233)
 *
 * Handles `Range: bytes=...` headers, returning `206 Partial Content` for
 * satisfiable single and multi-range requests and `416 Range Not Satisfiable`
 * for out-of-bounds ranges.
 *
 * Also adds `Accept-Ranges: bytes` to all eligible `200` responses.
 *
 * Only applies to `GET` responses with a non-empty body.
 * Streaming responses and non-`200` upstream responses are passed through.
 *
 * Security: enforces a maximum number of range specs per request to prevent
 * the CVE-2011-3192 class of multi-range DoS amplification attacks.
 * Default is `200`, matching Apache's `AP_DEFAULT_MAX_RANGES`.  When the spec
 * count exceeds the cap the `Range` header is ignored and a full `200` response
 * is returned — the same behaviour as Apache (not a `416`).
 *
 * RFC 7233 §2.1 conformance: if any single spec in a multi-range header is
 * syntactically invalid, the ENTIRE `Range` header is ignored (full `200`).
 *
 * RFC 7233 §3.2 — `If-Range` HTTP-date: when the `If-Range` value does not begin
 * with a double-quote it is treated as an HTTP-date and compared against the
 * upstream `Last-Modified` header using Apache's one-minute clock-skew rule.
 *
 * Usage in `app.php`:
 *   `$app->addMiddleware(new \ZealPHP\Middleware\RangeMiddleware());`
 *
 * To raise or lower the per-request range cap:
 *   `$mw = new \ZealPHP\Middleware\RangeMiddleware();`
 *   `$mw->maxRanges = 50;`
 *   `$app->addMiddleware($mw);`
 */
class RangeMiddleware implements MiddlewareInterface
{
    /**
     * Maximum number of range specs accepted per request.
     * Matches Apache's `AP_DEFAULT_MAX_RANGES` (`byterange_filter.c:59`).
     * When exceeded the `Range` header is ignored and a full `200` is returned.
     */
    public int $maxRanges = 200;

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

        $g = RequestContext::instance();
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

        // If-Range: honour the range only when the representation has not changed.
        // RFC 7233 §3.2: value starting with '"' is an entity-tag; anything else
        // is an HTTP-date.  Apache ap_condition_if_range() (http_protocol.c:524-558).
        $ifRange = $request->getHeaderLine('If-Range');
        if ($ifRange !== '') {
            if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/"')) {
                // ETag comparison — exact string match required (strong or weak tag).
                $etag = $response->getHeaderLine('ETag');
                if ($etag !== '' && $ifRange !== $etag) {
                    // ETag mismatch: ignore range, serve full body.
                    return $response->withHeader('Accept-Ranges', 'bytes');
                }
                // No ETag on response or tags match: fall through and honour range.
            } else {
                // HTTP-date comparison against Last-Modified.
                // Apache clock-skew rule: if request time < mtime + 60 s, treat as
                // a weak (untrustworthy) match and ignore the range.
                $lastModifiedHeader = $response->getHeaderLine('Last-Modified');
                if ($lastModifiedHeader === '') {
                    // No Last-Modified available — cannot validate; serve full body.
                    return $response->withHeader('Accept-Ranges', 'bytes');
                }

                $mtime = strtotime($lastModifiedHeader);
                $rtime = strtotime($ifRange);

                if ($mtime === false || $rtime === false || $mtime === -1 || $rtime === -1) {
                    // Unparseable date — cannot validate; serve full body safely.
                    return $response->withHeader('Accept-Ranges', 'bytes');
                }

                if ($rtime !== $mtime) {
                    // Dates differ: resource has changed, ignore range.
                    return $response->withHeader('Accept-Ranges', 'bytes');
                }

                // Dates match; apply Apache's one-minute clock-skew rule.
                // Use the response Date header when present, otherwise wall clock.
                $dateHeader = $response->getHeaderLine('Date');
                $reqtime = ($dateHeader !== '') ? strtotime($dateHeader) : time();
                if ($reqtime === false || $reqtime === -1) {
                    $reqtime = time();
                }

                if ($reqtime < $mtime + 60) {
                    // Clock skew too small — weak match not allowed with Range.
                    return $response->withHeader('Accept-Ranges', 'bytes');
                }
                // Strong match: honour range.
            }
        }

        // C2: enforce multi-range DoS cap before allocating any range storage.
        // Count comma-separated specs first; if over the cap serve full 200.
        // Matches Apache byterange_filter.c:466 (AP_DEFAULT_MAX_RANGES = 200).
        $rawSpecs = explode(',', $m[1]);
        if (count($rawSpecs) > $this->maxRanges) {
            return $response->withHeader('Accept-Ranges', 'bytes');
        }

        $ranges = [];

        // L5: RFC 7233 §2.1 — any syntactically invalid spec invalidates the
        // ENTIRE Range header (Apache byterange_filter.c:154-159).  We use a
        // $valid flag so a single bad spec causes a clean full-200 return after
        // the loop, rather than a 416.
        $valid = true;

        foreach ($rawSpecs as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                // Empty token after trim (e.g. trailing comma, or bytes= with only
                // whitespace).  Skip silently — no range added; if no valid spec is
                // found the empty-$ranges check below will emit 416.
                continue;
            }

            if (str_starts_with($spec, '-')) {
                // Suffix range: bytes=-N → last N bytes
                $tail = substr($spec, 1);
                if ($tail === '' || !ctype_digit($tail)) {
                    // Empty or non-numeric suffix — syntactically invalid.
                    $valid = false;
                    break;
                }
                $suffixLen = (int) $tail;
                if ($suffixLen <= 0 || $suffixLen > $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $ranges[] = [$total - $suffixLen, $total - 1];
            } elseif (str_ends_with($spec, '-')) {
                // Open-end range: bytes=N-
                $startStr = substr($spec, 0, -1);
                if ($startStr === '' || !ctype_digit($startStr)) {
                    $valid = false;
                    break;
                }
                $start = (int) $startStr;
                if ($start >= $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $ranges[] = [$start, $total - 1];
            } elseif (str_contains($spec, '-')) {
                // Bounded range: bytes=N-M
                [$startStr, $endStr] = explode('-', $spec, 2);
                if ($startStr === '' || !ctype_digit($startStr) ||
                    $endStr === ''   || !ctype_digit($endStr)) {
                    $valid = false;
                    break;
                }
                $start = (int) $startStr;
                $end   = (int) $endStr;
                if ($start > $end || $start >= $total) {
                    return $this->unsatisfiable($total, $g);
                }
                $end = min($end, $total - 1);
                $ranges[] = [$start, $end];
            } else {
                // No dash at all — syntactically invalid.
                $valid = false;
                break;
            }
        }

        // L5: whole-header invalidation — serve full 200, not 416.
        if (!$valid) {
            return $response->withHeader('Accept-Ranges', 'bytes');
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

    /**
     * @param array{0: int, 1: int} $range
     */
    private function singleRange(array $range, string $body, int $total, RequestContext $g): ResponseInterface
    {
        [$start, $end] = $range;
        $slice  = substr($body, $start, $end - $start + 1);
        $crHeader = "bytes {$start}-{$end}/{$total}";

        $this->setHeader($g, 'Content-Range', $crHeader);
        $this->setHeader($g, 'Content-Length', (string) strlen($slice));
        $g->status = 206;

        $resp = (new Response($slice, 206))->withHeader('Content-Range', $crHeader);
        assert($resp instanceof ResponseInterface);
        return $resp->withHeader('Accept-Ranges', 'bytes');
    }

    /**
     * @param array<int, array{0: int, 1: int}> $ranges
     */
    private function multiRange(array $ranges, string $body, int $total, string $contentType, RequestContext $g): ResponseInterface
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

        $resp = (new Response($multiBody, 206))->withHeader('Content-Type', $ct);
        assert($resp instanceof ResponseInterface);
        return $resp->withHeader('Accept-Ranges', 'bytes');
    }

    private function unsatisfiable(int $total, RequestContext $g): ResponseInterface
    {
        $crHeader = "bytes */{$total}";
        $this->setHeader($g, 'Content-Range', $crHeader);
        $g->status = 416;

        $resp = (new Response('', 416))
            ->withHeader('Content-Range', $crHeader);
        assert($resp instanceof ResponseInterface);
        return $resp;
    }

    /**
     * Queue a response header via the ZealPHP response wrapper (production path).
     * Guards against `null` in unit-test contexts where `zealphp_response` is not set.
     */
    private function setHeader(RequestContext $g, string $key, string $value): void
    {
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header($key, $value);
        }
    }
}
