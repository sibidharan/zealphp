<?php

declare(strict_types=1);

namespace ZealPHP\CGI;

use ZealPHP\App;

use function ZealPHP\elog;
use function ZealPHP\resolve_log_dir;

/**
 * Host-side handle for the fork-per-request CGI runner — `App::cgiMode('fork')`.
 *
 * Spawns ONE long-lived `fork_master.php` template subprocess (per OpenSwoole
 * worker, lazily) that binds a UNIX socket and forks a FRESH child per request
 * (Apache MPM prefork). Each `dispatch()` opens a short-lived connection to that
 * socket, sends one {@see IPC} request frame, and reads one response frame; the
 * master forks a child to handle it. Fresh-process correctness (no
 * "Cannot redeclare class") at fork cost (~1 ms), not `proc_open` cold-start.
 *
 * The master's stdout/stderr are redirected to a LOG FILE (not a pipe): fork
 * children inherit those fds, and an undrained pipe would fill (64 KB) and
 * deadlock a child mid-warning. The protocol is 100% over the socket, so the
 * std streams are diagnostics only. Readiness is detected by polling the socket
 * (the master accepts once bound), not a stderr line.
 *
 * EXPERIMENTAL. Requires pcntl + posix in the spawned PHP. See
 * docs/architecture/2026-06-02-fork-per-request-cgi-pool.md.
 */
final class ForkPool
{
    /** @var resource|null The fork_master subprocess handle. */
    private $proc = null;
    private string $sockPath;
    private bool $ready = false;

    public function __construct(
        private readonly int $maxConcurrent = 16,
        ?string $sockPath = null
    ) {
        $this->sockPath = $sockPath ?? self::defaultSockPath();
        $this->spawn();
    }

    private static function defaultSockPath(): string
    {
        $dir = resolve_log_dir() ?? sys_get_temp_dir();
        return rtrim($dir, '/') . '/zealphp_fork_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sock';
    }

    private function spawn(): void
    {
        $entry  = dirname(__DIR__) . '/fork_master.php';
        $dir    = resolve_log_dir() ?? sys_get_temp_dir();
        $errLog = rtrim($dir, '/') . '/fork_master.log';

        $cwd = App::$cwd !== '' ? App::$cwd : (getcwd() ?: '.');
        $env = array_merge(getenv(), [
            'ZEALPHP_FORK_SOCK'           => $this->sockPath,
            'ZEALPHP_FORK_MAX_CONCURRENT' => (string) max(1, $this->maxConcurrent),
            'ZEALPHP_CWD'                 => $cwd,
        ]);

        $desc = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $errLog, 'a'],
            2 => ['file', $errLog, 'a'],
        ];
        $pipes = [];
        $proc = @proc_open([\PHP_BINARY, '-d', 'display_errors=stderr', $entry], $desc, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('ForkPool: proc_open failed for ' . $entry);
        }
        $this->proc = $proc;

        // Readiness = the master has bound the socket and accepts connections.
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break; // master died during boot
            }
            $errno = 0;
            $errstr = '';
            $probe = @stream_socket_client('unix://' . $this->sockPath, $errno, $errstr, 0.5);
            if (is_resource($probe)) {
                @fclose($probe);
                $this->ready = true;
                break;
            }
            usleep(20000);
        }
        if (!$this->ready) {
            $this->close();
            throw new \RuntimeException('ForkPool: fork_master failed to become ready (socket ' . $this->sockPath . ')');
        }
    }

    /**
     * Dispatch one request: connect, send the frame, read the response.
     * Respawns the master and retries once on a connection failure.
     *
     * @param array<mixed,mixed> $request
     * @return array<mixed,mixed>
     */
    public function dispatch(array $request, float $timeout = 30.0): array
    {
        $t = $timeout > 0 ? $timeout : 30.0;
        $conn = $this->connect($t);
        if ($conn === null) {
            $this->respawn();
            $conn = $this->connect($t);
            if ($conn === null) {
                return ['status' => 503, 'body' => 'ForkPool: fork_master unavailable', 'headers' => [], 'cookies' => [], 'rawcookies' => []];
            }
        }
        try {
            IPC::writeFrame($conn, $request);
            $resp = IPC::readFrame($conn, $t);
        } finally {
            if (is_resource($conn)) {
                @fclose($conn);
            }
        }
        if (!is_array($resp)) {
            return ['status' => 500, 'body' => 'ForkPool: no response from fork child', 'headers' => [], 'cookies' => [], 'rawcookies' => []];
        }
        return $resp;
    }

    /** @return resource|null */
    private function connect(float $timeout)
    {
        if (!$this->isAlive()) {
            return null;
        }
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client('unix://' . $this->sockPath, $errno, $errstr, $timeout);
        return is_resource($conn) ? $conn : null;
    }

    public function isAlive(): bool
    {
        if (!is_resource($this->proc)) {
            return false;
        }
        $st = proc_get_status($this->proc);
        return $st['running'];
    }

    private function respawn(): void
    {
        $this->close();
        try {
            $this->spawn();
        } catch (\Throwable $e) {
            elog('ForkPool: respawn failed: ' . $e->getMessage(), 'error');
        }
    }

    public function close(): void
    {
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc, 15);
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $st = proc_get_status($this->proc);
                if (!$st['running']) {
                    break;
                }
                usleep(20000);
            }
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                @proc_terminate($this->proc, 9);
            }
            @proc_close($this->proc);
        }
        $this->proc  = null;
        $this->ready = false;
        @unlink($this->sockPath);
    }

    public function __destruct()
    {
        $this->close();
    }
}
