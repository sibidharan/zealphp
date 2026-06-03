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
    /** Redis host (default `'127.0.0.1'`). */
    private string $host;

    /** Redis port (default `6379`). */
    private int $port;

    /** Key prefix used for session entries (default `'PHPREDIS_SESSION:'`). */
    private string $prefix;

    /** Session TTL in seconds (default `1440`). */
    private int $ttl;

    /** Single connection used outside coroutine context (CLI / tests). */
    private ?\Redis $fallback = null;

    /**
     * @param string $host   Redis host.
     * @param int    $port   Redis port.
     * @param string $prefix Key prefix; use the phpredis default (`'PHPREDIS_SESSION:'`) for cross-handler compatibility.
     * @param int    $ttl    Session TTL in seconds.
     */
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

    /**
     * Open a new `\Redis` connection to `$this->host:$this->port`.
     */
    private function connect(): \Redis
    {
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);
        return $redis;
    }

    /**
     * Per-coroutine Redis connection.
     *
     * Each coroutine gets its own socket so concurrent session I/O never crosses
     * frames (issue #16). The connection is stored in the coroutine context under
     * the key `'__zeal_redis_session'` and is reaped automatically when the
     * coroutine ends. Outside a coroutine, the construction-time `$fallback`
     * connection is reused.
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

    /**
     * Verify that the Redis connection is alive. Called by PHP's session manager
     * before the first `read()`.
     */
    public function open($savePath, $sessionName): bool
    {
        return $this->redis()->isConnected();
    }

    /**
     * No-op: per-coroutine connections are reaped by the coroutine context,
     * not by the session manager lifecycle.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Per-coroutine read snapshot for 3-way merge on write conflict.
     *
     * @var array<string, string>
     */
    private array $baseData = [];

    /**
     * Read and return the serialised session data for `$sessionId`.
     *
     * Also `WATCH`es the key so a concurrent write is detected during the
     * subsequent `write()` call; stores the baseline data in `$baseData` for
     * the 3-way merge.
     */
    public function read($sessionId): string
    {
        $redis = $this->redis();
        $key = $this->prefix . $sessionId;
        $redis->watch($key);
        $data = $redis->get($key);
        $base = is_string($data) ? $data : '';
        $this->baseData[(string) $sessionId] = $base;
        return $base;
    }

    /**
     * Persist serialised session data for `$sessionId` with optimistic locking.
     *
     * Uses `WATCH`/`MULTI`/`EXEC` and retries up to 3 times on conflict. When
     * a concurrent writer is detected, performs a 3-way merge (base = original
     * `read()` snapshot, local = intended write, remote = current Redis value)
     * before retrying. Returns `false` if all 3 attempts fail.
     */
    public function write($sessionId, $sessionData): bool
    {
        $redis = $this->redis();
        $key = $this->prefix . $sessionId;
        $sid = (string) $sessionId;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $pipe = $redis->multi();
            $pipe->setex($key, $this->ttl, $sessionData);
            $result = $pipe->exec();
            if ($result !== false) return true;

            // WATCH/MULTI conflict — concurrent writer beat us. Re-WATCH,
            // read the current state, 3-way merge (base = our original
            // read, local = our intended write, remote = current). Retry.
            $redis->watch($key);
            $remote = $redis->get($key);
            $remoteStr = is_string($remote) ? $remote : '';
            $base = $this->baseData[$sid] ?? '';
            $sessionData = $this->merge3Sessions($base, $sessionData, $remoteStr);
            $this->baseData[$sid] = $remoteStr;
        }
        return false;
    }

    /**
     * 3-way merge for serialised PHP session strings.
     *
     * Gives leaf-level granularity — concurrent writes to disjoint leaf
     * paths under the same top-level key (e.g. `$_SESSION['cart']['item1']`
     * vs `$_SESSION['cart']['item2']`) both survive.
     */
    private function merge3Sessions(string $base, string $local, string $remote): string
    {
        $baseArr = self::parseSession($base);
        $localArr = self::parseSession($local);
        $remoteArr = self::parseSession($remote);
        $merged = self::merge3Array($baseArr, $localArr, $remoteArr);
        return self::serializeSession($merged);
    }

    /**
     * Parse a PHP session-encoded string into a key→value array.
     *
     * Uses the `php` serialisation format (`key|serialized_value`). Unknown or
     * malformed entries are silently skipped. Only `stdClass` objects are
     * allowed in `unserialize()` (matches the whitelist in `src/Session/utils.php`).
     *
     * @return array<mixed,mixed>
     */
    public static function parseSession(string $data): array
    {
        if ($data === '') return [];
        $result = [];
        $offset = 0;
        $len = strlen($data);
        while ($offset < $len) {
            $eq = strpos($data, '|', $offset);
            if ($eq === false) break;
            $key = substr($data, $offset, $eq - $offset);
            $offset = $eq + 1;
            try {
                $value = @unserialize(substr($data, $offset), ['allowed_classes' => ['stdClass']]);
            } catch (\Throwable) {
                break;
            }
            if ($value === false && substr($data, $offset, 4) !== 'b:0;') break;
            $offset += strlen(serialize($value));
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Serialise a key→value array back to the PHP session-encoded string format.
     *
     * @param array<mixed,mixed> $data
     */
    public static function serializeSession(array $data): string
    {
        $out = '';
        foreach ($data as $key => $val) {
            $out .= $key . '|' . serialize($val);
        }
        return $out;
    }

    /**
     * Recursive 3-way array merge.
     *
     * Strategy: remote is the baseline result; for each key in local:
     * - If the key is new in local (not in base), add it to the result.
     * - If both local and remote are arrays, recurse.
     * - If local changed from base, local wins.
     * Keys deleted locally (present in base, absent in local) are removed from
     * the result only when the remote value is still the base value (i.e. nobody
     * else changed it).
     *
     * @param array<mixed,mixed> $base
     * @param array<mixed,mixed> $local
     * @param array<mixed,mixed> $remote
     * @return array<mixed,mixed>
     */
    public static function merge3Array(array $base, array $local, array $remote): array
    {
        $result = $remote;
        foreach ($local as $key => $val) {
            $baseHas = array_key_exists($key, $base);
            if (!$baseHas) {
                $result[$key] = $val;
                continue;
            }
            $baseVal = $base[$key];
            $remoteVal = $remote[$key] ?? null;
            if (is_array($val) && is_array($baseVal) && is_array($remoteVal)) {
                $result[$key] = self::merge3Array($baseVal, $val, $remoteVal);
                continue;
            }
            if ($val !== $baseVal) {
                $result[$key] = $val;
            }
        }
        foreach ($base as $key => $_) {
            if (!array_key_exists($key, $local) && array_key_exists($key, $remote)) {
                if ($remote[$key] === $base[$key]) unset($result[$key]);
            }
        }
        return $result;
    }

    /**
     * Delete the session key from Redis and return `true`.
     */
    public function destroy($sessionId): bool
    {
        $this->redis()->del($this->prefix . $sessionId);
        return true;
    }

    /**
     * Garbage collection — Redis TTL handles expiry server-side, so this is a no-op.
     *
     * Returns `0` (zero sessions collected) to satisfy the `SessionHandlerInterface` contract.
     */
    public function gc($maxlifetime): int|false
    {
        return 0;
    }

    /**
     * Expose the Redis connection for the current coroutine (or the fallback).
     *
     * Useful for inspecting connection state in tests or running additional
     * Redis commands in the same per-coroutine socket.
     */
    public function getRedis(): \Redis
    {
        return $this->redis();
    }
}
