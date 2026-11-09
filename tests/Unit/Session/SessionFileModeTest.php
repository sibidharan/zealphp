<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;

use function ZealPHP\Session\zeal_session_create_private_file;

/**
 * Session files are created 0600 from the first instant — not created with
 * the umask mode and chmod-ed afterwards, which left a window where another
 * local user could open the file. The creation path must not depend on (or
 * change) the process umask.
 */
final class SessionFileModeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zp_sessmode_' . getmypid() . '_' . uniqid();
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    public function testCreatesFile0600EvenWithAPermissiveUmask(): void
    {
        $file = $this->dir . '/sess_abc';
        $old = umask(0);
        try {
            zeal_session_create_private_file($file);
            $this->assertSame(0, umask(), 'the umask is left alone');
        } finally {
            umask($old);
        }
        clearstatcache();
        $this->assertFileExists($file);
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame('', file_get_contents($file));
        $files = array_values(array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        $this->assertSame([$file], $files, 'no temp file is left behind');
    }

    public function testLeavesAnExistingFileUntouched(): void
    {
        $file = $this->dir . '/sess_existing';
        file_put_contents($file, 'k|i:1;');
        chmod($file, 0640);
        zeal_session_create_private_file($file);
        clearstatcache();
        $this->assertSame('k|i:1;', file_get_contents($file));
        $this->assertSame(0640, fileperms($file) & 0777);
    }

    public function testFileHandlerWriteCreatesFile0600WithPermissiveUmask(): void
    {
        $h = new \ZealPHP\Session\Handler\FileSessionHandler();
        $h->open($this->dir, 'PHPSESSID');
        $old = umask(0);
        try {
            $this->assertTrue($h->write('umask0', 'a|i:1;'));
        } finally {
            umask($old);
        }
        clearstatcache();
        $this->assertSame(0600, fileperms($this->dir . '/sess_umask0') & 0777);
    }
}
