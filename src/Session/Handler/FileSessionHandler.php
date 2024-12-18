<?php

namespace ZealPHP\Session\Handler;

use OpenSwoole\Coroutine as co;

class FileSessionHandler implements \SessionHandlerInterface
{
    private string $savePath;

    // Open session
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

    // Read session data
    public function read($sessionId): string
    {
        $file = "$this->savePath/sess_$sessionId";
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return '';
    }

    // Write session data
    public function write($sessionId, $sessionData): bool
    {
        $file = "$this->savePath/sess_$sessionId";
        return file_put_contents($file, $sessionData) !== false;
    }

    // Destroy a session
    public function destroy($sessionId): bool
    {
        $file = "$this->savePath/sess_$sessionId";
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }

    // Close the session
    public function close(): bool
    {
        return true;
    }

    // Garbage collection
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