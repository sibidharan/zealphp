<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Runtime;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * App::parallel / App::parallelLimit — fork-join + bounded fan-out.
 *
 * These tests run in CLI mode (sync context). App::parallel detects the
 * missing coroutine context and wraps itself in `Coroutine::run()`.
 * HOOK_ALL must be enabled so `usleep` yields to other coroutines —
 * that's the actual proof of parallelism (3 × 100ms sleeps complete in
 * ~100ms wall-clock instead of 300ms).
 */
final class AppParallelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // PHPUnit doesn't enable HOOK_ALL on its own; force it once for the
        // suite so usleep yields. The OpenSwoole signature accepts bool (legacy)
        // or int flags; passing HOOK_ALL is the documented modern form.
        Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
    }

    public function testEmptyTasksReturnsEmptyArray(): void
    {
        // Type the empty argument so PHPStan can resolve the @template.
        $empty = [];
        /** @var list<callable(): mixed> $empty */
        self::assertSame([], App::parallel($empty));
    }

    public function testThreeTasksRunInParallel(): void
    {
        $t0 = microtime(true);
        $results = App::parallel([
            function (): string { usleep(100_000); return 'a'; },
            function (): string { usleep(100_000); return 'b'; },
            function (): string { usleep(100_000); return 'c'; },
        ]);
        $elapsed = (microtime(true) - $t0) * 1000;

        self::assertSame(['a', 'b', 'c'], $results);
        self::assertLessThan(200, $elapsed, "expected ~100ms parallel; got {$elapsed}ms (sequential would be 300)");
    }

    public function testResultsPreserveInputOrder(): void
    {
        $results = App::parallel([
            function (): int { usleep(150_000); return 1; },  // intentionally slowest
            function (): int { usleep(50_000);  return 2; },
            function (): int { usleep(100_000); return 3; },
        ]);
        self::assertSame([1, 2, 3], $results, 'order-preserving regardless of completion order');
    }

    public function testExceptionInOneTaskPropagatesToCaller(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kaboom');
        App::parallel([
            fn() => 'ok',
            fn() => throw new \RuntimeException('kaboom'),
            fn() => 'ok2',
        ]);
    }

    public function testParallelLimitRespectsConcurrencyCeiling(): void
    {
        // 6 tasks × 100ms with concurrency=2 → 3 batches → ~300ms wall-clock
        $t0 = microtime(true);
        $results = App::parallelLimit(
            [10, 20, 30, 40, 50, 60],
            function (int $x): int { usleep(100_000); return $x * 2; },
            concurrency: 2,
        );
        $elapsed = (microtime(true) - $t0) * 1000;

        self::assertSame([20, 40, 60, 80, 100, 120], array_values($results));
        self::assertGreaterThan(250, $elapsed, "concurrency=2 over 6×100ms must take >=300ms; got {$elapsed}ms");
        self::assertLessThan(500, $elapsed,    "expected ~300ms; got {$elapsed}ms");
    }

    public function testParallelLimitPreservesInputKeys(): void
    {
        $results = App::parallelLimit(
            ['alice' => 1, 'bob' => 2, 'carol' => 3],
            fn(int $v): int => $v * 10,
            concurrency: 2,
        );
        self::assertSame(['alice' => 10, 'bob' => 20, 'carol' => 30], $results);
    }

    public function testParallelLimitRejectsZeroConcurrency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::parallelLimit([1, 2, 3], fn() => 0, concurrency: 0);
    }
}
