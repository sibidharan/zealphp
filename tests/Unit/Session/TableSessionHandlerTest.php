<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use OpenSwoole\Table;
use PHPUnit\Framework\TestCase;
use ZealPHP\Session\Handler\TableSessionHandler;

/**
 * Behavioural coverage for TableSessionHandler — the Table-as-store +
 * file-as-backing concurrent-safe session handler added in the
 * concurrent-session-merge PR.
 *
 * The handler is a process-global singleton (static Table + Atomic), so we
 * register once in setUpBeforeClass() against a temp save dir and use a unique
 * session id per test to keep them independent. Real OpenSwoole\Table /
 * Atomic instances work fine without a running server, so every path here is
 * exercised against the genuine storage — no mocks.
 */
final class TableSessionHandlerTest extends TestCase
{
    private static string $saveDir = '';
    private static TableSessionHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::$saveDir = sys_get_temp_dir() . '/zp-tsh-' . bin2hex(random_bytes(5));
        @mkdir(self::$saveDir, 0700, true);
        self::$handler = TableSessionHandler::register(
            ttl: 3600,
            maxRows: 256,
            dataSize: 16384,
            savePath: self::$saveDir
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$saveDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$saveDir);
    }

    private function sid(string $tag): string
    {
        return 'zptest_' . $tag . '_' . bin2hex(random_bytes(4));
    }

    /** Reach into the static Table for white-box setup of concurrent state. */
    private function table(): Table
    {
        $ref = new \ReflectionClass(self::$handler);
        $p = $ref->getProperty('table');
        $p->setAccessible(true);
        $t = $p->getValue();
        $this->assertInstanceOf(Table::class, $t);
        return $t;
    }

    // ── Registration / lifecycle ──────────────────────────────────────────

    public function testRegisterReturnsSingleton(): void
    {
        $again = TableSessionHandler::register(savePath: self::$saveDir);
        $this->assertSame(self::$handler, $again, 'register() is idempotent — same instance');
        $this->assertSame(self::$handler, TableSessionHandler::instance());
    }

    public function testOpenAndCloseReturnTrue(): void
    {
        $this->assertTrue(self::$handler->open(self::$saveDir, 'PHPSESSID'));
        $this->assertTrue(self::$handler->close());
    }

    public function testReadSnapshotIsKeyedByCoroutineAndClearedOnClose(): void
    {
        // #182: the read snapshot is keyed by coroutine id THEN session id (so
        // concurrent same-session requests don't clobber each other), and close()
        // reclaims this coroutine's bucket so it can't grow unbounded.
        $sid = $this->sid('snap');
        self::$handler->write($sid, 'v|i:1;');
        self::$handler->read($sid); // populates context[cid][sid]

        $ref = new \ReflectionProperty(self::$handler, 'context');
        $ref->setAccessible(true);
        $cid = \OpenSwoole\Coroutine::getCid();
        $ctx = $ref->getValue(self::$handler);
        $this->assertArrayHasKey($cid, $ctx);
        $this->assertArrayHasKey($sid, $ctx[$cid]);

        $this->assertTrue(self::$handler->close());
        $this->assertArrayNotHasKey($cid, $ref->getValue(self::$handler), 'close() clears the coroutine bucket');
    }

    // ── read / write round-trip ───────────────────────────────────────────

    public function testWriteThenReadRoundTrip(): void
    {
        $sid = $this->sid('rt');
        $payload = 'user_id|i:42;';
        $this->assertTrue(self::$handler->write($sid, $payload));
        $this->assertSame($payload, self::$handler->read($sid));
    }

    public function testWriteAlsoPersistsToFileBacking(): void
    {
        $sid = $this->sid('file');
        $payload = 'token|s:5:"abcde";';
        self::$handler->write($sid, $payload);

        $file = self::$saveDir . '/sess_' . $sid;
        $this->assertFileExists($file, 'write-through must persist to file backing');
        $this->assertSame($payload, file_get_contents($file));
    }

    public function testReadUnknownSessionReturnsEmptyString(): void
    {
        $this->assertSame('', self::$handler->read($this->sid('missing')));
    }

    public function testColdLoadPromotesFileToTable(): void
    {
        // Seed only the file (Table miss) — read() must load + promote it.
        $sid = $this->sid('cold');
        $payload = 'x|i:9;';
        file_put_contents(self::$saveDir . '/sess_' . $sid, $payload);

        $this->assertSame($payload, self::$handler->read($sid), 'cold read loads from file');
        // After promotion the row lives in the Table too.
        $row = $this->table()->get($sid);
        $this->assertIsArray($row);
        $this->assertSame($payload, $row['data']);
    }

    public function testExpiredTableRowIsTreatedAsMiss(): void
    {
        $sid = $this->sid('expired');
        // No file backing -> expired Table row should read back empty.
        $this->table()->set($sid, ['data' => 'stale|i:1;', 'version' => 5, 'expires' => time() - 100]);
        $this->assertSame('', self::$handler->read($sid), 'expired row must not be returned');
    }

    // ── destroy / gc ──────────────────────────────────────────────────────

    public function testDestroyRemovesTableRowAndFile(): void
    {
        $sid = $this->sid('destroy');
        self::$handler->write($sid, 'a|i:1;');
        $file = self::$saveDir . '/sess_' . $sid;
        $this->assertFileExists($file);

        $this->assertTrue(self::$handler->destroy($sid));
        $this->assertSame('', self::$handler->read($sid));
        $this->assertFileDoesNotExist($file);
    }

    public function testGcDeletesExpiredRowsAndReturnsCount(): void
    {
        $sid = $this->sid('gc');
        $this->table()->set($sid, ['data' => 'g|i:2;', 'version' => 1, 'expires' => time() - 100]);

        $count = self::$handler->gc(1);
        $this->assertGreaterThanOrEqual(1, $count, 'gc returns number of reaped rows');
        $this->assertFalse($this->table()->exists($sid), 'expired row removed by gc');
    }

    public function testGcKeepsLiveRows(): void
    {
        $sid = $this->sid('gclive');
        self::$handler->write($sid, 'live|i:1;');
        self::$handler->gc(1);
        $this->assertTrue($this->table()->exists($sid), 'non-expired row survives gc');
    }

    // ── concurrent-write conflict → 3-way merge ───────────────────────────

    public function testConcurrentWriteTriggersLeafLevelMerge(): void
    {
        $sid = $this->sid('merge');

        // 1. Establish a base: cart with item1.
        self::$handler->write($sid, 'cart|a:1:{s:5:"item1";i:5;}');
        // Refresh our read snapshot (base + version) for this coroutine.
        self::$handler->read($sid);

        // 2. Simulate a concurrent coroutine writing item3 first (version bump).
        $this->table()->set($sid, [
            'data'    => 'cart|a:2:{s:5:"item1";i:5;s:5:"item3";i:7;}',
            'version' => 999,
            'expires' => time() + 3600,
        ]);

        // 3. Our write adds item2 — the version mismatch forces a 3-way merge.
        self::$handler->write($sid, 'cart|a:2:{s:5:"item1";i:5;s:5:"item2";i:3;}');

        $merged = self::$handler->read($sid);
        // item3 (remote) AND item2 (local) must both survive the merge.
        $this->assertStringContainsString('item3', $merged);
        $this->assertStringContainsString('item2', $merged);
        $this->assertStringContainsString('item1', $merged);
    }

    // ── merge3 unit cases (public method, leaf-level granularity) ─────────

    public function testMerge3AddsLocalOnlyKeys(): void
    {
        $merged = self::$handler->merge3(
            ['user_id' => 1],
            ['user_id' => 1, 'cart' => ['x' => 5]],
            ['user_id' => 1, 'profile' => ['name' => 'Alice']]
        );
        $this->assertSame(
            ['user_id' => 1, 'profile' => ['name' => 'Alice'], 'cart' => ['x' => 5]],
            $merged
        );
    }

    public function testMerge3RecursesForLeafKeys(): void
    {
        $merged = self::$handler->merge3(
            ['cart' => ['a' => 1]],
            ['cart' => ['a' => 1, 'b' => 2]],
            ['cart' => ['a' => 1, 'c' => 3]]
        );
        $this->assertSame(['cart' => ['a' => 1, 'c' => 3, 'b' => 2]], $merged);
    }

    public function testMerge3LocalWinsOnChangedLeaf(): void
    {
        $merged = self::$handler->merge3(['n' => 1], ['n' => 10], ['n' => 7]);
        $this->assertSame(['n' => 10], $merged);
    }

    public function testMerge3KeepsRemoteWhenLocalUnchanged(): void
    {
        $merged = self::$handler->merge3(['n' => 1], ['n' => 1], ['n' => 5]);
        $this->assertSame(['n' => 5], $merged);
    }

    public function testMerge3DeletionPreservedWhenRemoteUnchanged(): void
    {
        $merged = self::$handler->merge3(['flag' => true], [], ['flag' => true]);
        $this->assertSame([], $merged, 'local deletion wins when remote untouched');
    }

    public function testMerge3DeletionLostWhenRemoteChanged(): void
    {
        $merged = self::$handler->merge3(['flag' => true], [], ['flag' => false]);
        $this->assertSame(['flag' => false], $merged, 'concurrent remote edit beats deletion');
    }

    // ── decode/encode round-trip (drives the manual serialize parser) ─────

    public function testEncodeDecodeRoundTripViaWriteRead(): void
    {
        // Exercises encode() implicitly on write + decode() on the merge path.
        $sid = $this->sid('codec');
        $payload = 'a|i:1;b|s:3:"xyz";nested|a:1:{s:1:"k";i:9;}';
        self::$handler->write($sid, $payload);
        $this->assertSame($payload, self::$handler->read($sid));

        // Force decode() by triggering a conflict merge that must parse the data.
        self::$handler->read($sid);
        $this->table()->set($sid, [
            'data'    => 'a|i:1;b|s:3:"xyz";nested|a:1:{s:1:"k";i:9;}other|i:2;',
            'version' => 888,
            'expires' => time() + 3600,
        ]);
        self::$handler->write($sid, $payload); // no local change -> remote 'other' kept
        $this->assertStringContainsString('other', self::$handler->read($sid));
    }

    /** @return mixed */
    private function decode(string $data)
    {
        $m = new \ReflectionMethod(self::$handler, 'decode');
        $m->setAccessible(true);
        return $m->invoke(self::$handler, $data);
    }

    public function testDecodeEmptyStringYieldsEmptyArray(): void
    {
        $this->assertSame([], $this->decode(''));
    }

    public function testDecodeWithoutDelimiterStopsParsing(): void
    {
        // No '|' separator → the parser can't find a key boundary, bails out.
        $this->assertSame([], $this->decode('no-delimiter-here'));
    }

    public function testDecodeWithUnparseableValueStopsParsing(): void
    {
        // Key present, but the value isn't valid serialize() output →
        // serializedLength() returns 0 → loop breaks, no key captured.
        $this->assertSame([], $this->decode('k|garbage-not-serialized'));
    }

    public function testDecodeHandlesSerializedFalseSentinel(): void
    {
        // 'b:0;' deserializes to false but IS valid — the parser must NOT
        // treat it as a failure (the b:0; special-case branch).
        $this->assertSame(['k' => false], $this->decode('k|b:0;'));
    }

    public function testDecodeKeepsKeysAfterAStoredFalse(): void
    {
        // Regression: a stored boolean false that is NOT the last entry must not
        // make decode() drop every subsequent key. The old serializedLength()
        // compared the WHOLE remaining tail to 'b:0;' (only true for a trailing
        // false), so 'logged_in|b:0;user_id|i:42;...' returned just the first key
        // (silent session-data loss). Now it compares the 4-byte prefix.
        $this->assertSame(
            ['logged_in' => false, 'user_id' => 42, 'role' => 'admin'],
            $this->decode('logged_in|b:0;user_id|i:42;role|s:5:"admin";')
        );
    }

    public function testReadFileMissingReturnsEmpty(): void
    {
        $m = new \ReflectionMethod(self::$handler, 'readFile');
        $m->setAccessible(true);
        $this->assertSame('', $m->invoke(self::$handler, 'no-such-session-' . bin2hex(random_bytes(4))));
    }

    // ── sharded write lock (scale fix) ────────────────────────────────────

    public function testWriteLockShardingIsStablePerSessionAndDistributed(): void
    {
        // The single global write-lock Atomic (which serialised EVERY session
        // write) was replaced with a bounded sharded set. Contract: same id →
        // same lock (same-session writes MUST serialise for the read-merge-CAS);
        // different ids spread across shards (so they don't all serialise).
        $m = new \ReflectionMethod(self::$handler, 'lockFor');
        $m->setAccessible(true);

        $a1 = $m->invoke(self::$handler, 'session-A');
        $a2 = $m->invoke(self::$handler, 'session-A');
        $this->assertInstanceOf(\OpenSwoole\Atomic::class, $a1);
        $this->assertSame($a1, $a2, 'same session id always maps to the same lock shard');

        $shards = [];
        for ($i = 0; $i < 300; $i++) {
            $shards[spl_object_id($m->invoke(self::$handler, "sess-{$i}"))] = true;
        }
        $this->assertGreaterThan(1, count($shards), 'distinct sessions hash to multiple lock shards');
    }

    public function testConcurrentWritesToDistinctSessionsAllSucceed(): void
    {
        // The scale property: many concurrent writes to DIFFERENT sessions
        // (which mostly hit different shards) all complete + round-trip
        // correctly — they no longer serialise behind one global lock.
        $results = [];
        \OpenSwoole\Coroutine::run(function () use (&$results): void {
            $done = new \OpenSwoole\Coroutine\Channel(24);
            for ($i = 0; $i < 24; $i++) {
                go(function () use ($done, $i): void {
                    $sid = "conc-{$i}-" . bin2hex(random_bytes(3));
                    self::$handler->write($sid, "v|i:{$i};");
                    $done->push(self::$handler->read($sid) === "v|i:{$i};");
                });
            }
            for ($i = 0; $i < 24; $i++) { $results[] = $done->pop(5.0); }
        });
        $this->assertCount(24, $results);
        $this->assertNotContains(false, $results, 'every concurrent distinct-session write round-tripped');
    }
}
