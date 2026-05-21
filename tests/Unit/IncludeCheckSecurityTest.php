<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * Security regression coverage for the static-serve guard in src/App.php
 * (Apache ap_directory_walk / resolve_symlink parity, Task C1 + M10):
 *
 *  - pathWithinRoot(): boundary-aware containment — exact match or true
 *    descendant, never a shared-string-prefix sibling.
 *  - includeCheck(): realpath() canonicalization refuses symlinks escaping the
 *    document root, refuses non-regular files (FIFO/device), keeps dotfile
 *    blocking.
 *  - isEnotdir(): a path whose ancestor is a file (not a directory) is detected
 *    so the caller can return 403 instead of 404.
 *
 * Uses a throwaway temp document root so the assertions don't depend on the
 * repo's public/ layout. Filesystem-dependent cases self-skip where the
 * environment forbids symlinks / FIFOs.
 */
class IncludeCheckSecurityTest extends TestCase
{
    private static App $app;
    private string $docRoot;
    private string $origDocRoot;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19995, ZEALPHP_ROOT);
        }
        $app = App::instance();
        self::assertNotNull($app);
        self::$app = $app;
    }

    public static function tearDownAfterClass(): void
    {
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    protected function setUp(): void
    {
        $this->origDocRoot = App::$document_root;
        $base = sys_get_temp_dir() . '/zealphp-includecheck-' . getmypid() . '-' . uniqid();
        mkdir($base, 0755, true);
        $this->docRoot = (string) realpath($base);
        App::$document_root = $this->docRoot;       // absolute → used verbatim
        App::$block_dotfiles = true;
    }

    protected function tearDown(): void
    {
        App::$document_root = $this->origDocRoot;
        $this->rrmdir($this->docRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_dir() follows symlinks; never recurse through a link, just unlink it.
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────
    // pathWithinRoot() — pure containment decision
    // ─────────────────────────────────────────────────────────────

    public function testPathWithinRootAcceptsExactMatch(): void
    {
        $this->assertTrue(App::pathWithinRoot('/var/www/public', '/var/www/public'));
    }

    public function testPathWithinRootAcceptsDescendant(): void
    {
        $this->assertTrue(App::pathWithinRoot('/var/www/public/css/app.css', '/var/www/public'));
        $this->assertTrue(App::pathWithinRoot('/var/www/public/a/b/c.php', '/var/www/public'));
    }

    public function testPathWithinRootRejectsSiblingWithSharedPrefix(): void
    {
        // The bug a plain strpos(...)===0 prefix match would let through.
        $this->assertFalse(App::pathWithinRoot('/var/www/public-data/secret', '/var/www/public'));
        $this->assertFalse(App::pathWithinRoot('/var/www/publicsecret', '/var/www/public'));
    }

    public function testPathWithinRootRejectsOutsidePath(): void
    {
        $this->assertFalse(App::pathWithinRoot('/etc/passwd', '/var/www/public'));
        $this->assertFalse(App::pathWithinRoot('/var/www', '/var/www/public'));
    }

    public function testPathWithinRootRejectsEmptyArgs(): void
    {
        $this->assertFalse(App::pathWithinRoot('', '/var/www/public'));
        $this->assertFalse(App::pathWithinRoot('/var/www/public/x', ''));
    }

    public function testPathWithinRootIgnoresRootTrailingSlash(): void
    {
        $this->assertTrue(App::pathWithinRoot('/var/www/public/x', '/var/www/public/'));
    }

    // ─────────────────────────────────────────────────────────────
    // includeCheck() — regular file inside docroot
    // ─────────────────────────────────────────────────────────────

    public function testIncludeCheckAcceptsRegularFile(): void
    {
        $file = $this->docRoot . '/page.php';
        file_put_contents($file, '<?php echo "ok";');
        $this->assertTrue(self::$app->includeCheck($file));
    }

    public function testIncludeCheckRejectsEmptyAndNonString(): void
    {
        $this->assertFalse(self::$app->includeCheck(''));
        $this->assertFalse(self::$app->includeCheck(false));
        $this->assertFalse(self::$app->includeCheck(null));
    }

    public function testIncludeCheckRejectsNonexistentFile(): void
    {
        $this->assertFalse(self::$app->includeCheck($this->docRoot . '/nope.php'));
    }

    public function testIncludeCheckRejectsDotfile(): void
    {
        $file = $this->docRoot . '/.env';
        file_put_contents($file, 'SECRET=1');
        $this->assertFalse(self::$app->includeCheck($file));
    }

    public function testIncludeCheckRejectsDotfileInSubdir(): void
    {
        mkdir($this->docRoot . '/.git');
        $file = $this->docRoot . '/.git/config';
        file_put_contents($file, '[core]');
        $this->assertFalse(self::$app->includeCheck($file));
    }

    public function testIncludeCheckRejectsDirectory(): void
    {
        mkdir($this->docRoot . '/sub');
        // A directory is not a regular file — serveDirectory() handles dirs.
        $this->assertFalse(self::$app->includeCheck($this->docRoot . '/sub'));
    }

    // ─────────────────────────────────────────────────────────────
    // includeCheck() — symlink escape (CRITICAL / C1)
    // ─────────────────────────────────────────────────────────────

    public function testIncludeCheckRejectsSymlinkEscapingDocroot(): void
    {
        $target = sys_get_temp_dir() . '/zealphp-escape-target-' . uniqid() . '.txt';
        file_put_contents($target, 'secret outside docroot');
        $link = $this->docRoot . '/escape.php';
        if (!@symlink($target, $link)) {
            @unlink($target);
            $this->markTestSkipped('symlinks not supported in this environment');
        }
        try {
            // realpath() resolves the link to its outside-docroot target → refused.
            $this->assertFalse(self::$app->includeCheck($link));
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function testIncludeCheckAcceptsSymlinkStayingInsideDocroot(): void
    {
        $real = $this->docRoot . '/real.php';
        file_put_contents($real, '<?php echo "ok";');
        $link = $this->docRoot . '/alias.php';
        if (!@symlink($real, $link)) {
            $this->markTestSkipped('symlinks not supported in this environment');
        }
        try {
            // Target is inside docroot → allowed (Apache FollowSymLinks-equivalent).
            $this->assertTrue(self::$app->includeCheck($link));
        } finally {
            @unlink($link);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // includeCheck() — non-regular files (M10)
    // ─────────────────────────────────────────────────────────────

    public function testIncludeCheckRejectsFifo(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('ext-posix not loaded — cannot create a FIFO');
        }
        $fifo = $this->docRoot . '/pipe';
        if (!@posix_mkfifo($fifo, 0644) || !file_exists($fifo)) {
            $this->markTestSkipped('FIFO creation not permitted in this environment');
        }
        try {
            $this->assertFalse(self::$app->includeCheck($fifo), 'non-regular file (FIFO) must be refused');
        } finally {
            @unlink($fifo);
        }
    }

    public function testIncludeCheckRejectsDeviceNode(): void
    {
        // /dev/null is a character device; symlink it into docroot and confirm
        // the realpath() target is refused as a non-regular file.
        $link = $this->docRoot . '/dev.php';
        if (!@symlink('/dev/null', $link)) {
            $this->markTestSkipped('symlinks not supported in this environment');
        }
        try {
            $this->assertFalse(self::$app->includeCheck($link), 'device node must be refused');
        } finally {
            @unlink($link);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // isEnotdir() — Apache "deny rather than assume not found"
    // ─────────────────────────────────────────────────────────────

    public function testIsEnotdirTrueWhenAncestorIsFile(): void
    {
        $file = $this->docRoot . '/file.php';
        file_put_contents($file, '<?php');
        // Requesting /file.php/extra: file.php is a regular file, not a dir.
        $this->assertTrue(App::isEnotdir($file . '/extra'));
        $this->assertTrue(App::isEnotdir($file . '/extra/more.php'));
    }

    public function testIsEnotdirFalseForDirectoryAncestors(): void
    {
        mkdir($this->docRoot . '/d');
        $this->assertFalse(App::isEnotdir($this->docRoot . '/d/missing.php'));
    }

    public function testIsEnotdirFalseForPlainMissingPath(): void
    {
        // No existing file component → ENOENT, not ENOTDIR.
        $this->assertFalse(App::isEnotdir($this->docRoot . '/nope/also-nope.php'));
    }

    public function testIsEnotdirFalseForExistingFileItself(): void
    {
        $file = $this->docRoot . '/exists.php';
        file_put_contents($file, '<?php');
        // The path IS the file (no deeper component) — the ancestor (docroot)
        // is a directory, so this is not ENOTDIR.
        $this->assertFalse(App::isEnotdir($file));
    }
}
