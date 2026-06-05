<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store\RedisStreams;

/**
 * Pure unit coverage for the orphan-recovery (XAUTOCLAIM) reclaim path —
 * NO live Redis, NO coroutines. Drives `RedisStreams::reclaimPassForTest()`,
 * the synchronous testing seam that exercises the SAME cursor-iteration +
 * handler-decision + XACK-on-success logic the runner's `reclaimAll()` uses.
 *
 * The reclaim source (`$claim`) and the XACK action (`$ack`) are injected as
 * closures, so the test asserts:
 *   - XAUTOCLAIM is invoked (cursor iterated until '0-0');
 *   - a reclaimed entry runs the handler;
 *   - the entry is XACKed on a true return, left pending on false/throw.
 */
final class RedisStreamsReclaimTest extends TestCase
{
    /**
     * A consumer entry shaped exactly like RedisStreams::register() stores.
     *
     * @return array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int}
     */
    private function entry(callable $handler): array
    {
        return [
            'stream'    => 'orders',
            'group'     => 'order-workers',
            'handler'   => $handler,
            'blockMs'   => 1000,
            'batchSize' => 16,
        ];
    }

    /**
     * A reclaimed-message shape: ['id' => ..., 'payload' => ['payload' => ...]].
     *
     * @return array{id:string, payload:array<string, string>}
     */
    private function msg(string $id, string $payload): array
    {
        return ['id' => $id, 'payload' => ['payload' => $payload]];
    }

    public function testConstructorAndPolicyDefaults(): void
    {
        $s = new RedisStreams('redis://127.0.0.1:6379');
        $this->assertSame(RedisStreams::DEFAULT_RECLAIM_EVERY_SEC, $s->reclaimEverySec());
        $this->assertSame(RedisStreams::DEFAULT_RECLAIM_MIN_IDLE_MS, $s->reclaimMinIdleMs());
    }

    public function testConstructorOverridesAndClamping(): void
    {
        $s = new RedisStreams('redis://h', null, [], reclaimEverySec: 10, reclaimMinIdleMs: 5000);
        $this->assertSame(10, $s->reclaimEverySec());
        $this->assertSame(5000, $s->reclaimMinIdleMs());

        // Negatives clamp to 0; 0 disables periodic reclaim.
        $neg = new RedisStreams('redis://h', null, [], reclaimEverySec: -5, reclaimMinIdleMs: -1);
        $this->assertSame(0, $neg->reclaimEverySec());
        $this->assertSame(0, $neg->reclaimMinIdleMs());
    }

    public function testReclaimPolicySetterIsChainable(): void
    {
        $s = new RedisStreams('redis://h');
        $ret = $s->reclaimPolicy(45, 90000, 128);
        $this->assertSame($s, $ret);
        $this->assertSame(45, $s->reclaimEverySec());
        $this->assertSame(90000, $s->reclaimMinIdleMs());
    }

    public function testReclaimDispatchesHandlerAndAcksOnTrue(): void
    {
        $s = new RedisStreams('redis://h', 'consumer-B', [], reclaimMinIdleMs: 60000);
        $handled = [];
        $entry = $this->entry(function (string $payload, string $id, string $stream, array $fields) use (&$handled): bool {
            $handled[] = [$payload, $id, $stream];
            return true; // ACK
        });

        // Capture the (stream, group, consumer, minIdle) args XAUTOCLAIM was called with.
        $claimArgs = [];
        $claim = function (string $cursor) use (&$claimArgs): array {
            // Single page, then end-of-scan.
            if ($cursor === '0-0' && $claimArgs === []) {
                $claimArgs[] = $cursor;
                return ['0-0', [['id' => '1-0', 'payload' => ['payload' => 'orphan']]]];
            }
            $claimArgs[] = $cursor;
            return ['0-0', []];
        };

        $acked = [];
        $ack = function (string $stream, string $group, string $id) use (&$acked): void {
            $acked[] = [$stream, $group, $id];
        };

        $decisions = $s->reclaimPassForTest($entry, $claim, $ack);

        // Handler ran with the reclaimed payload.
        $this->assertSame([['orphan', '1-0', 'orders']], $handled);
        // The entry was XACKed (true decision).
        $this->assertSame([['orders', 'order-workers', '1-0']], $acked);
        $this->assertSame([['id' => '1-0', 'acked' => true]], $decisions);
    }

    public function testReclaimLeavesPendingOnFalseReturn(): void
    {
        $s = new RedisStreams('redis://h', 'consumer-B');
        $entry = $this->entry(fn (): bool => false); // NACK

        $page = [$this->msg('2-0', 'nack-me')];
        $claim = $this->onePageThenEnd($page);

        $acked = [];
        $decisions = $s->reclaimPassForTest($entry, $claim, function (...$a) use (&$acked): void { $acked[] = $a; });

        // No XACK — message stays pending.
        $this->assertSame([], $acked);
        $this->assertSame([['id' => '2-0', 'acked' => false]], $decisions);
    }

    public function testReclaimLeavesPendingWhenHandlerThrows(): void
    {
        $s = new RedisStreams('redis://h', 'consumer-B');
        $entry = $this->entry(function (): bool { throw new \RuntimeException('boom'); });

        $claim = $this->onePageThenEnd([$this->msg('3-0', 'throw-me')]);
        $acked = [];
        $decisions = $s->reclaimPassForTest($entry, $claim, function (...$a) use (&$acked): void { $acked[] = $a; });

        // The throw is swallowed (runner survives), the entry is NOT acked.
        $this->assertSame([], $acked);
        $this->assertSame([['id' => '3-0', 'acked' => false]], $decisions);
    }

    public function testCursorIteratesUntilZeroZeroAcrossMultiplePages(): void
    {
        $s = new RedisStreams('redis://h', 'consumer-B');
        $handled = [];
        $entry = $this->entry(function (string $payload) use (&$handled): bool {
            $handled[] = $payload;
            return true;
        });

        // Three pages: cursor '0-0' -> 'c1' -> 'c2' -> '0-0' (done).
        $cursorsSeen = [];
        $claim = function (string $cursor) use (&$cursorsSeen): array {
            $cursorsSeen[] = $cursor;
            return match ($cursor) {
                '0-0' => ['c1', [['id' => 'a', 'payload' => ['payload' => 'p-a']]]],
                'c1'  => ['c2', [['id' => 'b', 'payload' => ['payload' => 'p-b']]]],
                default => ['0-0', [['id' => 'c', 'payload' => ['payload' => 'p-c']]]],
            };
        };

        $ackCount = 0;
        $decisions = $s->reclaimPassForTest($entry, $claim, function () use (&$ackCount): void { $ackCount++; });

        // Cursor was driven through every page until it returned to '0-0'.
        $this->assertSame(['0-0', 'c1', 'c2'], $cursorsSeen);
        // All three reclaimed entries were dispatched + acked.
        $this->assertSame(['p-a', 'p-b', 'p-c'], $handled);
        $this->assertSame(3, $ackCount);
        $this->assertCount(3, $decisions);
    }

    public function testReclaimNoOpWhenNothingPending(): void
    {
        $s = new RedisStreams('redis://h', 'consumer-B');
        $ran = false;
        $entry = $this->entry(function () use (&$ran): bool { $ran = true; return true; });

        // Empty first page, cursor already at end.
        $claim = fn (string $cursor): array => ['0-0', []];
        $acked = [];
        $decisions = $s->reclaimPassForTest($entry, $claim, function (...$a) use (&$acked): void { $acked[] = $a; });

        $this->assertFalse($ran, 'handler never runs when nothing is pending');
        $this->assertSame([], $acked);
        $this->assertSame([], $decisions);
    }

    /**
     * Build a $claim closure that returns one page of $entries on the first
     * call, then end-of-scan ('0-0', []) on every subsequent call.
     *
     * @param list<array{id:string, payload:array<string, string>}> $entries
     * @return callable(string): array{0:string, 1:list<array{id:string, payload:array<string, string>}>}
     */
    private function onePageThenEnd(array $entries): callable
    {
        $served = false;
        return function (string $cursor) use ($entries, &$served): array {
            if (!$served) {
                $served = true;
                return ['0-0', $entries];
            }
            return ['0-0', []];
        };
    }
}
