<?php
namespace ZealPHP;

/**
 * Cache — Tiered key-value cache (memory + file)
 *
 * General-purpose cache with a dead-simple API. Two tiers:
 *   Tier 1: In-memory via Store (OpenSwoole\Table) — fast, cross-worker, volatile
 *   Tier 2: File-based (.cache/ directory) — persistent, survives restarts
 *
 * Every set() writes through to both tiers. get() checks memory first, falls
 * back to file. TTL-based expiry with lazy cleanup + periodic GC timer.
 *
 * Usage:
 *
 * ```php
 * // Before $app->run():
 * Cache::init();
 *
 * // Anywhere (any worker):
 * Cache::set('user:42', $profile, ttl: 300);
 * $profile = Cache::get('user:42');
 * Cache::has('user:42');
 * Cache::del('user:42');
 * ```
 *
 * LIMITATIONS — when to use Redis/Valkey instead:
 *   - Multi-server: Cache is per-server. Redis shares state across machines.
 *   - Large datasets: Memory tier caps at maxRows (default 4096), 8KB per value.
 *   - Pub/Sub: No built-in publish/subscribe between workers or servers.
 *   - Data structures: No sorted sets, streams, Lua scripting. Flat KV only.
 *   - Persistence: File tier is best-effort. Redis AOF/RDB is crash-safe.
 *   - Eviction: No LRU/LFU. Full memory tier spills to file-only.
 *   - Transactions: No MULTI/EXEC. Store has per-row spinlocks only.
 */
class Cache
{
    private const TABLE = '__cache';
    private const MAX_MEM_SIZE = 8192;

    private static string $dir = '';
    private static bool $initialized = false;

    private static ?Counter $hitsMem = null;
    private static ?Counter $hitsFile = null;
    private static ?Counter $misses = null;
    private static ?Counter $spillsFile = null;
    private static ?Counter $spillsFull = null;

    /**
     * Initialize the cache. Must be called before $app->run().
     *
     * @param int         $maxRows      Max entries in memory tier (default 4096).
     *                                  HARD CAP on the Table backend (OpenSwoole\Table
     *                                  allocates a fixed-size shared-memory segment).
     *                                  NOT ENFORCED on the Redis backend — Redis is a
     *                                  global key-value store with no per-table size cap.
     *                                  Pair with `$ttlSeconds` OR configure
     *                                  Redis-server `maxmemory` + `maxmemory-policy` for
     *                                  bounded growth there. See the warning emitted at
     *                                  init() when this combo is misused.
     * @param string|null $cacheDir     File tier directory (default: .cache/ in project root)
     * @param int         $gcIntervalMs GC sweep interval in ms (default 60000)
     * @param ?int        $ttlSeconds   Per-key TTL hint (default null).
     *                                  On the Redis backend, setting this flips the
     *                                  underlying Store table to `mode='ttl'` so keys
     *                                  auto-expire server-side (Cache::set's per-key
     *                                  `$ttl` still wins as a per-call override; this
     *                                  is the DEFAULT TTL for keys whose set() doesn't
     *                                  pass one).
     */
    public static function init(
        int $maxRows = 4096,
        ?string $cacheDir = null,
        int $gcIntervalMs = 60000,
        ?int $ttlSeconds = null,
    ): void {
        if (self::$initialized) {
            return;
        }

        self::$dir = $cacheDir ?? App::$cwd . '/.cache';
        if (!is_dir(self::$dir)) {
            mkdir(self::$dir, 0755, true);
        }

        // Backend asymmetry surfaced at init():
        //   Table backend → $maxRows is HARD CAP (set() returns false when full
        //     and Cache spills to the file tier).
        //   Redis backend → $maxRows has no equivalent (Redis is global KV;
        //     eviction is server-side `maxmemory` + `maxmemory-policy`).
        //     Setting $ttlSeconds flips the Store table to TTL mode so keys
        //     auto-expire — the recommended pattern for cache workloads on Redis.
        $backend = Store::defaultBackend();
        $isRedis = $backend instanceof \ZealPHP\Store\RedisBackend
                || $backend instanceof \ZealPHP\Store\CircuitBreakerBackend;
        $makeOpts = [];
        if ($isRedis && $ttlSeconds !== null && $ttlSeconds > 0) {
            $makeOpts = ['mode' => 'ttl', 'ttl' => $ttlSeconds];
        }
        if ($isRedis && $maxRows !== 4096 && $ttlSeconds === null) {
            error_log(
                'Cache::init(maxRows=' . $maxRows . ') is NOT enforced on the Redis backend ' .
                '(Redis has no per-table size cap). Either pass $ttlSeconds for per-key auto-expiry, ' .
                'or configure Redis-server `maxmemory` + `maxmemory-policy allkeys-lru` for cluster-wide bound.'
            );
        }

        Store::make(self::TABLE, $maxRows, [
            'val' => [\OpenSwoole\Table::TYPE_STRING, self::MAX_MEM_SIZE],
            'ttl' => [\OpenSwoole\Table::TYPE_INT, 4],
            'crc' => [\OpenSwoole\Table::TYPE_INT, 4],
        ], $makeOpts);

        self::$hitsMem = new Counter(0);
        self::$hitsFile = new Counter(0);
        self::$misses = new Counter(0);
        self::$spillsFile = new Counter(0);
        self::$spillsFull = new Counter(0);

        if ($gcIntervalMs > 0) {
            self::registerGc($gcIntervalMs);
        }

        self::$initialized = true;
    }

    /**
     * Read-through helper: if `$key` exists, return it; otherwise call
     * `$compute()`, store the result, and return it.
     *
     * The fall-through-on-miss-then-compute pattern is the canonical
     * way to cache expensive lookups (DB queries, API calls, derived
     * values). Without this helper users write the same 3 lines:
     *
     *     $v = Cache::get($k);
     *     if ($v === null) { $v = expensiveLookup($k); Cache::set($k, $v, $ttl); }
     *     return $v;
     *
     * `getOrCompute()` collapses that to one call:
     *
     *     $v = Cache::getOrCompute($k, fn() => expensiveLookup($k), $ttl);
     *
     * Storage semantics are identical to set()+get() — the value goes
     * to BOTH the memory tier (Store table, capped at MAX_MEM_SIZE) AND
     * the file tier (large values + overflow). Subsequent calls within
     * `$ttl` short-circuit to the cached read.
     *
     * @template T
     * @param  callable(): T $compute
     * @return T
     */
    public static function getOrCompute(string $key, callable $compute, int $ttl = 0): mixed
    {
        // Distinguish "stored null" from "cache miss" with a private
        // sentinel that no caller can ever produce. Cache::get($key, $default)
        // returns $default on miss; if we pass our sentinel, an actual stored
        // null comes back as null (a real hit), and a miss comes back as the
        // sentinel object.
        static $sentinel;
        $sentinel ??= new \stdClass();
        $found = self::get($key, $sentinel);
        if ($found !== $sentinel) {
            return $found;
        }
        $value = $compute();
        self::set($key, $value, $ttl);
        return $value;
    }

    /**
     * Store a value. Writes to both memory and file tiers.
     * Values larger than 8KB are stored in file tier only.
     */
    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $serialized = serialize($value);
        $expires = $ttl > 0 ? time() + $ttl : 0;
        $crc = crc32($serialized);
        $hash = md5($key);

        $inMemory = false;
        if (strlen($serialized) <= self::MAX_MEM_SIZE) {
            $inMemory = Store::set(self::TABLE, $hash, [
                'val' => $serialized,
                'ttl' => $expires,
                'crc' => $crc,
            ]);
            if (!$inMemory) {
                self::$spillsFull?->increment();
            }
        } else {
            self::$spillsFile?->increment();
        }

        $inFile = self::writeFile($hash, $serialized, $expires);
        return $inMemory || $inFile;
    }

    /**
     * Retrieve a value. Memory tier checked first, file tier as fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $hash = md5($key);
        $now = time();

        $row = Store::get(self::TABLE, $hash);
        if (is_array($row)) {
            $ttl = $row['ttl'] ?? 0;
            $val = $row['val'] ?? '';
            $crc = $row['crc'] ?? 0;
            $ttlInt = is_numeric($ttl) ? (int)$ttl : 0;
            $valStr = is_scalar($val) ? (string)$val : '';
            if ($ttlInt > 0 && $ttlInt < $now) {
                Store::del(self::TABLE, $hash);
            } elseif (crc32($valStr) === $crc) {
                self::$hitsMem?->increment();
                return unserialize($valStr, ['allowed_classes' => false]);
            }
        }

        $file = self::readFile($hash);
        if ($file !== null) {
            self::$hitsFile?->increment();
            return $file;
        }

        self::$misses?->increment();
        return $default;
    }

    /**
     * Delete from both tiers.
     */
    public static function del(string $key): bool
    {
        $hash = md5($key);
        $mem = Store::del(self::TABLE, $hash);
        $file = false;
        $path = self::filePath($hash);
        if (file_exists($path)) {
            $file = @unlink($path);
        }
        return $mem || $file;
    }

    /**
     * Alias for del() — PSR-16 naming convention.
     */
    public static function delete(string $key): bool
    {
        return self::del($key);
    }

    /**
     * Check existence without deserializing. Respects TTL.
     */
    public static function has(string $key): bool
    {
        $hash = md5($key);
        $now = time();

        $row = Store::get(self::TABLE, $hash);
        if (is_array($row)) {
            $ttl = $row['ttl'] ?? 0;
            $ttlInt = is_numeric($ttl) ? (int)$ttl : 0;
            if ($ttlInt > 0 && $ttlInt < $now) {
                Store::del(self::TABLE, $hash);
            } else {
                return true;
            }
        }

        $path = self::filePath($hash);
        if (!file_exists($path)) {
            return false;
        }
        $f = @fopen($path, 'r');
        if (!$f) {
            return false;
        }
        $ttlLine = (int) fgets($f);
        fclose($f);
        if ($ttlLine > 0 && $ttlLine < $now) {
            @unlink($path);
            return false;
        }
        return true;
    }

    /**
     * Clear all cache entries from both tiers.
     */
    public static function flush(): void
    {
        // Backend-agnostic: works on TableBackend and RedisBackend alike.
        // Store::iterate() yields (key, row) pairs from whichever backend is
        // configured; Store::del() targets the same backend.
        foreach (Store::iterate(self::TABLE) as $key => $_row) {
            if ($key !== '') {
                Store::del(self::TABLE, $key);
            }
        }

        $files = glob(self::$dir . '/*.cache') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Alias for flush() — PSR-16 naming convention. Returns true.
     */
    public static function clear(): bool
    {
        self::flush();
        return true;
    }

    /**
     * Number of entries in memory tier (may include expired).
     */
    public static function count(): int
    {
        return Store::count(self::TABLE);
    }

    /**
     * Cache performance stats. All counters are cross-worker (atomic).
     *
     * Returns: [
     *   'memory_entries' => int,   // current rows in memory tier
     *   'hits_memory'    => int,   // get() served from memory
     *   'hits_file'      => int,   // get() served from file (memory miss)
     *   'misses'         => int,   // get() found nothing
     *   'spills_oversize' => int,  // set() skipped memory (value > 8KB)
     *   'spills_full'    => int,   // set() skipped memory (table full)
     *   'hit_rate'       => float, // hits / (hits + misses), 0.0–1.0
     * ]
     *
     * @return array{memory_entries: int, hits_memory: int, hits_file: int, misses: int, spills_oversize: int, spills_full: int, hit_rate: float}
     */
    public static function stats(): array
    {
        $hitsMem = self::$hitsMem?->get() ?? 0;
        $hitsFile = self::$hitsFile?->get() ?? 0;
        $misses = self::$misses?->get() ?? 0;
        $total = $hitsMem + $hitsFile + $misses;

        return [
            'memory_entries'  => Store::count(self::TABLE),
            'hits_memory'     => $hitsMem,
            'hits_file'       => $hitsFile,
            'misses'          => $misses,
            'spills_oversize' => self::$spillsFile?->get() ?? 0,
            'spills_full'     => self::$spillsFull?->get() ?? 0,
            'hit_rate'        => $total > 0 ? round(($hitsMem + $hitsFile) / $total, 4) : 0.0,
        ];
    }

    // -- Private helpers --

    private static function filePath(string $hash): string
    {
        return self::$dir . '/' . $hash . '.cache';
    }

    private static function writeFile(string $hash, string $serialized, int $expires): bool
    {
        $path = self::filePath($hash);
        return file_put_contents($path, $expires . "\n" . $serialized) !== false;
    }

    private static function readFile(string $hash): mixed
    {
        $path = self::filePath($hash);
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $nlPos = strpos($content, "\n");
        if ($nlPos === false) {
            @unlink($path);
            return null;
        }

        $ttl = (int) substr($content, 0, $nlPos);
        if ($ttl > 0 && $ttl < time()) {
            @unlink($path);
            return null;
        }

        $serialized = substr($content, $nlPos + 1);
        return unserialize($serialized, ['allowed_classes' => false]);
    }

    private static function registerGc(int $intervalMs): void
    {
        App::onWorkerStart(function ($server, $workerId) use ($intervalMs) {
            if ($workerId !== 0) {
                return;
            }
            App::tick($intervalMs, function () {
                self::gcMemory();
                self::gcFiles();
            });
        });
    }

    /** @internal */
    public static function gcMemory(): void
    {
        // Backend-agnostic (TableBackend + RedisBackend); same shape as flush().
        $now = time();
        foreach (Store::iterate(self::TABLE) as $key => $row) {
            $ttl = $row['ttl'] ?? 0;
            $ttlInt = is_numeric($ttl) ? (int)$ttl : 0;
            if ($ttlInt > 0 && $ttlInt < $now && $key !== '') {
                Store::del(self::TABLE, $key);
            }
        }
    }

    /** @internal */
    public static function gcFiles(): void
    {
        if (!self::$dir || !is_dir(self::$dir)) {
            return;
        }
        $now = time();
        $files = glob(self::$dir . '/*.cache') ?: [];
        foreach ($files as $file) {
            $f = @fopen($file, 'r');
            if (!$f) {
                continue;
            }
            $ttl = (int) fgets($f);
            fclose($f);
            if ($ttl > 0 && $ttl < $now) {
                @unlink($file);
            }
        }
    }

    /**
     * Initialize for unit testing (no App dependency).
     * @internal
     */
    public static function initForTest(string $cacheDir, int $maxRows = 64): void
    {
        self::$dir = $cacheDir;
        if (!is_dir(self::$dir)) {
            mkdir(self::$dir, 0755, true);
        }
        Store::make(self::TABLE, $maxRows, [
            'val' => [\OpenSwoole\Table::TYPE_STRING, self::MAX_MEM_SIZE],
            'ttl' => [\OpenSwoole\Table::TYPE_INT, 4],
            'crc' => [\OpenSwoole\Table::TYPE_INT, 4],
        ]);
        self::$hitsMem = new Counter(0);
        self::$hitsFile = new Counter(0);
        self::$misses = new Counter(0);
        self::$spillsFile = new Counter(0);
        self::$spillsFull = new Counter(0);
        self::$initialized = true;
    }
}
