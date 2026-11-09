<?php

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

use function ZealPHP\zealphp_log_dir_candidates;
use function ZealPHP\zealphp_private_dir_ok;

/**
 * The runtime/log/PID directory resolver. Every default is per-user — a shared
 * /tmp/zealphp could be pre-created (or symlink-seeded) by another local user to
 * read or redirect logs, PID files and sessions (E-4 / D-1).
 * `zealphp_log_dir_candidates()` is the pure ordering; `resolve_log_dir()` picks
 * the first one `zealphp_private_dir_ok()` accepts (owned by us, not a symlink,
 * not group/world-writable).
 */
class LogDirCandidatesTest extends TestCase
{
    private function restoreEnv(string $key, string|false $val): void
    {
        if ($val === false) {
            putenv($key);
        } else {
            putenv("{$key}={$val}");
        }
    }

    public function testNoSharedTmpDirAndPerUserDirComesFirst(): void
    {
        $origLogDir = getenv('ZEALPHP_LOG_DIR');
        $origXdg = getenv('XDG_RUNTIME_DIR');
        try {
            putenv('ZEALPHP_LOG_DIR');
            putenv('XDG_RUNTIME_DIR');

            $candidates = zealphp_log_dir_candidates();
            // A shared /tmp/zealphp can be pre-created by any local user.
            $this->assertNotContains('/tmp/zealphp', $candidates);

            $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '';
            if ($uid !== '') {
                $perUser = rtrim(sys_get_temp_dir(), '/') . '/zealphp-' . $uid;
                $this->assertSame($perUser, $candidates[0], 'the per-user temp dir is the first default');
            }
        } finally {
            $this->restoreEnv('ZEALPHP_LOG_DIR', $origLogDir);
            $this->restoreEnv('XDG_RUNTIME_DIR', $origXdg);
        }
    }

    public function testPrivateDirIsCreated0700(): void
    {
        $dir = sys_get_temp_dir() . '/zp_privdir_' . getmypid() . '/a/b';
        try {
            $this->assertTrue(zealphp_private_dir_ok($dir));
            $this->assertSame(0700, fileperms($dir) & 0777);
        } finally {
            @rmdir($dir);
            @rmdir(dirname($dir));
            @rmdir(dirname($dir, 2));
        }
    }

    public function testPrivateDirRefusesSymlink(): void
    {
        $base = sys_get_temp_dir() . '/zp_privlink_' . getmypid();
        mkdir($base . '_target', 0700);
        symlink($base . '_target', $base);
        try {
            $this->assertFalse(zealphp_private_dir_ok($base));
        } finally {
            @unlink($base);
            @rmdir($base . '_target');
        }
    }

    public function testPrivateDirTightensOwnGroupWritableDir(): void
    {
        $dir = sys_get_temp_dir() . '/zp_privwide_' . getmypid();
        mkdir($dir);
        chmod($dir, 0777);
        try {
            $this->assertTrue(zealphp_private_dir_ok($dir));
            clearstatcache();
            $this->assertSame(0700, fileperms($dir) & 0777);
        } finally {
            @rmdir($dir);
        }
    }

    public function testPrivateDirRefusesDirOwnedBySomeoneElse(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            $this->markTestSkipped('needs a non-root uid');
        }
        // `/` is root-owned: must be refused even though it exists.
        $this->assertFalse(zealphp_private_dir_ok('/'));
        $this->assertFalse(zealphp_private_dir_ok('/proc'));
    }

    public function testPrivateDirRefusesRegularFile(): void
    {
        $f = sys_get_temp_dir() . '/zp_privfile_' . getmypid();
        touch($f);
        try {
            $this->assertFalse(zealphp_private_dir_ok($f));
        } finally {
            @unlink($f);
        }
    }

    public function testExplicitLogDirRanksFirst(): void
    {
        $orig = getenv('ZEALPHP_LOG_DIR');
        try {
            putenv('ZEALPHP_LOG_DIR=/custom/zealphp/logs');
            $candidates = zealphp_log_dir_candidates();
            $this->assertSame('/custom/zealphp/logs', $candidates[0]);
        } finally {
            $this->restoreEnv('ZEALPHP_LOG_DIR', $orig);
        }
    }

    /**
     * $XDG_RUNTIME_DIR is wiped at logout and unset under cron/systemd/sudo,
     * so using it would let `stop` resolve a different dir than the running
     * server: the per-user temp dir is the first default whatever it says.
     */
    public function testXdgRuntimeDirIsIgnored(): void
    {
        $origXdg = getenv('XDG_RUNTIME_DIR');
        $origLogDir = getenv('ZEALPHP_LOG_DIR');
        try {
            putenv('ZEALPHP_LOG_DIR');
            putenv('XDG_RUNTIME_DIR=/run/user/4242');
            $withXdg = zealphp_log_dir_candidates();
            putenv('XDG_RUNTIME_DIR');
            $withoutXdg = zealphp_log_dir_candidates();

            $this->assertNotContains('/run/user/4242/zealphp', $withXdg);
            $this->assertSame($withoutXdg, $withXdg, 'XDG_RUNTIME_DIR must not change resolution');
        } finally {
            $this->restoreEnv('XDG_RUNTIME_DIR', $origXdg);
            $this->restoreEnv('ZEALPHP_LOG_DIR', $origLogDir);
        }
    }
}
