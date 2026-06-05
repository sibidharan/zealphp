<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\Session\Handler\RedisSessionHandler;
use ZealPHP\Session\Handler\TableSessionHandler;

/**
 * 3-way merge correctness — the property that closes the Deep_Ad1959
 * hole. Tests on the static `merge3Array` helper so we don't need a
 * live Redis or Table instance.
 */
final class TableSessionMergeTest extends TestCase
{
    public function testDisjointTopLevelKeysBothSurvive(): void
    {
        $base = ['user_id' => 1];
        $local = ['user_id' => 1, 'cart' => ['item1' => 5]];
        $remote = ['user_id' => 1, 'profile' => ['name' => 'Alice']];

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame(['user_id' => 1, 'profile' => ['name' => 'Alice'], 'cart' => ['item1' => 5]], $merged);
    }

    public function testDisjointLeafKeysUnderSameParentBothSurvive(): void
    {
        // The hole: A writes cart.item2, B writes cart.item3. With key-level
        // merge, one is lost. With recursive merge, both survive.
        $base = ['cart' => ['item1' => 5]];
        $local = ['cart' => ['item1' => 5, 'item2' => 3]];   // A adds item2
        $remote = ['cart' => ['item1' => 5, 'item3' => 7]];  // B added item3 first

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame(['cart' => ['item1' => 5, 'item3' => 7, 'item2' => 3]], $merged);
    }

    public function testSameLeafKeyLocalWins(): void
    {
        $base = ['cart' => ['item1' => 5]];
        $local = ['cart' => ['item1' => 10]];
        $remote = ['cart' => ['item1' => 7]];

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame(['cart' => ['item1' => 10]], $merged);
    }

    public function testLocalUnchangedKeepsRemote(): void
    {
        $base = ['counter' => 1];
        $local = ['counter' => 1];   // local didn't change it
        $remote = ['counter' => 5];  // remote bumped it

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame(['counter' => 5], $merged);
    }

    public function testDeletionPreservedWhenRemoteUnchanged(): void
    {
        $base = ['flag' => true];
        $local = [];  // local deleted flag
        $remote = ['flag' => true];  // remote still has base value

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame([], $merged);
    }

    public function testDeletionLostWhenRemoteChanged(): void
    {
        // Conflict: local deleted, remote changed. Remote wins (concurrent
        // edit beats deletion).
        $base = ['flag' => true];
        $local = [];
        $remote = ['flag' => false];

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame(['flag' => false], $merged);
    }

    public function testThreeLevelsDeep(): void
    {
        $base = ['user' => ['prefs' => ['theme' => 'light']]];
        $local = ['user' => ['prefs' => ['theme' => 'dark', 'lang' => 'en']]];
        $remote = ['user' => ['prefs' => ['theme' => 'light', 'fontsize' => 14]]];

        $merged = RedisSessionHandler::merge3Array($base, $local, $remote);

        $this->assertSame([
            'user' => ['prefs' => ['theme' => 'dark', 'fontsize' => 14, 'lang' => 'en']],
        ], $merged);
    }

    public function testSerializeRoundtrip(): void
    {
        $data = ['cart' => ['item1' => 5, 'item2' => 'hello'], 'user_id' => 42];
        $encoded = RedisSessionHandler::serializeSession($data);
        $decoded = RedisSessionHandler::parseSession($encoded);
        $this->assertSame($data, $decoded);
    }

    public function testEmptySessionRoundtrip(): void
    {
        $this->assertSame([], RedisSessionHandler::parseSession(''));
        $this->assertSame('', RedisSessionHandler::serializeSession([]));
    }

    // ── #253: TableSessionHandler::merge3() list-append union ──────────────
    //
    // merge3() diffs on array KEYS. The idiomatic `$_SESSION['flash'][] = 'x'`
    // append writes the next integer index, so two concurrent appends both land
    // at index 0 — the old key-aligned local-wins dropped one. The fix detects
    // three list-shaped sub-arrays and merges them by VALUE union (remote +
    // local-new) so both appends survive. These run against the real instance
    // method (newInstanceWithoutConstructor — merge3 touches no state).

    private function tableHandler(): TableSessionHandler
    {
        /** @var TableSessionHandler $h */
        $h = (new \ReflectionClass(TableSessionHandler::class))->newInstanceWithoutConstructor();
        return $h;
    }

    public function testConcurrentFlashAppendsBothSurvive(): void
    {
        $h = $this->tableHandler();
        // base flash empty; A appended msgA, B appended msgB concurrently.
        $merged = $h->merge3(
            ['flash' => []],
            ['flash' => ['msgA']],
            ['flash' => ['msgB']]
        );
        // Both appends present — remote first (already committed), then local-new.
        $this->assertSame(['flash' => ['msgB', 'msgA']], $merged);
    }

    public function testListAppendOntoExistingElementsUnions(): void
    {
        $h = $this->tableHandler();
        // base already has one element; A and B each append a different one.
        $merged = $h->merge3(
            ['queue' => ['a']],
            ['queue' => ['a', 'b']],   // local added b
            ['queue' => ['a', 'c']]    // remote added c
        );
        $this->assertSame(['queue' => ['a', 'c', 'b']], $merged);
    }

    public function testListMergeReindexesSequentially(): void
    {
        $h = $this->tableHandler();
        $merged = $h->merge3(
            ['flash' => []],
            ['flash' => ['only-local']],
            ['flash' => ['remote-1', 'remote-2']]
        );
        $this->assertSame(['flash' => ['remote-1', 'remote-2', 'only-local']], $merged);
        // Result is a clean 0-indexed list.
        $this->assertSame([0, 1, 2], array_keys($merged['flash']));
    }

    public function testStringKeyedMapKeepsLeafLocalWins(): void
    {
        // The map case (string keys) must NOT use the list-union path — it keeps
        // the documented leaf-level local-wins behaviour.
        $h = $this->tableHandler();
        $merged = $h->merge3(
            ['cart' => ['item1' => 5]],
            ['cart' => ['item1' => 5, 'item2' => 3]],   // local adds item2
            ['cart' => ['item1' => 5, 'item3' => 7]]    // remote adds item3
        );
        $this->assertSame(['cart' => ['item1' => 5, 'item3' => 7, 'item2' => 3]], $merged);
    }

    public function testMapDeleteDetectionUnchanged(): void
    {
        // Deletion of a string key with remote unchanged still drops it.
        $h = $this->tableHandler();
        $merged = $h->merge3(
            ['flag' => true, 'keep' => 1],
            ['keep' => 1],                 // local deleted flag
            ['flag' => true, 'keep' => 1]  // remote still has base value
        );
        $this->assertSame(['keep' => 1], $merged);
    }

    public function testValueDiffCaveatAppendEqualToBaseIsTreatedAsUnchanged(): void
    {
        // Documented caveat: the union diffs by VALUE (`!in_array($v, $base)`),
        // so if local's appended value already EXISTS in base, the diff cannot
        // tell it apart from base's copy and treats it as "not new" — the local
        // append is not added on top of remote. (Two distinct values never
        // collide; only a value-equal-to-an-existing-element does.)
        $h = $this->tableHandler();
        $merged = $h->merge3(
            ['tags' => ['x']],
            ['tags' => ['x', 'x']],   // local appended a SECOND 'x'
            ['tags' => ['x', 'y']]    // remote appended 'y'
        );
        // The second 'x' is indistinguishable from base's 'x' → not re-added;
        // remote's 'y' survives. No data is lost across distinct values.
        $this->assertSame(['tags' => ['x', 'y']], $merged);
    }
}
