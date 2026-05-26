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

    public function testOverrideBuiltinUopzPathExecutes(): void
    {
        if (extension_loaded('zealphp')) {
            $this->markTestSkipped('ext-zealphp loaded — uopz path not taken');
        }
        $this->assertTrue(function_exists('uopz_set_return'));

        $this->method->invoke(null, 'http_response_code', '\ZealPHP\http_response_code');
        // The override routes to ZealPHP's implementation — just verify no crash
        http_response_code();
        $this->addToAssertionCount(1);
    }

    public function testOverrideBuiltinAllResponseFunctions(): void
    {
        $funcs = [
            'header' => '\ZealPHP\header',
            'header_remove' => '\ZealPHP\header_remove',
            'headers_list' => '\ZealPHP\headers_list',
            'headers_sent' => '\ZealPHP\headers_sent',
            'setcookie' => '\ZealPHP\setcookie',
            'setrawcookie' => '\ZealPHP\setrawcookie',
            'http_response_code' => '\ZealPHP\http_response_code',
        ];

        foreach ($funcs as $name => $callable) {
            $this->method->invoke(null, $name, $callable);
            $this->addToAssertionCount(1);
        }
    }

    public function testOverrideBuiltinAllOutputFunctions(): void
    {
        $funcs = [
            'flush' => '\ZealPHP\flush',
            'ob_flush' => '\ZealPHP\ob_flush',
            'ob_end_flush' => '\ZealPHP\ob_end_flush',
            'ob_implicit_flush' => '\ZealPHP\ob_implicit_flush',
        ];

        foreach ($funcs as $name => $callable) {
            $this->method->invoke(null, $name, $callable);
            $this->addToAssertionCount(1);
        }
    }

    public function testOverrideBuiltinErrorAndMiscFunctions(): void
    {
        $funcs = [
            'error_log' => '\ZealPHP\error_log',
            'error_reporting' => '\ZealPHP\error_reporting',
            'register_shutdown_function' => '\ZealPHP\register_shutdown_function',
            'phpinfo' => '\ZealPHP\phpinfo',
            'php_sapi_name' => '\ZealPHP\php_sapi_name',
            'connection_status' => '\ZealPHP\connection_status',
            'connection_aborted' => '\ZealPHP\connection_aborted',
        ];

        foreach ($funcs as $name => $callable) {
            $this->method->invoke(null, $name, $callable);
            $this->addToAssertionCount(1);
        }
    }

    public function testValidateLifecycleCombinationAllowsSgCoroutineWithExtZealphp(): void
    {
        $validator = new \ReflectionMethod(App::class, 'validateLifecycleCombination');
        $validator->setAccessible(true);

        if (extension_loaded('zealphp')) {
            // Should NOT throw — ext-zealphp makes it safe
            $validator->invoke(null, true, 0, true);
            $this->addToAssertionCount(1);
        } else {
            // Should throw — no ext-zealphp to make it safe
            $this->expectException(\RuntimeException::class);
            $validator->invoke(null, true, 0, true);
        }
    }

    public function testValidateLifecycleCombinationSafeModesNeverThrow(): void
    {
        $validator = new \ReflectionMethod(App::class, 'validateLifecycleCombination');
        $validator->setAccessible(true);

        // superglobals(false) + coroutines = always safe
        $validator->invoke(null, false, \OpenSwoole\Runtime::HOOK_ALL, true);
        $this->addToAssertionCount(1);

        // superglobals(true) + no coroutines = always safe
        $validator->invoke(null, true, 0, false);
        $this->addToAssertionCount(1);

        // superglobals(false) + no hooks + no coroutines = always safe
        $validator->invoke(null, false, 0, false);
        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testRegisterAllOverridesCoversEveryLine(): void
    {
        $method = new \ReflectionMethod(App::class, 'registerAllOverrides');
        $method->setAccessible(true);
        $method->invoke(null);

        // Verify a few overrides are active
        $this->assertFalse(headers_sent());
        $sapiName = php_sapi_name();
        $this->assertIsString($sapiName);
    }

    public function testConstructorAcceptsEitherExtension(): void
    {
        $this->assertTrue(
            extension_loaded('zealphp') || extension_loaded('uopz'),
            'Constructor requires at least one override extension'
        );
    }

    public function testRegisterAllOverridesIsIdempotent(): void
    {
        $prop = new \ReflectionProperty(App::class, 'overridesRegistered');
        $prop->setAccessible(true);

        // First call sets the flag
        $method = new \ReflectionMethod(App::class, 'registerAllOverrides');
        $method->setAccessible(true);
        $method->invoke(null);
        $this->assertTrue($prop->getValue());

        // Second call is a no-op (no warnings, no errors)
        $method->invoke(null);
        $this->assertTrue($prop->getValue());
    }
}
