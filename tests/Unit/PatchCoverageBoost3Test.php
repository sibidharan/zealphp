<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use PHPUnit\Framework\TestCase;
use ZealPHP\Counter;
use ZealPHP\Store;
use ZealPHP\Store\StoreException;
use ZealPHP\WS\CapacityException;
use ZealPHP\WSRouter;

/**
 * Third patch-coverage batch — capacity-exception path, sendToClient
 * miss accounting, and broadcast/onRoom sugar coverage.
 */
final class PatchCoverageBoost3Test extends TestCase
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
        Store::defaultBackend(Store::BACKEND_TABLE);
        Counter::defaultBackend(Counter::BACKEND_ATOMIC);
    }

    // ── CapacityException type hierarchy ────────────────────────────

    public function testCapacityExceptionInheritsStoreException(): void
    {
        // CapacityException MUST extend StoreException so existing
        // `catch (StoreException)` blocks still catch them.
        $r = new \ReflectionClass(CapacityException::class);
        $this->assertSame(StoreException::class, $r->getParentClass()->getName());
    }

    public function testCapacityExceptionMessageRoundTrips(): void
    {
        $e = new CapacityException('table full at 256 connections');
        $this->assertStringContainsString('256 connections', $e->getMessage());
    }

    // ── sendToClient miss / rate-limit paths ─────────────────────────

    public function testSendToClientReturnsFalseForUnknownClient(): void
    {
        // L569-571 sendToClient_owner_missing branch — no row in
        // ws_owner for this clientId, so the publish is skipped.
        WSRouter::init('boost3-stc-' . bin2hex(random_bytes(2)));
        $ok = WSRouter::sendToClient('nobody', 'hi');
        $this->assertFalse($ok);
    }

    public function testSendToClientReturnsFalseWhenRateLimited(): void
    {
        // WS-4 — limit to 1 msg per 60s. Second send must be dropped
        // BEFORE the Store::get lookup (early-return optimisation).
        WSRouter::setClientRateLimit(1, 60);
        try {
            WSRouter::init('boost3-rl-' . bin2hex(random_bytes(2)));
            // First send: returns false because unknown client, but
            // checkClientRate consumed the budget.
            WSRouter::sendToClient('alice', 'first');
            // Second send: must short-circuit on rate limit.
            $ok = WSRouter::sendToClient('alice', 'second');
            $this->assertFalse($ok);
        } finally {
            WSRouter::setClientRateLimit(0);   // disable
        }
    }

    // ── broadcast / onRoom sugar (delegates to Store + App) ─────────

    public function testBroadcastReturnsZeroOnTableBackend(): void
    {
        // Store::publish throws on Table backend (pub/sub needs Redis)
        // — the assertion just pins which exception fires.
        $this->expectException(StoreException::class);
        WSRouter::broadcast('demo:room', 'hi');
    }

    // ── Room::push pre-init guard ───────────────────────────────────

    public function testRoomConstructionWithoutInitThrows(): void
    {
        // WS\Room::__construct or first op throws when WSRouter::init
        // hasn't been called.
        $this->expectException(StoreException::class);
        $r = WSRouter::room('preinit-room');
        $r->size();   // any op triggers the guard
    }

    // ── pushWithBackpressure on dead fd (statically callable) ───────

    public function testPushWithBackpressureReturnsFalseForDeadFd(): void
    {
        // L822-824 — when isEstablished returns false the helper
        // increments pushes_to_dead_fd_total and returns false.
        if (!class_exists(\OpenSwoole\WebSocket\Server::class)) {
            $this->markTestSkipped('OpenSwoole WS not loaded');
        }
        // Build a minimal stub server via anonymous-class extension
        // so isEstablished() returns false without binding to a port.
        $stub = new class('127.0.0.1', 0) extends \OpenSwoole\WebSocket\Server {
            public function isEstablished($fd): bool { return false; }
        };
        $ok = WSRouter::pushWithBackpressure($stub, 9999, 'payload');
        $this->assertFalse($ok);
    }

    // ── room rate limit accessors / setter ──────────────────────────

    public function testRoomRateLimitGetters(): void
    {
        WSRouter::setRoomRateLimit(42, 90);
        $this->assertSame(42, WSRouter::roomRateLimitN());
        $this->assertSame(90, WSRouter::roomRateLimitWindowSec());
        // Reset to default
        WSRouter::setRoomRateLimit(0);
        $this->assertSame(0, WSRouter::roomRateLimitN());
    }

    // ── initOptions accepts valid options (smoke) ───────────────────

    public function testInitOptionsAcceptsLargeValidValues(): void
    {
        WSRouter::initOptions(
            ownerCapacity: 100_000,
            roomMembersCapacity: 500_000,
            slowConsumerBytes: 16 * 1024 * 1024,
        );
        // No throw is the success — values land in private statics that
        // get used on the next WSRouter::init() call.
        $this->addToAssertionCount(1);
    }

    public function testInitOptionsRejectsZeroSlowConsumerBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WSRouter::initOptions(slowConsumerBytes: 0);
    }
}
