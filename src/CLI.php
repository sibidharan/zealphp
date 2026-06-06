<?php

declare(strict_types=1);

namespace ZealPHP;

use ZealPHP\App;
use function ZealPHP\elog;

/**
 * Command-line interface handling for the ZealPHP server lifecycle
 * (start/stop/restart/status/logs + PID-file management).
 *
 * Extracted verbatim from App.php (Phase 1 of the App.php decomposition).
 * Every method here was previously a private/protected static on App and is
 * only reached internally — there are no public-API BC shims because the
 * CLI surface is the `php app.php …` command path, not `App::cli*()` calls.
 */
class CLI
{
    /**
     * Parse `$argv`, execute any lifecycle sub-command, and return server-config overrides.
     *
     * Sub-commands (`stop`, `status`, `logs`, `restart`) are handled in-process and
     * call `exit()` when done. The `start` / default path returns an array of
     * OpenSwoole `$server->set()` override keys (`_host`, `_port`, `worker_num`,
     * `daemonize`, etc.) that `App::run()` merges into its config. Returns an
     * empty array when no overrides are needed.
     *
     * @return array<string, mixed>
     */
    public static function parseCliArgs(): array
    {
        $rawArgv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
        if (!is_array($rawArgv)) {
            return [];
        }
        // Filter to ensure all elements are strings (PHPStan can't infer from $_SERVER).
        $argv = array_values(array_filter($rawArgv, 'is_string'));
        if (count($argv) <= 1) {
            return [];
        }

        array_shift($argv);
        $command = 'start';
        $flags = [];
        $i = 0;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            // Accept the bare subcommand (`logs`) AND a dashed form (`--logs`):
            // users reach for `--logs`/`--status` by habit, and silently falling
            // through to the default `start` (booting the server) is a confusing
            // footgun. No real flag collides with a command name, so stripping
            // leading dashes here is safe.
            $bareCmd = ltrim($arg, '-');
            if (in_array($bareCmd, ['start', 'stop', 'status', 'restart', 'logs'], true)) {
                $command = $bareCmd;
                $i++;
                continue;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                self::cliHelp();
                exit(0);
            }
            if ($arg === '-p' || $arg === '--port') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['port'] = (int)$argv[++$i];
                if ($flags['port'] < 1 || $flags['port'] > 65535) { echo "Error: port must be between 1 and 65535\n"; exit(1); }
            } elseif ($arg === '-H' || $arg === '--host') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['host'] = $argv[++$i];
            } elseif ($arg === '-w' || $arg === '--workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['worker_num'] = max(1, (int)$argv[++$i]);
            } elseif ($arg === '-d' || $arg === '--daemonize') {
                $flags['daemonize'] = true;
            } elseif ($arg === '--task-workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['task_worker_num'] = max(0, (int)$argv[++$i]);
            } elseif ($arg === '--pid-file') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['pid_file'] = $argv[++$i];
            } elseif ($arg === '--access') {
                $flags['log_access'] = true;
            } elseif ($arg === '--debug') {
                $flags['log_debug'] = true;
            } elseif ($arg === '--server') {
                $flags['log_server'] = true;
            } elseif ($arg === '--zlog') {
                $flags['log_zlog'] = true;
            } elseif ($arg === '--dev') {
                // Dev route hot-reload: each worker watches route/*.php and
                // rebuilds the route table in place on change (no restart).
                // Equivalent to ZEALPHP_DEV=1 / App::devReload(true); the CLI
                // flag wins when combined. OFF in production (the default).
                App::$dev_reload = true;
            } elseif (str_starts_with($arg, '-')) {
                echo "Warning: unknown flag '{$arg}' (ignored)\n";
            }
            $i++;
        }

        switch ($command) {
            case 'stop':
                if (isset($flags['port']) || !empty($flags['pid_file'])) {
                    self::cliStop(self::resolvePidFile($flags));
                } else {
                    self::cliStopAuto();
                }
                exit(0);
            case 'status':
                self::cliStatus($flags);
                exit(0);
            case 'logs':
                self::cliLogs($flags);
                exit(0);
            case 'restart':
                $pidFile = self::resolvePidFile($flags);
                $wasDaemonized = file_exists($pidFile);
                echo "Restarting ZealPHP...\n";
                self::cliStop($pidFile, quiet: true);
                if ($wasDaemonized && !isset($flags['daemonize'])) {
                    $flags['daemonize'] = true;
                }
                // The "Restarted (pid X, port Y)" confirmation is printed by
                // forkStartupReporter() in the shared 'default' start path
                // below — it forks so the terminal-attached process prints
                // the message AFTER the new daemon is confirmed up, instead
                // of the prompt returning first and the message overlapping
                // the next command (issue #17). Fall through to start.
            default:
                $pidFile = self::resolvePidFile($flags);
                if ($command === 'start' && file_exists($pidFile)) {
                    $pid = (int)trim((string)file_get_contents($pidFile));
                    if ($pid > 0 && @posix_kill($pid, 0)) {
                        $port = $flags['port'] ?? (App::instance() ? App::instance()->port : 8080);
                        echo "ZealPHP is already running (pid {$pid}, port {$port})\n";
                        echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                        exit(0);
                    }
                    @unlink($pidFile);
                }

                $overrides = [];
                if (isset($flags['host'])) { $overrides['_host'] = $flags['host']; }
                if (isset($flags['port'])) { $overrides['_port'] = $flags['port']; }
                if (isset($flags['worker_num'])) { $overrides['worker_num'] = $flags['worker_num']; }
                if (isset($flags['daemonize'])) { $overrides['daemonize'] = true; }
                if (isset($flags['task_worker_num'])) { $overrides['task_worker_num'] = $flags['task_worker_num']; }
                if (isset($flags['pid_file'])) { $overrides['pid_file'] = $flags['pid_file']; }
                // Daemonized start/restart: fork so the terminal-attached
                // parent prints the confirmation AFTER the new daemon is up
                // (issue #17). The child returns these overrides and goes on
                // to boot the self-daemonizing server. A foreground start
                // (no -d) blocks in run() and needs no confirmation line.
                if (isset($flags['daemonize'])) {
                    $port = $flags['port'] ?? (App::instance() ? App::instance()->port : 8080);
                    $verb = $command === 'restart'
                        ? 'Restarted'
                        : 'Started ZealPHP in detached mode';
                    self::forkStartupReporter($pidFile, (int)$port, $verb);
                }
                return $overrides;
        }
    }

    /**
     * For daemonized start/restart: fork so the terminal-attached parent
     * polls for the new daemon's PID file and prints a confirmation line
     * BEFORE the shell prompt returns, while the child goes on to boot the
     * (self-daemonizing) server. The parent never touches OpenSwoole — it
     * only watches the PID file and exits, so the confirmation is always the
     * last thing written to the terminal (fixes the issue #17 race where the
     * prompt returned first and the message overlapped the next command).
     *
     * No-op when pcntl is unavailable or the fork fails: start proceeds
     * without a confirmation line (prior behaviour), never silently broken.
     *
     * @param string $verb e.g. "Restarted" or "Started ZealPHP in detached mode"
     *
     * Forks + polls the daemon PID file and exits in the child — neither
     * unit-testable in-process (pcntl_fork/exit kills the test runner) nor
     * dumpable as a subprocess (the OpenSwoole server suppresses the PHP
     * shutdown coverage flush). Verified manually + by the CLI behaviour.
     * @codeCoverageIgnore
     */
    private static function forkStartupReporter(string $pidFile, int $port, string $verb): void
    {
        if (!function_exists('pcntl_fork')) {
            return; // proceed to boot in-process — no confirmation possible
        }
        $childPid = pcntl_fork();
        if ($childPid <= 0) {
            // Child (0) boots the server; -1 (fork failed) also proceeds so
            // start is never blocked by the inability to report.
            return;
        }
        // Parent (terminal-attached): wait for the daemon to write its PID
        // file, print the confirmation, then exit so the prompt comes last.
        $newPid = 0;
        for ($i = 0; $i < 50; $i++) {   // poll up to 5s
            usleep(100000);
            if (file_exists($pidFile)) {
                $candidate = (int)trim(@file_get_contents($pidFile) ?: '');
                if ($candidate > 0 && @posix_kill($candidate, 0)) {
                    $newPid = $candidate;
                    break;
                }
            }
        }
        if ($newPid > 0) {
            echo "{$verb} (pid {$newPid}, port {$port}).\n";
        } else {
            echo "{$verb}, but could not confirm — check `php app.php status`.\n";
        }
        exit(0);
    }

    /**
     * Resolve the PID file path for the given CLI flags.
     *
     * Resolution order (first match wins):
     * 1. `--pid-file` flag (`$flags['pid_file']`).
     * 2. `ZEALPHP_PID_FILE` environment variable.
     * 3. `ZEALPHP_LOG_DIR` env var + `zealphp_{port}.pid`.
     * 4. `resolve_log_dir()` result (per-user fallback) + `zealphp_{port}.pid`.
     *
     * Creates the parent directory when it does not exist.
     *
     * @param array<string, mixed> $flags Parsed CLI flags from `parseCliArgs()`.
     */
    public static function resolvePidFile(array $flags): string
    {
        if (!empty($flags['pid_file']) && is_scalar($flags['pid_file'])) {
            $path = (string)$flags['pid_file'];
            self::ensurePidDir(dirname($path));
            return $path;
        }
        $envPid = getenv('ZEALPHP_PID_FILE');
        if ($envPid !== false && trim((string)$envPid) !== '') {
            $path = trim((string)$envPid);
            self::ensurePidDir(dirname($path));
            return $path;
        }
        // @phpstan-ignore-next-line — flags is array<string, mixed>; port value coerced to int at boundary
        $port = (int)($flags['port'] ?? (App::instance() ? App::instance()->port : 8080));
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir !== false && trim((string)$logDir) !== '') {
            $dir = rtrim(trim((string)$logDir), '/');
            self::ensurePidDir($dir);
            return "{$dir}/zealphp_{$port}.pid";
        }
        // No explicit --pid-file / ZEALPHP_PID_FILE / ZEALPHP_LOG_DIR: share the
        // same resolver the logger uses. It defaults to /tmp/zealphp but falls
        // back to a per-user dir when /tmp/zealphp is owned by another user — so
        // start and stop/status agree on the same writable dir instead of both
        // failing to write the PID file (issue: root-owned /tmp/zealphp).
        $dir = \ZealPHP\resolve_log_dir() ?? sys_get_temp_dir();
        self::ensurePidDir($dir);
        return "{$dir}/zealphp_{$port}.pid";
    }

    /**
     * Ensure `$dir` exists and is writable, creating it recursively if needed.
     * Logs a warning via `elog()` when creation fails — never throws.
     */
    private static function ensurePidDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                @elog('warn', "Cannot create PID directory {$dir} — check permissions or set ZEALPHP_PID_FILE");
            }
        }
        if (is_dir($dir) && !is_writable($dir)) {
            @chmod($dir, 0777);
        }
    }

    /**
     * Pull the port number from a default-shaped pid file path like
     * /tmp/zealphp/zealphp_8080.pid. Returns 0 when the caller passed a
     * --pid-file override that doesn't match the convention.
     */
    private static function extractPortFromPidFile(string $pidFile): int
    {
        if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m) === 1) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Returns the PID listening on $port, or null when nothing's listening
     * or it can't be determined (non-Linux, /proc unreadable). Linux-only:
     * /proc/net/tcp + tcp6 give the LISTEN-state socket inode; /proc/[pid]/fd/*
     * resolves inode → owner pid. We deliberately avoid stream_socket_server /
     * socket_bind here — those are intercepted by OpenSwoole's runtime hook
     * (HOOK_ALL) and become coroutine-only, which would crash this CLI path.
     */
    private static function findPortOwnerPid(int $port): ?int
    {
        if ($port <= 0 || !is_readable('/proc/net/tcp')) {
            return null;
        }
        $hexPort = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));
        $inode   = null;
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $tcpFile) {
            $lines = @file($tcpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $parts = preg_split('/\s+/', trim((string)$line));
                if (!is_array($parts) || count($parts) < 10) {
                    continue;
                }
                if ($parts[3] !== '0A') {    // 0A = TCP_LISTEN
                    continue;
                }
                if (!str_ends_with($parts[1], ':' . $hexPort)) {
                    continue;
                }
                $candidate = $parts[9];
                if ($candidate !== '' && $candidate !== '0') {
                    $inode = $candidate;
                    break 2;
                }
            }
        }
        if ($inode === null) {
            return null;
        }
        $target = "socket:[{$inode}]";
        $fds    = glob('/proc/[0-9]*/fd/*') ?: [];
        foreach ($fds as $fd) {
            if (@readlink($fd) !== $target) {
                continue;
            }
            if (preg_match('#^/proc/(\d+)/#', $fd, $m) === 1) {
                return (int)$m[1];
            }
        }
        return null;
    }

    /**
     * True when /proc/$pid/cmdline looks like a ZealPHP daemon: argv[0] is
     * a real php binary (php, php8.3, etc.) AND the args reference app.php.
     * Used to gate kill operations so a recycled PID belonging to an
     * unrelated process is never targeted.
     *
     * The stricter argv[0] check matters because bash wrappers that spawn
     * `php app.php …` as a child carry "app.php" in their own cmdline — a
     * naive substring match falsely identifies them as ZealPHP daemons and
     * would happily kill them.
     *
     * Non-Linux returns true (be permissive — caller still has posix_kill).
     */
    public static function processIsZealphp(int $pid): bool
    {
        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!is_readable($cmdlinePath)) {
            return true;
        }
        $raw = @file_get_contents($cmdlinePath);
        if ($raw === false || $raw === '') {
            return false;
        }
        // strtok on non-empty input always returns non-empty-string here
        // (we checked $raw !== '' above), so no false-return branch needed.
        $argv0 = strtok($raw, "\0");
        // Match `php`, `php8`, `php8.3` but not `php-fpm`, `php-cgi`,
        // `phpunit`, `phpstan`, etc.
        if (preg_match('/^php(\d|\.|$)/', basename($argv0)) !== 1) {
            return false;
        }
        return strpos(str_replace("\0", ' ', $raw), 'app.php') !== false;
    }

    /**
     * Recover from an orphaned-daemon situation: pid file missing or stale
     * but the port is still held. If the listener is a ZealPHP process,
     * graceful-then-force kill it. Returns true when an orphan was
     * cleaned up, false when there's nothing to do (port free, or held by
     * something that isn't ours).
     *
     * Orphan recovery is rare and notable, so messages always print even
     * when called from a "quiet" code path (cliStop during restart) —
     * silent recovery hides the fact that the system was in a degraded
     * state that needed self-healing.
     */
    public static function claimOrphanIfAny(int $port): bool
    {
        // findPortOwnerPid returns null in three indistinguishable cases:
        // port free, /proc unreadable, or LISTEN socket owner not in
        // /proc/*/fd. All three mean "no orphan to clean up."
        $ownerPid = self::findPortOwnerPid($port);
        if ($ownerPid === null) {
            return false;
        }
        if (!self::processIsZealphp($ownerPid)) {
            echo "Port {$port} is held by pid {$ownerPid} (not a ZealPHP process) — refusing to touch it.\n";
            return false;
        }
        echo "Found orphaned ZealPHP daemon on port {$port} (pid {$ownerPid}, no PID file) — cleaning up.\n";
        $pgid      = @posix_getpgid($ownerPid);
        $killGroup = $pgid && $pgid !== posix_getpgid(posix_getpid());
        $killGroup ? posix_kill(-$pgid, SIGTERM) : posix_kill($ownerPid, SIGTERM);
        for ($i = 0; $i < 100; $i++) {      // up to 10s
            usleep(100000);
            if (!@posix_kill($ownerPid, 0)) {
                echo "Orphan cleaned up.\n";
                return true;
            }
        }
        echo "Orphan ignored graceful SIGTERM — force killing.\n";
        $killGroup ? posix_kill(-$pgid, SIGKILL) : posix_kill($ownerPid, SIGKILL);
        usleep(200000);
        return true;
    }

    /**
     * Stop the server identified by `$pidFile`.
     *
     * Sends `SIGTERM` to the process group (or PID), polls up to 10 s for graceful
     * shutdown, then falls back to `SIGKILL`. Removes the PID file on success.
     * When the PID file is missing or stale, calls `claimOrphanIfAny()` to handle
     * a running-but-unregistered daemon. Output is suppressed when `$quiet` is `true`.
     */
    private static function cliStop(string $pidFile, bool $quiet = false): void
    {
        $say = function (string $msg) use ($quiet): void {
            if (!$quiet) { echo $msg; }
        };

        if (!file_exists($pidFile)) {
            // Pid file gone but the port might still be held by an orphaned
            // daemon. Auto-recover instead of silently passing through; the
            // alternative is the next start/restart binding the port and
            // failing without ever telling the user why.
            $port = self::extractPortFromPidFile($pidFile);
            if (self::claimOrphanIfAny($port)) {
                return;
            }
            $say("ZealPHP is not running (no PID file: {$pidFile})\n");
            return;
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
            $say("ZealPHP is not running (stale PID file)\n");
            @unlink($pidFile);
            // Stale pid file gone, but an orphan listener may still be there.
            $port = self::extractPortFromPidFile($pidFile);
            self::claimOrphanIfAny($port);
            return;
        }
        $pgid = @posix_getpgid($pid);
        $killGroup = $pgid && $pgid !== posix_getpgid(posix_getpid());
        $say("Stopping ZealPHP (pid {$pid})...\n");
        $killGroup ? posix_kill(-$pgid, SIGTERM) : posix_kill($pid, SIGTERM);
        // OpenSwoole graceful shutdown (workers finish current requests, master
        // tears down listeners) typically takes 5-7 seconds. Poll for up to 10s
        // before falling back to SIGKILL.
        for ($i = 0; $i < 10; $i++) {       // first 500ms: fast poll
            usleep(50000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        for ($i = 0; $i < 95; $i++) {       // next 9.5s: slower poll
            usleep(100000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        $say("Graceful shutdown timed out, force killing...\n");
        $killGroup ? posix_kill(-$pgid, SIGKILL) : posix_kill($pid, SIGKILL);
        usleep(100000);
        @unlink($pidFile);
    }

    /**
     * Stop all running ZealPHP instances when no specific port is given.
     *
     * Globs `{logDir}/zealphp_*.pid`, verifies each PID with `processIsZealphp()`,
     * and stops the lone instance automatically. When multiple instances are found,
     * lists them and asks the user to specify a port with `-p PORT`.
     */
    private static function cliStopAuto(): void
    {
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            // Same resolution as resolvePidFile()'s default — falls back to the
            // per-user dir when /tmp/zealphp is owned by another user, so
            // stop/status glob the same dir the running server wrote its PID into.
            $logDir = \ZealPHP\resolve_log_dir() ?: (is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp');
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid') ?: [];
        $running = [];
        foreach ($pidFiles as $f) {
            $pid = (int)trim((string)file_get_contents($f));
            // Pids get recycled — a bare posix_kill check will report a long-
            // dead ZealPHP daemon as "running" if its PID was reused by an
            // unrelated process. processIsZealphp() reads /proc/$pid/cmdline
            // to confirm the listener is actually ours.
            if ($pid > 0 && @posix_kill($pid, 0) && self::processIsZealphp($pid)) {
                $port = preg_match('/zealphp_(\d+)\.pid$/', $f, $m) ? $m[1] : '?';
                $running[] = ['file' => $f, 'pid' => $pid, 'port' => $port];
            } else {
                @unlink($f);
            }
        }
        if (empty($running)) {
            echo "No ZealPHP instances running\n";
            return;
        }
        if (count($running) === 1) {
            self::cliStop($running[0]['file']);
            return;
        }
        echo "Multiple ZealPHP instances running:\n";
        foreach ($running as $r) {
            echo "  pid {$r['pid']}, port {$r['port']}\n";
        }
        echo "Use 'php app.php stop -p PORT' to stop a specific instance\n";
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function cliStatus(array $flags): void
    {
        if (isset($flags['port'])) {
            $pidFile = self::resolvePidFile($flags);
            self::cliStatusOne($pidFile);
            return;
        }

        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            // Same resolution as resolvePidFile()'s default — falls back to the
            // per-user dir when /tmp/zealphp is owned by another user, so
            // stop/status glob the same dir the running server wrote its PID into.
            $logDir = \ZealPHP\resolve_log_dir() ?: (is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp');
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid') ?: [];
        if (empty($pidFiles)) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }

        $found = 0;
        foreach ($pidFiles as $pidFile) {
            $pid = (int)trim((string)file_get_contents($pidFile));
            // Pid liveness + cmdline check: posix_kill(0) only tells us the
            // PID exists, not that it's ours — recycled PIDs would lie.
            if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
                @unlink($pidFile);
                continue;
            }
            $port = '?';
            if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
                $port = $m[1];
            }
            echo "ZealPHP is running (pid {$pid}, port {$port})\n";
            $found++;
        }

        if ($found === 0) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }
        exit(0);
    }

    private static function cliStatusOne(string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            echo "ZealPHP is not running\n";
            exit(1);
        }
        $pid = (int)trim((string)file_get_contents($pidFile));
        // Cmdline verification guards against PID recycling (see cliStatus).
        if ($pid <= 0 || !@posix_kill($pid, 0) || !self::processIsZealphp($pid)) {
            echo "ZealPHP is not running (stale PID file)\n";
            @unlink($pidFile);
            exit(1);
        }
        $port = '?';
        if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
            $port = $m[1];
        }
        echo "ZealPHP is running (pid {$pid}, port {$port})\n";
        exit(0);
    }

    /**
     * @param array<string, mixed> $flags
     */
    private static function cliLogs(array $flags): void
    {
        if (isset($flags['port'])) {
            echo "Note: log files are shared across all ports. -p flag ignored.\n";
        }
        $hasFilter = isset($flags['log_access']) || isset($flags['log_debug'])
                  || isset($flags['log_server']) || isset($flags['log_zlog']);

        $files = [];

        if (!$hasFilter || isset($flags['log_access'])) {
            $path = \ZealPHP\log_file_for('access');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_debug'])) {
            $path = \ZealPHP\log_file_for('debug');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_zlog'])) {
            $path = \ZealPHP\log_file_for('zlog');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_server'])) {
            $serverLog = getenv('ZEALPHP_SERVER_LOG_FILE');
            if ($serverLog === false || trim((string)$serverLog) === '') {
                $dir = \ZealPHP\resolve_log_dir();
                if ($dir !== null) {
                    $serverLog = $dir . '/server.log';
                }
            }
            if ($serverLog !== false && trim((string)$serverLog) !== '') {
                $files[] = trim((string)$serverLog);
            }
        }

        if (empty($files)) {
            echo "No log files found. Check ZEALPHP_LOG_DIR or run the server first.\n";
            exit(1);
        }

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                @touch($file);
            }
        }

        echo "Tailing log files (Ctrl+C to stop):\n";
        foreach ($files as $file) {
            echo "  {$file}\n";
        }
        echo "\n";

        $cmd = 'tail -F';
        foreach ($files as $file) {
            $cmd .= ' ' . escapeshellarg($file);
        }
        passthru($cmd);
    }

    /**
     * Print the `php app.php --help` usage text to stdout and return.
     * The caller is responsible for calling `exit(0)` after this.
     */
    private static function cliHelp(): void
    {
        echo <<<'HELP'
Usage: php app.php [command] [options]

Commands:
  start    Start the server (default)
  stop     Stop a running server
  restart  Stop and restart the server
  status   Check if server is running
  logs     Tail log files (Ctrl+C to stop)

Options:
  -p, --port N         Listen port (default: from App::init)
  -H, --host ADDR      Listen address (default: 0.0.0.0)
  -w, --workers N      Number of worker processes
  -d, --daemonize      Run in background
  --task-workers N     Number of task workers (default: 8, set 0 to disable)
  --pid-file PATH      Custom PID file path
  --dev                Enable dev route hot-reload (watch route/*.php,
                       rebuild on change; no restart). Same as ZEALPHP_DEV=1.
                       OFF in production. See docs/hot-reload.md.
  -h, --help           Show this help message

Log filters (use with 'logs' command):
  --access             Only tail access.log
  --debug              Only tail debug.log
  --server             Only tail server.log
  --zlog               Only tail zlog.log

Examples:
  php app.php                        Start with defaults
  php app.php --dev                  Start with route hot-reload (dev)
  php app.php start -p 9501 -d      Start daemonized on port 9501
  php app.php stop                   Stop the default (port 8080) server
  php app.php stop -p 9501          Stop the server on port 9501
  php app.php restart -p 9501       Restart on port 9501
  php app.php status                 Check if default server is running
  php app.php status -p 9501        Check server on port 9501
  php app.php logs                   Tail all log files
  php app.php logs --access          Tail only access log
  php app.php logs --access --debug  Tail access + debug logs

PID + log files: /tmp/zealphp/ (one PID file per port). If that dir is owned by
another user, ZealPHP falls back to a per-user dir automatically. Override the
whole location with ZEALPHP_LOG_DIR=/path (or ZEALPHP_PID_FILE for just the PID).

HELP;
    }
}
