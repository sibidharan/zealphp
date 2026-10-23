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
        self::$savePath = $savePath ?? \ZealPHP\App::sessionSavePath();

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
        // Reclaim this coroutine's read-snapshot bucket. zeal_session_write_close()
        // and zeal_session_destroy() call this once per request; the snapshot is
        // also dropped when the coroutine ends (see remember()), so a request
        // that never closes its session cannot grow the map either (#182).
        unset($this->context[\ZealPHP\RequestContext::ownerCid()]);
        return true;
    }

    public function read($sessionId): string
    {
        $sessionId = (string) $sessionId;
        $table = $this->table();
        $key = self::rowKey($sessionId);

        // Hot path: Table
        $row = $table->get($key);
        if (is_array($row)) {
            $expRaw = $row[self::COL_EXPIRES] ?? 0;
            $expires = is_numeric($expRaw) ? (int) $expRaw : 0;
            if ($expires === 0 || $expires > time()) {
                $dRaw = $row[self::COL_DATA] ?? '';
                $data = is_string($dRaw) ? $dRaw : '';
                $vRaw = $row[self::COL_VERSION] ?? 0;
                $version = is_numeric($vRaw) ? (int) $vRaw : 0;
                $this->remember($sessionId, $this->decode($data), $version);
                return $data;
            }
            $table->del($key);
        }

        // Cold path: file → promote to Table. Only a session that has data and
        // fits the row is promoted: an unknown (forged) id used to get a row
        // too, so anyone could fill the Table with junk ids until real
        // sessions spilled to disk.
        $data = $this->readFile($sessionId);
        $version = 0;
        if ($data !== '' && $this->setRow($key, $data, 1)) {
            $version = 1;
        }
        $this->remember($sessionId, $this->decode($data), $version);
        return $data;
    }

    public function write($sessionId, $data): bool
    {
        $sessionId = (string) $sessionId;
        $data = (string) $data;
        $table = $this->table();
        $key = self::rowKey($sessionId);
        $writeLock = $this->lockFor($sessionId);

        /** @var array{base: array<mixed,mixed>, version: int} $ctx */
        $ctx = $this->context[\ZealPHP\RequestContext::ownerCid()][$sessionId] ?? ['base' => [], 'version' => 0];
        $base = $ctx['base'];
        $expectedVersion = $ctx['version'];
        $local = $this->decode($data);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Acquire the per-session-shard write lock. With sharded locks the
            // critical section only serialises writes to sessions that hash to
            // the SAME slot (rare), not every session globally. Exponential
            // backoff (0.1ms → cap 2ms) keeps a contended slot from busy-spinning
            // at a fixed 0.5ms quantum.
            $this->acquire($writeLock);
            try {
                $current = $table->get($key);
                if (is_array($current)) {
                    $cvRaw = $current[self::COL_VERSION] ?? 0;
                    $currentVersion = is_numeric($cvRaw) ? (int) $cvRaw : 0;
                    $cdRaw = $current[self::COL_DATA] ?? '';
                    $currentData = is_string($cdRaw) ? $cdRaw : '';
                } else {
                    // No row: the session lives only on disk (too big for a
                    // row, or the Table is full), is new, or was destroyed.
                    $currentVersion = 0;
                    $currentData = $this->readFile($sessionId);
                    if ($base !== [] && $currentData === '') {
                        // We read this session with data and it is gone now:
                        // destroyed (logout) or rotated since our read. Writing
                        // would bring it back — refuse (destroy() takes this
                        // same lock, so this check cannot race it).
                        unset($this->context[\ZealPHP\RequestContext::ownerCid()][$sessionId]);
                        return false;
                    }
                }

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
                if (!$this->setRow($key, $data, $newVersion)) {
                    // Too big for the row (or the Table is full): the file is
                    // the store for this session now. Drop any row we hold —
                    // a failed set() can leave the OLD row (or a truncated
                    // copy of the new data) in place, which read() would serve
                    // from then on instead of the file.
                    $table->del($key);
                    $newVersion = 0;
                }
                $this->remember($sessionId, $local, $newVersion);

                // Write-through to file backing. Keys present in our read
                // snapshot but absent from the final state are DELETIONS —
                // writeFile's disk merge must not resurrect them (#233).
                $deleted = array_values(array_map('strval', array_diff(array_keys($base), array_keys($local))));
                return $this->writeFile($sessionId, $data, $deleted) || $newVersion > 0;
            } finally {
                $writeLock->set(0);
            }
        }
        // @phpstan-ignore-next-line deadCode.unreachable — defensive fallthrough
        return false;
    }

    public function destroy($sessionId): bool
    {
        $sessionId = (string) $sessionId;
        $writeLock = $this->lockFor($sessionId);
        // Under the write lock, so an in-flight write either lands before the
        // destroy (and is deleted with it) or sees the session gone.
        $this->acquire($writeLock);
        try {
            $this->table()->del(self::rowKey($sessionId));
            $file = self::$savePath . '/sess_' . basename($sessionId);
            if (is_file($file)) @unlink($file);
        } finally {
            $writeLock->set(0);
        }
        unset($this->context[\ZealPHP\RequestContext::ownerCid()][$sessionId]);
        return true;
    }

    public function gc($maxlifetime): int
    {
        $now = time();
        $count = 0;
        // Collect, then delete: deleting while iterating an OpenSwoole\Table
        // skips rows.
        $expired = [];
        foreach ($this->table() as $id => $row) {
            if (!is_array($row)) continue;
            $expRaw = $row[self::COL_EXPIRES] ?? 0;
            $expires = is_numeric($expRaw) ? (int) $expRaw : 0;
            if ($expires > 0 && $expires < $now) {
                $expired[] = (string) $id;
            }
        }
        foreach ($expired as $id) {
            $this->table()->del($id);
            $count++;
        }
        // The file backing is swept by age rather than by row: a session that
        // only lives on disk (too big for a row, Table full) has no row, and a
        // long id's row key is a hash of the id, not its file name. Every
        // write refreshes the file's mtime, so this matches the row TTL.
        $cutoff = $now - max(1, self::$ttl);
        foreach (glob(self::$savePath . '/sess_*') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Table key for a session id. OpenSwoole\Table stores at most 63 bytes of
     * a key: a longer one (every id session_regenerate_id() mints is 64 hex
     * chars) is truncated in the stored row — iteration then yields a key
     * that del()/get() cannot find, and distinct ids share a stored key. Ids
     * up to 63 bytes are used as-is; longer ones by a fixed-length digest
     * (`~h~` prefix, 46 bytes) that a short id cannot collide with by chance.
     */
    public static function rowKey(string $sessionId): string
    {
        return \ZealPHP\Store\TableBackend::key($sessionId);
    }

    /**
     * Store a row; `false` when the data does not fit the column or the
     * Table is full. OpenSwoole throws for both (and may still write a
     * truncated value), so the size is checked first.
     */
    private function setRow(string $key, string $data, int $version): bool
    {
        if (strlen($data) > self::$dataSize) {
            return false;
        }
        try {
            return $this->table()->set($key, [
                self::COL_DATA => $data,
                self::COL_VERSION => $version,
                self::COL_EXPIRES => time() + self::$ttl,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Record this coroutine's read snapshot for `$sessionId`. The bucket is
     * dropped when the coroutine ends even if close() is never called.
     *
     * @param array<mixed,mixed> $base
     */
    private function remember(string $sessionId, array $base, int $version): void
    {
        $cid = \ZealPHP\RequestContext::ownerCid();
        // A fire-and-forget child that outlives its request: the owner's
        // cleanup already ran, so key this snapshot to the child itself.
        if ($cid !== Coroutine::getCid() && !Coroutine::exists($cid)) {
            $cid = Coroutine::getCid();
        }
        // The cleanup must run when the REQUEST ends: register it only from the
        // owning coroutine (a child's defer fires when the child ends). A
        // child-first read is still dropped by close() at write_close.
        if ($cid > 0 && !isset($this->context[$cid]) && $cid === Coroutine::getCid()) {
            Coroutine::defer(function () use ($cid): void {
                unset($this->context[$cid]);
            });
        }
        $this->context[$cid][$sessionId] = ['base' => $base, 'version' => $version];
    }

    /**
     * True only when no process has this pid. kill(pid, 0) also fails with
     * EPERM for a LIVE process of another user; only ESRCH means it is gone.
     */
    private static function processIsGone(int $pid): bool
    {
        if (!function_exists('posix_kill') || @posix_kill($pid, 0)) {
            return false;
        }
        return posix_get_last_error() === 3;   // ESRCH
    }

    /** Longest a request waits for a shard lock before failing its session write. */
    public static float $lockTimeoutSeconds = 5.0;

    /**
     * Take a shard lock. The lock holds the owner's pid, so a lock left behind
     * by a worker that died mid-write (it used to wedge that shard's sessions
     * forever) is reclaimed. A lock a LIVE process still holds past
     * $lockTimeoutSeconds fails this request's session I/O instead of
     * blocking the worker indefinitely.
     */
    private function acquire(Atomic $lock): void
    {
        $me = getmypid() ?: 1;
        $spin = 100; // microseconds
        $start = microtime(true);
        $deadline = $start + self::$lockTimeoutSeconds;
        $nextProbe = $start + 0.1;   // a dead holder is reclaimed after ~100 ms, not the full timeout
        while (!$lock->cmpset(0, $me)) {
            $now = microtime(true);
            if ($now >= $nextProbe) {
                $nextProbe = $now + 0.1;
                $holder = $lock->get();
                if ($holder > 0 && $holder !== $me && self::processIsGone($holder) && $lock->cmpset($holder, $me)) {
                    return;   // the holder is gone: reclaim its lock
                }
            }
            if ($now >= $deadline) {
                throw new \RuntimeException('TableSessionHandler: session shard lock held by pid ' . $lock->get() . ' for over '
                    . self::$lockTimeoutSeconds . 's; failing this session write');
            }
            if (Coroutine::getCid() > 0) {
                Coroutine::usleep($spin);
            } else {
                usleep($spin);
            }
            $spin = min($spin * 2, 2000);
        }
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
     * Same decoder as the session layer (an undecodable session is empty,
     * never partially parsed — see php_session_decode_to_array()).
     *
     * @return array<mixed,mixed>
     */
    private function decode(string $data): array
    {
        return $data === '' ? [] : \ZealPHP\Session\php_session_decode_to_array($data);
    }

    /**
     * @param array<mixed,mixed> $data
     */
    private function encode(array $data): string
    {
        /** @var array<string, mixed> $data */
        return \ZealPHP\Session\php_session_encode_from_array($data);
    }

    private function readFile(string $sessionId): string
    {
        $file = self::$savePath . '/sess_' . basename((string) $sessionId);
        if (!is_file($file)) return '';
        // An expired session stays expired: the backing file outlives its Table
        // row (its expired row was just deleted, or a worker restart emptied
        // the Table), and promoting it would hand the session a fresh TTL until
        // the next GC. Writes refresh the mtime, so mtime + TTL is the expiry.
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime + self::$ttl <= time()) {
            @unlink($file);
            return '';
        }
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

    /**
     * @param list<string> $deletedKeys Top-level keys the writer deleted this
     *        request (in its read snapshot, absent from the final state) —
     *        excluded from the disk merge so they don't resurrect.
     */
    private function writeFile(string $sessionId, string $data, array $deletedKeys = []): bool
    {
        $file = self::$savePath . '/sess_' . basename((string) $sessionId);
        $fp = @fopen($file, 'c+');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            // #233 residual — read-merge-write under the lock. The Table path
            // is CAS+merge3-protected, but this file backing (spillover +
            // persistence) was a blind truncate+write, so state that only
            // survived on DISK (e.g. a value the Table column truncated, or a
            // writer outside the shard-lock discipline) was lost wholesale.
            // Merge the on-disk state first — top-level keys, ours winning on
            // conflict, our explicit deletions honoured — the same documented
            // shallow-merge contract as the manager's file path in
            // zeal_session_write_close().
            $disk = stream_get_contents($fp);
            if (is_string($disk) && $disk !== '') {
                $diskData = $this->decode($disk);
                if ($diskData !== []) {
                    // array_replace, not array_merge: integer keys ($_SESSION[5])
                    // must keep their key, not be renumbered from 0.
                    $merged = array_replace($diskData, $this->decode($data));
                    foreach ($deletedKeys as $dk) {
                        unset($merged[$dk]);
                    }
                    $data = $this->encode($merged);
                }
            }
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
