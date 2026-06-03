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
    /** C-2: cap on file-tier entries — oldest-first eviction beyond this. 0 = unlimited. */
    private static int $maxFiles = 0;

    private static ?Counter $hitsMem = null;
    private static ?Counter $hitsFile = null;
    private static ?Counter $misses = null;
    private static ?Counter $spillsFile = null;
    private static ?Counter $spillsFull = null;
    /** C-1: stampede gate — count of getOrCompute losers that waited then served from cache. */
    private static ?Counter $stampedeBlocked = null;
    /** C-2: count of file-tier evictions due to maxFiles cap (oldest-first). */
    private static ?Counter $fileRotations = null;
    /** C-3: count of tag invalidations performed. */
    private static ?Counter $tagInvalidations = null;

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
        int $maxFiles = 0,
    ): void {
        if (self::$initialized) {
            return;
        }

        self::$dir = $cacheDir ?? App::$cwd . '/.cache';
        if (!is_dir(self::$dir)) {
            mkdir(self::$dir, 0755, true);
        }
        // C-2: file-tier cap. 0 (default) = unlimited; gcFiles() will only
        // drop expired files. Non-zero = also LRU-evict oldest files when
        // count exceeds the cap (atime-based; modify-time as fallback).
        // Set this when TTLs are 0 (no expiry) but you still want bounded
        // disk usage — otherwise the file tier grows monotonically.
        self::$maxFiles = max(0, $maxFiles);

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
        self::$stampedeBlocked = new Counter(0);
        self::$fileRotations = new Counter(0);
        self::$tagInvalidations = new Counter(0);

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
        // C-1: stampede gate. Without this, N concurrent misses on the same
        // hot key all run $compute() simultaneously — bad when $compute is
        // expensive (DB query, external API). Elect a single lock-holder
        // via Counter::compareAndSet (atomic across workers, server-wide on
        // Atomic backend; cluster-wide on Redis). Losers wait briefly (up
        // to 200ms in 20ms increments) for the winner's write, then retry
        // the cache read; on continued miss they compute themselves (worst
        // case = double compute, not the herd).
        $lockName = '__cache_lock_' . md5($key);
        $lock     = new Counter(0, $lockName);
        if (!$lock->compareAndSet(0, 1)) {
            for ($i = 0; $i < 10; $i++) {
                // Under HOOK_ALL (coroutine-mode default) usleep yields to
                // the scheduler — other coroutines run while we wait. In
                // sync mode (HOOK_ALL off) it blocks the worker briefly,
                // matching the rest of the sync request path.
                usleep(20_000);
                $found = self::get($key, $sentinel);
                if ($found !== $sentinel) {
                    self::$stampedeBlocked?->increment();
                    return $found;
                }
            }
            // Winner is taking >200ms — let this caller compute too. Better
            // than blocking the request indefinitely. compareAndSet keeps
            // the next miss waiting too if the original winner hasn't
            // released; the lock auto-clears below.
            return $compute();
        }
        try {
            // Double-check after acquire — someone else's set() might have
            // landed between our initial get and the CAS win.
            $found = self::get($key, $sentinel);
            if ($found !== $sentinel) { return $found; }
            $value = $compute();
            self::set($key, $value, $ttl);
            return $value;
        } finally {
            $lock->set(0);
        }
    }

    /**
     * Store a value. Writes to both memory and file tiers.
     * Values larger than 8KB are stored in file tier only.
     *
     * @param list<string> $tags  C-3: optional tags for bulk invalidation
     *                             via `Cache::invalidateTag($tag)`. Tag
     *                             index requires Redis-capable backend
     *                             (Redis / Tiered); ignored otherwise.
     */
    public static function set(string $key, mixed $value, int $ttl = 0, array $tags = []): bool
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
            // Oversize values live in the file tier only. Evict any stale
            // memory-tier row for this key first — get() checks memory before
            // file, so an old small value would otherwise mask this large one (#186).
            Store::del(self::TABLE, $hash);
            self::$spillsFile?->increment();
        }

        $inFile = self::writeFile($hash, $serialized, $expires);

        // C-3: index for tag-based invalidation. Best-effort: failures
        // don't roll back the write (the value is correctly cached even
        // if its tag membership entry didn't land).
        if ($tags !== [] && Store::hasSetOps()) {
            foreach ($tags as $tag) {
                try { Store::sadd('__cache_tag:' . $tag, $hash); }
                catch (\ZealPHP\Store\StoreException) { /* best effort */ }
            }
        }

        return $inMemory || $inFile;
    }

    /**
     * C-3 — invalidate every key that was set() with the given tag.
     * Drops the entries from both memory + file tiers in a single sweep.
     * Returns the count of keys invalidated.
     *
     * Requires Redis or Tiered backend (the tag→keys SET lives in Redis).
     * On Table backend this is a no-op + warns to error_log; for
     * single-node cache groups, prefer keying with a versioned prefix
     * (e.g. `Cache::set("user:42:v$ver", ...)` + bump $ver to invalidate).
     */
    public static function invalidateTag(string $tag): int
    {
        if (!Store::hasSetOps()) {
            error_log(
                "Cache::invalidateTag('$tag') requires Redis/Tiered backend; current is Table. " .
                "Use a versioned key prefix for single-node cache groups instead."
            );
            return 0;
        }
        $setKey = '__cache_tag:' . $tag;
        $count  = 0;
        $next   = '0';
        try {
            do {
                $page = Store::sscanCursor($setKey, $next, 200);
                foreach ($page['members'] as $hash) {
                    Store::del(self::TABLE, $hash);
                    $path = self::filePath($hash);
                    if (file_exists($path)) { @unlink($path); }
                    $count++;
                }
                $next = $page['cursor'];
            } while ($next !== '0');
            Store::sdel($setKey);
        } catch (\ZealPHP\Store\StoreException) {
            // Best-effort — partial sweep is acceptable for cache
            // invalidation (stale entries TTL-expire naturally).
        }
        self::$tagInvalidations?->increment();
        return $count;
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
     * Batch get — fetch multiple keys in one call.
     * Returns an associative array of key => value for keys that exist.
     * Missing keys are omitted from the result.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function mget(array $keys): array
    {
        $results = [];
        $hashes = [];
        foreach ($keys as $key) {
            $hashes[$key] = md5($key);
        }

        $storeRows = Store::mget(self::TABLE, array_values($hashes));
        $now = time();

        foreach ($keys as $key) {
            $hash = $hashes[$key];
            $row = $storeRows[$hash] ?? null;
            if (is_array($row)) {
                $ttl = is_numeric($row['ttl'] ?? 0) ? (int)$row['ttl'] : 0;
                $val = (string)($row['val'] ?? '');
                $crc = $row['crc'] ?? 0;
                if ($ttl > 0 && $ttl < $now) {
                    Store::del(self::TABLE, $hash);
                    continue;
                }
                if (crc32($val) === $crc) {
                    self::$hitsMem?->increment();
                    $results[$key] = unserialize($val, ['allowed_classes' => false]);
                    continue;
                }
            }

            $file = self::readFile($hashes[$key]);
            if ($file !== null) {
                self::$hitsFile?->increment();
                $results[$key] = $file;
            } else {
                self::$misses?->increment();
            }
        }

        return $results;
    }

    /**
     * Batch set — store multiple key-value pairs in one call.
     * Returns the number of keys successfully stored.
     *
     * @param array<string, mixed> $items  key => value
     */
    public static function mset(array $items, int $ttl = 0): int
    {
        $stored = 0;
        foreach ($items as $key => $value) {
            if (self::set((string)$key, $value, $ttl)) {
                $stored++;
            }
        }
        return $stored;
    }

    /**
     * Cache performance stats. All counters are cross-worker (atomic).
     *
     * Returns: [
     *   'memory_entries'   => int,   // current rows in memory tier
     *   'hits_memory'      => int,   // get() served from memory
     *   'hits_file'        => int,   // get() served from file (memory miss)
     *   'misses'           => int,   // get() found nothing
     *   'spills_oversize'  => int,   // set() skipped memory (value > 8KB)
     *   'spills_full'      => int,   // set() skipped memory (table full)
     *   'stampede_blocked' => int,   // C-1 — getOrCompute losers that
     *                                //       waited then served cached
     *   'file_rotations'   => int,   // C-2 — file evictions by maxFiles cap
     *   'tag_invalidations'=> int,   // C-3 — Cache::invalidateTag calls
     *   'hit_rate'         => float, // hits / (hits + misses), 0.0–1.0
     * ]
     *
     * @return array{memory_entries: int, hits_memory: int, hits_file: int, misses: int, spills_oversize: int, spills_full: int, stampede_blocked: int, file_rotations: int, tag_invalidations: int, hit_rate: float}
     */
    public static function stats(): array
    {
        $hitsMem = self::$hitsMem?->get() ?? 0;
        $hitsFile = self::$hitsFile?->get() ?? 0;
        $misses = self::$misses?->get() ?? 0;
        $total = $hitsMem + $hitsFile + $misses;

        return [
            'memory_entries'   => Store::count(self::TABLE),
            'hits_memory'      => $hitsMem,
            'hits_file'        => $hitsFile,
            'misses'           => $misses,
            'spills_oversize'  => self::$spillsFile?->get() ?? 0,
            'spills_full'      => self::$spillsFull?->get() ?? 0,
            'stampede_blocked' => self::$stampedeBlocked?->get() ?? 0,
            'file_rotations'   => self::$fileRotations?->get() ?? 0,
            'tag_invalidations'=> self::$tagInvalidations?->get() ?? 0,
            'hit_rate'         => $total > 0 ? round(($hitsMem + $hitsFile) / $total, 4) : 0.0,
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
        $alive = [];   // surviving file paths, for the maxFiles trim below
        foreach ($files as $file) {
            $f = @fopen($file, 'r');
            if (!$f) {
                continue;
            }
            $ttl = (int) fgets($f);
            fclose($f);
            if ($ttl > 0 && $ttl < $now) {
                @unlink($file);
                continue;
            }
            $alive[] = $file;
        }
        // C-2: enforce maxFiles cap. After expired-file cleanup above, if
        // the surviving count still exceeds the cap, evict oldest-first
        // (by mtime) until under the cap. Required when TTLs are 0 (no
        // expiry) but bounded disk usage is desired.
        if (self::$maxFiles > 0 && count($alive) > self::$maxFiles) {
            // Sort surviving files by mtime ascending (oldest first).
            usort($alive, function (string $a, string $b): int {
                return (int) filemtime($a) <=> (int) filemtime($b);
            });
            $overage = count($alive) - self::$maxFiles;
            for ($i = 0; $i < $overage; $i++) {
                @unlink($alive[$i]);
                self::$fileRotations?->increment();
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
        self::$stampedeBlocked = new Counter(0);
        self::$fileRotations = new Counter(0);
        self::$tagInvalidations = new Counter(0);
        self::$initialized = true;
    }
}
