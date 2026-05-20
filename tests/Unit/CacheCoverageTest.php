<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Cache;
use ZealPHP\Store;
use ZealPHP\Tests\TestCase;

/**
 * Branch coverage for src/Cache.php not reached by CacheTest.php:
 *
 *   - init() with gcIntervalMs > 0 → registerGc() registers an onWorkerStart
 *     hook; the hook's non-zero-worker guard (early return) is then exercised
 *     by invoking the captured hook directly.
 *   - readFile()/has() guards on an unreadable file (chmod 000): fopen /
 *     file_get_contents return false.
 *   - gcFiles() skips an unreadable .cache file (fopen false → continue).
 *
 * Cache is a process-wide singleton shared with CacheTest. Every test here
 * snapshots the live state (dir + initialized flag) and restores it via
 * Cache::initForTest() in tearDown so suite ordering is unaffected.
 */
class CacheCoverageTest extends TestCase
{
    private string $isolatedDir;
    private string $savedDir = '';
    /** @var list<string> */
    private array $chmodFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $dirProp = new \ReflectionProperty(Cache::class, 'dir');
        $this->savedDir = (string) $dirProp->getValue();
        $this->isolatedDir = sys_get_temp_dir() . '/zealphp_cache_cov_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Restore permissions so cleanup can remove the files.
        foreach ($this->chmodFiles as $f) {
            @chmod($f, 0644);
            @unlink($f);
        }
        $this->chmodFiles = [];

        if (is_dir($this->isolatedDir)) {
            foreach (glob($this->isolatedDir . '/*') ?: [] as $f) {
                @chmod($f, 0644);
                @unlink($f);
            }
            @rmdir($this->isolatedDir);
        }

        // Re-pin the shared cache that CacheTest (and the rest of the suite) use.
        $initProp = new \ReflectionProperty(Cache::class, 'initialized');
        $initProp->setValue(null, false);
        if ($this->savedDir !== '') {
            Cache::initForTest($this->savedDir);
        }
        parent::tearDown();
    }

    public function testInitWithGcIntervalRegistersWorkerHook(): void
    {
        $hooksProp = new \ReflectionProperty(App::class, 'workerStartHooks');
        $before = count((array) $hooksProp->getValue());

        $initProp = new \ReflectionProperty(Cache::class, 'initialized');
        $initProp->setValue(null, false);

        // gcIntervalMs > 0 → registerGc() → App::onWorkerStart(...).
        Cache::init(maxRows: 16, cacheDir: $this->isolatedDir, gcIntervalMs: 30000);

        $hooks = (array) $hooksProp->getValue();
        $this->assertGreaterThan($before, count($hooks));

        // Invoke the just-registered hook with a NON-zero worker id → the
        // hook's `if ($workerId !== 0) return;` early-exit branch runs (no
        // App::tick(), so no live event loop needed).
        $hook = end($hooks);
        $this->assertIsCallable($hook);
        $hook(null, 1); // workerId 1 → early return, no timer scheduled.
        $this->addToAssertionCount(1);
    }

    public function testReadFileGuardsOnUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root — chmod 000 still readable');
        }
        $initProp = new \ReflectionProperty(Cache::class, 'initialized');
        $initProp->setValue(null, false);
        Cache::initForTest($this->isolatedDir, maxRows: 16);

        // Write a valid two-tier entry, drop the memory row, then make the
        // backing file unreadable so readFile()/has() hit the fopen /
        // file_get_contents === false guards.
        Cache::set('unreadable', 'secret');
        $hash = md5('unreadable');
        $table = Store::table('__cache');
        $this->assertNotNull($table);
        $table->del($hash);

        $file = $this->isolatedDir . '/' . $hash . '.cache';
        $this->assertFileExists($file);
        @chmod($file, 0000);
        $this->chmodFiles[] = $file;

        // get() → readFile() → file_get_contents false → null → default.
        $this->assertSame('fallback', Cache::get('unreadable', 'fallback'));
        // has() → fopen false → false.
        $this->assertFalse(Cache::has('unreadable'));
    }

    public function testGcFilesSkipsUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root — chmod 000 still readable');
        }
        $initProp = new \ReflectionProperty(Cache::class, 'initialized');
        $initProp->setValue(null, false);
        Cache::initForTest($this->isolatedDir, maxRows: 16);

        Cache::set('gc-unreadable', 'x', ttl: 3600);
        $hash = md5('gc-unreadable');
        $file = $this->isolatedDir . '/' . $hash . '.cache';
        $this->assertFileExists($file);
        @chmod($file, 0000);
        $this->chmodFiles[] = $file;

        // gcFiles() iterates the dir; fopen() on the unreadable file returns
        // false → the `continue` branch skips it. A non-expired entry (ttl
        // 3600) must NOT be garbage-collected, whether or not it was readable.
        Cache::gcFiles();
        $this->assertFileExists($file, 'gcFiles() must not delete a skipped/non-expired entry');
    }
}
