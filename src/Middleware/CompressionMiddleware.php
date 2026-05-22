<?php

declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Stream;
use ZealPHP\RequestContext;

/**
 * Compression Middleware (`gzip` / `deflate`)
 *
 * Compresses response bodies when the client advertises support via
 * `Accept-Encoding`. Skips streaming responses (SSE, `Generator`, `stream()`),
 * responses smaller than the threshold, and optionally requests that already
 * came through a reverse proxy such as Traefik.
 *
 * RFC 7231 §5.3.4 compliance:
 *   - `Accept-Encoding` `q=0` is treated as explicit refusal; compression is skipped.
 *
 * RFC 7232 §2.1 compliance:
 *   - Strong `ETag`s on responses that are then compressed are weakened (`W/` prefix),
 *     because the compressed body is not byte-identical to the original.
 *
 * nginx / `mod_deflate` parity:
 *   - `Vary: Accept-Encoding` is *merged* with any existing `Vary` values so that
 *     a prior `Vary: Origin` from `CorsMiddleware` is preserved (`apr_table_mergen` /
 *     `ngx_http_header_filter` parity).
 *   - `Accept-Ranges` is cleared on compressed responses because `Range` requests
 *     cannot be satisfied on a compressed body (`ngx_http_clear_accept_ranges`
 *     parity).
 *
 * Reference usage when OpenSwoole `http_compression` is disabled:
 *   `$app->addMiddleware(new \ZealPHP\Middleware\CompressionMiddleware());`
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
        $g = RequestContext::instance();
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

        // RFC 7231 §5.3.4: q=0 is an explicit refusal — do not compress.
        if ($this->isAcceptedEncoding($accept, 'gzip')) {
            $compressed = gzencode($body, $this->level);
            if ($compressed === false) { return $response; }
            $stream = Stream::streamFor($compressed);
            assert($stream instanceof \Psr\Http\Message\StreamInterface);
            return $this->applyCompressionHeaders($response, 'gzip', $compressed)
                ->withBody($stream);
        }

        if ($this->isAcceptedEncoding($accept, 'deflate')) {
            $compressed = gzdeflate($body, $this->level);
            if ($compressed === false) { return $response; }
            $stream = Stream::streamFor($compressed);
            assert($stream instanceof \Psr\Http\Message\StreamInterface);
            return $this->applyCompressionHeaders($response, 'deflate', $compressed)
                ->withBody($stream);
        }

        return $response;
    }

    /**
     * Apply the standard post-compression response headers:
     *   - `Content-Encoding`
     *   - `Content-Length` (actual compressed byte count)
     *   - `Vary`: merge `Accept-Encoding` into any existing values (dedup, case-insensitive)
     *   - `ETag`: weaken any strong `ETag` (RFC 7232 §2.1)
     *   - `Accept-Ranges`: clear (compressed body cannot serve byte-range requests)
     */
    private function applyCompressionHeaders(
        ResponseInterface $response,
        string $encoding,
        string $compressed
    ): ResponseInterface {
        // Merge `Accept-Encoding` into `Vary`, preserving existing directives.
        $response = $this->mergeVary($response, 'Accept-Encoding');

        // Weaken any strong `ETag` (RFC 7232 §2.1: compressed body ≠ original body).
        $response = $this->weakenEtag($response);

        // Clear `Accept-Ranges` — a client must not attempt byte-range requests on
        // a compressed body (nginx `ngx_http_clear_accept_ranges` parity).
        if ($response->hasHeader('Accept-Ranges')) {
            $response = $response->withoutHeader('Accept-Ranges');
        }

        return $response
            ->withHeader('Content-Encoding', $encoding)
            ->withHeader('Content-Length',   (string)strlen($compressed));
    }

    /**
     * Check whether a given encoding token is accepted by the client, honouring
     * RFC 7231 §5.3.4 q-value semantics: `q=0` (or `q=0.000…`) means explicit refusal.
     *
     * Parses the comma-separated `Accept-Encoding` field value looking for the
     * token (case-insensitive, already lowercased by the caller).  If the token
     * is found and its q-value is absent or > 0, it is accepted.  A q-value of
     * exactly zero (any number of trailing zeros, e.g. `"0"`, `"0.0"`, `"0.000"`)
     * means the encoding is explicitly refused.
     */
    private function isAcceptedEncoding(string $accept, string $encoding): bool
    {
        // Quick reject: token not present at all.
        if (!str_contains($accept, $encoding)) {
            return false;
        }

        // Walk each comma-separated token.
        foreach (explode(',', $accept) as $part) {
            $part = trim($part);
            // Separate token from parameters (e.g. "gzip;q=0.5").
            $segments = explode(';', $part);
            $token = trim($segments[0]);

            if ($token !== $encoding) {
                continue;
            }

            // Token matched — now check q-value.
            for ($i = 1; $i < count($segments); $i++) {
                $param = trim($segments[$i]);
                if (!str_starts_with($param, 'q=')) {
                    continue;
                }
                $qStr = substr($param, 2); // everything after "q="
                $q    = (float) $qStr;
                if ($q === 0.0) {
                    return false; // explicit refusal per RFC 7231 §5.3.4
                }
            }

            return true; // found, q > 0 (or q absent, which defaults to 1)
        }

        return false;
    }

    /**
     * Merge `$directive` into the response's `Vary` header, deduplicating
     * case-insensitively.  Uses `withHeader()` on the merged result so the
     * full canonical list is a single header line (RFC 7230 §3.2.2).
     *
     * Apache parity: `apr_table_mergen(r->headers_out, "Vary", "Accept-Encoding")`
     * nginx parity:  `ngx_http_header_filter` `Vary` conditional add
     */
    private function mergeVary(ResponseInterface $response, string $directive): ResponseInterface
    {
        $existing = $response->getHeaderLine('Vary');

        if ($existing === '') {
            return $response->withHeader('Vary', $directive);
        }

        // Collect existing values, normalised for dedup comparison.
        $parts = array_map('trim', explode(',', $existing));
        $lower = array_map('strtolower', $parts);

        if (in_array(strtolower($directive), $lower, true)) {
            // Already present — nothing to do.
            return $response;
        }

        $parts[] = $directive;
        return $response->withHeader('Vary', implode(', ', $parts));
    }

    /**
     * If the response carries a strong `ETag`, weaken it by prepending `W/`.
     * A compressed body is a transformed representation — it is NOT
     * byte-identical to the original, so the strong validator must not survive.
     *
     * nginx parity:   `ngx_http_weak_etag()` (`ngx_http_core_module.c:1753`)
     * RFC reference:  RFC 7232 §2.1
     */
    private function weakenEtag(ResponseInterface $response): ResponseInterface
    {
        $etag = $response->getHeaderLine('ETag');

        if ($etag === '') {
            return $response;
        }

        // Already weak — nothing to do.
        if (str_starts_with($etag, 'W/')) {
            return $response;
        }

        // Strong ETag: must be a quoted string per RFC 7232 §2.3.
        return $response->withHeader('ETag', 'W/' . $etag);
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
