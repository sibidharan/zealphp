<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Store;

/**
 * Patch-coverage for the v0.2.40 Store facade additions:
 *   - `Store::hasSetOps()` — backend-detection helper for Set primitives.
 *   - `Store::iteratePaged()` — cursor-based pagination (Table impl).
 *   - Backend constants — `Store::BACKEND_*` + driver-prefer constants.
 *   - `Store::evalScript` / `Store::compareAndSet` / `Store::sadd` / etc.
 *     — throw clear StoreException on Table backend (Redis-required).
 *
 * The Redis path is exercised by the v0.2.40 cross-host smoke
 * (scripts/smoke-v0.2.40.php) + StoreFacadeParityTest.
 */
final class StoreV040ApiTest extends TestCase
{
    protected function setUp(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
    }

    // ── hasSetOps ───────────────────────────────────────────────────────

    public function testHasSetOpsFalseOnTable(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        $this->assertFalse(Store::hasSetOps());
    }

    // ── backend constants ───────────────────────────────────────────────

    public function testBackendConstants(): void
    {
        $this->assertSame('table',     Store::BACKEND_TABLE);
        $this->assertSame('redis',     Store::BACKEND_REDIS);
        $this->assertSame('tiered',    Store::BACKEND_TIERED);
        $this->assertSame('memcached', Store::BACKEND_MEMCACHED);
        $this->assertSame('auto',      Store::PREFER_AUTO);
        $this->assertSame('phpredis',  Store::PREFER_PHPREDIS);
        $this->assertSame('predis',    Store::PREFER_PREDIS);
    }

    // ── iteratePaged (Table backend) ────────────────────────────────────

    public function testIteratePagedReturnsEverythingOnSingleBatch(): void
    {
        Store::make('paged-1', 100, ['v' => [Store::TYPE_INT, 8]]);
        for ($i = 0; $i < 5; $i++) {
            Store::set('paged-1', "k$i", ['v' => $i]);
        }
        $page = Store::iteratePaged('paged-1', '0', 100);
        $this->assertSame('0', $page['cursor'], 'cursor=0 → end-of-scan');
        $this->assertCount(5, $page['rows']);
    }

    public function testIteratePagedWalksAcrossMultipleBatches(): void
    {
        Store::make('paged-2', 100, ['v' => [Store::TYPE_INT, 8]]);
        for ($i = 0; $i < 25; $i++) {
            Store::set('paged-2', "k$i", ['v' => $i]);
        }
        $next = '0';
        $total = 0;
        $pages = 0;
        do {
            $page = Store::iteratePaged('paged-2', $next, 7);
            $total += count($page['rows']);
            $next   = $page['cursor'];
            $pages++;
            $this->assertLessThan(10, $pages, 'pagination should converge in a bounded number of pages');
        } while ($next !== '0');
        $this->assertSame(25, $total, 'all rows drained');
    }

    public function testIteratePagedOnUnknownTableReturnsEmpty(): void
    {
        $page = Store::iteratePaged('does-not-exist', '0', 10);
        $this->assertSame(['cursor' => '0', 'rows' => []], $page);
    }

    // ── Redis-only methods throw clearly on Table ───────────────────────

    public function testEvalScriptThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        $this->expectExceptionMessageMatches('/requires the Redis or Tiered backend/');
        Store::evalScript('return 1');
    }

    public function testCompareAndSetThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::compareAndSet('any', 'any', 'col', '1', '2');
    }

    public function testSaddThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::sadd('any-key', 'member');
    }

    public function testScardThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::scard('any-key');
    }

    public function testSscanCursorThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::sscanCursor('any-key');
    }

    public function testSdelThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::sdel('any-key');
    }

    public function testSremThrowsOnTableBackend(): void
    {
        $this->expectException(\ZealPHP\Store\StoreException::class);
        Store::srem('any-key', 'member');
    }
}
