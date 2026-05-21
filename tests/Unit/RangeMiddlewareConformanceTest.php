<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * Conformance tests for RangeMiddleware fixes:
 *
 * C2 — Multi-range DoS cap (CVE-2011-3192 class): >200 specs → full 200, not 416.
 * M3 — If-Range HTTP-date: compare against Last-Modified with 60 s clock-skew rule.
 * L5 — Any syntactically invalid spec invalidates the entire Range header (full 200).
 */
class RangeMiddlewareConformanceTest extends TestCase
{
    // 54-byte body identical to RangeMiddlewareTest::BODY.
    private const BODY = 'Hello, World! This is test content for range requests.';

    // A fixed "old" Last-Modified date: 2024-01-01 00:00:00 UTC
    private const LAST_MODIFIED = 'Mon, 01 Jan 2024 00:00:00 GMT';
    // Unix timestamp of LAST_MODIFIED (1704067200).
    private const MTIME = 1704067200;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build and run the middleware against a crafted request/response pair.
     *
     * @param array<string, string> $requestHeaders   Extra request headers (Range, If-Range, …)
     * @param array<string, string> $responseHeaders  Extra response headers (ETag, Last-Modified, Date, …)
     */
    private function dispatch(
        array $requestHeaders,
        array $responseHeaders = [],
        ?string $body = null,
        string $method = 'GET',
        int $status = 200,
        ?RangeMiddleware $mw = null
    ): ResponseInterface {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $middleware = $mw ?? new RangeMiddleware();

        $request = new ServerRequest('/', $method, '', $requestHeaders);
        $responseBody = $body ?? self::BODY;

        $handler = new class($responseBody, $status, $responseHeaders) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(
                private string $body,
                private int $status,
                private array $headers
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, $this->status, '', $this->headers);
            }
        };

        return $middleware->process($request, $handler);
    }

    // -----------------------------------------------------------------------
    // C2 — Multi-range DoS cap
    // -----------------------------------------------------------------------

    /**
     * 200 ranges (exactly at the default cap) must be honoured — not capped.
     */
    public function testExactlyAtCapIsHonoured(): void
    {
        // Build a header with exactly 200 single-byte specs: bytes=0-0,1-1,...,199-199
        $specs = [];
        for ($i = 0; $i < 200; $i++) {
            $specs[] = "{$i}-{$i}";
        }
        $rangeHeader = 'bytes=' . implode(',', $specs);

        $response = $this->dispatch(['range' => $rangeHeader]);

        // All 200 specs are valid and within the 54-byte body only for indices 0-53;
        // indices 54-199 are out of range, so the first unsatisfiable spec triggers 416.
        // The important assertion is that the cap itself does NOT fire (status != 200 due to cap).
        // We verify the cap is not the cause: status will be 416 (first OOB spec), not 200 from cap.
        $this->assertNotSame(
            200,
            $response->getStatusCode(),
            'Exactly 200 specs should not trigger the DoS cap (cap is >200)'
        );
    }

    /**
     * 201 ranges (one over the default cap of 200) must be ignored — full 200 returned.
     * Apache byterange_filter.c:466: num_ranges > max_ranges → pass through.
     */
    public function testOverCapReturnsFullBody(): void
    {
        $specs = [];
        for ($i = 0; $i < 201; $i++) {
            $specs[] = "0-0";
        }
        $rangeHeader = 'bytes=' . implode(',', $specs);

        $response = $this->dispatch(['range' => $rangeHeader]);

        $this->assertSame(200, $response->getStatusCode(), '201 specs must trigger DoS cap → full 200');
        $this->assertSame(self::BODY, (string) $response->getBody(), 'Full body returned when cap exceeded');
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    /**
     * Large number of specs (10 000) is capped and returns full 200, not OOM.
     */
    public function testLargeSpecCountIsCappped(): void
    {
        $specs = array_fill(0, 10000, '0-0');
        $rangeHeader = 'bytes=' . implode(',', $specs);

        $response = $this->dispatch(['range' => $rangeHeader]);

        $this->assertSame(200, $response->getStatusCode(), '10 000 specs must be capped');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * Custom maxRanges property is respected.
     */
    public function testCustomMaxRangesIsRespected(): void
    {
        $mw = new RangeMiddleware();
        $mw->maxRanges = 3;

        // 3 specs exactly at the custom cap — should be honoured.
        $response = $this->dispatch(['range' => 'bytes=0-0,1-1,2-2'], [], null, 'GET', 200, $mw);
        $this->assertSame(206, $response->getStatusCode(), '3 specs at cap=3 must be honoured');

        // 4 specs exceeds the custom cap — should be ignored (full 200).
        $response = $this->dispatch(['range' => 'bytes=0-0,1-1,2-2,3-3'], [], null, 'GET', 200, $mw);
        $this->assertSame(200, $response->getStatusCode(), '4 specs at cap=3 must be capped');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * 200 specs with all valid in-range values returns 206 (multi-range).
     * Confirms cap check fires at >200 not >=200.
     */
    public function testTwoHundredInRangeSpecsHonoured(): void
    {
        // Only use indices within the 54-byte body.
        $specs = [];
        for ($i = 0; $i < 54; $i++) {
            $specs[] = "{$i}-{$i}";
        }
        // Pad to exactly 200 by repeating specs (duplicates are fine for the cap test).
        while (count($specs) < 200) {
            $specs[] = '0-0';
        }
        $rangeHeader = 'bytes=' . implode(',', $specs);

        $response = $this->dispatch(['range' => $rangeHeader]);

        // Should be 206, not 200 (cap not triggered).
        $this->assertSame(206, $response->getStatusCode(), '200 specs must not trigger cap (cap is >200)');
    }

    // -----------------------------------------------------------------------
    // M3 — If-Range HTTP-date
    // -----------------------------------------------------------------------

    /**
     * If-Range is an HTTP-date matching Last-Modified, request time is well past
     * mtime+60 — range must be honoured (strong match).
     */
    public function testIfRangeDateMatchHonoursRange(): void
    {
        // Date header is 2 hours after LAST_MODIFIED — well outside the 60 s skew window.
        $date = gmdate('D, d M Y H:i:s \G\M\T', self::MTIME + 7200);

        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            ['Last-Modified' => self::LAST_MODIFIED, 'Date' => $date]
        );

        $this->assertSame(206, $response->getStatusCode(), 'Matching date + old enough → 206');
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    /**
     * If-Range date matches Last-Modified but request time is within 60 s of mtime
     * (clock-skew window) — range must be ignored, full 200 returned.
     * Apache http_protocol.c:543: if (reqtime < mtime + 60) → NOMATCH.
     */
    public function testIfRangeDateClockSkewIgnoresRange(): void
    {
        // Date header is only 30 s after LAST_MODIFIED — inside the 60 s skew window.
        $date = gmdate('D, d M Y H:i:s \G\M\T', self::MTIME + 30);

        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            ['Last-Modified' => self::LAST_MODIFIED, 'Date' => $date]
        );

        $this->assertSame(200, $response->getStatusCode(), 'Clock skew < 60 s → full 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * If-Range date is exactly 60 s after mtime — still inside the skew window
     * (reqtime < mtime + 60 is false only when reqtime >= mtime + 60).
     * At reqtime == mtime + 60 the condition is false → strong match → 206.
     */
    public function testIfRangeDateExactly60sAfterMtimeHonoursRange(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', self::MTIME + 60);

        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            ['Last-Modified' => self::LAST_MODIFIED, 'Date' => $date]
        );

        // reqtime (mtime+60) < mtime+60 is FALSE → strong match → 206.
        $this->assertSame(206, $response->getStatusCode(), 'reqtime == mtime+60 → strong match → 206');
    }

    /**
     * If-Range date is 59 s after mtime — inside the skew window → 200.
     */
    public function testIfRangeDateOneSecondBeforeThresholdIgnoresRange(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', self::MTIME + 59);

        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            ['Last-Modified' => self::LAST_MODIFIED, 'Date' => $date]
        );

        // reqtime (mtime+59) < mtime+60 is TRUE → weak match rejected → 200.
        $this->assertSame(200, $response->getStatusCode(), 'reqtime < mtime+60 → weak match → 200');
    }

    /**
     * If-Range date does NOT match Last-Modified — range must be ignored (full 200).
     */
    public function testIfRangeDateMismatchIgnoresRange(): void
    {
        $staleDate = 'Mon, 01 Jan 2023 00:00:00 GMT'; // one year earlier
        $requestDate = gmdate('D, d M Y H:i:s \G\M\T', self::MTIME + 7200);

        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => $staleDate],
            ['Last-Modified' => self::LAST_MODIFIED, 'Date' => $requestDate]
        );

        $this->assertSame(200, $response->getStatusCode(), 'Date mismatch → full 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * If-Range is a date but response has no Last-Modified — range ignored (safe fallback).
     */
    public function testIfRangeDateWithNoLastModifiedIgnoresRange(): void
    {
        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            [] // no Last-Modified header
        );

        $this->assertSame(200, $response->getStatusCode(), 'No Last-Modified → safe fallback → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * If-Range is a date but the date string is unparseable — range ignored safely.
     */
    public function testIfRangeDateUnparseableIgnoresRange(): void
    {
        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => 'not-a-date'],
            ['Last-Modified' => self::LAST_MODIFIED]
        );

        $this->assertSame(200, $response->getStatusCode(), 'Unparseable If-Range date → safe 200');
    }

    /**
     * If-Range value is a weak ETag (W/"...") — handled via ETag path, not date path.
     * Matching weak ETag → range honoured.
     */
    public function testIfRangeWeakETagMatchHonoursRange(): void
    {
        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => 'W/"v2"'],
            ['ETag' => 'W/"v2"']
        );

        $this->assertSame(206, $response->getStatusCode(), 'Matching weak ETag → 206');
        $this->assertSame('Hello', (string) $response->getBody());
    }

    /**
     * If-Range value is a strong ETag ("...") — handled via ETag path.
     * Mismatching strong ETag → range ignored.
     */
    public function testIfRangeStrongETagMismatchIgnoresRange(): void
    {
        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => '"v1"'],
            ['ETag' => '"v2"']
        );

        $this->assertSame(200, $response->getStatusCode(), 'Mismatching strong ETag → 200');
    }

    /**
     * If-Range is a date but no Date response header — falls back to wall clock.
     * As long as wall clock is > mtime + 60 (guaranteed for a 2024 mtime), range is honoured.
     */
    public function testIfRangeDateNoDateHeaderFallsBackToWallClock(): void
    {
        // MTIME is 2024-01-01, wall clock is 2026 — well beyond mtime+60.
        $response = $this->dispatch(
            ['range' => 'bytes=0-4', 'if-range' => self::LAST_MODIFIED],
            ['Last-Modified' => self::LAST_MODIFIED]
            // No Date header — falls back to time()
        );

        $this->assertSame(206, $response->getStatusCode(), 'No Date header, old mtime → wall clock fallback → 206');
        $this->assertSame('Hello', (string) $response->getBody());
    }

    // -----------------------------------------------------------------------
    // L5 — Invalid spec invalidates entire Range header
    // -----------------------------------------------------------------------

    /**
     * A single alphabetic spec ("abc") mixed with a valid spec invalidates the whole header.
     * Apache byterange_filter.c:154-159: return 0 (pass-through) on any invalid spec.
     */
    public function testInvalidAlphaSpecInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=abc,0-4']);

        $this->assertSame(200, $response->getStatusCode(), 'Invalid spec "abc" → whole header ignored → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    /**
     * Invalid spec appearing AFTER a valid spec still invalidates the whole header.
     */
    public function testInvalidSpecAfterValidInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-4,xyz']);

        $this->assertSame(200, $response->getStatusCode(), 'Valid then invalid → whole header ignored → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * A spec with a non-numeric start (e.g. "a-5") invalidates the whole header.
     */
    public function testNonNumericStartInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=a-5']);

        $this->assertSame(200, $response->getStatusCode(), 'Non-numeric start "a-5" → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * A spec with a non-numeric end (e.g. "0-z") invalidates the whole header.
     */
    public function testNonNumericEndInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-z']);

        $this->assertSame(200, $response->getStatusCode(), 'Non-numeric end "0-z" → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * A spec with no dash at all (e.g. "5") invalidates the whole header.
     */
    public function testNoDashSpecInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=5']);

        $this->assertSame(200, $response->getStatusCode(), 'No dash "5" → 200');
    }

    /**
     * A spec with multiple dashes (e.g. "0-4-9") — the bounded branch will parse
     * "0" and "4-9" (non-digit due to dash) → non-numeric end → 200.
     */
    public function testMultipleDashesInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-4-9']);

        $this->assertSame(200, $response->getStatusCode(), 'Multiple dashes → 200');
    }

    /**
     * Mixed: two valid specs and one invalid one — result is 200, not 206.
     * This demonstrates the "whole-header" invalidation vs. skipping behaviour.
     */
    public function testThreeSpecsWithOneInvalidAllIgnored(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-4,garbage,10-14']);

        $this->assertSame(200, $response->getStatusCode(), 'Three specs, one invalid → all ignored → 200');
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    /**
     * A suffix spec with non-numeric tail (e.g. "-abc") invalidates the header.
     */
    public function testInvalidSuffixSpecInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=-abc']);

        $this->assertSame(200, $response->getStatusCode(), 'Invalid suffix "-abc" → 200');
    }

    /**
     * An open-end spec with non-numeric start (e.g. "abc-") invalidates the header.
     */
    public function testInvalidOpenEndSpecInvalidatesWholeHeader(): void
    {
        $response = $this->dispatch(['range' => 'bytes=abc-']);

        $this->assertSame(200, $response->getStatusCode(), 'Invalid open-end "abc-" → 200');
    }

    /**
     * Regression: a lone valid spec still works correctly after the L5 refactor.
     */
    public function testValidSpecStillWorks(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-4']);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    /**
     * Regression: two valid specs still produce multi-range 206 after the L5 refactor.
     */
    public function testTwoValidSpecsStillProduceMultiRange(): void
    {
        $response = $this->dispatch(['range' => 'bytes=0-4,14-17']);

        $this->assertSame(206, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Content-Range: bytes 0-4/54', $body);
        $this->assertStringContainsString('Content-Range: bytes 14-17/54', $body);
    }
}
