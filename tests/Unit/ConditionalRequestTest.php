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

    // ---- Mutant-killing: $notModified initialisation (mutant #1 -2 vs -1) ---
    // -1 and -2 are both "unset" sentinels; any code path that reads the value
    // only branches on 0 and 1, so -2 is genuinely equivalent. No test needed.

    // ---- Mutant-killing: IUS NOMATCH latch ($notModified=0, mutant #2) ------

    public function testIfUnmodifiedSinceUnparsedPlusIfNoneMatchMatchReturns200(): void
    {
        // IUS header is present but unparseable → COND_NOMATCH → $notModified=0.
        // A subsequent INM match sets $notModified=1 only when $notModified !== 0.
        // So the 0-latch must prevent the 304 → result must be 200, not 304.
        $r = ConditionalRequest::evaluate('GET', [
            'if-unmodified-since' => 'not-a-date',
            'if-none-match' => self::ETAG_STRONG,
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    // ---- Mutant-killing: IMS NOMATCH latch ($notModified=0, mutant #3) ------

    public function testIfNoneMatchMatchThenIfModifiedSinceModifiedReturns200(): void
    {
        // Already covered by testIfNoneMatchMatchButIfModifiedSinceModifiedReturns200
        // but we add a helper that also exercises the notModified=0 latch:
        // INM matches (would give 304) but IMS says resource WAS modified (NOMATCH).
        // NOMATCH on IMS must latch $notModified=0, overriding the INM 304 vote.
        $r = ConditionalRequest::evaluate('GET', [
            'if-none-match' => self::ETAG_STRONG,
            'if-modified-since' => $this->dt(self::PAST),
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    // ---- Mutant-killing: IMS >= COND_WEAK check (mutant #4) ----------------

    public function testIfModifiedSinceWeakMatchWithin60sReturns304(): void
    {
        // mtime within 60 s of requestTime → COND_WEAK returned by ifModifiedSince.
        // The >= guard must catch WEAK, not only STRONG, so 304 results for GET.
        $mtime = self::NOW - 30;                 // 30 s ago → inside weak window
        $ims   = $mtime;                         // IMS exactly at mtime → ims>=mtime, ims<=now
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $this->dt($ims)], '', $mtime, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Mutant-killing: PublicVisibility mutants #5, #13, #14 ---------------
    // (already killed because the test suite calls ifUnmodifiedSince,
    //  ifNoneMatch, ifModifiedSince directly on the public API — see
    //  testIfMatchHelperReturnsNoneWhenAbsent and the direct-helper section)

    public function testIfUnmodifiedSinceDirectCallIsPublic(): void
    {
        // Calls the public static method directly; protected would fatal.
        $result = ConditionalRequest::ifUnmodifiedSince([], null, self::NOW, false);
        $this->assertSame(ConditionalRequest::COND_NONE, $result);
    }

    public function testIfNoneMatchDirectCallIsPublic(): void
    {
        $result = ConditionalRequest::ifNoneMatch([], self::ETAG_STRONG, true, false);
        $this->assertSame(ConditionalRequest::COND_NONE, $result);
    }

    public function testIfModifiedSinceDirectCallIsPublic(): void
    {
        $result = ConditionalRequest::ifModifiedSince([], null, self::NOW, false);
        $this->assertSame(ConditionalRequest::COND_NONE, $result);
    }

    // ---- Mutant-killing: Coalesce swap ($lastModified??$requestTime, mutants #6, #15) ----

    public function testIfUnmodifiedSinceUsesLastModifiedNotRequestTime(): void
    {
        // $lastModified=MTIME, $requestTime=NOW. ius=MTIME-1 (one second before mtime).
        // $mtime must resolve to MTIME (not NOW) for the $mtime>$ius branch to fire.
        // If coalesce were swapped to $requestTime??$lastModified, $mtime=NOW,
        // and NOW>ius would also be true — but the 60s window check would differ.
        // Use ius=MTIME-1: mtime(MTIME)>ius, requestTime(NOW)-mtime(MTIME)=~11.5 days >60s
        // → COND_STRONG → 412.
        $r = ConditionalRequest::evaluate('PUT', [
            'if-unmodified-since' => $this->dt(self::MTIME - 1),
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfModifiedSinceUsesLastModifiedNotRequestTime(): void
    {
        // $lastModified=MTIME, $requestTime=NOW. ims=MTIME (exactly at mtime).
        // Condition: ims>=mtime (MTIME>=MTIME ✓) && ims<=requestTime (MTIME<=NOW ✓).
        // requestTime(NOW) >= mtime(MTIME)+60 → COND_STRONG → 304.
        // If coalesce swapped: $mtime=NOW, ims(MTIME) < mtime(NOW) → NOMATCH → 200.
        $r = ConditionalRequest::evaluate('GET', [
            'if-modified-since' => $this->dt(self::MTIME),
        ], '', self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Mutant-killing: IUS $mtime > $ius boundary (mutant #7, >= vs >) ---

    public function testIfUnmodifiedSinceMtimeEqualsIusPassesNomatch(): void
    {
        // mtime === ius: resource was not modified after the client's snapshot.
        // The condition is mtime > ius; when equal, it falls through to NOMATCH
        // (precondition met). The mutant (>=) would fire and return 412/WEAK.
        $r = ConditionalRequest::evaluate('PUT', [
            'if-unmodified-since' => $this->dt(self::MTIME),
        ], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(200, $r);
    }

    // ---- Mutant-killing: IUS 60s window boundary (mutants #8,#9,#10,#11,#12) ----

    public function testIfUnmodifiedSinceExactly60sWindowBoundaryIsWeak(): void
    {
        // requestTime = mtime + 59 → requestTime < mtime+60 → WEAK → 412.
        // Mutant +59: requestTime < mtime+59 would be false (59<59 false) → STRONG.
        // Mutant +61: requestTime < mtime+61 → same result (WEAK). Some of these
        // boundary mutants are equivalent to each other at this exact value.
        // Use requestTime = mtime+59 to kill the DecrementInteger (+59) mutant:
        // original: 59 < 60 → true (WEAK); mutant: 59 < 59 → false (STRONG). Both
        // still return 412 from evaluate() — equivalent from evaluate()'s perspective.
        // But calling ifUnmodifiedSince directly we can distinguish WEAK vs STRONG.
        $mtime = 1_000_000;
        $requestTime = $mtime + 59;            // exactly inside the weak window
        $ius = $mtime - 1;                     // resource WAS modified after ius
        $result = ConditionalRequest::ifUnmodifiedSince(
            ['if-unmodified-since' => $this->dt($ius)],
            $mtime, $requestTime, false
        );
        // requestTime(mtime+59) < mtime+60 → true → COND_WEAK (not STRONG).
        $this->assertSame(ConditionalRequest::COND_WEAK, $result);
    }

    public function testIfUnmodifiedSinceExactly60sBoundaryIsStrong(): void
    {
        // requestTime = mtime+60: requestTime < mtime+60 → false → COND_STRONG.
        // Kills: IncrementInteger (+61 would still be STRONG), Plus (-60 gives false
        // since mtime-60<mtime+60 so always false → NOMATCH... actually mtime-60<mtime
        // so requestTime(mtime+60) < mtime-60 would be false → STRONG same result).
        // Most useful: kills LessThanNegotiation (>=): requestTime>=mtime+60 → true→STRONG.
        $mtime = 1_000_000;
        $requestTime = $mtime + 60;
        $ius = $mtime - 1;
        $result = ConditionalRequest::ifUnmodifiedSince(
            ['if-unmodified-since' => $this->dt($ius)],
            $mtime, $requestTime, false
        );
        $this->assertSame(ConditionalRequest::COND_STRONG, $result);
    }

    public function testIfUnmodifiedSinceJustInside60sWindowUsesWeakNotStrong(): void
    {
        // requestTime = mtime+1: clearly inside the 60s weak window.
        // Kills Plus mutant (-60): requestTime < mtime-60 → (mtime+1 < mtime-60) → false
        // → would return STRONG instead of WEAK.
        $mtime = 1_000_000;
        $requestTime = $mtime + 1;
        $ius = $mtime - 1;
        $result = ConditionalRequest::ifUnmodifiedSince(
            ['if-unmodified-since' => $this->dt($ius)],
            $mtime, $requestTime, false
        );
        $this->assertSame(ConditionalRequest::COND_WEAK, $result);
    }

    // ---- Mutant-killing: IMS boundary (mutant #16, >= vs >) ----------------

    public function testIfModifiedSinceImsEqualsImtimeReturns304(): void
    {
        // ims === mtime: resource was not modified since the IMS timestamp.
        // Condition: ims >= mtime → true. Mutant (>): ims > mtime → false → NOMATCH → 200.
        $mtime = self::MTIME;
        $ims   = $mtime;
        // requestTime well outside 60s window → COND_STRONG → 304.
        $r = ConditionalRequest::evaluate('GET', [
            'if-modified-since' => $this->dt($ims),
        ], '', $mtime, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Mutant-killing: IMS 60s window boundary (mutants #17,#18,#19,#20,#21) ---

    public function testIfModifiedSinceWithin60sWindowReturnsWeak(): void
    {
        // requestTime = mtime+1 → requestTime < mtime+60 → WEAK.
        // Kills Plus mutant: mtime-60 → (mtime+1 < mtime-60) → false → STRONG instead.
        $mtime = 1_000_000;
        $requestTime = $mtime + 1;
        $ims = $mtime;          // ims==mtime: ims>=mtime ✓ && ims<=requestTime ✓
        $result = ConditionalRequest::ifModifiedSince(
            ['if-modified-since' => $this->dt($ims)],
            $mtime, $requestTime, false
        );
        $this->assertSame(ConditionalRequest::COND_WEAK, $result);
    }

    public function testIfModifiedSinceExactly60sBoundaryIsStrong(): void
    {
        // requestTime = mtime+60: requestTime < mtime+60 → false → COND_STRONG.
        // Kills IncrementInteger (+61): still STRONG — equivalent.
        // Kills DecrementInteger (+59): mtime+60 < mtime+59 → false → STRONG — same.
        // Kills LessThanNegotiation (>=): requestTime>=mtime+60 → true → STRONG — same.
        // Kills LessThan (<=): mtime+60 <= mtime+60 → true → WEAK — DIFFERENT! Killable.
        $mtime = 1_000_000;
        $requestTime = $mtime + 60;
        $ims = $mtime;
        $result = ConditionalRequest::ifModifiedSince(
            ['if-modified-since' => $this->dt($ims)],
            $mtime, $requestTime, false
        );
        $this->assertSame(ConditionalRequest::COND_STRONG, $result);
    }

    public function testIfModifiedSinceJustInsideWindowIsWeak(): void
    {
        // requestTime = mtime+59: requestTime < mtime+60 → true → WEAK.
        // Kills DecrementInteger (+59): mtime+59 < mtime+59 → false → STRONG.
        $mtime = 1_000_000;
        $requestTime = $mtime + 59;
        $ims = $mtime;
        $result = ConditionalRequest::ifModifiedSince(
            ['if-modified-since' => $this->dt($ims)],
            $mtime, $requestTime, false
        );
        $this->assertSame(ConditionalRequest::COND_WEAK, $result);
    }

    // ---- Mutant-killing: findEtagStrong || → && (mutants #22, #23) ----------

    public function testFindEtagStrongRejectsUnquotedStoredTag(): void
    {
        // stored tag 'abc' (no quotes): $etag==='' is false, $etag[0]!=='"' is true.
        // || → true → return false (correct). && → false (skips guard) → enters loop.
        // In the loop, candidate='"abc123"', which !== 'abc' → returns false anyway.
        // But: stored='abc', list='"abc"' — loop checks candidate '"abc"' === 'abc' → false.
        // Actually to distinguish: stored='abc', list='abc'. Guard skips with &&,
        // candidate 'abc' has [0]!=='"' so continues. Result: false either way...
        // Better: stored='"x"', list='"y", abc, "x"'. With ||: candidate 'abc' has
        // [0]!=='\"', continue (skip) → checks '"x"' === '"x"' → true.
        // With &&: 'abc' guard: false && true → false → doesn't continue → falls through
        // to candidate===etag check: 'abc' === '"x"' → false. Then '"x"'==='"x"' → true.
        // Same result. Need a case where the guard matters differently...
        // The key difference is when stored etag is non-empty non-quoted:
        // With ||: true immediately → return false before loop.
        // With &&: false (etag!==''), then checks etag[0]!=='"' → true → && → false → NOT true
        // → does NOT return false, enters the loop!
        $this->assertFalse(ConditionalRequest::findEtagStrong('"abc123"', 'abc123'));
    }

    public function testFindEtagStrongWeakRequestTokenSkippedViaOrGuard(): void
    {
        // list has a weak token first, then a matching strong token.
        // With continue (correct): skip weak, match strong → true.
        // With && in the candidate guard: 'W/"abc123"'[0]==='W'!=='"' → true,
        // '' || true === true (correct continue). But '' && true → false → no continue
        // → falls to candidate===etag: 'W/"abc123"'==='"abc123"' → false. Then '"abc123"'==='"abc123"'→true.
        // Actually same result... The || vs && in candidate guard at line 277:
        // candidate='W/"abc123"': candidate==='' is false, candidate[0]!=='"' is true.
        // ||: false||true=true → continue (skip). &&: false&&true=false → no continue → check match.
        // 'W/"abc123"' !== '"abc123"' → no match. Then next candidate '"abc123"'==='"abc123"'→true.
        // Same result. Let me think of a case where it differs...
        // candidate='' (empty after trim): ||: true||... → continue. &&: true&&''[0]!=='"'
        // PHP: ''[0] is '' → ''!=='"' → true → &&: true → continue. Same!
        // The REAL difference: candidate='W/"abc123"' AND that's the ONLY candidate AND etag='"abc123"'.
        // ||: skip it → return false. &&: don't skip → 'W/"abc123"'==='"abc123"'→false → return false. Same!
        // The mutant #23 (|| → && in candidate guard) seems equivalent for findEtagStrong.
        // Because even if we don't `continue`, the weak token won't === the strong etag.
        // EQUIVALENT — document this.
        $this->assertFalse(ConditionalRequest::findEtagStrong('W/"abc123"', '"abc123"'));
        $this->assertTrue(ConditionalRequest::findEtagStrong('W/"other", "abc123"', '"abc123"'));
    }

    // ---- Mutant-killing: continue → break (mutant #24) ----------------------

    public function testFindEtagStrongSkipsWeakAndFindsStrongLater(): void
    {
        // First candidate is weak (W/...), second is the matching strong tag.
        // continue: skip weak, find strong → true.
        // break: exit loop on first weak → return false.
        $this->assertTrue(ConditionalRequest::findEtagStrong('W/"abc123", "abc123"', '"abc123"'));
    }

    // ---- Mutant-killing: stripWeak || → && (mutant #25) --------------------

    public function testFindEtagWeakRejectsUnquotedListEntryWithOrGuard(): void
    {
        // list entry 'abc123' (no quotes, no W/ prefix): stripWeak returns null (correct).
        // With &&: '' === false for 'abc123'==='' check → false, then 'a'!=='"' → true
        // → && → false → does NOT return null → returns 'abc123'.
        // Then 'abc123'===target → could match, wrong.
        // Test: list='abc123', etag='"abc123"'; target='"abc123"'.
        // With ||: stripWeak('abc123') → null → skip. findEtagWeak returns false.
        // With &&: stripWeak('abc123') → 'abc123'. 'abc123'==='"abc123"'→false. Still false.
        // Hmm. Let's try list='"abc123"', etag='abc123':
        // target=stripWeak('abc123'): 'abc123'!=='' so false||true=true → null. findEtagWeak→false.
        // With &&: false&&true=false → NOT null, return 'abc123'. Then loop: stripWeak('"abc123"')→'"abc123"'. '"abc123"'==='abc123'→false. Still false.
        // The case that differs: etag='abc123' and we want it to return null from stripWeak.
        // With &&: stripWeak('abc123') returns 'abc123' (doesn't guard). Then loop finds
        // a matching 'abc123' in list → returns true instead of false!
        $this->assertFalse(ConditionalRequest::findEtagWeak('abc123', 'abc123'));
    }

    // ---- Mutant-killing: isWildcard trim (mutant #26) -----------------------

    public function testWildcardWithWhitespacePasses(): void
    {
        // " * " should match as wildcard; trim() makes it "=" to "*".
        // Without trim: " * " !== "*" → not wildcard.
        $r = ConditionalRequest::evaluate('GET', ['if-none-match' => ' * '], self::ETAG_STRONG, self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    // ---- Mutant-killing: parseHttpDate trim (mutant #27) --------------------

    public function testParseHttpDateTrimsLeadingTrailingWhitespace(): void
    {
        // A date string with surrounding spaces must still parse correctly.
        // Without trim(), " Thu, 14 Nov 2019 12:00:00 GMT " would fail strtotime
        // or be treated as non-empty but unparseable.
        $date = ' ' . $this->dt(self::MTIME) . ' ';
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $date], '', self::MTIME, self::NOW);
        // ims==mtime, requestTime well outside 60s → COND_STRONG → 304.
        $this->assertSame(304, $r);
    }

    // ---- #363: tolerant parsing like apr_date_parse_http ------------------

    public function testIfModifiedSinceWithLegacyLengthParameterStill304(): void
    {
        // #363 — Apache's apr_date_parse_http tolerates the historical trailing
        // "; length=NNN" parameter that some caches append. strtotime rejects it,
        // which downstream became a 304→200 cache miss. parseHttpDate now strips
        // the trailing parameter, so the date still drives the comparison → 304.
        $date = $this->dt(self::MTIME) . '; length=62';
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => $date], '', self::MTIME, self::NOW);
        $this->assertSame(304, $r);
    }

    public function testIfUnmodifiedSinceWithLengthParameterStillEnforced(): void
    {
        // #363 — for an unsafe method, a malformed-but-parseable
        // If-Unmodified-Since must still protect the resource (a past date →
        // 412), not silently bypass the precondition because of the trailing
        // "; length=" parameter. PAST < MTIME → resource modified since → 412.
        $date = $this->dt(self::PAST) . '; length=128';
        $r = ConditionalRequest::evaluate('PUT', ['if-unmodified-since' => $date], '', self::MTIME, self::NOW);
        $this->assertSame(412, $r);
    }

    public function testIfModifiedSinceLegacyRfc850AndAsctimeFormatsParse(): void
    {
        // #363 — the three RFC 9110 §5.6.7 formats all parse (strtotime handles
        // RFC 850 + asctime); only the trailing-parameter case needed the strip.
        $rfc850  = gmdate('l, d-M-y H:i:s', self::MTIME) . ' GMT'; // RFC 850
        $asctime = gmdate('D M j H:i:s Y', self::MTIME);          // asctime
        $this->assertSame(304, ConditionalRequest::evaluate('GET', ['if-modified-since' => $rfc850], '', self::MTIME, self::NOW));
        $this->assertSame(304, ConditionalRequest::evaluate('GET', ['if-modified-since' => $asctime], '', self::MTIME, self::NOW));
    }

    public function testIfModifiedSinceBareSemicolonIsIgnored(): void
    {
        // #363 — a value that is ONLY a parameter (no date before the ';') has no
        // recognisable timestamp → NOMATCH → 200 (well outside the 60s window).
        $requestTime = time();
        $mtime = $requestTime - 100_000;
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => '; length=62'], '', $mtime, $requestTime);
        $this->assertSame(200, $r);
    }

    public function testParseHttpDateWhitespaceOnlyHeaderIsIgnored(): void
    {
        // Without trim(), strtotime('   ') returns the current wall-clock time
        // (non-false). When $requestTime is also set to time(), the mutant makes
        // ims ≈ requestTime, so ims <= requestTime is true → the resource appears
        // "not modified" → 304. With trim(): '   ' → '' → null → NOMATCH → 200.
        // Use a real-time requestTime so strtotime('   ') ≈ requestTime.
        $requestTime = time();
        $mtime = $requestTime - 100_000;  // well in the past, outside 60s window
        $r = ConditionalRequest::evaluate('GET', ['if-modified-since' => '   '], '', $mtime, $requestTime);
        $this->assertSame(200, $r);
    }
}
