<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\Tests\TestCase;

/**
 * In-process coverage for App::parseCliArgs() and the PID-file helpers.
 *
 * parseCliArgs() reads $_SERVER['argv'] and exit()s on stop/status/logs/help.
 * Those exit paths can't run inside PHPUnit (they'd kill the test process), so
 * we only drive the foreground-`start` branch — which parses flags and RETURNS
 * an overrides array — plus the bad-value/unknown-flag handling and the pure
 * resolvePidFile()/extractPortFromPidFile() helpers via reflection.
 *
 * Each test points -p at a high, unused port so resolvePidFile()'s "already
 * running?" check finds no live server and start returns cleanly.
 */
class AppCliArgsTest extends TestCase
{
    /** @var array<int,string>|null */
    private static $savedArgv = null;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19995, ZEALPHP_ROOT);
        }
        self::$savedArgv = $_SERVER['argv'] ?? null;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$savedArgv !== null) {
            $_SERVER['argv'] = self::$savedArgv;
        }
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    /**
     * @param array<int,string> $argv
     * @return array<string,mixed>
     */
    private function parse(array $argv): array
    {
        $_SERVER['argv'] = $argv;
        // Make sure the resolved pid file for whatever port doesn't exist so the
        // "already running" branch is skipped and start returns overrides.
        $m = new \ReflectionMethod(App::class, 'parseCliArgs');
        $m->setAccessible(true);
        /** @var array<string,mixed> $out */
        $out = $m->invoke(null);
        return $out;
    }

    // ─────────────────────────────────────────────────────────────
    // No args / single arg → empty overrides
    // ─────────────────────────────────────────────────────────────

    public function testNoArgsReturnsEmpty(): void
    {
        $this->assertSame([], $this->parse(['app.php']));
    }

    public function testBareStartReturnsEmptyOverrides(): void
    {
        // Plain `start` with no flags → no overrides (uses framework defaults).
        $this->assertSame([], $this->parse(['app.php', 'start']));
    }

    // ─────────────────────────────────────────────────────────────
    // Foreground start with flags → overrides populated
    // ─────────────────────────────────────────────────────────────

    public function testStartWithPortHostWorkers(): void
    {
        $out = $this->parse(['app.php', 'start', '-p', '54321', '-H', '0.0.0.0', '-w', '8']);
        $this->assertSame(54321, $out['_port']);
        $this->assertSame('0.0.0.0', $out['_host']);
        $this->assertSame(8, $out['worker_num']);
        $this->assertArrayNotHasKey('daemonize', $out);
    }

    public function testStartWithLongFlagNames(): void
    {
        $out = $this->parse(['app.php', 'start', '--port', '54322', '--host', '127.0.0.1', '--workers', '4']);
        $this->assertSame(54322, $out['_port']);
        $this->assertSame('127.0.0.1', $out['_host']);
        $this->assertSame(4, $out['worker_num']);
    }

    public function testStartWithTaskWorkersAndPidFile(): void
    {
        $pidFile = sys_get_temp_dir() . '/zsh-cli-' . uniqid() . '.pid';
        $out = $this->parse(['app.php', 'start', '-p', '54323', '--task-workers', '2', '--pid-file', $pidFile]);
        $this->assertSame(2, $out['task_worker_num']);
        $this->assertSame($pidFile, $out['pid_file']);
    }

    public function testWorkersClampedToAtLeastOne(): void
    {
        $out = $this->parse(['app.php', 'start', '-p', '54324', '-w', '0']);
        $this->assertSame(1, $out['worker_num']);
    }

    public function testTaskWorkersClampedToZeroFloor(): void
    {
        $out = $this->parse(['app.php', 'start', '-p', '54325', '--task-workers', '-5']);
        $this->assertSame(0, $out['task_worker_num']);
    }

    public function testStartImpliedWhenNoCommandGiven(): void
    {
        // No explicit command verb → defaults to 'start'.
        $out = $this->parse(['app.php', '-p', '54326']);
        $this->assertSame(54326, $out['_port']);
    }

    public function testUnknownFlagIgnoredButStartStillReturns(): void
    {
        // Unknown -x emits a warning to stdout and is ignored; start proceeds.
        ob_start();
        $out = $this->parse(['app.php', 'start', '-p', '54327', '-x']);
        $warning = ob_get_clean();
        $this->assertSame(54327, $out['_port']);
        $this->assertStringContainsString("unknown flag '-x'", (string) $warning);
    }

    public function testLogFilterFlagsAreParsedButNotInStartOverrides(): void
    {
        // --access etc. are 'logs' filters; on a start command they're parsed
        // into flags but don't end up in the start overrides array.
        $out = $this->parse(['app.php', 'start', '-p', '54328', '--access', '--debug']);
        $this->assertSame(54328, $out['_port']);
        $this->assertArrayNotHasKey('log_access', $out);
    }

    // ─────────────────────────────────────────────────────────────
    // resolvePidFile() / extractPortFromPidFile() (private — reflection)
    // ─────────────────────────────────────────────────────────────

    public function testResolvePidFileExplicitPidFileWins(): void
    {
        $m = new \ReflectionMethod(App::class, 'resolvePidFile');
        $m->setAccessible(true);
        $this->assertSame('/custom/path.pid', $m->invoke(null, ['pid_file' => '/custom/path.pid']));
    }

    public function testResolvePidFilePerPortConvention(): void
    {
        $m = new \ReflectionMethod(App::class, 'resolvePidFile');
        $m->setAccessible(true);
        $resolved = $m->invoke(null, ['port' => 9876]);
        $this->assertStringContainsString('zealphp_9876.pid', (string) $resolved);
    }

    public function testExtractPortFromPidFileParsesConvention(): void
    {
        $m = new \ReflectionMethod(App::class, 'extractPortFromPidFile');
        $m->setAccessible(true);
        $this->assertSame(8080, $m->invoke(null, '/tmp/zealphp/zealphp_8080.pid'));
        $this->assertSame(9501, $m->invoke(null, '/var/run/zealphp_9501.pid'));
    }

    public function testExtractPortFromPidFileReturnsZeroForNonConvention(): void
    {
        $m = new \ReflectionMethod(App::class, 'extractPortFromPidFile');
        $m->setAccessible(true);
        $this->assertSame(0, $m->invoke(null, '/custom/server.pid'));
    }
}
