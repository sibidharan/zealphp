<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\CLI;
use ZealPHP\Tests\TestCase;

/**
 * In-process coverage for the CLI lifecycle helpers that AppCliArgsTest can't
 * reach: the stop / orphan-recovery / pid-liveness paths.
 *
 * These are normally exercised by spawning a real server, but every branch
 * below is driven WITHOUT spawning a process — by feeding the helpers the
 * deterministic "no server / stale PID / bad input" states they guard against:
 *   - a PID file pointing at a guaranteed-dead PID (stale-pid branch),
 *   - a missing PID file on a free port (no-server + orphan-scan branch),
 *   - an empty log dir (no-instances branch).
 * The live-kill, `exit()`-ing (cliStatus/cliStatusOne) and blocking
 * (`cliLogs` → `tail -F`) paths are deliberately NOT driven here — they can't
 * run in-process without killing or hanging the test runner.
 *
 * Private helpers are invoked via reflection (mirrors AppCliArgsTest).
 */
class CliLifecycleHelpersTest extends TestCase
{
    /** A PID far above /proc/sys/kernel/pid_max — posix_kill(0) always ESRCHs. */
    private const DEAD_PID = 2147483646;

    /** @var mixed */
    private $savedArgv;
    /** @var string|false */
    private $savedLogDir;
    /** @var string|false */
    private $savedPidFile;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19994, ZEALPHP_ROOT);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedArgv    = $_SERVER['argv'] ?? null;
        $this->savedLogDir  = getenv('ZEALPHP_LOG_DIR');
        $this->savedPidFile = getenv('ZEALPHP_PID_FILE');
    }

    protected function tearDown(): void
    {
        if ($this->savedArgv !== null) {
            $_SERVER['argv'] = $this->savedArgv;
        }
        $this->restoreEnv('ZEALPHP_LOG_DIR', $this->savedLogDir);
        $this->restoreEnv('ZEALPHP_PID_FILE', $this->savedPidFile);
        parent::tearDown();
    }

    /** @param string|false $value */
    private function restoreEnv(string $name, $value): void
    {
        if ($value === false) {
            putenv($name);
        } else {
            putenv("{$name}={$value}");
        }
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(CLI::class, $method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ─────────────────────────────────────────────────────────────
    // processIsZealphp() — PID-recycling guard (public)
    // ─────────────────────────────────────────────────────────────

    public function testProcessIsZealphpFalseForOwnNonDaemonProcess(): void
    {
        // The test runner is `php … phpunit …` — a php binary, but its cmdline
        // has no "app.php", so it must NOT be mistaken for a ZealPHP daemon.
        $this->assertFalse(CLI::processIsZealphp((int) getmypid()));
    }

    public function testProcessIsZealphpPermissiveWhenCmdlineUnreadable(): void
    {
        // No /proc/<pid>/cmdline (dead PID) → permissive true (caller still
        // gates the actual kill on posix_kill()).
        $this->assertTrue(CLI::processIsZealphp(self::DEAD_PID));
    }

    // ─────────────────────────────────────────────────────────────
    // findPortOwnerPid() / claimOrphanIfAny() — no listener (public + private)
    // ─────────────────────────────────────────────────────────────

    public function testFindPortOwnerPidNullForInvalidPort(): void
    {
        $this->assertNull($this->invoke('findPortOwnerPid', 0));
    }

    public function testClaimOrphanFalseWhenPortFree(): void
    {
        // Nothing listens on this high port → findPortOwnerPid() scans
        // /proc/net/tcp{,6}, finds no LISTEN socket, returns null → no orphan.
        $this->assertFalse(CLI::claimOrphanIfAny(59997));
    }

    // ─────────────────────────────────────────────────────────────
    // cliStop() — no-server + stale-PID branches (private)
    // ─────────────────────────────────────────────────────────────

    public function testCliStopReportsNotRunningWhenPidFileMissing(): void
    {
        $pidFile = sys_get_temp_dir() . '/zealphp_59996.pid';
        @unlink($pidFile);
        ob_start();
        $this->invoke('cliStop', $pidFile, false);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('not running', $out);
    }

    public function testCliStopQuietSuppressesOutput(): void
    {
        $pidFile = sys_get_temp_dir() . '/zealphp_59995.pid';
        @unlink($pidFile);
        ob_start();
        $this->invoke('cliStop', $pidFile, true);
        $out = (string) ob_get_clean();
        $this->assertSame('', $out);
    }

    public function testCliStopRemovesStalePidFile(): void
    {
        $pidFile = sys_get_temp_dir() . '/zealphp_59994.pid';
        file_put_contents($pidFile, (string) self::DEAD_PID);
        ob_start();
        $this->invoke('cliStop', $pidFile, false);
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('stale PID file', $out);
        $this->assertFileDoesNotExist($pidFile);
    }

    // ─────────────────────────────────────────────────────────────
    // cliStopAuto() — glob of the log dir (private)
    // ─────────────────────────────────────────────────────────────

    public function testCliStopAutoReportsNoneWhenDirEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/zealphp_cliauto_' . uniqid();
        mkdir($dir, 0777, true);
        putenv("ZEALPHP_LOG_DIR={$dir}");
        try {
            ob_start();
            $this->invoke('cliStopAuto');
            $out = (string) ob_get_clean();
            $this->assertStringContainsString('No ZealPHP instances running', $out);
        } finally {
            @rmdir($dir);
        }
    }

    public function testCliStopAutoPrunesStalePidFile(): void
    {
        $dir = sys_get_temp_dir() . '/zealphp_cliauto_' . uniqid();
        mkdir($dir, 0777, true);
        $stale = "{$dir}/zealphp_59993.pid";
        file_put_contents($stale, (string) self::DEAD_PID);
        putenv("ZEALPHP_LOG_DIR={$dir}");
        try {
            ob_start();
            $this->invoke('cliStopAuto');
            $out = (string) ob_get_clean();
            // The dead-PID file is pruned, leaving nothing running.
            $this->assertFileDoesNotExist($stale);
            $this->assertStringContainsString('No ZealPHP instances running', $out);
        } finally {
            @unlink($stale);
            @rmdir($dir);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // resolvePidFile() — the env-var branches AppCliArgsTest doesn't cover
    // ─────────────────────────────────────────────────────────────

    public function testResolvePidFileHonoursPidFileEnv(): void
    {
        $path = sys_get_temp_dir() . '/zealphp_env_override.pid';
        putenv("ZEALPHP_PID_FILE={$path}");
        $this->assertSame($path, CLI::resolvePidFile([]));
    }

    public function testResolvePidFileHonoursLogDirEnv(): void
    {
        $dir = sys_get_temp_dir() . '/zealphp_logdir_' . uniqid();
        mkdir($dir, 0777, true);
        putenv('ZEALPHP_PID_FILE');           // ensure pid-file env doesn't win
        putenv("ZEALPHP_LOG_DIR={$dir}");
        try {
            $this->assertSame("{$dir}/zealphp_7777.pid", CLI::resolvePidFile(['port' => 7777]));
        } finally {
            @rmdir($dir);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // cliHelp() — the usage heredoc (private)
    // ─────────────────────────────────────────────────────────────

    public function testCliHelpListsCommandsAndDevFlag(): void
    {
        ob_start();
        $this->invoke('cliHelp');
        $help = (string) ob_get_clean();
        $this->assertStringContainsString('Usage: php app.php', $help);
        $this->assertStringContainsString('--dev', $help);
        $this->assertStringContainsString('ZEALPHP_LOG_DIR', $help);
    }

    // ─────────────────────────────────────────────────────────────
    // parseCliArgs() — edge inputs that still RETURN (no exit())
    // ─────────────────────────────────────────────────────────────

    public function testParseCliArgsReturnsEmptyForNonArrayArgv(): void
    {
        $_SERVER['argv'] = 'not-an-array';
        /** @var array<string,mixed> $out */
        $out = $this->invoke('parseCliArgs');
        $this->assertSame([], $out);
    }

    public function testParseCliArgsParsesServerAndZlogLogFilters(): void
    {
        // --server / --zlog are logs filters; on `start` they're parsed but
        // don't surface in the start overrides — exercises those flag arms.
        $_SERVER['argv'] = ['app.php', 'start', '-p', '54339', '--server', '--zlog'];
        /** @var array<string,mixed> $out */
        $out = $this->invoke('parseCliArgs');
        $this->assertSame(54339, $out['_port']);
        $this->assertArrayNotHasKey('log_server', $out);
    }
}
