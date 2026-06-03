<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\Session\Handler\CoroutineMemorySessionHandler;
use OpenSwoole\Coroutine as co;

/**
 * Characterization tests for ZealPHP\Session\Handler\CoroutineMemorySessionHandler.
 *
 * The handler keeps session blobs in a per-coroutine in-memory map keyed by
 * `co::getCid()`. Outside a coroutine `getCid()` returns -1, which is a
 * perfectly valid array key, so every read/write/destroy/gc path is
 * reachable in a plain PHPUnit process without standing up the OpenSwoole
 * scheduler. (read() calls elog(); App::$cwd is set so that path is safe.)
 */
class CoroutineMemorySessionHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        // elog() inside read() dereferences App::$cwd when debug logging is
        // on — set it so the call never trips the uninitialized-property
        // fatal regardless of the ambient ZEALPHP_DEBUG setting.
        App::$cwd = dirname(__DIR__, 2);
    }

    public function testImplementsSessionHandlerInterface(): void
    {
        $this->assertInstanceOf(\SessionHandlerInterface::class, new CoroutineMemorySessionHandler());
    }

    public function testOpenIsNoOpReturningTrue(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertTrue($h->open('', 'PHPSESSID'));
        $this->assertTrue($h->open('/ignored/path', 'OTHER'));
    }

    public function testWriteReturnsTrue(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertTrue($h->write('s1', 'user_id|i:1;'));
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $payload = 'user_id|i:42;name|s:5:"alice";';
        $h->write('rid', $payload);
        $this->assertSame($payload, $h->read('rid'));
    }

    public function testReadMissingIdReturnsEmptyString(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertSame('', $h->read('never_written'));
    }

    public function testOverwriteReplacesData(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('rid', 'v|s:3:"old";');
        $h->write('rid', 'v|s:3:"new";');
        $this->assertSame('v|s:3:"new";', $h->read('rid'));
    }

    public function testDestroyRemovesSession(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('rid', 'x|i:1;');
        $this->assertSame('x|i:1;', $h->read('rid'));
        $this->assertTrue($h->destroy('rid'));
        $this->assertSame('', $h->read('rid'));
    }

    public function testDestroyMissingIdReturnsTrue(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertTrue($h->destroy('never_existed'));
    }

    public function testCloseReturnsTrue(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertTrue($h->close());
    }

    public function testCloseClearsCoroutineBucketSoMapCannotGrowUnbounded(): void
    {
        // The leak fix: close() drops this coroutine's session bucket. Before
        // the fix close() was a no-op, so $sessions accumulated an entry per
        // distinct (cid, sessionId) over the worker's whole lifetime (cids are
        // reused, but the map only grew). After close(), the just-written data
        // is gone — proving the bucket was pruned.
        $h = new CoroutineMemorySessionHandler();
        $h->write('rid', 'x|i:1;');
        $this->assertSame('x|i:1;', $h->read('rid'));
        $this->assertTrue($h->close());
        $this->assertSame('', $h->read('rid'), 'close() must clear the coroutine bucket');
    }

    public function testGcRemovesStaleSessions(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('stale', 'old|i:1;');
        $this->assertSame('old|i:1;', $h->read('stale'));
        // last_access is "now"; a negative maxLifetime makes
        // (time() - last_access) > maxLifetime true immediately.
        $this->assertSame(0, $h->gc(-1));
        $this->assertSame('', $h->read('stale'));
    }

    public function testGcKeepsFreshSessions(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('fresh', 'new|i:1;');
        // Generous lifetime — entry written "now" must survive.
        $this->assertSame(0, $h->gc(3600));
        $this->assertSame('new|i:1;', $h->read('fresh'));
    }

    public function testGcOnEmptyStoreReturnsZero(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $this->assertSame(0, $h->gc(60));
    }

    public function testMultipleSessionsCoexistInSameCoroutine(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('a', 'd|s:1:"A";');
        $h->write('b', 'd|s:1:"B";');
        $this->assertSame('d|s:1:"A";', $h->read('a'));
        $this->assertSame('d|s:1:"B";', $h->read('b'));
        $h->destroy('a');
        $this->assertSame('', $h->read('a'));
        $this->assertSame('d|s:1:"B";', $h->read('b'));
    }

    public function testReadRefreshesLastAccessSoGcCanSpare(): void
    {
        $h = new CoroutineMemorySessionHandler();
        $h->write('rid', 'x|i:1;');
        // read() bumps last_access to time(); with a positive lifetime
        // the just-read entry stays alive through gc.
        $this->assertSame('x|i:1;', $h->read('rid'));
        $this->assertSame(0, $h->gc(3600));
        $this->assertSame('x|i:1;', $h->read('rid'));
    }

    public function testStoreIsKeyedByCoroutineId(): void
    {
        // Documents the per-coroutine isolation contract: data written under
        // the current cid is reachable via co::getCid() (here -1, the
        // non-coroutine sentinel).
        $h = new CoroutineMemorySessionHandler();
        $h->write('rid', 'k|i:9;');
        $this->assertSame(-1, co::getCid(), 'tests run outside a coroutine');
        $this->assertSame('k|i:9;', $h->read('rid'));
    }
}
