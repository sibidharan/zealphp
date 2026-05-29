<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;

/**
 * Tests for the session-config fluent setters added in the
 * concurrent-session-merge PR: sessionTtl / sessionMaxRows /
 * sessionDataSize / sessionSavePath / sessionHandler.
 *
 * Each follows the App configurable contract (no-arg = getter, one-arg =
 * setter+return). The numeric setters clamp to safe minimums; sessionHandler
 * uses func_num_args() so an explicit null clears it while a no-arg call reads.
 */
final class AppSessionConfigTest extends TestCase
{
    private int $ttl;
    private int $maxRows;
    private int $dataSize;
    private string $savePath;
    /** @var string|\SessionHandlerInterface|null */
    private $handler;

    protected function setUp(): void
    {
        $this->ttl = App::$session_ttl;
        $this->maxRows = App::$session_max_rows;
        $this->dataSize = App::$session_data_size;
        $this->savePath = App::$session_save_path;
        $this->handler = App::$session_handler;
    }

    protected function tearDown(): void
    {
        App::$session_ttl = $this->ttl;
        App::$session_max_rows = $this->maxRows;
        App::$session_data_size = $this->dataSize;
        App::$session_save_path = $this->savePath;
        App::$session_handler = $this->handler;
    }

    // ── sessionTtl ────────────────────────────────────────────────────────

    public function testSessionTtlGetterSetter(): void
    {
        $this->assertSame(App::$session_ttl, App::sessionTtl());
        $this->assertSame(900, App::sessionTtl(900));
        $this->assertSame(900, App::$session_ttl);
    }

    public function testSessionTtlClampsToMinimumOne(): void
    {
        App::sessionTtl(0);
        $this->assertSame(1, App::$session_ttl);
        App::sessionTtl(-50);
        $this->assertSame(1, App::$session_ttl);
    }

    // ── sessionMaxRows ────────────────────────────────────────────────────

    public function testSessionMaxRowsGetterSetterAndClamp(): void
    {
        $this->assertSame(2048, App::sessionMaxRows(2048));
        $this->assertSame(2048, App::$session_max_rows);
        // Clamp: minimum 16.
        App::sessionMaxRows(4);
        $this->assertSame(16, App::$session_max_rows);
    }

    // ── sessionDataSize ───────────────────────────────────────────────────

    public function testSessionDataSizeGetterSetterAndClamp(): void
    {
        $this->assertSame(32768, App::sessionDataSize(32768));
        $this->assertSame(32768, App::$session_data_size);
        // Clamp: minimum 1024.
        App::sessionDataSize(100);
        $this->assertSame(1024, App::$session_data_size);
    }

    // ── sessionSavePath ───────────────────────────────────────────────────

    public function testSessionSavePathGetterSetter(): void
    {
        $this->assertSame('/var/tmp/zp-sessions', App::sessionSavePath('/var/tmp/zp-sessions'));
        $this->assertSame('/var/tmp/zp-sessions', App::$session_save_path);
        // No-arg reads it back.
        $this->assertSame('/var/tmp/zp-sessions', App::sessionSavePath());
    }

    // ── sessionHandler ────────────────────────────────────────────────────

    public function testSessionHandlerSetAndGetString(): void
    {
        $this->assertSame('table', App::sessionHandler('table'));
        $this->assertSame('table', App::$session_handler);
        $this->assertSame('table', App::sessionHandler());
    }

    public function testSessionHandlerExplicitNullClears(): void
    {
        App::$session_handler = 'redis';
        // Explicit null must clear (func_num_args() === 1).
        $this->assertNull(App::sessionHandler(null));
        $this->assertNull(App::$session_handler);
    }

    public function testSessionHandlerNoArgIsGetterOnly(): void
    {
        App::$session_handler = 'file';
        // No args → must NOT mutate (func_num_args() === 0).
        $this->assertSame('file', App::sessionHandler());
        $this->assertSame('file', App::$session_handler);
    }

    public function testSessionHandlerAcceptsInstance(): void
    {
        $instance = new \ZealPHP\Session\Handler\FileSessionHandler();
        $this->assertSame($instance, App::sessionHandler($instance));
        $this->assertSame($instance, App::$session_handler);
    }
}
