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
        // #271 — do NOT connect eagerly here. Under HOOK_ALL, `\Redis->connect()`
        // is a coroutine API, so constructing the handler at a non-coroutine point
        // (boot / middleware registration — e.g. with `sessionLifecycle(false)`,
        // where the app installs its own save handler before the request loop)
        // fataled with "API must be called in the coroutine". The connection is
        // now established lazily in `redis()` on first use — a per-coroutine socket
        // inside a request, or the `$fallback` (`??=`) outside one — both of which
        // are always safe contexts. `$fallback` is already nullable + lazy.
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
     * Resolve the Redis connection for the CURRENT context.
     *
     * In a coroutine: a per-coroutine socket stored in the coroutine context
     * (issue #16 — concurrent coroutines must never share a socket and interleave
     * RESP frames; the connection is reaped when the coroutine ends). Outside a
     * coroutine: the shared `$fallback`. The connection is established lazily here.
     *
     * Callers reach this only through `io()`, which guarantees a coroutine is live
     * before `connect()`'s hooked `\Redis->connect()` runs.
     */
    private function conn(): \Redis
    {
        $cid = \OpenSwoole\Coroutine::getCid();
        if ($cid < 0) {
            return $this->fallback ??= $this->connect();
        }
        $context = \OpenSwoole\Coroutine::getContext($cid);
        $existing = $context['__zeal_redis_session'] ?? null;
        if ($existing instanceof \Redis) {
            return $existing;
        }
        $fresh = $this->connect();
        $context['__zeal_redis_session'] = $fresh;
        return $fresh;
    }

    /**
     * Run a Redis save-handler operation, guaranteeing it executes inside a
     * coroutine.
     *
     * Every `\Redis` call in this handler — the `connect()` plus
     * `watch`/`get`/`multi`/`exec`/`del` — is hooked I/O under
     * `OpenSwoole\Runtime::HOOK_ALL`, so it FATALS with
     * "API must be called in the coroutine" when the PHP session save-handler
     * chain fires OUTSIDE a request coroutine. That happens under
     * `App::superglobals(true)` WITHOUT `enableCoroutine(true)`: the `onRequest`
     * handler isn't auto-wrapped in a coroutine, yet HOOK_ALL still hooks `\Redis`
     * — e.g. an app that installs its own save handler via `sessionLifecycle(false)`
     * and calls `session_start()` from middleware. #271 made the *constructor*
     * lazy, but `open()`/`read()` are themselves "first use" and still hit the wall
     * (#285).
     *
     * When already in a coroutine, run `$op` directly on the per-coroutine
     * connection. When NOT, run it inside `Coroutine::run()` on the shared
     * `$fallback` (the `App::parallel` / #261 sync-mode idiom — `Coroutine::run`
     * swallows throws, so capture + rethrow to keep a Redis error a catchable
     * exception, not a worker-killing fatal). `$fallback` persists across these
     * sequential transient runs, so the connection — and `WATCH` (read) ->
     * `MULTI`/`EXEC` (write) optimistic locking — spans the whole request.
     * Validated against a live Redis: a hooked socket created in one
     * `Coroutine::run()` is reused, with `WATCH`/`MULTI`/`EXEC` intact, in later
     * runs. (In this no-request-coroutine mode a worker handles requests
     * sequentially, so there is no concurrent writer for the lock to guard anyway.)
     *
     * @param callable(\Redis): mixed $op
     */
    private function io(callable $op): mixed
    {
        if (\OpenSwoole\Coroutine::getCid() >= 0) {
            return $op($this->conn());
        }
        $result = null;
        $error  = null;
        \OpenSwoole\Coroutine::run(function () use ($op, &$result, &$error): void {
            try {
                if ($this->fallback === null) {
                    $this->fallback = $this->connect();
                }
                $result = $op($this->fallback);
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    /**
     * Verify that the Redis connection is alive. Called by PHP's session manager
     * before the first `read()`.
     */
    public function open($savePath, $sessionName): bool
    {
        return $this->io(static fn(\Redis $r): bool => $r->isConnected()) === true;
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
        $key = $this->prefix . $sessionId;
        $sid = (string) $sessionId;
        $out = $this->io(function (\Redis $redis) use ($key, $sid): string {
            $redis->watch($key);
            $data = $redis->get($key);
            $base = is_string($data) ? $data : '';
            $this->baseData[$sid] = $base;
            return $base;
        });
        return is_string($out) ? $out : '';
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
        $key = $this->prefix . $sessionId;
        $sid = (string) $sessionId;
        return $this->io(function (\Redis $redis) use ($key, $sid, $sessionData): bool {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $pipe = $redis->multi();
                $pipe->setex($key, $this->ttl, $sessionData);
                $result = $pipe->exec();
                if ($result !== false) {
                    // The read→write merge baseline is consumed; drop it so the
                    // per-instance map doesn't grow with every distinct session id
                    // for the worker's lifetime (this is a singleton handler).
                    unset($this->baseData[$sid]);
                    return true;
                }

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
            unset($this->baseData[$sid]); // bound the map even when all retries fail
            return false;
        }) === true;
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
        $key = $this->prefix . $sessionId;
        $sid = (string) $sessionId;
        return $this->io(function (\Redis $redis) use ($key, $sid): bool {
            $redis->del($key);
            unset($this->baseData[$sid]);
            return true;
        }) === true;
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
        $conn = $this->io(static fn(\Redis $r): \Redis => $r);
        if (!$conn instanceof \Redis) {
            throw new \RuntimeException('RedisSessionHandler: Redis connection unavailable.');
        }
        return $conn;
    }
}
