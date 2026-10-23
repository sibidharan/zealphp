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
    /**
     * Absolute path to the session-file directory, resolved in `open()`.
     * Empty until then — every method refuses to touch the filesystem with
     * it (an uninitialized typed property used to throw an Error from gc(),
     * which the GC timer reaches without a request having opened the handler).
     */
    private string $savePath = '';

    /**
     * Open the session storage at `$savePath`, creating the directory if absent.
     *
     * Falls back to the system temp dir when `$savePath` is empty — matching
     * PHP-FPM, which uses `sys_get_temp_dir()` when `session.save_path` is the
     * empty-string default (#343). The prior code computed the fallback into
     * `$this->savePath` but then overwrote it back to the raw (empty)
     * `$savePath` on the last line, AND ran `is_dir()`/`mkdir()` on the empty
     * value — so writes hit `/sess_…` at the filesystem root and failed with
     * Permission denied, breaking session storage entirely.
     */
    public function open($savePath, $sessionName): bool
    {
        $resolved = ($savePath !== '')
            ? $savePath
            : (sys_get_temp_dir() ?: '/tmp');
        if (!is_dir($resolved)) {
            @mkdir($resolved, 0700, true);
        }
        $this->savePath = $resolved;
        return true;
    }

    /**
     * Read and return the raw session data string for `$sessionId`.
     *
     * Returns an empty string when the session file does not exist (new session).
     */
    public function read($sessionId): string
    {
        if ($this->savePath === '') {
            return '';
        }
        $file = $this->savePath . '/sess_' . basename((string) $sessionId);
        $fp = @fopen($file, 'r');
        if ($fp === false) {
            return '';
        }
        try {
            // Shared lock: write() truncates then writes under LOCK_EX, so an
            // unlocked read could see the empty or half-written file (a torn
            // read, i.e. an empty or truncated — undecodable — session).
            if (!flock($fp, LOCK_SH)) {
                return '';
            }
            $contents = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            return $contents === false ? '' : $contents;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Write serialised `$sessionData` to the session file for `$sessionId`.
     *
     * The file is named `sess_{sessionId}` inside `$savePath`. Returns `false`
     * only when `file_put_contents()` fails.
     */
    public function write($sessionId, $sessionData): bool
    {
        if ($this->savePath === '') {
            return false;
        }
        $file = $this->savePath . '/sess_' . basename((string) $sessionId);
        // Exclusive lock around truncate + write, so a concurrent read() (or
        // another write) never sees the file in between.
        return file_put_contents($file, $sessionData, LOCK_EX) !== false;
    }

    /**
     * Delete the session file for `$sessionId`. Always returns `true` —
     * a missing file is treated as already-destroyed (idempotent).
     */
    public function destroy($sessionId): bool
    {
        if ($this->savePath === '') {
            return true;
        }
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
        if ($this->savePath === '') {
            return 0;
        }
        $files = glob("$this->savePath/sess_*") ?: [];
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime + $maxLifetime < time()) {
                @unlink($file);
            }
        }
        return 0;
    }
}
