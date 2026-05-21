<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\HTTP\ConditionalRequest;
use ZealPHP\Tests\TestCase;

/**
 * Exhaustive unit coverage for the pure RFC 9110 conditional-request evaluator.
 *
 * Mirrors Apache `ap_meets_conditions` (http_protocol.c) outcomes: each header
 * in isolation, the precedence interactions, the '*' wildcard, weak vs strong
 * ETag comparison, the future-date guard, the within-60s weak window, the
 * Range-request strong-only rule, and method sensitivity (GET/HEAD -> 304,
 * other methods -> 412).
 */
class ConditionalRequestTest extends TestCase
{
    private const ETAG_STRONG = '"abc123"';
    private const ETAG_WEAK   = 'W/"abc123"';

    // A fixed reference clock so date comparisons are deterministic.
    private const NOW = 1_700_000_000;             // request time
    private const MTIME = 1_699_000_000;           // ~11.5 days before NOW (outside the 60s weak window)
    private const PAST = 1_698_000_000;            // before MTIME -> resource modified since
    private const FUTURE = 1_700_500_000;          // after NOW -> invalid date

    private function dt(int $ts): string
    {
        return gmdate('D, d M Y H:i:s', $ts) . ' GMT';
    }

    // ---- No conditional headers -------------------------------------------

    public function testNoHeadersReturns200(): void
    {
        $this->assertSame(200, ConditionalRequest::evaluate('GET', [], self::ETAG_WEAK, self::MTIME, self::NOW));
    }

    // ---- If-Match (step 1) ------------------------------------------------

    public function testIfMatchWildcardWithEtagPasses(): void
    {
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => '*'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfMatchWildcardStillPassesWhenNoEtag(): void
    {
        // '*' matches any existing representation regardless of stored tag.
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => '*'], '', self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfMatchStrongMatchPasses(): void
    {
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => self::ETAG_STRONG], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfMatchNoMatchReturns412(): void
    {
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => '"other"'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfMatchAgainstWeakStoredEtagFails412(): void
    {
        // Strong comparison: a weak stored ETag can never satisfy If-Match.
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => self::ETAG_WEAK], self::ETAG_WEAK, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfMatchWeakRequestTokenAgainstStrongFails412(): void
    {
        // A weak token in the request list is skipped under strong comparison.
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => self::ETAG_WEAK], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfMatchListMatchesOneStrongTag(): void
    {
        $list = '"x", "abc123", "y"';
        $r = ConditionalRequest::evaluate('PUT', ['if-match' => $list], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfMatchNoMatchTakesPrecedenceOverIfNoneMatch(): void
    {
        // Step 1 (If-Match) fails -> 412 before If-None-Match is even consulted.
        $r = ConditionalRequest::evaluate('GET', [
            'if-match' => '"other"',
            'if-none-match' => self::ETAG_WEAK,
        ], self::ETAG_WEAK, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    // ---- If-Unmodified-Since (step 2) -------------------------------------

    public function testIfUnmodifiedSinceNotModifiedPasses(): void
    {
        // mtime <= ius -> NOMATCH -> precondition met, proceed.
        $r = ConditionalRequest::evaluate('PUT', ['if-unmodified-since' => $this->dt(self::NOW)], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfUnmodifiedSinceModifiedReturns412(): void
    {
        // mtime > ius (resource changed after the client's snapshot) -> 412.
        $r = ConditionalRequest::evaluate('PUT', ['if-unmodified-since' => $this->dt(self::PAST)], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfUnmodifiedSinceUnparseableDatePasses(): void
    {
        $r = ConditionalRequest::evaluate('PUT', ['if-unmodified-since' => 'not-a-date'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfUnmodifiedSinceWithinWeakWindowStillReturns412(): void
    {
        // Modified within the last 60s -> WEAK, but WEAK still means 412 here.
        $mtime = self::NOW - 10;
        $ius = self::NOW - 30;
        $r = ConditionalRequest::evaluate('PUT', ['if-unmodified-since' => $this->dt($ius)], self::ETAG_STRONG, $mtime, self::NOW);
        $this->assertSame(412, $r);
    }

    // ---- If-None-Match (step 3) -------------------------------------------

    public function testIfNoneMatchWeakMatchGetReturns304(): void
    {
        // W/"x" request vs "x" stored -> weak comparison succeeds for GET.
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => self::ETAG_WEAK], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchStrongVsStrongGetReturns304(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => self::ETAG_STRONG], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchStrongTokenVsWeakStoredGetReturns304(): void
    {
        // Weak comparison strips W/ on both sides: "x" matches W/"x".
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => self::ETAG_STRONG], self::ETAG_WEAK, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchNoMatchGetReturns200(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => '"deadbeef"'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfNoneMatchHeadReturns304(): void
    {
        $r = ConditionalRequest::evaluate('HEAD', ['if-none-match' => self::ETAG_WEAK], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchMatchNonGetReturns412(): void
    {
        // POST/PUT/DELETE with a matching If-None-Match -> 412, never 304.
        $r = ConditionalRequest::evaluate('POST', ['if-none-match' => self::ETAG_STRONG], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfNoneMatchNonGetUsesStrongComparison(): void
    {
        // Non-GET requires strong: a weak request token must NOT match -> 200.
        $r = ConditionalRequest::evaluate('DELETE', ['if-none-match' => self::ETAG_WEAK], self::ETAG_WEAK, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfNoneMatchWildcardGetReturns304(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => '*'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchWildcardWithoutEtagGetReturns304(): void
    {
        // '*' matches any existing representation even when no ETag is known.
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => '*'], '', self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfNoneMatchWildcardNonGetReturns412(): void
    {
        // Create-only semantics: PUT with If-None-Match: * on existing -> 412.
        $r = ConditionalRequest::evaluate('PUT', ['if-none-match' => '*'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfNoneMatchListMatch(): void
    {
        $list = 'W/"x", W/"abc123", W/"y"';
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => $list], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- If-None-Match with Range header (strong-only) --------------------

    public function testIfNoneMatchWithRangeWeakDoesNotMatchGet(): void
    {
        // GET + Range demands strong comparison: weak request token -> 200.
        $r = ConditionalRequest::evaluate('GET', [
            'if-none-match' => self::ETAG_WEAK,
            'range' => 'bytes=0-99',
        ], self::ETAG_WEAK, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfNoneMatchWithRangeStrongMatchesGetReturns304(): void
    {
        $r = ConditionalRequest::evaluate('GET', [
            'if-none-match' => self::ETAG_STRONG,
            'range' => 'bytes=0-99',
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- If-Modified-Since (step 4) ---------------------------------------

    public function testIfModifiedSinceNotModifiedGetReturns304(): void
    {
        // ims >= mtime and ims <= now -> not modified -> 304.
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $this->dt(self::NOW)], '', self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfModifiedSinceModifiedGetReturns200(): void
    {
        // ims < mtime -> resource changed since -> 200.
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $this->dt(self::PAST)], '', self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfModifiedSinceFutureDateInvalidReturns200(): void
    {
        // A future-dated IMS (ims > requestTime) is invalid -> served fresh.
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $this->dt(self::FUTURE)], '', self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfModifiedSinceNonGetIgnored(): void
    {
        // IMS only produces 304 for GET/HEAD; a POST is served 200.
        $r = ConditionalRequest::evaluate('POST', ['if-modified-since' => $this->dt(self::NOW)], '', self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfModifiedSinceUnparseableReturns200(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => 'garbage'], '', self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfModifiedSinceHeadReturns304(): void
    {
        $r = ConditionalRequest::evaluate('HEAD', ['if-modified-since' => $this->dt(self::NOW)], '', self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Precedence interactions (steps 3 + 4) ----------------------------

    public function testIfNoneMatchNoMatchSuppressesIfModifiedSince304(): void
    {
        // INM says "changed" (NOMATCH) and IMS says "not modified": resource
        // changed wins -> 200, mirroring Apache's not_modified=0 latch.
        $r = ConditionalRequest::evaluate('GET', [
            'if-none-match' => '"deadbeef"',
            'if-modified-since' => $this->dt(self::NOW),
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfNoneMatchMatchButIfModifiedSinceModifiedReturns200(): void
    {
        // INM matches (candidate 304) but IMS reports the resource WAS modified
        // (NOMATCH). Apache latches not_modified to 0 on that NOMATCH -> 200.
        $r = ConditionalRequest::evaluate('GET', [
            'if-none-match' => self::ETAG_STRONG,
            'if-modified-since' => $this->dt(self::PAST),
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    public function testIfNoneMatchMatchWithoutIfModifiedSinceReturns304(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => self::ETAG_STRONG], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfUnmodifiedSince412TakesPrecedenceOverIfNoneMatch(): void
    {
        // Step 2 fails -> 412 before If-None-Match (step 3) could 304.
        $r = ConditionalRequest::evaluate('GET', [
            'if-unmodified-since' => $this->dt(self::PAST),
            'if-none-match' => self::ETAG_STRONG,
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    // ---- Header case-insensitivity & method casing ------------------------

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $r = ConditionalRequest::evaluate('GET', ['If-None-Match' => self::ETAG_STRONG], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testMethodIsCaseInsensitiveForGetPath(): void
    {
        $r = ConditionalRequest::evaluate('get', ['if-none-match' => '*'], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Default request time ---------------------------------------------

    public function testDefaultRequestTimeUsesNow(): void
    {
        // With no explicit request time, an IMS of "now" against an old mtime
        // should still 304 (ims >= mtime, ims <= now).
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $this->dt(time())], '', time() - 100_000);
        $this->assertSame(304, $r);
    }

    // ---- Direct condition-helper coverage ---------------------------------

    public function testIfMatchHelperReturnsNoneWhenAbsent(): void
    {
        $this->assertSame(ConditionalRequest::COND_NONE, ConditionalRequest::ifMatch([], self::ETAG_STRONG));
    }

    public function testFindEtagStrongRejectsWeakStored(): void
    {
        $this->assertFalse(ConditionalRequest::findEtagStrong('"abc123"', self::ETAG_WEAK));
    }

    public function testFindEtagStrongMatchesStrong(): void
    {
        $this->assertTrue(ConditionalRequest::findEtagStrong('"x", "abc123"', '"abc123"'));
    }

    public function testFindEtagWeakStripsPrefixBothSides(): void
    {
        $this->assertTrue(ConditionalRequest::findEtagWeak('W/"abc123"', '"abc123"'));
        $this->assertTrue(ConditionalRequest::findEtagWeak('"abc123"', 'W/"abc123"'));
    }

    public function testFindEtagWeakRejectsNonQuoted(): void
    {
        $this->assertFalse(ConditionalRequest::findEtagWeak('abc123', '"abc123"'));
        $this->assertFalse(ConditionalRequest::findEtagWeak('"abc123"', 'abc123'));
    }
}
