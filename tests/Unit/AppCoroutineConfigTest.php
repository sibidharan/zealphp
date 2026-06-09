<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Unit coverage for the coroutine-legacy CONFIG surface on ZealPHP\App:
 * the fluent isolation getter/setter pairs added for the per-coroutine
 * compatibility-runtime stack, the App::mode() presets' resulting flag
 * combinations, and the boot-time memory advisory.
 *
 * Each setter follows the App configurable contract:
 *   - no-arg call  → getter (returns current value, no mutation)
 *   - one-arg call → setter (assigns then returns the new value)
 * All backing properties are STATIC, so originals are snapshotted in
 * setUp() and restored in tearDown() to keep tests hermetic.
 *
 * mode() / isolation() flag-wiring + the Isolation enum are exercised by
 * AppModeIsolationTest; this file targets the per-knob fluent setters,
 * the advisory, and the mode→isolation-knob fan-out those don't pin.
 */
final class AppCoroutineConfigTest extends TestCase
{
    private bool $origSuperglobals;
    private bool $origSilentRedeclare;
    private bool $origIncludeIsolation;
    private bool $origDefineIsolation;
    private bool $origFunctionIsolation;
    private bool $origCoroutineGlobalsIsolation;
    private bool $origCoroutineStaticsIsolation;
    private bool $origKeepGlobals;
    private bool $origRunHasStarted;
    /** @var bool|null */
    private $origProcessIsolation;
    /** @var bool|null */
    private $origEnableCoroutine;
    /** @var bool|int|null */
    private $origHookAll;
    private string $origCgiMode;

    protected function setUp(): void
    {
        $this->origSuperglobals               = App::$superglobals;
        $this->origSilentRedeclare            = App::$silent_redeclare;
        $this->origIncludeIsolation           = App::$include_isolation;
        $this->origDefineIsolation            = App::$define_isolation;
        $this->origFunctionIsolation          = App::$function_isolation;
        $this->origCoroutineGlobalsIsolation  = App::$coroutine_globals_isolation;
        $this->origCoroutineStaticsIsolation  = App::$coroutine_statics_isolation;
        $this->origKeepGlobals                = App::$keep_globals;
        $this->origRunHasStarted              = App::$run_has_started;
        $this->origProcessIsolation           = App::$process_isolation;
        $this->origEnableCoroutine            = App::$enable_coroutine_override;
        $this->origHookAll                    = App::$hook_all_override;
        $this->origCgiMode                    = App::$cgi_mode;

        // mode()/isolation() refuse to run after boot — pin the boot flag off.
        App::$run_has_started = false;
    }

    protected function tearDown(): void
    {
        App::$superglobals                 = $this->origSuperglobals;
        App::$silent_redeclare             = $this->origSilentRedeclare;
        App::$include_isolation            = $this->origIncludeIsolation;
        App::$define_isolation             = $this->origDefineIsolation;
        App::$function_isolation           = $this->origFunctionIsolation;
        App::$coroutine_globals_isolation  = $this->origCoroutineGlobalsIsolation;
        App::$coroutine_statics_isolation  = $this->origCoroutineStaticsIsolation;
        App::$keep_globals                 = $this->origKeepGlobals;
        App::$process_isolation            = $this->origProcessIsolation;
        App::$enable_coroutine_override    = $this->origEnableCoroutine;
        App::$hook_all_override            = $this->origHookAll;
        App::$cgi_mode                     = $this->origCgiMode !== '' ? $this->origCgiMode : 'pool';
        App::$run_has_started              = $this->origRunHasStarted;
    }

    // ── coroutineGlobalsIsolation() ──────────────────────────────────────

    public function testCoroutineGlobalsIsolationDefaultIsFalse(): void
    {
        App::$coroutine_globals_isolation = false;
        $this->assertFalse(App::coroutineGlobalsIsolation(), 'default is off');
    }

    public function testCoroutineGlobalsIsolationSetTruePersistsAndReturns(): void
    {
        $this->assertTrue(App::coroutineGlobalsIsolation(true), 'setter returns the new value');
        $this->assertTrue(App::$coroutine_globals_isolation, 'backing prop is updated');
        $this->assertTrue(App::coroutineGlobalsIsolation(), 'no-arg getter reads it back');
    }

    public function testCoroutineGlobalsIsolationSetFalsePersists(): void
    {
        App::coroutineGlobalsIsolation(true);
        $this->assertFalse(App::coroutineGlobalsIsolation(false));
        $this->assertFalse(App::$coroutine_globals_isolation);
    }

    public function testCoroutineGlobalsIsolationNoArgIsGetterOnly(): void
    {
        App::$coroutine_globals_isolation = true;
        $this->assertTrue(App::coroutineGlobalsIsolation(), 'null arg must not mutate');
        $this->assertTrue(App::$coroutine_globals_isolation);
    }

    // ── keepGlobals() ────────────────────────────────────────────────────

    public function testKeepGlobalsDefaultIsFalse(): void
    {
        App::$keep_globals = false;
        $this->assertFalse(App::keepGlobals());
    }

    public function testKeepGlobalsSetTrueAndBackToFalse(): void
    {
        $this->assertTrue(App::keepGlobals(true));
        $this->assertTrue(App::$keep_globals);
        $this->assertFalse(App::keepGlobals(false));
        $this->assertFalse(App::$keep_globals);
    }

    public function testKeepGlobalsNoArgIsGetterOnly(): void
    {
        App::$keep_globals = true;
        $this->assertTrue(App::keepGlobals());
        $this->assertTrue(App::$keep_globals);
    }

    // ── silentRedeclare() ────────────────────────────────────────────────

    public function testSilentRedeclareDefaultIsFalse(): void
    {
        App::$silent_redeclare = false;
        $this->assertFalse(App::silentRedeclare());
    }

    public function testSilentRedeclareSetTruePersistsAndReturns(): void
    {
        $this->assertTrue(App::silentRedeclare(true));
        $this->assertTrue(App::$silent_redeclare);
        $this->assertTrue(App::silentRedeclare());
    }

    public function testSilentRedeclareSetFalsePersists(): void
    {
        App::silentRedeclare(true);
        $this->assertFalse(App::silentRedeclare(false));
        $this->assertFalse(App::$silent_redeclare);
    }

    public function testSilentRedeclareNoArgIsGetterOnly(): void
    {
        App::$silent_redeclare = true;
        $this->assertTrue(App::silentRedeclare());
        $this->assertTrue(App::$silent_redeclare);
    }

    // ── includeIsolation() ───────────────────────────────────────────────

    public function testIncludeIsolationDefaultIsFalse(): void
    {
        App::$include_isolation = false;
        $this->assertFalse(App::includeIsolation());
    }

    public function testIncludeIsolationRoundTrip(): void
    {
        $this->assertTrue(App::includeIsolation(true));
        $this->assertTrue(App::$include_isolation);
        $this->assertTrue(App::includeIsolation());
        $this->assertFalse(App::includeIsolation(false));
        $this->assertFalse(App::$include_isolation);
    }

    public function testIncludeIsolationNoArgIsGetterOnly(): void
    {
        App::$include_isolation = true;
        $this->assertTrue(App::includeIsolation());
        $this->assertTrue(App::$include_isolation);
    }

    // ── defineIsolation() ────────────────────────────────────────────────

    public function testDefineIsolationDefaultIsFalse(): void
    {
        App::$define_isolation = false;
        $this->assertFalse(App::defineIsolation());
    }

    public function testDefineIsolationRoundTrip(): void
    {
        $this->assertTrue(App::defineIsolation(true));
        $this->assertTrue(App::$define_isolation);
        $this->assertFalse(App::defineIsolation(false));
        $this->assertFalse(App::$define_isolation);
    }

    public function testDefineIsolationNoArgIsGetterOnly(): void
    {
        App::$define_isolation = true;
        $this->assertTrue(App::defineIsolation());
        $this->assertTrue(App::$define_isolation);
    }

    // ── coroutineStaticsIsolation() ──────────────────────────────────────

    public function testCoroutineStaticsIsolationDefaultIsFalse(): void
    {
        App::$coroutine_statics_isolation = false;
        $this->assertFalse(App::coroutineStaticsIsolation());
    }

    public function testCoroutineStaticsIsolationRoundTrip(): void
    {
        $this->assertTrue(App::coroutineStaticsIsolation(true));
        $this->assertTrue(App::$coroutine_statics_isolation);
        $this->assertTrue(App::coroutineStaticsIsolation());
        $this->assertFalse(App::coroutineStaticsIsolation(false));
        $this->assertFalse(App::$coroutine_statics_isolation);
    }

    public function testCoroutineStaticsIsolationNoArgIsGetterOnly(): void
    {
        App::$coroutine_statics_isolation = true;
        $this->assertTrue(App::coroutineStaticsIsolation());
        $this->assertTrue(App::$coroutine_statics_isolation);
    }

    // ── coroutineCwdIsolation() (#323) ───────────────────────────────────

    public function testCoroutineCwdIsolationDefaultIsFalse(): void
    {
        App::$coroutine_cwd_isolation = false;
        $this->assertFalse(App::coroutineCwdIsolation());
    }

    public function testCoroutineCwdIsolationRoundTrip(): void
    {
        $this->assertTrue(App::coroutineCwdIsolation(true));
        $this->assertTrue(App::$coroutine_cwd_isolation);
        $this->assertTrue(App::coroutineCwdIsolation());
        $this->assertFalse(App::coroutineCwdIsolation(false));
        $this->assertFalse(App::$coroutine_cwd_isolation);
    }

    public function testCoroutineCwdIsolationNoArgIsGetterOnly(): void
    {
        App::$coroutine_cwd_isolation = true;
        $this->assertTrue(App::coroutineCwdIsolation());
        $this->assertTrue(App::$coroutine_cwd_isolation);
        App::$coroutine_cwd_isolation = false;
    }

    // ── functionIsolation() ──────────────────────────────────────────────

    public function testFunctionIsolationDefaultIsFalse(): void
    {
        App::$function_isolation = false;
        $this->assertFalse(App::functionIsolation());
    }

    public function testFunctionIsolationRoundTrip(): void
    {
        $this->assertTrue(App::functionIsolation(true));
        $this->assertTrue(App::$function_isolation);
        $this->assertFalse(App::functionIsolation(false));
        $this->assertFalse(App::$function_isolation);
    }

    public function testFunctionIsolationNoArgIsGetterOnly(): void
    {
        App::$function_isolation = true;
        $this->assertTrue(App::functionIsolation());
        $this->assertTrue(App::$function_isolation);
    }

    // ── Each fluent setter is INDEPENDENT (no cross-talk) ────────────────

    public function testIsolationSettersAreIndependent(): void
    {
        App::$silent_redeclare            = false;
        App::$include_isolation           = false;
        App::$define_isolation            = false;
        App::$coroutine_globals_isolation = false;
        App::$coroutine_statics_isolation = false;
        App::$function_isolation          = false;
        App::$keep_globals                = false;

        // Flip ONLY include isolation — nothing else should move.
        App::includeIsolation(true);

        $this->assertTrue(App::includeIsolation());
        $this->assertFalse(App::silentRedeclare());
        $this->assertFalse(App::defineIsolation());
        $this->assertFalse(App::coroutineGlobalsIsolation());
        $this->assertFalse(App::coroutineStaticsIsolation());
        $this->assertFalse(App::functionIsolation());
        $this->assertFalse(App::keepGlobals());
    }

    // ── mode() preset → isolation-knob fan-out ───────────────────────────

    public function testModeCoroutineLegacyEnablesTheLegacyIsolationBundle(): void
    {
        App::mode(App::MODE_COROUTINE_LEGACY);
        $this->assertTrue(App::$superglobals, 'real $_GET/$_SESSION');
        $this->assertTrue(App::silentRedeclare(), 'silent redeclare on');
        $this->assertTrue(App::includeIsolation(), 'require_once isolation on');
        $this->assertTrue(App::coroutineGlobalsIsolation(), '$GLOBALS isolation on');
        // Stage 5 statics default ON unless ZEALPHP_FN_STATICS_DISABLE=1.
        $expectStatics = ((string) getenv('ZEALPHP_FN_STATICS_DISABLE')) !== '1';
        $this->assertSame($expectStatics, App::coroutineStaticsIsolation(), 'function statics follow env opt-out');
        // #323 CWD isolation default ON unless ZEALPHP_CWD_ISOLATION_DISABLE=1.
        $expectCwd = ((string) getenv('ZEALPHP_CWD_ISOLATION_DISABLE')) !== '1';
        $this->assertSame($expectCwd, App::coroutineCwdIsolation(), 'cwd isolation follows env opt-out (#323)');
    }

    public function testModeCoroutineDoesNotEnableLegacyBundle(): void
    {
        // Pre-set the legacy bundle on, then switch to plain coroutine mode.
        App::$silent_redeclare            = true;
        App::$include_isolation           = true;
        App::$coroutine_globals_isolation = true;

        App::mode(App::MODE_COROUTINE);

        $this->assertFalse(App::$superglobals, 'pure coroutine = per-coroutine $g');
        // Plain coroutine preset only sets superglobals + isolation(coroutine);
        // it does NOT touch the legacy-bundle knobs (so prior values survive).
        $this->assertTrue(App::silentRedeclare(), 'coroutine preset leaves silentRedeclare untouched');
        $this->assertTrue(App::includeIsolation(), 'coroutine preset leaves includeIsolation untouched');
        $this->assertTrue(App::coroutineGlobalsIsolation(), 'coroutine preset leaves globals isolation untouched');
    }

    public function testModeLegacyCgiLeavesCoroutineBundleAlone(): void
    {
        App::$silent_redeclare            = false;
        App::$include_isolation           = false;
        App::$coroutine_globals_isolation = false;

        App::mode(App::MODE_LEGACY_CGI);

        $this->assertTrue(App::$superglobals);
        // legacy-cgi is process isolation; it must NOT flip the coroutine bundle on.
        $this->assertFalse(App::silentRedeclare());
        $this->assertFalse(App::includeIsolation());
        $this->assertFalse(App::coroutineGlobalsIsolation());
    }

    public function testModeRejectsUnknownString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::mode('not-a-real-mode');
    }

    public function testModeRefusesAfterRunHasStarted(): void
    {
        App::$run_has_started = true;
        $this->expectException(\RuntimeException::class);
        App::mode(App::MODE_COROUTINE_LEGACY);
    }

    // ── coroutineGlobalsMemoryAdvisory() ─────────────────────────────────

    public function testMemoryAdvisoryRunsWithoutThrowing(): void
    {
        // Reads $GLOBALS + worker_num and emits via elog(); pure advisory,
        // no server required. Must complete and return void cleanly.
        App::coroutineGlobalsMemoryAdvisory();
        $this->addToAssertionCount(1);
    }

    public function testMemoryAdvisoryIsIdempotentAcrossCalls(): void
    {
        // Called twice — no per-call state, no growth, no throw.
        App::coroutineGlobalsMemoryAdvisory();
        App::coroutineGlobalsMemoryAdvisory();
        $this->addToAssertionCount(1);
    }
}
