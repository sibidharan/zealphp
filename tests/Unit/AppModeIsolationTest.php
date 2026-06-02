<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\Isolation;

/**
 * App::isolation() + App::mode() — the high-level lifecycle API that folds the
 * (processIsolation × enableCoroutine × hookAll × cgiMode) cross-product into
 * one intention-revealing knob, with mode() presets on top. Both are pure sugar
 * over the existing fine-grained setters, which keep working unchanged.
 */
final class AppModeIsolationTest extends TestCase
{
    private static bool $origSg = true;
    private static ?bool $origPi = null;
    private static ?bool $origEc = null;
    /** @var bool|int|null */
    private static $origHa = null;
    private static string $origCgiMode = 'pool';
    private static bool $origSr = false;
    private static bool $origIi = false;
    private static int $origCpmr = 500;
    private static bool $origCpmrSet = false;

    public static function setUpBeforeClass(): void
    {
        self::$origSg      = App::$superglobals;
        self::$origPi      = App::$process_isolation;
        self::$origEc      = App::$enable_coroutine_override;
        self::$origHa      = App::$hook_all_override;
        self::$origCgiMode = App::$cgi_mode;
        self::$origSr      = App::$silent_redeclare;
        self::$origIi      = App::$include_isolation;
        self::$origCpmr    = App::$cgi_pool_max_requests;
        self::$origCpmrSet = App::$cgi_pool_max_requests_set;
    }

    protected function setUp(): void
    {
        App::$run_has_started = false;
        // Fresh recycle baseline per test (500 = the untouched default).
        App::$cgi_pool_max_requests = 500;
        App::$cgi_pool_max_requests_set = false;
    }

    protected function tearDown(): void
    {
        App::$run_has_started           = false;
        App::$superglobals              = self::$origSg;
        App::$process_isolation         = self::$origPi;
        App::$enable_coroutine_override = self::$origEc;
        App::$hook_all_override         = self::$origHa;
        App::$cgi_mode                  = self::$origCgiMode !== '' ? self::$origCgiMode : 'pool';
        App::$silent_redeclare          = self::$origSr;
        App::$include_isolation         = self::$origIi;
        App::$coroutine_globals_isolation = false;
        App::$cgi_pool_max_requests     = self::$origCpmr;
        App::$cgi_pool_max_requests_set = self::$origCpmrSet;
    }

    // ── mode('legacy-cgi') defaults to recycle=1 (issue #167) ────────────

    public function testLegacyCgiDefaultsToFreshProcessPerRequest(): void
    {
        // Unmodified WordPress/Drupal re-declare classes on a reused subprocess
        // → "Cannot redeclare class". legacy-cgi must default to recycle=1.
        App::mode(App::MODE_LEGACY_CGI);
        $this->assertSame(1, App::cgiPoolMaxRequests(), 'legacy-cgi defaults to a fresh subprocess per request');
    }

    public function testExplicitRecycleBeforeModeIsRespected(): void
    {
        App::cgiPoolMaxRequests(50);
        App::mode(App::MODE_LEGACY_CGI);
        $this->assertSame(50, App::cgiPoolMaxRequests(), 'an explicit recycle set BEFORE mode() is not clobbered');
    }

    public function testExplicitRecycleAfterModeIsRespected(): void
    {
        App::mode(App::MODE_LEGACY_CGI);
        App::cgiPoolMaxRequests(50);
        $this->assertSame(50, App::cgiPoolMaxRequests(), 'an explicit recycle set AFTER mode() wins');
    }

    public function testOtherModesDoNotForceRecycleOne(): void
    {
        App::$cgi_pool_max_requests = 500;
        App::$cgi_pool_max_requests_set = false;
        App::mode(App::MODE_COROUTINE);
        $this->assertSame(500, App::cgiPoolMaxRequests(), 'non-legacy-cgi modes leave the recycle default untouched');
    }

    // ── isolation() getter: defaults follow superglobals ────────────────

    public function testDefaultIsolationFollowsSuperglobalsTrue(): void
    {
        App::$superglobals = true; App::$process_isolation = null;
        App::$enable_coroutine_override = null; App::$hook_all_override = null;
        $this->assertSame(App::ISOLATION_CGI_POOL, App::isolation(), 'sg=true defaults to cgi-pool (Legacy CGI)');
    }

    public function testDefaultIsolationFollowsSuperglobalsFalse(): void
    {
        App::$superglobals = false; App::$process_isolation = null;
        App::$enable_coroutine_override = null; App::$hook_all_override = null;
        $this->assertSame(App::ISOLATION_COROUTINE, App::isolation(), 'sg=false defaults to coroutine');
    }

    // ── isolation() setter mapping ──────────────────────────────────────

    public function testIsolationCoroutine(): void
    {
        App::isolation(Isolation::Coroutine);
        $this->assertFalse(App::processIsolation());
        $this->assertTrue(App::enableCoroutine());
        $this->assertNotSame(0, App::hookAll(), 'coroutine enables HOOK_ALL');
        $this->assertSame(App::ISOLATION_COROUTINE, App::isolation());
    }

    public function testIsolationCgiPool(): void
    {
        App::isolation('cgi-pool');
        $this->assertTrue(App::processIsolation());
        $this->assertFalse(App::enableCoroutine());
        $this->assertSame(0, App::hookAll());
        $this->assertSame('pool', App::cgiMode());
        $this->assertSame(App::ISOLATION_CGI_POOL, App::isolation());
    }

    public function testIsolationCgiProc(): void
    {
        App::isolation(App::ISOLATION_CGI_PROC);
        $this->assertTrue(App::processIsolation());
        $this->assertSame('proc', App::cgiMode());
        $this->assertSame(App::ISOLATION_CGI_PROC, App::isolation());
    }

    public function testIsolationCgiFcgi(): void
    {
        App::isolation(Isolation::CgiFcgi);
        $this->assertTrue(App::processIsolation());
        $this->assertSame('fcgi', App::cgiMode());
        $this->assertSame(App::ISOLATION_CGI_FCGI, App::isolation());
    }

    public function testIsolationNone(): void
    {
        App::isolation('none');
        $this->assertFalse(App::processIsolation());
        $this->assertFalse(App::enableCoroutine());
        $this->assertSame(0, App::hookAll());
        $this->assertSame(App::ISOLATION_NONE, App::isolation());
    }

    /** "no strong" — constant, enum, and bare string all accepted + equivalent. */
    public function testIsolationAcceptsConstantEnumAndString(): void
    {
        App::isolation(App::ISOLATION_CGI_PROC); $viaConst = App::isolation();
        App::isolation(Isolation::CgiProc);      $viaEnum  = App::isolation();
        App::isolation('cgi-proc');              $viaStr   = App::isolation();
        $this->assertSame($viaConst, $viaEnum);
        $this->assertSame($viaEnum, $viaStr);
        $this->assertSame(App::ISOLATION_CGI_PROC, $viaStr);
    }

    public function testIsolationRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::isolation('bogus');
    }

    // ── mode() presets: set BOTH axes ───────────────────────────────────

    public function testModeLegacyCgi(): void
    {
        App::mode(App::MODE_LEGACY_CGI);
        $this->assertTrue(App::$superglobals);
        $this->assertSame(App::ISOLATION_CGI_POOL, App::isolation());
        $this->assertTrue(App::processIsolation());
        $this->assertFalse(App::enableCoroutine());
    }

    public function testModeCoroutine(): void
    {
        App::mode('coroutine');
        $this->assertFalse(App::$superglobals);
        $this->assertSame(App::ISOLATION_COROUTINE, App::isolation());
        $this->assertTrue(App::enableCoroutine());
    }

    public function testModeMixed(): void
    {
        App::mode(App::MODE_MIXED);
        $this->assertTrue(App::$superglobals);
        $this->assertSame(App::ISOLATION_NONE, App::isolation());
        $this->assertFalse(App::processIsolation());
        $this->assertFalse(App::enableCoroutine());
    }

    public function testModeCoroutineLegacyIsMode4PlusLegacyBundle(): void
    {
        App::mode(App::MODE_COROUTINE_LEGACY);
        $this->assertTrue(App::$superglobals, 'sg=true — real $_GET/$_SESSION');
        $this->assertSame(App::ISOLATION_COROUTINE, App::isolation(), 'coroutine isolation (Mode 4)');
        $this->assertFalse(App::processIsolation(), 'pi=false — no CGI subprocess (the combo-fix shape)');
        $this->assertTrue(App::enableCoroutine(), 'coroutines on');
        $this->assertTrue(App::silentRedeclare(), 'legacy bundle: silent redeclare');
        $this->assertTrue(App::includeIsolation(), 'legacy bundle: require_once isolation');
        $this->assertTrue(App::coroutineGlobalsIsolation(), 'legacy bundle: per-coroutine $GLOBALS isolation ($wp/$wpdb)');
    }

    public function testModeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::mode('bogus-mode');
    }

    // ── BC: fine-grained setters still work alongside the new API ───────

    public function testFineGrainedSettersStillOverrideAfterMode(): void
    {
        App::mode(App::MODE_LEGACY_CGI);          // sg=true, cgi-pool
        App::cgiMode('proc');                      // power-user override on top
        $this->assertSame(App::ISOLATION_CGI_PROC, App::isolation(), 'fine-grained cgiMode() still composes');
    }

    // ── Isolation enum unit behavior ────────────────────────────────────

    public function testIsolationEnumHelpers(): void
    {
        $this->assertTrue(Isolation::CgiPool->isProcess());
        $this->assertTrue(Isolation::CgiProc->isProcess());
        $this->assertTrue(Isolation::CgiFcgi->isProcess());
        $this->assertFalse(Isolation::Coroutine->isProcess());
        $this->assertFalse(Isolation::None->isProcess());
        $this->assertSame(\ZealPHP\CgiMode::Proc, Isolation::CgiProc->cgiMode());
        $this->assertNull(Isolation::Coroutine->cgiMode());
        $this->assertSame(Isolation::CgiFcgi, Isolation::coerce('cgi-fcgi'));
        $this->assertSame(Isolation::Coroutine, Isolation::coerce(Isolation::Coroutine));
    }
}
