<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Store;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;
use ZealPHP\Store\RedisBackend;
use ZealPHP\Store\RedisConnectionPool;
use ZealPHP\Store\TableBackend;
use ZealPHP\Store\TieredBackend;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Proves cross-instance L1 invalidation works: two TieredBackend instances
 * share the same Redis L2; a write on instance A publishes an invalidation;
 * instance B's L1 evicts the stale entry without waiting for $l1Ttl.
 */
final class TieredBackendInvalidationTest extends RedisTestCase
{
    private function instance(string $prefix, string $originSuffix = ''): TieredBackend
    {
        $l1 = new TableBackend();
        $l2 = new RedisBackend(new RedisConnectionPool($this->url, 4), $prefix);
        return new TieredBackend($l1, $l2, 60, 'origin-' . $originSuffix);
    }

    public function testPeerInstanceL1IsEvictedOnRemoteWrite(): void
    {
        Coroutine::run(function (): void {
            $prefix = 'zptest-tier-inval-' . bin2hex(random_bytes(4));
            $a = $this->instance($prefix, 'A');
            $b = $this->instance($prefix, 'B');

            // Order: make() FIRST, then enableInvalidation() — the runner
            // subscribes to all registered channels at start, so tables
            // must be declared before the runner spins up.
            $a->make('users', 100, ['name' => [Table::TYPE_STRING, 32]]);
            $b->make('users', 100, ['name' => [Table::TYPE_STRING, 32]]);

            $a->enableInvalidation();
            $b->enableInvalidation();

            // Let subscribers register before the first publish.
            (new Channel(1))->pop(0.15);

            $a->set('users', 'alice', ['name' => 'Alice']);

            // Warm B's L1 by reading via B. Both should see 'Alice'.
            $this->assertSame(['name' => 'Alice'], $b->get('users', 'alice'));
            $this->assertNotNull($b->l1()->get('users', 'alice'),
                'B\'s L1 should be populated after the read');

            // Now write via A — this should publish an invalidation that B receives.
            $a->set('users', 'alice', ['name' => 'Alice (updated by A)']);

            // Give the subscriber a beat to process the invalidation.
            (new Channel(1))->pop(0.2);

            // B's L1 should have been evicted by the invalidation handler.
            $this->assertNull($b->l1()->get('users', 'alice'),
                'B\'s L1 should be EVICTED by the invalidation message from A');

            // B's next get re-fetches from L2 (which now has the updated value).
            $this->assertSame(['name' => 'Alice (updated by A)'], $b->get('users', 'alice'));

            $a->stopInvalidation();
            $b->stopInvalidation();
        });
    }

    public function testSelfPublishesAreSkipped(): void
    {
        Coroutine::run(function (): void {
            $prefix = 'zptest-tier-inval-self-' . bin2hex(random_bytes(4));
            $a = $this->instance($prefix, 'A');

            $a->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $a->enableInvalidation();

            (new Channel(1))->pop(0.15);

            // A writes; the invalidation message is published; A's OWN subscriber
            // receives it but skips because the origin tag matches A's id.
            // The L1 row should remain (A populated it via the set).
            $a->set('t', 'k', ['v' => 'wrote-by-A']);
            (new Channel(1))->pop(0.2); // let A's subscriber process its own message

            // A's L1 still has the row — self-publish was skipped.
            $l1Row = $a->l1()->get('t', 'k');
            $this->assertIsArray($l1Row);
            $this->assertSame('wrote-by-A', $l1Row['v']);

            $a->stopInvalidation();
        });
    }

    public function testInvalidationOnDelEvictsPeerL1(): void
    {
        Coroutine::run(function (): void {
            $prefix = 'zptest-tier-inval-del-' . bin2hex(random_bytes(4));
            $a = $this->instance($prefix, 'A');
            $b = $this->instance($prefix, 'B');

            $a->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);
            $b->make('t', 100, ['v' => [Table::TYPE_STRING, 16]]);

            $a->enableInvalidation();
            $b->enableInvalidation();

            (new Channel(1))->pop(0.15);

            $a->set('t', 'k', ['v' => 'about-to-die']);
            $this->assertSame(['v' => 'about-to-die'], $b->get('t', 'k')); // warm B's L1

            $a->del('t', 'k');
            (new Channel(1))->pop(0.2);

            // B's L1 should be evicted by the del-invalidation.
            $this->assertNull($b->l1()->get('t', 'k'));

            $a->stopInvalidation();
            $b->stopInvalidation();
        });
    }
}
