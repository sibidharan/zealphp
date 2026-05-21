<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\App;
use ZealPHP\Session\Handler\FileSessionHandler;

/**
 * Characterization tests for ZealPHP\Session\Handler\FileSessionHandler.
 *
 * This is the default file-backed SessionHandlerInterface implementation —
 * sessions live as `sess_<id>` files under a save path. Every method is
 * exercised against a real per-test temp directory (no mocks): open()
 * creates the directory, write() persists the serialized blob, read()
 * returns it verbatim, destroy() unlinks, gc() prunes by mtime, and
 * close() is the trivial true.
 */
class FileSessionHandlerTest extends TestCase
{
    private string $savePath;

    protected function setUp(): void
    {
        App::$cwd = dirname(__DIR__, 2);
        // Unique per-test directory; created fresh and torn down so the
        // filesystem assertions can't collide with /var/lib/php/sessions
        // or another test run.
        $this->savePath = sys_get_temp_dir() . '/zealphp_file_handler_' . getmypid() . '_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->savePath)) {
            foreach (glob($this->savePath . '/sess_*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->savePath);
        }
    }

    private function opened(): FileSessionHandler
    {
        $h = new FileSessionHandler();
        $h->open($this->savePath, 'PHPSESSID');
        return $h;
    }

    public function testImplementsSessionHandlerInterface(): void
    {
        $this->assertInstanceOf(\SessionHandlerInterface::class, new FileSessionHandler());
    }

    public function testOpenReturnsTrueAndCreatesDirectory(): void
    {
        $h = new FileSessionHandler();
        $this->assertDirectoryDoesNotExist($this->savePath);
        $this->assertTrue($h->open($this->savePath, 'PHPSESSID'));
        $this->assertDirectoryExists($this->savePath);
    }

    public function testOpenOnExistingDirectorySucceeds(): void
    {
        @mkdir($this->savePath, 0700, true);
        $h = new FileSessionHandler();
        $this->assertTrue($h->open($this->savePath, 'PHPSESSID'));
    }

    public function testWritePersistsFile(): void
    {
        $h = $this->opened();
        $payload = 'user_id|i:42;name|s:5:"alice";';
        $this->assertTrue($h->write('abc123', $payload));
        $this->assertFileExists($this->savePath . '/sess_abc123');
        $this->assertSame($payload, (string) file_get_contents($this->savePath . '/sess_abc123'));
    }

    public function testReadReturnsWhatWasWritten(): void
    {
        $h = $this->opened();
        $payload = 'cart|a:1:{i:0;s:6:"item-a";}';
        $h->write('rid', $payload);
        $this->assertSame($payload, $h->read('rid'));
    }

    public function testReadOfMissingIdReturnsEmptyString(): void
    {
        $h = $this->opened();
        $this->assertSame('', $h->read('does_not_exist'));
    }

    public function testWriteThenReadRoundTripWithEmptyPayload(): void
    {
        $h = $this->opened();
        $this->assertTrue($h->write('empty', ''));
        $this->assertSame('', $h->read('empty'));
        // The file should physically exist even though content is empty.
        $this->assertFileExists($this->savePath . '/sess_empty');
    }

    public function testDestroyRemovesFile(): void
    {
        $h = $this->opened();
        $h->write('todelete', 'x|i:1;');
        $this->assertFileExists($this->savePath . '/sess_todelete');
        $this->assertTrue($h->destroy('todelete'));
        $this->assertFileDoesNotExist($this->savePath . '/sess_todelete');
        $this->assertSame('', $h->read('todelete'));
    }

    public function testDestroyMissingIdStillReturnsTrue(): void
    {
        $h = $this->opened();
        // No file exists for this id — destroy() is idempotent.
        $this->assertTrue($h->destroy('never_existed'));
    }

    public function testGcRemovesStaleFiles(): void
    {
        $h = $this->opened();
        $h->write('stale', 'old|i:1;');
        $stale = $this->savePath . '/sess_stale';
        $this->assertFileExists($stale);
        // Force the mtime far into the past so mtime + maxLifetime < now.
        touch($stale, time() - 3600);
        clearstatcache();

        $this->assertSame(0, $h->gc(60));
        $this->assertFileDoesNotExist($stale);
    }

    public function testGcKeepsFreshFiles(): void
    {
        $h = $this->opened();
        $h->write('fresh', 'new|i:1;');
        $fresh = $this->savePath . '/sess_fresh';

        // mtime is "now"; with a generous lifetime it must survive.
        $this->assertSame(0, $h->gc(3600));
        $this->assertFileExists($fresh);
    }

    public function testGcOnEmptyDirectoryReturnsZero(): void
    {
        $h = $this->opened();
        $this->assertSame(0, $h->gc(60));
    }

    public function testCloseReturnsTrue(): void
    {
        $h = $this->opened();
        $this->assertTrue($h->close());
    }

    public function testMultipleSessionsAreIsolatedByFile(): void
    {
        $h = $this->opened();
        $h->write('a', 'data|s:1:"A";');
        $h->write('b', 'data|s:1:"B";');
        $this->assertSame('data|s:1:"A";', $h->read('a'));
        $this->assertSame('data|s:1:"B";', $h->read('b'));
        // Destroying one must not touch the other.
        $h->destroy('a');
        $this->assertSame('', $h->read('a'));
        $this->assertSame('data|s:1:"B";', $h->read('b'));
    }
}
