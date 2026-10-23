<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * App::staticHandlerSymlinkEscapes() — the boot scan for symlinks that
 * OpenSwoole's static handler would follow out of the document root (it serves
 * those prefixes in C, before any PHP path guard).
 */
class StaticHandlerSymlinkScanTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zp_static_scan_' . getmypid() . '_' . bin2hex(random_bytes(3));
        mkdir($this->base . '/public/css', 0777, true);
        mkdir($this->base . '/public/private', 0777, true);
        mkdir($this->base . '/outside', 0777, true);
        file_put_contents($this->base . '/outside/secret.txt', 'secret');
        file_put_contents($this->base . '/public/css/site.css', 'body{}');
        file_put_contents($this->base . '/public/private/inside.txt', 'ok');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    /** @param list<string> $locations */
    private function scan(array $locations, bool $enabled = true): array
    {
        return App::staticHandlerSymlinkEscapes([
            'enable_static_handler'    => $enabled,
            'document_root'            => $this->base . '/public',
            'static_handler_locations' => $locations,
        ]);
    }

    public function testCleanTreeReportsNothing(): void
    {
        $this->assertSame([], $this->scan(['/css/']));
    }

    public function testLinkLeavingDocrootIsReported(): void
    {
        symlink($this->base . '/outside/secret.txt', $this->base . '/public/css/leak.css');
        $found = $this->scan(['/css/']);
        $this->assertCount(1, $found);
        $this->assertStringEndsWith('/css/leak.css', $found[0][0]);
        $this->assertSame(realpath($this->base . '/outside/secret.txt'), $found[0][1]);
    }

    public function testLinkStayingInsideDocrootIsAllowed(): void
    {
        symlink($this->base . '/public/private/inside.txt', $this->base . '/public/css/alias.txt');
        $this->assertSame([], $this->scan(['/css/']));
    }

    public function testStaticDirectoryThatIsItselfALinkIsReported(): void
    {
        symlink($this->base . '/outside', $this->base . '/public/assets');
        $found = $this->scan(['/css/', '/assets/']);
        $this->assertCount(1, $found);
        $this->assertStringEndsWith('/public/assets', $found[0][0]);
    }

    public function testLinksOutsideTheStaticPrefixesAreNotTheHandlersBusiness(): void
    {
        symlink($this->base . '/outside/secret.txt', $this->base . '/public/private/leak.txt');
        $this->assertSame([], $this->scan(['/css/']), 'PHP-served paths have their own realpath guard');
    }

    public function testEmptyLocationListScansWholeDocroot(): void
    {
        symlink($this->base . '/outside/secret.txt', $this->base . '/public/private/leak.txt');
        $this->assertCount(1, $this->scan([]));
    }

    public function testDisabledStaticHandlerSkipsTheScan(): void
    {
        symlink($this->base . '/outside/secret.txt', $this->base . '/public/css/leak.css');
        $this->assertSame([], $this->scan(['/css/'], false));
    }
}
