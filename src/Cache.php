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
 *   // Before $app->run():
 *   Cache::init();
 *
 *   // Anywhere (any worker):
 *   Cache::set('user:42', $profile, ttl: 300);
 *   $profile = Cache::get('user:42');
 *   Cache::has('user:42');
 *   Cache::del('user:42');
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
     * @param int         $maxRows     Max entries in memory tier (default 4096)
     * @param string|null $cacheDir    File tier directory (default: .cache/ in project root)
     * @param int         $gcIntervalMs GC sweep interval in ms (default 60000)
     */
    public static function init(
        int $maxRows = 4096,
        ?string $cacheDir = null,
        int $gcIntervalMs = 60000,
    ): void {
        if (self::$initialized) {
            return;
        }

        self::$dir = $cacheDir ?? App::$cwd . '/.cache';
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

        if ($gcIntervalMs > 0) {
            self::registerGc($gcIntervalMs);
        }

        self::$initialized = true;
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
        if ($row !== false) {
            if ($row['ttl'] > 0 && $row['ttl'] < $now) {
                Store::del(self::TABLE, $hash);
            } elseif (crc32($row['val']) === $row['crc']) {
                self::$hitsMem?->increment();
                return unserialize($row['val'], ['allowed_classes' => false]);
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
        if ($row !== false) {
            if ($row['ttl'] > 0 && $row['ttl'] < $now) {
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
        $table = Store::table(self::TABLE);
        if ($table) {
            foreach ($table as $key => $row) {
                $table->del($key);
            }
        }

        foreach (glob(self::$dir . '/*.cache') as $file) {
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
        $table = Store::table(self::TABLE);
        if (!$table) {
            return;
        }
        $now = time();
        foreach ($table as $key => $row) {
            if ($row['ttl'] > 0 && $row['ttl'] < $now) {
                $table->del($key);
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
        foreach (glob(self::$dir . '/*.cache') as $file) {
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
