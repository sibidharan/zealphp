<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use ZealPHP\Session\Handler\RedisSessionHandler;

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
}
