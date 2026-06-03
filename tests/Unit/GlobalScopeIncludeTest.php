<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Stage 8 gate — `App::globalScopeInclude()`. When on (under coroutine-legacy,
 * with ext-zealphp's `zealphp_require_global()`), `App::include()` runs the
 * target file at true global scope. Off by default; `null` follows the
 * `ZEALPHP_GLOBAL_INCLUDE` env var. The capability check + the actual dispatch
 * live in `executeFile()` (needs the ext + a real include, exercised by the
 * WordPress coroutine-legacy e2e, not here).
 */
final class GlobalScopeIncludeTest extends TestCase
{
    private ?bool $savedGate;
    private bool $savedSilent;

    protected function setUp(): void
    {
        $this->savedGate = App::$global_scope_include;
        $this->savedSilent = App::$silent_redeclare;
        putenv('ZEALPHP_GLOBAL_INCLUDE');
    }

    protected function tearDown(): void
    {
        App::$global_scope_include = $this->savedGate;
        App::$silent_redeclare = $this->savedSilent;
        putenv('ZEALPHP_GLOBAL_INCLUDE');
    }

    public function testOffByDefaultWhenNullAndNoEnv(): void
    {
        App::$global_scope_include = null;
        putenv('ZEALPHP_GLOBAL_INCLUDE');
        $this->assertFalse(App::globalScopeInclude());
    }

    public function testNullFollowsEnvVar(): void
    {
        App::$global_scope_include = null;
        putenv('ZEALPHP_GLOBAL_INCLUDE=1');
        $this->assertTrue(App::globalScopeInclude());
    }

    public function testExplicitSetterWinsOverEnv(): void
    {
        putenv('ZEALPHP_GLOBAL_INCLUDE=1');   // env on...
        $this->assertFalse(App::globalScopeInclude(false));  // ...but explicit false wins
        $this->assertFalse(App::globalScopeInclude());

        $this->assertTrue(App::globalScopeInclude(true));
        $this->assertTrue(App::globalScopeInclude());
        $this->assertTrue(App::$global_scope_include);
    }

    /**
     * The private effective gate = setting AND coroutine-legacy. Outside
     * coroutine-legacy (silent_redeclare off) it must be false even when the
     * gate is explicitly on, because global-scope includes need the
     * per-coroutine globals isolation stack.
     */
    public function testEffectiveRequiresCoroutineLegacy(): void
    {
        $m = new \ReflectionMethod(App::class, 'globalScopeIncludeEffective');
        $m->setAccessible(true);

        App::globalScopeInclude(true);

        App::$silent_redeclare = false;
        $this->assertFalse($m->invoke(null), 'gate on but not coroutine-legacy → off');

        App::$silent_redeclare = true;
        $this->assertTrue($m->invoke(null), 'gate on + coroutine-legacy → on');

        App::globalScopeInclude(false);
        $this->assertFalse($m->invoke(null), 'gate off → off even in coroutine-legacy');
    }
}
