<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::onProcess — sidecar process registration semantics.
 *
 * Actually spawning the process requires a live server; we exercise the
 * REGISTRATION + VALIDATION surface here. The actual fork-and-execute
 * path is integration-test territory (run a server with a sidecar
 * registered and verify the sidecar process is alive).
 */
final class AppOnProcessTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear the registry before each test (it's a process-wide static).
        $r = new \ReflectionProperty(App::class, 'processHandlers');
        $r->setAccessible(true);
        $r->setValue(null, []);
        $bw = new \ReflectionProperty(App::class, 'processBootWired');
        $bw->setAccessible(true);
        $bw->setValue(null, false);
    }

    public function testRegistrationStoresHandler(): void
    {
        $fn = function (\OpenSwoole\Process $p): void { /* sidecar body */ };
        App::onProcess('log-shipper', $fn);

        $r = new \ReflectionProperty(App::class, 'processHandlers');
        $r->setAccessible(true);
        /** @var array<string, array{callable: callable, workers: int, coroutine: bool}> $reg */
        $reg = $r->getValue();
        self::assertArrayHasKey('log-shipper', $reg);
        self::assertSame(1, $reg['log-shipper']['workers']);
        self::assertTrue($reg['log-shipper']['coroutine']);
    }

    public function testWorkersCountIsHonored(): void
    {
        App::onProcess('queue-runner', fn() => null, workers: 3);
        $r = new \ReflectionProperty(App::class, 'processHandlers');
        $r->setAccessible(true);
        /** @var array<string, array{callable: callable, workers: int, coroutine: bool}> $reg */
        $reg = $r->getValue();
        self::assertSame(3, $reg['queue-runner']['workers']);
    }

    public function testCoroutineFlagDefaultsTrue(): void
    {
        App::onProcess('sync-job', fn() => null, coroutine: false);
        $r = new \ReflectionProperty(App::class, 'processHandlers');
        $r->setAccessible(true);
        /** @var array<string, array{callable: callable, workers: int, coroutine: bool}> $reg */
        $reg = $r->getValue();
        self::assertFalse($reg['sync-job']['coroutine']);
    }

    public function testDuplicateNameThrows(): void
    {
        App::onProcess('shipper', fn() => null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/already registered/");
        App::onProcess('shipper', fn() => null);
    }

    public function testZeroWorkersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\\$workers must be >= 1/');
        App::onProcess('bad', fn() => null, workers: 0);
    }
}
