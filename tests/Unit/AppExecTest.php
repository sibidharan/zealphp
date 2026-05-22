<?php
declare(strict_types=1);
namespace ZealPHP\Tests\Unit;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

final class AppExecTest extends TestCase
{
    public function testRawExecRunsBlockingAndReturnsOutput(): void
    {
        $this->assertSame("hi\n", App::rawExec('echo hi'));
    }

    public function testExecInCoroutineReturnsStructuredResult(): void
    {
        $result = null;
        \OpenSwoole\Coroutine::run(function () use (&$result) { $result = App::exec('echo hi'); });
        $this->assertIsArray($result);
        $this->assertSame("hi\n", $result['output']);
        $this->assertSame(0, $result['code']);
    }

    public function testExecOutsideCoroutineFallsBackWithoutError(): void
    {
        $result = App::exec('echo hi');           // no coroutine context
        $this->assertSame("hi\n", $result['output']);
        $this->assertSame(0, $result['code']);
    }

    public function testBacktickAndShellExecAreOverridable(): void
    {
        \uopz_set_return('shell_exec', fn($c) => "OVR[$c]", true);
        $this->assertSame('OVR[echo hi]', shell_exec('echo hi'));
        $this->assertSame('OVR[echo hi]', `echo hi`);
        \uopz_unset_return('shell_exec');
    }

    public function testHookedBacktickRoutesThroughAppExecInCoroutine(): void
    {
        \uopz_set_return('shell_exec', \Closure::fromCallable('\ZealPHP\zeal_shell_exec'), true);
        $out = null;
        \OpenSwoole\Coroutine::run(function () use (&$out) { $out = `echo hooked`; });
        $this->assertSame("hooked\n", $out);
        \uopz_unset_return('shell_exec');
    }

    public function testHookExecGetterSetterRoundTrips(): void
    {
        $original = App::hookExec();
        App::hookExec(true);
        $this->assertTrue(App::hookExec());
        App::hookExec(false);
        $this->assertFalse(App::hookExec());
        App::$hook_exec = $original; // restore
    }
}
