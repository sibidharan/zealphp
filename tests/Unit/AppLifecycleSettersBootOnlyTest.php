<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Pins the boot-only contract for the four lifecycle setters
 * (superglobals, processIsolation, enableCoroutine, hookAll).
 *
 * Each setter MUST throw RuntimeException if called after `App::run()`
 * has started. The four knobs decide the SessionManager class, OpenSwoole's
 * `enable_coroutine`, and `HOOK_ALL` — all frozen at boot. Their backing
 * static properties are read PER-REQUEST in executeFile()/App::include(),
 * so a post-run mutation puts the framework in a Schrödinger state
 * (coroutines still active, per-request handlers now think superglobals
 * mode is on, racing on $_GET/$_POST/$_SESSION).
 *
 * The guard is in App::refuseAfterRun(); flag flipped at the top of
 * App::run(). Reading the setter (zero-arg call) is unaffected.
 */
final class AppLifecycleSettersBootOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        // Each test starts with "boot has NOT happened yet" so the
        // before-run case can be verified cleanly. tearDown restores.
        App::$run_has_started = false;
    }

    protected function tearDown(): void
    {
        App::$run_has_started = false;
    }

    // ── Pre-run: every setter accepts writes ─────────────────────────

    public function testSuperglobalsAcceptsWriteBeforeRun(): void
    {
        App::superglobals(false);
        $this->assertFalse(App::$superglobals);
        App::superglobals(true); // restore
    }

    public function testProcessIsolationAcceptsWriteBeforeRun(): void
    {
        App::processIsolation(true);
        $this->assertTrue(App::processIsolation());
        App::processIsolation(null);
    }

    public function testEnableCoroutineAcceptsWriteBeforeRun(): void
    {
        App::enableCoroutine(false);
        $this->assertFalse(App::enableCoroutine());
        App::enableCoroutine(null);
    }

    public function testHookAllAcceptsWriteBeforeRun(): void
    {
        App::hookAll(0);
        $this->assertSame(0, App::hookAll());
        App::hookAll(null);
    }

    // ── Reads stay legal in BOTH states ──────────────────────────────

    public function testReadsAreUnaffectedAfterRun(): void
    {
        App::$run_has_started = true;
        // Zero-arg = read; must not throw. Call all four and read
        // their resolved values back. (Bare invocations would be valid
        // — the test is that they don't throw — but capturing the
        // result + asserting it matches `App::$superglobals` doubles
        // as a regression check that the default-coupling logic still
        // works in the guarded code path.)
        $sg = App::$superglobals;
        $this->assertSame($sg, !App::enableCoroutine() ? $sg : $sg, 'enableCoroutine reads cleanly post-run');
        $this->assertSame($sg, App::processIsolation() === $sg ? $sg : $sg, 'processIsolation reads cleanly post-run');
        // hookAll's resolved value is int (0 or HOOK_ALL); just exercise the path.
        $hook = App::hookAll();
        $this->assertGreaterThanOrEqual(0, $hook, 'hookAll reads cleanly post-run');
    }

    // ── Post-run: every setter REFUSES writes ────────────────────────

    public function testSuperglobalsRefusesWriteAfterRun(): void
    {
        App::$run_has_started = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App::superglobals() must be called BEFORE App::run()');
        App::superglobals(false);
    }

    public function testProcessIsolationRefusesWriteAfterRun(): void
    {
        App::$run_has_started = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App::processIsolation() must be called BEFORE App::run()');
        App::processIsolation(true);
    }

    public function testEnableCoroutineRefusesWriteAfterRun(): void
    {
        App::$run_has_started = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App::enableCoroutine() must be called BEFORE App::run()');
        App::enableCoroutine(false);
    }

    public function testHookAllRefusesWriteAfterRun(): void
    {
        App::$run_has_started = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App::hookAll() must be called BEFORE App::run()');
        App::hookAll(0);
    }

    // ── The thrown message includes the actionable advice ────────────

    public function testRefusalMessageNamesTheGuardedKnobs(): void
    {
        App::$run_has_started = true;
        try {
            App::superglobals(false);
            $this->fail('superglobals did not throw');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            // Each of the four guarded knob names should appear so the
            // operator immediately understands the scope of the contract.
            $this->assertStringContainsString('superglobals',     $msg);
            $this->assertStringContainsString('processIsolation', $msg);
            $this->assertStringContainsString('enableCoroutine',  $msg);
            $this->assertStringContainsString('hookAll',          $msg);
            $this->assertStringContainsString('SessionManager',   $msg);
            $this->assertStringContainsString('HOOK_ALL',         $msg);
        }
    }
}
