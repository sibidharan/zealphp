<?php

namespace ZealPHP\Session\Handler;

use OpenSwoole\Coroutine as co;

/**
 * File-backed `\SessionHandlerInterface` for ZealPHP.
 *
 * Stores session data as flat files named `sess_{sessionId}` inside `$savePath`
 * (defaults to `/var/lib/php/sessions`, matching the PHP-FPM convention).
 * This handler is a direct coroutine-mode replacement for PHP's built-in file
 * session handler — it performs standard blocking file I/O, which is
 * coroutine-hooked under `Runtime::HOOK_ALL` and yields transparently.
 *
 * IMPORTANT: not suitable for high-concurrency coroutine mode without an
 * external file lock. For concurrent workloads use `TableSessionHandler`
 * (the default in coroutine mode) or `RedisSessionHandler`. Use this handler
 * only when you need filesystem-compatible session files, e.g. when sharing
 * sessions with a PHP-FPM process on the same host.
 *
 * Register via `App::$session_handler = 'file'` or by passing an instance
 * to `session_set_save_handler()` before `App::run()`.
 */
class FileSessionHandler implements \SessionHandlerInterface
{
    /** Absolute path to the session-file directory, resolved in `open()`. */
    private string $savePath;

    /**
     * Open the session storage at `$savePath`, creating the directory if absent.
     *
     * Falls back to `/var/lib/php/sessions` when `$savePath` is empty.
     */
    public function open($savePath, $sessionName): bool
    {
        // if (!$savePath) {
        //     $savePath = sys_get_temp_dir() . '/zealphp_sessions';
        // }

        $this->savePath = $savePath ?: '/var/lib/php/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0700, true);
        }
        $this->savePath = $savePath;
        return true;
    }

    /**
     * Read and return the raw session data string for `$sessionId`.
     *
     * Returns an empty string when the session file does not exist (new session).
     */
    public function read($sessionId): string
    {
        $file = $this->savePath . '/sess_' . basename((string) $sessionId);
        if (file_exists($file)) {
            $contents = file_get_contents($file);
            return $contents === false ? '' : $contents;
        }
        return '';
    }

    /**
     * Write serialised `$sessionData` to the session file for `$sessionId`.
     *
     * The file is named `sess_{sessionId}` inside `$savePath`. Returns `false`
     * only when `file_put_contents()` fails.
     */
    public function write($sessionId, $sessionData): bool
    {
        $file = $this->savePath . '/sess_' . basename((string) $sessionId);
        return file_put_contents($file, $sessionData) !== false;
    }

    /**
     * Delete the session file for `$sessionId`. Always returns `true` —
     * a missing file is treated as already-destroyed (idempotent).
     */
    public function destroy($sessionId): bool
    {
        $file = $this->savePath . '/sess_' . basename((string) $sessionId);
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }

    /**
     * Close the session — no-op for file sessions; always returns `true`.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Garbage-collect session files older than `$maxLifetime` seconds.
     *
     * Scans `$savePath/sess_*` and unlinks files whose `mtime` is older than
     * `time() - $maxLifetime`. Returns `0` (PHP's `\SessionHandlerInterface`
     * contract accepts any non-negative int as "number of deleted sessions";
     * we return `0` rather than counting for performance).
     */
    public function gc($maxLifetime): int
    {
        $files = glob("$this->savePath/sess_*") ?: [];
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime + $maxLifetime < time()) {
                unlink($file);
            }
        }
        return 0;
    }
}
