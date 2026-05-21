<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Tests\TestCase;
use ZealPHP\GithubStars;
use ZealPHP\Store;

/**
 * Characterization tests for GithubStars.
 *
 * Network calls are NEVER made: the only seam that fetches from api.github.com
 * is fetchAndStore(), reachable solely via the refreshAsync() coroutine. Every
 * test here either (a) pre-seeds the shared Store table so format() takes the
 * cache-hit path (fresh fetched_at => not stale => no refresh), or (b) sets the
 * per-worker $refreshInFlight guard so refreshAsync() early-returns before
 * spawning a coroutine. formatCount() is pure and exercised via reflection.
 */
class GithubStarsTest extends TestCase
{
    private const TABLE = 'github_stars';

    public static function setUpBeforeClass(): void
    {
        // register() creates the 1-row Store table. Safe to call once; the
        // table persists for the process. Repo name is arbitrary — no fetch
        // happens at registration time.
        GithubStars::register('sibidharan/zealphp');
    }

    protected function setUp(): void
    {
        // Reset the in-flight guard and clear the single row between tests so
        // each case controls staleness explicitly.
        $this->setRefreshInFlight(false);
        Store::del(self::TABLE, 'count');
    }

    protected function tearDown(): void
    {
        $this->setRefreshInFlight(false);
        Store::del(self::TABLE, 'count');
    }

    private function setRefreshInFlight(bool $value): void
    {
        $p = new \ReflectionProperty(GithubStars::class, 'refreshInFlight');
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }

    private function invokeFormatCount(int $n): string
    {
        $m = new \ReflectionMethod(GithubStars::class, 'formatCount');
        $m->setAccessible(true);
        $result = $m->invoke(null, $n);
        $this->assertIsString($result);
        return $result;
    }

    private function getRefreshInFlight(): bool
    {
        $p = new \ReflectionProperty(GithubStars::class, 'refreshInFlight');
        $p->setAccessible(true);
        return (bool) $p->getValue();
    }

    public function testRegisterCreatesStoreTable(): void
    {
        // Call register() inside the test body (idempotent — Store::make
        // recreates the 1-row table) so its body is exercised under coverage.
        GithubStars::register('octocat/hello-world');
        $this->assertContains(self::TABLE, Store::names());

        // The 3-column schema is usable: a row round-trips through Store.
        Store::set(self::TABLE, 'count', [
            'count'      => 7,
            'fetched_at' => time(),
            'formatted'  => '7',
        ]);
        $this->assertSame('7', GithubStars::format());

        // Restore the canonical repo for the rest of the class.
        GithubStars::register('sibidharan/zealphp');
    }

    public function testFormatReturnsCachedValueOnFreshHit(): void
    {
        // Fresh fetched_at (now) => not stale => no refresh spawned.
        Store::set(self::TABLE, 'count', [
            'count'      => 1234,
            'fetched_at' => time(),
            'formatted'  => '1.2k',
        ]);

        $this->assertSame('1.2k', GithubStars::format());
    }

    public function testFormatReturnsEmptyStringWhenNothingCached(): void
    {
        // No row => fetched_at resolves to 0 => stale => refreshAsync() called,
        // but the guard makes it a no-op (no coroutine, no network).
        $this->setRefreshInFlight(true);

        $this->assertSame('', GithubStars::format());
    }

    public function testFormatReturnsCachedValueEvenWhenStale(): void
    {
        // Stale (fetched_at = 0) but a formatted value is present: format()
        // should still serve the cached text. Guard suppresses the refresh.
        $this->setRefreshInFlight(true);
        Store::set(self::TABLE, 'count', [
            'count'      => 50,
            'fetched_at' => 0,
            'formatted'  => '50',
        ]);

        $this->assertSame('50', GithubStars::format());
    }

    public function testFormatReturnsEmptyWhenFormattedFieldEmpty(): void
    {
        // Fresh timestamp (not stale) but empty formatted text => empty string.
        Store::set(self::TABLE, 'count', [
            'count'      => 0,
            'fetched_at' => time(),
            'formatted'  => '',
        ]);

        $this->assertSame('', GithubStars::format());
    }

    public function testRefreshAsyncNoOpsWhenInFlight(): void
    {
        // refreshAsync() must early-return when a refresh is already in flight.
        // Calling it should neither throw nor spawn a coroutine (no network).
        $this->setRefreshInFlight(true);

        $m = new \ReflectionMethod(GithubStars::class, 'refreshAsync');
        $m->setAccessible(true);
        $m->invoke(null);

        // Guard untouched (still in flight), no exception, no coroutine spawned.
        $this->assertTrue($this->getRefreshInFlight());
    }

    public function testRefreshAsyncNoOpsWhenRepoNull(): void
    {
        // With repo == null and not in flight, refreshAsync() still early-returns
        // (the `|| self::$repo === null` branch) without spawning a coroutine.
        $repoProp = new \ReflectionProperty(GithubStars::class, 'repo');
        $repoProp->setAccessible(true);
        $original = $repoProp->getValue();

        $this->setRefreshInFlight(false);
        $repoProp->setValue(null, null);

        try {
            $m = new \ReflectionMethod(GithubStars::class, 'refreshAsync');
            $m->setAccessible(true);
            $m->invoke(null);
            // repo === null => early return; the in-flight guard is never set.
            $this->assertFalse($this->getRefreshInFlight());
        } finally {
            // Restore so other tests / app state is unaffected.
            $repoProp->setValue(null, $original);
        }
    }

    public function testFormatCountBelowThousandIsRaw(): void
    {
        $this->assertSame('0', $this->invokeFormatCount(0));
        $this->assertSame('5', $this->invokeFormatCount(5));
        $this->assertSame('999', $this->invokeFormatCount(999));
    }

    public function testFormatCountThousandsGetsOneDecimalK(): void
    {
        $this->assertSame('1.0k', $this->invokeFormatCount(1000));
        $this->assertSame('1.2k', $this->invokeFormatCount(1234));
        $this->assertSame('1.5k', $this->invokeFormatCount(1500));
        $this->assertSame('9.9k', $this->invokeFormatCount(9949));
    }

    public function testFormatCountTenThousandPlusHasNoDecimals(): void
    {
        $this->assertSame('10k', $this->invokeFormatCount(10000));
        $this->assertSame('12k', $this->invokeFormatCount(12345));
        $this->assertSame('100k', $this->invokeFormatCount(100000));
    }
}
