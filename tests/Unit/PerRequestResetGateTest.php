<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Issue #227 root fix — `App::perRequestStateResetsActive()` gates the
 * per-request state RESETS (`zealphp_reset_request_rtcaches` /
 * `_statics` / `_class_statics`, run in the session-manager `finally`) on BOTH
 * `silent_redeclare` AND an active isolation (include- or function-isolation,
 * which is what makes the boot snapshot fire). The snapshot is what exempts
 * framework class statics (`App::$middleware_stack`, `App::$routes`,
 * `Store`/`Counter` backends) from the reset.
 *
 * The pre-fix gate was `silent_redeclare` ALONE, so a bare `silentRedeclare(true)`
 * (no isolation → no snapshot) activated the resets and zeroed the middleware
 * stack on request 2+ ("handle() on null"), and could heap-corrupt other
 * framework statics. The gate truth table below pins the fix.
 */
final class PerRequestResetGateTest extends TestCase
{
    private static bool $origSr = false;
    private static bool $origFi = false;
    private static bool $origIi = false;

    public static function setUpBeforeClass(): void
    {
        self::$origSr = App::$silent_redeclare;
        self::$origFi = App::$function_isolation;
        self::$origIi = App::$include_isolation;
    }

    protected function tearDown(): void
    {
        App::$silent_redeclare   = self::$origSr;
        App::$function_isolation = self::$origFi;
        App::$include_isolation  = self::$origIi;
    }

    public static function tearDownAfterClass(): void
    {
        App::$silent_redeclare   = self::$origSr;
        App::$function_isolation = self::$origFi;
        App::$include_isolation  = self::$origIi;
    }

    /** The dangerous config (#227): declare-opcode hook only, no isolation, no snapshot. */
    public function testBareSilentRedeclareDoesNotActivateResets(): void
    {
        App::$silent_redeclare   = true;
        App::$function_isolation = false;
        App::$include_isolation  = false;
        $this->assertFalse(App::perRequestStateResetsActive());
    }

    /** coroutine-legacy shape: silentRedeclare + includeIsolation → snapshot taken → safe. */
    public function testCoroutineLegacyShapeActivatesResets(): void
    {
        App::$silent_redeclare   = true;
        App::$function_isolation = false;
        App::$include_isolation  = true;
        $this->assertTrue(App::perRequestStateResetsActive());
    }

    /** function-isolation also drives the snapshot, so it too makes the resets safe. */
    public function testFunctionIsolationAloneActivatesResets(): void
    {
        App::$silent_redeclare   = true;
        App::$function_isolation = true;
        App::$include_isolation  = false;
        $this->assertTrue(App::perRequestStateResetsActive());
    }

    /** No silent_redeclare → user symbols don't persist → resets are not needed. */
    public function testIsolationWithoutSilentRedeclareIsInactive(): void
    {
        App::$silent_redeclare   = false;
        App::$function_isolation = true;
        App::$include_isolation  = true;
        $this->assertFalse(App::perRequestStateResetsActive());
    }

    public function testAllOffIsInactive(): void
    {
        App::$silent_redeclare   = false;
        App::$function_isolation = false;
        App::$include_isolation  = false;
        $this->assertFalse(App::perRequestStateResetsActive());
    }
}
