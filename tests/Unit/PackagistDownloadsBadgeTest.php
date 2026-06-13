<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\Badge\PackagistDownloads;
use ZealPHP\Tests\TestCase;

/**
 * The combined-downloads shields endpoint (route /badge/downloads). The live
 * sum hits Packagist over HTTP and is exercised on the running server; here we
 * pin the pure pieces: the JSON envelope, the per-package parse contract, the
 * human-readable formatting, and the per-worker memo (seeded via reflection so
 * no network is touched).
 */
final class PackagistDownloadsBadgeTest extends TestCase
{
    private function seedMemo(?int $total, int $age = 0): void
    {
        $t = new \ReflectionProperty(PackagistDownloads::class, 'memoTotal');
        $t->setAccessible(true);
        $t->setValue(null, $total);
        $a = new \ReflectionProperty(PackagistDownloads::class, 'memoAt');
        $a->setAccessible(true);
        $a->setValue(null, time() - $age);
    }

    protected function tearDown(): void
    {
        $this->seedMemo(null, 0); // reset the static memo between tests
        parent::tearDown();
    }

    public function testEndpointEnvelopeWithFreshMemo(): void
    {
        $this->seedMemo(257);
        $e = PackagistDownloads::endpoint();
        self::assertSame(1, $e['schemaVersion']);
        self::assertSame('downloads', $e['label']);
        self::assertSame('257', $e['message']);
        self::assertSame('blue', $e['color']);
        self::assertSame(3600, $e['cacheSeconds']);
    }

    public function testEndpointUnknownWhenNoMemoAndNoFetch(): void
    {
        // No memo and (in the unit harness) no reachable HTTP → graceful
        // 'unknown'/lightgrey rather than a broken badge. If the runner DOES
        // have outbound network, a real total is equally acceptable.
        $this->seedMemo(null);
        $e = PackagistDownloads::endpoint();
        self::assertContains($e['color'], ['lightgrey', 'blue']);
        self::assertNotSame('', $e['message']);
    }

    public function testStaleMemoIsServedOnRefreshFailureShape(): void
    {
        // A memo older than the TTL with no reachable network should still
        // yield the last good value (serve-stale), never a regression to null.
        $this->seedMemo(1234, 7200); // 2h old, TTL is 1h
        $e = PackagistDownloads::endpoint();
        // Either a fresh fetch succeeded (real number) or the stale 1234 is served.
        self::assertNotSame('unknown', $e['message']);
    }

    /** @dataProvider parseProvider */
    public function testParseTotal(mixed $decoded, ?int $expected): void
    {
        self::assertSame($expected, PackagistDownloads::parseTotal($decoded));
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function parseProvider(): array
    {
        return [
            'valid'          => [['package' => ['downloads' => ['total' => 252]]], 252],
            'zero'           => [['package' => ['downloads' => ['total' => 0]]], 0],
            'missing total'  => [['package' => ['downloads' => []]], null],
            'missing pkg'    => [['other' => 1], null],
            'non-int total'  => [['package' => ['downloads' => ['total' => '5']]], null],
            'not an array'   => ['nope', null],
            'null'           => [null, null],
        ];
    }

    /** @dataProvider humanizeProvider */
    public function testHumanizeViaEndpoint(int $total, string $expected): void
    {
        $this->seedMemo($total);
        self::assertSame($expected, PackagistDownloads::endpoint()['message']);
    }

    /** @return array<string, array{int, string}> */
    public static function humanizeProvider(): array
    {
        return [
            'units'      => [256, '256'],
            'sub-1k'     => [999, '999'],
            'exact 1k'   => [1000, '1k'],
            'k decimal'  => [1500, '1.5k'],
            'k trim'     => [2000, '2k'],
            'exact 1M'   => [1_000_000, '1M'],
            'M decimal'  => [2_500_000, '2.5M'],
        ];
    }
}
