<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::runRequestStaticsBeginRefresh() — the request-BEGIN function-static
 * refresh (#28), the concurrency companion to the request-END
 * zealphp_reset_request_statics(). It is gated identically to the other
 * per-request resets (perRequestStateResetsActive()) and is a no-op when the
 * ext primitive is absent, so it stays safe to call unconditionally from the
 * session managers just before dispatch.
 *
 * The session managers' __invoke() needs a live OpenSwoole request/response
 * pair (Integration territory), so the call sites themselves aren't unit
 * reachable — this pins the extracted helper directly: the gate short-circuits
 * to 0 when resets are inactive, and the ext primitive is invoked (and its
 * count returned) when they are active and the function is available.
 */
final class AppRequestStaticsBeginRefreshTest extends TestCase
{
    private bool $origSr = false;
    private bool $origFi = false;
    private bool $origIi = false;

    protected function setUp(): void
    {
        $this->origSr = App::$silent_redeclare;
        $this->origFi = App::$function_isolation;
        $this->origIi = App::$include_isolation;
    }

    protected function tearDown(): void
    {
        App::$silent_redeclare  = $this->origSr;
        App::$function_isolation = $this->origFi;
        App::$include_isolation  = $this->origIi;
    }

    public function testReturnsFalseWhenResetsInactive(): void
    {
        // silent_redeclare off → perRequestStateResetsActive() is false → the
        // ext primitive is never consulted, even if it exists.
        App::$silent_redeclare   = false;
        App::$function_isolation = true;
        App::$include_isolation  = true;

        $this->assertFalse(App::perRequestStateResetsActive());
        $this->assertFalse(App::runRequestStaticsBeginRefresh());
    }

    public function testReturnsFalseWhenSilentRedeclareWithoutIsolation(): void
    {
        // #227 gate: silent_redeclare alone (no isolation) must NOT run resets.
        App::$silent_redeclare   = true;
        App::$function_isolation = false;
        App::$include_isolation  = false;

        $this->assertFalse(App::perRequestStateResetsActive());
        $this->assertFalse(App::runRequestStaticsBeginRefresh());
    }

    public function testInvokesExtPrimitiveWhenActive(): void
    {
        // Resets active (the coroutine-legacy shape). When the ext provides the
        // primitive (ext-zealphp 0.3.57+, as on CI) the refresh runs and returns
        // true (refreshing an empty registry is a safe no-op); when the ext is
        // older/absent the helper short-circuits to false via the function_exists
        // guard. Either way the gate evaluates true and the helper body executes.
        App::$silent_redeclare   = true;
        App::$function_isolation = true;
        App::$include_isolation  = false;

        $this->assertTrue(App::perRequestStateResetsActive());

        $ran = App::runRequestStaticsBeginRefresh();
        $this->assertSame(
            \function_exists('zealphp_reset_request_statics_begin'),
            $ran,
            'refresh runs iff the ext primitive is available'
        );
    }
}
