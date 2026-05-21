<?php
namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CompressionMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class CompressionMiddlewareTest extends TestCase
{
    private const BODY = '<html><body>' . 'proxied content ' . '</body></html>';

    public function testGzipStillAppliesWithoutProxyHeader(): void
    {
        $response = $this->process(['accept-encoding' => 'gzip'], true);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testCompressionIsSkippedForForwardedProxyHeader(): void
    {
        $response = $this->process([
            'accept-encoding'   => 'gzip',
            'x-forwarded-proto' => 'https',
        ], true);

        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame($this->body(), (string)$response->getBody());
    }

    public function testProxySkipIsOptIn(): void
    {
        $response = $this->process([
            'accept-encoding'   => 'gzip',
            'x-forwarded-proto' => 'https',
        ], false);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testDefaultMinLengthSkipsBodyJustBelowThreshold(): void
    {
        // Default minLength is 1024. A 1023-byte body must NOT be compressed.
        // Kills the IncrementInteger/DecrementInteger mutants on the default
        // (1023 -> 1023 would compress this; 1025 -> still skip).
        $response = $this->compress(str_repeat('a', 1023), 'gzip', 'text/html');
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testDefaultMinLengthCompressesAtThreshold(): void
    {
        // A 1024-byte body sits exactly AT the default threshold and the
        // `strlen($body) < $this->minLength` guard is false => compress.
        // Kills LessThan (< -> <=, which would skip 1024) and the default
        // minLength=1025 mutant (which would skip 1024).
        $response = $this->compress(str_repeat('a', 1024), 'gzip', 'text/html');
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testDefaultSkipProxiedIsOff(): void
    {
        // Default skipProxiedRequests is false: a proxied request still gets
        // compressed. Kills the FalseValue mutant (default -> true).
        $response = $this->compress(
            str_repeat('a', 2048),
            'gzip',
            'text/html',
            ['x-forwarded-for' => '203.0.113.7']
        );
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testAcceptEncodingIsCaseInsensitive(): void
    {
        // Kills the UnwrapStrToLower mutant — uppercase GZIP must still match.
        $response = $this->compress(str_repeat('a', 2048), 'GZIP', 'text/html');
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testDeflateBranchEncodesBody(): void
    {
        // Exercises the deflate path. Body decodes back to the original.
        $body = str_repeat('deflate-me ', 200);
        $response = $this->compress($body, 'deflate', 'text/html');
        $this->assertSame('deflate', $response->getHeaderLine('Content-Encoding'));
        $this->assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
        $compressed = (string)$response->getBody();
        $this->assertSame($body, (string)gzinflate($compressed));
        $this->assertSame((string)strlen($compressed), $response->getHeaderLine('Content-Length'));
    }

    public function testGzipBodyRoundTripsAndSetsContentLength(): void
    {
        $body = str_repeat('round-trip ', 200);
        $response = $this->compress($body, 'gzip', 'text/html');
        $compressed = (string)$response->getBody();
        $this->assertSame($body, (string)gzdecode($compressed));
        $this->assertSame((string)strlen($compressed), $response->getHeaderLine('Content-Length'));
        $this->assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    public function testGzipUsesDefaultLevelSix(): void
    {
        // Pin the default level (6). The body is deliberately varied so that
        // gzencode at levels 5, 6 and 7 each produce DIFFERENT byte streams
        // (verified: highly-repetitive bodies compress identically at all
        // levels and would let the level mutant survive). The emitted bytes
        // must match gzencode($body, 6) exactly. Kills the Increment/Decrement
        // mutants on the default level.
        $body = $this->variedBody();
        $this->assertNotSame(gzencode($body, 5), gzencode($body, 6));
        $this->assertNotSame(gzencode($body, 7), gzencode($body, 6));

        $response = $this->compress($body, 'gzip', 'text/html');
        $expected = gzencode($body, 6);
        $this->assertNotFalse($expected);
        $this->assertSame($expected, (string)$response->getBody());
    }

    public function testStreamingResponsesAreNotCompressed(): void
    {
        // Kills the Coalesce mutant at L48 ($g->_streaming ?? false ->
        // false ?? $g->_streaming). When _streaming is true the body has
        // already been sent and must NOT be re-compressed.
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = true;
        try {
            $middleware = new CompressionMiddleware();
            $request = new ServerRequest('/', 'GET', '', ['accept-encoding' => 'gzip']);
            $body = str_repeat('a', 4096);
            $handler = new class($body) implements RequestHandlerInterface {
                public function __construct(private string $body) {}
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response($this->body, 200, '', ['Content-Type' => 'text/html']);
                }
            };
            $response = $middleware->process($request, $handler);
            $this->assertFalse($response->hasHeader('Content-Encoding'));
            $this->assertSame($body, (string)$response->getBody());
        } finally {
            RequestContext::instance()->_streaming = null;
        }
    }

    private function variedBody(): string
    {
        $lorem = 'The quick brown fox jumps over the lazy dog. ';
        $b = '';
        mt_srand(7);
        for ($i = 0; $i < 2000; $i++) {
            $b .= substr($lorem, mt_rand(0, 20));
        }
        return $b;
    }

    public function testUncompressibleContentTypeIsSkipped(): void
    {
        $response = $this->compress(str_repeat('a', 2048), 'gzip', 'image/png');
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    public function testNoAcceptEncodingLeavesBodyUncompressed(): void
    {
        $response = $this->compress(str_repeat('a', 2048), '', 'text/html');
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    // --- B5 / parity fixes ---

    /**
     * Vary: Origin set by a prior middleware must be preserved when
     * CompressionMiddleware merges Accept-Encoding into it.
     * withHeader('Vary','Accept-Encoding') would overwrite — the fix uses
     * mergeVary() which appends instead.
     */
    public function testVaryMergePreservesExistingOrigin(): void
    {
        $body = str_repeat('a', 2048);
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();
        $request    = new ServerRequest('/', 'GET', '', ['accept-encoding' => 'gzip']);

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Simulate CorsMiddleware having already set Vary: Origin.
                return (new Response($this->body, 200, '', ['Content-Type' => 'text/html']))
                    ->withHeader('Vary', 'Origin');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $vary = $response->getHeaderLine('Vary');
        // Both directives must be present, order is not mandated.
        $this->assertStringContainsStringIgnoringCase('Origin', $vary);
        $this->assertStringContainsStringIgnoringCase('Accept-Encoding', $vary);
        // Must not contain duplicates (Accept-Encoding must appear exactly once).
        $count = substr_count(strtolower($vary), 'accept-encoding');
        $this->assertSame(1, $count, 'Accept-Encoding should appear exactly once in Vary');
    }

    /**
     * RFC 7231 §5.3.4: Accept-Encoding: gzip;q=0 is an explicit refusal.
     * The middleware must NOT compress the response in this case.
     */
    public function testQValueZeroSkipsCompression(): void
    {
        $response = $this->compress(str_repeat('a', 2048), 'gzip;q=0', 'text/html');
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertFalse($response->hasHeader('Content-Length') &&
            $response->getHeaderLine('Content-Encoding') === 'gzip');
    }

    /**
     * gzip;q=0.000 is also a refusal (Apache mod_deflate parity: strncmp "q=0.000").
     */
    public function testQValueZeroPointZeroSkipsCompression(): void
    {
        $response = $this->compress(str_repeat('a', 2048), 'gzip;q=0.000', 'text/html');
        $this->assertFalse($response->hasHeader('Content-Encoding'));
    }

    /**
     * gzip;q=0.1 is NOT a refusal — must still compress.
     */
    public function testQValueNonZeroCompresses(): void
    {
        $response = $this->compress(str_repeat('a', 2048), 'gzip;q=0.1', 'text/html');
        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    /**
     * RFC 7232 §2.1: A strong ETag must be weakened when the body undergoes
     * transformation (compression).  The middleware must prepend W/.
     */
    public function testStrongEtagIsWeakenedOnCompress(): void
    {
        $body = str_repeat('a', 2048);
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();
        $request    = new ServerRequest('/', 'GET', '', ['accept-encoding' => 'gzip']);

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response($this->body, 200, '', ['Content-Type' => 'text/html']))
                    ->withHeader('ETag', '"abc123"');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertSame('W/"abc123"', $response->getHeaderLine('ETag'));
    }

    /**
     * A weak ETag (W/"…") must be left unchanged — it is already weak.
     */
    public function testWeakEtagIsNotDoubleWeakened(): void
    {
        $body = str_repeat('a', 2048);
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();
        $request    = new ServerRequest('/', 'GET', '', ['accept-encoding' => 'gzip']);

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response($this->body, 200, '', ['Content-Type' => 'text/html']))
                    ->withHeader('ETag', 'W/"abc123"');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertSame('W/"abc123"', $response->getHeaderLine('ETag'));
    }

    /**
     * Accept-Ranges must be cleared on compressed responses (nginx
     * ngx_http_clear_accept_ranges parity) — a client cannot satisfy a
     * byte-range request against a compressed body.
     */
    public function testAcceptRangesIsClearedOnCompress(): void
    {
        $body = str_repeat('a', 2048);
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();
        $request    = new ServerRequest('/', 'GET', '', ['accept-encoding' => 'gzip']);

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response($this->body, 200, '', ['Content-Type' => 'text/html']))
                    ->withHeader('Accept-Ranges', 'bytes');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        $this->assertFalse($response->hasHeader('Accept-Ranges'));
    }

    /**
     * Accept-Ranges must not be touched when compression is not applied
     * (e.g. the client did not send Accept-Encoding).
     */
    public function testAcceptRangesPreservedWhenNotCompressed(): void
    {
        $body = str_repeat('a', 2048);
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();
        $request    = new ServerRequest('/', 'GET', '', []);

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response($this->body, 200, '', ['Content-Type' => 'text/html']))
                    ->withHeader('Accept-Ranges', 'bytes');
            }
        };

        $response = $middleware->process($request, $handler);

        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    /**
     * Compress with explicit defaults so mutations on the constructor defaults
     * are observable. minLength/level are NOT passed, so they take their
     * declared default values (1024 / 6).
     *
     * @param array<string, string> $extraHeaders
     */
    private function compress(
        string $body,
        string $acceptEncoding,
        string $contentType,
        array $extraHeaders = []
    ): ResponseInterface {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware();

        $headers = $extraHeaders;
        if ($acceptEncoding !== '') {
            $headers['accept-encoding'] = $acceptEncoding;
        }

        $request = new ServerRequest('/', 'GET', '', $headers);

        $handler = new class($body, $contentType) implements RequestHandlerInterface {
            public function __construct(private string $body, private string $ct) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => $this->ct]);
            }
        };

        return $middleware->process($request, $handler);
    }

    private function process(array $headers, bool $skipProxiedRequests): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->_streaming = null;

        $middleware = new CompressionMiddleware(
            minLength: 1,
            skipProxiedRequests: $skipProxiedRequests
        );

        $request = new ServerRequest('/', 'GET', '', $headers);
        $body = $this->body();

        $handler = new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => 'text/html']);
            }
        };

        return $middleware->process($request, $handler);
    }

    private function body(): string
    {
        return str_repeat(self::BODY, 80);
    }
}
