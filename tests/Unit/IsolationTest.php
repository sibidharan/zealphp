<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\CgiMode;
use ZealPHP\Isolation;

/**
 * Patch-coverage for the Isolation backed enum: case values, coerce()
 * (enum/string/case-insensitive/invalid), isProcess() across every case,
 * and cgiMode() mapping for each case including the null default branch.
 */
final class IsolationTest extends TestCase
{
    // -- enum case backing values ------------------------------------------

    public function testEnumCaseValues(): void
    {
        $this->assertSame('coroutine', Isolation::Coroutine->value);
        $this->assertSame('cgi-pool', Isolation::CgiPool->value);
        $this->assertSame('cgi-proc', Isolation::CgiProc->value);
        $this->assertSame('cgi-fcgi', Isolation::CgiFcgi->value);
        $this->assertSame('none', Isolation::None->value);
    }

    public function testEnumHasExactlyFiveCases(): void
    {
        $this->assertCount(5, Isolation::cases());
    }

    public function testTryFromKnownValues(): void
    {
        $this->assertSame(Isolation::Coroutine, Isolation::tryFrom('coroutine'));
        $this->assertSame(Isolation::None, Isolation::tryFrom('none'));
    }

    public function testTryFromUnknownReturnsNull(): void
    {
        // Source the string from getenv() so PHPStan sees a runtime `string`
        // (not a literal) and infers tryFrom()'s return as Isolation|null —
        // otherwise it folds the result to a certain null and flags the assert.
        $bad = getenv('ZEALPHP_NONEXISTENT_ISOLATION_ENV_XYZ') ?: 'nope';
        $this->assertNull(Isolation::tryFrom($bad));
    }

    // -- coerce(): enum passthrough ----------------------------------------

    public function testCoerceFromEnumReturnsSameInstance(): void
    {
        $this->assertSame(Isolation::Coroutine, Isolation::coerce(Isolation::Coroutine));
        $this->assertSame(Isolation::CgiPool, Isolation::coerce(Isolation::CgiPool));
        $this->assertSame(Isolation::CgiProc, Isolation::coerce(Isolation::CgiProc));
        $this->assertSame(Isolation::CgiFcgi, Isolation::coerce(Isolation::CgiFcgi));
        $this->assertSame(Isolation::None, Isolation::coerce(Isolation::None));
    }

    // -- coerce(): string forms --------------------------------------------

    public function testCoerceFromStringEveryCase(): void
    {
        $this->assertSame(Isolation::Coroutine, Isolation::coerce('coroutine'));
        $this->assertSame(Isolation::CgiPool, Isolation::coerce('cgi-pool'));
        $this->assertSame(Isolation::CgiProc, Isolation::coerce('cgi-proc'));
        $this->assertSame(Isolation::CgiFcgi, Isolation::coerce('cgi-fcgi'));
        $this->assertSame(Isolation::None, Isolation::coerce('none'));
    }

    public function testCoerceIsCaseInsensitive(): void
    {
        $this->assertSame(Isolation::Coroutine, Isolation::coerce('COROUTINE'));
        $this->assertSame(Isolation::CgiPool, Isolation::coerce('Cgi-Pool'));
        $this->assertSame(Isolation::CgiProc, Isolation::coerce('CGI-PROC'));
        $this->assertSame(Isolation::CgiFcgi, Isolation::coerce('Cgi-Fcgi'));
        $this->assertSame(Isolation::None, Isolation::coerce('None'));
    }

    // -- coerce(): invalid inputs ------------------------------------------

    public function testCoerceUnknownStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Isolation::coerce('nonsense');
    }

    public function testCoerceEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Isolation::coerce('');
    }

    public function testCoerceMessageNamesTheBadValueAndValidOptions(): void
    {
        try {
            Isolation::coerce('bogus');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString("'bogus'", $msg);
            $this->assertStringContainsString('coroutine', $msg);
            $this->assertStringContainsString('cgi-pool', $msg);
            $this->assertStringContainsString('cgi-proc', $msg);
            $this->assertStringContainsString('cgi-fcgi', $msg);
            $this->assertStringContainsString('none', $msg);
        }
    }

    /**
     * The underscore variant ('cgi_pool') is NOT a valid backing value —
     * coerce() lowercases but does not normalise separators.
     */
    public function testCoerceUnderscoreVariantThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Isolation::coerce('cgi_pool');
    }

    // -- isProcess(): the three CGI strategies are true, others false ------

    public function testIsProcessTrueForCgiStrategies(): void
    {
        $this->assertTrue(Isolation::CgiPool->isProcess());
        $this->assertTrue(Isolation::CgiProc->isProcess());
        $this->assertTrue(Isolation::CgiFcgi->isProcess());
    }

    public function testIsProcessFalseForInProcessStrategies(): void
    {
        $this->assertFalse(Isolation::Coroutine->isProcess());
        $this->assertFalse(Isolation::None->isProcess());
    }

    /** Sanity: exactly the three CGI cases report isProcess() === true. */
    public function testIsProcessCountMatchesCgiCases(): void
    {
        $processCases = array_filter(
            Isolation::cases(),
            static fn (Isolation $i): bool => $i->isProcess()
        );
        $this->assertCount(3, $processCases);
    }

    // -- cgiMode(): match mapping incl. null default branch ----------------

    public function testCgiModeMapsCgiStrategies(): void
    {
        $this->assertSame(CgiMode::Pool, Isolation::CgiPool->cgiMode());
        $this->assertSame(CgiMode::Proc, Isolation::CgiProc->cgiMode());
        $this->assertSame(CgiMode::Fcgi, Isolation::CgiFcgi->cgiMode());
    }

    public function testCgiModeNullForNonProcessStrategies(): void
    {
        $this->assertNull(Isolation::Coroutine->cgiMode());
        $this->assertNull(Isolation::None->cgiMode());
    }

    /**
     * cgiMode() and isProcess() agree: a non-null CgiMode is returned
     * exactly when the case is a process-isolation strategy.
     */
    public function testCgiModePresenceTracksIsProcess(): void
    {
        foreach (Isolation::cases() as $case) {
            if ($case->isProcess()) {
                $this->assertInstanceOf(CgiMode::class, $case->cgiMode());
            } else {
                $this->assertNull($case->cgiMode());
            }
        }
    }

    /** Round-trip: value -> coerce -> back to the same case for all cases. */
    public function testValueCoerceRoundTrip(): void
    {
        foreach (Isolation::cases() as $case) {
            $this->assertSame($case, Isolation::coerce($case->value));
        }
    }
}
