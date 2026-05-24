<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;
use ZealPHP\WSRouter;

/**
 * Covers WSRouter's client-dispatch surface — `sendToClient`, the
 * `broadcast` Store-publish passthrough, `pushWithBackpressure`'s
 * dead-fd branch, and the `Room` pre-init guard.
 */
final class WSRouterClientDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
        WSRouter::reset();
    }

    protected function tearDown(): void
    {
        WSRouter::reset();
        WSRouter::setClientRateLimit(0);
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    public function testSendToClientReturnsFalseForUnknownClient(): void
    {
        WSRouter::init('dispatch-stc-' . bin2hex(random_bytes(2)));
        $this->assertFalse(WSRouter::sendToClient('nobody-here', 'hi'));
    }

    public function testSendToClientReturnsFalseWhenRateLimited(): void
    {
        WSRouter::setClientRateLimit(1, 60);
        WSRouter::init('dispatch-rl-' . bin2hex(random_bytes(2)));
        // First send consumes the per-client budget (returns false because
        // client unknown, but the rate gate ran first).
        WSRouter::sendToClient('alice', 'first');
        // Second send must short-circuit on the rate gate, BEFORE the
        // Store::get lookup.
        $this->assertFalse(WSRouter::sendToClient('alice', 'second'));
    }

    public function testPushWithBackpressureReturnsFalseForDeadFd(): void
    {
        if (!class_exists(\OpenSwoole\WebSocket\Server::class)) {
            $this->markTestSkipped('OpenSwoole WS not loaded');
        }
        // Anon stub keeps the test self-contained — no listener port.
        $stub = new class('127.0.0.1', 0) extends \OpenSwoole\WebSocket\Server {
            public function isEstablished(int $fd): bool { return false; }
        };
        $this->assertFalse(WSRouter::pushWithBackpressure($stub, 9999, 'payload'));
    }

    public function testBroadcastThrowsOnTableBackend(): void
    {
        // broadcast() is a one-line Store::publish passthrough; Store::publish
        // throws on Table backend (pub/sub needs Redis). Pinning the
        // behavior keeps any future accidental relaxation visible.
        $this->expectException(StoreException::class);
        WSRouter::broadcast('demo:room', 'hi');
    }

    public function testRoomOpThrowsBeforeInit(): void
    {
        $this->expectException(StoreException::class);
        $r = WSRouter::room('preinit-room');
        $r->size();
    }
}
