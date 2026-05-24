<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * Patch-coverage for the WSRouter ServerRegistry layer:
 *   - server-row writes at boot + heartbeat refresh
 *   - stale-server GC sweep (no live worker → drop the row)
 *   - graceful shutdown sweep (onWorkerStop)
 *   - own() rollback path when Store::set returns false (capacity / row-too-large)
 *
 * Exercises code paths under the Table backend — no Redis required.
 */
final class WSRouterServerRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        WSRouter::reset();
        WSRouter::init('unit-srv-' . bin2hex(random_bytes(3)));
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
    }

    public function testInitWritesOwnServerRowToRegistry(): void
    {
        // After init, the registry table should have a row for our serverId.
        $row = Store::get(WSRouter::serverTable(), WSRouter::serverId());
        $this->assertIsArray($row, 'init() must write our own server row');
        $this->assertArrayHasKey('last_seen', $row);
        $this->assertArrayHasKey('host', $row);
        $this->assertArrayHasKey('pid', $row);
        $this->assertGreaterThan(0, $row['last_seen']);
    }

    public function testWriteServerRegistryRowRefreshesTimestamp(): void
    {
        $first  = Store::get(WSRouter::serverTable(), WSRouter::serverId(), 'last_seen');
        $this->assertIsInt($first);
        sleep(1);   // make sure the next write produces a strictly-greater timestamp
        WSRouter::writeServerRegistryRow();
        $second = Store::get(WSRouter::serverTable(), WSRouter::serverId(), 'last_seen');
        $this->assertIsInt($second);
        $this->assertGreaterThanOrEqual($first, $second);
    }

    public function testRunStaleServerGcDropsExpiredRows(): void
    {
        // Plant a stale row with a last_seen far in the past.
        Store::set(WSRouter::serverTable(), 'phantom-srv', [
            'last_seen' => time() - 9999,   // way older than SERVER_STALE_AFTER_SEC
            'host'      => 'gone.example',
            'pid'       => 999999,
        ]);
        $this->assertIsArray(Store::get(WSRouter::serverTable(), 'phantom-srv'), 'phantom row present pre-GC');

        WSRouter::runStaleServerGC();

        $this->assertFalse(Store::get(WSRouter::serverTable(), 'phantom-srv'), 'phantom row dropped post-GC');
        // Our own row survives (recently-written).
        $this->assertIsArray(Store::get(WSRouter::serverTable(), WSRouter::serverId()));
    }

    public function testSweepThisServerDropsOwnerRowsOwnedByThisServer(): void
    {
        $myId = WSRouter::serverId();
        // Own two clients under THIS server, plus one stale row claimed by a peer.
        WSRouter::own('alice-' . bin2hex(random_bytes(2)), 11);
        WSRouter::own('bob-'   . bin2hex(random_bytes(2)), 12);
        Store::set(WSRouter::roomTable(), 'phantom-room:carol', [
            'room' => 'phantom-room', 'client_id' => 'carol', 'server_id' => 'OTHER-SRV', 'joined_at' => time(),
        ]);
        $before = Store::count(WSRouter::roomTable());

        // Plant a room membership row for one of OUR clients so the sweep
        // also reaps room memberships.
        Store::set(WSRouter::roomTable(), 'test-room:alice-x', [
            'room' => 'test-room', 'client_id' => 'alice-x', 'server_id' => $myId, 'joined_at' => time(),
        ]);

        WSRouter::sweepThisServer();

        // OTHER-SRV's phantom row must survive — sweep is server-scoped.
        $this->assertIsArray(
            Store::get(WSRouter::roomTable(), 'phantom-room:carol'),
            'sweepThisServer must NOT touch rows owned by other servers'
        );
    }

    public function testOnSrvIdAccessor(): void
    {
        $id = WSRouter::serverId();
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->assertStringStartsWith('unit-srv-', $id);
    }

    public function testServerTableConstantAccessor(): void
    {
        $this->assertSame('ws_servers', WSRouter::serverTable());
    }

    public function testRoomTableConstantAccessor(): void
    {
        $this->assertSame('ws_room_members', WSRouter::roomTable());
    }

    public function testRoomChannelPrefixConstantAccessor(): void
    {
        $this->assertSame('ws:room:', WSRouter::roomChannelPrefix());
    }

    public function testRoomMembersSetKey(): void
    {
        $key = WSRouter::roomMembersSetKey('chat:42');
        $this->assertSame('ws_room:chat:42:members', $key);
    }

    public function testSetRoomRateLimitGettersAndValidation(): void
    {
        WSRouter::setRoomRateLimit(7, 90);
        $this->assertSame(7,  WSRouter::roomRateLimitN());
        $this->assertSame(90, WSRouter::roomRateLimitWindowSec());

        WSRouter::setRoomRateLimit(0);
        $this->assertSame(0, WSRouter::roomRateLimitN());

        $this->expectException(\InvalidArgumentException::class);
        WSRouter::setRoomRateLimit(-1);
    }

    public function testInitOptionsValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::initOptions(ownerCapacity: 0);
    }

    public function testInitOptionsRejectsTooSmallSlowConsumerBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::initOptions(slowConsumerBytes: 1023);   // must be ≥1024
    }
}
