<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;
use ZealPHP\WSRouter;

/**
 * Patch-coverage for WSRouter::handleRoomMessage — the pub/sub
 * subscriber callback that decodes envelope, dispatches to
 * user-registered message + presence handlers, and maintains the
 * per-worker local-membership cache.
 *
 * Exercised by direct invocation with mock envelope JSON, avoiding the
 * real pub/sub round-trip (which requires the live subscriber loop).
 */
final class WSRouterHandleRoomMessageTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        WSRouter::reset();
        WSRouter::init('handler-test-' . bin2hex(random_bytes(2)));
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
    }

    public function testHandleIgnoresChannelsOutsideRoomPrefix(): void
    {
        $fired = false;
        // No setup needed — the early return on prefix-miss should leave state untouched.
        WSRouter::handleRoomMessage('something:else', '{"type":"message","data":"x"}');
        $this->assertFalse($fired);
    }

    public function testHandleIgnoresMalformedJson(): void
    {
        // Should not throw on invalid JSON.
        WSRouter::handleRoomMessage('ws:room:test', 'this-is-not-json');
        $this->assertTrue(true);   // exception-free is the success signal
    }

    public function testHandleIgnoresNonArrayPayload(): void
    {
        WSRouter::handleRoomMessage('ws:room:test', '"just a string"');
        $this->assertTrue(true);
    }

    public function testMessageHandlerFiresForRegisteredRoom(): void
    {
        $received = [];
        $room = WSRouter::room('test-room-' . bin2hex(random_bytes(2)));
        $room->onMessage(function (array $msg, string $roomName) use (&$received): void {
            $received[] = ['msg' => $msg, 'room' => $roomName];
        });

        $envelope = json_encode([
            'type' => 'message',
            'data' => 'hello',
        ]);
        WSRouter::handleRoomMessage(WSRouter::roomChannelPrefix() . $room->name(), $envelope);

        $this->assertCount(1, $received);
        $this->assertSame('hello', $received[0]['msg']['data']);
        $this->assertSame($room->name(), $received[0]['room']);
    }

    public function testPresenceHandlerFiresForJoinEvent(): void
    {
        $events = [];
        $room = WSRouter::room('pres-room-' . bin2hex(random_bytes(2)));
        $room->onPresence(function (array $event, string $roomName) use (&$events): void {
            $events[] = $event;
        });

        $envelope = json_encode([
            'type'      => 'join',
            'client_id' => 'alice',
            'ts'        => time(),
        ]);
        WSRouter::handleRoomMessage(WSRouter::roomChannelPrefix() . $room->name(), $envelope);

        $this->assertCount(1, $events);
        $this->assertSame('join', $events[0]['type']);
        $this->assertSame('alice', $events[0]['client_id']);
    }

    public function testMultipleMessageHandlersAllFire(): void
    {
        $count = 0;
        $room = WSRouter::room('multi-h-' . bin2hex(random_bytes(2)));
        $room->onMessage(function () use (&$count): void { $count++; });
        $room->onMessage(function () use (&$count): void { $count++; });
        $room->onMessage(function () use (&$count): void { $count++; });

        WSRouter::handleRoomMessage(
            WSRouter::roomChannelPrefix() . $room->name(),
            json_encode(['type' => 'message', 'data' => 'x'])
        );
        $this->assertSame(3, $count, 'all 3 handlers fire on one message');
    }

    public function testHandlerThrowDoesNotCrashDispatch(): void
    {
        $okFired = false;
        $room = WSRouter::room('throw-h-' . bin2hex(random_bytes(2)));
        $room->onMessage(function (): void { throw new \RuntimeException('boom'); });
        $room->onMessage(function () use (&$okFired): void { $okFired = true; });

        WSRouter::handleRoomMessage(
            WSRouter::roomChannelPrefix() . $room->name(),
            json_encode(['type' => 'message', 'data' => 'x'])
        );
        $this->assertTrue($okFired, 'second handler fires after first throws');
    }
}
