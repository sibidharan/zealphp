<?php
namespace ZealPHP\Session\Handler;

/**
 * Redis-backed session handler for ZealPHP.
 *
 * Reads/writes session data using the same key format as PHP's phpredis
 * session handler (PHPREDIS_SESSION:{session_id}), so sessions created
 * by Apache/mod_php are readable by ZealPHP and vice versa.
 *
 * ## Coroutine safety (issue #16)
 *
 * phpredis (`\Redis`) is **not** coroutine-safe: a single connection multiplexed
 * across concurrent coroutines interleaves request/response frames on the same
 * socket, so one coroutine can read another's reply (or garbage). Sharing one
 * handler instance — the common `onWorkerStart` pattern — therefore corrupted
 * session reads under load, which `write_close()` then persisted (a 24-key
 * session collapsing to a handful of keys).
 *
 * This handler keeps **one connection per coroutine**, stored in the coroutine's
 * context and reaped when the coroutine ends, so concurrent requests never share
 * a socket. Outside a coroutine (CLI, tests) it uses a single fallback
 * connection created at construction. High-throughput deployments that want to
 * avoid per-request connection churn should front this with a connection pool;
 * the per-coroutine model here is correct-by-default.
 *
 * Every method requires a live Redis connection, so it is verified against a
 * real server rather than unit tests — excluded from coverage measurement
 * (no offline seam without shipping a Redis mock).
 *
 * @codeCoverageIgnore
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    private string $host;
    private int $port;
    private string $prefix;
    private int $ttl;
    /** Single connection used outside coroutine context (CLI / tests). */
    private ?\Redis $fallback = null;

    public function __construct(string $host = '127.0.0.1', int $port = 6379, string $prefix = 'PHPREDIS_SESSION:', int $ttl = 1440)
    {
        $this->host = $host;
        $this->port = $port;
        $this->prefix = $prefix;
        $this->ttl = $ttl;
        // Connect eagerly to validate configuration at construction (preserves
        // the prior constructor behaviour); this becomes the non-coroutine
        // fallback connection.
        $this->fallback = $this->connect();
    }

    private function connect(): \Redis
    {
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);
        return $redis;
    }

    /**
     * Per-coroutine Redis connection. Each coroutine gets its own socket so
     * concurrent session I/O never crosses frames (#16). Outside a coroutine,
     * the construction-time fallback connection is reused.
     */
    private function redis(): \Redis
    {
        $cid = \OpenSwoole\Coroutine::getCid();
        if ($cid < 0) {
            return $this->fallback ??= $this->connect();
        }
        $context = \OpenSwoole\Coroutine::getContext($cid);
        if (!isset($context['__zeal_redis_session'])) {
            $context['__zeal_redis_session'] = $this->connect();
        }
        $conn = $context['__zeal_redis_session'];
        assert($conn instanceof \Redis);
        return $conn;
    }

    public function open($savePath, $sessionName): bool
    {
        return $this->redis()->isConnected();
    }

    public function close(): bool
    {
        return true;
    }

    public function read($sessionId): string
    {
        $redis = $this->redis();
        $key = $this->prefix . $sessionId;
        $redis->watch($key);
        $data = $redis->get($key);
        return is_string($data) ? $data : '';
    }

    public function write($sessionId, $sessionData): bool
    {
        $redis = $this->redis();
        $key = $this->prefix . $sessionId;
        $pipe = $redis->multi();
        $pipe->setex($key, $this->ttl, $sessionData);
        $result = $pipe->exec();
        return $result !== false;
    }

    public function destroy($sessionId): bool
    {
        $this->redis()->del($this->prefix . $sessionId);
        return true;
    }

    public function gc($maxlifetime): int|false
    {
        return 0;
    }

    /** The Redis connection for the current coroutine (or the fallback). */
    public function getRedis(): \Redis
    {
        return $this->redis();
    }
}
