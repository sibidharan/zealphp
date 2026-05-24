<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Runtime;
use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Patch-coverage for App::parallel / parallelLimit edge cases that
 * AppParallelTest doesn't directly hit — error propagation, empty
 * input short-circuit, concurrency boundaries.
 */
final class AppParallelExtraTest extends TestCase
{
    protected function setUp(): void
    {
        if (class_exists(\OpenSwoole\Runtime::class)) {
            Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
        }
    }

    protected function tearDown(): void
    {
        if (class_exists(\OpenSwoole\Runtime::class)) {
            Runtime::enableCoroutine(false);
        }
    }

    public function testParallelReturnsResultsInTaskOrder(): void
    {
        $r = App::parallel([
            fn(): string => 'A',
            fn(): string => 'B',
            fn(): string => 'C',
        ]);
        $this->assertSame('A', $r[0]);
        $this->assertSame('B', $r[1]);
        $this->assertSame('C', $r[2]);
    }

    public function testParallelEmptyArrayShortCircuits(): void
    {
        $this->assertSame([], App::parallel([]));
    }

    public function testParallelPropagatesFirstException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        App::parallel([
            'ok'   => fn(): int => 1,
            'fail' => fn(): int => throw new \RuntimeException('boom'),
            'also' => fn(): int => 2,
        ]);
    }

    public function testParallelLimitRespectsConcurrencyCap(): void
    {
        // Just smoke — verify it returns the expected result shape with
        // a known bounded concurrency.
        $r = App::parallelLimit(
            range(1, 5),
            fn(int $n): int => $n * 2,
            concurrency: 2,
        );
        sort($r);
        $this->assertSame([2, 4, 6, 8, 10], $r);
    }

    public function testParallelLimitEmptyTasks(): void
    {
        $this->assertSame([], App::parallelLimit([], fn(): int => 0, concurrency: 4));
    }

    public function testParallelLimitConcurrencyMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::parallelLimit([1, 2, 3], fn(): int => 1, concurrency: 0);
    }
}
