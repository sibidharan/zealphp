<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Pins the App::onWorkerStart / onWorkerStop per-worker hook registration
 * (the onWorkerStop hook is the reliable place to flush per-worker state on
 * OpenSwoole's signal-driven worker shutdown — used by the coverage harness).
 */
class AppWorkerHooksTest extends TestCase
{
    /** @return array<int, callable> */
    private function hooks(string $prop): array
    {
        $p = new \ReflectionProperty(App::class, $prop);
        $p->setAccessible(true);
        /** @var array<int, callable> $v */
        $v = $p->getValue();
        return $v;
    }

    private function setHooks(string $prop, array $value): void
    {
        $p = new \ReflectionProperty(App::class, $prop);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    public function testOnWorkerStopRegistersCallable(): void
    {
        $before = $this->hooks('workerStopHooks');
        try {
            $fn = function ($server, $workerId): void {};
            App::onWorkerStop($fn);
            $after = $this->hooks('workerStopHooks');
            $this->assertCount(count($before) + 1, $after);
            $this->assertSame($fn, end($after));
        } finally {
            $this->setHooks('workerStopHooks', $before);
        }
    }

    public function testOnWorkerStartRegistersCallable(): void
    {
        $before = $this->hooks('workerStartHooks');
        try {
            $fn = function ($server, $workerId): void {};
            App::onWorkerStart($fn);
            $after = $this->hooks('workerStartHooks');
            $this->assertCount(count($before) + 1, $after);
            $this->assertSame($fn, end($after));
        } finally {
            $this->setHooks('workerStartHooks', $before);
        }
    }

    public function testStartAndStopHooksAreSeparateStacks(): void
    {
        $startBefore = $this->hooks('workerStartHooks');
        $stopBefore  = $this->hooks('workerStopHooks');
        try {
            App::onWorkerStart(fn($s, $w) => null);
            $this->assertCount(count($startBefore) + 1, $this->hooks('workerStartHooks'));
            $this->assertCount(count($stopBefore), $this->hooks('workerStopHooks'));
        } finally {
            $this->setHooks('workerStartHooks', $startBefore);
            $this->setHooks('workerStopHooks', $stopBefore);
        }
    }
}
