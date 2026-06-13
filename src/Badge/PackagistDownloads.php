<?php

declare(strict_types=1);

namespace ZealPHP\Badge;

use ZealPHP\HTTP;

/**
 * Combined Packagist download count for the shields.io `endpoint` badge.
 *
 * The project is published under two Packagist names — the original
 * `sibidharan/zealphp` and the current canonical `zealphp/zealphp` (the
 * `name` in composer.json). Packagist NEVER merges download counts across
 * package names, so a single `packagist/dt/<vendor>/<pkg>` badge only ever
 * shows one side of the split. This endpoint sums every project package name
 * and returns the shields `endpoint` schema, so the README badge shows the
 * true combined total.
 *
 * Resilience: a per-worker memo with a TTL avoids hammering Packagist (the
 * badge is also cached by shields via `cacheSeconds`), and a failed refresh
 * serves the last good value rather than breaking the badge.
 */
final class PackagistDownloads
{
    /** Every Packagist package name this project has shipped under. */
    private const PACKAGES = ['zealphp/zealphp', 'sibidharan/zealphp'];

    /** Memo TTL (seconds) — also advertised to shields via cacheSeconds. */
    private const TTL = 3600;

    private static ?int $memoTotal = null;
    private static int $memoAt = 0;

    /**
     * shields.io `endpoint` badge JSON for the combined download total.
     *
     * @return array{schemaVersion:int,label:string,message:string,color:string,cacheSeconds:int}
     */
    public static function endpoint(): array
    {
        $total = self::combinedTotal();
        return [
            'schemaVersion' => 1,
            'label'         => 'downloads',
            'message'       => $total === null ? 'unknown' : self::humanize($total),
            'color'         => $total === null ? 'lightgrey' : 'blue',
            'cacheSeconds'  => self::TTL,
        ];
    }

    /**
     * Combined total across {@see self::PACKAGES}, memoized per worker. Returns
     * the last good value on a failed refresh; null only when no value has
     * ever been fetched.
     */
    private static function combinedTotal(): ?int
    {
        if (self::$memoTotal !== null && (time() - self::$memoAt) < self::TTL) {
            return self::$memoTotal;
        }
        $sum = self::fetchSum();
        if ($sum !== null) {
            self::$memoTotal = $sum;
            self::$memoAt = time();
        }
        // On failure, serve the stale memo if we have one.
        return $sum ?? self::$memoTotal;
    }

    /** @return int|null sum of every package's total, or null if all sources failed. */
    private static function fetchSum(): ?int
    {
        $total = 0;
        $any = false;
        foreach (self::PACKAGES as $pkg) {
            $r = HTTP::get(
                "https://packagist.org/packages/{$pkg}.json",
                ['User-Agent' => 'zealphp-downloads-badge'],
                8.0
            );
            if (!$r->ok()) {
                continue;
            }
            $n = self::parseTotal($r->json());
            if ($n !== null) {
                $total += $n;
                $any = true;
            }
        }
        return $any ? $total : null;
    }

    /**
     * Extract `package.downloads.total` from a decoded Packagist payload.
     * Pure (no I/O) so the parse contract is unit-testable without network.
     *
     * @param mixed $decoded json_decode($body, true) of a Packagist package JSON
     */
    public static function parseTotal(mixed $decoded): ?int
    {
        if (!is_array($decoded)) {
            return null;
        }
        $pkg = $decoded['package'] ?? null;
        if (!is_array($pkg)) {
            return null;
        }
        $downloads = $pkg['downloads'] ?? null;
        if (!is_array($downloads)) {
            return null;
        }
        $n = $downloads['total'] ?? null;
        return is_int($n) ? $n : null;
    }

    /** 1234 → "1.2k", 2_500_000 → "2.5M". */
    private static function humanize(int $n): string
    {
        if ($n >= 1_000_000) {
            return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
        }
        if ($n >= 1_000) {
            return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.') . 'k';
        }
        return (string) $n;
    }
}
