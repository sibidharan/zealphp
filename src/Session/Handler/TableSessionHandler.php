<?php

declare(strict_types=1);

namespace ZealPHP\Session\Handler;

use OpenSwoole\Atomic;
use OpenSwoole\Coroutine;
use OpenSwoole\Table;

/**
 * Table-as-store + file-as-backing session handler.
 *
 * Closes the file handler's key-level merge granularity hole without
 * requiring Redis. Three layers:
 *
 *   1. OpenSwoole\Table  — hot, in-memory, cross-worker, atomic ops
 *   2. File backing      — cold, persistent across restarts, overflow
 *   3. Sharded spinlock  — Atomic cmpset, one of N shards per session id
 *                          (`crc32($id) % WRITE_LOCK_SLOTS`), so writes to
 *                          different sessions don't serialise against each
 *                          other; only same-session writes (same shard) do.
 *
 * Writes use optimistic versioning (CAS): each session row has a
 * `version` column. On read, the handler snapshots both the data
 * (the "base") and the version. On write, it checks the current
 * version — if unchanged, it sets the new data + version+1 atomically.
 * If the version changed (a concurrent coroutine got there first), it
 * performs a recursive 3-way merge between the base, the local
 * mutation, and the current remote state, then retries with the new
 * version. Up to 3 retries before giving up.
 *
 * The 3-way merge gives LEAF-LEVEL granularity:
 *   - Coroutine A sets $_SESSION['cart']['item2'] = 3
 *   - Coroutine B sets $_SESSION['cart']['item3'] = 5
 *   - Result: BOTH item2 and item3 survive (vs file handler's
 *     last-writer-wins at the 'cart' key).
 *
 * File backing handles two cases:
 *   - Cold start: on Table miss, read from file and promote to Table.
 *   - Persistence: write-through to file so server restart doesn't
 *     drop the entire session pool.
 *
 * Use when:
 *   - You want concurrent-coroutine-safe sessions WITHOUT Redis.
 *   - Single-host deployment (Table is per-OpenSwoole-server).
 *
 * For multi-host or cross-restart durability with cross-node sharing,
 * use Redis WATCH/MULTI via RedisSessionHandler instead.
 */
final class TableSessionHandler implements \SessionHandlerInterface
{
    private const COL_DATA = 'data';
    private const COL_VERSION = 'version';
    private const COL_EXPIRES = 'expires';

    /**
     * Number of sharded write-lock slots. Session ids hash to a slot
     * (`crc32($id) % WRITE_LOCK_SLOTS`), so writes to DIFFERENT sessions almost
     * always take DIFFERENT locks (proceed in parallel) while writes to the
     * SAME session always take the same lock (serialised — required for the
     * read-merge-CAS to be atomic). Bounded at boot (N small Atomics in shared
     * memory), so there's no per-session unbounded allocation. Replaces the
     * single global Atomic that serialised EVERY session write cluster-wide.
     */
    private const WRITE_LOCK_SLOTS = 1024;

    private static ?self $instance = null;
    private static ?Table $table = null;
    /** @var array<int, Atomic> Sharded write locks, indexed `crc32($id) % WRITE_LOCK_SLOTS`. */
    private static array $writeLocks = [];
    private static string $savePath = '/var/lib/php/sessions';
    private static int $ttl = 7200;          // 2 hours
    private static int $maxRows = 65536;     // 64K rows
    private static int $dataSize = 16384;    // 16 KB per session

    /**
     * Read snapshot, keyed by coroutine id THEN session id. This is a per-worker
     * singleton, so keying by coroutine id keeps concurrent requests for the SAME
     * session id from clobbering each other's base/version snapshot (#182).
     *
     * @var array<int, array<string, array{base: array<mixed,mixed>, version: int}>>
     */
    private array $context = [];

    private function __construct() {}

    /**
     * Idempotent setup. Call BEFORE App::run() so the Table is allocated
     * in the master process and inherited by all workers on fork.
     *
     * All four params fall back to App config (`App::sessionTtl()`, etc.)
     * when null. Pass explicit values to override per-handler. Defaults
     * resolved here ONLY apply if App config is also unset (rare).
     *
     * @param int|null $ttl       Session TTL in seconds. App default: 7200 (2 hours)
     * @param int|null $maxRows   Max concurrent sessions in Table; overflow goes
     *                            to file backing. App default: 65536 (64K).
     *                            Memory: ~maxRows × (dataSize + 64) bytes shared.
     * @param int|null $dataSize  Max serialized session size in bytes.
     *                            App default: 16384 (16 KB) — fits OAuth tokens
     *                            + cart state + user prefs. Overflow → file only.
     * @param string|null $savePath  File backing dir. App default: /var/lib/php/sessions.
     */
    public static function register(
        ?int $ttl = null,
        ?int $maxRows = null,
        ?int $dataSize = null,
        ?string $savePath = null
    ): self {
        // Resolve from App config if not explicitly passed — lets users set
        // these via App::sessionTtl() / sessionMaxRows() / etc. before run().
        self::$ttl = max(1, $ttl ?? \ZealPHP\App::$session_ttl);
        self::$maxRows = max(16, $maxRows ?? \ZealPHP\App::$session_max_rows);
        self::$dataSize = max(1024, $dataSize ?? \ZealPHP\App::$session_data_size);
        self::$savePath = $savePath ?? \ZealPHP\App::$session_save_path;

        if (self::$table === null) {
            $table = new Table(self::$maxRows);
            $table->column(self::COL_DATA, Table::TYPE_STRING, self::$dataSize);
            $table->column(self::COL_VERSION, Table::TYPE_INT, 8);
            $table->column(self::COL_EXPIRES, Table::TYPE_INT, 8);
            $table->create();
            self::$table = $table;
        }

        // Sharded write locks — allocated once, BEFORE workers fork (Atomic is
        // shared memory inherited on fork). Idempotent.
        if (self::$writeLocks === []) {
            for ($i = 0; $i < self::WRITE_LOCK_SLOTS; $i++) {
                self::$writeLocks[$i] = new Atomic(0);
            }
        }

        if (!is_dir(self::$savePath)) {
            @mkdir(self::$savePath, 0700, true);
        }

        self::$instance ??= new self();
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('TableSessionHandler::register() must be called first');
        }
        return self::$instance;
    }

    public function open($savePath, $sessionName): bool
    {
        return self::$table !== null;
    }

    public function close(): bool
    {
        // Reclaim this coroutine's read-snapshot bucket at session_write_close
        // (called per request by CoSessionManager) so it can't grow unbounded on
        // a long-lived worker (#182).
        unset($this->context[Coroutine::getCid()]);
        return true;
    }

    public function read($sessionId): string
    {
        $sessionId = (string) $sessionId;
        $table = $this->table();

        // Hot path: Table
        $row = $table->get($sessionId);
        if (is_array($row)) {
            $expRaw = $row[self::COL_EXPIRES] ?? 0;
            $expires = is_numeric($expRaw) ? (int) $expRaw : 0;
            if ($expires === 0 || $expires > time()) {
                $dRaw = $row[self::COL_DATA] ?? '';
                $data = is_string($dRaw) ? $dRaw : '';
                $vRaw = $row[self::COL_VERSION] ?? 0;
                $version = is_numeric($vRaw) ? (int) $vRaw : 0;
                $this->context[Coroutine::getCid()][$sessionId] = [
                    'base' => $this->decode($data),
                    'version' => $version,
                ];
                return $data;
            }
            $table->del($sessionId);
        }

        // Cold path: file → promote to Table
        $data = $this->readFile($sessionId);
        $table->set($sessionId, [
            self::COL_DATA => $data,
            self::COL_VERSION => 1,
            self::COL_EXPIRES => time() + self::$ttl,
        ]);
        $this->context[Coroutine::getCid()][$sessionId] = [
            'base' => $this->decode($data),
            'version' => 1,
        ];
        return $data;
    }

    public function write($sessionId, $data): bool
    {
        $sessionId = (string) $sessionId;
        $data = (string) $data;
        $table = $this->table();
        $writeLock = $this->lockFor($sessionId);

        /** @var array{base: array<mixed,mixed>, version: int} $ctx */
        $ctx = $this->context[Coroutine::getCid()][$sessionId] ?? ['base' => [], 'version' => 0];
        $base = $ctx['base'];
        $expectedVersion = $ctx['version'];
        $local = $this->decode($data);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Acquire the per-session-shard write lock. With sharded locks the
            // critical section only serialises writes to sessions that hash to
            // the SAME slot (rare), not every session globally. Exponential
            // backoff (0.1ms → cap 2ms) keeps a contended slot from busy-spinning
            // at a fixed 0.5ms quantum.
            $spin = 100; // microseconds
            while (!$writeLock->cmpset(0, 1)) {
                Coroutine::usleep($spin);
                $spin = min($spin * 2, 2000);
            }
            try {
                $current = $table->get($sessionId);
                $cvRaw = is_array($current) ? ($current[self::COL_VERSION] ?? 0) : 0;
                $currentVersion = is_numeric($cvRaw) ? (int) $cvRaw : 0;
                $cdRaw = is_array($current) ? ($current[self::COL_DATA] ?? '') : '';
                $currentData = is_string($cdRaw) ? $cdRaw : '';

                if ($currentVersion !== $expectedVersion) {
                    // CONFLICT: another coroutine wrote in between. Recursive
                    // 3-way merge: base = what we read, local = our mutation,
                    // remote = current state. Then retry the CAS with the new
                    // version.
                    $remote = $this->decode($currentData);
                    $merged = $this->merge3($base, $local, $remote);
                    $data = $this->encode($merged);
                    $local = $merged;
                    $expectedVersion = $currentVersion;
                    // Don't update $base — we still want to detect leaf-level
                    // conflicts against our ORIGINAL read snapshot.
                }

                $newVersion = $expectedVersion + 1;
                $table->set($sessionId, [
                    self::COL_DATA => $data,
                    self::COL_VERSION => $newVersion,
                    self::COL_EXPIRES => time() + self::$ttl,
                ]);
                $this->context[Coroutine::getCid()][$sessionId] = ['base' => $local, 'version' => $newVersion];

                // Write-through to file backing.
                $this->writeFile($sessionId, $data);
                return true;
            } finally {
                $writeLock->set(0);
            }
        }
        // @phpstan-ignore-next-line deadCode.unreachable — defensive fallthrough
        return false;
    }

    public function destroy($sessionId): bool
    {
        $this->table()->del((string) $sessionId);
        $file = self::$savePath . '/sess_' . basename((string) $sessionId);
        if (is_file($file)) @unlink($file);
        unset($this->context[Coroutine::getCid()][(string) $sessionId]);
        return true;
    }

    public function gc($maxlifetime): int
    {
        $now = time();
        $count = 0;
        foreach ($this->table() as $id => $row) {
            if (!is_array($row)) continue;
            $expRaw = $row[self::COL_EXPIRES] ?? 0;
            $expires = is_numeric($expRaw) ? (int) $expRaw : 0;
            if ($expires > 0 && $expires < $now) {
                $this->table()->del((string) $id);
                $file = self::$savePath . '/sess_' . basename((string) $id);
                if (is_file($file)) @unlink($file);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Recursive 3-way merge:
     *   - base: what we read
     *   - local: our mutation
     *   - remote: current state (a concurrent coroutine wrote it)
     *
     * Returns the merged array where:
     *   - Keys present in local but not base → added (new keys we want)
     *   - Keys whose value differs in local vs base → use local (we changed it)
     *   - Keys unchanged in local → use remote (someone else may have changed)
     *   - Nested arrays → recurse for leaf-level granularity
     *
     * @param array<mixed,mixed> $base
     * @param array<mixed,mixed> $local
     * @param array<mixed,mixed> $remote
     * @return array<mixed,mixed>
     */
    public function merge3(array $base, array $local, array $remote): array
    {
        $result = $remote;
        foreach ($local as $key => $val) {
            $baseHas = array_key_exists($key, $base);
            $baseVal = $baseHas ? $base[$key] : null;
            $remoteHas = array_key_exists($key, $remote);
            $remoteVal = $remoteHas ? $remote[$key] : null;

            if (!$baseHas) {
                // Local added this key — keep it.
                $result[$key] = $val;
                continue;
            }
            if (is_array($val) && is_array($baseVal) && is_array($remoteVal)) {
                // #253 — list-shaped (sequential-int-keyed) sub-arrays are the
                // idiomatic `$_SESSION['flash'][] = ...` append. Two concurrent
                // appends both land at the SAME next integer index, so the
                // key-aligned leaf recursion below would pick one and silently
                // drop the other. Treat three lists as an APPEND-merge instead:
                // keep all of remote's elements plus the elements local added on
                // top of base (union of remote + local-new), so BOTH appends
                // survive. Caveat: "local-new" is diffed by VALUE
                // (`!in_array($v, $base, true)`), so a local append whose value
                // already EXISTS in base is indistinguishable from base's copy
                // and is NOT re-added on top of remote (no data loss across
                // distinct values; only a value-equal-to-an-existing-element is
                // affected). String-keyed maps fall through to the leaf-level
                // recursion and keep their existing local-wins behaviour.
                if (array_is_list($baseVal) && array_is_list($val) && array_is_list($remoteVal)) {
                    $added = array_values(array_filter(
                        $val,
                        static fn($v): bool => !in_array($v, $baseVal, true)
                    ));
                    // array_merge of two lists is already 0-indexed — no outer
                    // array_values() needed (it would be a no-op).
                    $result[$key] = array_merge($remoteVal, $added);
                    continue;
                }
                // All three are arrays — recurse for leaf-level merge.
                $result[$key] = $this->merge3($baseVal, $val, $remoteVal);
                continue;
            }
            if ($val !== $baseVal) {
                // Local changed this leaf — local wins.
                $result[$key] = $val;
            }
            // else: local didn't change → keep remote's value (which may
            // be the same as base or may be a concurrent update).
        }
        // Also detect keys deleted by local (present in base, not in local).
        foreach ($base as $key => $_) {
            if (!array_key_exists($key, $local) && array_key_exists($key, $remote)) {
                // Local deleted this key — only remove if remote still
                // has the base value (no concurrent edit).
                if ($remote[$key] === $base[$key]) {
                    unset($result[$key]);
                }
                // else: remote changed it → keep remote's value (concurrent
                // edit beats our deletion).
            }
        }
        return $result;
    }

    /**
     * @return array<mixed,mixed>
     */
    private function decode(string $data): array
    {
        if ($data === '') return [];
        // PHP's serialize handler. session_decode requires an active
        // session, so we parse the wire format manually for the merge.
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
            // Skip past the unserialized value. The serialize format has
            // self-describing lengths so we can compute it.
            $consumed = $this->serializedLength($data, $offset);
            if ($consumed === 0) break;
            $offset += $consumed;
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * @param array<mixed,mixed> $data
     */
    private function encode(array $data): string
    {
        $out = '';
        foreach ($data as $key => $val) {
            $out .= $key . '|' . serialize($val);
        }
        return $out;
    }

    /** Compute the length of a serialize()d value starting at $offset. */
    private function serializedLength(string $data, int $offset): int
    {
        // Simple approach: serialize+deserialize loop. Fast enough for
        // session data (we're not doing this in hot paths).
        $remaining = substr($data, $offset);
        try {
            $val = @unserialize($remaining, ['allowed_classes' => ['stdClass']]);
        } catch (\Throwable) {
            return 0;
        }
        // `unserialize` returns false BOTH for a corrupt value AND for a
        // legitimately stored boolean false (`b:0;`). Distinguish by the PREFIX,
        // not the whole tail: a stored false is rarely the last entry, so
        // `$remaining` is e.g. `b:0;next|i:1;...`. Comparing the full tail to
        // `'b:0;'` mis-flagged that as corrupt and made decode() drop every key
        // after the first stored false (silent session-data loss). Mirror
        // RedisSessionHandler::parseSession / php_session_decode_to_array.
        if ($val === false && substr($remaining, 0, 4) !== 'b:0;') {
            return 0;
        }
        return strlen(serialize($val));
    }

    private function readFile(string $sessionId): string
    {
        $file = self::$savePath . '/sess_' . basename((string) $sessionId);
        if (!is_file($file)) return '';
        $fp = @fopen($file, 'r');
        if (!$fp) return '';
        try {
            if (flock($fp, LOCK_SH)) {
                $data = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                return is_string($data) ? $data : '';
            }
        } finally {
            fclose($fp);
        }
        return '';
    }

    private function writeFile(string $sessionId, string $data): bool
    {
        $file = self::$savePath . '/sess_' . basename((string) $sessionId);
        $fp = @fopen($file, 'c');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            ftruncate($fp, 0);
            rewind($fp);
            $written = fwrite($fp, $data);
            fflush($fp);
            flock($fp, LOCK_UN);
            return $written !== false;
        } finally {
            fclose($fp);
        }
    }

    private function table(): Table
    {
        if (self::$table === null) {
            throw new \RuntimeException('TableSessionHandler::register() must be called first');
        }
        return self::$table;
    }

    /**
     * The write lock for a session id — its hashed shard. Same id → same lock
     * (serialised, required); different ids → almost always different locks
     * (parallel).
     */
    private function lockFor(string $sessionId): Atomic
    {
        if (self::$writeLocks === []) {
            throw new \RuntimeException('TableSessionHandler::register() must be called first');
        }
        return self::$writeLocks[crc32($sessionId) % self::WRITE_LOCK_SLOTS];
    }
}
