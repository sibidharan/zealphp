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
}
