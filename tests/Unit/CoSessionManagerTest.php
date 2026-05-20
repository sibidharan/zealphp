<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\Session\CoSessionManager;

/**
 * Unit tests for ZealPHP\Session\CoSessionManager — the per-coroutine
 * session lifecycle wrapper used in superglobals(false) mode.
 *
 * `__invoke()` requires a live OpenSwoole Http\Request / Http\Response pair
 * and the running worker's uopz session overrides, so it's exercised by the
 * Integration suite, not here. What IS cleanly unit-reachable is the
 * constructor's dependency capture and the ini-driven cookie-flag
 * resolution — both pinned below via reflection.
 */
class CoSessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = dirname(__DIR__, 2);
    }

    /**
     * @return mixed
     */
    private function prop(CoSessionManager $m, string $name)
    {
        $ref = new \ReflectionObject($m);
        $p = $ref->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($m);
    }

    public function testConstructorCapturesMiddleware(): void
    {
        $mw = function (\OpenSwoole\Http\Request $req, \OpenSwoole\Http\Response $res): void {
        };
        $m = new CoSessionManager($mw);
        $this->assertSame($mw, $this->prop($m, 'middleware'));
    }

    public function testDefaultIdGeneratorIsSessionCreateId(): void
    {
        $m = new CoSessionManager(function ($req, $res): void {
        });
        $this->assertSame('session_create_id', $this->prop($m, 'idGenerator'));
    }

    public function testCustomIdGeneratorIsCaptured(): void
    {
        $gen = static fn(): string => 'custom-id';
        $m = new CoSessionManager(function ($req, $res): void {
        }, $gen);
        $this->assertSame($gen, $this->prop($m, 'idGenerator'));
    }

    public function testExplicitCookieFlagsOverrideIni(): void
    {
        $m = new CoSessionManager(function ($req, $res): void {
        }, 'session_create_id', true, false);
        $this->assertTrue($this->prop($m, 'useCookies'));
        $this->assertFalse($this->prop($m, 'useOnlyCookies'));

        $m2 = new CoSessionManager(function ($req, $res): void {
        }, 'session_create_id', false, true);
        $this->assertFalse($this->prop($m2, 'useCookies'));
        $this->assertTrue($this->prop($m2, 'useOnlyCookies'));
    }

    public function testNullCookieFlagsFallBackToIniSettings(): void
    {
        // With null flags the constructor reads the corresponding ini
        // values and coerces them to bool. We assert it matches whatever
        // the runtime ini currently reports.
        $expectedUseCookies = (bool) ini_get('session.use_cookies');
        $expectedUseOnlyCookies = (bool) ini_get('session.use_only_cookies');

        $m = new CoSessionManager(function ($req, $res): void {
        });
        $this->assertSame($expectedUseCookies, $this->prop($m, 'useCookies'));
        $this->assertSame($expectedUseOnlyCookies, $this->prop($m, 'useOnlyCookies'));
    }

    public function testIsInvokable(): void
    {
        $m = new CoSessionManager(function ($req, $res): void {
        });
        // Reflection over the concrete type — asserts the __invoke contract
        // without an always-true is_callable()/method_exists() narrowing.
        $ref = new \ReflectionClass($m);
        $this->assertTrue($ref->hasMethod('__invoke'));
        $invoke = $ref->getMethod('__invoke');
        $params = $invoke->getParameters();
        $this->assertCount(2, $params, '__invoke takes Request + Response');
        $this->assertSame('request', $params[0]->getName());
        $this->assertSame('response', $params[1]->getName());
    }
}
