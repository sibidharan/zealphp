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

    public function testZealSystemEchoesAllOutputAndReturnsLastLine(): void
    {
        $code = null;
        ob_start();
        $ret = \ZealPHP\zeal_system("printf 'a\\nb\\nc\\n'", $code);
        $echoed = ob_get_clean();
        $this->assertSame("a\nb\nc\n", $echoed);
        $this->assertSame('c', $ret);          // last line of output
        $this->assertSame(0, $code);
    }

    public function testZealSystemWritesExitCodeByReference(): void
    {
        // Outside a coroutine App::exec() falls back to rawExec(), which reports
        // code 0 (proc_open's exit status isn't threaded back on that path). The
        // contract we pin here is that $code is written by reference at all.
        $code = null;
        ob_start();
        \ZealPHP\zeal_system('echo hi', $code);
        ob_end_clean();
        $this->assertIsInt($code);
        $this->assertSame(0, $code);
    }

    public function testZealPassthruEchoesRawOutputAndSetsCode(): void
    {
        $code = null;
        ob_start();
        \ZealPHP\zeal_passthru("printf 'raw-out'", $code);
        $echoed = ob_get_clean();
        $this->assertSame('raw-out', $echoed);
        $this->assertSame(0, $code);
    }

    public function testZealExecAppendsLinesAndReturnsLast(): void
    {
        $output = [];
        $code = null;
        $ret = \ZealPHP\zeal_exec("printf 'one\\ntwo\\nthree\\n'", $output, $code);
        $this->assertSame(['one', 'two', 'three'], $output);
        $this->assertSame('three', $ret);      // builtin returns last line
        $this->assertSame(0, $code);
    }

    public function testZealExecAppendsToExistingOutputArray(): void
    {
        $output = ['preexisting'];
        $code = null;
        \ZealPHP\zeal_exec("printf 'added\\n'", $output, $code);
        $this->assertSame(['preexisting', 'added'], $output);
    }

    public function testZealShellExecReturnsNullWhenNoOutputAndFails(): void
    {
        // No output + non-zero exit -> builtin shell_exec returns null. The real
        // exit code is only available inside a coroutine (System::exec), so run
        // there to exercise the null branch.
        $out = 'sentinel';
        \OpenSwoole\Coroutine::run(function () use (&$out) { $out = \ZealPHP\zeal_shell_exec('exit 1'); });
        $this->assertNull($out);
    }

    public function testZealShellExecReturnsOutputOnSuccess(): void
    {
        $this->assertSame("ok\n", \ZealPHP\zeal_shell_exec('echo ok'));
    }
}
