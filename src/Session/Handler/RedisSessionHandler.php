<?php
namespace ZealPHP\Session\Handler;

/**
 * Redis-backed session handler for ZealPHP.
 *
 * Reads/writes session data using the same key format as PHP's phpredis
 * session handler (PHPREDIS_SESSION:{session_id}), so sessions created
 * by Apache/mod_php are readable by ZealPHP and vice versa.
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    private \Redis $redis;
    private string $prefix;
    private int $ttl;

    public function __construct(string $host = '127.0.0.1', int $port = 6379, string $prefix = 'PHPREDIS_SESSION:', int $ttl = 1440)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
        $this->redis = new \Redis();
        $this->redis->connect($host, $port);
    }

    public function open($savePath, $sessionName): bool
    {
        return $this->redis->isConnected();
    }

    public function close(): bool
    {
        return true;
    }

    public function read($sessionId): string
    {
        $data = $this->redis->get($this->prefix . $sessionId);
        return $data === false ? '' : $data;
    }

    public function write($sessionId, $sessionData): bool
    {
        return $this->redis->setex($this->prefix . $sessionId, $this->ttl, $sessionData);
    }

    public function destroy($sessionId): bool
    {
        $this->redis->del($this->prefix . $sessionId);
        return true;
    }

    public function gc($maxlifetime): int|false
    {
        return 0;
    }

    public function getRedis(): \Redis
    {
        return $this->redis;
    }
}
