<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use ZealPHP\Store;
use ZealPHP\WSRouter;
use ZealPHP\Tests\Helpers\RedisTestCase;

/**
 * Patch-coverage for the WSRouter\Room accessors — name(), isMember(),
 * size(), members(), membersPaged(), onMessage/onPresence registration,
 * leave(), inter-room isolation. Room::join/leave/push publish presence
 * events via Store::publish, so these tests run against Redis (Table
 * has no pub/sub semantics by design).
 */
final class RoomAccessorsTest extends RedisTestCase
{
    protected function setUp(): void
    {
        parent::setUp();   // sets $this->url + opens predis client; skips when valkey absent
        Store::defaultBackend(Store::BACKEND_REDIS, $this->url);
        WSRouter::reset();
        WSRouter::init('room-accessor-test-' . bin2hex(random_bytes(2)));
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);   // restore default for other tests
        parent::tearDown();
    }

    public function testNameReturnsConstructedRoomName(): void
    {
        // #247 — room names are charset-validated (no ':' which would collide
        // ws_room_members keys / the ws:room:* channel). Use the dotted form.
        $r = WSRouter::room('chat.42');
        $this->assertSame('chat.42', $r->name());
    }

    public function testIsMemberFalseForUnjoinedClient(): void
    {
        $r = WSRouter::room('isMember-test');
        $this->assertFalse($r->isMember('alice'));
    }

    public function testIsMemberTrueAfterJoin(): void
    {
        $r = WSRouter::room('isMember-test-' . bin2hex(random_bytes(2)));
        $r->join('alice');
        $this->assertTrue($r->isMember('alice'));
    }

    public function testSizeAfterJoins(): void
    {
        $r = WSRouter::room('size-test-' . bin2hex(random_bytes(2)));
        $r->join('alice');
        $r->join('bob');
        $this->assertSame(2, $r->size());
    }

    public function testMembersAfterJoins(): void
    {
        $r = WSRouter::room('members-test-' . bin2hex(random_bytes(2)));
        $r->join('alice');
        $r->join('bob');
        $names = $r->members();
        sort($names);
        $this->assertSame(['alice', 'bob'], $names);
    }

    public function testMembersPagedDrainsAllMembers(): void
    {
        $r = WSRouter::room('paged-test-' . bin2hex(random_bytes(2)));
        $r->join('alice');
        $r->join('bob');
        $r->join('carol');
        // SSCAN-based — may take multiple batches. Drain to '0'.
        $next = '0';
        $names = [];
        do {
            $page = $r->membersPaged($next, 100);
            $names = array_merge($names, $page['members']);
            $next = $page['cursor'];
        } while ($next !== '0');
        sort($names);
        $this->assertSame(['alice', 'bob', 'carol'], $names);
    }

    public function testOnMessageRegistersAHandlerWithoutFiring(): void
    {
        $r = WSRouter::room('onmsg-test-' . bin2hex(random_bytes(2)));
        $fired = false;
        $r->onMessage(function () use (&$fired): void { $fired = true; });
        $this->assertFalse($fired, 'registration alone must not fire the handler');
    }

    public function testOnPresenceRegistersAHandlerWithoutFiring(): void
    {
        $r = WSRouter::room('onpres-test-' . bin2hex(random_bytes(2)));
        $fired = false;
        $r->onPresence(function () use (&$fired): void { $fired = true; });
        $this->assertFalse($fired);
    }

    public function testLeaveRemovesFromMetadataTable(): void
    {
        $name = 'leave-test-' . bin2hex(random_bytes(2));
        $r = WSRouter::room($name);
        $r->join('alice');
        $this->assertTrue($r->isMember('alice'));
        $r->leave('alice');
        $this->assertFalse($r->isMember('alice'));
    }

    public function testTwoRoomsHaveIndependentRosters(): void
    {
        $r1 = WSRouter::room('iso-1-' . bin2hex(random_bytes(2)));
        $r2 = WSRouter::room('iso-2-' . bin2hex(random_bytes(2)));
        $r1->join('alice');
        $r2->join('bob');
        $this->assertSame(1, $r1->size());
        $this->assertSame(1, $r2->size());
        $this->assertTrue($r1->isMember('alice'));
        $this->assertFalse($r1->isMember('bob'));
        $this->assertTrue($r2->isMember('bob'));
        $this->assertFalse($r2->isMember('alice'));
    }
}
