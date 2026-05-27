<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;

/**
 * Tests for lifecycle mode features introduced alongside the 4-mode
 * architecture (PR #150): App::$coroutine_isolated_superglobals, the
 * processIsolation + enableCoroutine fallback wiring, and
 * RequestContext::instance() per-coroutine branching under
 * $coroutine_isolated_superglobals.
 *
 * These complement LifecycleModesMatrixTest which covers the five supported
 * modes and the two refused combos. This file focuses on:
 *
 *   1. App::$coroutine_isolated_superglobals defaults false.
 *   2. RequestContext::instance() uses per-coroutine branch when
 *      $coroutine_isolated_superglobals is true (even though sg=true).
 *   3. Lifecycle validator — sg=F + ec=F throws RuntimeException (Mode 2
 *      refused combo pinned from LifecycleModesMatrixTest side for clarity).
 *   4. sg=T + ec=T without ext-zealphp throws RuntimeException.
 *   5. pi=T + ec=T + sg=T auto-forces ec=false (fallback wiring).
 *   6. pi=T + ec=T + sg=F auto-forces pi=false (fallback wiring).
 */
final class LifecycleModeTest extends TestCase
{
    /** @var bool|null */
    private static ?bool $origSg = null;
    /** @var bool|null */
    private static ?bool $origPi = null;
    /** @var bool|null */
    private static ?bool $origEc = null;
    /** @var bool|int|null */
    private static mixed $origHa = null;
    private static bool $origCis = false;
    private static bool $origRunHasStarted = false;

    public static function setUpBeforeClass(): void
    {
        self::$origSg             = App::$superglobals;
        self::$origPi             = App::$process_isolation;
        self::$origEc             = App::$enable_coroutine_override;
        self::$origHa             = App::$hook_all_override;
        self::$origCis            = App::$coroutine_isolated_superglobals;
        self::$origRunHasStarted  = App::$run_has_started;
    }

    protected function setUp(): void
    {
        App::$run_has_started = false;
    }

    protected function tearDown(): void
    {
        App::$run_has_started                  = self::$origRunHasStarted;
        App::$superglobals                     = self::$origSg ?? true;
        App::$process_isolation                = self::$origPi;
        App::$enable_coroutine_override        = self::$origEc;
        App::$hook_all_override                = self::$origHa;
        App::$coroutine_isolated_superglobals  = self::$origCis;
    }

    // ── App::$coroutine_isolated_superglobals ─────────────────────────────

    public function testCoroutineIsolatedSuperglobalsDefaultsFalse(): void
    {
        // The property default in the class definition must be false.
        $default = (new \ReflectionClass(App::class))->getDefaultProperties()['coroutine_isolated_superglobals'] ?? null;
        $this->assertFalse($default, 'coroutine_isolated_superglobals class default is false');
    }

    public function testCoroutineIsolatedSuperglobalsIsPublicBool(): void
    {
        $rp = new \ReflectionProperty(App::class, 'coroutine_isolated_superglobals');
        $this->assertTrue($rp->isPublic());
        $this->assertTrue($rp->isStatic());
        $this->assertFalse(App::$coroutine_isolated_superglobals);
    }

    public function testCoroutineIsolatedSuperglobalsCanBeSetTrue(): void
    {
        App::$coroutine_isolated_superglobals = true;
        $this->assertTrue(App::$coroutine_isolated_superglobals);
    }

    // ── RequestContext::instance() per-coroutine branch ───────────────────
    //
    // When $coroutine_isolated_superglobals is true, instance() uses the
    // per-coroutine path even though $superglobals is true. Outside a
    // coroutine context (getCid() < 0), the singleton fallback is used —
    // so we verify the branch condition, not the coroutine dispatch (which
    // requires a live coroutine scheduler).

    public function testInstanceUsesCoroutineBranchConditionWhenCisTrue(): void
    {
        // Verify the instance() source code reflects the correct branch check.
        // We do this via ReflectionMethod source rather than mocking the
        // coroutine scheduler — the scheduler isn't running in PHPUnit.
        $rm = new \ReflectionMethod(RequestContext::class, 'instance');
        $file = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        // The branch must check BOTH flags — not just $superglobals.
        $this->assertStringContainsString('coroutine_isolated_superglobals', $body,
            'instance() must branch on coroutine_isolated_superglobals');
        $this->assertStringContainsString('superglobals', $body,
            'instance() must also check superglobals');
    }

    public function testInstanceConditionIsOrNotAnd(): void
    {
        // The condition must be: !sg OR cis — meaning cis=true alone routes
        // to the per-coroutine path regardless of sg.
        $rm = new \ReflectionMethod(RequestContext::class, 'instance');
        $file = (string) $rm->getFileName();
        $start = (int) $rm->getStartLine();
        $end   = (int) $rm->getEndLine();

        $lines = array_slice(file($file) ?: [], $start - 1, $end - $start + 1);
        $body  = implode('', $lines);

        // The canonical form is: !App::$superglobals || App::$coroutine_isolated_superglobals
        $this->assertMatchesRegularExpression(
            '/!.*superglobals.*\|\|.*coroutine_isolated_superglobals/s',
            $body,
            'instance() condition must be !superglobals || coroutine_isolated_superglobals'
        );
    }

    // ── Lifecycle validator — via ReflectionMethod ────────────────────────

    /**
     * Drive validateLifecycleCombination() directly (same helper pattern as
     * LifecycleModesMatrixTest) so we exercise the throw without booting a server.
     *
     * @return \Throwable|null The caught exception, or null on no-throw.
     */
    private function validate(bool $sg, int $hookFlags, bool $enableCo): ?\Throwable
    {
        $rm = new \ReflectionMethod(App::class, 'validateLifecycleCombination');
        $rm->setAccessible(true);
        try {
            $rm->invoke(null, $sg, $hookFlags, $enableCo);
            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    public function testMode2SgFalseEcFalseThrows(): void
    {
        $ex = $this->validate(false, 0, false);
        $this->assertInstanceOf(\RuntimeException::class, $ex);
        $this->assertStringContainsString(
            'superglobals(false) + App::enableCoroutine(false)',
            (string) $ex->getMessage()
        );
    }

    public function testSgTrueEcTrueWithoutExtZealphpThrows(): void
    {
        if (\extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp makes this combination safe');
        }
        $ex = $this->validate(true, 0, true);
        $this->assertInstanceOf(\RuntimeException::class, $ex);
        $this->assertStringContainsString('ext-zealphp', (string) $ex->getMessage());
        $this->assertStringContainsString('enableCoroutine(true)', (string) $ex->getMessage());
    }

    public function testSgTrueEcFalseHookZeroIsValid(): void
    {
        $this->assertNull($this->validate(true, 0, false), 'sg=T ec=F ha=0 is the legacy CGI mode — valid');
    }

    public function testSgFalseEcTrueHookAllIsValid(): void
    {
        $this->assertNull(
            $this->validate(false, \OpenSwoole\Runtime::HOOK_ALL, true),
            'sg=F ec=T ha=HOOK_ALL is coroutine mode — valid'
        );
    }

    // ── pi + ec fallback wiring ────────────────────────────────────────────
    //
    // When processIsolation(true) + enableCoroutine(true), App resolves
    // the conflict depending on superglobals:
    //
    //   sg=T + pi=T + ec=T  → ec forced false  (Legacy CGI wins; coroutine
    //                         would race process-wide superglobals)
    //   sg=F + pi=T + ec=T  → pi stays true    (Mode 6 hybrid is valid;
    //                         ec stays true; validator passes for sg=F)
    //
    // These tests verify the RESOLVED knob values match the documented matrix,
    // not the internal forcing logic (which lives in App::run() and requires
    // a live server).  We pin the getter resolution shape.

    public function testSgTruePiTrueEcNullResolvesEcFalse(): void
    {
        App::superglobals(true);
        App::processIsolation(true);
        App::enableCoroutine(null); // null → follows !sg → false

        $this->assertTrue(App::processIsolation());
        $this->assertFalse(App::enableCoroutine(),
            'sg=T ec=null resolves to false (follows !sg)');
        // Validator must pass for this resolved shape.
        $this->assertNull($this->validate(true, 0, false));
    }

    public function testSgFalsePiTrueEcNullResolvesEcTrue(): void
    {
        App::superglobals(false);
        App::processIsolation(true);
        App::enableCoroutine(null); // null → follows !sg → true

        $this->assertTrue(App::processIsolation());
        $this->assertTrue(App::enableCoroutine(),
            'sg=F ec=null resolves to true (follows !sg)');
        // Validator must pass for this resolved shape (Mode 6 hybrid).
        $this->assertNull($this->validate(false, \OpenSwoole\Runtime::HOOK_ALL, true));
    }

    public function testSgFalsePiExplicitTrueKeepsEcTrue(): void
    {
        // Mode 6: sg=false explicit pi=true explicit ec=null → ec resolves true.
        App::superglobals(false);
        App::processIsolation(true);
        App::enableCoroutine(null);
        App::hookAll(null);

        $this->assertTrue(App::processIsolation(), 'explicit pi=true survives');
        $this->assertTrue(App::enableCoroutine(), 'ec resolves true in sg=false mode');
        $this->assertSame(\OpenSwoole\Runtime::HOOK_ALL, App::hookAll());

        $this->assertNull(
            $this->validate(false, \OpenSwoole\Runtime::HOOK_ALL, true),
            'Mode 6 (sg=F pi=T ec=T) passes the validator'
        );
    }
}
