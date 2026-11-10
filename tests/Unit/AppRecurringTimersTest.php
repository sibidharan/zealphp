<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::clearRecurringTimers() runs on workerExit. A recurring timer always has
 * a next run, so a stopping worker that had one waited out max_wait_time (3 s)
 * and was then killed.
 */
final class AppRecurringTimersTest extends TestCase
{
    public function testClearsRecurringTimersAndLeavesOneShotsAndSleeps(): void
    {
        $result = [];
        Coroutine::run(static function () use (&$result): void {
            $tick = App::tick(60000, static fn () => null);
            $raw = Timer::tick(60000, static fn () => null);
            $once = App::after(50, static function () use (&$result): void { $result['after'] = true; });
            Coroutine::create(static function () use (&$result): void {
                Coroutine::usleep(30000);
                $result['slept'] = true;
            });

            $result['cleared'] = App::clearRecurringTimers();
            $result['tick'] = Timer::exists($tick);
            $result['raw'] = Timer::exists($raw);
            $result['once'] = Timer::exists($once);
            Coroutine::usleep(100000);
        });

        $this->assertSame(2, $result['cleared']);
        $this->assertFalse($result['tick'], 'App::tick() timer cleared');
        $this->assertFalse($result['raw'], 'OpenSwoole\Timer::tick() timer cleared');
        $this->assertTrue($result['once'], 'one-shot timer kept');
        $this->assertTrue($result['after'] ?? false, 'one-shot timer still fired');
        $this->assertTrue($result['slept'] ?? false, 'a sleeping coroutine still woke up');
    }

    public function testNothingToClearOutsideAScheduler(): void
    {
        $this->assertSame(0, App::clearRecurringTimers());
    }
}
