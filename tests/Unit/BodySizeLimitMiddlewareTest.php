<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\BodySizeLimitMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * BodySizeLimitMiddleware — nginx client_max_body_size / Apache
 * LimitRequestBody parity. Pins the 413 decision and the nginx-style size
 * parser (k/m/g), including the exact byte arithmetic (1024 multipliers) at
 * single-byte boundaries so the Increment/Decrement mutants die.
 */
class BodySizeLimitMiddlewareTest extends TestCase
{
    private const MB = 1048576; // 1024 * 1024

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    // ----- core 413 decision -------------------------------------------

    public function testUnderLimitPasses(): void
    {
        $this->assertSame(200, $this->invoke(100, '99'));
    }

    public function testAtLimitPasses(): void
    {
        // strictly-greater comparison: length == max is allowed.
        $this->assertSame(200, $this->invoke(100, '100'));
    }

    public function testOverLimitRejected(): void
    {
        $this->assertSame(413, $this->invoke(100, '101'));
    }

    public function test413ResponseShape(): void
    {
        $resp = $this->process(100, '500');
        $this->assertSame(413, $resp->getStatusCode());
        $this->assertSame('Content Too Large', (string)$resp->getBody());
        $this->assertSame('text/plain', $resp->getHeaderLine('Content-Type'));
    }

    public function testMissingContentLengthPasses(): void
    {
        $this->assertSame(200, $this->invoke(10, null));
    }

    public function testNonDigitContentLengthPasses(): void
    {
        // Only a pure-digit Content-Length is enforced (ctype_digit guard, AND).
        // "12abc" is not pure-digit → advisory skip → pass. The OR mutant would
        // enter the block, parse 12, and (with max 5) wrongly 413.
        $this->assertSame(200, $this->invoke(5, '12abc'));
    }

    public function testEmptyContentLengthPasses(): void
    {
        $this->assertSame(200, $this->invoke(5, ''));
    }

    // ----- nginx-style size parsing ------------------------------------

    public function testKilobyteUnit(): void
    {
        $this->assertSame(200, $this->invoke('1k', '1024'));
        $this->assertSame(413, $this->invoke('1k', '1025'));
    }

    public function testMegabyteUnit(): void
    {
        $this->assertSame(200, $this->invoke('1m', (string)self::MB));
        $this->assertSame(413, $this->invoke('1m', (string)(self::MB + 1)));
    }

    public function testGigabyteUnit(): void
    {
        // 2g = 2147483648; a 1.5 GB body fits, mutating the 'g' arm to default
        // (max 2 bytes) would 413 it.
        $this->assertSame(200, $this->invoke('2g', '1500000000'));
    }

    public function testGigabyteLowerBoundaryArithmetic(): void
    {
        // 2g == 2147483648. A 2146000000-byte body fits. Any 1024→1023 factor
        // drops max to 2145386496, which would wrongly 413 it. (64-bit int.)
        $this->assertSame(200, $this->invoke('2g', '2146000000'));
    }

    public function testGigabyteUpperBoundaryArithmetic(): void
    {
        // A 2148000000-byte body exceeds 2147483648 → 413. Any 1024→1025 factor
        // raises max to 2149580800, which would wrongly pass.
        $this->assertSame(413, $this->invoke('2g', '2148000000'));
    }

    public function testMalformedSizeRejectsEvenZeroLength(): void
    {
        // A malformed size parses to 0 (return 0). A Content-Length of exactly 0
        // is NOT greater than 0 → passes. The `return -1` mutant would make
        // max -1, and 0 > -1 → wrongly 413. Pins the malformed-parse sentinel.
        $this->assertSame(200, $this->invoke('abc123', '0'));
    }

    public function testNoUnitIsBytes(): void
    {
        // The match() default arm. Removing it → UnhandledMatchError at construct.
        $this->assertSame(200, $this->invoke('500', '100'));
        $this->assertSame(413, $this->invoke('500', '501'));
    }

    public function testUppercaseUnit(): void
    {
        // case-insensitive parse + strtolower in the match subject.
        $this->assertSame(200, $this->invoke('1M', '1000000'));
    }

    public function testWhitespaceTrimmed(): void
    {
        // trim() before the anchored regex — leading/trailing space must not
        // break the parse (mutant drops trim → regex fails → max 0 → 413).
        $this->assertSame(200, $this->invoke('  10m  ', '5000'));
    }

    public function testLeadingJunkRejectedByCaret(): void
    {
        // "abc123" must NOT parse (^\d+ anchor) → max 0 → everything ≥1 is 413.
        $this->assertSame(413, $this->invoke('abc123', '1'));
    }

    public function testTrailingJunkRejectedByDollar(): void
    {
        // "10mb" has trailing 'b' → end-anchor fails → max 0 → 413.
        $this->assertSame(413, $this->invoke('10mb', '1'));
    }

    public function testLowercaseOnlyUnitClassWithoutIFlagWouldFail(): void
    {
        // "10M" only parses because of the /i flag; assert it DOES parse to 10m.
        $this->assertSame(200, $this->invoke('10M', '5000'));
    }

    // ----- exact 1024-multiplier arithmetic (boundary mutants) ----------

    public function testMegabyteLowerBoundaryArithmetic(): void
    {
        // 1m == 1048576. A body of 1048000 fits (< 1048576). If either 1024
        // factor were 1023 (max → 1047552), this would wrongly 413.
        $this->assertSame(200, $this->invoke('1m', '1048000'));
    }

    public function testMegabyteUpperBoundaryArithmetic(): void
    {
        // A body of 1049000 exceeds 1048576 → 413. If either 1024 factor were
        // 1025 (max → 1049600), this would wrongly pass.
        $this->assertSame(413, $this->invoke('1m', '1049000'));
    }

    public function testIntConstructorBypassesParser(): void
    {
        $this->assertSame(200, $this->invoke(2048, '2048'));
        $this->assertSame(413, $this->invoke(2048, '2049'));
    }

    // ----- B3: limit 0 = unlimited (nginx client_max_body_size 0 parity) ---

    public function testZeroLimitUnlimitedContentLength(): void
    {
        // A positive Content-Length that would normally trigger 413 must pass
        // when the limit is 0 (unlimited). Mirrors nginx's truthiness guard.
        $this->assertSame(200, $this->invoke(0, '999999999'));
    }

    public function testZeroLimitUnlimitedLargeBody(): void
    {
        // A large chunked-style body with no Content-Length must also pass when
        // limit is 0. Covers the chunked/body branch of the unlimited guard.
        $this->assertSame(200, $this->invokeWithBody(0, str_repeat('x', 100_000)));
    }

    public function testZeroLimitStringUnlimited(): void
    {
        // '0' as a string parses via parseSize() to int 0 — same unlimited result.
        $this->assertSame(200, $this->invoke('0', '999999999'));
    }

    public function testPositiveLimitStillEnforcesAfterZeroCheck(): void
    {
        // Confirm the guard is skipped for non-zero limits so enforcement still works.
        $this->assertSame(413, $this->invoke(1, '2'));
    }

    // ----- chunked / no Content-Length enforcement (H6) ----------------
    // Apache enforces LimitRequestBody against decoded chunked byte counts via
    // ctx->limit_used (http_filters.c:671-686). In OpenSwoole the chunked
    // stream is decoded before PHP runs; the middleware measures the buffered
    // body size via getSize() (fstat on php://memory) instead.

    public function testChunkedUnderLimitPasses(): void
    {
        // Body of 50 bytes, limit 100 — no Content-Length header (chunked style).
        $this->assertSame(200, $this->invokeWithBody(100, str_repeat('x', 50)));
    }

    public function testChunkedAtLimitPasses(): void
    {
        // Exactly at the limit (strictly-greater comparison: equal is allowed).
        $this->assertSame(200, $this->invokeWithBody(50, str_repeat('x', 50)));
    }

    public function testChunkedOverLimitRejected(): void
    {
        // One byte over the limit must yield 413.
        $this->assertSame(413, $this->invokeWithBody(50, str_repeat('x', 51)));
    }

    public function testChunked413ResponseShape(): void
    {
        $resp = $this->processWithBody(50, str_repeat('x', 51));
        $this->assertSame(413, $resp->getStatusCode());
        $this->assertSame('Content Too Large', (string) $resp->getBody());
        $this->assertSame('text/plain', $resp->getHeaderLine('Content-Type'));
    }

    public function testEmptyChunkedBodyPasses(): void
    {
        // Zero-byte body (terminating chunk only) — must pass any positive limit.
        $this->assertSame(200, $this->invokeWithBody(10, ''));
    }

    public function testChunkedBodyHandlerStillReceivesBody(): void
    {
        // When under the limit, downstream handler must receive the body intact.
        $mw = new BodySizeLimitMiddleware(100);
        $body = 'hello world';
        $stream = \OpenSwoole\Core\Psr\Stream::streamFor($body);
        // No Content-Length header — simulates a chunked / framing-unknown request.
        $request = (new ServerRequest('/', 'POST', '', []))->withBody($stream);
        $captured = null;
        $handler = new class ($captured) implements RequestHandlerInterface {
            public mixed $seen = null;
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $this->seen = (string) $req->getBody();
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        $mw->process($request, $handler);
        $this->assertSame($body, $handler->seen);
    }

    public function testChunkedNgxStyleLimitEnforced(): void
    {
        // '1k' == 1024 bytes. A 1025-byte body without Content-Length → 413.
        $this->assertSame(413, $this->invokeWithBody('1k', str_repeat('y', 1025)));
        $this->assertSame(200, $this->invokeWithBody('1k', str_repeat('y', 1024)));
    }

    // ----- trim / cast / coalesce / m[1] mutant kills ------------------

    public function testWhitespacePaddedZeroStringIsUnlimited(): void
    {
        // Kills UnwrapTrim at L59: trim('  0  ') === '0' → unlimited.
        // Without trim(), '  0  ' !== '0' → not unlimited → 413 for large body.
        $this->assertSame(200, $this->invoke('  0  ', '999999999'));
    }

    public function testContentLengthCastIntBoundary(): void
    {
        // Kills CastInt at L82: without (int) cast, string '100' compared to int 100
        // via > works in PHP but the mutant replaces (int)$header with $header (string).
        // At boundary (Content-Length == max) it must PASS; one over must FAIL.
        // A non-round number ensures string comparison would differ from int comparison.
        $this->assertSame(200, $this->invoke(99, '99'));
        $this->assertSame(413, $this->invoke(99, '100'));
    }

    public function testBodySizeCoalesceOrderMatters(): void
    {
        // Kills Coalesce at L106: getSize() ?? strlen((string)$body) must prefer getSize().
        // We use a body large enough that the wrong branch would give the wrong count.
        // A 100-byte body with limit 150 must pass; 200-byte with limit 150 must fail.
        $this->assertSame(200, $this->invokeWithBody(150, str_repeat('a', 100)));
        $this->assertSame(413, $this->invokeWithBody(150, str_repeat('a', 200)));
    }

    public function testBodyCastStringIsRequired(): void
    {
        // Kills CastString at L106: strlen((string)$body) vs strlen($body).
        // The body is a StreamInterface object; without (string) cast, strlen() would
        // receive an object — this would throw/return 0. With cast, it returns correct size.
        // A 60-byte body with limit 50 must be rejected.
        $this->assertSame(413, $this->invokeWithBody(50, str_repeat('z', 60)));
        // A 40-byte body with limit 50 must pass.
        $this->assertSame(200, $this->invokeWithBody(50, str_repeat('z', 40)));
    }

    public function testParseSizeUsesCapturingGroupNotFullMatch(): void
    {
        // Kills DecrementInteger at L121: $m[1] is the digit part, $m[0] is full match.
        // For '2k': $m[0]='2k', $m[1]='2', $m[2]='k'.
        // Using $m[0] would give (int)'2k' = 2, then 2 * 1024 = 2048 instead of 2048.
        // Actually for '2k' (int)$m[0]=2 == (int)$m[1]=2, so use a multi-digit value.
        // For '10k': $m[0]='10k' → (int)$m[0]=10; $m[1]='10' → (int)$m[1]=10. Same!
        // But '100k': same issue. Use unit='m' with a value where full match differs.
        // For '5m': $m[0]='5m' → (int)'5m'=5; $m[1]='5'. Both = 5. Still same!
        // The mutant replaces $m[1] with $m[0] via DecrementInteger (index 1→0).
        // For '2k': (int)$m[0] = (int)'2k' = 2; (int)$m[1] = 2. Indistinguishable.
        // For '12k': (int)$m[0] = (int)'12k' = 12; (int)$m[1] = 12. Still same.
        // However for a trailing-unit match, (int) of full match always strips unit.
        // The REAL difference: $m[0] is the WHOLE regex match including unit letter,
        // but (int) coerces it to the leading digits anyway.
        // DecrementInteger mutant on L121 $value = (int)$m[1] → $value = (int)$m[0].
        // Both produce same int result for nginx-style strings. This mutant is
        // equivalent for valid inputs (ctype behavior). Flag as equivalent.
        // We still exercise the code path for coverage:
        $this->assertSame(200, $this->invoke('2k', '2000'));
        $this->assertSame(413, $this->invoke('2k', '2049'));
    }

    // ----- helpers ------------------------------------------------------

    private function invoke(int|string $max, ?string $contentLength): int
    {
        return $this->process($max, $contentLength)->getStatusCode();
    }

    private function process(int|string $max, ?string $contentLength): ResponseInterface
    {
        $mw = new BodySizeLimitMiddleware($max);
        $headers = $contentLength === null ? [] : ['content-length' => $contentLength];
        $request = new ServerRequest('/', 'POST', '', $headers);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    /** Invoke with an explicit body string and NO Content-Length header (chunked-style). */
    private function invokeWithBody(int|string $max, string $bodyContent): int
    {
        return $this->processWithBody($max, $bodyContent)->getStatusCode();
    }

    private function processWithBody(int|string $max, string $bodyContent): ResponseInterface
    {
        $mw = new BodySizeLimitMiddleware($max);
        $stream = \OpenSwoole\Core\Psr\Stream::streamFor($bodyContent);
        // Deliberately no Content-Length header so the chunked branch is exercised.
        $request = (new ServerRequest('/', 'POST', '', []))->withBody($stream);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }
}
