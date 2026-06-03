<?php

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;

use function ZealPHP\zealphp_log_dir_candidates;

/**
 * The runtime/log/PID directory resolver. ZealPHP defaults to the shared
 * /tmp/zealphp but must fall back to a per-user dir when that path is owned by
 * another user (e.g. root started a server there first) — otherwise a non-root
 * user can't write the PID file and `php app.php` fails to record/stop itself.
 * `zealphp_log_dir_candidates()` is the pure ordering; `resolve_log_dir()` picks
 * the first writable one.
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

    public function testSharedDefaultRanksBeforePerUserFallback(): void
    {
        $origLogDir = getenv('ZEALPHP_LOG_DIR');
        $origXdg = getenv('XDG_RUNTIME_DIR');
        try {
            putenv('ZEALPHP_LOG_DIR');
            putenv('XDG_RUNTIME_DIR');

            $candidates = zealphp_log_dir_candidates();
            $this->assertContains('/tmp/zealphp', $candidates);

            $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '';
            if ($uid !== '') {
                $perUser = rtrim(sys_get_temp_dir(), '/') . '/zealphp-' . $uid;
                $this->assertContains($perUser, $candidates, 'a per-user fallback must exist');
                $this->assertLessThan(
                    array_search($perUser, $candidates, true),
                    array_search('/tmp/zealphp', $candidates, true),
                    '/tmp/zealphp must be preferred over the per-user fallback (BC)'
                );
            }
        } finally {
            $this->restoreEnv('ZEALPHP_LOG_DIR', $origLogDir);
            $this->restoreEnv('XDG_RUNTIME_DIR', $origXdg);
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

    public function testXdgRuntimeDirIsAPreferredPerUserFallback(): void
    {
        $origXdg = getenv('XDG_RUNTIME_DIR');
        $origLogDir = getenv('ZEALPHP_LOG_DIR');
        try {
            putenv('ZEALPHP_LOG_DIR');
            putenv('XDG_RUNTIME_DIR=/run/user/4242');
            $candidates = zealphp_log_dir_candidates();
            $this->assertContains('/run/user/4242/zealphp', $candidates);
            // XDG runtime dir (per-user, well-defined) ranks before the temp-dir fallback.
            $uid = function_exists('posix_getuid') ? (string) posix_getuid() : '';
            if ($uid !== '') {
                $perUserTmp = rtrim(sys_get_temp_dir(), '/') . '/zealphp-' . $uid;
                $this->assertLessThan(
                    array_search($perUserTmp, $candidates, true),
                    array_search('/run/user/4242/zealphp', $candidates, true),
                    'XDG_RUNTIME_DIR should rank before the temp-dir fallback'
                );
            }
        } finally {
            $this->restoreEnv('XDG_RUNTIME_DIR', $origXdg);
            $this->restoreEnv('ZEALPHP_LOG_DIR', $origLogDir);
        }
    }
}
