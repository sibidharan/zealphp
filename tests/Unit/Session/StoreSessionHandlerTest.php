<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\Session\Handler\StoreSessionHandler;
use ZealPHP\Store;

/**
 * Drives StoreSessionHandler against the Table backend (no live Redis
 * required for the basic CRUD + GC sweep). The Redis-backend path is
 * the same code-path — just with cross-node visibility coming from
 * Store::defaultBackend() being Redis. Pinned by the Store unit suite.
 */
final class StoreSessionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        StoreSessionHandler::reset();
    }

    protected function tearDown(): void
    {
        StoreSessionHandler::reset();
    }

    public function testRegisterReturnsHandlerInstance(): void
    {
        $h = StoreSessionHandler::register(60);
        $this->assertInstanceOf(StoreSessionHandler::class, $h);
        $this->assertSame($h, StoreSessionHandler::instance());
    }

    public function testInstanceWithoutRegisterThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        StoreSessionHandler::instance();
    }

    public function testWriteReadDestroyRoundTrip(): void
    {
        $h = StoreSessionHandler::register(60);
        $this->assertTrue($h->write('sid-1', 'serialized payload bytes'));
        $this->assertSame('serialized payload bytes', $h->read('sid-1'));
        $this->assertTrue($h->destroy('sid-1'));
        $this->assertSame('', $h->read('sid-1'));
    }

    public function testReadReturnsEmptyForUnknownSession(): void
    {
        $h = StoreSessionHandler::register(60);
        $this->assertSame('', $h->read('does-not-exist'));
    }

    public function testReadExpiresSessionPastItsTtlAndDeletesIt(): void
    {
        // TTL = 1 second; write, wait, read should return '' AND clean up.
        $h = StoreSessionHandler::register(1);
        $h->write('expiring', 'about-to-die');
        sleep(2);
        $this->assertSame('', $h->read('expiring'));
        // Re-read: confirmed lazy-deleted by the previous read.
        $this->assertSame('', $h->read('expiring'));
    }

    public function testGcSweepsExpiredSessions(): void
    {
        $h = StoreSessionHandler::register(1);
        $h->write('a', 'session-a');
        $h->write('b', 'session-b');
        $h->write('c', 'session-c');
        $this->assertSame(3, Store::count('zealphp_sessions'));

        sleep(2);
        $deleted = $h->gc(1);
        $this->assertSame(3, $deleted);
        $this->assertSame(0, Store::count('zealphp_sessions'));
    }

    public function testOpenAndCloseReturnTrue(): void
    {
        $h = StoreSessionHandler::register(60);
        $this->assertTrue($h->open('', 'PHPSESSID'));
        $this->assertTrue($h->close());
    }
}
