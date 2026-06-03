<?php

namespace ZealPHP\Session\Handler;
use function ZealPHP\elog;
use OpenSwoole\Coroutine as co;

class CoroutineMemorySessionHandler implements \SessionHandlerInterface
{
    /** @var array<int, array<string, array{data: string, last_access: int}>> */
    private array $sessions = [];

    // Open session
    public function open($savePath, $sessionName): bool
    {
        // No-op for in-memory storage
        return true;
    }

    // Read session data
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

    // Write session data
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

    // Destroy a session
    public function destroy($sessionId): bool
    {
        $cid = co::getCid();

        unset($this->sessions[$cid][$sessionId]);

        return true;
    }

    // Close the session
    public function close(): bool
    {
        // Drop this coroutine's session bucket so $sessions doesn't accumulate
        // an entry per distinct (cid, sessionId) over the worker's lifetime.
        // OpenSwoole reuses cids across requests but close() never pruned them,
        // so the map only ever grew. Mirrors TableSessionHandler::close()'s
        // per-cid context teardown.
        unset($this->sessions[co::getCid()]);
        return true;
    }

    // Garbage collection
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
