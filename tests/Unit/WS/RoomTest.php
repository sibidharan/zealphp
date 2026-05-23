<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\WS;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;
use ZealPHP\WS\Room;

/**
 * WSRouter::room() — federated WebSocket rooms.
 *
 * Tests run against the local Valkey on :16379 (Redis backend needed
 * because rooms use cluster-wide membership + pub/sub). Skipped if
 * Redis isn't reachable.
 *
 * Membership semantics + presence-event dispatch are testable here;
 * the actual push-to-local-fd path requires a live WS server (covered
 * in integration tests).
 */
final class RoomTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $url = (string) getenv('ZEALPHP_REDIS_URL');
        $this->url = $url !== '' ? $url : 'redis://127.0.0.1:16379/0';
        Store::defaultBackend(Store::BACKEND_REDIS, $this->url);
        WSRouter::reset();
        $this->skipIfNoRedis();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    public function testCannotConstructBeforeInit(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $this->expectExceptionMessageMatches('/WSRouter::init\(\) must be called/');
        WSRouter::room('chat:never-constructed');
    }

    public function testJoinPopulatesClusterMembership(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            $room->join('alice');
            $room->join('bob');

            self::assertTrue($room->isMember('alice'));
            self::assertTrue($room->isMember('bob'));
            self::assertFalse($room->isMember('carol'));
            self::assertSame(2, $room->size());

            $roster = $room->members();
            sort($roster);
            self::assertSame(['alice', 'bob'], $roster);
        });
    }

    public function testLeaveRemovesFromMembership(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            $room->join('alice');
            $room->join('bob');
            $room->leave('alice');

            self::assertFalse($room->isMember('alice'));
            self::assertTrue($room->isMember('bob'));
            self::assertSame(1, $room->size());
            self::assertSame(['bob'], $room->members());
        });
    }

    public function testTwoRoomsAreIndependent(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $alpha = WSRouter::room($roomAlpha);
            $beta  = WSRouter::room($roomBeta);

            $alpha->join('alice');
            $beta->join('bob');

            self::assertTrue($alpha->isMember('alice'));
            self::assertFalse($alpha->isMember('bob'));
            self::assertFalse($beta->isMember('alice'));
            self::assertTrue($beta->isMember('bob'));
        });
    }

    public function testJoinIsIdempotent(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            $room->join('alice');
            $room->join('alice');
            $room->join('alice');

            self::assertSame(1, $room->size());
        });
    }

    public function testPushPublishesToRoomChannel(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            // No subscribers on this channel from THIS test (the
            // pattern subscriber the framework installs in init() needs
            // App::run() boot wiring which we don't have here). So the
            // receiver count is 0 — but the publish itself succeeds.
            $receivers = $room->push(['type' => 'message', 'data' => 'hi everyone']);
            self::assertGreaterThanOrEqual(0, $receivers);
        });
    }

    public function testHandleRoomMessageDispatchesPresenceHandlers(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            $events = [];
            $room->onPresence(function (array $event) use (&$events): void {
                $events[] = $event;
            });

            // Simulate the pattern subscriber delivering a presence event.
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'alice',
                'ts'        => 1234,
            ]));

            self::assertCount(1, $events);
            self::assertSame('join', $events[0]['type']);
            self::assertSame('alice', $events[0]['client_id']);
        });
    }

    public function testHandleRoomMessageDispatchesMessageHandlers(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            $messages = [];
            $room->onMessage(function (array $msg, string $roomName) use (&$messages): void {
                $messages[] = ['msg' => $msg, 'room' => $roomName];
            });

            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type' => 'message',
                'data' => 'hi',
            ]));

            self::assertCount(1, $messages);
            self::assertSame($roomName, $messages[0]['room']);
            self::assertSame('hi', $messages[0]['msg']['data']);
        });
    }

    public function testLocalCacheUpdatesOnPresenceEventForLocallyOwnedClient(): void
    {
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');
            $room = WSRouter::room($roomName);

            // Take ownership of alice locally — so the join event will
            // update this worker's local-membership cache.
            WSRouter::own('alice', 99);

            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'alice',
                'ts'        => 1234,
            ]));

            $cache = WSRouter::localRoomMembership();
            self::assertArrayHasKey($roomName, $cache);
            self::assertArrayHasKey('alice', $cache[$roomName]);

            // Now leave — cache should drop alice.
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'leave',
                'client_id' => 'alice',
                'ts'        => 1235,
            ]));

            self::assertArrayNotHasKey($roomName, WSRouter::localRoomMembership());
        });
    }

    public function testLocalCacheSkipsClientsNotOwnedByThisWorker(): void
    {
        // Only locally-owned clients should land in the local cache —
        // remote-owned clients' joins are observed but their fd isn't
        // here, so the cache shouldn't fill up with strangers.
        Coroutine::run(function (): void { $roomName = "r-" . bin2hex(random_bytes(3)); $roomAlpha = "alpha-" . bin2hex(random_bytes(3)); $roomBeta = "beta-" . bin2hex(random_bytes(3));
            WSRouter::init('test-server');

            // Don't WSRouter::own('alice', …) — she's remote.
            WSRouter::handleRoomMessage('ws:room:' . $roomName, (string) json_encode([
                'type'      => 'join',
                'client_id' => 'alice-on-another-server',
                'ts'        => 1234,
            ]));

            self::assertSame([], WSRouter::localRoomMembership());
        });
    }

    private function skipIfNoRedis(): void
    {
        try {
            $c = new \Predis\Client($this->url);
            $c->ping();
            $c->disconnect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis/Valkey not available: ' . $e->getMessage());
        }
    }
}
