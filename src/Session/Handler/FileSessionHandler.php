<?php

namespace ZealPHP\Session\Handler;

use OpenSwoole\Coroutine as co;

/**
 * FileSessionHandler stores PHP session data in files on the filesystem.
 *
 * Implements the SessionHandlerInterface to read, write, and manage session files.
 */
class FileSessionHandler implements \SessionHandlerInterface
{
    private string $savePath;

    /**
     * Initialize session storage directory for file-based sessions.
     *
     * @param string $savePath    Directory where session files are stored.
     * @param string $sessionName Name of the session (unused).
     * @return bool True on success.
     */
    public function open($savePath, $sessionName): bool
    {
        // if (!$savePath) {
        //     $savePath = sys_get_temp_dir() . '/zealphp_sessions';
        // }

        $this->savePath = $savePath ?: '/var/lib/php/sessions';
        if (!is_dir($savePath)) {
            mkdir($savePath, 0777, true);
        }
        $this->savePath = $savePath;
        return true;
    }

    /**
     * Read session data for a given session ID from file.
     *
     * @param string $sessionId The session identifier.
     * @return string The session data, or empty string if not found.
     */
    public function read($sessionId): string
    {
        $file = "$this->savePath/sess_$sessionId";
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return '';
    }

    /**
     * Write session data to the file for a given session ID.
     *
     * @param string $sessionId   The session identifier.
     * @param string $sessionData The serialized session data.
     * @return bool True on success, false on failure.
     */
    public function write($sessionId, $sessionData): bool
    {
        $file = "$this->savePath/sess_$sessionId";
        return file_put_contents($file, $sessionData) !== false;
    }

    /**
     * Destroy the session by removing its file.
     *
     * @param string $sessionId The session identifier.
     * @return bool True on success.
     */
    public function destroy($sessionId): bool
    {
        $file = "$this->savePath/sess_$sessionId";
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }

    /**
     * Close the session. No action needed for file-based sessions.
     *
     * @return bool True on success.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Perform garbage collection by removing expired session files.
     *
     * @param int $maxLifetime Sessions not updated for this many seconds will be removed.
     * @return int Number of deleted session files (always returns 0 here).
     */
    public function gc($maxLifetime): int
    {
        foreach (glob("$this->savePath/sess_*") as $file) {
            if (filemtime($file) + $maxLifetime < time()) {
                unlink($file);
            }
        }
        return 0;
    }
}
