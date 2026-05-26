<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Tests for App::overrideBuiltin() — the ext-zealphp / uopz auto-detection layer.
 */
final class OverrideBuiltinTest extends TestCase
{
    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        $this->method = new \ReflectionMethod(App::class, 'overrideBuiltin');
        $this->method->setAccessible(true);
    }

    public function testOverrideBuiltinIsCallable(): void
    {
        $this->assertTrue($this->method->isStatic());
        $this->assertCount(2, $this->method->getParameters());
    }

    public function testOverrideRoutesToAvailableExtension(): void
    {
        $hasZealphp = extension_loaded('zealphp');
        $hasUopz = function_exists('uopz_set_return');

        $this->assertTrue(
            $hasZealphp || $hasUopz,
            'At least one override extension (zealphp or uopz) must be loaded'
        );

        // Call overrideBuiltin on a function we can verify
        $this->method->invoke(null, 'headers_sent', '\ZealPHP\headers_sent');

        // The function should now be overridden — calling it should route
        // to ZealPHP's implementation which returns false (no headers sent
        // outside a request context)
        $result = headers_sent();
        $this->assertFalse($result);
    }

    public function testOverrideAcceptsAllSessionFunctions(): void
    {
        $sessionFuncs = [
            'session_cache_limiter' => '\ZealPHP\Session\zeal_session_cache_limiter',
            'session_cache_expire'  => '\ZealPHP\Session\zeal_session_cache_expire',
            'session_module_name'   => '\ZealPHP\Session\zeal_session_module_name',
            'session_save_path'     => '\ZealPHP\Session\zeal_session_save_path',
        ];

        foreach ($sessionFuncs as $name => $callable) {
            // Should not throw — all session functions are in the allowlist
            $this->method->invoke(null, $name, $callable);
            $this->addToAssertionCount(1);
        }
    }

    public function testOverrideAcceptsExecFamily(): void
    {
        if (!function_exists('\ZealPHP\zeal_shell_exec')) {
            $this->markTestSkipped('Exec overrides not defined (loaded outside App boot)');
        }

        $execFuncs = [
            'shell_exec' => '\ZealPHP\zeal_shell_exec',
            'exec'       => '\ZealPHP\zeal_exec',
            'system'     => '\ZealPHP\zeal_system',
            'passthru'   => '\ZealPHP\zeal_passthru',
        ];

        foreach ($execFuncs as $name => $callable) {
            $this->method->invoke(null, $name, $callable);
            $this->addToAssertionCount(1);
        }
    }

    public function testDetectsExtZealphpWhenLoaded(): void
    {
        if (!extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp not loaded');
        }

        $this->assertTrue(function_exists('zealphp_override'));
        $this->assertTrue(function_exists('zealphp_restore'));
        $this->assertTrue(function_exists('zealphp_restore_all'));
    }

    public function testFallsBackToUopzWhenZealphpNotLoaded(): void
    {
        if (extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp is loaded — fallback path not exercised');
        }

        $this->assertTrue(
            function_exists('uopz_set_return'),
            'uopz must be loaded when ext-zealphp is not'
        );
    }
}
