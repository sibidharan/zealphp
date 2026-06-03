<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * WSRouter::runStaleServerGC() — dead-server reaping correctness.
 *
 * These run on the Table backend (no Redis needed): the GC functions
 * operate directly on the ws_owner / ws_room_members / ws_servers Store
 * tables, so the reaping logic is exercisable in-process.
 *
 * The bug under test: the GC used to `Store::del()` rows WHILE iterating
 * the live OpenSwoole\Table. Deleting during iteration advances the
 * internal cursor into a freed slot and silently skips a chunk of the
 * remaining rows — so dead-server rows survived the sweep. The fix
 * collects the hit list first, then deletes after the iterator drains.
 */
final class WsRouterGcTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        // Allocate the three tables WSRouter::init() would, with matching
        // schemas, then clear any rows a prior test left behind.
        Store::make(WSRouter::ownerTable(), 8192, [
            'server_id' => [Store::TYPE_STRING, 192],
            'conn_id'   => [Store::TYPE_STRING, 32],
        ]);
        Store::make(WSRouter::roomTable(), 8192, [
            'room'      => [Store::TYPE_STRING, 64],
            'client_id' => [Store::TYPE_STRING, 128],
            'server_id' => [Store::TYPE_STRING, 192],
            'joined_at' => [Store::TYPE_INT, 8],
        ]);
        Store::make(WSRouter::serverTable(), 256, [
            'last_seen' => [Store::TYPE_INT, 8],
            'host'      => [Store::TYPE_STRING, 128],
            'pid'       => [Store::TYPE_INT, 8],
        ]);
        Store::clear(WSRouter::ownerTable());
        Store::clear(WSRouter::roomTable());
        Store::clear(WSRouter::serverTable());
    }

    protected function tearDown(): void
    {
        Store::clear(WSRouter::ownerTable());
        Store::clear(WSRouter::roomTable());
        Store::clear(WSRouter::serverTable());
    }

    public function testGcReapsEveryDeadServerRowDespiteIteration(): void
    {
        $now  = time();
        $dead = 'dead-server';
        $live = 'live-server';

        // One stale registry row (older than the 90s threshold) + one fresh.
        Store::set(WSRouter::serverTable(), $dead, ['last_seen' => $now - 1000, 'host' => 'h', 'pid' => 1]);
        Store::set(WSRouter::serverTable(), $live, ['last_seen' => $now,        'host' => 'h', 'pid' => 2]);

        // 60 owner rows from the dead server + 10 from the live one. 60 is far
        // above the ~28% the delete-during-iterate bug would have skipped, so
        // a regression leaves a clearly-nonzero remnant.
        for ($i = 0; $i < 60; $i++) {
            Store::set(WSRouter::ownerTable(), "dead-c$i", ['server_id' => $dead, 'conn_id' => "x$i"]);
        }
        for ($i = 0; $i < 10; $i++) {
            Store::set(WSRouter::ownerTable(), "live-c$i", ['server_id' => $live, 'conn_id' => "y$i"]);
        }
        // 40 room rows from the dead server + 5 from the live one.
        for ($i = 0; $i < 40; $i++) {
            Store::set(WSRouter::roomTable(), "room:dead-c$i", [
                'room' => 'room', 'client_id' => "dead-c$i", 'server_id' => $dead, 'joined_at' => $now,
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            Store::set(WSRouter::roomTable(), "room:live-c$i", [
                'room' => 'room', 'client_id' => "live-c$i", 'server_id' => $live, 'joined_at' => $now,
            ]);
        }

        $reaped = WSRouter::runStaleServerGC();

        // 60 owner + 40 room = 100 dependent rows reaped, EVERY dead one gone.
        self::assertSame(100, $reaped, 'all dead-server dependent rows reaped');
        self::assertSame(10, Store::count(WSRouter::ownerTable()), 'only live owner rows remain');
        self::assertSame(5, Store::count(WSRouter::roomTable()), 'only live room rows remain');
        // The dead registry row itself is gone; the live one stays.
        self::assertFalse(Store::exists(WSRouter::serverTable(), $dead));
        self::assertTrue(Store::exists(WSRouter::serverTable(), $live));

        // No dead-server rows linger in either dependent table.
        foreach (Store::iterate(WSRouter::ownerTable()) as $row) {
            self::assertNotSame($dead, $row['server_id'] ?? null);
        }
        foreach (Store::iterate(WSRouter::roomTable()) as $row) {
            self::assertNotSame($dead, $row['server_id'] ?? null);
        }
    }

    public function testGcNoOpWhenAllServersFresh(): void
    {
        $now = time();
        Store::set(WSRouter::serverTable(), 'a', ['last_seen' => $now, 'host' => 'h', 'pid' => 1]);
        Store::set(WSRouter::ownerTable(), 'c0', ['server_id' => 'a', 'conn_id' => 'z']);

        self::assertSame(0, WSRouter::runStaleServerGC());
        self::assertSame(1, Store::count(WSRouter::ownerTable()));
    }
}
