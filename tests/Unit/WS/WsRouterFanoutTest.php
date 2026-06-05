<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\WSRouter;

/**
 * WS-6 — bounded room-broadcast fan-out (`WSRouter::boundedFanOut`).
 *
 * Before WS-6 the room broadcast push loop did `Coroutine::create()` per
 * local member with NO concurrency cap — a large room + message burst spawned
 * an unbounded number of simultaneous push coroutines (the keystone
 * backpressure gap). The fix routes the fan-out through a `Coroutine\Channel`
 * token semaphore so at most N pushes run at once, while still dispatching
 * each member in its OWN coroutine (per-coroutine isolation preserved).
 *
 * These tests drive the helper directly with an injected fake `$spawn` that
 * records the concurrency it observes — no live OpenSwoole server, real
 * WebSocket fds, or `$server->push` needed. The helper's own channel
 * admission logic runs inside `Coroutine::run()` so the channel pop/push
 * yields work like they do in production.
 */
final class WsRouterFanoutTest extends TestCase
{
    public function testNeverExceedsConcurrencyCapForLargeMemberList(): void
    {
        Coroutine::run(function (): void {
            $cap       = 8;
            $members   = range(1, 200);   // 200 >> 8
            $inFlight  = 0;
            $maxSeen   = 0;
            $dispatched = [];

            WSRouter::boundedFanOut(
                $members,
                $cap,
                function (int $fd, callable $release) use (&$inFlight, &$maxSeen, &$dispatched): void {
                    $dispatched[] = $fd;
                    // Each "push" runs in its own coroutine, holds its token
                    // across a yield (modelling a non-instant push), records
                    // peak concurrency, then releases.
                    Coroutine::create(function () use (&$inFlight, &$maxSeen, $release): void {
                        $inFlight++;
                        if ($inFlight > $maxSeen) { $maxSeen = $inFlight; }
                        // Yield so other admitted tasks overlap with this one.
                        Coroutine::usleep(1000);
                        $inFlight--;
                        $release();
                    });
                },
            );

            // Drain any tasks still in flight after the driver returns.
            Coroutine::usleep(20000);

            self::assertSame(200, count($dispatched), 'every member dispatched exactly once');
            self::assertSame($members, $dispatched, 'dispatch order + completeness preserved');
            self::assertLessThanOrEqual($cap, $maxSeen, 'never more than $cap pushes in flight');
            self::assertGreaterThan(1, $maxSeen, 'fan-out actually ran concurrently (not serialized)');
        });
    }

    public function testDispatchesEveryMemberExactlyOnce(): void
    {
        Coroutine::run(function (): void {
            $members = ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0];
            $fds     = [10, 11, 12, 13, 14];
            $seen    = [];

            WSRouter::boundedFanOut(
                $fds,
                2,
                function (int $fd, callable $release) use (&$seen): void {
                    $seen[$fd] = ($seen[$fd] ?? 0) + 1;
                    $release();
                },
            );

            self::assertSame([10 => 1, 11 => 1, 12 => 1, 13 => 1, 14 => 1], $seen);
        });
    }

    public function testUnboundedModeSpawnsImmediatelyWithNoopRelease(): void
    {
        // $maxConcurrent === 0 → unbounded legacy behaviour: no channel, every
        // item dispatched immediately, release is a no-op. Runs OUTSIDE a
        // coroutine to prove the unbounded path never touches a channel (which
        // would block off-scheduler).
        $fds     = [1, 2, 3, 4, 5];
        $count   = 0;
        $ordered = [];

        WSRouter::boundedFanOut(
            $fds,
            0,
            function (int $fd, callable $release) use (&$count, &$ordered): void {
                $count++;
                $ordered[] = $fd;
                $release(); // no-op in unbounded mode — must not throw
            },
        );

        self::assertSame(5, $count, 'all items dispatched in unbounded mode');
        self::assertSame($fds, $ordered);
    }

    public function testEmptyMemberListIsANoOp(): void
    {
        $called = false;
        WSRouter::boundedFanOut([], 4, function () use (&$called): void { $called = true; });
        self::assertFalse($called, 'no spawn for an empty member list');
    }

    public function testDoubleReleaseDoesNotInflateConcurrencyBudget(): void
    {
        // A buggy spawner that releases twice must not let the in-flight budget
        // creep above the cap — the per-token guard absorbs the extra push.
        Coroutine::run(function (): void {
            $cap      = 4;
            $members  = range(1, 60);
            $inFlight = 0;
            $maxSeen  = 0;

            WSRouter::boundedFanOut(
                $members,
                $cap,
                function (int $fd, callable $release) use (&$inFlight, &$maxSeen): void {
                    Coroutine::create(function () use (&$inFlight, &$maxSeen, $release): void {
                        $inFlight++;
                        if ($inFlight > $maxSeen) { $maxSeen = $inFlight; }
                        Coroutine::usleep(1000);
                        $inFlight--;
                        $release();
                        $release(); // double release — guarded, second is a no-op
                    });
                },
            );
            Coroutine::usleep(20000);

            self::assertLessThanOrEqual($cap, $maxSeen, 'double-release does not breach the cap');
        });
    }
}
