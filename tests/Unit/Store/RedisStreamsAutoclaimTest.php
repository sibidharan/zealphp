<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use ZealPHP\Store\RedisClient;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Drives the new XAUTOCLAIM surface on RedisDriver / RedisClient. The
 * scenario: consumer A reads a message but never ACKs (simulates a crash);
 * consumer B uses XAUTOCLAIM with a tiny min-idle to steal it.
 */
final class RedisStreamsAutoclaimTest extends RedisTestCase
{
    public function testAutoclaimStealsStalePendingFromDeadConsumer(): void
    {
        $this->requireYieldingSubscribe();
        Coroutine::run(function (): void {
            $stream   = 't:autoclaim:' . bin2hex(random_bytes(4));
            $group    = 'g1';
            $c = new RedisClient($this->url);

            $c->xgroupCreate($stream, $group);
            $msgId = $c->xadd($stream, ['payload' => 'orphan']);

            // Consumer A reads it (now pending under A's name) — but never ACKs.
            $a = $c->xreadGroup($group, 'consumer-A', [$stream], 1, 100);
            $this->assertCount(1, $a[$stream] ?? []);
            $this->assertSame($msgId, $a[$stream][0]['id']);

            // Wait > min-idle so the message qualifies for auto-claim.
            sleep(1);

            // Consumer B auto-claims everything pending > 500 ms.
            [$nextCursor, $claimed] = $c->xautoclaim($stream, $group, 'consumer-B', 500);
            $this->assertCount(1, $claimed, 'B claims A\'s orphan');
            $this->assertSame($msgId, $claimed[0]['id']);
            $this->assertSame(['payload' => 'orphan'], $claimed[0]['payload']);
            // Cursor returns to 0-0 when the scan completes.
            $this->assertSame('0-0', $nextCursor);

            // B ACKs the claimed message — pending list shrinks to empty.
            $this->assertSame(1, $c->xack($stream, $group, $msgId));
        });
    }

    public function testAutoclaimReturnsEmptyWhenNothingQualifies(): void
    {
        $this->requireYieldingSubscribe();
        Coroutine::run(function (): void {
            $stream = 't:autoclaim-empty:' . bin2hex(random_bytes(4));
            $c = new RedisClient($this->url);
            $c->xgroupCreate($stream, 'g1');
            // No messages at all — auto-claim is a no-op.
            [$cursor, $claimed] = $c->xautoclaim($stream, 'g1', 'unused', 100);
            $this->assertSame('0-0', $cursor);
            $this->assertSame([], $claimed);
        });
    }
}
