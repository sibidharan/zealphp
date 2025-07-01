<?php

namespace ZealPHP\Session\Handler;
use function ZealPHP\elog;
use OpenSwoole\Coroutine as co;

/**
 * CoroutineMemorySessionHandler stores session data in memory for each coroutine.
 *
 * Implements SessionHandlerInterface using coroutine-local storage.
 */
class CoroutineMemorySessionHandler implements \SessionHandlerInterface
{
    private array $sessions = [];

    /**
     * Initialize the in-memory session handler (no-op).
     *
     * @param string $savePath    Session save path (unused).
     * @param string $sessionName Session name (unused).
     * @return bool True on success.
     */
    public function open($savePath, $sessionName): bool
    {
        // No-op for in-memory storage
        return true;
    }

    /**
     * Read session data for the given session ID from coroutine-local storage.
     *
     * @param string $sessionId The session identifier.
     * @return string The session data, or empty string if none exists.
     */
    public function read($sessionId): string
    {
        elog('SessionHandler::read');
        $cid = co::getCid();

        if (isset($this->sessions[$cid][$sessionId])) {
            // Update last_access timestamp
            $this->sessions[$cid][$sessionId]['last_access'] = time();
            return $this->sessions[$cid][$sessionId]['data'];
        }

        return ''; // Return empty if no session data
    }

    /**
     * Write session data for the given session ID to coroutine-local storage.
     *
     * @param string $sessionId   The session identifier.
     * @param string $sessionData The serialized session data.
     * @return bool True on success.
     */
    public function write($sessionId, $sessionData): bool
    {
        $cid = co::getCid();

        if (!isset($this->sessions[$cid])) {
            $this->sessions[$cid] = [];
        }

        $this->sessions[$cid][$sessionId] = [
            'data' => $sessionData,
            'last_access' => time(),
        ];

        return true;
    }

    /**
     * Destroy the session data for the given session ID.
     *
     * @param string $sessionId The session identifier.
     * @return bool True on success.
     */
    public function destroy($sessionId): bool
    {
        $cid = co::getCid();

        unset($this->sessions[$cid][$sessionId]);

        return true;
    }

    /**
     * Close the session handler (no-op for in-memory storage).
     *
     * @return bool True on success.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Perform garbage collection by removing expired session entries.
     *
     * @param int $maxLifetime Sessions older than this (in seconds) are removed.
     * @return int Number of cleaned session entries (always returns 0).
     */
    public function gc($maxLifetime): int
    {
        foreach ($this->sessions as $cid => $sessions) {
            foreach ($sessions as $sessionId => $sessionData) {
                // Remove sessions older than maxLifetime
                if (time() - $sessionData['last_access'] > $maxLifetime) {
                    unset($this->sessions[$cid][$sessionId]);
                }
            }
        }

        return 0;
    }
}
